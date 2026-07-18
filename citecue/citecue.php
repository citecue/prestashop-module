<?php
/**
 * CiteCue AI Delivery for PrestaShop.
 *
 * Serves CiteCue-enhanced (AI auto-fixed) versions of shop pages and products
 * to AI bots and crawlers, acting as a transparent middleware in front of the
 * regular PrestaShop rendering.
 *
 * @author    CiteCue
 * @copyright CiteCue
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/CitecueClient.php';
require_once __DIR__ . '/classes/CitecueCrawlers.php';
require_once __DIR__ . '/classes/CitecueCache.php';

class Citecue extends Module
{
    const CONF_ENABLED = 'CITECUE_ENABLED';
    const CONF_API_BASE = 'CITECUE_API_BASE';
    const CONF_API_KEY = 'CITECUE_API_KEY';
    const CONF_PROJECT_KEY = 'CITECUE_PROJECT_KEY';
    const CONF_LLMSTXT = 'CITECUE_LLMSTXT';

    const DEFAULT_API_BASE = 'https://app.citecue.com';

    /** Freshness window for the locally cached llms.txt body (seconds). */
    const LLMSTXT_FRESH_TTL = 300;

    /** Negative-cache TTL for page misses, mirrors the API's 404 max-age. */
    const MISS_TTL = 60;

    public function __construct()
    {
        $this->name = 'citecue';
        $this->tab = 'seo';
        $this->version = '1.0.0';
        $this->author = 'CiteCue';
        $this->need_instance = 0;
        $this->bootstrap = true;
        // actionDispatcherBefore was introduced in PrestaShop 1.7.1.0
        $this->ps_versions_compliancy = array('min' => '1.7.1.0', 'max' => _PS_VERSION_);

        parent::__construct();

        $this->displayName = $this->l('CiteCue AI Delivery');
        $this->description = $this->l('Serve CiteCue AI-enhanced versions of your pages and products to AI bots and crawlers (GPTBot, ClaudeBot, PerplexityBot and more), plus an llms.txt feed.');
        $this->confirmUninstall = $this->l('Uninstall CiteCue? AI crawlers will receive the regular pages again.');
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('actionDispatcherBefore')
            && Configuration::updateValue(self::CONF_ENABLED, 0)
            && Configuration::updateValue(self::CONF_API_BASE, self::DEFAULT_API_BASE)
            && Configuration::updateValue(self::CONF_LLMSTXT, 1);
    }

    public function uninstall()
    {
        CitecueCache::clearAll();
        foreach (array(self::CONF_ENABLED, self::CONF_API_BASE, self::CONF_API_KEY, self::CONF_PROJECT_KEY, self::CONF_LLMSTXT, CitecueCrawlers::CONF_REGISTRY) as $key) {
            Configuration::deleteByName($key);
        }

        return parent::uninstall();
    }

    /**
     * Front-office middleware. Runs before routing/controller instantiation;
     * everything is wrapped so that no CiteCue failure can ever break the
     * shop — any error degrades to normal PrestaShop rendering.
     *
     * @param array $params
     */
    public function hookActionDispatcherBefore($params)
    {
        try {
            $this->interceptRequest($params);
        } catch (Exception $e) {
            // fail open: serve the regular page
        } catch (Throwable $e) {
            // fail open (PHP 7+ engine errors)
        }
    }

    /**
     * @param array $params
     */
    private function interceptRequest($params)
    {
        if (isset($params['controller_type']) && (int) $params['controller_type'] !== Dispatcher::FC_FRONT) {
            return;
        }
        if (!Configuration::get(self::CONF_ENABLED)) {
            return;
        }

        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
        if ($method !== 'GET' && $method !== 'HEAD') {
            return;
        }

        $apiKey = trim((string) Configuration::get(self::CONF_API_KEY));
        $projectKey = trim((string) Configuration::get(self::CONF_PROJECT_KEY));
        if ($apiKey === '' || $projectKey === '') {
            return;
        }

        $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $path = (string) parse_url($requestUri, PHP_URL_PATH);

        if (Configuration::get(self::CONF_LLMSTXT) && $this->isLlmsTxtPath($path)) {
            $this->serveLlmsTxt($apiKey, $projectKey, $method);

            return;
        }

        $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
        $crawler = CitecueCrawlers::match($userAgent);
        if ($crawler === null || $this->isAssetPath($path)) {
            return;
        }

        $apiBase = $this->apiBase();
        $url = $this->currentAbsoluteUrl($requestUri);
        $cache = new CitecueCache();
        $cacheKey = 'page|' . $projectKey . '|' . $url;

        $entry = $cache->get($cacheKey);
        if ($entry !== null && !empty($entry['miss'])) {
            return;
        }

        // Registry refresh only ever runs here, after a crawler already
        // matched — a regular visitor never waits on the CiteCue API.
        CitecueCrawlers::maybeRefresh($apiBase);

        $etag = ($entry !== null && !empty($entry['etag']) && $entry['body'] !== '') ? $entry['etag'] : null;
        $response = CitecueClient::getPage($apiBase, $apiKey, $projectKey, $url, $crawler['id'], $etag);

        if ($response === null) {
            // API unreachable: brief negative cache shields the shop from
            // repeated blocking lookups, then fail open.
            $cache->setNegative($cacheKey, self::MISS_TTL);

            return;
        }

        if ($response['status'] === 200 && $response['body'] !== '') {
            $newEtag = isset($response['headers']['etag']) ? $response['headers']['etag'] : null;
            $mode = isset($response['headers']['x-citecue-mode']) ? $response['headers']['x-citecue-mode'] : null;
            $cache->setPositive($cacheKey, $newEtag, $response['body'], $mode);
            $this->emit($response['body'], 'text/html; charset=utf-8', $newEtag, $mode, $method);
        }

        if ($response['status'] === 304 && $entry !== null && $entry['body'] !== '') {
            $this->emit($entry['body'], 'text/html; charset=utf-8', $entry['etag'], isset($entry['mode']) ? $entry['mode'] : null, $method);
        }

        if ($response['status'] === 304) {
            // 304 without a usable cached body: drop the stale entry and pass
            // through; the next hit revalidates from scratch.
            $cache->delete($cacheKey);

            return;
        }

        // 404 miss sentinel (or any other status): this URL has no enhanced
        // version — mirror the API's short miss TTL, then passthrough.
        $cache->setNegative($cacheKey, self::MISS_TTL);
    }

    /**
     * Proxies /llms.txt from the delivery API, for every visitor (the feed is
     * meant to be fetchable by anything, not just registered crawlers). The
     * body is served from local cache within a short freshness window and
     * revalidated with the ETag afterwards; a stale copy is served when the
     * API is unreachable. Falls through (PrestaShop 404) when the project has
     * llms.txt serving disabled.
     *
     * @param string $apiKey
     * @param string $projectKey
     * @param string $method
     */
    private function serveLlmsTxt($apiKey, $projectKey, $method)
    {
        $cache = new CitecueCache();
        $cacheKey = 'llmstxt|' . $projectKey;
        $entry = $cache->get($cacheKey);

        if ($entry !== null && !empty($entry['miss'])) {
            return;
        }
        if ($entry !== null && (int) $entry['expires'] > time() && $entry['body'] !== '') {
            $this->emit($entry['body'], 'text/plain; charset=utf-8', $entry['etag'], null, $method);
        }

        $etag = ($entry !== null && !empty($entry['etag']) && $entry['body'] !== '') ? $entry['etag'] : null;
        $response = CitecueClient::getLlmsTxt($this->apiBase(), $apiKey, $projectKey, $etag);

        if ($response === null) {
            if ($entry !== null && $entry['body'] !== '') {
                $this->emit($entry['body'], 'text/plain; charset=utf-8', $entry['etag'], null, $method);
            }

            return;
        }

        if ($response['status'] === 200 && $response['body'] !== '') {
            $newEtag = isset($response['headers']['etag']) ? $response['headers']['etag'] : null;
            $cache->setPositive($cacheKey, $newEtag, $response['body'], null, self::LLMSTXT_FRESH_TTL);
            $this->emit($response['body'], 'text/plain; charset=utf-8', $newEtag, null, $method);
        }

        if ($response['status'] === 304 && $entry !== null && $entry['body'] !== '') {
            $cache->refresh($cacheKey, $entry, self::LLMSTXT_FRESH_TTL);
            $this->emit($entry['body'], 'text/plain; charset=utf-8', $entry['etag'], null, $method);
        }

        if ($response['status'] === 404) {
            // Disabled/unknown (the API's miss sentinel): cache the miss so
            // the shop 404s cheaply, matching the freshness window.
            $cache->setNegative($cacheKey, self::LLMSTXT_FRESH_TTL);

            return;
        }

        // Transient upstream failure (429/5xx/unexpected): keep the cached
        // entry and serve it stale when available; never poison the cache.
        if ($entry !== null && $entry['body'] !== '') {
            $this->emit($entry['body'], 'text/plain; charset=utf-8', $entry['etag'], null, $method);
        }
    }

    /**
     * Sends the response and stops PrestaShop processing.
     *
     * @param string $body
     * @param string $contentType
     * @param string|null $etag
     * @param string|null $mode
     * @param string $method
     */
    private function emit($body, $contentType, $etag, $mode, $method)
    {
        header('Content-Type: ' . $contentType);
        header('Cache-Control: no-cache');
        if ($etag) {
            header('ETag: ' . $etag);
        }
        if ($mode) {
            header('X-Citecue-Mode: ' . $mode);
        }
        header('X-Served-By: citecue-prestashop');
        if ($method !== 'HEAD') {
            echo $body;
        }
        exit;
    }

    /**
     * @return string
     */
    private function apiBase()
    {
        $base = trim((string) Configuration::get(self::CONF_API_BASE));

        return $base !== '' ? $base : self::DEFAULT_API_BASE;
    }

    /**
     * Absolute URL of the current request, built from the shop's configured
     * domain (not the Host header, so a spoofed Host can't be forwarded).
     *
     * @param string $requestUri
     *
     * @return string
     */
    private function currentAbsoluteUrl($requestUri)
    {
        $shop = Context::getContext()->shop;
        $ssl = Tools::usingSecureMode();
        $domain = $ssl ? $shop->domain_ssl : $shop->domain;
        if (!$domain) {
            $domain = Tools::getHttpHost();
        }

        return ($ssl ? 'https://' : 'http://') . $domain . $requestUri;
    }

    /**
     * @param string $path
     *
     * @return bool
     */
    private function isLlmsTxtPath($path)
    {
        if ($path === '/llms.txt') {
            return true;
        }
        $shop = Context::getContext()->shop;
        $base = $shop ? $shop->getBaseURI() : '/';

        return $path === rtrim($base, '/') . '/llms.txt';
    }

    /**
     * Static assets are never enhanced; skip them before any API traffic.
     *
     * @param string $path
     *
     * @return bool
     */
    private function isAssetPath($path)
    {
        return (bool) preg_match(
            '#\.(css|js|mjs|map|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|otf|eot|txt|xml|json|pdf|zip|gz|mp4|webm|mp3)$#i',
            $path
        );
    }

    /* ---------------------------------------------------------------------
     * Back office configuration
     * ------------------------------------------------------------------- */

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitCitecue')) {
            $output .= $this->processConfigSubmit();
        }

        $output .= $this->renderStatusPanel();

        return $output . $this->renderForm();
    }

    /**
     * @return string confirmation/error HTML
     */
    private function processConfigSubmit()
    {
        $apiBase = trim((string) Tools::getValue('CITECUE_API_BASE'));
        if ($apiBase === '') {
            $apiBase = self::DEFAULT_API_BASE;
        }
        if (!Validate::isAbsoluteUrl($apiBase)) {
            return $this->displayError($this->l('The API base URL is not a valid URL.'));
        }

        Configuration::updateValue(self::CONF_ENABLED, (int) Tools::getValue('CITECUE_ENABLED'));
        Configuration::updateValue(self::CONF_API_BASE, rtrim($apiBase, '/'));
        Configuration::updateValue(self::CONF_API_KEY, trim((string) Tools::getValue('CITECUE_API_KEY')));
        Configuration::updateValue(self::CONF_PROJECT_KEY, trim((string) Tools::getValue('CITECUE_PROJECT_KEY')));
        Configuration::updateValue(self::CONF_LLMSTXT, (int) Tools::getValue('CITECUE_LLMSTXT'));

        // Settings changed: cached bodies/misses may belong to another
        // project or key.
        CitecueCache::clearAll();

        return $this->displayConfirmation($this->l('Settings updated.'));
    }

    /**
     * Live connection check against the delivery API: validates the key,
     * lists the org's projects, auto-selects the project whose domain matches
     * this shop when none is configured yet, and surfaces dashboard-side
     * state (delivery disabled, llms.txt off) the merchant would otherwise
     * only discover by testing with a crawler UA.
     *
     * @return string
     */
    private function renderStatusPanel()
    {
        $apiKey = trim((string) Configuration::get(self::CONF_API_KEY));
        if ($apiKey === '') {
            return $this->renderInfo(
                $this->l('Enter your CiteCue organization API key (ck_live_…) to connect. You can create one in the CiteCue dashboard under Settings → API keys.')
            );
        }

        $response = CitecueClient::getConfig($this->apiBase(), $apiKey);
        if ($response === null) {
            return $this->displayWarning($this->l('Could not reach the CiteCue API. Check the API base URL and your server\'s outbound connectivity.'));
        }
        if ($response['status'] === 401) {
            return $this->displayError($this->l('The CiteCue API rejected this API key. Check that the key is active and copied in full.'));
        }
        $decoded = json_decode($response['body'], true);
        if ($response['status'] !== 200 || !is_array($decoded) || !isset($decoded['projects']) || !is_array($decoded['projects'])) {
            return $this->displayWarning($this->l('Unexpected response from the CiteCue API.'));
        }

        CitecueCrawlers::maybeRefresh($this->apiBase());

        $projects = $decoded['projects'];
        $projectKey = trim((string) Configuration::get(self::CONF_PROJECT_KEY));
        $shopDomain = $this->normalizeDomain(Context::getContext()->shop->domain);

        $selected = null;
        if ($projectKey === '') {
            foreach ($projects as $project) {
                if (isset($project['domain']) && $this->normalizeDomain($project['domain']) === $shopDomain) {
                    $selected = $project;
                    break;
                }
            }
            if ($selected !== null) {
                Configuration::updateValue(self::CONF_PROJECT_KEY, (string) $selected['publicKey']);
            }
        } else {
            foreach ($projects as $project) {
                if (isset($project['publicKey']) && (string) $project['publicKey'] === $projectKey) {
                    $selected = $project;
                    break;
                }
            }
        }

        $messages = $this->displayConfirmation(
            sprintf($this->l('Connected to CiteCue — %d project(s) available on this organization.'), count($projects))
        );

        if ($selected === null) {
            $known = array();
            foreach ($projects as $project) {
                if (isset($project['domain'])) {
                    $known[] = $project['domain'];
                }
            }

            return $messages . $this->displayWarning(sprintf(
                $this->l('No CiteCue project matches this shop (%1$s). Available project domains: %2$s. Paste the right project public key below.'),
                $shopDomain,
                $known ? implode(', ', array_map('htmlspecialchars', $known)) : $this->l('none')
            ));
        }

        $messages .= $this->displayConfirmation(sprintf(
            $this->l('Serving project: %1$s (public key %2$s).'),
            htmlspecialchars(isset($selected['domain']) ? (string) $selected['domain'] : '?'),
            htmlspecialchars((string) $selected['publicKey'])
        ));

        if (array_key_exists('enabled', $selected) && !$selected['enabled']) {
            $messages .= $this->displayWarning($this->l('Delivery is switched OFF for this project in the CiteCue dashboard (Auto-Fix page). Crawlers will get regular pages until it is enabled there.'));
        }
        if (Configuration::get(self::CONF_LLMSTXT) && array_key_exists('serveLlmsTxt', $selected) && !$selected['serveLlmsTxt']) {
            $messages .= $this->renderInfo($this->l('llms.txt serving is disabled for this project in the CiteCue dashboard, so /llms.txt will return 404.'));
        }
        if (!Configuration::get(self::CONF_ENABLED)) {
            $messages .= $this->displayWarning($this->l('The module is currently disabled below — no enhanced pages are being served.'));
        }

        return $messages;
    }

    /**
     * Info-level alert (Module::displayInformation is not available on every
     * supported PrestaShop version).
     *
     * @param string $message
     *
     * @return string
     */
    private function renderInfo($message)
    {
        return '<div class="alert alert-info">' . $message . '</div>';
    }

    /**
     * @param string $domain
     *
     * @return string
     */
    private function normalizeDomain($domain)
    {
        $domain = Tools::strtolower(trim((string) $domain));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = preg_replace('#[:/].*$#', '', $domain);

        return preg_replace('#^www\.#', '', $domain);
    }

    /**
     * @return string
     */
    private function renderForm()
    {
        $form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('CiteCue settings'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Serve enhanced pages to AI crawlers'),
                        'name' => 'CITECUE_ENABLED',
                        'is_bool' => true,
                        'desc' => $this->l('When enabled, requests from known AI crawlers receive the CiteCue-enhanced version of pages you optimized in the CiteCue dashboard. Regular visitors are never affected.'),
                        'values' => array(
                            array('id' => 'enabled_on', 'value' => 1, 'label' => $this->l('Yes')),
                            array('id' => 'enabled_off', 'value' => 0, 'label' => $this->l('No')),
                        ),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Organization API key'),
                        'name' => 'CITECUE_API_KEY',
                        'desc' => $this->l('Your ck_live_… key from the CiteCue dashboard (Settings → API keys).'),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Project public key'),
                        'name' => 'CITECUE_PROJECT_KEY',
                        'desc' => $this->l('Leave empty to auto-select the CiteCue project matching this shop\'s domain.'),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('API base URL'),
                        'name' => 'CITECUE_API_BASE',
                        'desc' => sprintf($this->l('Default: %s. Only change this for a self-hosted CiteCue instance.'), self::DEFAULT_API_BASE),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Serve /llms.txt'),
                        'name' => 'CITECUE_LLMSTXT',
                        'is_bool' => true,
                        'desc' => $this->l('Answer requests to /llms.txt with the feed generated by CiteCue (also requires llms.txt to be enabled for the project in the dashboard).'),
                        'values' => array(
                            array('id' => 'llmstxt_on', 'value' => 1, 'label' => $this->l('Yes')),
                            array('id' => 'llmstxt_off', 'value' => 0, 'label' => $this->l('No')),
                        ),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitCitecue';
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => array(
                'CITECUE_ENABLED' => (int) Configuration::get(self::CONF_ENABLED),
                'CITECUE_API_KEY' => (string) Configuration::get(self::CONF_API_KEY),
                'CITECUE_PROJECT_KEY' => (string) Configuration::get(self::CONF_PROJECT_KEY),
                'CITECUE_API_BASE' => (string) $this->apiBase(),
                'CITECUE_LLMSTXT' => (int) Configuration::get(self::CONF_LLMSTXT),
            ),
            'languages' => is_object($this->context->controller) ? $this->context->controller->getLanguages() : array(),
            'id_language' => is_object($this->context->language) ? $this->context->language->id : (int) Configuration::get('PS_LANG_DEFAULT'),
        );

        return $helper->generateForm(array($form));
    }
}

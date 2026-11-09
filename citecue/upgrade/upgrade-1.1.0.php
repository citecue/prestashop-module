<?php
/**
 * CiteCue AI Delivery for PrestaShop.
 *
 * @author    CiteCue
 * @copyright CiteCue
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 1.0.0 → 1.1.0.
 *
 * - Adds CITECUE_ALLOWED_HOSTS. A 1.0.0 shop already pointed at a
 *   self-hosted https instance keeps working: that host is trusted. An http
 *   base URL or a private/reserved IP is not carried over, so the module stops
 *   sending the key there and the settings page says why.
 * - Encrypts a stored API key at rest.
 * - Drops the stored crawler registry (1.0.0 kept fetching rows only, without
 *   their flags) so the next crawler hit refetches it.
 * - Clears the cache: page entries are now keyed by representation too.
 *
 * @param Citecue $module
 *
 * @return bool
 */
function upgrade_module_1_1_0($module)
{
    $hosts = array();
    $base = rtrim(trim((string) Configuration::get('CITECUE_API_BASE')), '/');
    if ($base !== '') {
        $host = strtolower((string) parse_url($base, PHP_URL_HOST));
        $trusted = CitecueEndpoint::normalizeHostList('');
        $candidate = CitecueEndpoint::normalizeHostList($host);
        if ($host !== '' && !in_array($host, $trusted, true)
            && CitecueEndpoint::isAllowed($base, $candidate)
        ) {
            $hosts[] = $host;
        }
    }

    $ok = Configuration::updateValue('CITECUE_ALLOWED_HOSTS', implode(', ', $hosts));
    CitecueKeyStore::migrate();
    Configuration::deleteByName(CitecueCrawlers::CONF_REGISTRY);
    CitecueCache::clearAll();

    return (bool) $ok;
}

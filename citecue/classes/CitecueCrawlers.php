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
 * AI crawler User-Agent registry.
 *
 * Mirrors the CiteCue delivery crawler registry (case-insensitive substring
 * match, longest token wins). A bundled snapshot ships with the module so
 * detection works with zero network calls; the live registry is refreshed
 * best-effort from the keyless `GET /api/delivery/v1/crawlers` feed so new
 * crawlers are picked up without a module update. The refresh only ever runs
 * on requests that already matched a crawler (or from the back office), so a
 * slow/unreachable API can never delay a regular visitor.
 */
class CitecueCrawlers
{
    const CONF_REGISTRY = 'CITECUE_CRAWLER_REGISTRY';

    /** Refresh cadence, matches the feed's public max-age (6 hours). */
    const REFRESH_TTL = 21600;

    /** Retry delay after a failed refresh (1 hour). */
    const RETRY_TTL = 3600;

    /**
     * Bundled snapshot of the CiteCue registry (crawlers that actually fetch
     * pages; robots.txt-only tokens such as Google-Extended are excluded).
     *
     * @return array<int, array{id: string, token: string}>
     */
    public static function defaults()
    {
        return array(
            array('id' => 'gptbot', 'token' => 'GPTBot'),
            array('id' => 'oai-searchbot', 'token' => 'OAI-SearchBot'),
            array('id' => 'chatgpt-user', 'token' => 'ChatGPT-User'),
            array('id' => 'claudebot', 'token' => 'ClaudeBot'),
            array('id' => 'claude-user', 'token' => 'Claude-User'),
            array('id' => 'claude-searchbot', 'token' => 'Claude-SearchBot'),
            array('id' => 'anthropic-ai', 'token' => 'anthropic-ai'),
            array('id' => 'perplexitybot', 'token' => 'PerplexityBot'),
            array('id' => 'perplexity-user', 'token' => 'Perplexity-User'),
            array('id' => 'googleother', 'token' => 'GoogleOther'),
            array('id' => 'bytespider', 'token' => 'Bytespider'),
            array('id' => 'ccbot', 'token' => 'CCBot'),
            array('id' => 'cohere-ai', 'token' => 'cohere-ai'),
            array('id' => 'meta-externalagent', 'token' => 'meta-externalagent'),
            array('id' => 'meta-externalfetcher', 'token' => 'meta-externalfetcher'),
            array('id' => 'amazonbot', 'token' => 'Amazonbot'),
            array('id' => 'duckassistbot', 'token' => 'DuckAssistBot'),
            array('id' => 'mistralai-user', 'token' => 'MistralAI-User'),
        );
    }

    /**
     * The active registry: the stored live snapshot when one exists, merged
     * over the bundled defaults (so a truncated remote list can never disable
     * detection of a bundled crawler).
     *
     * @return array<int, array{id: string, token: string}>
     */
    public static function all()
    {
        $byId = array();
        foreach (self::defaults() as $crawler) {
            $byId[$crawler['id']] = $crawler;
        }

        $stored = self::readStored();
        if ($stored && !empty($stored['crawlers']) && is_array($stored['crawlers'])) {
            foreach ($stored['crawlers'] as $crawler) {
                if (!empty($crawler['id']) && !empty($crawler['token'])) {
                    $byId[(string) $crawler['id']] = array(
                        'id' => (string) $crawler['id'],
                        'token' => (string) $crawler['token'],
                    );
                }
            }
        }

        return array_values($byId);
    }

    /**
     * Matches a User-Agent against the registry. Case-insensitive substring
     * match; when several tokens match, the longest wins (same rule as the
     * CiteCue server, so e.g. ChatGPT-User is never shadowed by GPTBot).
     *
     * @param string|null $userAgent
     *
     * @return array{id: string, token: string}|null
     */
    public static function match($userAgent)
    {
        if (!is_string($userAgent) || $userAgent === '') {
            return null;
        }

        $ua = Tools::strtolower($userAgent);
        $best = null;
        foreach (self::all() as $crawler) {
            if (strpos($ua, Tools::strtolower($crawler['token'])) !== false) {
                if ($best === null || Tools::strlen($crawler['token']) > Tools::strlen($best['token'])) {
                    $best = $crawler;
                }
            }
        }

        return $best;
    }

    /**
     * Refreshes the stored registry from the keyless crawler feed when the
     * snapshot is older than REFRESH_TTL. Failures are silent and retried
     * after RETRY_TTL. Safe to call on the crawler-serving hot path.
     *
     * @param string $apiBase
     */
    public static function maybeRefresh($apiBase)
    {
        $stored = self::readStored();
        $fetchedAt = ($stored && isset($stored['fetched_at'])) ? (int) $stored['fetched_at'] : 0;
        if ($fetchedAt + self::REFRESH_TTL > time()) {
            return;
        }

        // Cross-process lock so concurrent stale-registry requests don't
        // stampede the API: one request refreshes, the rest keep serving with
        // the existing registry.
        $lock = self::acquireLock();
        if ($lock === null) {
            return;
        }

        // Re-check under the lock — another request may have just refreshed.
        $stored = self::readStored();
        $fetchedAt = ($stored && isset($stored['fetched_at'])) ? (int) $stored['fetched_at'] : 0;
        if ($fetchedAt + self::REFRESH_TTL > time()) {
            self::releaseLock($lock);

            return;
        }

        $response = CitecueClient::getCrawlers($apiBase);
        $crawlers = null;
        if ($response !== null && $response['status'] === 200) {
            $decoded = json_decode($response['body'], true);
            if (is_array($decoded) && isset($decoded['crawlers']) && is_array($decoded['crawlers'])) {
                $crawlers = array();
                foreach ($decoded['crawlers'] as $crawler) {
                    if (!is_array($crawler) || empty($crawler['id']) || empty($crawler['token'])) {
                        continue;
                    }
                    if (array_key_exists('fetchesPages', $crawler) && !$crawler['fetchesPages']) {
                        continue;
                    }
                    $crawlers[] = array('id' => (string) $crawler['id'], 'token' => (string) $crawler['token']);
                }
            }
        }

        if ($crawlers !== null && count($crawlers) > 0) {
            $snapshot = array('fetched_at' => time(), 'crawlers' => $crawlers);
        } else {
            $keep = ($stored && !empty($stored['crawlers'])) ? $stored['crawlers'] : array();
            $snapshot = array(
                'fetched_at' => time() - (self::REFRESH_TTL - self::RETRY_TTL),
                'crawlers' => $keep,
            );
        }

        Configuration::updateValue(self::CONF_REGISTRY, json_encode($snapshot));
        self::releaseLock($lock);
    }

    /**
     * Non-blocking cross-process lock for the registry refresh.
     *
     * @return resource|null lock handle, or null when another process holds it
     */
    private static function acquireLock()
    {
        $dir = (defined('_PS_CACHE_DIR_') ? _PS_CACHE_DIR_ : (rtrim(sys_get_temp_dir(), '/') . '/')) . 'citecue/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $handle = @fopen($dir . 'registry.lock', 'c');
        if ($handle === false) {
            return null;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /**
     * @param resource $handle
     */
    private static function releaseLock($handle)
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * @return array|null
     */
    private static function readStored()
    {
        $raw = Configuration::get(self::CONF_REGISTRY);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}

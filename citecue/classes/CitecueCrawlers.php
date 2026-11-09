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
 * match over every token, longest token wins, served only when the winner
 * fetches pages). A bundled snapshot ships with the module so detection works
 * with zero network calls; the live registry is refreshed best-effort from the
 * keyless `GET /api/delivery/v1/crawlers` feed and then replaces the snapshot,
 * so new crawlers are picked up, and retired ones dropped, without a module
 * update. The refresh only ever runs
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
     * Bundled snapshot of the CiteCue registry, used until the first feed
     * refresh. It includes the robots.txt-only tokens (Google-Extended,
     * Applebot-Extended): they never fetch pages, but they take part in the
     * longest-token match so a UA naming one of them is not served, exactly
     * as on the CiteCue side.
     *
     * @return array<int, array{id: string, token: string, fetchesPages: bool}>
     */
    public static function defaults()
    {
        $rows = array(
            array('gptbot', 'GPTBot', true),
            array('oai-searchbot', 'OAI-SearchBot', true),
            array('chatgpt-user', 'ChatGPT-User', true),
            array('claudebot', 'ClaudeBot', true),
            array('claude-user', 'Claude-User', true),
            array('claude-searchbot', 'Claude-SearchBot', true),
            array('anthropic-ai', 'anthropic-ai', true),
            array('perplexitybot', 'PerplexityBot', true),
            array('perplexity-user', 'Perplexity-User', true),
            array('google-extended', 'Google-Extended', false),
            array('googleother', 'GoogleOther', true),
            array('bytespider', 'Bytespider', true),
            array('ccbot', 'CCBot', true),
            array('cohere-ai', 'cohere-ai', true),
            array('meta-externalagent', 'meta-externalagent', true),
            array('meta-externalfetcher', 'meta-externalfetcher', true),
            array('amazonbot', 'Amazonbot', true),
            array('applebot-extended', 'Applebot-Extended', false),
            array('duckassistbot', 'DuckAssistBot', true),
            array('mistralai-user', 'MistralAI-User', true),
        );
        $crawlers = array();
        foreach ($rows as $row) {
            $crawlers[] = array('id' => $row[0], 'token' => $row[1], 'fetchesPages' => $row[2]);
        }

        return $crawlers;
    }

    /**
     * The active registry: the stored feed snapshot when one was ever
     * stored, otherwise the bundled defaults. The feed replaces the defaults
     * entirely, so the app can switch a bundled crawler off (fetchesPages:
     * false) or drop it.
     *
     * @return array<int, array{id: string, token: string, fetchesPages: bool}>
     */
    public static function all()
    {
        $stored = self::readStored();
        if ($stored && !empty($stored['crawlers']) && is_array($stored['crawlers'])) {
            $crawlers = self::sanitize($stored['crawlers']);
            if ($crawlers) {
                return $crawlers;
            }
        }

        return self::defaults();
    }

    /**
     * Matches a User-Agent against the whole registry, robots-only tokens
     * included: case-insensitive substring match, the longest matching token
     * wins (the CiteCue server's rule, so e.g. ChatGPT-User is never shadowed
     * by GPTBot). Returns the winner only when it actually fetches pages.
     *
     * @param string|null $userAgent
     *
     * @return array{id: string, token: string, fetchesPages: bool}|null
     */
    public static function match($userAgent)
    {
        $best = self::matchAny($userAgent);

        return ($best !== null && $best['fetchesPages']) ? $best : null;
    }

    /**
     * Longest-token match over the whole registry, without the serve
     * decision.
     *
     * @param string|null $userAgent
     *
     * @return array{id: string, token: string, fetchesPages: bool}|null
     */
    public static function matchAny($userAgent)
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
     * Normalizes feed or stored rows to {id, token, fetchesPages}. A row
     * without the flag counts as fetching (the 1.0.0 snapshot only stored
     * fetching rows, without the flag); a row with any value other than a
     * literal true is not fetching, so a malformed "false" can never switch
     * serving on.
     *
     * @param array $rows
     *
     * @return array<int, array{id: string, token: string, fetchesPages: bool}>
     */
    public static function sanitize(array $rows)
    {
        $crawlers = array();
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['id'], $row['token']) || !is_scalar($row['id']) || !is_scalar($row['token'])) {
                continue;
            }
            $id = trim((string) $row['id']);
            $token = trim((string) $row['token']);
            if ($id === '' || $token === '') {
                continue;
            }
            $crawlers[] = array(
                'id' => $id,
                'token' => $token,
                'fetchesPages' => !array_key_exists('fetchesPages', $row) || $row['fetchesPages'] === true,
            );
        }

        return $crawlers;
    }

    /**
     * Refreshes the stored registry from the keyless crawler feed when the
     * snapshot is older than REFRESH_TTL. Failures are silent and retried
     * after RETRY_TTL. Safe to call on the crawler-serving hot path.
     *
     * @param string $apiBase
     *
     * @return bool false when a refresh ran and found the API down
     *              (transport failure, 429 or 5xx), true otherwise
     */
    public static function maybeRefresh($apiBase)
    {
        $stored = self::readStored();
        $fetchedAt = ($stored && isset($stored['fetched_at'])) ? (int) $stored['fetched_at'] : 0;
        if ($fetchedAt + self::REFRESH_TTL > time()) {
            return true;
        }

        // Cross-process lock so concurrent stale-registry requests don't
        // stampede the API: one request refreshes, the rest keep serving with
        // the existing registry.
        $lock = self::acquireLock();
        if ($lock === null) {
            return true;
        }

        // Re-check under the lock — another request may have just refreshed.
        $stored = self::readStored();
        $fetchedAt = ($stored && isset($stored['fetched_at'])) ? (int) $stored['fetched_at'] : 0;
        if ($fetchedAt + self::REFRESH_TTL > time()) {
            self::releaseLock($lock);

            return true;
        }

        $response = CitecueClient::getCrawlers($apiBase);
        $crawlers = null;
        if ($response !== null && $response['status'] === 200) {
            $decoded = json_decode($response['body'], true);
            if (is_array($decoded) && isset($decoded['crawlers']) && is_array($decoded['crawlers'])) {
                // Keep every row with its fetchesPages flag: robots-only
                // tokens take part in matching, and a crawler the feed marks
                // as not fetching must stop being served.
                $crawlers = self::sanitize($decoded['crawlers']);
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

        return !($response === null || $response['status'] === 429 || $response['status'] >= 500);
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

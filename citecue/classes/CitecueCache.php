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
 * File cache for delivery responses.
 *
 * Two kinds of entries:
 *  - positive: an enhanced body + its ETag. Used to answer the API's 304 on
 *    revalidation (the plugin serves the cached body; the API still counts
 *    the hit as served). Optionally carries a freshness window (`expires`)
 *    during which the entry may be served without revalidating — used for
 *    llms.txt, never for pages (pages revalidate every crawler hit so every
 *    hit is recorded).
 *  - negative: a short-lived "not optimized" marker mirroring the API's 404
 *    miss `max-age`, so unoptimized pages don't trigger an API call per
 *    crawler request. The per-project circuit breaker is a negative entry
 *    too.
 *
 * Every distinct crawled URL leaves an entry, and an entry is otherwise only
 * removed when that same URL is read again, so sweep() runs every
 * SWEEP_INTERVAL on the crawler path: it deletes expired entries and
 * abandoned temp files, then caps the directory at MAX_FILES.
 */
class CitecueCache
{
    /** Positive entries older than this are discarded on read (seconds). */
    const MAX_ENTRY_AGE = 2592000;

    /** Minimum seconds between two sweeps. */
    const SWEEP_INTERVAL = 600;

    /** Entry count the sweep trims the directory down to, oldest first. */
    const MAX_FILES = 5000;

    /** Entries up to this size are parsed by the sweep; larger ones are aged by mtime. */
    const SWEEP_PARSE_BYTES = 4096;

    /** @var string */
    private $dir;

    public function __construct()
    {
        $base = defined('_PS_CACHE_DIR_') ? _PS_CACHE_DIR_ : (rtrim(sys_get_temp_dir(), '/') . '/');
        $this->dir = $base . 'citecue/';
    }

    /**
     * @param string $key
     *
     * @return array|null entry array, or null when absent/expired
     */
    public function get($key)
    {
        $path = $this->path($key);
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $entry = json_decode($raw, true);
        if (!is_array($entry)) {
            @unlink($path);

            return null;
        }

        if (!empty($entry['miss'])) {
            if ((int) $entry['expires'] < time()) {
                @unlink($path);

                return null;
            }

            return $entry;
        }

        if (isset($entry['stored_at']) && (int) $entry['stored_at'] + self::MAX_ENTRY_AGE < time()) {
            @unlink($path);

            return null;
        }
        if (isset($entry['body']) && is_string($entry['body'])) {
            $decoded = base64_decode($entry['body'], true);
            $entry['body'] = ($decoded === false) ? '' : $decoded;
        }

        return $entry;
    }

    /**
     * @param string $key
     * @param string|null $etag
     * @param string $body
     * @param string|null $mode
     * @param int $freshTtl seconds the entry may be served without revalidation (0 = always revalidate)
     * @param string|null $contentType the API's Content-Type for the body
     */
    public function setPositive($key, $etag, $body, $mode, $freshTtl = 0, $contentType = null)
    {
        $this->write($key, array(
            'miss' => false,
            'etag' => $etag,
            // base64 keeps the entry json-safe regardless of the body's encoding
            'body' => base64_encode($body),
            'mode' => $mode,
            'type' => $contentType,
            'stored_at' => time(),
            'expires' => $freshTtl > 0 ? time() + $freshTtl : 0,
        ));
    }

    /**
     * @param string $key
     * @param int $ttl
     */
    public function setNegative($key, $ttl)
    {
        $this->write($key, array('miss' => true, 'expires' => time() + $ttl));
    }

    /**
     * Extends a positive entry's freshness window after a 304 revalidation.
     *
     * @param string $key
     * @param array $entry as returned by get() (body already decoded)
     * @param int $freshTtl
     */
    public function refresh($key, array $entry, $freshTtl)
    {
        $this->setPositive(
            $key,
            isset($entry['etag']) ? $entry['etag'] : null,
            isset($entry['body']) ? $entry['body'] : '',
            isset($entry['mode']) ? $entry['mode'] : null,
            $freshTtl,
            isset($entry['type']) ? $entry['type'] : null
        );
    }

    /**
     * @param string $key
     */
    public function delete($key)
    {
        @unlink($this->path($key));
    }

    /** Removes every cached entry (config change, uninstall). */
    public static function clearAll()
    {
        $cache = new self();
        $files = @glob($cache->dir . '*.json');
        if (is_array($files)) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * Runs sweep() when the last one is older than SWEEP_INTERVAL. Only one
     * process sweeps at a time; the others skip it without waiting.
     *
     * @return int|null files removed, or null when no sweep ran
     */
    public static function maybeSweep()
    {
        $cache = new self();
        $stamp = $cache->dir . 'sweep.stamp';
        $last = @filemtime($stamp);
        if ($last !== false && $last + self::SWEEP_INTERVAL > time()) {
            return null;
        }
        if (!is_dir($cache->dir)) {
            return null;
        }
        $handle = @fopen($cache->dir . 'sweep.lock', 'c');
        if ($handle === false) {
            return null;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }
        clearstatcache(true, $stamp);
        $last = @filemtime($stamp);
        $removed = null;
        if ($last === false || $last + self::SWEEP_INTERVAL <= time()) {
            @touch($stamp);
            $removed = $cache->sweep();
        }
        flock($handle, LOCK_UN);
        fclose($handle);

        return $removed;
    }

    /**
     * Deletes expired entries and abandoned temp files, then trims the
     * directory to MAX_FILES entries, oldest first.
     *
     * @param int|null $now
     *
     * @return int files removed
     */
    public function sweep($now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $removed = 0;

        $temps = @glob($this->dir . '*.tmp');
        foreach (is_array($temps) ? $temps : array() as $file) {
            $mtime = @filemtime($file);
            if ($mtime !== false && $mtime + 3600 < $now && @unlink($file)) {
                ++$removed;
            }
        }

        $files = @glob($this->dir . '*.json');
        if (!is_array($files)) {
            return $removed;
        }
        $kept = array();
        foreach ($files as $file) {
            $mtime = @filemtime($file);
            if ($mtime === false) {
                continue;
            }
            if ($this->isExpiredFile($file, $mtime, $now)) {
                if (@unlink($file)) {
                    ++$removed;
                }
                continue;
            }
            $kept[$file] = $mtime;
        }

        $excess = count($kept) - self::MAX_FILES;
        if ($excess > 0) {
            asort($kept);
            foreach (array_keys($kept) as $file) {
                if ($excess <= 0) {
                    break;
                }
                if (@unlink($file)) {
                    ++$removed;
                }
                --$excess;
            }
        }

        return $removed;
    }

    /**
     * @param string $file
     * @param int $mtime
     * @param int $now
     *
     * @return bool
     */
    private function isExpiredFile($file, $mtime, $now)
    {
        // Entries are rewritten whole, so the mtime is the store time.
        if ($mtime + self::MAX_ENTRY_AGE < $now) {
            return true;
        }
        $size = @filesize($file);
        if ($size === false || $size > self::SWEEP_PARSE_BYTES) {
            // a large positive entry: only MAX_ENTRY_AGE applies
            return false;
        }
        $entry = json_decode((string) @file_get_contents($file), true);
        if (!is_array($entry)) {
            return true;
        }
        if (!empty($entry['miss'])) {
            return !isset($entry['expires']) || (int) $entry['expires'] < $now;
        }

        return isset($entry['stored_at']) && (int) $entry['stored_at'] + self::MAX_ENTRY_AGE < $now;
    }

    /**
     * @param string $key
     *
     * @return string
     */
    private function path($key)
    {
        return $this->dir . md5($key) . '.json';
    }

    /**
     * @param string $key
     * @param array $entry
     */
    private function write($key, array $entry)
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0755, true);
            // PrestaShop convention: no directory listing
            @file_put_contents($this->dir . 'index.php', "<?php\nheader('Location: ../');\nexit;\n");
        }

        $payload = json_encode($entry);
        if ($payload === false) {
            return;
        }

        $path = $this->path($key);
        $tmp = $path . '.' . uniqid('', true) . '.tmp';
        if (@file_put_contents($tmp, $payload) !== false) {
            @rename($tmp, $path);
        }
    }
}

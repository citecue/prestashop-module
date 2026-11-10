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
 * Decides which API base URLs may receive the org API key.
 *
 * Every v2 call carries the merchant's `ck_live_…` key as a Bearer token, so
 * the base URL is a credential destination, not just a setting. A base URL is
 * accepted only when it is https, names a host on the trusted-host list
 * (app.citecue.com plus whatever the merchant added) and is not an IP literal
 * in a loopback, private, link-local, CGNAT, documentation or other
 * non-public range. The same rules run on Save and again before every request.
 *
 * Pure and dependency-free (apart from Configuration in the two *Configured*
 * helpers), so it is unit-tested without PrestaShop.
 */
class CitecueEndpoint
{
    const CONF_ALLOWED_HOSTS = 'CITECUE_ALLOWED_HOSTS';

    const DEFAULT_BASE = 'https://app.citecue.com';

    /**
     * Non-public IPv4 ranges. Listed explicitly rather than relying on
     * FILTER_FLAG_NO_PRIV_RANGE / FILTER_FLAG_NO_RES_RANGE, whose coverage
     * differs between PHP versions and misses CGNAT and the documentation
     * ranges on older ones.
     *
     * @return array<int, string>
     */
    public static function blockedIpv4Ranges()
    {
        return array(
            '0.0.0.0/8',        // "this network"
            '10.0.0.0/8',       // private
            '100.64.0.0/10',    // carrier-grade NAT
            '127.0.0.0/8',      // loopback
            '169.254.0.0/16',   // link-local, cloud metadata
            '172.16.0.0/12',    // private
            '192.0.0.0/24',     // IETF protocol assignments
            '192.0.2.0/24',     // documentation (TEST-NET-1)
            '192.88.99.0/24',   // 6to4 relay anycast
            '192.168.0.0/16',   // private
            '198.18.0.0/15',    // benchmarking
            '198.51.100.0/24',  // documentation (TEST-NET-2)
            '203.0.113.0/24',   // documentation (TEST-NET-3)
            '224.0.0.0/4',      // multicast
            '240.0.0.0/4',      // reserved, broadcast
        );
    }

    /**
     * Non-public IPv6 ranges.
     *
     * @return array<int, string>
     */
    public static function blockedIpv6Ranges()
    {
        return array(
            '::/128',           // unspecified
            '::1/128',          // loopback
            '::ffff:0:0/96',    // IPv4-mapped
            '64:ff9b::/96',     // NAT64 (embeds an IPv4 address)
            '64:ff9b:1::/48',   // local-use NAT64
            '100::/64',         // discard
            '2001::/23',        // IETF protocol assignments (incl. Teredo)
            '2001:db8::/32',    // documentation
            '2002::/16',        // 6to4 (embeds an IPv4 address)
            '3fff::/20',        // documentation
            'fc00::/7',         // unique local
            'fe80::/10',        // link-local
            'fec0::/10',        // site-local (deprecated)
            'ff00::/8',         // multicast
        );
    }

    /**
     * Parses a comma/newline-separated host list into lowercase hostnames.
     * The default host is always included, so the module can never lock
     * itself out of its own default endpoint. Entries may be bare hosts or
     * full URLs; ports and paths are dropped.
     *
     * @param string $raw
     *
     * @return array<int, string>
     */
    public static function normalizeHostList($raw)
    {
        $hosts = array();
        $default = parse_url(self::DEFAULT_BASE, PHP_URL_HOST);
        $hosts[strtolower((string) $default)] = true;

        $lines = preg_split('/[\s,]+/', (string) $raw);
        foreach (is_array($lines) ? $lines : array() as $line) {
            $host = self::extractHost(trim($line));
            if ($host !== '') {
                $hosts[$host] = true;
            }
        }

        return array_keys($hosts);
    }

    /**
     * Whether $url may receive credentialed requests given $allowedHosts.
     *
     * @param string $url
     * @param array<int, string> $allowedHosts
     *
     * @return bool
     */
    public static function isAllowed($url, array $allowedHosts)
    {
        return self::rejectionReason($url, $allowedHosts) === null;
    }

    /**
     * Why $url is refused, or null when it is allowed. The reason is a short
     * machine code: not_url, not_https, credentials, blocked_ip, not_listed.
     *
     * @param string $url
     * @param array<int, string> $allowedHosts
     *
     * @return string|null
     */
    public static function rejectionReason($url, array $allowedHosts)
    {
        $url = (string) $url;
        if ($url === '' || preg_match('/[\x00-\x20\x7F]/', $url)) {
            return 'not_url';
        }
        $parts = @parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return 'not_url';
        }
        if (strtolower($parts['scheme']) !== 'https') {
            return 'not_https';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'credentials';
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            return 'not_url';
        }

        $host = strtolower(trim($parts['host'], '[]'));
        if ($host === '') {
            return 'not_url';
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false && self::isBlockedIp($host)) {
            return 'blocked_ip';
        }
        if (!in_array($host, $allowedHosts, true)) {
            return 'not_listed';
        }

        return null;
    }

    /**
     * Whether an IP literal falls in a non-public range.
     *
     * @param string $ip
     *
     * @return bool true for blocked (and for anything that is not an IP)
     */
    public static function isBlockedIp($ip)
    {
        $packed = @inet_pton((string) $ip);
        if ($packed === false) {
            return true;
        }
        $ranges = strlen($packed) === 4 ? self::blockedIpv4Ranges() : self::blockedIpv6Ranges();
        foreach ($ranges as $cidr) {
            if (self::inCidr($packed, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The trusted hosts configured for this shop (default host included).
     *
     * @return array<int, string>
     */
    public static function configuredHosts()
    {
        return self::normalizeHostList((string) Configuration::get(self::CONF_ALLOWED_HOSTS));
    }

    /**
     * Request-time check against the shop's configured trusted hosts.
     *
     * @param string $url
     *
     * @return bool
     */
    public static function isConfiguredAllowed($url)
    {
        return self::isAllowed($url, self::configuredHosts());
    }

    /**
     * @param string $packed inet_pton() output
     * @param string $cidr
     *
     * @return bool
     */
    private static function inCidr($packed, $cidr)
    {
        list($subnet, $bits) = explode('/', $cidr);
        $subnetPacked = @inet_pton($subnet);
        if ($subnetPacked === false || strlen($subnetPacked) !== strlen($packed)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = ($bits - ($bits % 8)) / 8;
        if ($bytes > 0 && substr($packed, 0, $bytes) !== substr($subnetPacked, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($subnetPacked[$bytes]) & $mask);
    }

    /**
     * @param string $entry
     *
     * @return string
     */
    private static function extractHost($entry)
    {
        if ($entry === '') {
            return '';
        }
        if (strpos($entry, '//') !== false) {
            $host = (string) parse_url($entry, PHP_URL_HOST);
        } else {
            $host = $entry;
            $slash = strpos($host, '/');
            if ($slash !== false) {
                $host = substr($host, 0, $slash);
            }
            if ($host !== '' && $host[0] === '[') {
                // [v6]:port
                $close = strpos($host, ']');
                $host = $close === false ? '' : substr($host, 1, $close - 1);
            } elseif (substr_count($host, ':') === 1) {
                // host:port, but never a bare IPv6 literal
                $host = substr($host, 0, strpos($host, ':'));
            }
        }

        $host = strtolower(trim($host, '[] '));

        // host names and IP literals only
        return preg_match('/^[a-z0-9.\-:]+$/', $host) ? $host : '';
    }
}

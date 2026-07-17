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
 * Minimal HTTP client for the CiteCue Delivery API.
 *
 * v2 endpoints are Bearer-authenticated with an org `ck_live_…` API key and
 * org-scoped; the v1 crawler registry feed is keyless. All timeouts are tight
 * because the page endpoint sits on the front-office request path — a slow
 * API must degrade to a passthrough, never a slow page.
 */
class CitecueClient
{
    const VERSION = '1.0.0';
    const CHANNEL = 'prestashop';

    /**
     * `GET /api/delivery/v2/config` — connection test + the org's delivery
     * projects (used to auto-select the project by shop domain).
     *
     * @param string $apiBase
     * @param string $apiKey
     *
     * @return array{status: int, headers: array<string, string>, body: string}|null
     */
    public static function getConfig($apiBase, $apiKey)
    {
        return self::request(
            self::url($apiBase, '/api/delivery/v2/config'),
            self::baseHeaders($apiKey),
            8000,
            4000
        );
    }

    /**
     * `GET /api/delivery/v2/page` — the enhanced page for a crawler request.
     * Serving and hit recording happen in this single call; a 304 answer to
     * the If-None-Match ETag is still counted as served by the API (the
     * plugin then serves its locally cached body).
     *
     * @param string $apiBase
     * @param string $apiKey
     * @param string $projectKey
     * @param string $url absolute URL of the current shop request
     * @param string|null $crawlerId matched crawler id (analytics bucket)
     * @param string|null $etag cached ETag for conditional revalidation
     *
     * @return array{status: int, headers: array<string, string>, body: string}|null
     */
    public static function getPage($apiBase, $apiKey, $projectKey, $url, $crawlerId, $etag)
    {
        $query = array('k' => $projectKey, 'u' => $url);
        if ($crawlerId) {
            $query['b'] = $crawlerId;
        }
        $headers = self::baseHeaders($apiKey);
        if ($etag) {
            $headers[] = 'If-None-Match: ' . $etag;
        }

        return self::request(
            self::url($apiBase, '/api/delivery/v2/page', $query),
            $headers,
            4000,
            2000
        );
    }

    /**
     * `GET /api/delivery/v2/llms.txt` — the project's llms.txt feed (404 when
     * the project has llms.txt serving disabled in the dashboard).
     *
     * @param string $apiBase
     * @param string $apiKey
     * @param string $projectKey
     * @param string|null $etag
     *
     * @return array{status: int, headers: array<string, string>, body: string}|null
     */
    public static function getLlmsTxt($apiBase, $apiKey, $projectKey, $etag)
    {
        $headers = self::baseHeaders($apiKey);
        if ($etag) {
            $headers[] = 'If-None-Match: ' . $etag;
        }

        return self::request(
            self::url($apiBase, '/api/delivery/v2/llms.txt', array('k' => $projectKey)),
            $headers,
            4000,
            2000
        );
    }

    /**
     * `GET /api/delivery/v1/crawlers` — keyless versioned crawler registry.
     *
     * @param string $apiBase
     *
     * @return array{status: int, headers: array<string, string>, body: string}|null
     */
    public static function getCrawlers($apiBase)
    {
        return self::request(
            self::url($apiBase, '/api/delivery/v1/crawlers'),
            array('Accept: application/json', 'User-Agent: ' . self::userAgent()),
            4000,
            2000
        );
    }

    /**
     * @param string $apiBase
     * @param string $path
     * @param array<string, string> $query
     *
     * @return string
     */
    private static function url($apiBase, $path, array $query = array())
    {
        $url = rtrim($apiBase, '/') . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }

    /**
     * @param string $apiKey
     *
     * @return array<int, string>
     */
    private static function baseHeaders($apiKey)
    {
        return array(
            'Authorization: Bearer ' . $apiKey,
            'X-Citecue-Channel: ' . self::CHANNEL,
            'User-Agent: ' . self::userAgent(),
        );
    }

    /**
     * @return string
     */
    private static function userAgent()
    {
        return 'citecue-prestashop/' . self::VERSION . ' (PrestaShop ' . _PS_VERSION_ . ')';
    }

    /**
     * GET the URL. Returns null on transport failure (DNS, TLS, timeout) so
     * callers can fail open; HTTP error statuses are returned normally.
     *
     * @param string $url
     * @param array<int, string> $headers
     * @param int $timeoutMs
     * @param int $connectTimeoutMs
     *
     * @return array{status: int, headers: array<string, string>, body: string}|null
     */
    private static function request($url, array $headers, $timeoutMs, $connectTimeoutMs)
    {
        if (function_exists('curl_init')) {
            return self::requestCurl($url, $headers, $timeoutMs, $connectTimeoutMs);
        }

        return self::requestStream($url, $headers, $timeoutMs);
    }

    /**
     * @param string $url
     * @param array<int, string> $headers
     * @param int $timeoutMs
     * @param int $connectTimeoutMs
     *
     * @return array{status: int, headers: array<string, string>, body: string}|null
     */
    private static function requestCurl($url, array $headers, $timeoutMs, $connectTimeoutMs)
    {
        $responseHeaders = array();
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $connectTimeoutMs,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$responseHeaders) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[Tools::strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return Tools::strlen($line);
            },
        ));

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $status === 0) {
            return null;
        }

        return array('status' => $status, 'headers' => $responseHeaders, 'body' => (string) $body);
    }

    /**
     * Stream fallback for the rare host without the cURL extension.
     *
     * @param string $url
     * @param array<int, string> $headers
     * @param int $timeoutMs
     *
     * @return array{status: int, headers: array<string, string>, body: string}|null
     */
    private static function requestStream($url, array $headers, $timeoutMs)
    {
        $context = stream_context_create(array(
            'http' => array(
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => $timeoutMs / 1000,
                'ignore_errors' => true,
                'follow_location' => 0,
            ),
        ));

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return null;
        }

        $status = 0;
        $responseHeaders = array();
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $status = (int) $m[1];
                    $responseHeaders = array();
                    continue;
                }
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[Tools::strtolower(trim($parts[0]))] = trim($parts[1]);
                }
            }
        }
        if ($status === 0) {
            return null;
        }

        return array('status' => $status, 'headers' => $responseHeaders, 'body' => (string) $body);
    }
}

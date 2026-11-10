<?php

$default = CitecueEndpoint::normalizeHostList('');

return array(
    'the default base URL is allowed' => function () use ($default) {
        assert_same(array('app.citecue.com'), $default);
        assert_true(CitecueEndpoint::isAllowed('https://app.citecue.com', $default));
        assert_true(CitecueEndpoint::isAllowed('https://APP.citecue.com/', $default));
    },
    'http is refused even for the default host' => function () use ($default) {
        assert_same('not_https', CitecueEndpoint::rejectionReason('http://app.citecue.com', $default));
        assert_same('not_https', CitecueEndpoint::rejectionReason('ftp://app.citecue.com', $default));
    },
    'an unlisted host is refused' => function () use ($default) {
        assert_same('not_listed', CitecueEndpoint::rejectionReason('https://evil.example', $default));
        assert_same('not_listed', CitecueEndpoint::rejectionReason('https://app.citecue.com.evil.example', $default));
        assert_same('not_listed', CitecueEndpoint::rejectionReason('https://localhost', $default));
    },
    'a listed self-hosted host is allowed, with or without a port' => function () {
        $hosts = CitecueEndpoint::normalizeHostList("citecue.internal.example, https://cc.example.org:8443/path\nlocalhost:3000");
        assert_same(array('app.citecue.com', 'citecue.internal.example', 'cc.example.org', 'localhost'), $hosts);
        assert_true(CitecueEndpoint::isAllowed('https://citecue.internal.example', $hosts));
        assert_true(CitecueEndpoint::isAllowed('https://cc.example.org:8443', $hosts));
        assert_true(CitecueEndpoint::isAllowed('https://localhost:3443', $hosts));
    },
    'credentials, queries and junk are refused' => function () use ($default) {
        assert_same('credentials', CitecueEndpoint::rejectionReason('https://user:pw@app.citecue.com', $default));
        assert_same('not_url', CitecueEndpoint::rejectionReason('https://app.citecue.com?x=1', $default));
        assert_same('not_url', CitecueEndpoint::rejectionReason('https://app.citecue.com#x', $default));
        assert_same('not_url', CitecueEndpoint::rejectionReason('app.citecue.com', $default));
        assert_same('not_url', CitecueEndpoint::rejectionReason('', $default));
        assert_same('not_url', CitecueEndpoint::rejectionReason("https://app.citecue.com\r\nX: y", $default));
    },
    'URL-parser confusion never yields a trusted host' => function () use ($default) {
        assert_same('credentials', CitecueEndpoint::rejectionReason('https://evil.example\\@app.citecue.com', $default));
        assert_same('credentials', CitecueEndpoint::rejectionReason('https://app.citecue.com\\@evil.example', $default));
        assert_same('not_url', CitecueEndpoint::rejectionReason('https://app.citecue.com#@evil.example', $default));
        assert_same('not_url', CitecueEndpoint::rejectionReason('https://app.citecue.com?@evil.example', $default));
        assert_same('not_listed', CitecueEndpoint::rejectionReason('https://app.citecue.com.', $default));
        assert_same('not_listed', CitecueEndpoint::rejectionReason('https://2130706433', $default));
        assert_same('not_listed', CitecueEndpoint::rejectionReason('https://0x7f.1', $default));
    },
    'junk entries in the host list are dropped' => function () {
        assert_same(array('app.citecue.com', 'ok.example'), CitecueEndpoint::normalizeHostList('<script>, ok.example, b"c, ev\\il'));
    },
    'private and reserved IP literals are refused even when listed' => function () {
        $blocked = array(
            '127.0.0.1', '127.8.9.10', '10.1.2.3', '172.16.0.1', '172.31.255.255', '192.168.1.1',
            '169.254.169.254', '100.64.0.1', '100.127.255.254', '0.0.0.0', '192.0.2.10',
            '198.51.100.7', '203.0.113.9', '198.18.0.1', '224.0.0.1', '255.255.255.255',
            '::1', '::', 'fd00::1', 'fc00::1', 'fe80::1', '2001:db8::1', '::ffff:127.0.0.1',
            '::ffff:10.0.0.1', '64:ff9b::a00:1', '2002:a00:1::1', 'ff02::1', '3fff::1',
        );
        foreach ($blocked as $ip) {
            $host = strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip;
            $hosts = CitecueEndpoint::normalizeHostList($host);
            assert_same('blocked_ip', CitecueEndpoint::rejectionReason('https://' . $host, $hosts), $ip);
        }
    },
    'public IP literals pass the IP guard but still need listing' => function () use ($default) {
        foreach (array('8.8.8.8', '100.128.0.1', '172.32.0.1', '[2606:4700::1111]') as $host) {
            assert_same('not_listed', CitecueEndpoint::rejectionReason('https://' . $host, $default), $host);
            assert_true(CitecueEndpoint::isAllowed('https://' . $host, CitecueEndpoint::normalizeHostList($host)), $host);
        }
    },
    'the configured host list is read from Configuration' => function () {
        assert_true(!CitecueEndpoint::isConfiguredAllowed('https://cc.example.org'));
        Configuration::updateValue(CitecueEndpoint::CONF_ALLOWED_HOSTS, 'cc.example.org');
        assert_true(CitecueEndpoint::isConfiguredAllowed('https://cc.example.org'));
        assert_true(CitecueEndpoint::isConfiguredAllowed('https://app.citecue.com'));
    },
);

<?php

return array(
    'formatFor follows the API rule for markdown' => function () {
        assert_same('markdown', CitecueClient::formatFor('text/markdown'));
        assert_same('markdown', CitecueClient::formatFor('text/html, text/markdown;q=0.8'));
        assert_same('markdown', CitecueClient::formatFor('TEXT/MARKDOWN; charset=utf-8'));
        assert_same('html', CitecueClient::formatFor('text/markdown;q=0'));
        assert_same('html', CitecueClient::formatFor('*/*'));
        assert_same('html', CitecueClient::formatFor('text/*'));
        assert_same('html', CitecueClient::formatFor('text/html,application/xhtml+xml'));
        assert_same('html', CitecueClient::formatFor(''));
        assert_same('html', CitecueClient::formatFor(null));
    },
    'no request leaves for a refused base URL' => function () {
        $key = 'ck_live_' . str_repeat('a', 48);
        assert_same(null, CitecueClient::getConfig('http://app.citecue.com', $key));
        assert_same(null, CitecueClient::getConfig('https://127.0.0.1', $key));
        assert_same(null, CitecueClient::getPage('https://evil.example', $key, 'pk', 'https://s/', 'gptbot', null));
        assert_same(null, CitecueClient::getLlmsTxt('https://169.254.169.254', $key, 'pk', null));
        assert_same(null, CitecueClient::getCrawlers('http://localhost:3000'));
    },
);

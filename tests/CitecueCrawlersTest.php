<?php

$storeFeed = function (array $rows) {
    Configuration::updateValue(CitecueCrawlers::CONF_REGISTRY, json_encode(array('fetched_at' => time(), 'crawlers' => $rows)));
};
$id = function ($ua) {
    $match = CitecueCrawlers::match($ua);

    return $match === null ? null : $match['id'];
};

return array(
    'bundled defaults match fetching crawlers, longest token first' => function () use ($id) {
        assert_same('gptbot', $id('Mozilla/5.0 (compatible; GPTBot/1.2)'));
        assert_same('chatgpt-user', $id('Mozilla/5.0 (compatible; GPTBot/1.2) ChatGPT-User/1.0'));
        assert_same('gptbot', $id('GPTBOT/1.0'));
        assert_same(null, $id('Mozilla/5.0 (compatible; Googlebot/2.1)'));
        assert_same(null, $id(''));
        assert_same(null, $id(null));
    },
    'robots-only tokens win the match and are not served' => function () use ($id) {
        assert_same(null, $id('Google-Extended'));
        assert_same(null, $id('Mozilla/5.0 (compatible; Applebot-Extended/1.0; GPTBot/1.2)'));
        $any = CitecueCrawlers::matchAny('Mozilla/5.0 (compatible; Applebot-Extended/1.0; GPTBot/1.2)');
        assert_same('applebot-extended', $any['id']);
    },
    'a stored feed replaces the defaults' => function () use ($storeFeed, $id) {
        $storeFeed(array(
            array('id' => 'gptbot', 'token' => 'GPTBot', 'fetchesPages' => true),
            array('id' => 'bytespider', 'token' => 'Bytespider', 'fetchesPages' => false),
            array('id' => 'newai-bot', 'token' => 'NewAI-Bot', 'fetchesPages' => true),
        ));
        assert_same(null, $id('Mozilla/5.0 (compatible; Bytespider; spider-feedback@bytedance.com)'));
        assert_same('newai-bot', $id('Mozilla/5.0 (compatible; NewAI-Bot/1.0)'));
        // dropped from the feed entirely
        assert_same(null, $id('CCBot/2.0 (https://commoncrawl.org/faq/)'));
    },
    'only a literal true switches serving on' => function () use ($storeFeed, $id) {
        $storeFeed(array(
            array('id' => 'a', 'token' => 'BotA', 'fetchesPages' => 'false'),
            array('id' => 'b', 'token' => 'BotB', 'fetchesPages' => 1),
            array('id' => 'c', 'token' => 'BotC', 'fetchesPages' => true),
            array('id' => '', 'token' => 'BotD', 'fetchesPages' => true),
            'junk',
        ));
        assert_same(null, $id('BotA'));
        assert_same(null, $id('BotB'));
        assert_same('c', $id('BotC'));
        assert_same(null, $id('BotD'));
    },
    'a 1.0.0 snapshot without flags still serves its rows' => function () use ($storeFeed, $id) {
        $storeFeed(array(array('id' => 'gptbot', 'token' => 'GPTBot')));
        assert_same('gptbot', $id('GPTBot/1.2'));
    },
    'an empty stored snapshot falls back to the defaults' => function () use ($storeFeed, $id) {
        $storeFeed(array());
        assert_same('claudebot', $id('ClaudeBot/1.0'));
        assert_same(count(CitecueCrawlers::defaults()), count(CitecueCrawlers::all()));
    },
);

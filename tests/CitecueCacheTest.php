<?php

$dir = _PS_CACHE_DIR_ . 'citecue/';
$files = function () use ($dir) {
    $list = glob($dir . '*.json');

    return is_array($list) ? count($list) : 0;
};

return array(
    'the sweep removes expired misses and keeps live entries' => function () use ($dir, $files) {
        CitecueCache::clearAll();
        $cache = new CitecueCache();
        for ($i = 0; $i < 50; ++$i) {
            $cache->setNegative('page|p|html|https://shop.example/?q=' . $i, 60);
        }
        $cache->setPositive('page|p|html|https://shop.example/a', '"e"', '<html>a</html>', 'enriched');
        $cache->setNegative('page|p|html|https://shop.example/fresh', 3600);
        assert_same(52, $files());

        $removed = $cache->sweep(time() + 120);
        assert_same(50, $removed);
        assert_same(2, $files());
        $entry = $cache->get('page|p|html|https://shop.example/a');
        assert_same('<html>a</html>', $entry['body']);
    },
    'the sweep removes positive entries past MAX_ENTRY_AGE and old temp files' => function () use ($dir, $files) {
        CitecueCache::clearAll();
        $cache = new CitecueCache();
        $cache->setPositive('page|p|html|https://shop.example/big', '"e"', str_repeat('x', 10000), 'enriched');
        touch($dir . 'abc.json.1.tmp', time() - 7200);
        $removed = $cache->sweep(time() + CitecueCache::MAX_ENTRY_AGE + 10);
        assert_same(2, $removed);
        assert_same(0, $files());
    },
    'the sweep caps the directory, oldest first' => function () use ($dir, $files) {
        CitecueCache::clearAll();
        $cache = new CitecueCache();
        $total = CitecueCache::MAX_FILES + 25;
        for ($i = 0; $i < $total; ++$i) {
            $cache->setNegative('k' . $i, 3600);
        }
        $oldest = $dir . md5('k0') . '.json';
        touch($oldest, time() - 600);
        assert_same($total, $files());
        assert_same(25, $cache->sweep());
        assert_same(CitecueCache::MAX_FILES, $files());
        assert_true(!file_exists($oldest), 'the oldest entry goes first');
    },
    'maybeSweep runs at most once per interval' => function () use ($dir) {
        CitecueCache::clearAll();
        @unlink($dir . 'sweep.stamp');
        $cache = new CitecueCache();
        $cache->setNegative('gone', 60);
        touch($dir . md5('gone') . '.json', time() - CitecueCache::MAX_ENTRY_AGE - 1);
        assert_same(1, CitecueCache::maybeSweep());
        assert_same(null, CitecueCache::maybeSweep());
    },
    'an entry keeps its content type' => function () {
        $cache = new CitecueCache();
        $cache->setPositive('page|p|markdown|u', '"e"', '# md', 'enriched', 0, 'text/markdown; charset=utf-8');
        $entry = $cache->get('page|p|markdown|u');
        assert_same('text/markdown; charset=utf-8', $entry['type']);
        $cache->refresh('page|p|markdown|u', $entry, 300);
        $entry = $cache->get('page|p|markdown|u');
        assert_same('text/markdown; charset=utf-8', $entry['type']);
    },
);

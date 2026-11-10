<?php

$key = 'ck_live_' . str_repeat('0123456789abcdef', 3);

return array(
    'without PhpEncryption the key is stored raw and read back' => function () use ($key) {
        assert_true(!CitecueKeyStore::canEncrypt());
        CitecueKeyStore::set($key);
        assert_same($key, Configuration::get(CitecueKeyStore::CONF_KEY));
        assert_same($key, CitecueKeyStore::get());
    },
    'with PhpEncryption the key is encrypted at rest' => function () use ($key) {
        citecue_test_define_cipher();
        assert_true(CitecueKeyStore::canEncrypt());
        CitecueKeyStore::set($key);
        $stored = (string) Configuration::get(CitecueKeyStore::CONF_KEY);
        assert_same(0, strpos($stored, 'enc1:'));
        assert_true(strpos($stored, $key) === false, 'the raw key is not in the stored value');
        assert_same($key, CitecueKeyStore::get());
    },
    'a 1.0.0 raw key is migrated in place' => function () use ($key) {
        citecue_test_define_cipher();
        Configuration::updateValue(CitecueKeyStore::CONF_KEY, $key);
        assert_same($key, CitecueKeyStore::get());
        CitecueKeyStore::migrate();
        assert_same(0, strpos((string) Configuration::get(CitecueKeyStore::CONF_KEY), 'enc1:'));
        assert_same($key, CitecueKeyStore::get());
    },
    'an undecryptable value reads as no key, but is still reported as stored' => function () {
        citecue_test_define_cipher();
        Configuration::updateValue(CitecueKeyStore::CONF_KEY, 'enc1:not-a-ciphertext');
        assert_same('', CitecueKeyStore::get());
        assert_true(CitecueKeyStore::hasStored());
    },
    'the mask shows only the dashboard prefix' => function () use ($key) {
        assert_same('ck_live_0123456…', CitecueKeyStore::mask($key));
        assert_same('••••••••', CitecueKeyStore::mask('short'));
        assert_same('', CitecueKeyStore::mask(''));
    },
    'keys that could break the header are refused' => function () use ($key) {
        assert_true(CitecueKeyStore::isWellFormed($key));
        assert_true(!CitecueKeyStore::isWellFormed("ck_live_abc\r\nX-Evil: 1"));
        assert_true(!CitecueKeyStore::isWellFormed('ck live abcdefgh'));
        assert_true(!CitecueKeyStore::isWellFormed('short'));
    },
);

/**
 * Defines a stand-in for PrestaShop's PhpEncryption (same API: constructor
 * takes the hex key, decrypt() returns false on a wrong key) on first use.
 */
function citecue_test_define_cipher()
{
    if (class_exists('PhpEncryption', false)) {
        return;
    }
    define('_NEW_COOKIE_KEY_', bin2hex(str_repeat('k', 32)));
    require __DIR__ . '/fixtures/PhpEncryption.php';
}

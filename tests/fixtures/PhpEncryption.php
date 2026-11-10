<?php
/**
 * Stand-in for PrestaShop's PhpEncryption with the same API: the constructor
 * takes the shop's hex key, encrypt() returns a string, decrypt() returns the
 * plaintext (false on a wrong key) and throws on a malformed ciphertext.
 * Loaded on demand by CitecueKeyStoreTest, after the no-encryption cases.
 */
class PhpEncryption
{
    private $key;

    public function __construct($hex)
    {
        $this->key = hex2bin($hex);
    }

    public function encrypt($plain)
    {
        $iv = random_bytes(16);

        return bin2hex($iv . openssl_encrypt($plain, 'aes-256-cbc', $this->key, OPENSSL_RAW_DATA, $iv));
    }

    public function decrypt($cipher)
    {
        $raw = @hex2bin($cipher);
        if ($raw === false || strlen($raw) < 17) {
            throw new Exception('bad format');
        }

        return openssl_decrypt(substr($raw, 16), 'aes-256-cbc', $this->key, OPENSSL_RAW_DATA, substr($raw, 0, 16));
    }
}

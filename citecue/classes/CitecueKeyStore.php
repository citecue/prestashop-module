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
 * Storage for the org API key (`ck_live_…`).
 *
 * The key is encrypted at rest with PrestaShop's own PhpEncryption and the
 * shop's _NEW_COOKIE_KEY_ (both present from 1.7.1.0 through 9.x), stored as
 * `enc1:<ciphertext>`. On a host where that is unavailable it falls back to
 * the raw value, which is also what 1.0.0 stored; get() reads both. The key is
 * never rendered back into the settings form, only a masked hint.
 */
class CitecueKeyStore
{
    const CONF_KEY = 'CITECUE_API_KEY';

    const PREFIX = 'enc1:';

    /**
     * The usable key, or '' when none is stored or it cannot be decrypted
     * (for example after the shop's cookie key was regenerated).
     *
     * @return string
     */
    public static function get()
    {
        return self::decode((string) Configuration::get(self::CONF_KEY));
    }

    /**
     * Whether a value is stored, even one that no longer decrypts.
     *
     * @return bool
     */
    public static function hasStored()
    {
        return trim((string) Configuration::get(self::CONF_KEY)) !== '';
    }

    /**
     * @param string $apiKey
     *
     * @return bool
     */
    public static function set($apiKey)
    {
        return Configuration::updateValue(self::CONF_KEY, self::encode(trim((string) $apiKey)));
    }

    /**
     * Encrypts a stored raw (1.0.0) key in place. No-op when already
     * encrypted, empty, or encryption is unavailable.
     */
    public static function migrate()
    {
        $raw = (string) Configuration::get(self::CONF_KEY);
        if ($raw === '' || strpos($raw, self::PREFIX) === 0) {
            return;
        }
        self::set($raw);
    }

    /**
     * A display hint: the same 15-character prefix the CiteCue dashboard
     * shows for the key on its API & MCP access page (`ck_live_` plus seven
     * of its 48 hex characters), so the merchant can tell which key is saved.
     *
     * @param string $apiKey
     *
     * @return string
     */
    public static function mask($apiKey)
    {
        $apiKey = (string) $apiKey;
        if ($apiKey === '') {
            return '';
        }
        if (strlen($apiKey) < 32) {
            return '••••••••';
        }

        return substr($apiKey, 0, 15) . '…';
    }

    /**
     * Keys are opaque tokens; refuse anything that could break out of the
     * Authorization header.
     *
     * @param string $apiKey
     *
     * @return bool
     */
    public static function isWellFormed($apiKey)
    {
        return (bool) preg_match('/^[A-Za-z0-9_\-.]{8,200}$/', (string) $apiKey);
    }

    /**
     * Whether values are encrypted on this shop.
     *
     * @return bool
     */
    public static function canEncrypt()
    {
        return self::cipher() !== null;
    }

    /**
     * @param string $apiKey
     *
     * @return string
     */
    private static function encode($apiKey)
    {
        if ($apiKey === '') {
            return '';
        }
        $cipher = self::cipher();
        if ($cipher !== null) {
            try {
                $encrypted = $cipher->encrypt($apiKey);
                if (is_string($encrypted) && $encrypted !== '') {
                    return self::PREFIX . $encrypted;
                }
            } catch (Exception $e) {
                // fall through to the raw value
            } catch (Throwable $e) {
                // fall through to the raw value
            }
        }

        return $apiKey;
    }

    /**
     * @param string $stored
     *
     * @return string
     */
    private static function decode($stored)
    {
        $stored = trim($stored);
        if ($stored === '' || strpos($stored, self::PREFIX) !== 0) {
            return $stored;
        }
        $cipher = self::cipher();
        if ($cipher === null) {
            return '';
        }
        try {
            $plain = $cipher->decrypt(substr($stored, strlen(self::PREFIX)));
        } catch (Exception $e) {
            return '';
        } catch (Throwable $e) {
            return '';
        }

        return is_string($plain) ? trim($plain) : '';
    }

    /**
     * @return PhpEncryption|null
     */
    private static function cipher()
    {
        if (!class_exists('PhpEncryption') || !defined('_NEW_COOKIE_KEY_') || !_NEW_COOKIE_KEY_) {
            return null;
        }
        try {
            return new PhpEncryption(_NEW_COOKIE_KEY_);
        } catch (Exception $e) {
            return null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

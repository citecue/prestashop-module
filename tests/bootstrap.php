<?php
/**
 * Minimal PrestaShop stand-ins for the module's framework-free classes, so
 * the tests run on plain PHP: `php tests/run.php`. No PrestaShop install and
 * no Composer packages needed.
 */
define('_PS_VERSION_', '8.1.7');
define('CITECUE_TEST_DIR', rtrim(sys_get_temp_dir(), '/') . '/citecue-tests-' . getmypid());
define('_PS_CACHE_DIR_', CITECUE_TEST_DIR . '/cache/');

class Configuration
{
    public static $values = array();

    public static function get($key, $idLang = null, $idShopGroup = null, $idShop = null, $default = false)
    {
        return array_key_exists($key, self::$values) ? self::$values[$key] : $default;
    }

    public static function updateValue($key, $value)
    {
        self::$values[$key] = $value;

        return true;
    }

    public static function deleteByName($key)
    {
        unset(self::$values[$key]);

        return true;
    }
}

class Tools
{
    public static function strtolower($s)
    {
        return mb_strtolower((string) $s);
    }

    public static function strlen($s)
    {
        return mb_strlen((string) $s);
    }
}

$moduleDir = dirname(__DIR__) . '/citecue';
require $moduleDir . '/classes/CitecueEndpoint.php';
require $moduleDir . '/classes/CitecueKeyStore.php';
require $moduleDir . '/classes/CitecueClient.php';
require $moduleDir . '/classes/CitecueCrawlers.php';
require $moduleDir . '/classes/CitecueCache.php';

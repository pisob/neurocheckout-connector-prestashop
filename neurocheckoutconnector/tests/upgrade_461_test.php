<?php
define('_PS_VERSION_', '9.1.4');
class Configuration {
    public static array $writes = [];
    public static function updateValue($key, $value) { self::$writes[$key] = $value; return true; }
}
require __DIR__ . '/../upgrade/upgrade-4.6.1.php';
$module = new class { public function registerHook($hook) { return $hook === 'displayBackOfficeHeader'; } };
if (!upgrade_module_4_6_1($module)) throw new RuntimeException('Upgrade failed');
foreach (array_keys(Configuration::$writes) as $key) {
    if (strpos($key, 'API_KEY') !== false) throw new RuntimeException('Upgrade overwrote credentials');
}
echo "4.6.1 in-place upgrade preservation test passed.\n";

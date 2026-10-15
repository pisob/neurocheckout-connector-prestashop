<?php
define('_PS_VERSION_', '1.7.8.11');
class Configuration {
    public static function updateValue($key, $value) { throw new RuntimeException('Settings must not change'); }
}
require __DIR__ . '/../upgrade/upgrade-4.6.8.php';
if (!upgrade_module_4_6_8(new stdClass())) throw new RuntimeException('Upgrade failed');
$source = file_get_contents(__DIR__ . '/../neurocheckoutconnector.php');
if (!preg_match("/\\\$this->version = '([^']+)'/", $source, $match) || version_compare($match[1], '4.6.8', '<')) throw new RuntimeException('Version mismatch');
echo "4.6.8 in-place upgrade preserves configuration and synchronization state.\n";

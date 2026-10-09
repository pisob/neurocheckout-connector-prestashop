<?php
define('_PS_VERSION_', '9.1.4');
class Configuration {
    public static function updateValue($key, $value) { throw new RuntimeException('Settings must not change'); }
}
require __DIR__ . '/../upgrade/upgrade-4.6.7.php';
if (!upgrade_module_4_6_7(new stdClass())) throw new RuntimeException('Upgrade failed');
$source = file_get_contents(__DIR__ . '/../src/Community/PrestashopSourceSnapshot.php');
if (strpos($source, 'customer.is_guest AS customer_is_guest') === false) {
    throw new RuntimeException('Native guest identity missing');
}
echo "4.6.7 upgrade and native customer identity contract passed.\n";

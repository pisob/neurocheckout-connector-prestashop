<?php
define('_PS_VERSION_', '9.1.4');
class Configuration {
    public static array $values = ['NC_API_KEY'=>'synthetic-secret', 'NC_SHOP_EXTERNAL_ID'=>'synthetic-shop'];
    public static function updateValue($key, $value) { self::$values[$key]=$value; return true; }
}
require __DIR__ . '/../upgrade/upgrade-4.6.6.php';
if (!upgrade_module_4_6_6(new stdClass())) throw new RuntimeException('Upgrade failed');
if (Configuration::$values !== ['NC_API_KEY'=>'synthetic-secret', 'NC_SHOP_EXTERNAL_ID'=>'synthetic-shop',
    'NC_CONNECTOR_UPDATE_CHECKED_AT'=>0,'NC_CONNECTOR_UPDATE_STATUS'=>'',
    'NC_CONNECTOR_LATEST_VERSION'=>'','NC_CONNECTOR_RELEASE_URL'=>'']) {
    throw new RuntimeException('Upgrade changed merchant settings');
}
echo "4.6.6 upgrade preserves merchant settings.\n";

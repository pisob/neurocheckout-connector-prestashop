<?php
declare(strict_types=1);
namespace NeuroCheckout\BackOffice {
    class GeneralFormRenderer { public function __construct($module) {} public function render(): string { return ''; } }
    class ExecutionFormRenderer extends GeneralFormRenderer {}
    class IAFormRenderer extends GeneralFormRenderer {}
    class MonitoringFormRenderer extends GeneralFormRenderer {}
}
namespace NeuroCheckout\I18n {
    class ModuleTranslator {
        public static function trans($key) { return $key; }
        public static function backOfficeCatalog($interval) { return []; }
    }
}
namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    define('_PS_VERSION_', '9.1.4');
    define('_COOKIE_KEY_', 'synthetic-configuration-test-key');
    class Configuration {
        public static array $values = [];
        public static string $fail = '';
        public static function get($key) { return self::$values[$key] ?? ''; }
        public static function updateValue($key, $value) {
            if ($key === self::$fail) { return false; }
            self::$values[$key] = $value; return true;
        }
    }
    class Tools {
        public static function getValue($key) { return $_POST[$key] ?? false; }
        public static function getIsset($key) { return array_key_exists($key, $_POST); }
        public static function isSubmit($key) { return self::getIsset($key); }
    }
    class PrestaShopLogger { public static function addLog($message, $severity) {} }
    class Module {
        public $context;
        public $name = 'neurocheckoutconnector';
        public $_path = '/module/';
        public function displayError($message) { return 'ERROR:'.$message; }
        public function display($file, $template) { return 'FORM'; }
    }
    require __DIR__.'/../neurocheckoutconnector/neurocheckoutconnector.php';
    use NeuroCheckout\Security\SecretConfiguration;
    function check($condition, $message) { if (!$condition) { throw new \RuntimeException($message); } }
    $module = (new \ReflectionClass('NeuroCheckoutConnector'))->newInstanceWithoutConstructor();
    $smarty = new class { public array $values = []; public function assign($values) { $this->values = array_merge($this->values, $values); } };
    $module->context = (object) ['smarty'=>$smarty, 'link'=>new class { public function getAdminLink(...$args) { return '/admin'; } }];
    Configuration::$values = ['NC_CONNECTOR_UPDATE_CHECKED_AT'=>time(), 'NC_API_ENDPOINT'=>'https://neurocheckout.com', 'NC_SHOP_EXTERNAL_ID'=>'synthetic-shop'];
    check(SecretConfiguration::set('NC_API_KEY', 'synthetic-secret'), 'key saved');
    $module->markApiTestValidationSuccess();
    check($module->isApiTestValidationCurrent(), 'initial validation');
    $before = Configuration::$values;
    foreach (['https://evil.example', ['invalid']] as $invalid) {
        $_POST = ['submitNcSaveConfig'=>1, 'NC_API_ENDPOINT'=>$invalid, 'NC_API_KEY'=>'replacement'];
        check(strpos($module->getContent(), 'ERROR:') === 0, 'invalid form handled without exception');
        check(Configuration::$values === $before, 'invalid input does not mutate settings');
        check(!$smarty->values['nc_save_success'], 'no false success');
    }
    $_POST = ['submitNcSaveConfig'=>1, 'NC_API_ENDPOINT'=>' https://www.neurocheckout.com/ ', 'NC_API_KEY'=>'', 'NC_SHOP_EXTERNAL_ID'=>'synthetic-shop'];
    check($module->getContent() === 'FORM', 'official address saved');
    check(Configuration::get('NC_API_ENDPOINT') === 'https://www.neurocheckout.com', 'endpoint normalized');
    check(SecretConfiguration::get('NC_API_KEY') === 'synthetic-secret', 'blank key preserved');
    check(!$module->isApiTestValidationCurrent(), 'endpoint change invalidates old test');
    $module->markApiTestValidationSuccess();
    $module->getContent();
    check($module->isApiTestValidationCurrent(), 'unchanged save preserves validation');
    foreach (['NC_API_KEY'=>'new-synthetic-key', 'NC_SHOP_EXTERNAL_ID'=>'another-shop'] as $field=>$value) {
        $_POST[$field] = $value;
        check($module->getContent() === 'FORM', 'changed setting saved');
        check(!$module->isApiTestValidationCurrent(), 'changed credentials require new test');
        $module->markApiTestValidationSuccess();
        check($module->isApiTestValidationCurrent(), 'new test validates current settings');
    }
    $_POST['NC_SHOP_EXTERNAL_ID'] = '';
    check(strpos($module->getContent(), 'ERROR:') === 0, 'empty shop refused');
    $_POST['NC_SHOP_EXTERNAL_ID'] = 'new-shop';
    Configuration::$fail = 'NC_SHOP_EXTERNAL_ID';
    check(strpos($module->getContent(), 'ERROR:config_save_failed') === 0, 'write failure handled safely');
    check(!$module->isApiTestValidationCurrent(), 'failed save invalidates test');
    check(!$smarty->values['nc_save_success'], 'write failure not reported as saved');
    Configuration::$fail = '';
    Configuration::$values['NC_API_ENDPOINT'] = 'https://untrusted.example';
    $module->markApiTestValidationSuccess();
    check(!$module->isApiTestValidationCurrent(), 'legacy validation cannot authorize untrusted endpoint');
    echo "Configuration save, official URL, validation and failure regressions passed.\n";
}

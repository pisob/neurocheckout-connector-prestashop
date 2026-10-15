<?php
declare(strict_types=1);
namespace NeuroCheckout\Http {
    class SecureHttpClient {
        public static $calls = 0;
        public static $result = [];
        public function checkConnectorVersion($version) { self::$calls++; return self::$result; }
    }
}
namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    define('_PS_VERSION_', '1.7.8.11');
    class Module { public $version = '4.6.8'; }
    class Configuration {
        public static $values = [];
        public static function get($key) { return self::$values[$key] ?? ''; }
        public static function updateValue($key, $value) { self::$values[$key] = $value; return true; }
    }
    class PrestaShopLogger { public static function addLog($message, $severity) {} }
    require __DIR__.'/../neurocheckoutconnector/neurocheckoutconnector.php';
    use NeuroCheckout\Http\SecureHttpClient;
    function check($condition, $message) { if (!$condition) { throw new \RuntimeException($message); } }
    $module = (new \ReflectionClass('NeuroCheckoutConnector'))->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod($module, 'refreshConnectorUpdateStatus');
    $method->setAccessible(true);
    $run = function () use ($module, $method) { $method->invoke($module); };
    SecureHttpClient::$result = ['success'=>false];
    $run(); $run();
    check(SecureHttpClient::$calls === 1, 'Failures are throttled for five minutes');
    check(!Configuration::get('NC_CONNECTOR_UPDATE_CHECKED_AT'), 'A failure is not a successful observation');
    Configuration::$values['NC_CONNECTOR_UPDATE_ATTEMPTED_AT'] = time() - 301;
    $valid = ['platform'=>'prestashop','status'=>'current','latest_version'=>'4.6.8','release_url'=>'https://github.com/pisob/neurocheckout-connector-prestashop/releases/tag/v4.6.8'];
    SecureHttpClient::$result = ['success'=>true,'body'=>json_encode($valid)];
    $run(); $run();
    check(SecureHttpClient::$calls === 2, 'Success is cached for one day');
    check(Configuration::get('NC_CONNECTOR_REPORTED_VERSION') === '4.6.8', 'Observed version stored');
    $module->version = '4.6.9';
    Configuration::$values['NC_CONNECTOR_UPDATE_ATTEMPTED_AT'] = time() - 301;
    $run();
    check(SecureHttpClient::$calls === 3, 'Upgrade bypasses the previous version daily cache');
    check(Configuration::get('NC_CONNECTOR_REPORTED_VERSION') === '4.6.9', 'Actual upgraded version reported');
    foreach (['wrong_platform','bad_url','invalid_json'] as $case) {
        Configuration::$values = [];
        $payload = $valid;
        if ($case === 'wrong_platform') { $payload['platform'] = 'magento'; }
        if ($case === 'bad_url') { $payload['release_url'] .= '?redirect=https://untrusted.invalid'; }
        SecureHttpClient::$result = ['success'=>true,'body'=>$case === 'invalid_json' ? '{' : json_encode($payload)];
        $run();
        check(!Configuration::get('NC_CONNECTOR_UPDATE_CHECKED_AT'), 'Invalid response not cached as success');
    }
    echo "PASS: signed version reporting cadence, retries, upgrade and invalid-response rejection.\n";
}

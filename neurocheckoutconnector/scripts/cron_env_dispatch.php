<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$rootDir = realpath(__DIR__ . '/../../../');
if ($rootDir === false) {
    exit(2);
}

$configPath = $rootDir . '/config/config.inc.php';
$initPath = $rootDir . '/init.php';

if (!is_file($configPath) || !is_file($initPath)) {
    exit(3);
}

require_once $configPath;
require_once $initPath;
require_once __DIR__ . '/../vendor/autoload.php';

$expectedToken = \NeuroCheckout\Security\SecretConfiguration::get('NEURO_CRON_TOKEN');

if ($expectedToken === '') {
    exit(4);
}

$module = Module::getInstanceByName('neurocheckoutconnector');
if (!$module) {
    exit(5);
}

if (
    !method_exists($module, 'acquireCronLock')
    || !method_exists($module, 'releaseCronLock')
    || !method_exists($module, 'executeCronRun')
) {
    exit(6);
}

$runtimeEnv = method_exists($module, 'detectRuntimeEnvironmentMode')
    ? (string) $module->detectRuntimeEnvironmentMode()
    : 'production';

if ($runtimeEnv !== 'local' && $runtimeEnv !== 'production') {
    $runtimeEnv = 'production';
}

if ($runtimeEnv === 'production') {
    $executedViaHttp = runSignedHttpCronIfPossible($expectedToken);
    if ($executedViaHttp) {
        exit(0);
    }
}

if (!$module->acquireCronLock()) {
    exit(0);
}

try {
    $module->executeCronRun(false, false);
} catch (Throwable $e) {
    if (class_exists('PrestaShopLogger')) {
        PrestaShopLogger::addLog(
            '[NC] Env cron dispatch fatal: ' . $e->getMessage(),
            3
        );
    }
    exit(7);
} finally {
    $module->releaseCronLock();
}

exit(0);

function runSignedHttpCronIfPossible(string $token): bool
{
    if (!function_exists('curl_init')) {
        return false;
    }

    $apiKey = trim(\NeuroCheckout\Security\SecretConfiguration::get('NC_API_KEY'));
    if ($apiKey === '') {
        return false;
    }

    $baseUrl = buildShopBaseUrl();
    if ($baseUrl === '') {
        return false;
    }

    $timestamp = time();
    try {
        $nonce = bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        return false;
    }

    $signature = hash_hmac(
        'sha256',
        $timestamp . '.' . $nonce . '.' . $token,
        $apiKey
    );

    $url = $baseUrl . '/module/neurocheckoutconnector/cron';

    $curlHandle = curl_init($url);
    if ($curlHandle === false) {
        return false;
    }

    curl_setopt_array($curlHandle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'X-Requested-With: XMLHttpRequest',
            'Accept: application/json',
            'X-Neuro-Cron-Token: ' . $token,
            'X-Neuro-Timestamp: ' . $timestamp,
            'X-Neuro-Nonce: ' . $nonce,
            'X-Neuro-Signature: ' . $signature,
        ],
    ]);

    if (defined('CURLOPT_PROTOCOLS')) {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        curl_setopt($curlHandle, CURLOPT_PROTOCOLS, $scheme === 'http' ? CURLPROTO_HTTP : CURLPROTO_HTTPS);
    }

    $responseBody = curl_exec($curlHandle);
    $httpCode = (int) curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
    $curlError = curl_error($curlHandle);
    curl_close($curlHandle);

    if ($responseBody !== false && $httpCode >= 200 && $httpCode < 300) {
        return true;
    }

    if (class_exists('PrestaShopLogger')) {
        PrestaShopLogger::addLog(
            '[NC] Env cron dispatch HTTP attempt failed (code=' . $httpCode . ', error=' . $curlError . ')',
            2
        );
    }

    return false;
}

function buildShopBaseUrl(): string
{
    $shopDomainSsl = trim((string) Configuration::get('PS_SHOP_DOMAIN_SSL'));
    $shopDomain = trim((string) Configuration::get('PS_SHOP_DOMAIN'));

    $host = $shopDomainSsl !== '' ? $shopDomainSsl : $shopDomain;
    if ($host === '') {
        return '';
    }

    $sslEnabled = (bool) Configuration::get('PS_SSL_ENABLED');
    $scheme = $shopDomainSsl !== '' || $sslEnabled ? 'https' : 'http';

    $baseUri = defined('__PS_BASE_URI__') ? (string) __PS_BASE_URI__ : '/';
    if ($baseUri === '') {
        $baseUri = '/';
    }
    if ($baseUri[0] !== '/') {
        $baseUri = '/' . $baseUri;
    }

    $baseUri = rtrim($baseUri, '/');

    return $scheme . '://' . $host . $baseUri;
}

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

if (!method_exists($module, 'acquireCronLock')
    || !method_exists($module, 'releaseCronLock')
    || !method_exists($module, 'executeCronRun')) {
    exit(6);
}

if (!$module->acquireCronLock()) {
    exit(0);
}

try {
    $module->executeCronRun(false, false);
} catch (Throwable $e) {
    if (class_exists('PrestaShopLogger')) {
        PrestaShopLogger::addLog(
            '[NC] Auto CLI tick fatal: ' . $e->getMessage(),
            3
        );
    }
    exit(7);
} finally {
    $module->releaseCronLock();
}

exit(0);

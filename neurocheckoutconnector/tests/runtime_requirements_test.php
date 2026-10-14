<?php
require_once __DIR__ . '/../src/Compatibility/RuntimeRequirements.php';
use NeuroCheckout\Compatibility\RuntimeRequirements;

function checkRuntime($condition, $label) {
    if (!$condition) { throw new RuntimeException($label); }
}
foreach ([['1.7.8.0', '7.4.33'], ['1.7.8.11', '7.4.33'], ['8.1.7', '8.1.27'], ['9.1.4', '8.4.0']] as $runtime) {
    checkRuntime(RuntimeRequirements::errors($runtime[0], $runtime[1], ['curl', 'openssl'], true) === [], 'compatible requirements');
}
foreach (['7.1.33', '7.2.34', '7.3.33'] as $php) {
    $errors = RuntimeRequirements::errors('1.7.8.11', $php, ['curl', 'openssl'], true);
    checkRuntime(count($errors) === 1 && strpos($errors[0], $php) !== false, 'specific PHP diagnostic');
    checkRuntime(strpos($errors[0], 'cron') !== false, 'web and CLI requirement');
}
$errors = RuntimeRequirements::errors('1.7.8.11', '7.4.33', [], false, 'fr');
checkRuntime(count($errors) === 3, 'all missing dependencies visible');
checkRuntime(strpos(implode(' ', $errors), 'vendor/autoload.php') !== false, 'incomplete package diagnostic');
checkRuntime(strpos($errors[0], 'requise') !== false, 'French diagnostic');
checkRuntime(count(RuntimeRequirements::errors('1.6.1.24', '7.4.33', ['curl', 'openssl'], true)) === 1, 'unsupported platform');
echo "Runtime requirements: supported PHP, legacy PHP, missing extensions and package diagnostics passed.\n";

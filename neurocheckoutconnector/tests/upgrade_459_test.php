<?php
declare(strict_types=1);
namespace NeuroCheckout\Security {
    class SecretConfiguration {
        public static $ok = true;
        public static function migrateKnownSecrets() { return self::$ok; }
    }
}
namespace {
    define('_PS_VERSION_', 'test-fixture');
    class Configuration {
        public static function updateValue(...$args) { throw new \RuntimeException('Upgrade must not overwrite merchant settings'); }
    }
    require __DIR__ . '/../upgrade/upgrade-4.5.9.php';
    $module = new class {
        public $ok = true;
        public function ensureRequiredRuntimeHooks() { return ['success' => $this->ok]; }
    };
    if (!upgrade_module_4_5_9($module)) throw new \RuntimeException('Upgrade failed');
    $module->ok = false;
    if (upgrade_module_4_5_9($module)) throw new \RuntimeException('Hook repair failure accepted');
    $module->ok = true;
    \NeuroCheckout\Security\SecretConfiguration::$ok = false;
    if (upgrade_module_4_5_9($module)) throw new \RuntimeException('Secret migration failure accepted');
    echo "4.5.9 upgrade guard tests passed.\n";
}

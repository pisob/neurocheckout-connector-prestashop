<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_4_5_8($module)
{
    if (!Configuration::updateValue('NC_OPAQUE_RECOVERY_LINKS', 1)) {
        return false;
    }

    if (class_exists('NeuroCheckout\\Security\\SecretConfiguration')) {
        if (!\NeuroCheckout\Security\SecretConfiguration::migrateKnownSecrets()) {
            return false;
        }
    }

    if (method_exists($module, 'ensureRequiredRuntimeHooks')) {
        $result = $module->ensureRequiredRuntimeHooks();
        if (empty($result['success'])) {
            return false;
        }
    }

    return true;
}

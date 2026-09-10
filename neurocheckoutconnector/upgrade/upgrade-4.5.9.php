<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_4_5_9($module)
{
    // Preserve the merchant's endpoint, keys and recovery-link preference.
    // The new source endpoint stays disabled unless explicitly configured.
    if (class_exists('NeuroCheckout\\Security\\SecretConfiguration')
        && !\NeuroCheckout\Security\SecretConfiguration::migrateKnownSecrets()) {
        return false;
    }
    if (method_exists($module, 'ensureRequiredRuntimeHooks')) {
        $result = $module->ensureRequiredRuntimeHooks();
        return !empty($result['success']);
    }
    return true;
}

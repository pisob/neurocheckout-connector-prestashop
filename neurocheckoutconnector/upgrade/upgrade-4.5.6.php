<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_4_5_6($module)
{
    if (!$module || !method_exists($module, 'ensureRequiredRuntimeHooks')) {
        return false;
    }

    $result = $module->ensureRequiredRuntimeHooks();

    return !empty($result['success']);
}

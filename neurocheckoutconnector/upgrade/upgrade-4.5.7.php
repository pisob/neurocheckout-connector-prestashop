<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_4_5_7($module)
{
    if (method_exists($module, 'ensureRequiredRuntimeHooks')) {
        $module->ensureRequiredRuntimeHooks();
    }

    return true;
}

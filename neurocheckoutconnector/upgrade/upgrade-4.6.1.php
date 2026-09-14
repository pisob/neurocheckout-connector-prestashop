<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_4_6_1($module)
{
    // An in-place upgrade must preserve every existing connector table,
    // credential and merchant setting. Only the update-check cache is reset.
    return Configuration::updateValue('NC_CONNECTOR_UPDATE_CHECKED_AT', 0)
        && Configuration::updateValue('NC_CONNECTOR_UPDATE_STATUS', '')
        && Configuration::updateValue('NC_CONNECTOR_LATEST_VERSION', '')
        && Configuration::updateValue('NC_CONNECTOR_RELEASE_URL', '')
        && $module->registerHook('displayBackOfficeHeader');
}

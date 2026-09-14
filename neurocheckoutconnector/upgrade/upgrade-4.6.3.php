<?php

if (!defined('_PS_VERSION_')) { exit; }

function upgrade_module_4_6_3($module)
{
    // Only clear release-notification caches. Never reset store or source state.
    return Configuration::updateValue('NC_CONNECTOR_UPDATE_CHECKED_AT', 0)
        && Configuration::updateValue('NC_CONNECTOR_UPDATE_STATUS', '')
        && Configuration::updateValue('NC_CONNECTOR_LATEST_VERSION', '')
        && Configuration::updateValue('NC_CONNECTOR_RELEASE_URL', '');
}

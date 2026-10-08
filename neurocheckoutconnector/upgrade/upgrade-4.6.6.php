<?php

if (!defined('_PS_VERSION_')) { exit; }

function upgrade_module_4_6_6($module)
{
    // Preserve merchant credentials, queues and synchronization state.
    return Configuration::updateValue('NC_CONNECTOR_UPDATE_CHECKED_AT', 0)
        && Configuration::updateValue('NC_CONNECTOR_UPDATE_STATUS', '')
        && Configuration::updateValue('NC_CONNECTOR_LATEST_VERSION', '')
        && Configuration::updateValue('NC_CONNECTOR_RELEASE_URL', '');
}

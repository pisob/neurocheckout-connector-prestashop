<?php
if (!defined('_PS_VERSION_')) { exit; }

// Runtime compatibility only: no settings, queue or synchronization migration.
function upgrade_module_4_6_8($module)
{
    return true;
}

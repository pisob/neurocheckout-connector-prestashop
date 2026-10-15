<?php
if (!defined('_PS_VERSION_')) { exit; }

// Runtime fixes only: preserve settings, queues and persistent source state.
function upgrade_module_4_6_9($module)
{
    return true;
}

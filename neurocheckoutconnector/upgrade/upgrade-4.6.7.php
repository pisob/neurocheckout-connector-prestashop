<?php
if (!defined('_PS_VERSION_')) { exit; }

// Additive snapshot field only: preserve all merchant configuration and queues.
function upgrade_module_4_6_7($module)
{
    return true;
}

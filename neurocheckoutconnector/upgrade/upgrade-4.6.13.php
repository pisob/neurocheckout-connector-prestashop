<?php
if (!defined('_PS_VERSION_')) { exit; }

// Preserve credentials, pending rotations, queues and encrypted source state.
// The stable snapshot key is initialized on the next pull or API key change.
function upgrade_module_4_6_13($module)
{
    return true;
}

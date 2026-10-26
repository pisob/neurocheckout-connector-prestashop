<?php
if (!defined('_PS_VERSION_')) { exit; }

// Snapshot enrichment only: preserve credentials, queues and encrypted source state.
function upgrade_module_4_6_14($module)
{
    return true;
}

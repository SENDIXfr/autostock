<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_3_0($module)
{
    return $module->registerHook('actionFrontControllerInitAfter');
}

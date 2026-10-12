<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_1_0($module)
{
    // Keep tracking rows and settings while making rollbacks effective even
    // on installations that previously used a different default engine.
    return Db::getInstance()->execute(
        'ALTER TABLE `' . _DB_PREFIX_ . 'autostock_disabled` ENGINE=InnoDB'
    );
}

<?php

require __DIR__ . '/fixtures/PrestaShop.php';
require dirname(__DIR__) . '/autostock.php';

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$module = new Autostock();
check($module->version === '2.1.0' && $module->ps_versions_compliancy['max'] === '9.2.99', 'Compatibility metadata is incorrect.');
$module->getContent();
$links = $module->context->link->moduleLinks;
check(count($links) === 2, 'Only active shops with the module enabled should have cron links.');
check($links[0][4] === 11 && $links[0][5] === 1, 'The cron link must use the target shop language and domain.');
check($links[1][4] === 22 && $links[1][5] === 2, 'The second shop must not use the current shop domain.');
check(HelperForm::$last->tpl_vars['fields_value']['autostock_token'] === 'admin-token', 'Settings form token is missing.');

$result = $module->runSynchronization(2);
$queries = implode("\n", Db::getInstance()->queries);
check(strpos($queries, '<= 5') !== false, 'The target shop group threshold must be inherited.');
check($result === ['disabled' => 2, 'enabled' => 0], 'The target shop group reactivation setting must be inherited.');
check(Product::$resets === 1 && Cache::$cleaned === ['objectmodel_Product_*'], 'Changed products must invalidate caches.');

foreach ([0, 3, 4, 5, 100] as $idShop) {
    try {
        $module->runSynchronization($idShop);
        throw new LogicException('Invalid or disabled shop must be rejected.');
    } catch (InvalidArgumentException $exception) {
        // Expected: missing shop, disabled module, or inactive shop.
    }
}

$_REQUEST = ['submitAutostockToken' => 1];
$_SERVER['REQUEST_METHOD'] = 'GET';
check(strpos($module->getContent(), 'ERROR:') !== false && !Configuration::$writes, 'GET must never change configuration.');

$_SERVER['REQUEST_METHOD'] = 'POST';
check(strpos($module->getContent(), 'ERROR:') !== false && !Configuration::$writes, 'A missing CSRF token must prevent writes.');
$_REQUEST['autostock_token'] = ['admin-token'];
check(strpos($module->getContent(), 'ERROR:') !== false && !Configuration::$writes, 'Array CSRF tokens must be rejected without warnings.');
$_REQUEST['autostock_token'] = 'admin-token';
Module::$canConfigure = false;
check(strpos($module->getContent(), 'ERROR:') !== false && !Configuration::$writes, 'Configuration permission is required.');
Module::$canConfigure = true;
Configuration::$failWrite = true;
check(strpos($module->getContent(), 'could not be regenerated') !== false, 'Failed token persistence must not report success.');
Configuration::$failWrite = false;
check(strpos($module->getContent(), 'OK:') !== false, 'Valid POST should regenerate the token.');
check(strlen(Configuration::$global[Autostock::CONFIG_TOKEN]) === 64, 'Cron token must contain 256 bits of entropy.');

$_REQUEST = ['submitAutostockSettings' => 1, 'autostock_token' => 'admin-token', Autostock::CONFIG_THRESHOLD => ['0']];
check(strpos($module->getContent(), 'must be an integer') !== false, 'Malformed thresholds must be rejected without warnings.');
$_REQUEST[Autostock::CONFIG_THRESHOLD] = '-2';
$_REQUEST[Autostock::CONFIG_AUTO_REACTIVATE] = '0';
check(strpos($module->getContent(), 'OK:') !== false, 'Valid negative thresholds must be saved.');

require dirname(__DIR__) . '/upgrade/upgrade-2.1.0.php';
$configurationBeforeUpgrade = Configuration::$global;
check(upgrade_module_2_1_0($module), 'Version 2.0 upgrade should succeed.');
check(Configuration::$global === $configurationBeforeUpgrade, 'Upgrade must preserve settings and the cron token.');
check(strpos(end(Db::getInstance()->queries), 'ENGINE=InnoDB') !== false, 'Upgrade must ensure transactional tracking.');

echo "Module tests passed.\n";

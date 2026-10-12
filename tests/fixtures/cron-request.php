<?php

require __DIR__ . '/PrestaShop.php';
require dirname(__DIR__, 2) . '/autostock.php';

class ModuleFrontController
{
    public $module, $context;
    public function init() { throw new LogicException('Storefront initialization must not run for cron.'); }
}

class CronModule
{
    public function runSynchronization($idShop)
    {
        switch ($idShop) {
            case 3: throw new InvalidArgumentException('Disabled shop');
            case 4: throw new Autostock\SynchronizationBusyException('Busy');
            case 5: throw new RuntimeException('SQL error containing private details');
        }
        return ['disabled' => 2, 'enabled' => 1];
    }
}

require dirname(__DIR__, 2) . '/controllers/front/cron.php';
$_REQUEST = json_decode(stream_get_contents(STDIN), true);
http_response_code(200);
register_shutdown_function(function () { file_put_contents('php://stderr', (string) http_response_code()); });
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$controller = new AutostockCronModuleFrontController();
$controller->module = new CronModule();
$controller->context = (object) ['shop' => (object) ['id' => 1]];
$controller->init();

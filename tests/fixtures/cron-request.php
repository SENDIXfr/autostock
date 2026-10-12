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

$endpoint = isset($argv[1]) ? $argv[1] : 'cron';
check(in_array($endpoint, ['cron', 'webcron'], true), 'Unexpected test endpoint.');
require dirname(__DIR__, 2) . '/controllers/front/' . $endpoint . '.php';
$_REQUEST = json_decode(stream_get_contents(STDIN), true);
$_SERVER['REQUEST_METHOD'] = isset($argv[2]) ? $argv[2] : 'GET';
http_response_code(200);
register_shutdown_function(function () { file_put_contents('php://stderr', (string) http_response_code()); });
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$controller = $endpoint === 'webcron'
    ? new AutostockWebcronModuleFrontController()
    : new AutostockCronModuleFrontController();
$controller->module = new CronModule();
$controller->context = (object) ['shop' => (object) ['id' => 1]];
$controller->init();

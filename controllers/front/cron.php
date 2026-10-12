<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class AutostockCronModuleFrontController extends ModuleFrontController
{
    public $auth = false;
    public $guestAllowed = true;

    // This machine endpoint must also work during shop maintenance and without
    // running storefront hooks, geolocation, or theme rendering.
    public function init()
    {
        $providedToken = Tools::getValue('token');
        $expectedToken = (string) Configuration::getGlobalValue(Autostock::CONFIG_TOKEN);

        if ($expectedToken === '' || !is_string($providedToken) || !hash_equals($expectedToken, $providedToken)) {
            $this->renderJson(['success' => false, 'error' => 'Forbidden'], 403);
        }

        $idShop = Tools::getValue('id_shop', (int) $this->context->shop->id);

        if (!is_scalar($idShop) || filter_var($idShop, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $this->renderJson(['success' => false, 'error' => 'Invalid shop identifier'], 400);
        }

        $idShop = (int) $idShop;

        try {
            $result = $this->module->runSynchronization($idShop);
        } catch (InvalidArgumentException $exception) {
            $this->renderJson(['success' => false, 'error' => 'Invalid or disabled shop'], 400);
        } catch (\Autostock\SynchronizationBusyException $exception) {
            header('Retry-After: 60');
            $this->renderJson(['success' => false, 'error' => 'Synchronization already running'], 409);
        } catch (Throwable $exception) {
            PrestaShopLogger::addLog('[autostock] ' . $exception->getMessage(), 3);
            $this->renderJson([
                'success' => false,
                'error' => 'Synchronization failed. See the PrestaShop logs.',
            ], 500);
        }

        $this->renderJson([
            'success' => true,
            'shop' => $idShop,
            'disabled' => $result['disabled'],
            'enabled' => $result['enabled'],
        ]);
    }

    private function renderJson(array $payload, $statusCode = 200)
    {
        http_response_code((int) $statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
        exit;
    }
}

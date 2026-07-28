<?php

class SdxautostockCronModuleFrontController extends ModuleFrontController
{
    public $auth = false;
    public $guestAllowed = true;

    public function initContent()
    {
        parent::initContent();

        $providedToken = (string) Tools::getValue('token');
        $expectedToken = (string) Configuration::get(SdxAutostock::CONFIG_TOKEN);

        if ($expectedToken === '' || !hash_equals($expectedToken, $providedToken)) {
            $this->renderJson(['success' => false, 'error' => 'Forbidden'], 403);
        }

        $idShop = (int) Tools::getValue('id_shop', (int) $this->context->shop->id);

        try {
            $result = $this->module->runSynchronization($idShop);
            $this->renderJson([
                'success' => true,
                'shop' => $idShop,
                'disabled' => $result['disabled'],
                'enabled' => $result['enabled'],
            ]);
        } catch (Throwable $exception) {
            PrestaShopLogger::addLog('[SDX AutoStock] ' . $exception->getMessage(), 3);
            $this->renderJson([
                'success' => false,
                'error' => 'Synchronization failed. See the PrestaShop logs.',
            ], 500);
        }
    }

    private function renderJson(array $payload, $statusCode = 200)
    {
        http_response_code((int) $statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        echo Tools::jsonEncode($payload);
        exit;
    }
}

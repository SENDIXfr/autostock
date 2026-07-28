<?php

use SDXAutostock\StockSynchronizer;

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/src/StockSynchronizer.php';

class SdxAutostock extends Module
{
    const CONFIG_AUTO_REACTIVATE = 'SDXAUTOSTOCK_AUTO_REACTIVATE';
    const CONFIG_THRESHOLD = 'SDXAUTOSTOCK_THRESHOLD';
    const CONFIG_TOKEN = 'SDXAUTOSTOCK_CRON_TOKEN';
    const TRANSLATION_DOMAIN = 'Modules.Sdxautostock.Admin';

    /** @var bool */
    private static $synchronizationRunning = false;

    public function __construct()
    {
        $this->name = 'sdxautostock';
        $this->tab = 'administration';
        $this->version = '2.0.0';
        $this->author = 'SENDIX';
        $this->need_instance = 0;
        $this->is_configurable = 1;
        $this->controllers = ['cron'];
        $this->ps_versions_compliancy = [
            'min' => '8.0.0',
            'max' => '9.99.99',
        ];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('SDX AutoStock', [], self::TRANSLATION_DOMAIN);
        $this->description = $this->trans(
            'Automatically disables out-of-stock products and optionally reactivates them when stock returns.',
            [],
            self::TRANSLATION_DOMAIN
        );
        $this->confirmUninstall = $this->trans(
            'Uninstall SDX AutoStock and remove its tracking data?',
            [],
            self::TRANSLATION_DOMAIN
        );
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        $installed = $this->installDatabase()
            && $this->installConfiguration()
            && $this->registerHook('actionCronJob')
            && $this->registerHook('actionProductUpdate');

        if (!$installed) {
            $this->uninstallDatabase();
            Configuration::deleteByName(self::CONFIG_AUTO_REACTIVATE);
            Configuration::deleteByName(self::CONFIG_THRESHOLD);
            Configuration::deleteByName(self::CONFIG_TOKEN);
            parent::uninstall();
        }

        return $installed;
    }

    public function uninstall()
    {
        $result = $this->uninstallDatabase();

        $result = Configuration::deleteByName(self::CONFIG_AUTO_REACTIVATE) && $result;
        $result = Configuration::deleteByName(self::CONFIG_THRESHOLD) && $result;
        $result = Configuration::deleteByName(self::CONFIG_TOKEN) && $result;

        return parent::uninstall() && $result;
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitSdxAutostockSettings')) {
            $output .= $this->processSettings();
        }

        if (Tools::isSubmit('submitSdxAutostockRun')) {
            $output .= $this->processManualRun();
        }

        if (Tools::isSubmit('submitSdxAutostockToken')) {
            Configuration::updateGlobalValue(self::CONFIG_TOKEN, $this->generateToken());
            $output .= $this->displayConfirmation(
                $this->trans('The cron token has been regenerated.', [], self::TRANSLATION_DOMAIN)
            );
        }

        return $output . $this->renderConfigurationForm() . $this->renderCronPanel();
    }

    /**
     * Compatibility with the official Cron tasks manager module.
     *
     * @param array<string, mixed> $params
     *
     * @return bool
     */
    public function hookActionCronJob($params = [])
    {
        try {
            $this->runForContextShops();

            return true;
        } catch (Throwable $exception) {
            PrestaShopLogger::addLog(
                '[SDX AutoStock] ' . $exception->getMessage(),
                3,
                null,
                'Module',
                (int) $this->id,
                true
            );

            return false;
        }
    }

    /**
     * Default schedule used by the legacy Cron tasks manager: every day at 05:00.
     *
     * @return array{hour: int, day: int, month: int, day_of_week: int}
     */
    public function getCronFrequency()
    {
        return [
            'hour' => 5,
            'day' => -1,
            'month' => -1,
            'day_of_week' => -1,
        ];
    }

    /**
     * A manual product save takes precedence over the module's previous action.
     *
     * @param array<string, mixed> $params
     */
    public function hookActionProductUpdate($params)
    {
        if (self::$synchronizationRunning || empty($params['id_product'])) {
            return;
        }

        $idProduct = (int) $params['id_product'];

        foreach ($this->getContextShopIds() as $idShop) {
            Db::getInstance()->delete(
                'sdxautostock_disabled',
                '`id_product` = ' . $idProduct . ' AND `id_shop` = ' . (int) $idShop
            );
        }
    }

    /**
     * Public entry point used by the secured front controller.
     *
     * @return array{disabled: int, enabled: int}
     */
    public function runSynchronization($idShop)
    {
        $idShop = (int) $idShop;
        $shop = new Shop($idShop);

        if (!Validate::isLoadedObject($shop) || !$shop->active) {
            throw new InvalidArgumentException('The requested shop does not exist or is disabled.');
        }

        $threshold = (int) Configuration::get(self::CONFIG_THRESHOLD, null, null, $idShop, 0);
        $autoReactivate = (bool) Configuration::get(
            self::CONFIG_AUTO_REACTIVATE,
            null,
            null,
            $idShop,
            true
        );
        $synchronizer = new StockSynchronizer(Db::getInstance(), _DB_PREFIX_, $threshold);

        self::$synchronizationRunning = true;

        try {
            return $synchronizer->synchronize($idShop, $autoReactivate);
        } finally {
            self::$synchronizationRunning = false;
        }
    }

    private function installDatabase()
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'sdxautostock_disabled` (
            `id_product` INT UNSIGNED NOT NULL,
            `id_shop` INT UNSIGNED NOT NULL,
            `date_add` DATETIME NOT NULL,
            PRIMARY KEY (`id_product`, `id_shop`),
            KEY `idx_sdxautostock_shop` (`id_shop`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4';

        return Db::getInstance()->execute($sql);
    }

    private function uninstallDatabase()
    {
        return Db::getInstance()->execute(
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'sdxautostock_disabled`'
        );
    }

    private function installConfiguration()
    {
        $token = (string) Configuration::get(self::CONFIG_TOKEN);

        if ($token === '') {
            $token = $this->generateToken();
        }

        return Configuration::updateValue(self::CONFIG_AUTO_REACTIVATE, 1)
            && Configuration::updateValue(self::CONFIG_THRESHOLD, 0)
            && Configuration::updateGlobalValue(self::CONFIG_TOKEN, $token);
    }

    private function processSettings()
    {
        $threshold = Tools::getValue(self::CONFIG_THRESHOLD);

        if (!Validate::isInt($threshold) || (int) $threshold < -1000000 || (int) $threshold > 1000000) {
            return $this->displayError(
                $this->trans(
                    'The stock threshold must be an integer between -1,000,000 and 1,000,000.',
                    [],
                    self::TRANSLATION_DOMAIN
                )
            );
        }

        $updated = Configuration::updateValue(self::CONFIG_THRESHOLD, (int) $threshold)
            && Configuration::updateValue(
                self::CONFIG_AUTO_REACTIVATE,
                (int) (bool) Tools::getValue(self::CONFIG_AUTO_REACTIVATE)
            );

        if (!$updated) {
            return $this->displayError(
                $this->trans('The settings could not be saved.', [], self::TRANSLATION_DOMAIN)
            );
        }

        return $this->displayConfirmation(
            $this->trans('The settings have been saved.', [], self::TRANSLATION_DOMAIN)
        );
    }

    private function processManualRun()
    {
        try {
            $result = $this->runForContextShops();

            return $this->displayConfirmation(
                $this->trans(
                    'Synchronization completed: %disabled% product(s) disabled, %enabled% product(s) enabled.',
                    [
                        '%disabled%' => (int) $result['disabled'],
                        '%enabled%' => (int) $result['enabled'],
                    ],
                    self::TRANSLATION_DOMAIN
                )
            );
        } catch (Throwable $exception) {
            PrestaShopLogger::addLog('[SDX AutoStock] ' . $exception->getMessage(), 3);

            return $this->displayError(
                $this->trans(
                    'Synchronization failed. See the PrestaShop logs for details.',
                    [],
                    self::TRANSLATION_DOMAIN
                )
            );
        }
    }

    /**
     * @return array{disabled: int, enabled: int}
     */
    private function runForContextShops()
    {
        $totals = ['disabled' => 0, 'enabled' => 0];

        foreach ($this->getContextShopIds() as $idShop) {
            $result = $this->runSynchronization($idShop);
            $totals['disabled'] += $result['disabled'];
            $totals['enabled'] += $result['enabled'];
        }

        return $totals;
    }

    /**
     * @return int[]
     */
    private function getContextShopIds()
    {
        $shopIds = array_map('intval', (array) Shop::getContextListShopID());

        if (!$shopIds && Validate::isLoadedObject($this->context->shop)) {
            $shopIds[] = (int) $this->context->shop->id;
        }

        return array_values(array_unique(array_filter($shopIds)));
    }

    private function renderConfigurationForm()
    {
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = (int) $this->context->language->id;
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitSdxAutostockSettings';
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = [
            'fields_value' => [
                self::CONFIG_THRESHOLD => (int) Configuration::get(self::CONFIG_THRESHOLD, null, null, null, 0),
                self::CONFIG_AUTO_REACTIVATE => (int) Configuration::get(
                    self::CONFIG_AUTO_REACTIVATE,
                    null,
                    null,
                    null,
                    true
                ),
            ],
        ];

        return $helper->generateForm([[
            'form' => [
                'legend' => [
                    'title' => $this->trans('Stock synchronization', [], self::TRANSLATION_DOMAIN),
                    'icon' => 'icon-refresh',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->trans('Stock threshold', [], self::TRANSLATION_DOMAIN),
                        'name' => self::CONFIG_THRESHOLD,
                        'class' => 'fixed-width-sm',
                        'desc' => $this->trans(
                            'Products with an aggregate quantity at or below this value are disabled.',
                            [],
                            self::TRANSLATION_DOMAIN
                        ),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Automatic reactivation', [], self::TRANSLATION_DOMAIN),
                        'name' => self::CONFIG_AUTO_REACTIVATE,
                        'is_bool' => true,
                        'values' => [
                            [
                                'id' => 'auto_reactivate_on',
                                'value' => 1,
                                'label' => $this->trans('Enabled', [], self::TRANSLATION_DOMAIN),
                            ],
                            [
                                'id' => 'auto_reactivate_off',
                                'value' => 0,
                                'label' => $this->trans('Disabled', [], self::TRANSLATION_DOMAIN),
                            ],
                        ],
                        'desc' => $this->trans(
                            'Only products previously disabled by this module are reactivated.',
                            [],
                            self::TRANSLATION_DOMAIN
                        ),
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Save', [], self::TRANSLATION_DOMAIN),
                    'class' => 'btn btn-default pull-right',
                ],
            ],
        ]]);
    }

    private function renderCronPanel()
    {
        $urls = [];
        $token = (string) Configuration::get(self::CONFIG_TOKEN);

        foreach ($this->getContextShopIds() as $idShop) {
            $shop = new Shop($idShop);
            $url = $this->context->link->getModuleLink(
                $this->name,
                'cron',
                ['token' => $token, 'id_shop' => $idShop],
                true,
                $idShop
            );
            $urls[] = '<li><strong>' . Tools::safeOutput($shop->name) . ':</strong> <code>'
                . Tools::safeOutput($url) . '</code></li>';
        }

        $action = AdminController::$currentIndex . '&configure=' . $this->name
            . '&token=' . Tools::getAdminTokenLite('AdminModules');

        return '<div class="panel">'
            . '<h3><i class="icon-time"></i> '
            . Tools::safeOutput($this->trans('Cron execution', [], self::TRANSLATION_DOMAIN)) . '</h3>'
            . '<p>' . Tools::safeOutput($this->trans(
                'Call the URL for each shop from your scheduler. Keep the token secret.',
                [],
                self::TRANSLATION_DOMAIN
            )) . '</p>'
            . '<ul>' . implode('', $urls) . '</ul>'
            . '<form method="post" action="' . Tools::safeOutput($action) . '" class="form-inline">'
            . '<button type="submit" name="submitSdxAutostockRun" class="btn btn-primary">'
            . '<i class="icon-play"></i> '
            . Tools::safeOutput($this->trans('Run now', [], self::TRANSLATION_DOMAIN)) . '</button> '
            . '<button type="submit" name="submitSdxAutostockToken" class="btn btn-default">'
            . '<i class="icon-refresh"></i> '
            . Tools::safeOutput($this->trans('Regenerate token', [], self::TRANSLATION_DOMAIN)) . '</button>'
            . '</form></div>';
    }

    private function generateToken()
    {
        try {
            return bin2hex(random_bytes(32));
        } catch (Exception $exception) {
            return Tools::passwdGen(64, 'RANDOM');
        }
    }
}

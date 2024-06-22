<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class SdxAutostock extends Module
{
    public function __construct()
    {
        $this->name = 'sdx-autostock';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Your Name';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = [
            'min' => '8.0.0',
            'max' => _PS_VERSION_
        ];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('SDX AutoStock');
        $this->description = $this->l('Automatically disables products that are out of stock using a cron job.');
    }

    public function install()
    {
        return parent::install() &&
            $this->registerHook('actionCronJob');
    }

    public function uninstall()
    {
        return parent::uninstall();
    }

    public function hookActionCronJob()
    {
        $this->disableOutOfStockProducts();
    }

    private function disableOutOfStockProducts()
    {
        $sql = 'UPDATE ' . _DB_PREFIX_ . 'product p
                JOIN ' . _DB_PREFIX_ . 'stock_available sa ON p.id_product = sa.id_product
                SET p.active = 0
                WHERE sa.quantity <= 0 AND p.active = 1';

        return Db::getInstance()->execute($sql);
    }
}

// Content for config/cron_jobs.yml
/*
jobs:
  - name: SDX AutoStock - Disable Out of Stock Products
    interval: 0 5 * * *
    command: SdxAutostock
*/
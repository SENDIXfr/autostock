<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

use SDXAutostock\Cron\SDXAutostockCronTask;

class SdxAutostock extends Module
{
    public function __construct()
    {
        $this->name = 'sdx-autostock';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Your Name';
        $this->need_instance = 0;
        $this->bootstrap = true;

        $this->ps_versions_compliancy = [
            'min' => '1.7.0.0',
            'max' => '8.99.99',
        ];

        parent::__construct();

        $this->displayName = $this->l('SDX AutoStock');
        $this->description = $this->l('Module to activate/deactivate products based on stock.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');
    }

    public function install()
    {
        return parent::install() && $this->registerHook('actionCronJob');
    }

    public function uninstall()
    {
        return parent::uninstall();
    }

    public function hookActionCronJob()
    {
        $cronTask = new SDXAutostockCronTask();
        $cronTask->execute();
    }
}

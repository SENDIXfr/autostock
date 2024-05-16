<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class ProductStockUpdater extends Module
{
    public function __construct()
    {
        $this->name = 'sdx-autostock';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'SENDIX';
        $this->need_instance = 0;

        parent::__construct();

        $this->displayName = $this->l('SDX AutoStock');
        $this->description = $this->l('Disables products with zero quantity and re-enables products with stock.');

        $this->ps_versions_compliancy = array('min' => '1.7.0.0', 'max' => _PS_VERSION_);
    }

    public function install()
    {
        return parent::install();
    }

    public function uninstall()
    {
        return parent::uninstall();
    }

    public function getContent()
    {
        $output = null;

        if (Tools::isSubmit('submit'.$this->name)) {
            $this->runUpdate();
            $output .= $this->displayConfirmation($this->l('Product stock status updated successfully.'));
        }

        return $output.$this->displayForm();
    }

    public function displayForm()
    {
        $default_lang = (int)Configuration::get('PS_LANG_DEFAULT');

        $fields_form[0]['form'] = array(
            'legend' => array(
                'title' => $this->l('Update Product Stock Status'),
            ),
            'submit' => array(
                'title' => $this->l('Run Update'),
                'class' => 'btn btn-default pull-right'
            )
        );

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $default_lang;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submit'.$this->name;
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        return $helper->generateForm($fields_form);
    }

    public function runUpdate()
    {
        require_once _PS_MODULE_DIR_.$this->name.'/cronjob.php';
    }
}
?>

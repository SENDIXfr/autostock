<?php

// Minimal doubles for the legacy APIs used by the module. These tests do not
// boot a shop; SQL behavior is covered separately by the MySQL integration test.
define('_PS_VERSION_', '9.2.0');
define('_DB_PREFIX_', 'ps_');

function check($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

class Module
{
    public $name, $tab, $version, $author, $need_instance, $is_configurable;
    public $controllers, $ps_versions_compliancy, $bootstrap, $displayName, $description, $confirmUninstall;
    public $id = 8;
    public $table = 'module';
    public $identifier = 'id_module';
    public $context;
    public static $canConfigure = true;
    public $registeredHooks = [];
    public $hookRegistrationSucceeds = true;

    public function __construct()
    {
        $this->context = (object) [
            'shop' => new Shop(1), 'language' => (object) ['id' => 1, 'iso_code' => 'en'],
            'employee' => new Employee(), 'link' => new Link(),
        ];
    }

    public function trans($message, $parameters = [], $domain = null) { return strtr($message, $parameters); }
    public function displayError($message) { return 'ERROR: ' . $message; }
    public function displayConfirmation($message) { return 'OK: ' . $message; }
    public function getPermission($action) { return self::$canConfigure; }
    public function install() { return true; }
    public function registerHook($hook) { $this->registeredHooks[] = $hook; return $this->hookRegistrationSucceeds; }
}

class Employee
{
    public function isLoggedBack() { return true; }
}

class Shop
{
    public $id, $id_shop_group, $active, $name;
    public $deleted = false;
    public function __construct($id)
    {
        $this->id = $id > 0 && $id < 6 ? $id : 0;
        $this->id_shop_group = $id * 10;
        $this->active = $id !== 4;
        $this->deleted = $id === 5;
        $this->name = 'Shop ' . $id;
    }
    public static function getContextListShopID() { return [1, 2, 3, 4]; }
}

class Link
{
    public $moduleLinks = [];
    public function getModuleLink($module, $controller, $params, $ssl = null, $idLang = null, $idShop = null)
    {
        $this->moduleLinks[] = func_get_args();
        return 'https://shop' . $idShop . '.test/' . $controller . '?' . http_build_query($params);
    }
    public function getAdminLink($controller, $withToken = true, $routeParams = [], $params = [])
    {
        return '/admin/modules?' . http_build_query($params + ($withToken ? ['token' => 'admin-token'] : []));
    }
    public function getPageLink($controller, $ssl = null, $idLang = null, $request = null, $encode = false, $idShop = null)
    {
        return 'https://shop' . $idShop . '.test/?lang=' . $idLang . '&home=1';
    }
}

class Tools
{
    public static function getValue($key, $default = false) { return isset($_REQUEST[$key]) ? $_REQUEST[$key] : $default; }
    public static function isSubmit($key) { return isset($_REQUEST[$key]); }
    public static function getAdminTokenLite($controller) { return 'admin-token'; }
    public static function safeOutput($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
    // Intentionally no jsonEncode(): this method is absent from PrestaShop 9.2.
}

class Configuration
{
    public static $global = ['AUTOSTOCK_CRON_TOKEN' => 'secret-token'];
    public static $writes = [];
    public static $failWrite = false;
    public static function get($key, $idLang = null, $idGroup = null, $idShop = null, $default = false)
    {
        if ($key === 'PS_LANG_DEFAULT') { return $idShop * 11; }
        if ($idGroup === 20 && $key === 'AUTOSTOCK_THRESHOLD') { return 5; }
        if ($idGroup === 20 && $key === 'AUTOSTOCK_AUTO_REACTIVATE') { return 0; }
        return isset(self::$global[$key]) ? self::$global[$key] : $default;
    }
    public static function getGlobalValue($key) { return isset(self::$global[$key]) ? self::$global[$key] : false; }
    public static function updateValue($key, $value)
    {
        self::$writes[] = [$key, $value];
        if (self::$failWrite) { return false; }
        self::$global[$key] = $value;
        return true;
    }
    public static function updateGlobalValue($key, $value) { return self::updateValue($key, $value); }
}

class Validate
{
    public static function isLoadedObject($object) { return (bool) $object->id; }
}

class HelperForm
{
    public $show_toolbar, $table, $module, $default_form_language, $allow_employee_form_lang;
    public $identifier, $submit_action, $currentIndex, $token, $tpl_vars;
    public static $last;
    public function generateForm($form) { self::$last = $this; return 'FORM'; }
}

class Db
{
    public static $instance;
    public $queries = [];
    public static function getInstance()
    {
        if (!self::$instance) { self::$instance = new self(); }
        return self::$instance;
    }
    public function getValue($sql, $useCache = true)
    {
        if (strpos($sql, 'module_shop') !== false && strpos($sql, '`id_shop` = 3') !== false) { return false; }
        return 1;
    }
    public function executeS($sql) { return [['id_shop' => 1], ['id_shop' => 2]]; }
    public function execute($sql) { $this->queries[] = $sql; return true; }
    public function Affected_Rows() { return 2; }
    public function delete($table, $where) { return true; }
}

class StockAvailable
{
    public static function addSqlShopRestriction($sql, $idShop, $alias)
    {
        return ' AND ' . $alias . '.`id_shop` = ' . (int) $idShop . ' AND ' . $alias . '.`id_shop_group` = 0';
    }
}

class Cache
{
    public static $cleaned = [];
    public static function clean($pattern) { self::$cleaned[] = $pattern; }
}

class Product
{
    public static $resets = 0;
    public static function resetStaticCache() { ++self::$resets; }
    public static function checkAccessStatic($idProduct, $idCustomer) { return $idProduct !== 12; }
}

class PrestaShopLogger
{
    public static $logs = [];
    public static function addLog($message, $severity = 1) { self::$logs[] = $message; }
}

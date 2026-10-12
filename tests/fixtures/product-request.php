<?php

require __DIR__ . '/PrestaShop.php';
require dirname(__DIR__, 2) . '/autostock.php';

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

class ProductController
{
    public $ajax = false;
}

class AvailabilityDatabase extends Db
{
    public function getValue($sql, $useCache = true)
    {
        check($useCache === false, 'Availability must bypass SQL caching.');
        check(strpos($sql, 'product_shop') !== false && strpos($sql, 'shop_product.`active` = 0') !== false,
            'Availability must verify that the product is still inactive.');
        check(strpos($sql, 'INNER JOIN `ps_product`') !== false, 'Deleted products must be excluded.');
        preg_match('/WHERE tracked\.`id_product` = (\d+)/', $sql, $product);
        preg_match('/AND tracked\.`id_shop` = (\d+)/', $sql, $shop);
        // Products 8 (reactivated), 9 (manual), 10 (deleted) and missing IDs
        // are deliberately absent from this simulated join result.
        return in_array([(int) $product[1], (int) $shop[1]], [[7, 1], [11, 2], [12, 1]], true) ? 1 : false;
    }
}

Db::$instance = new AvailabilityDatabase();
$module = new Autostock();
$module->context->shop = new Shop(isset($_GET['shop']) ? (int) $_GET['shop'] : 1);
$module->context->customer = (object) ['id' => 42];
if (isset($_GET['escape'])) {
    $module->context->language->iso_code = 'en"><script>alert(1)</script>';
}
$controller = isset($_GET['other']) ? new stdClass() : new ProductController();
if ($controller instanceof ProductController) {
    $controller->ajax = !empty($_GET['ajax']);
}
$module->hookActionFrontControllerInitAfter(['controller' => $controller]);

// Sentinel for requests left to PrestaShop. This is deliberately not a
// reimplementation of its product controller or a full-shop integration test.
header('X-Native-Controller: reached');
echo 'native controller';

<?php

namespace SDXAutostock\Cron;

use Product;
use Context;

class SDXAutostockCronTask
{
    public function execute()
    {
        $context = Context::getContext();
        $products = Product::getProducts($context->language->id, 0, 0, 'id_product', 'ASC');

        foreach ($products as $product) {
            $id_product = (int)$product['id_product'];
            $productObj = new Product($id_product);

            if ($productObj->quantity > 0 && !$productObj->active) {
                $productObj->active = 1;
                $productObj->save();
            } elseif ($productObj->quantity <= 0 && $productObj->active) {
                $productObj->active = 0;
                $productObj->save();
            }
        }
    }
}

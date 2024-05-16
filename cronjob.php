<?php
// Obtenir la connexion à la base de données
$db = Db::getInstance();

// Requête pour récupérer les produits avec une quantité disponible de 0
$sql_disable = 'SELECT p.id_product
                FROM ' . _DB_PREFIX_ . 'product p
                LEFT JOIN ' . _DB_PREFIX_ . 'stock_available sa ON (p.id_product = sa.id_product)
                WHERE sa.quantity = 0 AND p.active = 1';

$products_to_disable = $db->executeS($sql_disable);

// Parcourir les produits et désactiver ceux avec une quantité disponible de 0
foreach ($products_to_disable as $product) {
    $productObj = new Product($product['id_product']);
    $productObj->active = false;
    $productObj->save();
}

// Requête pour récupérer les produits avec une quantité disponible supérieure à 0
$sql_enable = 'SELECT p.id_product
               FROM ' . _DB_PREFIX_ . 'product p
               LEFT JOIN ' . _DB_PREFIX_ . 'stock_available sa ON (p.id_product = sa.id_product)
               WHERE sa.quantity > 0 AND p.active = 0';

$products_to_enable = $db->executeS($sql_enable);

// Parcourir les produits et activer ceux avec une quantité disponible supérieure à 0
foreach ($products_to_enable as $product) {
    $productObj = new Product($product['id_product']);
    $productObj->active = true;
    $productObj->save();
}

echo 'Mise à jour des produits terminée : désactivation des produits avec une quantité disponible de 0 et activation des produits avec du stock.';
?>

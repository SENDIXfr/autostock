<?php

namespace Autostock;

require_once __DIR__ . '/SynchronizationBusyException.php';

/**
 * Synchronizes product availability with the aggregate product stock.
 *
 * The module keeps its own list of disabled products. This prevents a product
 * disabled manually by a merchant from being enabled by a later cron run.
 */
final class StockSynchronizer
{
    /** @var object PrestaShop database adapter */
    private $database;

    /** @var string */
    private $tablePrefix;

    /** @var int */
    private $threshold;

    /**
     * @param object $database PrestaShop Db instance
     * @param string $tablePrefix PrestaShop database prefix
     * @param int $threshold Products at or below this quantity are disabled
     */
    public function __construct($database, $tablePrefix, $threshold)
    {
        $this->database = $database;
        $this->tablePrefix = $tablePrefix;
        $this->threshold = (int) $threshold;
    }

    /**
     * @return array{disabled: int, enabled: int}
     */
    public function synchronize($idShop, $autoReactivate)
    {
        $idShop = (int) $idShop;

        if ($idShop <= 0) {
            throw new \InvalidArgumentException('A valid shop identifier is required.');
        }

        // Named locks are connection-scoped and protect the complete transaction.
        // Include the database name to keep separate installations independent.
        $lock = "CONCAT('autostock:', SHA1(CONCAT(DATABASE(), ':', '"
            . sha1($this->tablePrefix . ':' . $idShop) . "')))";
        $acquired = $this->database->getValue('SELECT GET_LOCK(' . $lock . ', 0)', false);

        if ((string) $acquired === '0') {
            throw new SynchronizationBusyException('Stock synchronization is already running for this shop.');
        }

        if ((string) $acquired !== '1') {
            throw new \RuntimeException('Unable to acquire the stock synchronization lock.');
        }

        try {
            $this->executeOrFail('START TRANSACTION');

            try {
                $this->removeObsoleteTrackingRows($idShop);
                $this->trackProductsToDisable($idShop);
                $disabled = $this->disableTrackedProducts($idShop);
                $enabled = 0;

                if ($autoReactivate) {
                    $enabled = $this->reactivateInStockProducts($idShop);
                }

                $this->updateDefaultShopProducts($idShop);
                $this->removeReactivatedTrackingRows($idShop);
                $this->executeOrFail('COMMIT');

                return [
                    'disabled' => $disabled,
                    'enabled' => $enabled,
                ];
            } catch (\Throwable $exception) {
                try {
                    $this->database->execute('ROLLBACK');
                } catch (\Throwable $rollbackException) {
                    // Preserve the original failure if the connection was lost.
                }

                throw $exception;
            }
        } finally {
            try {
                $this->database->getValue('SELECT RELEASE_LOCK(' . $lock . ')', false);
            } catch (\Throwable $releaseException) {
                // Connection loss releases named locks; do not hide the result.
            }
        }
    }

    private function removeObsoleteTrackingRows($idShop)
    {
        $sql = 'DELETE tracked
            FROM `' . $this->tablePrefix . 'autostock_disabled` tracked
            LEFT JOIN `' . $this->tablePrefix . 'product_shop` product_shop
                ON product_shop.`id_product` = tracked.`id_product`
                AND product_shop.`id_shop` = tracked.`id_shop`
            WHERE tracked.`id_shop` = ' . (int) $idShop . '
                AND (product_shop.`id_product` IS NULL OR product_shop.`active` = 1)';

        $this->executeOrFail($sql);
    }

    private function trackProductsToDisable($idShop)
    {
        $sql = 'INSERT IGNORE INTO `' . $this->tablePrefix . 'autostock_disabled`
                (`id_product`, `id_shop`, `date_add`)
            SELECT product_shop.`id_product`, ' . (int) $idShop . ', NOW()
            FROM `' . $this->tablePrefix . 'product_shop` product_shop
            LEFT JOIN `' . $this->tablePrefix . 'stock_available` stock
                ON stock.`id_product` = product_shop.`id_product`
                AND stock.`id_product_attribute` = 0'
                . $this->getStockShopRestriction($idShop) . '
            WHERE product_shop.`id_shop` = ' . (int) $idShop . '
                AND product_shop.`active` = 1
                AND COALESCE(stock.`quantity`, 0) <= ' . (int) $this->threshold;

        $this->executeOrFail($sql);
    }

    private function disableTrackedProducts($idShop)
    {
        $sql = 'UPDATE `' . $this->tablePrefix . 'product_shop` product_shop
            INNER JOIN `' . $this->tablePrefix . 'autostock_disabled` tracked
                ON tracked.`id_product` = product_shop.`id_product`
                AND tracked.`id_shop` = product_shop.`id_shop`
            LEFT JOIN `' . $this->tablePrefix . 'stock_available` stock
                ON stock.`id_product` = product_shop.`id_product`
                AND stock.`id_product_attribute` = 0'
                . $this->getStockShopRestriction($idShop) . '
            SET product_shop.`active` = 0,
                product_shop.`date_upd` = NOW()
            WHERE product_shop.`id_shop` = ' . (int) $idShop . '
                AND product_shop.`active` = 1
                AND COALESCE(stock.`quantity`, 0) <= ' . (int) $this->threshold;

        $this->executeOrFail($sql);

        return (int) $this->database->Affected_Rows();
    }

    private function reactivateInStockProducts($idShop)
    {
        $sql = 'UPDATE `' . $this->tablePrefix . 'product_shop` product_shop
            INNER JOIN `' . $this->tablePrefix . 'autostock_disabled` tracked
                ON tracked.`id_product` = product_shop.`id_product`
                AND tracked.`id_shop` = product_shop.`id_shop`
            LEFT JOIN `' . $this->tablePrefix . 'stock_available` stock
                ON stock.`id_product` = product_shop.`id_product`
                AND stock.`id_product_attribute` = 0'
                . $this->getStockShopRestriction($idShop) . '
            SET product_shop.`active` = 1,
                product_shop.`date_upd` = NOW()
            WHERE product_shop.`id_shop` = ' . (int) $idShop . '
                AND product_shop.`active` = 0
                AND COALESCE(stock.`quantity`, 0) > ' . (int) $this->threshold;

        $this->executeOrFail($sql);

        return (int) $this->database->Affected_Rows();
    }

    private function removeReactivatedTrackingRows($idShop)
    {
        $sql = 'DELETE tracked
            FROM `' . $this->tablePrefix . 'autostock_disabled` tracked
            INNER JOIN `' . $this->tablePrefix . 'product_shop` product_shop
                ON product_shop.`id_product` = tracked.`id_product`
                AND product_shop.`id_shop` = tracked.`id_shop`
            WHERE tracked.`id_shop` = ' . (int) $idShop . '
                AND product_shop.`active` = 1';

        $this->executeOrFail($sql);
    }

    private function updateDefaultShopProducts($idShop)
    {
        $this->executeOrFail('UPDATE `' . $this->tablePrefix . 'product` product
            INNER JOIN `' . $this->tablePrefix . 'product_shop` product_shop
                ON product_shop.`id_product` = product.`id_product`
                AND product_shop.`id_shop` = product.`id_shop_default`
            INNER JOIN `' . $this->tablePrefix . 'autostock_disabled` tracked
                ON tracked.`id_product` = product_shop.`id_product`
                AND tracked.`id_shop` = product_shop.`id_shop`
            SET product.`active` = product_shop.`active`, product.`date_upd` = NOW()
            WHERE product_shop.`id_shop` = ' . (int) $idShop . '
                AND product.`active` <> product_shop.`active`');
    }

    private function getStockShopRestriction($idShop)
    {
        return \StockAvailable::addSqlShopRestriction(null, (int) $idShop, 'stock');
    }

    private function executeOrFail($sql)
    {
        if (!$this->database->execute($sql)) {
            throw new \RuntimeException('Stock synchronization database query failed.');
        }
    }
}

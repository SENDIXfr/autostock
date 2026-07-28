<?php

namespace {
    final class StockAvailable
    {
        public static function addSqlShopRestriction($sql = null, $idShop = null, $alias = null)
        {
            return ' AND ' . $alias . '.`id_shop` = ' . (int) $idShop
                . ' AND ' . $alias . '.`id_shop_group` = 0';
        }
    }
}

namespace SDXAutostock\Tests {
    use SDXAutostock\StockSynchronizer;

    require_once dirname(__DIR__) . '/src/StockSynchronizer.php';

    final class FakeDatabase
    {
        public $queries = [];
        public $failOn = '';

        private $affectedRows = 0;

        public function execute($sql)
        {
            $this->queries[] = $sql;

            if ($this->failOn !== '' && strpos($sql, $this->failOn) !== false) {
                return false;
            }

            if (strpos($sql, 'SET product_shop.`active` = 0') !== false) {
                $this->affectedRows = 3;
            } elseif (strpos($sql, 'SET product_shop.`active` = 1') !== false) {
                $this->affectedRows = 2;
            } else {
                $this->affectedRows = 0;
            }

            return true;
        }

        public function Affected_Rows()
        {
            return $this->affectedRows;
        }
    }

    function assertTrue($condition, $message)
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    $database = new FakeDatabase();
    $synchronizer = new StockSynchronizer($database, 'ps_', 0);
    $result = $synchronizer->synchronize(4, true);
    $allQueries = implode("\n", $database->queries);

    assertTrue($result === ['disabled' => 3, 'enabled' => 2], 'Unexpected synchronization counters.');
    assertTrue(strpos($allQueries, 'product_shop.`id_shop` = 4') !== false, 'Shop filter is missing.');
    assertTrue(strpos($allQueries, 'stock.`id_product_attribute` = 0') !== false, 'Aggregate stock is not used.');
    assertTrue(strpos($allQueries, 'COALESCE(stock.`quantity`, 0) &lt;= 0') === false, 'SQL was HTML-escaped.');
    assertTrue(strpos($allQueries, 'COALESCE(stock.`quantity`, 0) <= 0') !== false, 'Threshold is missing.');
    assertTrue(strpos($allQueries, 'product_shop.`date_upd` = NOW()') !== false, 'Update date is missing.');
    assertTrue(end($database->queries) === 'COMMIT', 'The transaction was not committed.');

    $noReactivationDatabase = new FakeDatabase();
    $noReactivationSynchronizer = new StockSynchronizer($noReactivationDatabase, 'ps_', -2);
    $noReactivationResult = $noReactivationSynchronizer->synchronize(2, false);
    $noReactivationQueries = implode("\n", $noReactivationDatabase->queries);

    assertTrue($noReactivationResult === ['disabled' => 3, 'enabled' => 0], 'Reactivation must be optional.');
    assertTrue(strpos($noReactivationQueries, 'SET product_shop.`active` = 1') === false, 'Reactivation query must be skipped.');
    assertTrue(strpos($noReactivationQueries, '<= -2') !== false, 'Negative thresholds must be supported.');

    $failingDatabase = new FakeDatabase();
    $failingDatabase->failOn = 'SET product_shop.`active` = 0';
    $failingSynchronizer = new StockSynchronizer($failingDatabase, 'ps_', 0);
    $exceptionRaised = false;

    try {
        $failingSynchronizer->synchronize(4, true);
    } catch (\RuntimeException $exception) {
        $exceptionRaised = true;
    }

    assertTrue($exceptionRaised, 'A failed query must raise an exception.');
    assertTrue(end($failingDatabase->queries) === 'ROLLBACK', 'A failed query must roll back the transaction.');

    echo "StockSynchronizer tests passed.\n";
}

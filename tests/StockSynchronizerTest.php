<?php

namespace {
    final class StockAvailable
    {
        public static $sharedStock = false;

        public static function addSqlShopRestriction($sql = null, $idShop = null, $alias = null)
        {
            if (self::$sharedStock) {
                return ' AND ' . $alias . '.`id_shop` = 0 AND ' . $alias . '.`id_shop_group` = 7';
            }

            return ' AND ' . $alias . '.`id_shop` = ' . (int) $idShop
                . ' AND ' . $alias . '.`id_shop_group` = 0';
        }
    }
}

namespace Autostock\Tests {
    use Autostock\StockSynchronizer;

    require_once dirname(__DIR__) . '/src/StockSynchronizer.php';

    final class FakeDatabase
    {
        public $queries = [];
        public $failOn = '';
        public $lockResult = 1;
        public $lockQueries = [];
        public $throwOnRollback = false;

        private $affectedRows = 0;

        public function execute($sql)
        {
            $this->queries[] = $sql;

            if ($sql === 'ROLLBACK' && $this->throwOnRollback) {
                throw new \RuntimeException('Connection lost during rollback.');
            }

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

        public function getValue($sql, $useCache = true)
        {
            assertTrue($useCache === false, 'Named lock queries must bypass the query cache.');
            $this->lockQueries[] = $sql;

            return strpos($sql, 'GET_LOCK(') !== false ? $this->lockResult : 1;
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
    assertTrue(strpos($allQueries, 'product.`id_shop_default`') !== false, 'Default shop product data must be synchronized.');
    assertTrue(count($database->lockQueries) === 2, 'The lock must be acquired and released.');

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
    assertTrue(strpos(end($failingDatabase->lockQueries), 'RELEASE_LOCK(') !== false, 'Failure must release the lock.');

    foreach ([0, null, false] as $lockResult) {
        $lockedDatabase = new FakeDatabase();
        $lockedDatabase->lockResult = $lockResult;
        $exceptionRaised = false;

        try {
            (new StockSynchronizer($lockedDatabase, 'ps_', 0))->synchronize(4, true);
        } catch (\RuntimeException $exception) {
            $exceptionRaised = true;
            assertTrue(
                ($exception instanceof \Autostock\SynchronizationBusyException) === ($lockResult === 0),
                'A busy synchronization must be distinguishable from a database failure.'
            );
        }

        assertTrue($exceptionRaised && !$lockedDatabase->queries, 'An unavailable lock must prevent all writes.');
    }

    $startFailure = new FakeDatabase();
    $startFailure->failOn = 'START TRANSACTION';
    try {
        (new StockSynchronizer($startFailure, 'ps_', 0))->synchronize(4, true);
        throw new \LogicException('Failed transaction start must raise an exception.');
    } catch (\RuntimeException $exception) {
        assertTrue(strpos(end($startFailure->lockQueries), 'RELEASE_LOCK(') !== false, 'Failed start must release the lock.');
    }

    $failingDatabase->throwOnRollback = true;
    try {
        $failingSynchronizer->synchronize(4, true);
        throw new \LogicException('Failed update must raise an exception.');
    } catch (\RuntimeException $exception) {
        assertTrue($exception->getMessage() === 'Stock synchronization database query failed.', 'Rollback must preserve the original exception.');
    }

    \StockAvailable::$sharedStock = true;
    $sharedDatabase = new FakeDatabase();
    (new StockSynchronizer($sharedDatabase, 'custom_', 1))->synchronize(4, true);
    $sharedQueries = implode("\n", $sharedDatabase->queries);
    assertTrue(strpos($sharedQueries, 'stock.`id_shop` = 0 AND stock.`id_shop_group` = 7') !== false, 'Shared stock restriction is missing.');
    assertTrue(strpos($sharedQueries, '`custom_product_shop`') !== false, 'Custom database prefixes must be supported.');

    $invalidDatabase = new FakeDatabase();
    try {
        (new StockSynchronizer($invalidDatabase, 'ps_', 0))->synchronize(0, true);
        throw new \LogicException('Invalid shop must be rejected.');
    } catch (\InvalidArgumentException $exception) {
        assertTrue(!$invalidDatabase->queries && !$invalidDatabase->lockQueries, 'Invalid shops must not access the database.');
    }

    echo "StockSynchronizer tests passed.\n";
}

<?php

// Run only against a dedicated test database. Tables use a random prefix and
// are removed in finally; no existing PrestaShop tables are read or modified.
require dirname(__DIR__) . '/src/StockSynchronizer.php';

class StockAvailable
{
    public static function addSqlShopRestriction($sql, $idShop, $alias)
    {
        return $idShop === 3 || $idShop === 4
            ? ' AND ' . $alias . '.`id_shop` = 0 AND ' . $alias . '.`id_shop_group` = 7'
            : ' AND ' . $alias . '.`id_shop` = ' . (int) $idShop . ' AND ' . $alias . '.`id_shop_group` = 0';
    }
}

class IntegrationDatabase
{
    private $connection;
    private $affected = 0;
    public $failOn = '';
    public function __construct(PDO $connection) { $this->connection = $connection; }
    public function execute($sql)
    {
        if ($this->failOn !== '' && strpos($sql, $this->failOn) !== false) { return false; }
        $this->affected = $this->connection->exec($sql);
        return $this->affected !== false;
    }
    public function getValue($sql, $useCache = true) { return $this->connection->query($sql)->fetchColumn(); }
    public function Affected_Rows() { return $this->affected; }
}

function verify($condition, $message)
{
    if (!$condition) { throw new RuntimeException($message); }
}

$dsn = getenv('AUTOSTOCK_TEST_DSN');
if (!$dsn) {
    fwrite(STDERR, "Set AUTOSTOCK_TEST_DSN to a dedicated MySQL/MariaDB test database.\n");
    exit(1);
}
$pdo = new PDO($dsn, getenv('AUTOSTOCK_TEST_USER') ?: 'root', getenv('AUTOSTOCK_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database = new IntegrationDatabase($pdo);
$prefix = 'autostock_test_' . bin2hex(random_bytes(6)) . '_';
$tables = ['product', 'product_shop', 'stock_available', 'autostock_disabled'];

try {
    $pdo->exec('CREATE TABLE `' . $prefix . 'product` (id_product INT PRIMARY KEY, id_shop_default INT NOT NULL, active TINYINT NOT NULL, date_upd DATETIME NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE `' . $prefix . 'product_shop` (id_product INT, id_shop INT, active TINYINT NOT NULL, date_upd DATETIME NULL, PRIMARY KEY (id_product, id_shop)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE `' . $prefix . 'stock_available` (id_product INT, id_product_attribute INT, id_shop INT, id_shop_group INT, quantity INT, UNIQUE KEY (id_product, id_product_attribute, id_shop, id_shop_group)) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE `' . $prefix . 'autostock_disabled` (id_product INT, id_shop INT, date_add DATETIME, PRIMARY KEY (id_product, id_shop), KEY (id_shop)) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO `' . $prefix . 'product` (id_product, id_shop_default, active) VALUES (1,1,1),(2,1,1),(3,1,0),(4,1,1),(5,1,1),(6,1,1),(7,3,1),(8,3,1)');
    $pdo->exec('INSERT INTO `' . $prefix . 'product_shop` (id_product,id_shop,active) VALUES (1,1,1),(1,2,1),(2,1,1),(3,1,0),(4,1,1),(5,1,1),(6,1,1),(7,3,1),(7,4,1),(8,3,1)');
    // Product 4 has aggregate stock > 0 even though one combination is at 0.
    // Product 5 has no stock row and product 6 has negative stock.
    $pdo->exec('INSERT INTO `' . $prefix . 'stock_available` VALUES (1,0,1,0,0),(1,0,2,0,9),(2,0,1,0,10),(3,0,1,0,10),(4,0,1,0,4),(4,1,1,0,0),(4,2,1,0,4),(6,0,1,0,-2),(7,0,0,7,0),(8,0,0,7,2)');
    $sync = new Autostock\StockSynchronizer($database, $prefix, 0);
    verify($sync->synchronize(1, true) === ['disabled' => 3, 'enabled' => 0], 'Zero, missing and negative stock must be disabled.');
    verify($sync->synchronize(1, true) === ['disabled' => 0, 'enabled' => 0], 'Repeated runs must be idempotent.');
    verify((int) $pdo->query('SELECT active FROM `' . $prefix . 'product_shop` WHERE id_product=1 AND id_shop=2')->fetchColumn() === 1, 'Other shops must remain active.');
    verify((int) $pdo->query('SELECT active FROM `' . $prefix . 'product` WHERE id_product=1')->fetchColumn() === 0, 'Default shop base product must match.');
    verify((int) $pdo->query('SELECT active FROM `' . $prefix . 'product_shop` WHERE id_product=4')->fetchColumn() === 1, 'Aggregate stock must be used for combinations.');
    $pdo->exec('UPDATE `' . $prefix . 'stock_available` SET quantity=3 WHERE id_product=1 AND id_shop=1');
    verify($sync->synchronize(1, false) === ['disabled' => 0, 'enabled' => 0], 'Reactivation must be optional.');
    verify($sync->synchronize(1, true) === ['disabled' => 0, 'enabled' => 1], 'Restocking must reactivate tracked products.');
    verify((int) $pdo->query('SELECT active FROM `' . $prefix . 'product_shop` WHERE id_product=3')->fetchColumn() === 0, 'Manually disabled products must remain disabled.');
    verify((int) $pdo->query('SELECT active FROM `' . $prefix . 'product` WHERE id_product=1')->fetchColumn() === 1, 'Reactivation must update the base product.');
    verify((int) $pdo->query('SELECT COUNT(*) FROM `' . $prefix . 'autostock_disabled` WHERE id_product=1')->fetchColumn() === 0, 'Reactivated products must leave tracking.');

    verify($sync->synchronize(3, true) === ['disabled' => 1, 'enabled' => 0], 'Shared stock must be respected.');
    verify($sync->synchronize(4, true) === ['disabled' => 1, 'enabled' => 0], 'Shared stock must apply to each shop.');
    $pdo->exec('UPDATE `' . $prefix . 'stock_available` SET quantity=4 WHERE id_product=7');
    verify($sync->synchronize(4, true) === ['disabled' => 0, 'enabled' => 1], 'Shared stock restocking must reactivate the target shop.');
    verify((int) $pdo->query('SELECT active FROM `' . $prefix . 'product` WHERE id_product=7')->fetchColumn() === 0, 'A secondary shop must not overwrite the default shop base state.');

    // Force failure after tracking insertion; both state and ownership must roll back.
    $pdo->exec('UPDATE `' . $prefix . 'stock_available` SET quantity=0 WHERE id_product=2');
    $database->failOn = 'SET product_shop.`active` = 0';
    try {
        $sync->synchronize(1, true);
        throw new LogicException('Injected database failure was ignored.');
    } catch (RuntimeException $exception) {
        verify((int) $pdo->query('SELECT active FROM `' . $prefix . 'product_shop` WHERE id_product=2')->fetchColumn() === 1, 'Failed run must preserve activation.');
        verify((int) $pdo->query('SELECT COUNT(*) FROM `' . $prefix . 'autostock_disabled` WHERE id_product=2')->fetchColumn() === 0, 'Failed run must roll back tracking.');
    }
    $database->failOn = '';
    verify($sync->synchronize(1, true) === ['disabled' => 1, 'enabled' => 0], 'Failure must release the named lock.');

    $otherConnection = new PDO($dsn, getenv('AUTOSTOCK_TEST_USER') ?: 'root', getenv('AUTOSTOCK_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $lock = "CONCAT('autostock:', SHA1(CONCAT(DATABASE(), ':', '" . sha1($prefix . ':1') . "')))";
    $otherConnection->query('SELECT GET_LOCK(' . $lock . ',0)')->fetchColumn();
    try {
        $sync->synchronize(1, true);
        throw new LogicException('A simultaneous run must be rejected.');
    } catch (Autostock\SynchronizationBusyException $exception) {
        // A different connection holds the lock.
    } finally {
        $otherConnection->query('SELECT RELEASE_LOCK(' . $lock . ')')->fetchColumn();
    }
    echo "MySQL/MariaDB integration tests passed.\n";
} finally {
    foreach (array_reverse($tables) as $table) {
        $pdo->exec('DROP TABLE IF EXISTS `' . $prefix . $table . '`');
    }
}

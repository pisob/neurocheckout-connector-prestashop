<?php
declare(strict_types=1);
// Isolated regression for PHP 7.4's PDO transaction bookkeeping. No database.
require_once __DIR__ . '/../src/Community/PrestashopSourceSnapshot.php';

class SnapshotTransactionStatement extends PDOStatement {
    private $query;
    private $parameters = [];
    public function __construct($query) { $this->query = $query; }
    #[\ReturnTypeWillChange]
    public function execute($parameters = null) { $this->parameters = $parameters ?? []; return true; }
    #[\ReturnTypeWillChange]
    public function fetchAll($mode = null, $className = null, $constructorArguments = null, ...$args) {
        if (strpos($this->query, 'information_schema.TABLES') !== false) {
            return array_map(static function ($name) { return ['TABLE_NAME' => $name, 'ENGINE' => 'InnoDB']; }, $this->parameters);
        }
        if (strpos($this->query, 'SELECT id_shop_group, active') === 0) {
            return $this->parameters[0] === 1 ? [['id_shop_group' => 1, 'active' => 1]] : [];
        }
        return [];
    }
}
class SnapshotTransactionPDO extends PDO {
    public $active = false;
    public $begins = 0;
    public $rollbacks = 0;
    public $statements = [];
    public function __construct() {}
    #[\ReturnTypeWillChange]
    public function getAttribute($attribute) { return $attribute === PDO::ATTR_DRIVER_NAME ? 'mysql' : '10.6-MariaDB'; }
    #[\ReturnTypeWillChange]
    public function setAttribute($attribute, $value) { return true; }
    #[\ReturnTypeWillChange]
    public function exec($query) { $this->statements[] = $query; return 0; }
    #[\ReturnTypeWillChange]
    public function prepare($query, $options = []) { return new SnapshotTransactionStatement($query); }
    public function inTransaction(): bool { return $this->active; }
    public function beginTransaction(): bool { $this->active = true; $this->begins++; return true; }
    public function rollBack(): bool {
        if (!$this->active) { throw new PDOException('There is no active transaction'); }
        $this->active = false; $this->rollbacks++; return true;
    }
}
function checkSnapshotTransaction($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
}
$db = new SnapshotTransactionPDO();
$reader = new NeuroCheckout\Community\PrestashopSourceSnapshot($db, 'ps_');
checkSnapshotTransaction($reader->capture(1) === [], 'empty catalog is a valid snapshot');
checkSnapshotTransaction($db->begins === 1 && $db->rollbacks === 1 && !$db->active, 'PDO tracks and closes the read');
checkSnapshotTransaction(in_array('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY', $db->statements, true), 'database read-only and isolation enforced');
try { $reader->capture(999); throw new LogicException('Invalid scope accepted'); }
catch (RuntimeException $error) { checkSnapshotTransaction($error->getMessage() === 'source_shop_unavailable', 'original error retained'); }
checkSnapshotTransaction($db->rollbacks === 2 && !$db->active, 'failed read rolled back');
$reader->capture(1);
checkSnapshotTransaction($db->begins === 3 && $db->rollbacks === 3, 'next snapshot succeeds');
$db->active = true;
try { $reader->capture(1); throw new LogicException('Existing transaction accepted'); }
catch (RuntimeException $error) { checkSnapshotTransaction($error->getMessage() === 'source_unavailable', 'existing transaction refused'); }
checkSnapshotTransaction($db->active && $db->rollbacks === 3, 'caller transaction preserved');
echo "Snapshot transactions: PHP 7.4 bookkeeping, read-only isolation, repeat, failure cleanup and caller transaction preserved.\n";

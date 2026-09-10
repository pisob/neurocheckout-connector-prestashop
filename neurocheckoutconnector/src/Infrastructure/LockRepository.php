<?php

namespace NeuroCheckout\Infrastructure;

use Db;
use PrestaShopLogger;

class LockRepository
{
    private string $table;
    private int $shopId;

    public function __construct(int $shopId)
    {
        if ($shopId <= 0) {
            throw new \InvalidArgumentException('Invalid shop_id');
        }

        $this->shopId = (int)$shopId;
        $this->table  = _DB_PREFIX_ . 'neurocheckout_lock';
    }

    /* ============================================================
     * Acquire Lock (TTL Based — Atomic)
     * ============================================================ */

    public function acquire(string $key, int $ttlSeconds): bool
    {
        try {

            $db = Db::getInstance();

            $now   = date('Y-m-d H:i:s');
            $until = date('Y-m-d H:i:s', time() + (int)$ttlSeconds);

            // 1️⃣ Global expired lock cleanup (shop scoped)
            $db->execute(
                "DELETE FROM `{$this->table}`
                 WHERE shop_id = {$this->shopId}
                 AND locked_until < NOW()"
            );

            // 2️⃣ Atomic insert (requires UNIQUE(shop_id, lock_key))
            $db->execute(
                "INSERT IGNORE INTO `{$this->table}`
                 (shop_id, lock_key, locked_until, created_at)
                 VALUES (
                    {$this->shopId},
                    '" . pSQL($key) . "',
                    '{$until}',
                    '{$now}'
                 )"
            );

            return $db->Affected_Rows() > 0;

        } catch (\Throwable $e) {

            PrestaShopLogger::addLog(
                '[NC] Lock acquire error: ' . $e->getMessage(),
                3
            );

            return false;
        }
    }

    /* ============================================================
     * Release Lock
     * ============================================================ */

    public function release(string $key): void
    {
        try {

            Db::getInstance()->execute(
                "DELETE FROM `{$this->table}`
                 WHERE shop_id = {$this->shopId}
                 AND lock_key = '" . pSQL($key) . "'"
            );

        } catch (\Throwable $e) {

            PrestaShopLogger::addLog(
                '[NC] Lock release error: ' . $e->getMessage(),
                3
            );
        }
    }

    /* ============================================================
     * Check Lock (Active Only)
     * ============================================================ */

    public function isLocked(string $key): bool
    {
        try {

            $exists = (int) Db::getInstance()->getValue(
                "SELECT 1
                 FROM `{$this->table}`
                 WHERE shop_id = {$this->shopId}
                 AND lock_key = '" . pSQL($key) . "'
                 AND locked_until > NOW()
                 LIMIT 1"
            );

            return $exists === 1;

        } catch (\Throwable $e) {

            PrestaShopLogger::addLog(
                '[NC] Lock isLocked error: ' . $e->getMessage(),
                3
            );

            return false;
        }
    }
}

<?php

namespace NeuroCheckout\Infrastructure;

use Configuration;
use Db;
use PrestaShopLogger;
use NeuroCheckout\Security\IpResolver;

/**
 * =============================================================
 * CronLogRepository v2 — Production Hardened
 * =============================================================
 *
 * - ShopId injecté (aucune dépendance implicite au Context)
 * - Insert via Db::insert (sans préfixe)
 * - SELECT / DELETE avec préfixe manuel
 * - Sécurisation stricte
 * - Tolérance aux erreurs
 * - Haute charge compatible
 */

class CronLogRepository
{
    private string $table;
    private string $tableWithPrefix;
    private int $shopId;

    public function __construct(int $shopId)
    {
        if ($shopId <= 0) {
            throw new \InvalidArgumentException('Invalid shop_id');
        }

        $this->shopId = $shopId;
        $this->table = 'neurocheckout_cron_log';
        $this->tableWithPrefix = _DB_PREFIX_ . $this->table;
    }

    /* ============================================================
     * INSERT LOG ENTRY
     * ============================================================ */

    public function log(
        string $status,
        int $processedEvents = 0,
        int $executionTimeMs = 0,
        ?string $errorMessage = null
    ): bool {

        try {

            $ip = $this->detectIp();

            return Db::getInstance()->insert(
                $this->table, // ⚠ Presta ajoute le préfixe
                [
                    'shop_id'          => (int) $this->shopId,
                    'executed_at'      => date('Y-m-d H:i:s'),
                    'status'           => pSQL($status),
                    'processed_events' => (int) $processedEvents,
                    'execution_time_ms'=> (int) $executionTimeMs,
                    'ip_address'       => pSQL($ip),
                    'error_message'    => $errorMessage
                        ? pSQL(substr($errorMessage, 0, 255))
                        : null,
                ]
            );

        } catch (\Throwable $e) {

            PrestaShopLogger::addLog(
                '[NC] CronLog insert error: ' . $e->getMessage(),
                3
            );

            return false;
        }
    }

    /* ============================================================
     * FETCH LAST LOGS
     * ============================================================ */

    public function getLastLogs(int $limit = 20): array
    {
        try {

            return Db::getInstance()->executeS(
                'SELECT *
                 FROM `' . $this->tableWithPrefix . '`
                 WHERE shop_id = ' . (int)$this->shopId . '
                 ORDER BY executed_at DESC
                 LIMIT ' . (int)$limit
            ) ?: [];

        } catch (\Throwable $e) {

            PrestaShopLogger::addLog(
                '[NC] CronLog getLastLogs error: ' . $e->getMessage(),
                3
            );

            return [];
        }
    }

    /* ============================================================
     * ERROR COUNT (SHORT WINDOW)
     * ============================================================ */

    public function countRecentErrors(int $minutes = 10): int
    {
        try {
            // Keep the same PHP clock basis used when writing executed_at.
            // This avoids timezone drift between PHP date() and SQL NOW().
            $windowMinutes = max(1, (int) $minutes);
            $threshold = date('Y-m-d H:i:s', time() - ($windowMinutes * 60));

            return (int) Db::getInstance()->getValue(
                'SELECT COUNT(*)
                 FROM `' . $this->tableWithPrefix . '`
                 WHERE shop_id = ' . (int)$this->shopId . '
                 AND status = "error"
                 AND executed_at > "' . pSQL($threshold) . '"'
            );

        } catch (\Throwable $e) {

            PrestaShopLogger::addLog(
                '[NC] CronLog countRecentErrors error: ' . $e->getMessage(),
                3
            );

            return 0;
        }
    }

    /* ============================================================
     * GLOBAL STATS
     * ============================================================ */

    public function countAll(): int
    {
        return $this->simpleCount(null);
    }

    public function countSuccess(): int
    {
        return $this->simpleCount('success');
    }

    public function countErrors(): int
    {
        return $this->simpleCount('error');
    }

    private function simpleCount(?string $status): int
    {
        try {

            $where = 'shop_id = ' . (int)$this->shopId;

            if ($status !== null) {
                $where .= ' AND status = "' . pSQL($status) . '"';
            }

            return (int) Db::getInstance()->getValue(
                'SELECT COUNT(*)
                 FROM `' . $this->tableWithPrefix . '`
                 WHERE ' . $where
            );

        } catch (\Throwable $e) {

            PrestaShopLogger::addLog(
                '[NC] CronLog simpleCount error: ' . $e->getMessage(),
                3
            );

            return 0;
        }
    }

    /* ============================================================
     * CLEANUP OLD LOGS
     * ============================================================ */

    public function cleanup(int $days = 30): int
    {
        try {

            Db::getInstance()->execute(
                'DELETE FROM `' . $this->tableWithPrefix . '`
                 WHERE shop_id = ' . (int)$this->shopId . '
                 AND executed_at < DATE_SUB(NOW(), INTERVAL ' . (int)$days . ' DAY)'
            );

            return Db::getInstance()->Affected_Rows();

        } catch (\Throwable $e) {

            PrestaShopLogger::addLog(
                '[NC] CronLog cleanup error: ' . $e->getMessage(),
                3
            );

            return 0;
        }
    }

    /* ============================================================
     * IP DETECTION (SAFE)
     * ============================================================ */

    private function detectIp(): string
    {
        $ipResolver = new IpResolver();
        $trustedProxyRules = $ipResolver->parseRules((string) Configuration::get('NC_TRUSTED_PROXY_IPS'));

        return $ipResolver->resolve($_SERVER, $trustedProxyRules);
    }
}

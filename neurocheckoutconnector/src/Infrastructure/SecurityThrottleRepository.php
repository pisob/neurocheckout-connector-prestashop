<?php

namespace NeuroCheckout\Infrastructure;

use Db;
use DbQuery;
use PrestaShopLogger;

class SecurityThrottleRepository
{
    private const WINDOW_SECONDS = 300;
    private const MAX_FAILURES = 10;
    private const BLOCK_SECONDS = 600;

    private string $table;

    public function __construct()
    {
        $this->table = _DB_PREFIX_ . 'neurocheckout_security_rate_limit';
    }

    public function getRetryAfterSeconds(int $shopId, string $endpoint, string $clientIp): int
    {
        $row = $this->getRow($shopId, $endpoint, $clientIp);
        if (!is_array($row)) {
            return 0;
        }

        $blockedUntil = trim((string) ($row['blocked_until'] ?? ''));
        if ($blockedUntil === '') {
            return 0;
        }

        $blockedUntilTs = strtotime($blockedUntil) ?: 0;
        return $blockedUntilTs > time() ? max(1, $blockedUntilTs - time()) : 0;
    }

    public function recordFailure(int $shopId, string $endpoint, string $clientIp, string $reason = ''): int
    {
        $this->ensureTable();

        $nowTs = time();
        $now = date('Y-m-d H:i:s', $nowTs);
        $windowResetThreshold = date('Y-m-d H:i:s', $nowTs - self::WINDOW_SECONDS);
        $row = $this->getRow($shopId, $endpoint, $clientIp);

        $attemptCount = 1;
        $windowStartedAt = $now;
        $blockedUntil = null;

        if (is_array($row)) {
            $currentBlockedUntil = trim((string) ($row['blocked_until'] ?? ''));
            $currentBlockedUntilTs = $currentBlockedUntil !== '' ? (strtotime($currentBlockedUntil) ?: 0) : 0;
            if ($currentBlockedUntilTs > $nowTs) {
                return max(1, $currentBlockedUntilTs - $nowTs);
            }

            $storedWindowStartedAt = trim((string) ($row['window_started_at'] ?? ''));
            $windowStillOpen = $storedWindowStartedAt !== '' && $storedWindowStartedAt >= $windowResetThreshold;
            $attemptCount = $windowStillOpen ? ((int) ($row['attempt_count'] ?? 0) + 1) : 1;
            $windowStartedAt = $windowStillOpen && $storedWindowStartedAt !== '' ? $storedWindowStartedAt : $now;
        }

        if ($attemptCount > self::MAX_FAILURES) {
            $blockedUntil = date('Y-m-d H:i:s', $nowTs + self::BLOCK_SECONDS);
        }

        $data = [
            'shop_id' => (int) max(0, $shopId),
            'endpoint' => pSQL(substr($endpoint, 0, 32)),
            'client_ip' => pSQL(substr($clientIp, 0, 64)),
            'attempt_count' => (int) max(1, $attemptCount),
            'window_started_at' => pSQL($windowStartedAt),
            'blocked_until' => $blockedUntil !== null ? pSQL($blockedUntil) : null,
            'last_error' => $reason !== '' ? pSQL(substr($reason, 0, 255)) : null,
            'updated_at' => pSQL($now),
        ];

        try {
            if (is_array($row) && (int) ($row['id'] ?? 0) > 0) {
                Db::getInstance()->update(
                    'neurocheckout_security_rate_limit',
                    $data,
                    'id = ' . (int) $row['id']
                );
            } else {
                Db::getInstance()->insert('neurocheckout_security_rate_limit', $data);
            }
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('[NC] SecurityThrottle recordFailure error: ' . $e->getMessage(), 3);
        }

        if ($blockedUntil === null) {
            return 0;
        }

        $blockedUntilTs = strtotime($blockedUntil) ?: 0;
        return $blockedUntilTs > $nowTs ? max(1, $blockedUntilTs - $nowTs) : 0;
    }

    public function clear(int $shopId, string $endpoint, string $clientIp): void
    {
        $this->ensureTable();

        try {
            Db::getInstance()->delete(
                'neurocheckout_security_rate_limit',
                'shop_id = ' . (int) max(0, $shopId)
                . ' AND endpoint = \'' . pSQL(substr($endpoint, 0, 32)) . '\''
                . ' AND client_ip = \'' . pSQL(substr($clientIp, 0, 64)) . '\''
            );
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('[NC] SecurityThrottle clear error: ' . $e->getMessage(), 3);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecentFailures(int $shopId, int $limit = 10): array
    {
        $this->ensureTable();

        try {
            $query = new DbQuery();
            $query->select('endpoint, client_ip, attempt_count, last_error, blocked_until, updated_at');
            $query->from('neurocheckout_security_rate_limit');
            $query->where('shop_id = ' . (int) max(0, $shopId));
            $query->orderBy('updated_at DESC');
            $query->limit(max(1, $limit));

            $rows = Db::getInstance()->executeS($query);
            if (!is_array($rows)) {
                return [];
            }

            $result = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $blockedUntil = trim((string) ($row['blocked_until'] ?? ''));
                $isBlocked = $blockedUntil !== '' && (strtotime($blockedUntil) ?: 0) > time();
                $result[] = [
                    'updated_at' => (string) ($row['updated_at'] ?? ''),
                    'endpoint' => (string) ($row['endpoint'] ?? ''),
                    'client_ip' => (string) ($row['client_ip'] ?? ''),
                    'attempt_count' => (int) ($row['attempt_count'] ?? 0),
                    'last_error' => (string) ($row['last_error'] ?? ''),
                    'status' => $isBlocked ? 'blocked' : 'watch',
                    'blocked_until' => $blockedUntil,
                ];
            }

            return $result;
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('[NC] SecurityThrottle getRecentFailures error: ' . $e->getMessage(), 3);
            return [];
        }
    }

    private function ensureTable(): void
    {
        static $ready = false;

        if ($ready) {
            return;
        }

        try {
            Db::getInstance()->execute(
                'CREATE TABLE IF NOT EXISTS `' . $this->table . '` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `shop_id` INT UNSIGNED NOT NULL,
                    `endpoint` VARCHAR(32) NOT NULL,
                    `client_ip` VARCHAR(64) NOT NULL,
                    `attempt_count` INT UNSIGNED NOT NULL DEFAULT 0,
                    `window_started_at` DATETIME NOT NULL,
                    `blocked_until` DATETIME DEFAULT NULL,
                    `last_error` VARCHAR(255) DEFAULT NULL,
                    `updated_at` DATETIME NOT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uniq_scope` (`shop_id`, `endpoint`, `client_ip`),
                    KEY `idx_blocked_until` (`blocked_until`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
            $ready = true;
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('[NC] SecurityThrottle ensureTable error: ' . $e->getMessage(), 3);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getRow(int $shopId, string $endpoint, string $clientIp): ?array
    {
        $this->ensureTable();

        try {
            $query = new DbQuery();
            $query->select('id, attempt_count, window_started_at, blocked_until');
            $query->from('neurocheckout_security_rate_limit');
            $query->where('shop_id = ' . (int) max(0, $shopId));
            $query->where('endpoint = \'' . pSQL(substr($endpoint, 0, 32)) . '\'');
            $query->where('client_ip = \'' . pSQL(substr($clientIp, 0, 64)) . '\'');

            $row = Db::getInstance()->getRow($query);
            return is_array($row) ? $row : null;
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('[NC] SecurityThrottle getRow error: ' . $e->getMessage(), 3);
            return null;
        }
    }
}

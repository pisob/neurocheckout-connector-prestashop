<?php

namespace NeuroCheckout\Infrastructure;

use Db;
use PrestaShopLogger;

class CustomerJourneyEventRepository
{
    private const MAX_RETRIES = 8;
    private const MAX_RETRY_AGE_HOURS = 48;

    private string $table;
    private int $shopId;

    public function __construct(int $shopId)
    {
        if ($shopId <= 0) {
            throw new \InvalidArgumentException('Invalid shop_id');
        }

        $this->shopId = $shopId;
        $this->table = _DB_PREFIX_ . 'neurocheckout_customer_journey_event';
    }

    public function ensureTable(): bool
    {
        $query = '
            CREATE TABLE IF NOT EXISTS `' . $this->table . '` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `shop_id` INT UNSIGNED NOT NULL,
                `event_id` VARCHAR(120) NOT NULL,
                `event_type` VARCHAR(140) NOT NULL,
                `visitor_id` VARCHAR(120) DEFAULT NULL,
                `session_id` VARCHAR(120) DEFAULT NULL,
                `cart_id` VARCHAR(64) DEFAULT NULL,
                `customer_ref` VARCHAR(120) DEFAULT NULL,
                `event_hash` VARCHAR(64) NOT NULL,
                `payload` LONGTEXT NOT NULL,
                `status` ENUM(\'pending\',\'processing\',\'sent\',\'dead\') NOT NULL DEFAULT \'pending\',
                `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `next_retry_at` DATETIME DEFAULT NULL,
                `last_attempt_at` DATETIME DEFAULT NULL,
                `last_error` VARCHAR(255) DEFAULT NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                `sent_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_shop_event_id` (`shop_id`, `event_id`),
                KEY `idx_dispatch` (`shop_id`, `status`, `next_retry_at`, `created_at`),
                KEY `idx_visitor` (`shop_id`, `visitor_id`, `created_at`),
                KEY `idx_session` (`shop_id`, `session_id`, `created_at`),
                KEY `idx_cart` (`shop_id`, `cart_id`, `created_at`),
                KEY `idx_event_type` (`event_type`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ';

        try {
            return (bool) Db::getInstance()->execute($query);
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('[NC] Customer journey table create error: ' . $e->getMessage(), 3);
            return false;
        }
    }

    public function enqueue(array $payload): bool
    {
        if (!$this->ensureTable()) {
            return false;
        }

        $eventId = trim((string) ($payload['event_id'] ?? ''));
        $eventType = trim((string) ($payload['event_type'] ?? ''));
        if ($eventId === '' || $eventType === '') {
            return false;
        }

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($payloadJson) || $payloadJson === '') {
            return false;
        }
        if (strlen($payloadJson) > 131072) {
            PrestaShopLogger::addLog('[NC] Customer journey payload skipped: too large', 2);
            return false;
        }

        $refs = $this->resolveRefs($payload);
        $eventHash = hash('sha256', $payloadJson);

        try {
            return (bool) Db::getInstance()->execute(
                '
                INSERT INTO `' . $this->table . '`
                    (`shop_id`, `event_id`, `event_type`, `visitor_id`, `session_id`, `cart_id`, `customer_ref`, `event_hash`, `payload`, `status`, `attempts`, `next_retry_at`, `created_at`, `updated_at`)
                VALUES
                    (' . (int) $this->shopId . ',
                     "' . pSQL($eventId) . '",
                     "' . pSQL($eventType) . '",
                     ' . ($refs['visitor_id'] !== null ? '"' . pSQL($refs['visitor_id']) . '"' : 'NULL') . ',
                     ' . ($refs['session_id'] !== null ? '"' . pSQL($refs['session_id']) . '"' : 'NULL') . ',
                     ' . ($refs['cart_id'] !== null ? '"' . pSQL($refs['cart_id']) . '"' : 'NULL') . ',
                     ' . ($refs['customer_ref'] !== null ? '"' . pSQL($refs['customer_ref']) . '"' : 'NULL') . ',
                     "' . pSQL($eventHash) . '",
                     "' . pSQL($payloadJson, true) . '",
                     "pending",
                     0,
                     NOW(),
                     NOW(),
                     NOW())
                ON DUPLICATE KEY UPDATE
                    `payload` = IF(`status` IN ("sent", "dead"), `payload`, VALUES(`payload`)),
                    `event_hash` = IF(`status` IN ("sent", "dead"), `event_hash`, VALUES(`event_hash`)),
                    `attempts` = IF(`status` IN ("sent", "dead"), `attempts`, 0),
                    `next_retry_at` = IF(`status` IN ("sent", "dead"), `next_retry_at`, NOW()),
                    `last_error` = IF(`status` IN ("sent", "dead"), `last_error`, NULL),
                    `updated_at` = NOW(),
                    `status` = IF(`status` IN ("sent", "dead"), `status`, "pending")
                '
            );
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('[NC] Customer journey enqueue error: ' . $e->getMessage(), 3);
            return false;
        }
    }

    public function lockBatchAtomic(int $limit = 50): array
    {
        if (!$this->ensureTable()) {
            return [];
        }

        $db = Db::getInstance();
        $inTransaction = false;

        try {
            $limit = max(1, min(200, $limit));
            $db->execute('START TRANSACTION');
            $inTransaction = true;

            $rows = $db->executeS(
                '
                SELECT `id`
                FROM `' . $this->table . '`
                WHERE `shop_id` = ' . (int) $this->shopId . '
                  AND `status` = "pending"
                  AND (`next_retry_at` IS NULL OR `next_retry_at` <= NOW())
                ORDER BY `created_at` ASC
                LIMIT ' . (int) $limit . '
                FOR UPDATE
                '
            ) ?: [];

            $ids = array_values(array_filter(array_map(static function ($row) {
                return (int) ($row['id'] ?? 0);
            }, $rows)));

            if (empty($ids)) {
                $db->execute('COMMIT');
                return [];
            }

            $idList = implode(',', $ids);
            $db->execute(
                '
                UPDATE `' . $this->table . '`
                SET `status` = "processing",
                    `last_attempt_at` = NOW(),
                    `updated_at` = NOW()
                WHERE `shop_id` = ' . (int) $this->shopId . '
                  AND `status` = "pending"
                  AND `id` IN (' . $idList . ')
                '
            );

            $lockedRows = $db->executeS(
                '
                SELECT *
                FROM `' . $this->table . '`
                WHERE `shop_id` = ' . (int) $this->shopId . '
                  AND `status` = "processing"
                  AND `id` IN (' . $idList . ')
                ORDER BY `created_at` ASC
                '
            ) ?: [];

            $db->execute('COMMIT');
            $inTransaction = false;

            return $lockedRows;
        } catch (\Throwable $e) {
            if ($inTransaction) {
                try {
                    $db->execute('ROLLBACK');
                } catch (\Throwable $rollbackError) {
                }
            }

            PrestaShopLogger::addLog('[NC] Customer journey lock error: ' . $e->getMessage(), 3);
            return [];
        }
    }

    public function releaseStuckProcessing(int $minutes = 5): int
    {
        if (!$this->ensureTable()) {
            return 0;
        }

        Db::getInstance()->execute(
            '
            UPDATE `' . $this->table . '`
            SET `status` = "pending",
                `next_retry_at` = NOW(),
                `updated_at` = NOW()
            WHERE `shop_id` = ' . (int) $this->shopId . '
              AND `status` = "processing"
              AND `last_attempt_at` < DATE_SUB(NOW(), INTERVAL ' . max(1, (int) $minutes) . ' MINUTE)
            '
        );

        return (int) Db::getInstance()->Affected_Rows();
    }

    public function expireRetryableEvents(): int
    {
        if (!$this->ensureTable()) {
            return 0;
        }

        Db::getInstance()->execute(
            '
            UPDATE `' . $this->table . '`
            SET `status` = "dead",
                `payload` = "",
                `visitor_id` = NULL,
                `session_id` = NULL,
                `cart_id` = NULL,
                `customer_ref` = NULL,
                `next_retry_at` = NULL,
                `last_error` = "retry_window_expired",
                `updated_at` = NOW()
            WHERE `shop_id` = ' . (int) $this->shopId . '
              AND `status` = "pending"
              AND `created_at` < DATE_SUB(NOW(), INTERVAL ' . self::MAX_RETRY_AGE_HOURS . ' HOUR)
            '
        );

        return (int) Db::getInstance()->Affected_Rows();
    }

    public function markAsSent(int $id): bool
    {
        Db::getInstance()->execute(
            '
            UPDATE `' . $this->table . '`
            SET `status` = "sent",
                `payload` = "",
                `visitor_id` = NULL,
                `session_id` = NULL,
                `cart_id` = NULL,
                `customer_ref` = NULL,
                `sent_at` = NOW(),
                `next_retry_at` = NULL,
                `last_error` = NULL,
                `updated_at` = NOW()
            WHERE `shop_id` = ' . (int) $this->shopId . '
              AND `id` = ' . (int) $id . '
              AND `status` = "processing"
            '
        );

        return Db::getInstance()->Affected_Rows() > 0;
    }

    public function markAsFailed(int $id, string $error): bool
    {
        $row = Db::getInstance()->getRow(
            '
            SELECT `attempts`
            FROM `' . $this->table . '`
            WHERE `shop_id` = ' . (int) $this->shopId . '
              AND `id` = ' . (int) $id . '
            '
        );

        if (!$row) {
            return false;
        }

        $attempts = (int) ($row['attempts'] ?? 0) + 1;
        $shortError = substr(trim($error) !== '' ? trim($error) : 'customer_journey_send_failed', 0, 255);

        if ($attempts >= self::MAX_RETRIES) {
            return $this->markAsDead($id, $shortError, $attempts);
        }

        $delaySeconds = min(900, max(30, (int) (30 * pow(2, min($attempts - 1, 5)))));

        Db::getInstance()->execute(
            '
            UPDATE `' . $this->table . '`
            SET `status` = "pending",
                `attempts` = ' . (int) $attempts . ',
                `next_retry_at` = DATE_ADD(NOW(), INTERVAL ' . (int) $delaySeconds . ' SECOND),
                `last_error` = "' . pSQL($shortError) . '",
                `updated_at` = NOW()
            WHERE `shop_id` = ' . (int) $this->shopId . '
              AND `id` = ' . (int) $id . '
              AND `status` = "processing"
            '
        );

        return Db::getInstance()->Affected_Rows() > 0;
    }

    public function markAsDead(int $id, string $error = 'dead_letter', int $attempts = 0): bool
    {
        $attemptsSet = $attempts > 0 ? '`attempts` = ' . (int) $attempts . ',' : '';
        Db::getInstance()->execute(
            '
            UPDATE `' . $this->table . '`
            SET `status` = "dead",
                ' . $attemptsSet . '
                `payload` = "",
                `visitor_id` = NULL,
                `session_id` = NULL,
                `cart_id` = NULL,
                `customer_ref` = NULL,
                `next_retry_at` = NULL,
                `last_error` = "' . pSQL(substr($error, 0, 255)) . '",
                `updated_at` = NOW()
            WHERE `shop_id` = ' . (int) $this->shopId . '
              AND `id` = ' . (int) $id . '
              AND `status` = "processing"
            '
        );

        return Db::getInstance()->Affected_Rows() > 0;
    }

    public function countPendingReady(): int
    {
        if (!$this->ensureTable()) {
            return 0;
        }

        return (int) Db::getInstance()->getValue(
            '
            SELECT COUNT(*)
            FROM `' . $this->table . '`
            WHERE `shop_id` = ' . (int) $this->shopId . '
              AND `status` = "pending"
              AND (`next_retry_at` IS NULL OR `next_retry_at` <= NOW())
            '
        );
    }

    public function purgeTerminalBatch(int $days = 14, int $batchSize = 300): int
    {
        if (!$this->ensureTable()) {
            return 0;
        }

        $days = max(1, min(90, $days));
        $batchSize = max(1, min(1000, $batchSize));

        $ids = Db::getInstance()->executeS(
            '
            SELECT `id`
            FROM `' . $this->table . '`
            WHERE `shop_id` = ' . (int) $this->shopId . '
              AND `status` IN ("sent", "dead")
              AND `updated_at` < DATE_SUB(NOW(), INTERVAL ' . (int) $days . ' DAY)
            ORDER BY `updated_at` ASC
            LIMIT ' . (int) $batchSize . '
            '
        ) ?: [];

        $idList = implode(',', array_values(array_filter(array_map(static function ($row) {
            return (int) ($row['id'] ?? 0);
        }, $ids))));

        if ($idList === '') {
            return 0;
        }

        Db::getInstance()->execute(
            '
            DELETE FROM `' . $this->table . '`
            WHERE `shop_id` = ' . (int) $this->shopId . '
              AND `id` IN (' . $idList . ')
            '
        );

        return (int) Db::getInstance()->Affected_Rows();
    }

    private function resolveRefs(array $payload): array
    {
        $journey = isset($payload['journey']) && is_array($payload['journey']) ? $payload['journey'] : [];
        $cart = isset($payload['cart']) && is_array($payload['cart']) ? $payload['cart'] : [];
        $customer = isset($payload['customer']) && is_array($payload['customer']) ? $payload['customer'] : [];

        return [
            'visitor_id' => $this->cleanRef($journey['visitor_id'] ?? null, 120),
            'session_id' => $this->cleanRef($journey['session_id'] ?? null, 120),
            'cart_id' => $this->cleanRef($cart['id'] ?? null, 64),
            'customer_ref' => $this->cleanRef($customer['id'] ?? ($customer['email_hash'] ?? null), 120),
        ];
    }

    private function cleanRef($value, int $maxLength): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        $text = preg_replace('/[^a-zA-Z0-9_.:@-]/', '', $text);
        $text = substr($text, 0, $maxLength);

        return $text !== '' ? $text : null;
    }
}

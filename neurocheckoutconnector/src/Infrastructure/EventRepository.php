<?php

namespace NeuroCheckout\Infrastructure;

use Db;
use PrestaShopLogger;

class EventRepository
{
    private const MAX_RETRY_AGE_HOURS = 48;

    private string $table;
    private int $shopId;

    public function __construct(int $shopId)
    {
        if ($shopId <= 0) {
            throw new \InvalidArgumentException('Invalid shop_id');
        }

        $this->shopId = (int)$shopId;
        $this->table  = _DB_PREFIX_ . 'neurocheckout_event';
    }

    /* ============================================================
     * INSERT MINIMAL
     * ============================================================ */

    public function insertMinimal(int $cartId): bool
    {
        if ($cartId <= 0) {
            return false;
        }

        try {

            return (bool) Db::getInstance()->execute(
                '
                INSERT INTO `' . $this->table . '`
                (
                    shop_id,
                    cart_id,
                    event_hash,
                    payload,
                    status,
                    attempts,
                    created_at,
                    last_attempt_at,
                    next_retry_at,
                    priority
                )
                VALUES
                (
                    ' . $this->shopId . ',
                    "' . (int)$cartId . '",
                    "",
                    "",
                    "pending",
                    0,
                    NOW(),
                    NULL,
                    NULL,
                    0
                )
                ON DUPLICATE KEY UPDATE
                    event_hash = IF(status="processing", event_hash, ""),
                    payload = IF(status="processing", payload, ""),
                    status = IF(status="processing","processing","pending"),
                    attempts = 0,
                    next_retry_at = NULL,
                    created_at = NOW()
                '
            );

        } catch (\Throwable $e) {

            PrestaShopLogger::addLog(
                '[NC] insertMinimal error: ' . $e->getMessage(),
                3
            );

            return false;
        }
    }

    /* ============================================================
     * LOCK BATCH — REAL ATOMIC VERSION
     * ============================================================ */

    public function lockBatchAtomic(int $limit = 100): array
    {
        $db = Db::getInstance();
        $inTransaction = false;

        try {

            $limit = max(1, (int) $limit);

            // Use a real transaction + row locks to avoid cross-worker batch mixups.
            $db->execute('START TRANSACTION');
            $inTransaction = true;

            $candidateRows = $db->executeS(
                "
                SELECT id
                FROM `{$this->table}`
                WHERE shop_id = {$this->shopId}
                AND status = 'pending'
                AND (next_retry_at IS NULL OR next_retry_at <= NOW())
                ORDER BY priority DESC, created_at ASC
                LIMIT {$limit}
                FOR UPDATE
                "
            ) ?: [];

            if (empty($candidateRows)) {
                $db->execute('COMMIT');
                return [];
            }

            $ids = array_map(
                static function ($row) {
                    return (int) $row['id'];
                },
                $candidateRows
            );
            $ids = array_filter($ids, static function ($id) {
                return $id > 0;
            });

            if (empty($ids)) {
                $db->execute('COMMIT');
                return [];
            }

            $idList = implode(',', $ids);

            $db->execute(
                "
                UPDATE `{$this->table}`
                SET status = 'processing',
                    last_attempt_at = NOW()
                WHERE shop_id = {$this->shopId}
                AND status = 'pending'
                AND id IN ({$idList})
                "
            );

            if ($db->Affected_Rows() === 0) {
                $db->execute('COMMIT');
                return [];
            }

            $lockedRows = $db->executeS(
                "
                SELECT *
                FROM `{$this->table}`
                WHERE shop_id = {$this->shopId}
                AND status = 'processing'
                AND id IN ({$idList})
                ORDER BY priority DESC, created_at ASC
                "
            ) ?: [];

            $db->execute('COMMIT');
            $inTransaction = false;

            return $lockedRows;

        } catch (\Throwable $e) {

            if ($inTransaction) {
                try {
                    $db->execute('ROLLBACK');
                } catch (\Throwable $rollbackError) {
                    // Never override the original failure path.
                }
            }

            PrestaShopLogger::addLog(
                '[NC] lockBatchAtomic error: ' . $e->getMessage(),
                3
            );

            return [];
        }
    }

    /* ============================================================
     * RELEASE STUCK PROCESSING
     * ============================================================ */

    public function releaseStuckProcessing(int $minutes = 10): int
    {
        $minutes = max(1, (int) $minutes);
        Db::getInstance()->execute(
            "
            UPDATE `{$this->table}`
            SET status='pending',
                next_retry_at = NULL
            WHERE shop_id = {$this->shopId}
            AND status='processing'
            AND last_attempt_at < DATE_SUB(NOW(), INTERVAL {$minutes} MINUTE)
            "
        );

        return (int) Db::getInstance()->Affected_Rows();
    }

    public function expireRetryableEvents(): int
    {
        Db::getInstance()->execute(
            "
            UPDATE `{$this->table}`
            SET status='dead',
                payload='',
                next_retry_at = NULL,
                last_attempt_at = NOW()
            WHERE shop_id = {$this->shopId}
            AND status='pending'
            AND created_at < DATE_SUB(NOW(), INTERVAL " . self::MAX_RETRY_AGE_HOURS . " HOUR)
            "
        );

        return (int) Db::getInstance()->Affected_Rows();
    }

    /* ============================================================
     * MARK AS SENT
     * ============================================================ */

    public function markAsSent(int $id): bool
    {
        Db::getInstance()->execute(
            "
            UPDATE `{$this->table}`
            SET status='sent',
                payload='',
                last_attempt_at = NOW(),
                next_retry_at = NULL
            WHERE id = " . (int)$id . "
            AND shop_id = {$this->shopId}
            AND status = 'processing'
            "
        );

        return Db::getInstance()->Affected_Rows() > 0;
    }

    /* ============================================================
     * MARK AS FAILED (EXPONENTIAL BACKOFF)
     * ============================================================ */

    public function markAsFailed(int $id): bool
    {
        $row = Db::getInstance()->getRow(
            "
            SELECT attempts
            FROM `{$this->table}`
            WHERE id = " . (int)$id . "
            AND shop_id = {$this->shopId}
            "
        );

        if (!$row) {
            return false;
        }

        $attempts = (int)$row['attempts'] + 1;
        $delaySeconds = min(3600, pow(2, $attempts) * 10);

        Db::getInstance()->execute(
            "
            UPDATE `{$this->table}`
            SET attempts = {$attempts},
                status = 'pending',
                last_attempt_at = NOW(),
                next_retry_at = DATE_ADD(NOW(), INTERVAL {$delaySeconds} SECOND)
            WHERE id = " . (int)$id . "
            AND shop_id = {$this->shopId}
            AND status = 'processing'
            "
        );

        return Db::getInstance()->Affected_Rows() > 0;
    }

    /* ============================================================
     * MARK TERMINAL STATES
     * ============================================================ */

    public function markAsDead(int $id): bool
    {
        Db::getInstance()->execute(
            "
            UPDATE `{$this->table}`
            SET status = 'dead',
                payload = '',
                next_retry_at = NULL
            WHERE id = " . (int)$id . "
            AND shop_id = {$this->shopId}
            AND status = 'processing'
            "
        );

        return Db::getInstance()->Affected_Rows() > 0;
    }

    public function markAsCleared(int $id): bool
    {
        Db::getInstance()->execute(
            "
            UPDATE `{$this->table}`
            SET status = 'cleared',
                payload = '',
                last_attempt_at = NOW(),
                next_retry_at = NULL
            WHERE id = " . (int)$id . "
            AND shop_id = {$this->shopId}
            AND status = 'processing'
            "
        );

        return Db::getInstance()->Affected_Rows() > 0;
    }

    /* ============================================================
     * PRIORITY BOOST
     * ============================================================ */

    public function boostPriority(int $cartId): void
    {
        Db::getInstance()->execute(
            "
            UPDATE `{$this->table}`
            SET priority = priority + 1
            WHERE shop_id = {$this->shopId}
            AND cart_id = " . (int)$cartId . "
            "
        );
    }
    public function purgeSentBatch(int $days, int $batchSize = 500): int
    {
        return $this->deleteOldTerminalBatch($days, $batchSize);
    }

    public function deleteOldTerminalBatch(int $days, int $batchSize = 500): int
    {
        Db::getInstance()->execute(
            "
            DELETE FROM `{$this->table}`
            WHERE shop_id = {$this->shopId}
            AND status IN ('sent','cleared','dead')
            AND created_at < DATE_SUB(NOW(), INTERVAL {$days} DAY)
            LIMIT {$batchSize}
            "
        );

        return (int) Db::getInstance()->Affected_Rows();
    }
    

    public function updateSnapshot(int $eventId, array $payload, string $hash): bool {

            try {

                $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

                if ($json === false) {
                    return false;
                }

                return Db::getInstance()->update(
                    'neurocheckout_event',
                    [
                        'payload'       => pSQL($json, true),
                        'event_hash'    => pSQL($hash),
                        'next_retry_at' => null,
                    ],
                    'id = ' . (int)$eventId .
                    ' AND shop_id = ' . (int)$this->shopId .
                    " AND status = 'processing'"
                );

            } catch (\Throwable $e) {

                PrestaShopLogger::addLog(
                    '[NC] updateSnapshot error: ' . $e->getMessage(),
                    3
                );

                return false;
            }
        }



}

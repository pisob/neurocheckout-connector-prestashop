<?php

namespace NeuroCheckout\Infrastructure;

use Db;
use DbQuery;
use PrestaShopLogger;

class RecoveryAuditRepository
{
    private int $shopId;

    public function __construct(int $shopId)
    {
        $this->shopId = max(1, $shopId);
    }

    public function getRecentCoupons(int $limit = 5): array
    {
        try {
            $query = new DbQuery();
            $query->select('created_at, cart_id, customer_email, coupon_code, discount_percent, expires_at');
            $query->from('neurocheckout_coupon');
            $query->where('shop_id = ' . (int) $this->shopId);
            $query->orderBy('created_at DESC');
            $query->limit(max(1, $limit));

            return Db::getInstance()->executeS($query) ?: [];
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('[NC] RecoveryAudit getRecentCoupons error: ' . $e->getMessage(), 3);
            return [];
        }
    }

    public function getRecentRecoveryTokens(int $limit = 5): array
    {
        try {
            $query = new DbQuery();
            $query->select('created_at, cart_id, customer_email, coupon_code, expires_at, used_at');
            $query->from('neurocheckout_recovery_token');
            $query->where('shop_id = ' . (int) $this->shopId);
            $query->orderBy('created_at DESC');
            $query->limit(max(1, $limit));

            $rows = Db::getInstance()->executeS($query) ?: [];
            foreach ($rows as &$row) {
                $row['status'] = $this->resolveRecoveryStatus($row);
            }
            unset($row);

            return $rows;
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('[NC] RecoveryAudit getRecentRecoveryTokens error: ' . $e->getMessage(), 3);
            return [];
        }
    }

    private function resolveRecoveryStatus(array $row): string
    {
        $usedAt = trim((string) ($row['used_at'] ?? ''));
        if ($usedAt !== '') {
            return 'used';
        }

        $expiresAt = trim((string) ($row['expires_at'] ?? ''));
        if ($expiresAt !== '' && (strtotime($expiresAt) ?: 0) < time()) {
            return 'expired';
        }

        return 'ready';
    }
}

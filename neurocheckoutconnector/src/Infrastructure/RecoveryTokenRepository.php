<?php

namespace NeuroCheckout\Infrastructure;

use Db;
use DbQuery;

class RecoveryTokenRepository
{
    private const TOKEN_BYTES = 32;

    private string $table;

    public function __construct()
    {
        $this->table = _DB_PREFIX_ . 'neurocheckout_recovery_token';
        $this->ensureTable();
    }

    public function issue(
        int $shopId,
        string $cartId,
        string $customerEmail,
        string $couponCode,
        int $ttlSeconds,
        string $cartFingerprint = ''
    ): ?string {
        $normalizedCartId = trim($cartId);
        $normalizedEmail = strtolower(trim($customerEmail));
        $normalizedFingerprint = trim($cartFingerprint);
        if ($shopId <= 0 || $normalizedCartId === '' || $normalizedEmail === '' || $ttlSeconds <= 0) {
            return null;
        }

        $this->purgeExpired();

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $token = $this->generateToken();
            if ($token === null) {
                return null;
            }
            $inserted = Db::getInstance()->insert(
                'neurocheckout_recovery_token',
                [
                    'shop_id' => (int) $shopId,
                    'token_hash' => pSQL($this->hashToken($token)),
                    'cart_id' => pSQL($normalizedCartId),
                    'customer_email' => pSQL($normalizedEmail),
                    'coupon_code' => $couponCode !== '' ? pSQL($couponCode) : null,
                    'cart_fingerprint' => $normalizedFingerprint !== '' ? pSQL($normalizedFingerprint) : null,
                    'expires_at' => date('Y-m-d H:i:s', time() + $ttlSeconds),
                    'used_at' => null,
                    'created_at' => date('Y-m-d H:i:s'),
                ]
            );

            if ($inserted) {
                return $token;
            }
        }

        return null;
    }

    public function issueCustomerSession(
        int $shopId,
        int $customerId,
        string $customerEmail,
        string $targetUrl,
        int $ttlSeconds
    ): ?string {
        $normalizedEmail = strtolower(trim($customerEmail));
        $normalizedTarget = trim($targetUrl);
        if ($shopId <= 0 || $customerId <= 0 || $normalizedEmail === '' || $normalizedTarget === '' || $ttlSeconds <= 0) {
            return null;
        }

        $this->purgeExpired();

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $token = $this->generateToken();
            if ($token === null) {
                return null;
            }
            $inserted = Db::getInstance()->insert(
                'neurocheckout_recovery_token',
                [
                    'shop_id' => (int) $shopId,
                    'token_hash' => pSQL($this->hashToken($token)),
                    'cart_id' => pSQL('customer:' . (int) $customerId),
                    'customer_email' => pSQL($normalizedEmail),
                    'coupon_code' => null,
                    'cart_fingerprint' => null,
                    'mode' => pSQL('customer_session'),
                    'customer_id' => (int) $customerId,
                    'target_url' => pSQL($normalizedTarget),
                    'expires_at' => date('Y-m-d H:i:s', time() + $ttlSeconds),
                    'used_at' => null,
                    'created_at' => date('Y-m-d H:i:s'),
                ]
            );

            if ($inserted) {
                return $token;
            }
        }

        return null;
    }

    public function resolveUsable(string $token): ?array
    {
        $tokenHash = $this->hashToken($token);
        if ($tokenHash === '') {
            return null;
        }

        $query = new DbQuery();
        $query->select('shop_id, cart_id, customer_email, coupon_code, cart_fingerprint, mode, customer_id, target_url, expires_at');
        $query->from('neurocheckout_recovery_token');
        $query->where('token_hash = \'' . pSQL($tokenHash) . '\'');
        $query->where('(used_at IS NULL OR used_at = \'0000-00-00 00:00:00\')');
        $query->orderBy('id DESC');

        $row = Db::getInstance()->getRow($query);
        if (!$row) {
            return null;
        }

        $expiresAt = trim((string) ($row['expires_at'] ?? ''));
        if ($expiresAt !== '' && strtotime($expiresAt) < time()) {
            return null;
        }

        return [
            'shop_id' => (int) ($row['shop_id'] ?? 0),
            'cart_id' => (string) ($row['cart_id'] ?? ''),
            'customer_email' => (string) ($row['customer_email'] ?? ''),
            'coupon_code' => (string) ($row['coupon_code'] ?? ''),
            'cart_fingerprint' => (string) ($row['cart_fingerprint'] ?? ''),
            'mode' => (string) ($row['mode'] ?? 'cart'),
            'customer_id' => (int) ($row['customer_id'] ?? 0),
            'target_url' => (string) ($row['target_url'] ?? ''),
            'expires_at' => $expiresAt,
        ];
    }

    public function consume(string $token): bool
    {
        $tokenHash = $this->hashToken($token);
        if ($tokenHash === '') {
            return false;
        }

        Db::getInstance()->execute(
            "
            UPDATE `{$this->table}`
            SET used_at = NOW()
            WHERE token_hash = '" . pSQL($tokenHash) . "'
              AND (used_at IS NULL OR used_at = '0000-00-00 00:00:00')
              AND expires_at >= NOW()
            "
        );

        return Db::getInstance()->Affected_Rows() > 0;
    }

    public function purgeExpired(): void
    {
        Db::getInstance()->execute(
            "
            DELETE FROM `{$this->table}`
            WHERE expires_at < NOW()
            "
        );
    }

    public function ensureTable(): void
    {
        Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . $this->table . '` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `shop_id` INT UNSIGNED NOT NULL,
                `token_hash` VARCHAR(64) NOT NULL,
                `cart_id` VARCHAR(64) NOT NULL,
                `customer_email` VARCHAR(255) NOT NULL,
                `coupon_code` VARCHAR(64) DEFAULT NULL,
                `cart_fingerprint` VARCHAR(64) DEFAULT NULL,
                `mode` VARCHAR(32) NOT NULL DEFAULT "cart",
                `customer_id` INT UNSIGNED DEFAULT NULL,
                `target_url` TEXT DEFAULT NULL,
                `expires_at` DATETIME NOT NULL,
                `used_at` DATETIME DEFAULT NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_token_hash` (`token_hash`),
                KEY `idx_shop_expires` (`shop_id`, `expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $columns = Db::getInstance()->executeS('SHOW COLUMNS FROM `' . $this->table . '` LIKE "cart_fingerprint"');
        if (!is_array($columns) || count($columns) === 0) {
            Db::getInstance()->execute(
                'ALTER TABLE `' . $this->table . '` ADD `cart_fingerprint` VARCHAR(64) DEFAULT NULL AFTER `coupon_code`'
            );
        }

        $columns = Db::getInstance()->executeS('SHOW COLUMNS FROM `' . $this->table . '` LIKE "mode"');
        if (!is_array($columns) || count($columns) === 0) {
            Db::getInstance()->execute(
                'ALTER TABLE `' . $this->table . '` ADD `mode` VARCHAR(32) NOT NULL DEFAULT "cart" AFTER `cart_fingerprint`'
            );
        }

        $columns = Db::getInstance()->executeS('SHOW COLUMNS FROM `' . $this->table . '` LIKE "customer_id"');
        if (!is_array($columns) || count($columns) === 0) {
            Db::getInstance()->execute(
                'ALTER TABLE `' . $this->table . '` ADD `customer_id` INT UNSIGNED DEFAULT NULL AFTER `mode`'
            );
        }

        $columns = Db::getInstance()->executeS('SHOW COLUMNS FROM `' . $this->table . '` LIKE "target_url"');
        if (!is_array($columns) || count($columns) === 0) {
            Db::getInstance()->execute(
                'ALTER TABLE `' . $this->table . '` ADD `target_url` TEXT DEFAULT NULL AFTER `customer_id`'
            );
        }
    }

    private function generateToken(): ?string
    {
        try {
            return bin2hex(random_bytes(self::TOKEN_BYTES));
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function hashToken(string $token): string
    {
        $normalized = trim($token);
        if ($normalized === '') {
            return '';
        }

        return hash('sha256', $normalized);
    }
}

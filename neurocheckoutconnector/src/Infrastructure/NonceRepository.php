<?php

namespace NeuroCheckout\Infrastructure;

use Db;
use Context;

class NonceRepository
{
    private string $table;
    private int $shopId;

    public function __construct()
    {
        $this->table = _DB_PREFIX_ . 'neurocheckout_nonce';

        $context = Context::getContext();
        $this->shopId = (int) $context->shop->id ?: 1;
    }

    /* ============================================================
     * CHECK + INSERT ATOMIC
     * ============================================================ */

    public function register(string $nonce, int $ttl): bool
    {
        $expires = time() + $ttl;

        try {

            return Db::getInstance()->insert(
                'neurocheckout_nonce',
                [
                    'nonce'      => pSQL($nonce),
                    'expires_at' => (int)$expires,
                    'shop_id'    => $this->shopId,
                ]
            );

        } catch (\Exception $e) {
            // Unique violation = replay
            return false;
        }
    }

    /* ============================================================
     * PURGE EXPIRED
     * ============================================================ */

    public function purgeExpired(): void
    {
        Db::getInstance()->execute(
            'DELETE FROM `' . $this->table . '`
             WHERE expires_at < ' . time()
        );
    }
}

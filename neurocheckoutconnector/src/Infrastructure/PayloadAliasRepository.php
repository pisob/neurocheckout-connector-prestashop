<?php

namespace NeuroCheckout\Infrastructure;

use Db;
use PrestaShopLogger;

/**
 * PayloadAliasRepository
 *
 * Responsabilités :
 *  - Gérer mapping dynamique original_key → alias_key
 *  - Isolation multi-shop
 *  - Gestion transactionnelle
 *  - Versionnement schema alias
 *
 * Core payload n'est PAS concerné ici.
 * Uniquement extensions dynamiques.
 */
class PayloadAliasRepository
{
    private string $table;
    private int $shopId;

    public function __construct(int $shopId)
    {
        if ($shopId <= 0) {
            throw new \InvalidArgumentException('Invalid shop_id');
        }

        $this->shopId = $shopId;
        $this->table  = _DB_PREFIX_ . 'neurocheckout_payload_alias';
    }

    /* ============================================================
     * PUBLIC API
     * ============================================================ */

    /**
     * Retourne mapping complet [original_key => alias_key]
     */
    public function getAllMappings(): array
    {
        $rows = Db::getInstance()->executeS(
            'SELECT original_key, alias_key
             FROM `' . $this->table . '`
             WHERE shop_id = ' . (int)$this->shopId
        );

        if (!$rows) {
            return [];
        }

        $map = [];

        foreach ($rows as $row) {
            $map[$row['original_key']] = $row['alias_key'];
        }

        return $map;
    }

    /**
     * Retourne alias existant ou le crée si absent
     */
    public function getOrCreateAlias(string $originalKey): string
    {
        if ($originalKey === '') {
            throw new \InvalidArgumentException('Empty original key');
        }

        $db = Db::getInstance();

        // 1️⃣ Check existing
        $alias = $db->getValue(
            'SELECT alias_key
            FROM `' . $this->table . '`
            WHERE shop_id = ' . (int)$this->shopId . '
            AND original_key = "' . pSQL($originalKey) . '"'
        );

        if ($alias) {
            return $alias;
        }

        // 2️⃣ Compute next index
        $nextIndex = (int)$db->getValue(
            'SELECT COUNT(*)
            FROM `' . $this->table . '`
            WHERE shop_id = ' . (int)$this->shopId
        ) + 1;

        $aliasKey = 'a' . $nextIndex;

        // 3️⃣ Insert safely
        $db->execute(
            'INSERT IGNORE INTO `' . $this->table . '`
            (
                shop_id,
                original_key,
                alias_key,
                schema_version,
                created_at
            )
            VALUES
            (
                ' . (int)$this->shopId . ',
                "' . pSQL($originalKey) . '",
                "' . pSQL($aliasKey) . '",
                1,
                NOW()
            )'
        );

        return $aliasKey;
    }

    /**
     * Retourne schema_version courant
     */
    public function getSchemaVersion(): int
    {
        $version = (int) Db::getInstance()->getValue(
            'SELECT MAX(schema_version)
             FROM `' . $this->table . '`
             WHERE shop_id = ' . (int)$this->shopId
        );

        return $version > 0 ? $version : 1;
    }
}

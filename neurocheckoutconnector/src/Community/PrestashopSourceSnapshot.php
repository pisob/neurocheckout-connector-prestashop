<?php

declare(strict_types=1);

namespace NeuroCheckout\Community;

use PDO;
use RuntimeException;

/** Read-only native snapshot for small staging stores. Never invokes cart hooks. */
final class PrestashopSourceSnapshot
{
    private PDO $db;
    private string $prefix;
    private float $started = 0;
    private const TABLES = ['shop', 'product', 'product_shop', 'product_lang', 'product_attribute',
        'product_attribute_shop', 'stock_available', 'category_product', 'cart', 'cart_product', 'customer', 'currency', 'orders'];

    public function __construct(PDO $connection, string $prefix)
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $prefix) || $connection->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new RuntimeException('source_unavailable');
        }
        $this->db = $connection; $this->prefix = $prefix;
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    public static function fromRuntime(): self
    {
        // A separate connection avoids committing/rolling back any framework
        // transaction, and avoids ORM side effects such as recalculating carts.
        $host = (string) _DB_SERVER_; $name = (string) _DB_NAME_;
        if (strpos($host, ';') !== false || !preg_match('/^[A-Za-z0-9_$-]+$/D', $name)) {
            throw new RuntimeException('source_unavailable');
        }
        if (preg_match('/^(.*):([0-9]+)$/D', $host, $parts)) {
            $target = 'host=' . $parts[1] . ';port=' . $parts[2];
        } elseif (preg_match('#^.*:(/.*)$#D', $host, $parts)) {
            $target = 'unix_socket=' . $parts[1];
        } else { $target = 'host=' . $host; }
        return new self(new PDO('mysql:' . $target . ';dbname=' . $name . ';charset=utf8mb4',
            (string) _DB_USER_, (string) _DB_PASSWD_, [PDO::ATTR_TIMEOUT => 2,
                PDO::MYSQL_ATTR_MULTI_STATEMENTS => false]), (string) _DB_PREFIX_);
    }

    public function capture(int $scope): array
    {
        if ($scope < 1 || $this->db->inTransaction()) { throw new RuntimeException('source_unavailable'); }
        $this->started = microtime(true);
        $version = (string) $this->db->getAttribute(PDO::ATTR_SERVER_VERSION);
        $this->db->exec(stripos($version, 'mariadb') !== false
            ? 'SET SESSION max_statement_time=1' : 'SET SESSION MAX_EXECUTION_TIME=1000');
        $names = array_map(function ($table) { return $this->prefix . $table; }, self::TABLES);
        $engines = $this->rows('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('
            . implode(',', array_fill(0, count($names), '?')) . ')', $names, count($names));
        if (count($engines) !== count($names)) { throw new RuntimeException('source_schema_unavailable'); }
        foreach ($engines as $table) {
            if (strcasecmp((string) $table['ENGINE'], 'InnoDB') !== 0) { throw new RuntimeException('source_snapshot_not_transactional'); }
        }
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        try {
            $shop = $this->rows('SELECT id_shop_group, active FROM ' . $this->table('shop') . ' WHERE id_shop=?', [$scope], 1);
            if (count($shop) !== 1 || !(int) $shop[0]['active']) { throw new RuntimeException('source_shop_unavailable'); }
            $products = $this->rows('SELECT p.id_product, p.reference, ps.active, ps.price AS price_tax_excl,
                ps.id_tax_rules_group, ps.id_category_default FROM ' . $this->table('product_shop') . ' ps
                JOIN ' . $this->table('product') . ' p ON p.id_product=ps.id_product WHERE ps.id_shop=? ORDER BY p.id_product', [$scope], 256);
            $carts = $this->rows('SELECT c.id_cart, c.id_customer, c.id_lang, c.id_currency, c.date_add, c.date_upd,
                u.iso_code AS currency, customer.email, customer.firstname, customer.lastname
                FROM ' . $this->table('cart') . ' c
                LEFT JOIN ' . $this->table('currency') . ' u ON u.id_currency=c.id_currency
                LEFT JOIN ' . $this->table('customer') . ' customer ON customer.id_customer=c.id_customer
                WHERE c.id_shop=? ORDER BY c.id_cart', [$scope], 256);
            if (count($products) + count($carts) > 256) { throw new RuntimeException('source_snapshot_capacity'); }
            $result = [];
            foreach ($products as $product) {
                $product['source_schema'] = 'prestashop-native-v1';
                $id = (int) $product['id_product'];
                // A sentinel beyond the payload cap prevents an unbounded TEXT
                // fetch. Any truncation necessarily exceeds record()'s cap and
                // is refused; a truncated description is never exported.
                $product['translations'] = $this->rows('SELECT id_lang, name, link_rewrite, LEFT(description_short,16385) AS description_short FROM '
                    . $this->table('product_lang') . ' WHERE id_product=? AND id_shop=? ORDER BY id_lang', [$id, $scope], 32);
                $product['variants'] = $this->rows('SELECT pa.id_product_attribute, pa.reference, pas.price AS price_impact_tax_excl,
                    pas.default_on FROM ' . $this->table('product_attribute') . ' pa JOIN ' . $this->table('product_attribute_shop')
                    . ' pas ON pas.id_product_attribute=pa.id_product_attribute WHERE pa.id_product=? AND pas.id_shop=? ORDER BY pa.id_product_attribute', [$id, $scope], 128);
                $product['stocks'] = $this->rows('SELECT id_product_attribute, id_shop, id_shop_group, quantity, out_of_stock FROM '
                    . $this->table('stock_available') . ' WHERE id_product=? AND (id_shop=? OR (id_shop=0 AND id_shop_group=?))
                    ORDER BY id_product_attribute, id_shop, id_shop_group', [$id, $scope, (int) $shop[0]['id_shop_group']], 128);
                $product['categories'] = $this->rows('SELECT id_category FROM ' . $this->table('category_product')
                    . ' WHERE id_product=? ORDER BY id_category', [$id], 128);
                $result[] = $this->record('product', $id, $product);
            }
            foreach ($carts as $cart) {
                $cart['source_schema'] = 'prestashop-native-v1';
                $id = (int) $cart['id_cart'];
                $cart['items'] = $this->rows('SELECT id_shop, id_product, id_product_attribute, id_customization, quantity, id_address_delivery FROM '
                    . $this->table('cart_product') . ' WHERE id_cart=? ORDER BY id_product, id_product_attribute, id_customization, id_address_delivery', [$id], 128);
                foreach ($cart['items'] as $line) {
                    if ((int) $line['id_shop'] !== $scope) { throw new RuntimeException('source_scope_inconsistent'); }
                }
                $cart['orders'] = $this->rows('SELECT id_order, id_shop, reference, current_state, total_paid_tax_incl, date_add FROM '
                    . $this->table('orders') . ' WHERE id_cart=? ORDER BY id_order', [$id], 32);
                foreach ($cart['orders'] as $order) {
                    if ((int) $order['id_shop'] !== $scope) { throw new RuntimeException('source_scope_inconsistent'); }
                }
                $cart['status'] = $cart['orders'] ? 'converted' : ($cart['items'] ? 'active' : 'empty');
                $result[] = $this->record('cart', $id, $cart);
            }
            if (microtime(true) - $this->started > 5) { throw new RuntimeException('source_snapshot_timeout'); }
            $this->db->rollBack(); // Read-only snapshot; no native state is changed.
            return $result;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $error;
        }
    }

    private function table(string $name): string { return '`' . $this->prefix . $name . '`'; }

    private function record(string $kind, int $id, array $payload): array
    {
        if (strlen(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > 16384) {
            throw new RuntimeException('source_snapshot_capacity');
        }
        return ['kind' => $kind, 'sourceId' => (string) $id, 'payload' => $payload];
    }

    private function rows(string $sql, array $parameters, int $limit): array
    {
        if (microtime(true) - $this->started > 5) { throw new RuntimeException('source_snapshot_timeout'); }
        $statement = $this->db->prepare($sql . ' LIMIT ' . ($limit + 1));
        $statement->execute($parameters); $rows = $statement->fetchAll();
        if (count($rows) > $limit) { throw new RuntimeException('source_snapshot_capacity'); }
        return $rows;
    }
}

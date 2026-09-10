<?php

use NeuroCheckout\Event\OrderEventBuilder;
use NeuroCheckout\Http\RequestBodyDecoder;
use NeuroCheckout\Security\RequestSecurityValidator;

class NeuroCheckoutConnectorOrderhistoryModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $auth = false;
    public $ajax = true;

    private const DEFAULT_LOOKBACK_DAYS = 180;
    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT = 250;
    private const RESPONSE_GZIP_MIN_BYTES = 1024;
    private const MAX_REQUEST_BODY_BYTES = 2097152;
    private const MAX_REQUEST_DECOMPRESSED_BYTES = 12582912;
    private const MODE_FULL = 'full';
    private const MODE_SLIM = 'slim';
    private const DEFAULT_MODE = self::MODE_FULL;
    private const ORDERS_CURSOR_INDEX_NAME = 'idx_nc_orders_shop_date_order';

    public function initContent()
    {
        parent::initContent();
        header('Content-Type: application/json');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            $this->jsonExit(405, false, 'Method not allowed');
        }

        try {
            $rawRequest = RequestBodyDecoder::readRaw(self::MAX_REQUEST_BODY_BYTES);
            if (empty($rawRequest['success'])) {
                $this->jsonExit(
                    (int) ($rawRequest['status'] ?? 400),
                    false,
                    (string) ($rawRequest['error'] ?? 'Unable to read request body')
                );
            }
            $rawBody = (string) ($rawRequest['body'] ?? '');
            $security = (new RequestSecurityValidator())->validateSignedPost($rawBody, 'orderhistory', true);
            if (empty($security['success'])) {
                $this->jsonExit(
                    (int) ($security['status'] ?? 403),
                    false,
                    (string) ($security['error'] ?? 'Forbidden')
                );
            }

            $decoded = $this->decodePayload($rawBody);
            if (empty($decoded['success'])) {
                $this->jsonExit(
                    (int) ($decoded['status'] ?? 422),
                    false,
                    (string) ($decoded['error'] ?? 'Invalid JSON payload')
                );
            }

            $payload = is_array($decoded['payload'] ?? null) ? $decoded['payload'] : [];

            if (!$this->isRequestedShopMatching($payload)) {
                $this->jsonExit(409, false, 'shop_id mismatch');
            }

            $shopId = $this->resolveShopId();
            if ($shopId <= 0) {
                $this->jsonExit(500, false, 'Unable to resolve shop context');
            }

            $window = $this->resolveWindow($payload);
            $limit = $this->resolveLimit($payload);
            $mode = $this->resolveRequestedMode($payload);
            $cursorRaw = trim((string) ($payload['cursor'] ?? ''));
            $cursor = null;
            if ($cursorRaw !== '') {
                $cursor = $this->decodeCursor($cursorRaw);
                if ($cursor === null) {
                    $this->jsonExit(422, false, 'Invalid cursor');
                }
            }

            $this->ensureOrderHistoryIndexes();
            $rows = $this->fetchOrderRows(
                $shopId,
                $window['since_sql'],
                $window['until_sql'],
                $limit + 1,
                $cursor
            );

            $hasMore = count($rows) > $limit;
            if ($hasMore) {
                $rows = array_slice($rows, 0, $limit);
            }

            $orders = ($mode === self::MODE_SLIM)
                ? $this->buildOrderHistoryPayloadSlim($rows, $shopId)
                : $this->buildOrderHistoryPayload($rows);
            $nextCursor = null;
            if ($hasMore && !empty($rows)) {
                $lastRow = end($rows);
                if (is_array($lastRow)) {
                    $nextCursor = $this->encodeCursor(
                        (string) ($lastRow['date_add'] ?? ''),
                        (int) ($lastRow['id_order'] ?? 0)
                    );
                }
            }

            $this->jsonExit(200, true, null, [
                'orders' => $orders,
                'next_cursor' => $nextCursor,
                'has_more' => $hasMore,
                'count' => count($orders),
                'limit' => $limit,
                'mode' => $mode,
                'window' => [
                    'since' => $window['since_iso'],
                    'until' => $window['until_iso'],
                ],
            ]);
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('[NC] Order history endpoint fatal: ' . $e->getMessage(), 3);
            $this->jsonExit(500, false, 'Internal error');
        }
    }

    private function decodePayload(string $rawBody): array
    {
        return RequestBodyDecoder::decodeJson(
            $rawBody,
            $this->headerValue('Content-Encoding'),
            self::MAX_REQUEST_DECOMPRESSED_BYTES
        );
    }

    private function isRequestedShopMatching(array $payload): bool
    {
        $requestedShopId = trim((string) ($payload['shop_id'] ?? ''));
        if ($requestedShopId === '') {
            return true;
        }

        $configuredShopExternalId = trim((string) Configuration::get('NC_SHOP_EXTERNAL_ID'));
        if ($configuredShopExternalId === '') {
            return true;
        }

        return hash_equals($configuredShopExternalId, $requestedShopId);
    }

    private function resolveShopId(): int
    {
        $context = Context::getContext();
        if ($context && isset($context->shop) && $context->shop && (int) ($context->shop->id ?? 0) > 0) {
            return (int) $context->shop->id;
        }

        return max(1, (int) Configuration::get('PS_SHOP_DEFAULT'));
    }

    private function resolveWindow(array $payload): array
    {
        $untilTs = $this->parseTimestamp($payload['until'] ?? null);
        if ($untilTs === null) {
            $untilTs = time();
        }

        $sinceTs = $this->parseTimestamp($payload['since'] ?? null);
        if ($sinceTs === null) {
            $sinceTs = $untilTs - (self::DEFAULT_LOOKBACK_DAYS * 86400);
        }

        if ($sinceTs > $untilTs) {
            $swap = $sinceTs;
            $sinceTs = $untilTs;
            $untilTs = $swap;
        }

        $sinceSql = date('Y-m-d H:i:s', $sinceTs);
        $untilSql = date('Y-m-d H:i:s', $untilTs);

        return [
            'since_sql' => $sinceSql,
            'until_sql' => $untilSql,
            'since_iso' => gmdate('c', $sinceTs),
            'until_iso' => gmdate('c', $untilTs),
        ];
    }

    private function parseTimestamp($value): ?int
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }

        $ts = strtotime($raw);
        if ($ts === false) {
            return null;
        }

        return (int) $ts;
    }

    private function resolveLimit(array $payload): int
    {
        $limit = (int) ($payload['limit'] ?? self::DEFAULT_LIMIT);
        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }

        return min(self::MAX_LIMIT, max(1, $limit));
    }

    private function resolveRequestedMode(array $payload): string
    {
        $requested = strtolower(trim((string) ($payload['mode'] ?? self::DEFAULT_MODE)));
        if ($requested === self::MODE_SLIM) {
            return self::MODE_SLIM;
        }

        return self::MODE_FULL;
    }

    private function ensureOrderHistoryIndexes(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }
        $ensured = true;

        try {
            $db = Db::getInstance();
            $ordersTable = _DB_PREFIX_ . 'orders';
            $indexRows = $db->executeS(
                "SHOW INDEX FROM `" . bqSQL($ordersTable) . "` WHERE Key_name = '" . pSQL(self::ORDERS_CURSOR_INDEX_NAME) . "'"
            );
            $indexExists = is_array($indexRows) && !empty($indexRows);
            if ($indexExists) {
                return;
            }

            $created = $db->execute(
                "ALTER TABLE `" . bqSQL($ordersTable) . "` ADD INDEX `" . self::ORDERS_CURSOR_INDEX_NAME . "` (`id_shop`, `date_add`, `id_order`)"
            );
            if (!$created) {
                throw new \RuntimeException(
                    'ALTER TABLE failed: ' . (string) $db->getMsgError()
                );
            }
            PrestaShopLogger::addLog('[NC] Added order history index: ' . self::ORDERS_CURSOR_INDEX_NAME, 1);
        } catch (\Throwable $e) {
            $message = strtolower((string) $e->getMessage());
            if (strpos($message, 'duplicate key name') !== false || strpos($message, 'already exists') !== false) {
                return;
            }
            PrestaShopLogger::addLog('[NC] Unable to ensure order history index: ' . $e->getMessage(), 2);
        }
    }

    private function decodeCursor(string $cursor): ?array
    {
        if ($cursor === '') {
            return null;
        }

        $decoded = $this->decodeCursorJson($cursor);
        if (!is_array($decoded)) {
            return null;
        }

        $createdAt = trim((string) ($decoded['created_at'] ?? ''));
        $orderId = (int) ($decoded['order_id'] ?? 0);
        if ($createdAt === '' || $orderId <= 0) {
            return null;
        }

        $createdAtTs = strtotime($createdAt);
        if ($createdAtTs === false) {
            return null;
        }

        return [
            'created_at' => date('Y-m-d H:i:s', $createdAtTs),
            'order_id' => $orderId,
        ];
    }

    private function decodeCursorJson(string $cursor)
    {
        $raw = trim($cursor);
        if ($raw === '') {
            return null;
        }

        $direct = json_decode($raw, true);
        if (is_array($direct)) {
            return $direct;
        }

        $base64 = strtr($raw, '-_', '+/');
        $padding = strlen($base64) % 4;
        if ($padding > 0) {
            $base64 .= str_repeat('=', 4 - $padding);
        }

        $decodedString = base64_decode($base64, true);
        if ($decodedString === false || $decodedString === '') {
            return null;
        }

        $decoded = json_decode($decodedString, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function encodeCursor(string $createdAt, int $orderId): ?string
    {
        $createdAt = trim($createdAt);
        if ($createdAt === '' || $orderId <= 0) {
            return null;
        }

        $cursorPayload = json_encode([
            'created_at' => $createdAt,
            'order_id' => $orderId,
        ]);
        if (!is_string($cursorPayload) || $cursorPayload === '') {
            return null;
        }

        return rtrim(strtr(base64_encode($cursorPayload), '+/', '-_'), '=');
    }

    private function fetchOrderRows(
        int $shopId,
        string $sinceSql,
        string $untilSql,
        int $limit,
        ?array $cursor
    ): array {
        $db = Db::getInstance();
        $query = new DbQuery();
        $query->select('o.id_order, o.date_add');
        $query->from('orders', 'o');
        $query->where('o.id_shop = ' . (int) $shopId);
        $query->where("o.date_add >= '" . pSQL($sinceSql) . "'");
        $query->where("o.date_add <= '" . pSQL($untilSql) . "'");
        $query->where('o.valid = 1');

        if (is_array($cursor)) {
            $cursorDate = pSQL((string) ($cursor['created_at'] ?? ''));
            $cursorOrderId = (int) ($cursor['order_id'] ?? 0);
            if ($cursorDate !== '' && $cursorOrderId > 0) {
                $query->where(
                    "(o.date_add > '" . $cursorDate . "' OR (o.date_add = '" . $cursorDate . "' AND o.id_order > " . $cursorOrderId . '))'
                );
            }
        }

        $query->orderBy('o.date_add ASC, o.id_order ASC');
        $query->limit(max(1, (int) $limit));

        $rows = $db->executeS($query);
        return is_array($rows) ? $rows : [];
    }

    private function buildOrderHistoryPayload(array $rows): array
    {
        $orders = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $orderId = (int) ($row['id_order'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }

            try {
                $order = new Order($orderId);
                if (!(int) $order->id) {
                    continue;
                }

                $eventPayload = OrderEventBuilder::buildFromValidateOrderParams([
                    'order' => $order,
                ]);
                if (!is_array($eventPayload)) {
                    continue;
                }

                $historyOrder = $this->normalizeHistoryOrderPayload($eventPayload, $orderId);
                if (is_array($historyOrder)) {
                    $orders[] = $historyOrder;
                }
            } catch (\Throwable $e) {
                PrestaShopLogger::addLog(
                    '[NC] Order history row skipped (' . $orderId . '): ' . $e->getMessage(),
                    2
                );
            }
        }

        return $orders;
    }

    private function buildOrderHistoryPayloadSlim(array $rows, int $shopId): array
    {
        $orderIds = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $orderId = (int) ($row['id_order'] ?? 0);
            if ($orderId > 0) {
                $orderIds[] = $orderId;
            }
        }

        if (empty($orderIds)) {
            return [];
        }

        $coreByOrderId = $this->fetchSlimOrdersByIds($orderIds, $shopId);
        $itemsByOrderId = $this->fetchSlimOrderItemsByOrderIds($orderIds, $shopId);
        $sourceShopId = $this->resolveSourceShopId($shopId);
        $orders = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $orderId = (int) ($row['id_order'] ?? 0);
            if ($orderId <= 0 || empty($coreByOrderId[$orderId])) {
                continue;
            }

            $core = $coreByOrderId[$orderId];
            $customerEmail = trim((string) ($core['customer_email'] ?? ''));
            if ($customerEmail === '') {
                continue;
            }

            $dateAdd = trim((string) ($core['date_add'] ?? ''));
            $occurredAt = '';
            if ($dateAdd !== '') {
                $ts = strtotime($dateAdd);
                if ($ts !== false) {
                    $occurredAt = gmdate('c', $ts);
                }
            }
            if ($occurredAt === '') {
                $occurredAt = gmdate('c');
            }

            $totalTaxIncl = (float) ($core['total_paid_tax_incl'] ?? 0);
            $totalPaid = (float) ($core['total_paid'] ?? 0);
            $orderTotal = $totalTaxIncl > 0 ? $totalTaxIncl : $totalPaid;

            $customerId = (int) ($core['id_customer'] ?? 0);
            $customerFirstName = trim((string) ($core['customer_first_name'] ?? ''));
            $customerLastName = trim((string) ($core['customer_last_name'] ?? ''));
            $status = trim((string) ($core['current_state'] ?? ''));
            if ($status === '') {
                $status = 'completed';
            }

            $orders[] = [
                'order_id' => (string) $orderId,
                'cart_id' => trim((string) ($core['id_cart'] ?? '')),
                'occurred_at' => $occurredAt,
                'order_total' => round($orderTotal, 2),
                'currency' => trim((string) ($core['currency_code'] ?? '')),
                'customer_email' => $customerEmail,
                'customer_id' => $customerId > 0 ? (string) $customerId : '',
                'customer' => [
                    'id' => $customerId > 0 ? (string) $customerId : null,
                    'email' => $customerEmail,
                    'first_name' => $customerFirstName !== '' ? $customerFirstName : null,
                    'last_name' => $customerLastName !== '' ? $customerLastName : null,
                ],
                'status' => $status,
                'valid' => true,
                'is_valid_purchase' => true,
                'items' => $itemsByOrderId[$orderId] ?? [],
                'source' => [
                    'platform' => 'prestashop',
                    'shop_id' => $sourceShopId,
                ],
                'context' => [],
            ];
        }

        return $orders;
    }

    private function fetchSlimOrdersByIds(array $orderIds, int $shopId): array
    {
        $normalizedIds = [];
        foreach ($orderIds as $orderId) {
            $candidate = (int) $orderId;
            if ($candidate > 0) {
                $normalizedIds[] = $candidate;
            }
        }
        $normalizedIds = array_values(array_unique($normalizedIds));
        if (empty($normalizedIds)) {
            return [];
        }

        $idList = implode(',', array_map('intval', $normalizedIds));
        $query = '
            SELECT
                o.id_order,
                o.id_cart,
                o.id_customer,
                o.date_add,
                o.total_paid_tax_incl,
                o.total_paid,
                o.current_state,
                cu.iso_code AS currency_code,
                c.email AS customer_email,
                c.firstname AS customer_first_name,
                c.lastname AS customer_last_name
            FROM `' . _DB_PREFIX_ . 'orders` o
            LEFT JOIN `' . _DB_PREFIX_ . 'currency` cu
                ON cu.id_currency = o.id_currency
            LEFT JOIN `' . _DB_PREFIX_ . 'customer` c
                ON c.id_customer = o.id_customer
            WHERE o.id_shop = ' . (int) $shopId . '
              AND o.id_order IN (' . $idList . ')';

        $rows = Db::getInstance()->executeS($query);
        if (!is_array($rows)) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $idOrder = (int) ($row['id_order'] ?? 0);
            if ($idOrder > 0) {
                $result[$idOrder] = $row;
            }
        }

        return $result;
    }

    private function fetchSlimOrderItemsByOrderIds(array $orderIds, int $shopId): array
    {
        $normalizedIds = [];
        foreach ($orderIds as $orderId) {
            $candidate = (int) $orderId;
            if ($candidate > 0) {
                $normalizedIds[] = $candidate;
            }
        }
        $normalizedIds = array_values(array_unique($normalizedIds));
        if (empty($normalizedIds)) {
            return [];
        }

        $context = Context::getContext();
        $languageId = 0;
        if (
            $context
            && isset($context->language)
            && $context->language
            && (int) ($context->language->id ?? 0) > 0
        ) {
            $languageId = (int) $context->language->id;
        }
        if ($languageId <= 0) {
            $languageId = (int) Configuration::get('PS_LANG_DEFAULT');
        }
        if ($languageId <= 0) {
            $languageId = 1;
        }

        $idList = implode(',', array_map('intval', $normalizedIds));
        $query = '
            SELECT
                od.id_order,
                od.product_id,
                od.product_attribute_id,
                od.product_name,
                od.product_reference,
                od.product_quantity,
                od.unit_price_tax_incl,
                od.total_price_tax_incl,
                m.name AS brand_name,
                (
                    SELECT GROUP_CONCAT(cl_path.name ORDER BY c_path.level_depth ASC SEPARATOR \' > \')
                    FROM `' . _DB_PREFIX_ . 'category` c_default
                    INNER JOIN `' . _DB_PREFIX_ . 'category` c_path
                        ON c_path.nleft <= c_default.nleft
                       AND c_path.nright >= c_default.nright
                    INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl_path
                        ON cl_path.id_category = c_path.id_category
                       AND cl_path.id_lang = ' . (int) $languageId . '
                       AND cl_path.id_shop = ' . (int) $shopId . '
                    WHERE c_default.id_category = p.id_category_default
                ) AS category_path,
                pl.link_rewrite,
                i.id_image AS cover_image_id
            FROM `' . _DB_PREFIX_ . 'order_detail` od
            LEFT JOIN `' . _DB_PREFIX_ . 'product` p
                ON p.id_product = od.product_id
            LEFT JOIN `' . _DB_PREFIX_ . 'manufacturer` m
                ON m.id_manufacturer = p.id_manufacturer
            LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                ON pl.id_product = od.product_id
               AND pl.id_lang = ' . (int) $languageId . '
               AND pl.id_shop = ' . (int) $shopId . '
            LEFT JOIN `' . _DB_PREFIX_ . 'image` i
                ON i.id_product = od.product_id
               AND i.cover = 1
            WHERE od.id_order IN (' . $idList . ')
            ORDER BY od.id_order ASC, od.id_order_detail ASC';

        $rows = Db::getInstance()->executeS($query);
        if (!is_array($rows)) {
            return [];
        }

        $itemsByOrderId = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $idOrder = (int) ($row['id_order'] ?? 0);
            if ($idOrder <= 0) {
                continue;
            }

            if (!isset($itemsByOrderId[$idOrder])) {
                $itemsByOrderId[$idOrder] = [];
            }

            $productId = (int) ($row['product_id'] ?? 0);
            $attributeId = (int) ($row['product_attribute_id'] ?? 0);
            $quantity = max(0, (int) ($row['product_quantity'] ?? 0));
            $unitPrice = (float) ($row['unit_price_tax_incl'] ?? 0);
            $lineTotal = (float) ($row['total_price_tax_incl'] ?? 0);
            $linkRewrite = trim((string) ($row['link_rewrite'] ?? ''));
            $brandName = trim((string) ($row['brand_name'] ?? ''));
            $categoryPath = trim((string) ($row['category_path'] ?? ''));
            $productUrl = $this->resolveSlimProductUrl($productId, $linkRewrite);
            $imageUrl = $this->resolveSlimProductImageUrl(
                $productId,
                $linkRewrite,
                (int) ($row['cover_image_id'] ?? 0)
            );

            $itemsByOrderId[$idOrder][] = [
                'product_id' => $productId > 0 ? (string) $productId : null,
                'attribute_id' => $attributeId > 0 ? (string) $attributeId : '0',
                'name' => trim((string) ($row['product_name'] ?? '')) ?: (
                    $productId > 0 ? 'product_' . $productId : 'product'
                ),
                'sku' => trim((string) ($row['product_reference'] ?? '')) ?: null,
                'category_path' => $categoryPath !== '' ? $categoryPath : null,
                'brand_name' => $brandName !== '' ? $brandName : null,
                'product_url' => $productUrl,
                'image_url' => $imageUrl,
                'quantity' => $quantity,
                'unit_price' => round($unitPrice, 2),
                'line_total' => round($lineTotal, 2),
            ];
        }

        return $itemsByOrderId;
    }

    private function resolveSlimProductUrl(int $productId, string $linkRewrite = ''): ?string
    {
        if ($productId <= 0) {
            return null;
        }

        $link = $this->resolveContextLink();
        if ($link === null) {
            return null;
        }

        try {
            $candidate = $link->getProductLink(
                $productId,
                $linkRewrite !== '' ? $linkRewrite : null
            );
        } catch (\Throwable $e) {
            return null;
        }

        return $this->sanitizeUrlCandidate($candidate);
    }

    private function resolveSlimProductImageUrl(
        int $productId,
        string $linkRewrite = '',
        int $coverImageId = 0
    ): ?string {
        if ($productId <= 0 || $coverImageId <= 0) {
            return null;
        }

        $link = $this->resolveContextLink();
        if ($link === null) {
            return null;
        }

        $imageName = $linkRewrite !== '' ? $linkRewrite : 'product';
        $imageIdCandidates = [
            $productId . '-' . $coverImageId,
            (string) $coverImageId,
        ];
        $imageTypeCandidates = ['home_default', null];

        foreach ($imageIdCandidates as $imageId) {
            foreach ($imageTypeCandidates as $imageType) {
                try {
                    $candidate = $link->getImageLink($imageName, $imageId, $imageType);
                } catch (\Throwable $e) {
                    $candidate = null;
                }
                $normalized = $this->sanitizeUrlCandidate($candidate);
                if ($normalized !== null) {
                    return $normalized;
                }
            }
        }

        return null;
    }

    private function resolveContextLink()
    {
        $context = Context::getContext();
        if (!$context || !isset($context->link) || !$context->link instanceof Link) {
            return null;
        }

        return $context->link;
    }

    private function sanitizeUrlCandidate($value): ?string
    {
        $candidate = trim((string) ($value ?? ''));
        if ($candidate === '' || strtolower($candidate) === 'null') {
            return null;
        }

        return $candidate;
    }

    private function resolveSourceShopId(int $shopId): string
    {
        $configured = trim((string) Configuration::get('NC_SHOP_EXTERNAL_ID'));
        if ($configured !== '') {
            return $configured;
        }

        return (string) max(1, $shopId);
    }

    private function normalizeHistoryOrderPayload(array $eventPayload, int $fallbackOrderId): ?array
    {
        $orderBlock = is_array($eventPayload['order'] ?? null) ? $eventPayload['order'] : [];
        $customerBlock = is_array($eventPayload['customer'] ?? null) ? $eventPayload['customer'] : [];
        $items = is_array($orderBlock['items'] ?? null) ? $orderBlock['items'] : [];

        $orderId = trim((string) ($eventPayload['order_id'] ?? ''));
        if ($orderId === '') {
            $orderId = (string) $fallbackOrderId;
        }

        $customerEmail = trim((string) ($eventPayload['customer_email'] ?? ''));
        if ($customerEmail === '') {
            $customerEmail = trim((string) ($customerBlock['email'] ?? ''));
        }
        if ($customerEmail === '') {
            return null;
        }

        return [
            'order_id' => $orderId,
            'cart_id' => trim((string) ($eventPayload['cart_id'] ?? '')),
            'occurred_at' => trim((string) ($eventPayload['occurred_at'] ?? '')),
            'order_total' => (float) ($eventPayload['order_total'] ?? 0),
            'currency' => trim((string) ($eventPayload['currency'] ?? '')),
            'customer_email' => $customerEmail,
            'customer_id' => trim((string) ($customerBlock['id'] ?? '')),
            'customer' => $customerBlock,
            'status' => trim((string) ($orderBlock['status'] ?? '')),
            'valid' => true,
            'is_valid_purchase' => true,
            'items' => $items,
            'source' => is_array($eventPayload['source'] ?? null) ? $eventPayload['source'] : [],
            'context' => is_array($eventPayload['context'] ?? null) ? $eventPayload['context'] : [],
        ];
    }

    private function headerValue(string $headerName): string
    {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));
        return (string) ($_SERVER[$serverKey] ?? '');
    }

    private function shouldCompressResponse(string $jsonPayload): bool
    {
        if (!function_exists('gzencode')) {
            return false;
        }
        if (strlen($jsonPayload) < self::RESPONSE_GZIP_MIN_BYTES) {
            return false;
        }
        $acceptEncoding = strtolower($this->headerValue('Accept-Encoding'));
        return strpos($acceptEncoding, 'gzip') !== false;
    }

    private function jsonExit(int $statusCode, bool $success, ?string $error = null, ?array $data = null): void
    {
        http_response_code($statusCode);

        $payload = [
            'success' => $success,
            'status' => $statusCode,
            'error' => $error,
            'timestamp' => date('Y-m-d H:i:s'),
        ];
        if (is_array($data)) {
            $payload['data'] = $data;
        }

        $jsonPayload = json_encode($payload);
        if (!is_string($jsonPayload)) {
            $jsonPayload = '{"success":false,"status":500,"error":"json_encode_failed"}';
        }

        if ($this->shouldCompressResponse($jsonPayload)) {
            $encoded = gzencode($jsonPayload, 5);
            if (is_string($encoded)) {
                header('Content-Encoding: gzip');
                header('Vary: Accept-Encoding');
                echo $encoded;
                exit;
            }
        }

        echo $jsonPayload;
        exit;
    }
}

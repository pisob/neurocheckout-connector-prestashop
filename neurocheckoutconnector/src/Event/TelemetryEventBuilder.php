<?php

namespace NeuroCheckout\Event;

use Cart;
use Configuration;
use Context;
use Order;
use OrderState;
use Tools;
use NeuroCheckout\Util\Uuid;

class TelemetryEventBuilder
{
    private const MAX_TEXT_LENGTH = 300;

    private const SUPPORTED_EVENT_TYPES = [
        'prestashop.checkout.performance',
        'prestashop.checkout.js_error',
        'prestashop.checkout.request_anomaly',
        'prestashop.checkout.friction_snapshot',
        'prestashop.checkout.shipping_cost_snapshot',
        'prestashop.payment.failed',
        'prestashop.connector.runtime_error',
    ];

    public static function buildFromBrowserPayload(array $payload, ?Context $context, string $moduleVersion): ?array
    {
        $eventType = self::safeText($payload['event_type'] ?? '', 120);
        if (!in_array($eventType, self::SUPPORTED_EVENT_TYPES, true)) {
            return null;
        }

        $cart = self::resolveContextCart($context);
        $shopId = self::resolveContextShopId($context, $cart);
        $occurredAt = self::normalizeOccurredAt($payload['occurred_at'] ?? null);
        $metrics = self::sanitizeMap($payload['metrics'] ?? [], 30);
        $browserContext = self::sanitizeMap($payload['context'] ?? [], 30);

        $browserContext['origin'] = 'browser';
        $browserContext['collector'] = 'prestashop_checkout_telemetry';
        $browserContext['module_version'] = $moduleVersion;

        if ($cart && $cart->id) {
            $browserContext['cart_id'] = (string) (int) $cart->id;
        }

        return [
            'event_id' => self::normalizeEventId($payload['event_id'] ?? null),
            'event_type' => $eventType,
            'occurred_at' => $occurredAt,
            'source' => self::buildSource($context, $shopId),
            'cart' => self::buildCartPayload($cart),
            'metrics' => $metrics,
            'context' => $browserContext,
            'privacy' => self::buildPrivacyPayload(),
        ];
    }

    public static function buildShippingCostSnapshot(Cart $cart, ?Context $context, string $moduleVersion): ?array
    {
        if (!$cart->id) {
            return null;
        }

        $productsTotal = self::safeOrderTotal($cart, 'products');
        $shippingTotal = self::safeOrderTotal($cart, 'shipping');
        $cartTotal = self::safeOrderTotal($cart, 'total');
        $shippingRatio = $productsTotal > 0 ? $shippingTotal / $productsTotal : 0.0;
        $shopId = self::resolveContextShopId($context, $cart);

        return [
            'event_id' => self::deterministicEventId(
                'prestashop.checkout.shipping_cost_snapshot',
                (string) $shopId,
                (string) (int) $cart->id,
                (string) floor(time() / 300),
                (string) round($shippingTotal, 2)
            ),
            'event_type' => 'prestashop.checkout.shipping_cost_snapshot',
            'occurred_at' => gmdate('c'),
            'source' => self::buildSource($context, $shopId),
            'cart' => self::buildCartPayload($cart, $productsTotal + $shippingTotal),
            'metrics' => [
                'cart_products_total' => round($productsTotal, 2),
                'shipping_total' => round($shippingTotal, 2),
                'shipping_ratio' => round($shippingRatio, 4),
                'cart_total' => round($cartTotal, 2),
            ],
            'context' => [
                'origin' => 'server',
                'collector' => 'prestashop_shipping_snapshot',
                'module_version' => $moduleVersion,
                'cart_id' => (string) (int) $cart->id,
                'has_delivery_address' => (int) ($cart->id_address_delivery ?? 0) > 0,
            ],
            'privacy' => self::buildPrivacyPayload(),
        ];
    }

    public static function buildPaymentFailed(Order $order, ?OrderState $state, ?Context $context, string $moduleVersion): ?array
    {
        if (!$order->id) {
            return null;
        }

        $shopId = self::resolveContextShopId($context, null, isset($order->id_shop) ? (int) $order->id_shop : 0);
        $orderId = (string) (int) $order->id;
        $cartId = isset($order->id_cart) ? (string) (int) $order->id_cart : '';
        $stateId = $state && $state->id ? (int) $state->id : (int) ($order->current_state ?? 0);

        return [
            'event_id' => self::deterministicEventId(
                'prestashop.payment.failed',
                (string) $shopId,
                $orderId,
                (string) $stateId
            ),
            'event_type' => 'prestashop.payment.failed',
            'occurred_at' => gmdate('c'),
            'source' => self::buildSource($context, $shopId),
            'cart' => [
                'id' => $cartId !== '' ? $cartId : null,
            ],
            'metrics' => [
                'order_total' => round((float) ($order->total_paid_tax_incl ?? $order->total_paid ?? 0), 2),
            ],
            'context' => [
                'origin' => 'server',
                'collector' => 'prestashop_order_status',
                'module_version' => $moduleVersion,
                'order_id' => $orderId,
                'cart_id' => $cartId !== '' ? $cartId : null,
                'order_state_id' => $stateId > 0 ? $stateId : null,
                'order_state_name' => self::safeText($state ? ($state->name ?? '') : '', 120),
                'payment_module' => self::safeText($order->module ?? '', 120),
            ],
            'privacy' => self::buildPrivacyPayload(),
        ];
    }

    public static function buildConnectorRuntimeError(
        string $message,
        ?Context $context,
        string $moduleVersion,
        array $meta = []
    ): array {
        $shopId = self::resolveContextShopId($context);

        return [
            'event_id' => Uuid::v4(),
            'event_type' => 'prestashop.connector.runtime_error',
            'occurred_at' => gmdate('c'),
            'source' => self::buildSource($context, $shopId),
            'cart' => self::buildCartPayload(self::resolveContextCart($context)),
            'metrics' => [],
            'context' => [
                'origin' => 'server',
                'collector' => 'prestashop_connector_runtime',
                'module_version' => $moduleVersion,
                'error_message' => self::scrubText($message, self::MAX_TEXT_LENGTH),
            ] + self::sanitizeMap($meta, 12),
            'privacy' => self::buildPrivacyPayload(),
        ];
    }

    public static function isSupportedEventType(string $eventType): bool
    {
        return in_array($eventType, self::SUPPORTED_EVENT_TYPES, true);
    }

    private static function normalizeEventId($value): string
    {
        $eventId = trim((string) $value);
        if (preg_match('/^[a-f0-9-]{32,80}$/i', $eventId)) {
            return substr($eventId, 0, 80);
        }

        return Uuid::v4();
    }

    private static function deterministicEventId(string ...$parts): string
    {
        $hash = hash('sha256', implode('|', $parts));

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 4),
            substr($hash, 16, 4),
            substr($hash, 20, 12)
        );
    }

    private static function normalizeOccurredAt($value): string
    {
        $raw = trim((string) $value);
        if ($raw !== '') {
            $timestamp = strtotime($raw);
            if ($timestamp !== false && abs(time() - $timestamp) < 86400) {
                return gmdate('c', $timestamp);
            }
        }

        return gmdate('c');
    }

    private static function buildSource(?Context $context, int $shopId): array
    {
        $externalShopId = trim((string) Configuration::get('NC_SHOP_EXTERNAL_ID'));
        if ($externalShopId === '') {
            $externalShopId = $shopId > 0 ? (string) $shopId : 'prestashop_local';
        }

        $shopName = null;
        if ($context && isset($context->shop) && $context->shop && !empty($context->shop->name)) {
            $shopName = self::safeText($context->shop->name, 120);
        }

        return [
            'platform' => 'prestashop',
            'shop_id' => $externalShopId,
            'shop_name' => $shopName,
            'language' => self::resolveLanguage($context),
        ];
    }

    private static function buildCartPayload(?Cart $cart, ?float $totalOverride = null): array
    {
        if (!$cart || !$cart->id) {
            return [
                'id' => null,
                'uid' => null,
                'total' => null,
            ];
        }

        $total = $totalOverride;
        if ($total === null) {
            $total = self::safeOrderTotal($cart, 'total');
        }

        return [
            'id' => (string) (int) $cart->id,
            'uid' => 'ps_' . (int) $cart->id,
            'total' => round((float) $total, 2),
        ];
    }

    private static function resolveContextCart(?Context $context): ?Cart
    {
        if ($context && isset($context->cart) && $context->cart instanceof Cart && $context->cart->id) {
            return $context->cart;
        }

        $cartId = (int) Tools::getValue('id_cart');
        if ($cartId > 0) {
            try {
                $cart = new Cart($cartId);
                return $cart->id ? $cart : null;
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }

    private static function resolveContextShopId(?Context $context, ?Cart $cart = null, int $fallbackShopId = 0): int
    {
        if ($cart && isset($cart->id_shop) && (int) $cart->id_shop > 0) {
            return (int) $cart->id_shop;
        }
        if ($fallbackShopId > 0) {
            return $fallbackShopId;
        }
        if ($context && isset($context->shop) && $context->shop && (int) $context->shop->id > 0) {
            return (int) $context->shop->id;
        }

        return (int) Configuration::get('PS_SHOP_DEFAULT');
    }

    private static function safeOrderTotal(Cart $cart, string $scope): float
    {
        try {
            if ($scope === 'products') {
                return max(0.0, (float) $cart->getOrderTotal(true, Cart::ONLY_PRODUCTS));
            }
            if ($scope === 'shipping') {
                return max(0.0, (float) $cart->getOrderTotal(true, Cart::ONLY_SHIPPING));
            }

            return max(0.0, (float) $cart->getOrderTotal(true, Cart::BOTH));
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    private static function resolveLanguage(?Context $context): string
    {
        if ($context && isset($context->language) && $context->language && !empty($context->language->iso_code)) {
            return strtolower(substr((string) $context->language->iso_code, 0, 8));
        }

        return 'unknown';
    }

    private static function sanitizeMap($value, int $maxItems): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        $index = 0;
        foreach ($value as $key => $item) {
            if ($index >= $maxItems) {
                $result['_truncated'] = true;
                break;
            }

            $normalizedKey = preg_replace('/[^a-zA-Z0-9_.-]/', '_', (string) $key);
            $normalizedKey = substr((string) $normalizedKey, 0, 80);
            if ($normalizedKey === '') {
                continue;
            }

            $result[$normalizedKey] = self::sanitizeScalar($item);
            $index++;
        }

        return $result;
    }

    private static function sanitizeScalar($value)
    {
        if (is_bool($value) || $value === null) {
            return $value;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return is_finite($value) ? round($value, 4) : 0.0;
        }
        if (is_numeric($value)) {
            $numeric = (float) $value;
            return floor($numeric) === $numeric ? (int) $numeric : round($numeric, 4);
        }
        if (is_array($value)) {
            return self::sanitizeMap($value, 12);
        }

        return self::scrubText((string) $value, self::MAX_TEXT_LENGTH);
    }

    private static function safeText($value, int $maxLength): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        return self::scrubText($text, $maxLength);
    }

    private static function scrubText(string $text, int $maxLength): string
    {
        $text = preg_replace('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/', '[email]', $text);
        $text = preg_replace('/https?:\/\/[^\s"\']+/i', '[url]', (string) $text);
        $text = preg_replace('/\b(?:sk|pk|rk|whsec|secret|token|api[_-]?key)[_-]?[A-Za-z0-9]{12,}\b/i', '[secret]', (string) $text);
        $text = trim((string) $text);

        return substr($text, 0, $maxLength);
    }

    private static function buildPrivacyPayload(): array
    {
        return [
            'contains_raw_server_logs' => false,
            'contains_payment_provider_logs' => false,
            'contains_form_values' => false,
            'contains_personal_data' => false,
        ];
    }
}

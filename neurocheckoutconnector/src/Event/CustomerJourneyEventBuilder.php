<?php

namespace NeuroCheckout\Event;

use Cart;
use Configuration;
use Context;
use Currency;
use Order;
use Tools;
use NeuroCheckout\Util\Uuid;

class CustomerJourneyEventBuilder
{
    private const MAX_TEXT_LENGTH = 300;
    private const MAX_CART_ITEMS = 20;

    private const SUPPORTED_EVENT_TYPES = [
        'prestashop.customer_journey.page_view',
        'prestashop.customer_journey.product_view',
        'prestashop.customer_journey.category_view',
        'prestashop.customer_journey.cart_view',
        'prestashop.customer_journey.add_to_cart_intent',
        'prestashop.customer_journey.checkout_started',
        'prestashop.customer_journey.checkout_step',
        'prestashop.customer_journey.form_error',
        'prestashop.customer_journey.performance',
        'prestashop.customer_journey.exit_intent',
        'prestashop.customer_journey.cart_snapshot',
        'prestashop.customer_journey.order_completed',
    ];

    private const SENSITIVE_KEY_FRAGMENTS = [
        'authorization',
        'card',
        'cookie',
        'cvc',
        'cvv',
        'password',
        'payment_method',
        'payment_token',
        'secret',
        'session_cookie',
        'token',
    ];

    public static function buildFromBrowserPayload(array $payload, ?Context $context, string $moduleVersion): ?array
    {
        $eventType = self::safeText($payload['event_type'] ?? '', 140);
        if (!in_array($eventType, self::SUPPORTED_EVENT_TYPES, true)) {
            return null;
        }

        $cart = self::resolveContextCart($context);
        $shopId = self::resolveContextShopId($context, $cart);
        $journey = self::sanitizeMap($payload['journey'] ?? [], 40);
        $browserContext = self::sanitizeMap($payload['context'] ?? [], 30);

        $browserContext['origin'] = 'browser';
        $browserContext['collector'] = 'prestashop_customer_journey_tracker';
        $browserContext['module_version'] = $moduleVersion;

        if ($cart && $cart->id) {
            $browserContext['cart_id'] = (string) (int) $cart->id;
        }

        return [
            'event_id' => self::normalizeEventId($payload['event_id'] ?? null),
            'event_type' => $eventType,
            'occurred_at' => self::normalizeOccurredAt($payload['occurred_at'] ?? null),
            'source' => self::buildSource($context, $shopId),
            'customer' => self::buildCustomerPayload($context, $cart),
            'cart' => self::buildCartPayload($cart),
            'journey' => [
                'visitor_id' => self::safeIdentifier($journey['visitor_id'] ?? $payload['visitor_id'] ?? ''),
                'session_id' => self::safeIdentifier($journey['session_id'] ?? $payload['session_id'] ?? ''),
                'event' => self::sanitizeMap($payload['event'] ?? [], 20),
                'page' => self::sanitizeMap($payload['page'] ?? [], 30),
                'product' => self::sanitizeMap($payload['product'] ?? [], 20),
                'category' => self::sanitizeMap($payload['category'] ?? [], 20),
                'performance' => self::sanitizeMap($payload['performance'] ?? [], 20),
            ],
            'context' => $browserContext,
            'privacy' => self::buildPrivacyPayload(),
        ];
    }

    public static function buildServerCartSnapshot(
        Cart $cart,
        ?Context $context,
        string $moduleVersion,
        string $hookName
    ): ?array {
        if (!$cart->id) {
            return null;
        }

        $shopId = self::resolveContextShopId($context, $cart);

        return [
            'event_id' => Uuid::v4(),
            'event_type' => 'prestashop.customer_journey.cart_snapshot',
            'occurred_at' => gmdate('c'),
            'source' => self::buildSource($context, $shopId),
            'customer' => self::buildCustomerPayload($context, $cart),
            'cart' => self::buildCartPayload($cart, true),
            'journey' => [
                'event' => [
                    'name' => 'cart_snapshot',
                    'hook' => self::safeText($hookName, 80),
                ],
            ],
            'context' => [
                'origin' => 'server',
                'collector' => 'prestashop_customer_journey_cart_hook',
                'module_version' => $moduleVersion,
                'hook' => self::safeText($hookName, 80),
            ],
            'privacy' => self::buildPrivacyPayload(),
        ];
    }

    public static function buildServerOrderCompleted(
        Order $order,
        ?Context $context,
        string $moduleVersion,
        string $hookName
    ): ?array {
        if (!$order->id) {
            return null;
        }

        $shopId = self::resolveContextShopId($context, null, isset($order->id_shop) ? (int) $order->id_shop : 0);
        $orderId = (string) (int) $order->id;
        $cartId = isset($order->id_cart) ? (string) (int) $order->id_cart : '';

        return [
            'event_id' => self::deterministicEventId(
                'prestashop.customer_journey.order_completed',
                (string) $shopId,
                $orderId
            ),
            'event_type' => 'prestashop.customer_journey.order_completed',
            'occurred_at' => gmdate('c'),
            'source' => self::buildSource($context, $shopId),
            'customer' => self::buildOrderCustomerPayload($order),
            'cart' => [
                'id' => $cartId !== '' ? $cartId : null,
                'uid' => $cartId !== '' ? 'ps_' . $cartId : null,
                'total' => round((float) ($order->total_paid_tax_incl ?? $order->total_paid ?? 0), 2),
                'currency_code' => self::resolveOrderCurrencyCode($order, $context),
            ],
            'order' => [
                'id' => $orderId,
                'cart_id' => $cartId !== '' ? $cartId : null,
                'total' => round((float) ($order->total_paid_tax_incl ?? $order->total_paid ?? 0), 2),
                'products' => self::buildOrderProducts($order),
            ],
            'journey' => [
                'event' => [
                    'name' => 'order_completed',
                    'hook' => self::safeText($hookName, 80),
                ],
            ],
            'context' => [
                'origin' => 'server',
                'collector' => 'prestashop_customer_journey_order_hook',
                'module_version' => $moduleVersion,
                'hook' => self::safeText($hookName, 80),
            ],
            'privacy' => self::buildPrivacyPayload(),
        ];
    }

    public static function isSupportedEventType(string $eventType): bool
    {
        return in_array($eventType, self::SUPPORTED_EVENT_TYPES, true);
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

    private static function buildCustomerPayload(?Context $context, ?Cart $cart): array
    {
        $customer = ($context && isset($context->customer) && $context->customer) ? $context->customer : null;
        if (!$customer || empty($customer->id)) {
            return [
                'id' => null,
                'is_guest' => true,
            ];
        }

        $email = strtolower(trim((string) ($customer->email ?? '')));

        return [
            'id' => (string) (int) $customer->id,
            'is_guest' => (bool) ($customer->is_guest ?? false),
            'email_hash' => $email !== '' ? hash('sha256', $email) : null,
            'cart_customer_id' => $cart && isset($cart->id_customer) ? (string) (int) $cart->id_customer : null,
        ];
    }

    private static function buildOrderCustomerPayload(Order $order): array
    {
        $customerId = isset($order->id_customer) ? (int) $order->id_customer : 0;

        return [
            'id' => $customerId > 0 ? (string) $customerId : null,
            'is_guest' => $customerId <= 0,
        ];
    }

    private static function buildCartPayload(?Cart $cart, bool $includeItems = false): array
    {
        if (!$cart || !$cart->id) {
            return [
                'id' => null,
                'uid' => null,
                'total' => null,
                'items' => [],
            ];
        }

        $total = 0.0;
        try {
            $total = (float) $cart->getOrderTotal(true, Cart::BOTH);
        } catch (\Throwable $e) {
            $total = 0.0;
        }

        $payload = [
            'id' => (string) (int) $cart->id,
            'uid' => 'ps_' . (int) $cart->id,
            'total' => round($total, 2),
            'currency_code' => self::resolveCartCurrencyCode($cart),
        ];

        if ($includeItems) {
            $payload['items'] = self::buildCartItems($cart);
        }

        return $payload;
    }

    private static function buildCartItems(Cart $cart): array
    {
        try {
            $products = $cart->getProducts(true);
        } catch (\Throwable $e) {
            return [];
        }

        if (!is_array($products) || empty($products)) {
            return [];
        }

        $items = [];
        foreach (array_slice($products, 0, self::MAX_CART_ITEMS) as $product) {
            $quantity = (int) ($product['cart_quantity'] ?? $product['quantity'] ?? 0);
            $unitPrice = (float) ($product['price_wt'] ?? $product['price'] ?? 0);
            $lineTotal = (float) ($product['total_wt'] ?? ($unitPrice * $quantity));
            $items[] = [
                'product_id' => (string) (int) ($product['id_product'] ?? 0),
                'attribute_id' => (string) (int) ($product['id_product_attribute'] ?? 0),
                'name' => self::safeText($product['name'] ?? '', 160),
                'category_path' => self::safeText($product['category'] ?? '', 160),
                'quantity' => $quantity,
                'unit_price' => round($unitPrice, 2),
                'line_total' => round($lineTotal, 2),
            ];
        }

        return $items;
    }

    private static function buildOrderProducts(Order $order): array
    {
        try {
            $products = $order->getProducts();
        } catch (\Throwable $e) {
            return [];
        }

        if (!is_array($products) || empty($products)) {
            return [];
        }

        $items = [];
        foreach (array_slice($products, 0, self::MAX_CART_ITEMS) as $product) {
            $quantity = (int) ($product['product_quantity'] ?? 0);
            $unitPrice = (float) ($product['unit_price_tax_incl'] ?? $product['product_price'] ?? 0);
            $items[] = [
                'product_id' => (string) (int) ($product['product_id'] ?? 0),
                'attribute_id' => (string) (int) ($product['product_attribute_id'] ?? 0),
                'name' => self::safeText($product['product_name'] ?? '', 160),
                'quantity' => $quantity,
                'unit_price' => round($unitPrice, 2),
                'line_total' => round($unitPrice * $quantity, 2),
            ];
        }

        return $items;
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

    private static function resolveContextShopId(?Context $context, ?Cart $cart = null, int $fallback = 0): int
    {
        if ($cart && isset($cart->id_shop) && (int) $cart->id_shop > 0) {
            return (int) $cart->id_shop;
        }
        if ($context && isset($context->shop) && $context->shop && (int) $context->shop->id > 0) {
            return (int) $context->shop->id;
        }
        if ($fallback > 0) {
            return $fallback;
        }

        return (int) Configuration::get('PS_SHOP_DEFAULT');
    }

    private static function resolveCartCurrencyCode(Cart $cart): ?string
    {
        $currencyId = isset($cart->id_currency) ? (int) $cart->id_currency : 0;
        if ($currencyId <= 0) {
            return null;
        }

        try {
            $currency = new Currency($currencyId);
            return !empty($currency->iso_code) ? strtoupper((string) $currency->iso_code) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function resolveOrderCurrencyCode(Order $order, ?Context $context): ?string
    {
        $currencyId = isset($order->id_currency) ? (int) $order->id_currency : 0;
        if ($currencyId <= 0 && $context && isset($context->currency) && $context->currency) {
            return !empty($context->currency->iso_code) ? strtoupper((string) $context->currency->iso_code) : null;
        }

        if ($currencyId <= 0) {
            return null;
        }

        try {
            $currency = new Currency($currencyId);
            return !empty($currency->iso_code) ? strtoupper((string) $currency->iso_code) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function resolveLanguage(?Context $context): ?string
    {
        if ($context && isset($context->language) && $context->language) {
            if (!empty($context->language->locale)) {
                return self::safeText($context->language->locale, 20);
            }
            if (!empty($context->language->iso_code)) {
                return self::safeText($context->language->iso_code, 20);
            }
        }

        return null;
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

    private static function safeIdentifier($value): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        $text = preg_replace('/[^a-zA-Z0-9_.:-]/', '', $text);
        return $text !== '' ? substr($text, 0, 120) : null;
    }

    private static function sanitizeMap($value, int $maxItems = 30, int $depth = 4): array
    {
        if (!is_array($value) || $depth <= 0) {
            return [];
        }

        $result = [];
        $count = 0;
        foreach ($value as $key => $item) {
            if ($count >= $maxItems) {
                $result['_truncated'] = true;
                break;
            }

            $safeKey = substr(preg_replace('/[^a-zA-Z0-9_.:-]/', '_', (string) $key), 0, 120);
            if ($safeKey === '' || self::isSensitiveKey($safeKey)) {
                continue;
            }

            if (is_array($item)) {
                $result[$safeKey] = self::sanitizeMap($item, $maxItems, $depth - 1);
            } elseif (is_bool($item) || is_int($item) || is_float($item) || $item === null) {
                $result[$safeKey] = $item;
            } else {
                $result[$safeKey] = self::safeText($item, self::MAX_TEXT_LENGTH);
            }
            $count++;
        }

        return $result;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);
        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (strpos($normalized, $fragment) !== false) {
                return true;
            }
        }

        return false;
    }

    private static function safeText($value, int $maxLength): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        $text = preg_replace('/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/', '[email]', $text);
        $text = preg_replace('/\b(?:sk|pk|rk|whsec|secret|token|api[_-]?key)[_-]?[A-Za-z0-9]{12,}\b/i', '[secret]', $text);

        return substr($text, 0, $maxLength);
    }

    private static function buildPrivacyPayload(): array
    {
        return [
            'contains_form_values' => false,
            'contains_payment_data' => false,
            'contains_raw_server_logs' => false,
            'contains_payment_provider_logs' => false,
            'uses_pseudonymous_visitor_id' => true,
        ];
    }
}

<?php

namespace NeuroCheckout\Event;

use Address;
use Configuration;
use Context;
use Country;
use Currency;
use Customer;
use Db;
use Image;
use Language;
use Link;
use Order;
use Shop;
use Tools;

class OrderEventBuilder
{
    public static function buildFromValidateOrderParams(array $params): ?array
    {
        if (empty($params['order']) || !($params['order'] instanceof Order)) {
            return null;
        }

        /** @var Order $order */
        $order = $params['order'];
        $orderId = (int)$order->id;
        $cartId  = (int)$order->id_cart;

        if ($orderId <= 0 || $cartId <= 0) {
            return null;
        }

        $context = Context::getContext();
        $shopExternalId = trim((string)Configuration::get('NC_SHOP_EXTERNAL_ID'));
        $shopFallbackId = (
            $context &&
            isset($context->shop) &&
            $context->shop &&
            isset($context->shop->id)
        )
            ? (string)(int)$context->shop->id
            : '0';

        $shopSourceId = $shopExternalId !== ''
            ? $shopExternalId
            : $shopFallbackId;
        $shopName = self::resolveShopName($context, isset($order->id_shop) ? (int)$order->id_shop : 0);
        $customerEmail = self::resolveCustomerEmail($params, $order);
        $currencyCode = self::resolveCurrencyIso($params, $order);
        $discountContext = self::resolveDiscountContext($order, $cartId, $customerEmail);
        $shopLocale = self::resolveShopLocale($params, $order, $context);
        $customerPayload = self::resolveCustomerPayload(
            $params,
            $order,
            $customerEmail,
            $shopLocale
        );
        $orderPayload = self::resolveOrderPayload($order, $context, $currencyCode);
        $runtimeContext = [
            'shop_locale' => $shopLocale,
            'currency_code' => $currencyCode,
            'currency_precision' => self::resolveCurrencyPrecision($params, $order, $context),
            'shop_timezone' => self::resolveShopTimezone(),
        ];
        $primaryColor = CartEventBuilder::resolveThemePrimaryColor($context);
        if ($primaryColor !== null) {
            $runtimeContext['primary_color'] = $primaryColor;
            $runtimeContext['theme_palette'] = ['primary_color' => $primaryColor];
        }
        $orderReference = self::nullableString($order->reference ?? null);
        $visibleOrderId = (string)$orderId;

        return [
            'event_id' => self::deterministicEventId($shopSourceId, $orderId, $cartId),
            'event_type' => 'order.completed',
            'occurred_at' => self::resolveOccurredAt($order),
            'order_id' => (string)$orderId,
            'technical_order_id' => (string)$orderId,
            'external_order_id' => $visibleOrderId,
            'customer_visible_order_id' => $visibleOrderId,
            'order_reference' => $orderReference,
            'cart_id' => (string)$cartId,
            'order_total' => self::resolveOrderTotal($params, $order),
            'customer_email' => $customerEmail,
            'currency' => $currencyCode,
            'total_discounts' => $discountContext['total_discounts'],
            'used_coupon_codes' => $discountContext['used_coupon_codes'],
            'neuro_coupon_used' => $discountContext['neuro_coupon_used'],
            'neuro_coupon_code' => $discountContext['neuro_coupon_code'],
            'neuro_discount_percent' => $discountContext['neuro_discount_percent'],
            'neuro_discount_amount' => $discountContext['neuro_discount_amount'],
            'customer' => $customerPayload,
            'order' => $orderPayload,
            'source' => [
                'platform' => 'prestashop',
                'shop_id' => $shopSourceId,
                'shop_name' => $shopName,
            ],
            'context' => $runtimeContext,
        ];
    }

    private static function resolveOccurredAt(Order $order): string
    {
        $dateAdd = isset($order->date_add) ? (string)$order->date_add : '';
        if ($dateAdd !== '') {
            $timestamp = strtotime($dateAdd);
            if ($timestamp !== false) {
                return gmdate('c', $timestamp);
            }
        }

        return gmdate('c');
    }

    private static function resolveOrderTotal(array $params, Order $order): float
    {
        $total = null;

        if (isset($params['orderTotal'])) {
            $total = (float)$params['orderTotal'];
        } elseif (isset($order->total_paid_tax_incl)) {
            $total = (float)$order->total_paid_tax_incl;
        } elseif (isset($order->total_paid)) {
            $total = (float)$order->total_paid;
        }

        if ($total === null || $total < 0) {
            $total = 0.0;
        }

        return round($total, 2);
    }

    private static function resolveCustomerEmail(array $params, Order $order): ?string
    {
        if (!empty($params['customer']) && $params['customer'] instanceof Customer) {
            $email = trim((string)$params['customer']->email);
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }

        $customerId = (int)$order->id_customer;
        if ($customerId > 0) {
            try {
                $customer = new Customer($customerId);
                if ($customer->id) {
                    $email = trim((string)$customer->email);
                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        return $email;
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        return null;
    }

    private static function resolveCustomerPayload(
        array $params,
        Order $order,
        ?string $customerEmail,
        string $locale
    ): array {
        $payload = [
            'id' => null,
            'email' => $customerEmail,
            'is_guest' => true,
            'first_name' => null,
            'last_name' => null,
            'phone' => null,
            'locale' => $locale,
            'address' => null,
        ];

        $customer = null;
        if (!empty($params['customer']) && $params['customer'] instanceof Customer) {
            $customer = $params['customer'];
        } elseif ((int)$order->id_customer > 0) {
            try {
                $loadedCustomer = new Customer((int)$order->id_customer);
                if ($loadedCustomer->id) {
                    $customer = $loadedCustomer;
                }
            } catch (\Throwable $e) {
            }
        }

        if ($customer instanceof Customer && $customer->id) {
            $payload['id'] = (string)(int)$customer->id;
            $payload['email'] = $payload['email'] ?: trim((string)$customer->email);
            $payload['is_guest'] = (bool)$customer->is_guest;
            $payload['first_name'] = self::nullableString($customer->firstname ?? null);
            $payload['last_name'] = self::nullableString($customer->lastname ?? null);
            if (isset($customer->newsletter)) {
                $payload['email_marketing_opt_in'] = (bool)$customer->newsletter;
                $payload['email_marketing_opt_in_source'] = 'prestashop_customer_newsletter';
                $payload['email_marketing_opt_in_recorded_at'] = (
                    self::nullableString($customer->date_upd ?? null) ?: gmdate('c')
                );
            }
        }

        $addressPayload = self::resolveAddressPayload($order);
        if ($addressPayload !== null) {
            $payload['address'] = $addressPayload;
            $payload['phone'] = $addressPayload['phone'] ?? null;
        }

        return $payload;
    }

    private static function resolveAddressPayload(Order $order): ?array
    {
        $addressId = 0;
        if (isset($order->id_address_delivery) && (int)$order->id_address_delivery > 0) {
            $addressId = (int)$order->id_address_delivery;
        } elseif (isset($order->id_address_invoice) && (int)$order->id_address_invoice > 0) {
            $addressId = (int)$order->id_address_invoice;
        }

        if ($addressId <= 0) {
            return null;
        }

        try {
            $address = new Address($addressId);
            if (!$address->id) {
                return null;
            }

            $countryCode = null;
            if ((int)$address->id_country > 0) {
                try {
                    $country = new Country((int)$address->id_country);
                    if ($country->id && !empty($country->iso_code)) {
                        $countryCode = strtoupper(trim((string)$country->iso_code));
                    }
                } catch (\Throwable $e) {
                }
            }

            $phone = self::nullableString($address->phone_mobile ?? null);
            if ($phone === null) {
                $phone = self::nullableString($address->phone ?? null);
            }

            return [
                'company' => self::nullableString($address->company ?? null),
                'address1' => self::nullableString($address->address1 ?? null),
                'address2' => self::nullableString($address->address2 ?? null),
                'postcode' => self::nullableString($address->postcode ?? null),
                'city' => self::nullableString($address->city ?? null),
                'country_code' => $countryCode,
                'phone' => $phone,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function resolveOrderPayload(
        Order $order,
        ?Context $context,
        ?string $currencyCode
    ): array {
        return [
            'id' => isset($order->id) ? (string)(int)$order->id : null,
            'reference' => self::nullableString($order->reference ?? null),
            'status' => self::resolveOrderStatus($order),
            'items' => self::resolveOrderItems($order, $context, $currencyCode),
        ];
    }

    private static function resolveOrderStatus(Order $order): ?string
    {
        if (isset($order->current_state) && (int)$order->current_state > 0) {
            return (string)(int)$order->current_state;
        }

        return null;
    }

    private static function resolveOrderItems(
        Order $order,
        ?Context $context,
        ?string $currencyCode
    ): array {
        $items = [];

        try {
            $products = $order->getProducts();
        } catch (\Throwable $e) {
            $products = [];
        }

        if (!is_array($products) || empty($products)) {
            return $items;
        }

        $shopId = isset($order->id_shop) ? (int)$order->id_shop : 0;
        $langId = (
            $context
            && isset($context->language)
            && $context->language
            && isset($context->language->id)
        ) ? (int)$context->language->id : (isset($order->id_lang) ? (int)$order->id_lang : 0);

        if ($langId <= 0) {
            $langId = (int) Configuration::get('PS_LANG_DEFAULT');
        }

        $link = self::resolveLink($context);
        static $productUrlCache = [];
        static $imageUrlCache = [];
        $cacheScope = $shopId . ':' . $langId;

        foreach ($products as $index => $product) {
            $productId = (int)($product['product_id'] ?? $product['id_product'] ?? 0);
            $attributeId = (int)($product['product_attribute_id'] ?? $product['id_product_attribute'] ?? 0);
            $quantity = (int)($product['product_quantity'] ?? $product['quantity'] ?? 0);

            $unitPrice = self::firstNonNegativeFloat([
                $product['unit_price_tax_incl'] ?? null,
                $product['product_price_wt'] ?? null,
                $product['product_price'] ?? null,
                $product['unit_price_tax_excl'] ?? null,
            ], 0.0);

            $lineTotal = self::firstNonNegativeFloat([
                $product['total_price_tax_incl'] ?? null,
                $product['total_price_wt'] ?? null,
                $product['total_price_tax_excl'] ?? null,
                $product['total_price'] ?? null,
            ], round($unitPrice * max(0, $quantity), 2));

            $productMeta = self::loadOrderProductMetadata($productId, $shopId, $langId);
            $productUrl = self::resolveOrderProductUrl(
                $product,
                $productId,
                $link,
                $productUrlCache,
                $cacheScope
            );
            $imageUrl = self::resolveOrderImageUrl(
                $product,
                $productId,
                (string)($productMeta['link_rewrite'] ?? ''),
                $link,
                $imageUrlCache,
                $cacheScope
            );
            $resolvedCategoryPath = self::resolveCategoryPath(
                $productMeta['category_path'] ?? null,
                $product,
                $productUrl,
                (int)($productMeta['category_id'] ?? 0)
            );
            $resolvedBrandName = self::resolveBrandName(
                $productMeta['brand_name'] ?? null,
                $product
            );
            $resolvedName = self::nullableString($product['product_name'] ?? $product['name'] ?? null)
                ?: self::nullableString($productMeta['product_name'] ?? null)
                ?: ($productId > 0 ? 'product_' . $productId : null);

            $items[] = [
                'product_id' => $productId > 0 ? (string)$productId : null,
                'attribute_id' => $attributeId > 0 ? (string)$attributeId : null,
                'reference' => self::nullableString($product['product_reference'] ?? $product['reference'] ?? null),
                'name' => $resolvedName,
                'variant_label' => self::nullableString($product['product_attribute_name'] ?? $product['attributes_small'] ?? null),
                'category_path' => $resolvedCategoryPath,
                'brand_name' => $resolvedBrandName,
                'quantity' => $quantity,
                'unit_price' => round($unitPrice, 2),
                'line_total' => round($lineTotal, 2),
                'currency_code' => $currencyCode,
                'product_url' => $productUrl,
                'image_url' => $imageUrl,
                'product_snapshot' => [
                    'product_name' => self::nullableString($productMeta['product_name'] ?? null) ?: $resolvedName,
                    'reference' => self::nullableString($product['product_reference'] ?? $product['reference'] ?? null),
                    'product_url' => $productUrl,
                    'image_url' => $imageUrl,
                    'category_path' => $resolvedCategoryPath,
                    'brand_name' => $resolvedBrandName,
                ],
                'line_number' => $index + 1,
            ];
        }

        return $items;
    }

    private static function loadOrderProductMetadata(
        int $productId,
        int $shopId,
        int $langId
    ): array {
        static $cache = [];

        if ($productId <= 0) {
            return [
                'product_name' => null,
                'link_rewrite' => null,
                'brand_name' => null,
                'category_path' => null,
                'category_id' => null,
            ];
        }

        $cacheKey = $shopId . ':' . $langId . ':' . $productId;
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $queries = [
            '
            SELECT
                pl.name AS product_name,
                pl.link_rewrite AS link_rewrite,
                m.name AS brand_name,
                cl_shop.name AS category_name,
                COALESCE(NULLIF(ps.id_category_default, 0), NULLIF(p.id_category_default, 0)) AS category_id
            FROM `' . _DB_PREFIX_ . 'product` p
            LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                ON pl.id_product = p.id_product
               AND pl.id_lang = ' . (int)$langId . '
               AND pl.id_shop = ' . (int)$shopId . '
            LEFT JOIN `' . _DB_PREFIX_ . 'product_shop` ps
                ON ps.id_product = p.id_product
               AND ps.id_shop = ' . (int)$shopId . '
            LEFT JOIN `' . _DB_PREFIX_ . 'manufacturer` m
                ON m.id_manufacturer = p.id_manufacturer
            LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl_shop
                ON cl_shop.id_category = COALESCE(NULLIF(ps.id_category_default, 0), NULLIF(p.id_category_default, 0))
               AND cl_shop.id_lang = ' . (int)$langId . '
               AND cl_shop.id_shop = ' . (int)$shopId . '
            WHERE p.id_product = ' . (int)$productId . '
            LIMIT 1',
            '
            SELECT
                pl.name AS product_name,
                pl.link_rewrite AS link_rewrite,
                m.name AS brand_name,
                cl_any.name AS category_name,
                COALESCE(NULLIF(ps.id_category_default, 0), NULLIF(p.id_category_default, 0)) AS category_id
            FROM `' . _DB_PREFIX_ . 'product` p
            LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                ON pl.id_product = p.id_product
               AND pl.id_lang = ' . (int)$langId . '
            LEFT JOIN `' . _DB_PREFIX_ . 'product_shop` ps
                ON ps.id_product = p.id_product
               AND ps.id_shop = ' . (int)$shopId . '
            LEFT JOIN `' . _DB_PREFIX_ . 'manufacturer` m
                ON m.id_manufacturer = p.id_manufacturer
            LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl_any
                ON cl_any.id_category = COALESCE(NULLIF(ps.id_category_default, 0), NULLIF(p.id_category_default, 0))
               AND cl_any.id_lang = ' . (int)$langId . '
            WHERE p.id_product = ' . (int)$productId . '
            LIMIT 1',
            '
            SELECT
                pl.name AS product_name,
                pl.link_rewrite AS link_rewrite,
                m.name AS brand_name,
                cl.name AS category_name,
                cp.id_category AS category_id
            FROM `' . _DB_PREFIX_ . 'product` p
            LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                ON pl.id_product = p.id_product
               AND pl.id_lang = ' . (int)$langId . '
            LEFT JOIN `' . _DB_PREFIX_ . 'manufacturer` m
                ON m.id_manufacturer = p.id_manufacturer
            LEFT JOIN `' . _DB_PREFIX_ . 'category_product` cp
                ON cp.id_product = p.id_product
            LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                ON cl.id_category = cp.id_category
               AND cl.id_lang = ' . (int)$langId . '
            WHERE p.id_product = ' . (int)$productId . '
            ORDER BY cp.position ASC
            LIMIT 1',
        ];

        $row = [];
        foreach ($queries as $query) {
            try {
                $row = Db::getInstance()->getRow($query);
            } catch (\Throwable $e) {
                $row = [];
            }

            if (
                !empty($row['product_name'])
                || !empty($row['link_rewrite'])
                || !empty($row['brand_name'])
                || !empty($row['category_name'])
                || !empty($row['category_id'])
            ) {
                break;
            }
        }
        $cache[$cacheKey] = [
            'product_name' => self::nullableString($row['product_name'] ?? null),
            'link_rewrite' => self::nullableString($row['link_rewrite'] ?? null),
            'brand_name' => self::nullableString($row['brand_name'] ?? null),
            'category_path' => self::nullableString($row['category_name'] ?? null),
            'category_id' => (isset($row['category_id']) && (int)$row['category_id'] > 0)
                ? (int)$row['category_id']
                : null,
        ];

        return $cache[$cacheKey];
    }

    private static function resolveOrderProductUrl(
        array $product,
        int $productId,
        Link $link,
        array &$cache,
        string $cacheScope
    ): ?string {
        $existing = self::nullableString($product['product_url'] ?? $product['url'] ?? null);
        if ($existing !== null) {
            return self::normalizeAbsoluteUrl($existing, $link);
        }

        if ($productId <= 0) {
            return null;
        }

        if (!isset($cache[$cacheScope][$productId])) {
            $cache[$cacheScope][$productId] = null;
            try {
                $cache[$cacheScope][$productId] = self::normalizeAbsoluteUrl(
                    $link->getProductLink($productId),
                    $link
                );
            } catch (\Throwable $e) {
                $cache[$cacheScope][$productId] = null;
            }
        }

        return $cache[$cacheScope][$productId];
    }

    private static function resolveOrderImageUrl(
        array $product,
        int $productId,
        string $linkRewrite,
        Link $link,
        array &$cache,
        string $cacheScope
    ): ?string {
        $existing = self::nullableString($product['image_url'] ?? null);
        if ($existing !== null) {
            return self::normalizeAbsoluteUrl($existing, $link);
        }

        if ($productId <= 0) {
            return null;
        }

        if (!isset($cache[$cacheScope][$productId])) {
            $cache[$cacheScope][$productId] = null;
            try {
                $cover = Image::getCover($productId);
                if (is_array($cover) && !empty($cover['id_image'])) {
                    $cache[$cacheScope][$productId] = self::buildProductImageUrl(
                        $link,
                        $productId,
                        (int) $cover['id_image'],
                        $linkRewrite
                    );
                }
            } catch (\Throwable $e) {
                $cache[$cacheScope][$productId] = null;
            }
        }

        return $cache[$cacheScope][$productId];
    }

    private static function resolveLink(?Context $context = null): Link
    {
        if (
            $context
            && isset($context->link)
            && $context->link instanceof Link
        ) {
            return $context->link;
        }

        $protocol = Tools::getCurrentUrlProtocolPrefix();
        if ($protocol !== 'http://' && $protocol !== 'https://') {
            $protocol = 'http://';
        }

        return new Link($protocol, $protocol);
    }

    private static function buildProductImageUrl(
        Link $link,
        int $productId,
        int $imageId,
        string $linkRewrite = ''
    ): ?string {
        if ($imageId <= 0) {
            return null;
        }

        $imageName = trim($linkRewrite) !== '' ? trim($linkRewrite) : 'product';
        $imageIdCandidates = [];

        if ($productId > 0) {
            $imageIdCandidates[] = $productId . '-' . $imageId;
        }
        $imageIdCandidates[] = (string) $imageId;

        foreach ($imageIdCandidates as $imageIdCandidate) {
            foreach (['home_default', 'large_default', null] as $imageType) {
                try {
                    $candidate = $link->getImageLink($imageName, $imageIdCandidate, $imageType);
                } catch (\Throwable $e) {
                    $candidate = null;
                }

                $normalized = self::normalizeAbsoluteUrl($candidate, $link);
                if ($normalized !== null) {
                    return $normalized;
                }
            }
        }

        return null;
    }

    private static function normalizeAbsoluteUrl(?string $url, Link $link): ?string
    {
        if (!$url) {
            return null;
        }

        $url = trim((string)$url);
        if ($url === '') {
            return null;
        }

        if (
            strpos($url, 'http://') === 0
            || strpos($url, 'https://') === 0
        ) {
            return $url;
        }

        if (strpos($url, '//') === 0) {
            return 'https:' . $url;
        }

        $origin = self::resolveOrigin($link);

        if (strpos($url, 'localhost:') === 0 || strpos($url, '127.0.0.1:') === 0) {
            return 'http://' . $url;
        }

        if ($origin !== '') {
            if (strpos($url, '/') === 0) {
                return $origin . $url;
            }

            return $origin . '/' . ltrim($url, '/');
        }

        return $url;
    }

    private static function resolveOrigin(Link $link): string
    {
        $baseCandidates = [
            self::safeGetPageLink($link, false),
            self::safeGetPageLink($link, null),
            self::safeGetPageLink($link, true),
            Tools::getShopDomain(true),
            Tools::getShopDomainSsl(true),
        ];

        foreach ($baseCandidates as $baseCandidate) {
            $origin = self::extractUrlOrigin($baseCandidate);
            if ($origin !== '') {
                return $origin;
            }
        }

        return '';
    }

    private static function safeGetPageLink(Link $link, ?bool $ssl): ?string
    {
        try {
            return $link->getPageLink('index', $ssl);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function extractUrlOrigin(?string $url): string
    {
        $candidate = trim((string) $url);
        if ($candidate === '') {
            return '';
        }

        $parsed = parse_url($candidate);
        if (empty($parsed['scheme']) || empty($parsed['host'])) {
            return '';
        }

        $origin = $parsed['scheme'] . '://' . $parsed['host'];
        if (!empty($parsed['port'])) {
            $origin .= ':' . $parsed['port'];
        }

        return $origin;
    }

    private static function resolveCategoryPath(
        ?string $primaryCategory,
        array $product,
        ?string $productUrl,
        int $fallbackCategoryId = 0
    ): string {
        $candidates = [
            $primaryCategory,
            $product['category'] ?? null,
            $product['category_name'] ?? null,
            $product['default_category'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $normalized = self::nullableString($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        $categoryIdCandidates = [
            $product['id_category_default'] ?? null,
            $product['id_category'] ?? null,
            $fallbackCategoryId > 0 ? $fallbackCategoryId : null,
        ];
        foreach ($categoryIdCandidates as $categoryIdCandidate) {
            $categoryId = (int)$categoryIdCandidate;
            if ($categoryId > 0) {
                return 'category_' . $categoryId;
            }
        }

        $fromUrl = self::extractCategoryFromUrl($productUrl);
        if ($fromUrl !== null) {
            return $fromUrl;
        }

        return 'uncategorized';
    }

    private static function extractCategoryFromUrl(?string $url): ?string
    {
        $normalizedUrl = self::nullableString($url);
        if ($normalizedUrl === null) {
            return null;
        }

        $path = parse_url($normalizedUrl, PHP_URL_PATH);
        if (!is_string($path) || trim($path) === '') {
            return null;
        }

        $segments = explode('/', trim($path, '/'));
        foreach ($segments as $segment) {
            $slug = strtolower(trim((string)$segment));
            if ($slug === '' || $slug === 'index.php') {
                continue;
            }
            if (preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $slug)) {
                continue;
            }
            if (preg_match('/^\d+-([a-z0-9-]+)$/', $slug, $matches)) {
                return trim((string)$matches[1]) !== '' ? (string)$matches[1] : null;
            }
            if (strpos($slug, '.') !== false) {
                continue;
            }

            return $slug;
        }

        return null;
    }

    private static function resolveBrandName(?string $primaryBrand, array $product): ?string
    {
        $candidates = [
            $primaryBrand,
            $product['manufacturer_name'] ?? null,
            $product['manufacturer'] ?? null,
            $product['brand_name'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $normalized = self::nullableString($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        $manufacturerId = (int)($product['id_manufacturer'] ?? 0);
        if ($manufacturerId > 0) {
            try {
                $manufacturer = new \Manufacturer($manufacturerId);
                if (!empty($manufacturer->name)) {
                    return self::nullableString($manufacturer->name);
                }
            } catch (\Throwable $e) {
            }
        }

        return null;
    }

    private static function nullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string)$value);
        return $value !== '' ? $value : null;
    }

    private static function firstNonNegativeFloat(array $candidates, float $default): float
    {
        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            $value = (float)$candidate;
            if ($value >= 0) {
                return $value;
            }
        }

        return $default;
    }

    private static function resolveCurrencyIso(array $params, Order $order): ?string
    {
        if (!empty($params['currency']) && $params['currency'] instanceof Currency) {
            $iso = strtoupper(trim((string)$params['currency']->iso_code));
            return $iso !== '' ? $iso : null;
        }

        $currencyId = (int)$order->id_currency;
        if ($currencyId > 0) {
            try {
                $currency = new Currency($currencyId);
                if ($currency->id) {
                    $iso = strtoupper(trim((string)$currency->iso_code));
                    return $iso !== '' ? $iso : null;
                }
            } catch (\Throwable $e) {
            }
        }

        return null;
    }

    private static function resolveCurrencyPrecision(
        array $params,
        Order $order,
        ?Context $context
    ): int {
        foreach ([$params['currency'] ?? null, $context->currency ?? null] as $currency) {
            if (!is_object($currency)) {
                continue;
            }

            if (isset($currency->precision) && $currency->precision !== null) {
                return max(0, min(4, (int)$currency->precision));
            }

            if (isset($currency->decimals)) {
                return (bool)$currency->decimals ? 2 : 0;
            }
        }

        $currencyId = (int)$order->id_currency;
        if ($currencyId > 0) {
            try {
                $currency = new Currency($currencyId);
                if (isset($currency->precision) && $currency->precision !== null) {
                    return max(0, min(4, (int)$currency->precision));
                }
                if (isset($currency->decimals)) {
                    return (bool)$currency->decimals ? 2 : 0;
                }
            } catch (\Throwable $e) {
            }
        }

        return 2;
    }

    private static function resolveShopLocale(
        array $params,
        Order $order,
        ?Context $context
    ): string {
        if (
            $context
            && isset($context->language)
            && $context->language
            && !empty($context->language->locale)
        ) {
            return (string)$context->language->locale;
        }

        if (!empty($params['language']) && isset($params['language']->locale)) {
            $locale = trim((string)$params['language']->locale);
            if ($locale !== '') {
                return $locale;
            }
        }

        $langId = 0;
        if (!empty($params['language']) && isset($params['language']->id)) {
            $langId = (int)$params['language']->id;
        } elseif (isset($order->id_lang)) {
            $langId = (int)$order->id_lang;
        }

        if ($langId > 0) {
            try {
                $language = new Language($langId);
                if ($language->id && !empty($language->locale)) {
                    return (string)$language->locale;
                }
            } catch (\Throwable $e) {
            }
        }

        return 'fr-FR';
    }

    private static function resolveShopTimezone(): string
    {
        $timezone = trim((string)Configuration::get('PS_TIMEZONE'));
        return $timezone !== '' ? $timezone : 'UTC';
    }

    private static function resolveShopName(?Context $context, int $shopId): ?string
    {
        if (
            $context
            && isset($context->shop)
            && $context->shop
            && !empty($context->shop->name)
        ) {
            $name = trim((string)$context->shop->name);
            if ($name !== '') {
                return $name;
            }
        }

        if ($shopId > 0) {
            try {
                $shop = new Shop($shopId);
                if ($shop->id && !empty($shop->name)) {
                    $name = trim((string)$shop->name);
                    if ($name !== '') {
                        return $name;
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        return null;
    }

    private static function resolveDiscountContext(
        Order $order,
        int $cartId,
        ?string $customerEmail
    ): array {
        $usedCouponCodes = [];
        $discountAmountsByRuleId = [];
        $discountAmountsByCode = [];
        $totalDiscounts = self::resolveTotalDiscounts($order);

        $orderCartRules = $order->getCartRules();
        if (is_array($orderCartRules)) {
            foreach ($orderCartRules as $rule) {
                $code = strtoupper(trim((string)($rule['code'] ?? '')));
                if ($code !== '') {
                    $usedCouponCodes[] = $code;
                }

                $amount = self::extractRuleDiscountAmount($rule);
                if ($amount === null) {
                    continue;
                }

                $ruleId = (int)($rule['id_cart_rule'] ?? 0);
                if ($ruleId > 0) {
                    $discountAmountsByRuleId[$ruleId] = $amount;
                }
                if ($code !== '') {
                    $discountAmountsByCode[$code] = $amount;
                }
            }
        }

        $usedCouponCodes = array_values(array_unique($usedCouponCodes));
        $neuroCouponRows = self::loadNeuroCouponRows($order, $cartId, $customerEmail);

        $neuroCouponUsed = false;
        $neuroCouponCode = null;
        $neuroDiscountPercent = null;
        $neuroDiscountAmount = 0.0;

        foreach ($neuroCouponRows as $row) {
            $couponCode = strtoupper(trim((string)($row['coupon_code'] ?? '')));
            $cartRuleId = (int)($row['cart_rule_id'] ?? 0);

            $matchedAmount = null;
            if ($cartRuleId > 0 && array_key_exists($cartRuleId, $discountAmountsByRuleId)) {
                $matchedAmount = $discountAmountsByRuleId[$cartRuleId];
            } elseif ($couponCode !== '' && array_key_exists($couponCode, $discountAmountsByCode)) {
                $matchedAmount = $discountAmountsByCode[$couponCode];
            } elseif (
                $couponCode !== ''
                && in_array($couponCode, $usedCouponCodes, true)
                && count($usedCouponCodes) === 1
                && $totalDiscounts > 0
            ) {
                $matchedAmount = $totalDiscounts;
            }

            if ($matchedAmount === null) {
                continue;
            }

            $neuroCouponUsed = true;
            if ($neuroCouponCode === null && $couponCode !== '') {
                $neuroCouponCode = $couponCode;
            }
            if ($neuroDiscountPercent === null && isset($row['discount_percent'])) {
                $neuroDiscountPercent = round((float)$row['discount_percent'], 2);
            }
            $neuroDiscountAmount += $matchedAmount;
        }

        if (!$neuroCouponUsed) {
            foreach ($usedCouponCodes as $code) {
                if (strpos($code, 'NC-') === 0) {
                    $neuroCouponUsed = true;
                    $neuroCouponCode = $code;
                    if ($totalDiscounts > 0 && count($usedCouponCodes) === 1) {
                        $neuroDiscountAmount = $totalDiscounts;
                    }
                    break;
                }
            }
        }

        return [
            'total_discounts' => round($totalDiscounts, 2),
            'used_coupon_codes' => $usedCouponCodes,
            'neuro_coupon_used' => $neuroCouponUsed,
            'neuro_coupon_code' => $neuroCouponCode,
            'neuro_discount_percent' => $neuroDiscountPercent,
            'neuro_discount_amount' => round($neuroDiscountAmount, 2),
        ];
    }

    private static function resolveTotalDiscounts(Order $order): float
    {
        $totalDiscounts = null;

        if (isset($order->total_discounts_tax_incl)) {
            $totalDiscounts = (float)$order->total_discounts_tax_incl;
        } elseif (isset($order->total_discounts)) {
            $totalDiscounts = (float)$order->total_discounts;
        }

        if ($totalDiscounts === null || $totalDiscounts < 0) {
            return 0.0;
        }

        return round($totalDiscounts, 2);
    }

    private static function extractRuleDiscountAmount(array $rule): ?float
    {
        $candidates = [
            $rule['value_tax_incl'] ?? null,
            $rule['value_real'] ?? null,
            $rule['value'] ?? null,
            $rule['reduction_amount'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            $amount = round((float)$candidate, 2);
            if ($amount >= 0) {
                return $amount;
            }
        }

        return null;
    }

    private static function loadNeuroCouponRows(
        Order $order,
        int $cartId,
        ?string $customerEmail
    ): array {
        $shopId = (int)$order->id_shop;
        if ($shopId <= 0 || $cartId <= 0) {
            return [];
        }

        $query = '
            SELECT cart_rule_id, coupon_code, discount_percent
            FROM `' . _DB_PREFIX_ . 'neurocheckout_coupon`
            WHERE shop_id = ' . $shopId . '
              AND cart_id = "' . pSQL((string)$cartId) . '"';

        if ($customerEmail) {
            $query .= '
              AND LOWER(customer_email) = LOWER("' . pSQL($customerEmail) . '")';
        }

        $query .= '
            ORDER BY created_at DESC';

        $rows = Db::getInstance()->executeS($query);

        return is_array($rows) ? $rows : [];
    }

    private static function deterministicEventId(
        string $shopSourceId,
        int $orderId,
        int $cartId
    ): string {
        $hash = md5('order.completed|' . $shopSourceId . '|' . $orderId . '|' . $cartId);

        $timeHi = sprintf(
            '%04x',
            (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x4000
        );
        $clockSeq = sprintf(
            '%04x',
            (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000
        );

        return substr($hash, 0, 8)
            . '-'
            . substr($hash, 8, 4)
            . '-'
            . $timeHi
            . '-'
            . $clockSeq
            . '-'
            . substr($hash, 20, 12);
    }
}

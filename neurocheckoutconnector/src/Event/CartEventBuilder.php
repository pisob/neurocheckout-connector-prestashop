<?php

namespace NeuroCheckout\Event;

use Cart;
use Address;
use Customer;
use Context;
use Configuration;
use Currency;
use Db;
use Link;
use Image;
use Shop;
use StockAvailable;
use Tools;
use NeuroCheckout\Util\Uuid;

class CartEventBuilder
{
    public static function build(Cart $cart): ?array
    {
        if (!$cart->id) {
            return null;
        }

        $context = Context::getContext();
        $shopId = self::resolveShopId($cart, $context);
        $langId = self::resolveLangId($context);
        $config = self::loadShopConfig($shopId);

        $customizations = self::loadCartCustomizations((int)$cart->id);

        $products = $cart->getProducts(true);

        $items = [];
        $total = 0.0;

        $link = self::resolveLink($context);

        static $productUrlCache = [];
        static $imageUrlCache = [];
        static $productMetaCache = [];
        $cacheScope = $shopId . ':' . $langId;

        foreach ($products as $product) {

            $productId   = (int) $product['id_product'];
            $attributeId = (int) ($product['id_product_attribute'] ?? 0);
            $quantity    = (int) $product['cart_quantity'];

            $unitPrice = (float) ($product['price_wt'] ?? 0);
            $calculatedLineTotal = $unitPrice * $quantity;
            $lineTotal = (float) ($product['total_wt'] ?? $calculatedLineTotal);
            if ($lineTotal <= 0 && $calculatedLineTotal > 0) {
                $lineTotal = $calculatedLineTotal;
            }

            if (!isset($productUrlCache[$cacheScope][$productId])) {
                $productUrlCache[$cacheScope][$productId] = $link->getProductLink($productId);
            }

            $productUrl = self::normalizeAbsoluteUrl(
                $productUrlCache[$cacheScope][$productId],
                $link
            );

            $imageUrl = null;
            $imageId = (int) ($product['id_image'] ?? 0);

            if ($imageId) {

                if (!isset($imageUrlCache[$cacheScope][$imageId])) {

                    try {

                        $image = new Image($imageId);

                        $imageUrlCache[$cacheScope][$imageId] = self::buildProductImageUrl(
                            $link,
                            $productId,
                            $image->id,
                            (string) ($product['link_rewrite'] ?? '')
                        );

                    } catch (\Throwable $e) {

                        $imageUrlCache[$cacheScope][$imageId] = null;
                    }
                }

                $imageUrl = $imageUrlCache[$cacheScope][$imageId];
            }

            $productName = trim((string)($product['name'] ?? ''));
            if ($productName === '') {
                $productName = self::buildNameFromSlug(
                    (string)($product['link_rewrite'] ?? ''),
                    $productId
                );
            }

            $productMetaCacheKey = $cacheScope . ':' . $productId;
            if (!isset($productMetaCache[$productMetaCacheKey])) {
                $productMetaCache[$productMetaCacheKey] = self::loadProductMetadata(
                    $productId,
                    $shopId,
                    $langId
                );
            }
            $productMeta = $productMetaCache[$productMetaCacheKey];
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
            $availability = self::resolveAvailability(
                $product,
                $productId,
                $attributeId,
                $shopId
            );

            $item = [

                'product_id'   => $productId,
                'attribute_id' => $attributeId,
                'name'         => $productName,
                'category_path' => $resolvedCategoryPath,
                'brand_name' => $resolvedBrandName,

                'quantity'     => $quantity,
                'unit_price'   => round($unitPrice, 2),
                'line_total'   => round($lineTotal, 2),
                'availability' => $availability['availability'],
                'in_stock'     => $availability['in_stock'],
                'stock'        => $availability['stock'],

                'product_url'  => $productUrl,
                'image_url'    => $imageUrl,
            ];

            $key = $productId . '-' . $attributeId;

            if (!empty($customizations[$key])) {

                foreach ($customizations[$key] as $fieldName => $fieldValue) {

                    $normalizedKey = self::normalizeKey($fieldName);
                    $item[$normalizedKey] = $fieldValue;
                }
            }

            $items[] = $item;
            $total += $lineTotal;
        }

        if (empty($items)) {
            return null;
        }

        $total = round($total, 2);

        $locale = self::resolveLocale($context);
        $languageCode = self::resolveLanguageCode($context);
        $customerData = self::buildCustomerData(
            $cart,
            $locale,
            $languageCode
        );
        $runtimeContext = self::buildRuntimeContext($context, $languageCode);
        $shopName = self::resolveShopName($context, $shopId);

        return [

            'event_id'    => Uuid::v4(),
            'event_type'  => 'cart.updated',
            'occurred_at' => gmdate('c'),

            'source' => [
                'platform' => 'prestashop',
                'shop_id'  => $config['external_shop_id'],
                'shop_name' => $shopName,
                'language' => $languageCode,
            ],

            'cart' => [
                'id'    => (string) $cart->id,
                'uid'   => self::generateCartUid($cart, $config['external_shop_id']),
                'total' => $total,
                'items' => $items,
            ],

            'customer' => $customerData,

            'context' => $runtimeContext,
            'language' => $languageCode,
            'rules' => self::buildRulesPayload($config),
            'meta' => self::buildSessionMeta(),
        ];
    }

    public static function buildCleared(Cart $cart, string $reason = 'cart_cleared'): ?array
    {
        if (!$cart->id) {
            return null;
        }

        $context = Context::getContext();
        $shopId = self::resolveShopId($cart, $context);
        $config = self::loadShopConfig($shopId);
        $shopName = self::resolveShopName($context, $shopId);

        $locale = self::resolveLocale($context);
        $languageCode = self::resolveLanguageCode($context);

        return [
            'event_id'    => Uuid::v4(),
            'event_type'  => 'cart.cleared',
            'occurred_at' => gmdate('c'),

            'source' => [
                'platform' => 'prestashop',
                'shop_id'  => $config['external_shop_id'],
                'shop_name' => $shopName,
                'language' => $languageCode,
            ],

            'cart' => [
                'id'    => (string) $cart->id,
                'uid'   => self::generateCartUid($cart, $config['external_shop_id']),
                'total' => 0.0,
                'items' => [],
            ],

            'customer' => self::buildCustomerData(
                $cart,
                $locale,
                $languageCode
            ),

            'context' => self::buildRuntimeContext($context, $languageCode),
            'language' => $languageCode,
            'rules' => self::buildRulesPayload($config),
            'meta' => [
                'clear_reason' => trim($reason) !== '' ? trim($reason) : 'cart_cleared',
                'session' => self::buildSessionMeta(),
            ],
        ];
    }

    private static function loadCartCustomizations(int $cartId): array
    {
        $idLang = (int) Context::getContext()->language->id;

        $rows = Db::getInstance()->executeS(
            '
            SELECT
                c.id_product,
                c.id_product_attribute,
                COALESCE(cfl.name, "custom_field") AS field_name,
                cd.value
            FROM `'._DB_PREFIX_.'customization` c
            INNER JOIN `'._DB_PREFIX_.'customized_data` cd
                ON cd.id_customization = c.id_customization
            LEFT JOIN `'._DB_PREFIX_.'customization_field_lang` cfl
                ON cfl.id_customization_field = cd.`index`
                AND cfl.id_lang = '.$idLang.'
            WHERE c.id_cart = '.(int)$cartId.'
            AND cd.type = 1
            LIMIT 200
            '
        );

        $result = [];

        foreach ($rows as $row) {

            $key = (int)$row['id_product'].'-'.(int)$row['id_product_attribute'];

            if (!isset($result[$key])) {
                $result[$key] = [];
            }

            $fieldName = self::normalizeKey($row['field_name']);
            $result[$key][$fieldName] = $row['value'];
        }

        return $result;
    }

    private static function normalizeKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = preg_replace('/[^a-z0-9_]+/', '_', $key);
        return trim($key, '_');
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

        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        if (
            strpos($url, 'http://') === 0 ||
            strpos($url, 'https://') === 0
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

    private static function buildNameFromSlug(string $slug, int $productId): string
    {
        $slug = trim($slug);
        if ($slug === '') {
            return 'product_' . $productId;
        }

        $label = str_replace('-', ' ', $slug);
        $label = preg_replace('/\s+/', ' ', $label);
        return ucfirst(trim((string) $label));
    }

    private static function loadProductMetadata(
        int $productId,
        int $shopId,
        int $langId
    ): array {
        if ($productId <= 0) {
            return [
                'brand_name' => null,
                'category_path' => null,
                'category_id' => null,
            ];
        }

        $queries = [
            '
            SELECT
                m.name AS brand_name,
                cl_shop.name AS category_name,
                COALESCE(NULLIF(ps.id_category_default, 0), NULLIF(p.id_category_default, 0)) AS category_id
            FROM `' . _DB_PREFIX_ . 'product` p
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
                m.name AS brand_name,
                cl_any.name AS category_name,
                COALESCE(NULLIF(ps.id_category_default, 0), NULLIF(p.id_category_default, 0)) AS category_id
            FROM `' . _DB_PREFIX_ . 'product` p
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
                m.name AS brand_name,
                cl.name AS category_name,
                cp.id_category AS category_id
            FROM `' . _DB_PREFIX_ . 'product` p
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
                !empty($row['brand_name'])
                || !empty($row['category_name'])
                || !empty($row['category_id'])
            ) {
                break;
            }
        }

        return [
            'brand_name' => self::nullableString($row['brand_name'] ?? null),
            'category_path' => self::nullableString($row['category_name'] ?? null),
            'category_id' => (isset($row['category_id']) && (int)$row['category_id'] > 0)
                ? (int)$row['category_id']
                : null,
        ];
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

    private static function resolveAvailability(
        array $product,
        int $productId,
        int $attributeId,
        int $shopId
    ): array {
        $stock = null;
        $stockCandidates = [
            $product['quantity_available'] ?? null,
            $product['stock_quantity'] ?? null,
            $product['quantity'] ?? null,
            $product['available_quantity'] ?? null,
        ];
        foreach ($stockCandidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }
            $stock = (int)$candidate;
            break;
        }

        if ($stock === null && $productId > 0 && class_exists(StockAvailable::class)) {
            try {
                $stock = (int)StockAvailable::getQuantityAvailableByProduct(
                    $productId,
                    $attributeId,
                    $shopId
                );
            } catch (\Throwable $e) {
                $stock = null;
            }
        }

        $canOrderOutOfStock = null;
        if (array_key_exists('out_of_stock', $product) && class_exists(Product::class)) {
            try {
                $canOrderOutOfStock = Product::isAvailableWhenOutOfStock(
                    (int)$product['out_of_stock']
                );
            } catch (\Throwable $e) {
                $canOrderOutOfStock = null;
            }
        }

        $inStock = $stock !== null ? ($stock > 0) : null;
        if ($inStock === false && $canOrderOutOfStock === true) {
            $inStock = true;
        }
        if (array_key_exists('active', $product) && !(bool)$product['active']) {
            $inStock = false;
        }
        $availability = 'unknown';
        if ($inStock === true) {
            $availability = 'in_stock';
        } elseif ($inStock === false) {
            $availability = 'out_of_stock';
        }

        return [
            'availability' => $availability,
            'in_stock' => $inStock,
            'stock' => $stock,
        ];
    }

    private static function generateCartUid(Cart $cart, string $externalShopId): string
    {
        return sha1(
            $externalShopId.'-'.$cart->id.'-'.$cart->date_add
        );
    }

    private static function resolveShopId(Cart $cart, ?Context $context): int
    {
        if (
            $context
            && isset($context->shop)
            && $context->shop
            && isset($context->shop->id)
            && (int)$context->shop->id > 0
        ) {
            return (int)$context->shop->id;
        }

        if (isset($cart->id_shop) && (int)$cart->id_shop > 0) {
            return (int)$cart->id_shop;
        }

        return 1;
    }

    private static function nullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string)$value);
        return $value !== '' ? $value : null;
    }

    private static function resolveLangId(?Context $context): int
    {
        if (
            $context
            && isset($context->language)
            && $context->language
            && isset($context->language->id)
        ) {
            return (int)$context->language->id;
        }

        return (int) Configuration::get('PS_LANG_DEFAULT');
    }

    private static function resolveLocale(?Context $context): string
    {
        if (
            $context
            && isset($context->language)
            && $context->language
            && !empty($context->language->locale)
        ) {
            return (string)$context->language->locale;
        }

        return 'fr-FR';
    }

    private static function normalizeLanguageCode(?string $value): ?string
    {
        $raw = strtolower(str_replace('_', '-', trim((string)$value)));
        if ($raw === '') {
            return null;
        }

        $base = explode('-', $raw, 2)[0];
        if (in_array($base, ['fr', 'en', 'es', 'it', 'de'], true)) {
            return $base;
        }

        return null;
    }

    private static function resolveLanguageCode(?Context $context): string
    {
        $language = ($context && isset($context->language) && $context->language)
            ? $context->language
            : null;

        if ($language) {
            foreach (['iso_code', 'language_code', 'locale'] as $field) {
                if (!empty($language->{$field})) {
                    $normalized = self::normalizeLanguageCode((string)$language->{$field});
                    if ($normalized !== null) {
                        return $normalized;
                    }
                }
            }
        }

        $normalizedLocale = self::normalizeLanguageCode(self::resolveLocale($context));
        return $normalizedLocale !== null ? $normalizedLocale : 'en';
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

    private static function loadShopConfig(int $shopId): array
    {
        static $configByShop = [];

        if (!isset($configByShop[$shopId])) {
            $rawMaxDiscount = Configuration::get('NC_MAX_DISCOUNT_PERCENT');
            $maxDiscountPercent = $rawMaxDiscount !== false
                ? (float)$rawMaxDiscount
                : 20.0;

            $configByShop[$shopId] = [
                'recovery_enabled' => (bool) Configuration::get('NC_RECOVERY_ENABLED'),
                'allow_discount'   => (bool) Configuration::get('NC_ENABLE_DISCOUNT'),
                'min_cart_total'   => round((float) Configuration::get('NC_MIN_CART_TOTAL'), 2),
                'allow_guest'      => (bool) Configuration::get('NC_ALLOW_GUEST'),
                'no_discount_max'  => round((float) Configuration::get('NC_NO_DISCOUNT_MAX'), 2),
                'discount_5_min'   => round((float) Configuration::get('NC_DISCOUNT_5_MIN'), 2),
                'discount_5_max'   => round((float) Configuration::get('NC_DISCOUNT_5_MAX'), 2),
                'discount_10_min'  => round((float) Configuration::get('NC_DISCOUNT_10_MIN'), 2),
                'max_discount_percent' => round($maxDiscountPercent, 2),
                'external_shop_id' => (string) Configuration::get('NC_SHOP_EXTERNAL_ID'),
            ];
        }

        return $configByShop[$shopId];
    }

    private static function buildCustomerData(Cart $cart, string $locale, string $languageCode): array
    {
        $customerData = [
            'id'         => null,
            'email'      => null,
            'is_guest'   => true,
            'first_name' => null,
            'last_name'  => null,
            'phone'      => null,
            'locale'     => $locale,
            'language'   => $languageCode,
        ];

        $customerData['phone'] = self::resolveCustomerPhone($cart);

        if (!$cart->id_customer) {
            return $customerData;
        }

        try {
            $customer = new Customer((int)$cart->id_customer);

            if ($customer->id) {
                return [
                    'id'         => (int)$customer->id,
                    'email'      => (string)$customer->email,
                    'is_guest'   => (bool)$customer->is_guest,
                    'first_name' => (string)$customer->firstname,
                    'last_name'  => (string)$customer->lastname,
                    'phone'      => $customerData['phone'],
                    'locale'     => $locale,
                    'language'   => $languageCode,
                    'email_marketing_opt_in' => isset($customer->newsletter)
                        ? (bool)$customer->newsletter
                        : null,
                    'email_marketing_opt_in_source' => isset($customer->newsletter)
                        ? 'prestashop_customer_newsletter'
                        : null,
                    'email_marketing_opt_in_recorded_at' => isset($customer->newsletter)
                        ? (self::nullableString($customer->date_upd ?? null) ?: gmdate('c'))
                        : null,
                ];
            }
        } catch (\Throwable $e) {
        }
        return $customerData;
    }

    private static function resolveCustomerPhone(Cart $cart): ?string
    {
        $addressId = 0;

        if (isset($cart->id_address_delivery) && (int)$cart->id_address_delivery > 0) {
            $addressId = (int)$cart->id_address_delivery;
        } elseif (isset($cart->id_address_invoice) && (int)$cart->id_address_invoice > 0) {
            $addressId = (int)$cart->id_address_invoice;
        }

        if ($addressId <= 0) {
            return null;
        }

        try {
            $address = new Address($addressId);
            if (!(int)$address->id) {
                return null;
            }

            $phone = self::nullableString($address->phone_mobile ?? null);
            if ($phone === null) {
                $phone = self::nullableString($address->phone ?? null);
            }

            return $phone;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function buildRuntimeContext(?Context $context, ?string $languageCode = null): array
    {
        $shopTimezone = Configuration::get('PS_TIMEZONE') ?: 'UTC';
        $shopLocale = self::resolveLocale($context);
        $shopLanguage = $languageCode ?: self::resolveLanguageCode($context);
        $primaryColor = self::resolveThemePrimaryColor($context);

        try {
            $dt = new \DateTime('now', new \DateTimeZone($shopTimezone));
            $shopLocalHour = (int)$dt->format('G');
        } catch (\Throwable $e) {
            $shopLocalHour = (int)gmdate('G');
        }

        $currencyPrecision = null;
        try {
            if (isset($context->currency) && is_object($context->currency)) {
                if (isset($context->currency->precision) && $context->currency->precision !== null) {
                    $currencyPrecision = (int)$context->currency->precision;
                } elseif (isset($context->currency->decimals)) {
                    $currencyPrecision = (bool)$context->currency->decimals ? 2 : 0;
                }
            }
        } catch (\Throwable $e) {
            $currencyPrecision = null;
        }

        if ($currencyPrecision === null) {
            $currencyPrecision = 2;
        }

        $currencyCode = self::resolveCurrencyCode($context);

        $runtimeContext = [
            'shop_timezone' => $shopTimezone,
            'shop_local_hour' => $shopLocalHour,
            'currency_precision' => $currencyPrecision,
            'shop_locale' => $shopLocale,
            'shop_language' => $shopLanguage,
            'currency_code' => $currencyCode,
        ];
        if ($primaryColor !== null) {
            $runtimeContext['primary_color'] = $primaryColor;
            $runtimeContext['theme_palette'] = ['primary_color' => $primaryColor];
        }

        return $runtimeContext;
    }

    public static function resolveThemePrimaryColor(?Context $context): ?string
    {
        $themeName = 'classic';
        try {
            if (
                $context &&
                isset($context->shop) &&
                is_object($context->shop)
            ) {
                $candidate = trim((string)($context->shop->theme_name ?? ''));
                if ($candidate !== '') {
                    $themeName = $candidate;
                }
            }
        } catch (\Throwable $e) {
        }

        $cssCandidates = [
            _PS_ROOT_DIR_ . '/themes/' . $themeName . '/assets/css/custom.css',
            _PS_ROOT_DIR_ . '/themes/' . $themeName . '/assets/css/theme.css',
        ];

        foreach (array_values(array_unique($cssCandidates)) as $path) {
            $primaryColor = self::extractPrimaryColorFromCss(self::readCssFile($path));
            if ($primaryColor !== null) {
                return $primaryColor;
            }
        }

        return null;
    }

    private static function readCssFile(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            return '';
        }

        $contents = @file_get_contents($path, false, null, 0, 250000);
        return is_string($contents) ? $contents : '';
    }

    private static function extractPrimaryColorFromCss(string $css): ?string
    {
        if ($css === '') {
            return null;
        }

        $patterns = [
            '/--(?:color|theme|brand|button)-primary(?:-[a-z0-9_-]+)?\s*:\s*(#[0-9a-fA-F]{3,6})/i',
            '/\.btn-primary(?:\s*,[^{]+)?\{[^}]*background(?:-color)?\s*:\s*(#[0-9a-fA-F]{3,6})/is',
            '/\.btn-primary:hover\{[^}]*background(?:-color)?\s*:\s*(#[0-9a-fA-F]{3,6})/is',
        ];

        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $css, $matches)) {
                continue;
            }

            $normalized = self::normalizeHexColor($matches[1] ?? null);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private static function normalizeHexColor(?string $value): ?string
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }
        if ($raw[0] !== '#') {
            $raw = '#' . $raw;
        }
        if (preg_match('/^#[0-9a-fA-F]{3}$/', $raw)) {
            $raw = '#' . str_repeat($raw[1], 2) . str_repeat($raw[2], 2) . str_repeat($raw[3], 2);
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $raw)) {
            return null;
        }

        return strtoupper($raw);
    }

    private static function resolveCurrencyCode(?Context $context): ?string
    {
        try {
            if (isset($context->currency) && is_object($context->currency)) {
                $iso = strtoupper(trim((string)($context->currency->iso_code ?? '')));
                if ($iso !== '') {
                    return $iso;
                }
            }
        } catch (\Throwable $e) {
        }

        try {
            $currencyId = (int)Configuration::get('PS_CURRENCY_DEFAULT');
            if ($currencyId > 0) {
                $currency = new Currency($currencyId);
                if ($currency->id) {
                    $iso = strtoupper(trim((string)$currency->iso_code));
                    if ($iso !== '') {
                        return $iso;
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        return null;
    }

    private static function buildRulesPayload(array $config): array
    {
        return [
            'recovery_enabled' => $config['recovery_enabled'],
            'allow_discount'   => $config['allow_discount'],
            'min_cart_total'   => $config['min_cart_total'],
            'allow_guest'      => $config['allow_guest'],
            'no_discount_max'  => $config['no_discount_max'],
            'discount_5_min'   => $config['discount_5_min'],
            'discount_5_max'   => $config['discount_5_max'],
            'discount_10_min'  => $config['discount_10_min'],
            'max_discount_percent' => $config['max_discount_percent'],
        ];
    }

    private static function buildSessionMeta(): array
    {
        $requestUri = '';
        $referrer = '';
        $userAgent = '';

        if (isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI'])) {
            $requestUri = trim($_SERVER['REQUEST_URI']);
        }
        if (isset($_SERVER['HTTP_REFERER']) && is_string($_SERVER['HTTP_REFERER'])) {
            $referrer = trim($_SERVER['HTTP_REFERER']);
        }
        if (isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT'])) {
            $userAgent = trim($_SERVER['HTTP_USER_AGENT']);
        }

        if (strlen($requestUri) > 1024) {
            $requestUri = substr($requestUri, 0, 1024);
        }
        if (strlen($referrer) > 1024) {
            $referrer = substr($referrer, 0, 1024);
        }
        if (strlen($userAgent) > 1024) {
            $userAgent = substr($userAgent, 0, 1024);
        }

        return [
            'source_page' => $requestUri !== '' ? $requestUri : null,
            'referrer' => $referrer !== '' ? $referrer : null,
            'user_agent' => $userAgent !== '' ? $userAgent : null,
        ];
    }
}

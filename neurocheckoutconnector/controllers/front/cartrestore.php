<?php

use NeuroCheckout\Security\CartFingerprintService;
use NeuroCheckout\Security\RecoveryLinkService;
use NeuroCheckout\Security\RequestSecurityValidator;
use NeuroCheckout\Http\RequestBodyDecoder;

class NeuroCheckoutConnectorCartrestoreModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $auth = false;
    public $ajax = true;

    private const MAX_TIME_DRIFT = 120;
    private const NONCE_TTL = 120;
    private const MAX_REQUEST_BODY_BYTES = 524288;
    private const MAX_REQUEST_DECOMPRESSED_BYTES = 2097152;

    public function initContent()
    {
        parent::initContent();
        header('Content-Type: application/json');

        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            $this->jsonExit(405, false, 'Method not allowed');
        }

        try {
            $this->ensureCouponTable();

            $rawRequest = RequestBodyDecoder::readRaw(self::MAX_REQUEST_BODY_BYTES);
            if (empty($rawRequest['success'])) {
                $this->jsonExit(
                    (int) ($rawRequest['status'] ?? 400),
                    false,
                    (string) ($rawRequest['error'] ?? 'Unable to read request body')
                );
            }
            $rawBody = (string) ($rawRequest['body'] ?? '');
            $security = (new RequestSecurityValidator())->validateSignedPost($rawBody, 'cartrestore', false);
            if (empty($security['success'])) {
                $this->jsonExit(
                    (int) ($security['status'] ?? 403),
                    false,
                    (string) ($security['error'] ?? 'Forbidden')
                );
            }

            $decoded = RequestBodyDecoder::decodeJson(
                $rawBody,
                $this->headerValue('Content-Encoding'),
                self::MAX_REQUEST_DECOMPRESSED_BYTES
            );
            if (empty($decoded['success'])) {
                $this->jsonExit(
                    (int) ($decoded['status'] ?? 422),
                    false,
                    (string) ($decoded['error'] ?? 'Invalid JSON payload')
                );
            }
            $payload = is_array($decoded['payload'] ?? null) ? $decoded['payload'] : [];

            $cartId = (int)($payload['cart_id'] ?? 0);
            $cartUid = trim((string)($payload['cart_uid'] ?? ''));
            $customerEmail = trim((string)($payload['customer_email'] ?? ''));
            $customerId = (int)($payload['customer_id'] ?? 0);
            $targetUrl = trim((string)($payload['target_url'] ?? ''));
            $products = $this->normalizeProducts($payload['products'] ?? []);
            $sessionOnly = $this->isTruthy($payload['session_only'] ?? false);

            if ($sessionOnly) {
                $targetUrl = $this->sanitizeSameShopTargetUrl($targetUrl);
                if ($customerEmail === '' || $targetUrl === '') {
                    $this->jsonExit(422, false, 'Missing required fields');
                }

                $resolvedCustomerId = $this->resolveCustomerId($customerId, $customerEmail);
                if ($resolvedCustomerId <= 0 || !$this->customerEmailMatches($resolvedCustomerId, $customerEmail)) {
                    $this->jsonExit(422, false, 'Customer not found');
                }

                $recoveryUrl = $this->buildCustomerSessionRecoveryUrl(
                    (string)$resolvedCustomerId,
                    $customerEmail,
                    $targetUrl
                );

                $this->jsonExit(200, true, null, [
                    'recovery_url' => $recoveryUrl,
                    'restored_cart_id' => null,
                    'cart_uid' => $cartUid,
                    'recreated' => false,
                    'products_added' => 0,
                    'coupon_preserved' => false,
                    'session_only' => true,
                ]);
            }

            if ($customerEmail === '' || empty($products)) {
                $this->jsonExit(422, false, 'Missing required fields');
            }

            $resolved = $this->resolveRecoverableCart(
                $cartId,
                $customerEmail,
                $customerId,
                $products
            );

            if (!$resolved || !($resolved['cart'] instanceof Cart)) {
                $this->jsonExit(500, false, 'Unable to restore cart');
            }

            /** @var Cart $restoredCart */
            $restoredCart = $resolved['cart'];
            $recreated = (bool)($resolved['recreated'] ?? false);
            $productsAdded = (int)($resolved['products_added'] ?? 0);
            $cartFingerprint = (new CartFingerprintService())->fromCart($restoredCart);

            $couponCode = $this->extractCouponCodeFromTarget($targetUrl);
            if ($couponCode !== '' && $recreated) {
                $couponReady = $this->cloneCouponMetaForRecoveredCart(
                    $couponCode,
                    $customerEmail,
                    (string)$restoredCart->id,
                    $cartFingerprint
                );
                if (!$couponReady) {
                    $couponCode = '';
                }
            }

            $recoveryUrl = $this->buildRecoveryUrl(
                (string)$restoredCart->id,
                $customerEmail,
                $couponCode,
                $cartFingerprint
            );
            $recoveryUrl = $this->appendTargetUrlToRecoveryUrl($recoveryUrl, $targetUrl);

            $this->jsonExit(200, true, null, [
                'recovery_url' => $recoveryUrl,
                'restored_cart_id' => (string)$restoredCart->id,
                'cart_uid' => $cartUid,
                'recreated' => $recreated,
                'products_added' => $productsAdded,
                'coupon_preserved' => $couponCode !== '',
            ]);
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('[NC] Cart restore endpoint fatal: ' . $e->getMessage(), 3);
            $this->jsonExit(500, false, 'Internal error');
        }
    }

    private function headerValue(string $headerName): string
    {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));
        return (string)($_SERVER[$serverKey] ?? '');
    }

    private function normalizeProducts($products): array
    {
        if (!is_array($products)) {
            return [];
        }

        $normalized = [];
        foreach ($products as $item) {
            if (!is_array($item)) {
                continue;
            }

            $productId = (int)($item['product_id'] ?? $item['id_product'] ?? 0);
            $attributeId = (int)($item['attribute_id'] ?? $item['id_product_attribute'] ?? 0);
            $quantity = (int)($item['quantity'] ?? $item['cart_quantity'] ?? 0);

            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }

            $normalized[] = [
                'product_id' => $productId,
                'attribute_id' => max(0, $attributeId),
                'quantity' => $quantity,
            ];
        }

        return $normalized;
    }

    private function isTruthy($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function resolveRecoverableCart(
        int $sourceCartId,
        string $customerEmail,
        int $customerId,
        array $products
    ): array {
        $existing = $this->tryLoadExistingCart($sourceCartId, $customerEmail);
        if ($existing instanceof Cart) {
            return [
                'cart' => $existing,
                'recreated' => false,
                'products_added' => 0,
            ];
        }

        $recreatedCart = $this->createCartSkeleton($customerEmail, $customerId);
        $productsAdded = $this->addProductsToCart($recreatedCart, $products);

        if ($productsAdded <= 0 || !$this->cartContainsProducts($recreatedCart)) {
            if ((int)$recreatedCart->id > 0) {
                $recreatedCart->delete();
            }
            throw new RuntimeException('Cart recreation produced an empty cart');
        }

        return [
            'cart' => new Cart((int)$recreatedCart->id),
            'recreated' => true,
            'products_added' => $productsAdded,
        ];
    }

    private function tryLoadExistingCart(int $cartId, string $customerEmail): ?Cart
    {
        if ($cartId <= 0) {
            return null;
        }

        $cart = new Cart($cartId);
        if (!(int)$cart->id) {
            return null;
        }

        if (!$this->emailMatchesCart($cart, $customerEmail)) {
            return null;
        }

        if (!$this->cartContainsProducts($cart)) {
            return null;
        }

        return $cart;
    }

    private function createCartSkeleton(string $customerEmail, int $customerId): Cart
    {
        $context = Context::getContext();

        $shopId = (int)($context->shop->id ?? 0);
        if ($shopId <= 0) {
            $shopId = (int)Configuration::get('PS_SHOP_DEFAULT');
        }

        $langId = (int)($context->language->id ?? 0);
        if ($langId <= 0) {
            $langId = (int)Configuration::get('PS_LANG_DEFAULT');
        }

        $currencyId = (int)($context->currency->id ?? 0);
        if ($currencyId <= 0) {
            $currencyId = (int)Configuration::get('PS_CURRENCY_DEFAULT');
        }

        $resolvedCustomerId = $this->resolveCustomerId($customerId, $customerEmail);

        $cart = new Cart();
        $cart->id_shop = max(1, $shopId);
        $cart->id_lang = max(1, $langId);
        $cart->id_currency = max(1, $currencyId);
        $cart->id_customer = max(0, $resolvedCustomerId);
        $cart->id_guest = 0;
        $cart->id_address_delivery = 0;
        $cart->id_address_invoice = 0;

        if ($resolvedCustomerId > 0) {
            $customer = new Customer($resolvedCustomerId);
            if ((int)$customer->id > 0) {
                $cart->secure_key = (string)$customer->secure_key;
                $addressId = $this->resolveCustomerAddressId($resolvedCustomerId);
                if ($addressId > 0) {
                    $cart->id_address_delivery = $addressId;
                    $cart->id_address_invoice = $addressId;
                }
            }
        }

        if (!$cart->add()) {
            throw new RuntimeException('Unable to create cart shell');
        }

        return $cart;
    }

    private function resolveCustomerId(int $customerId, string $customerEmail): int
    {
        if ($customerId > 0) {
            $candidate = new Customer($customerId);
            if ((int)$candidate->id > 0) {
                return (int)$candidate->id;
            }
        }

        $normalizedEmail = strtolower(trim($customerEmail));
        if ($normalizedEmail === '') {
            return 0;
        }

        $query = new DbQuery();
        $query->select('id_customer');
        $query->from('customer');
        $query->where('lower(email) = \'' . pSQL($normalizedEmail) . '\'');
        $query->orderBy('id_customer DESC');

        return (int)Db::getInstance()->getValue($query);
    }

    private function customerEmailMatches(int $customerId, string $customerEmail): bool
    {
        if ($customerId <= 0) {
            return false;
        }

        $normalizedEmail = strtolower(trim($customerEmail));
        if ($normalizedEmail === '') {
            return false;
        }

        $customer = new Customer($customerId);
        if (!(int)$customer->id) {
            return false;
        }

        return hash_equals($normalizedEmail, strtolower((string)$customer->email));
    }

    private function resolveCustomerAddressId(int $customerId): int
    {
        if ($customerId <= 0) {
            return 0;
        }

        $query = new DbQuery();
        $query->select('id_address');
        $query->from('address');
        $query->where('id_customer = ' . (int)$customerId);
        $query->where('deleted = 0');
        $query->orderBy('id_address DESC');

        return (int)Db::getInstance()->getValue($query);
    }

    private function addProductsToCart(Cart $cart, array $products): int
    {
        $addedLines = 0;

        foreach ($products as $item) {
            $productId = (int)($item['product_id'] ?? 0);
            $attributeId = (int)($item['attribute_id'] ?? 0);
            $quantity = (int)($item['quantity'] ?? 0);

            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }

            try {
                $product = new Product(
                    $productId,
                    false,
                    (int)$cart->id_lang,
                    (int)$cart->id_shop
                );

                if (!(int)$product->id || !(bool)$product->active) {
                    continue;
                }

                $ok = $cart->updateQty(
                    $quantity,
                    $productId,
                    $attributeId > 0 ? $attributeId : null,
                    false,
                    'up',
                    (int)$cart->id_address_delivery
                );

                if ($ok) {
                    $addedLines++;
                }
            } catch (\Throwable $e) {
                PrestaShopLogger::addLog(
                    '[NC] Cart restore add product failed (product=' . $productId . '): ' . $e->getMessage(),
                    2
                );
            }
        }

        $cart->update();

        return $addedLines;
    }

    private function emailMatchesCart(Cart $cart, string $email): bool
    {
        $normalized = strtolower(trim($email));
        if ($normalized === '') {
            return false;
        }

        $customerId = (int)$cart->id_customer;
        if ($customerId <= 0) {
            return true;
        }

        $customer = new Customer($customerId);
        if (!(int)$customer->id) {
            return false;
        }

        return hash_equals($normalized, strtolower((string)$customer->email));
    }

    private function cartContainsProducts(Cart $cart): bool
    {
        try {
            $products = $cart->getProducts();
            return is_array($products) && count($products) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function extractCouponCodeFromTarget(string $targetUrl): string
    {
        if ($targetUrl === '') {
            return '';
        }

        $parts = parse_url($targetUrl);
        if (!is_array($parts) || empty($parts['query'])) {
            return '';
        }

        parse_str((string)$parts['query'], $params);
        return trim((string)($params['coupon'] ?? ''));
    }

    private function cloneCouponMetaForRecoveredCart(
        string $couponCode,
        string $customerEmail,
        string $newCartId,
        string $cartFingerprint = ''
    ): bool {
        $code = trim($couponCode);
        $email = strtolower(trim($customerEmail));
        if ($code === '' || $email === '' || $newCartId === '') {
            return false;
        }

        $exists = (int)Db::getInstance()->getValue(
            'SELECT id
             FROM `' . _DB_PREFIX_ . 'neurocheckout_coupon`
             WHERE cart_id = \'' . pSQL($newCartId) . '\'
               AND coupon_code = \'' . pSQL($code) . '\''
        );
        if ($exists > 0) {
            return true;
        }

        $query = new DbQuery();
        $query->select('*');
        $query->from('neurocheckout_coupon');
        $query->where('coupon_code = \'' . pSQL($code) . '\'');
        $query->where('lower(customer_email) = \'' . pSQL($email) . '\'');
        $query->orderBy('id DESC');

        $row = Db::getInstance()->getRow($query);
        if (!$row) {
            return false;
        }

        $expiresAt = trim((string)($row['expires_at'] ?? ''));
        if ($expiresAt !== '' && strtotime($expiresAt) < time()) {
            return false;
        }

        $requestUid = 'restore:' . sha1($code . '|' . $newCartId . '|' . $email . '|' . time());
        $shopId = (int)($row['shop_id'] ?? ($this->context->shop->id ?? 1));

        return (bool)Db::getInstance()->insert(
            'neurocheckout_coupon',
            [
                'shop_id' => max(1, $shopId),
                'request_uid' => pSQL($requestUid),
                'decision_id' => pSQL((string)($row['decision_id'] ?? 'restore')),
                'action_id' => pSQL((string)($row['action_id'] ?? 'restore')),
                'cart_id' => pSQL($newCartId),
                'customer_email' => pSQL($email),
                'cart_rule_id' => (int)($row['cart_rule_id'] ?? 0),
                'coupon_code' => pSQL($code),
                'discount_percent' => (float)($row['discount_percent'] ?? 0),
                'cart_fingerprint' => $cartFingerprint !== '' ? pSQL($cartFingerprint) : null,
                'recovery_url' => null,
                'expires_at' => pSQL($expiresAt !== '' ? $expiresAt : date('Y-m-d H:i:s', time() + 3600)),
                'created_at' => date('Y-m-d H:i:s'),
            ]
        );
    }

    private function buildRecoveryUrl(
        string $cartId,
        string $customerEmail,
        string $couponCode = '',
        string $cartFingerprint = ''
    ): string {
        $service = new RecoveryLinkService();

        return $service->build($cartId, $customerEmail, $couponCode, $cartFingerprint);
    }

    private function buildCustomerSessionRecoveryUrl(
        string $customerId,
        string $customerEmail,
        string $targetUrl
    ): string {
        $service = new RecoveryLinkService();

        return $service->buildCustomerSession($customerId, $customerEmail, $targetUrl);
    }

    private function appendTargetUrlToRecoveryUrl(string $recoveryUrl, string $targetUrl): string
    {
        $safeTargetUrl = $this->sanitizeSameShopTargetUrl($targetUrl);
        if ($safeTargetUrl === '') {
            return $recoveryUrl;
        }

        $separator = strpos($recoveryUrl, '?') === false ? '?' : '&';
        return $recoveryUrl . $separator . http_build_query(['u' => $safeTargetUrl]);
    }

    private function sanitizeSameShopTargetUrl(string $targetUrl): string
    {
        $candidate = trim($targetUrl);
        if ($candidate === '') {
            return '';
        }

        $parsedTarget = parse_url($candidate);
        if (!is_array($parsedTarget)) {
            return '';
        }

        if (isset($parsedTarget['user']) || isset($parsedTarget['pass'])) {
            return '';
        }

        $scheme = strtolower((string)($parsedTarget['scheme'] ?? ''));
        $targetHost = strtolower((string)($parsedTarget['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $targetHost === '') {
            return '';
        }

        $shopUrl = Tools::getShopDomainSsl(true, true);
        $parsedShop = parse_url($shopUrl);
        $shopHost = strtolower((string)($parsedShop['host'] ?? ''));
        $shopScheme = strtolower((string)($parsedShop['scheme'] ?? ''));
        $targetPort = (int)($parsedTarget['port'] ?? ($scheme === 'https' ? 443 : 80));
        $shopPort = (int)($parsedShop['port'] ?? ($shopScheme === 'https' ? 443 : 80));

        return (
            $shopHost !== ''
            && hash_equals($shopHost, $targetHost)
            && hash_equals($shopScheme, $scheme)
            && $shopPort === $targetPort
        ) ? $candidate : '';
    }

    private function ensureCouponTable(): void
    {
        Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'neurocheckout_coupon` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `shop_id` INT UNSIGNED NOT NULL,
                `request_uid` VARCHAR(120) NOT NULL,
                `decision_id` VARCHAR(64) NOT NULL,
                `action_id` VARCHAR(64) DEFAULT NULL,
                `cart_id` VARCHAR(64) NOT NULL,
                `customer_email` VARCHAR(255) NOT NULL,
                `cart_rule_id` INT UNSIGNED NOT NULL,
                `coupon_code` VARCHAR(64) NOT NULL,
                `discount_percent` DECIMAL(5,2) NOT NULL,
                `cart_fingerprint` VARCHAR(64) DEFAULT NULL,
                `recovery_url` TEXT DEFAULT NULL,
                `expires_at` DATETIME NOT NULL,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_shop_request_uid` (`shop_id`, `request_uid`),
                KEY `idx_shop_decision` (`shop_id`, `decision_id`),
                KEY `idx_coupon_code` (`coupon_code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $columns = Db::getInstance()->executeS('SHOW COLUMNS FROM `' . _DB_PREFIX_ . 'neurocheckout_coupon` LIKE "cart_fingerprint"');
        if (!is_array($columns) || count($columns) === 0) {
            Db::getInstance()->execute(
                'ALTER TABLE `' . _DB_PREFIX_ . 'neurocheckout_coupon` ADD `cart_fingerprint` VARCHAR(64) DEFAULT NULL AFTER `discount_percent`'
            );
        }
    }

    private function jsonExit(
        int $statusCode,
        bool $success,
        ?string $error = null,
        ?array $data = null
    ): void {
        http_response_code($statusCode);
        echo json_encode([
            'success' => $success,
            'error' => $error,
            'recovery_url' => $data['recovery_url'] ?? null,
            'restored_cart_id' => $data['restored_cart_id'] ?? null,
            'cart_uid' => $data['cart_uid'] ?? null,
            'recreated' => $data['recreated'] ?? null,
            'products_added' => $data['products_added'] ?? null,
            'coupon_preserved' => $data['coupon_preserved'] ?? null,
            'session_only' => $data['session_only'] ?? null,
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
        exit;
    }
}

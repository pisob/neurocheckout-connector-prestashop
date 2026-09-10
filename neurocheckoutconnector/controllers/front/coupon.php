<?php

use NeuroCheckout\Security\CartFingerprintService;
use NeuroCheckout\Security\RecoveryLinkService;
use NeuroCheckout\Security\RequestSecurityValidator;
use NeuroCheckout\Http\RequestBodyDecoder;

class NeuroCheckoutConnectorCouponModuleFrontController extends ModuleFrontController
{
    public $ssl  = true;
    public $auth = false;
    public $ajax = true;

    private const MAX_TIME_DRIFT = 120;
    private const NONCE_TTL = 120;
    private const DEFAULT_TTL_HOURS = 48;
    private const MAX_TTL_HOURS = 168;
    private const MAX_REQUEST_BODY_BYTES = 131072;
    private const MAX_REQUEST_DECOMPRESSED_BYTES = 524288;

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
            $security = (new RequestSecurityValidator())->validateSignedPost($rawBody, 'coupon', false);
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

            $requestUid = trim((string)($payload['request_uid'] ?? ''));
            $decisionId = trim((string)($payload['decision_id'] ?? ''));
            $actionId = trim((string)($payload['action_id'] ?? ''));
            $cartId = trim((string)($payload['cart_id'] ?? ''));
            $customerEmail = trim((string)($payload['customer_email'] ?? ''));
            $discountPercent = (float)($payload['discount_percent'] ?? 0);
            $ttlHours = (int)($payload['ttl_hours'] ?? self::DEFAULT_TTL_HOURS);

            if ($requestUid === '') {
                $requestUid = trim($decisionId . ':' . $actionId);
            }
            if ($requestUid === '' || $cartId === '' || $customerEmail === '') {
                $this->jsonExit(422, false, 'Missing required fields');
            }

            $discountPercent = min(max($discountPercent, 0.0), 100.0);
            $ttlHours = min(max($ttlHours, 1), self::MAX_TTL_HOURS);
            $shopId = (int)($this->context->shop->id ?? 1);
            $cartFingerprint = $this->resolveCartFingerprint((int) $cartId);

            $existing = $this->getExistingCoupon($shopId, $requestUid);
            if ($existing) {
                $this->jsonExit(200, true, null, $existing);
            }

            $cart = new Cart((int)$cartId);
            if (!$cart->id) {
                $this->jsonExit(404, false, 'Cart not found');
            }

            if ($discountPercent <= 0.0) {
                $this->jsonExit(200, true, null, [
                    'coupon_code' => null,
                    'discount_percent' => 0,
                    'recovery_url' => $this->buildRecoveryUrl((string)$cart->id, $customerEmail, '', $cartFingerprint),
                    'expires_at' => null,
                ]);
            }

            $customerId = (int)$cart->id_customer;
            if ($customerId <= 0 && $customerEmail !== '') {
                $query = new DbQuery();
                $query->select('id_customer');
                $query->from('customer');
                $query->where('email = \'' . pSQL($customerEmail) . '\'');
                $query->orderBy('id_customer DESC');

                $customerId = (int)Db::getInstance()->getValue($query);
            }

            $couponCode = $this->generateCouponCode((int)$cart->id);
            $expiresAt = date('Y-m-d H:i:s', time() + ($ttlHours * 3600));
            $cartRuleId = $this->createCartRule(
                $couponCode,
                $discountPercent,
                $customerId,
                $expiresAt
            );
            $recoveryUrl = $this->buildRecoveryUrl((string)$cart->id, $customerEmail, $couponCode, $cartFingerprint);

            $this->persistCoupon(
                $shopId,
                $requestUid,
                $decisionId,
                $actionId,
                (string)$cart->id,
                $customerEmail,
                $cartRuleId,
                $couponCode,
                $discountPercent,
                $cartFingerprint,
                $recoveryUrl,
                $expiresAt
            );

            $this->jsonExit(200, true, null, [
                'coupon_code' => $couponCode,
                'discount_percent' => round($discountPercent, 2),
                'recovery_url' => $recoveryUrl,
                'expires_at' => $expiresAt,
            ]);
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('[NC] Coupon endpoint fatal: ' . $e->getMessage(), 3);
            $this->jsonExit(500, false, 'Internal error');
        }
    }

    private function headerValue(string $headerName): string
    {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));
        return (string)($_SERVER[$serverKey] ?? '');
    }

    private function getExistingCoupon(int $shopId, string $requestUid): ?array
    {
        $query = new DbQuery();
        $query->select('id, cart_id, customer_email, coupon_code, discount_percent, cart_fingerprint, recovery_url, expires_at');
        $query->from('neurocheckout_coupon');
        $query->where('shop_id=' . (int)$shopId);
        $query->where('request_uid = \'' . pSQL($requestUid) . '\'');

        $row = Db::getInstance()->getRow($query);

        if (!$row) {
            return null;
        }

        $cartId = (string)($row['cart_id'] ?? '');
        $customerEmail = (string)($row['customer_email'] ?? '');
        $couponCode = (string)($row['coupon_code'] ?? '');
        $cartFingerprint = trim((string)($row['cart_fingerprint'] ?? ''));
        $recoveryUrl = $this->buildRecoveryUrl($cartId, $customerEmail, $couponCode, $cartFingerprint);
        if ((int)($row['id'] ?? 0) > 0) {
            Db::getInstance()->update(
                'neurocheckout_coupon',
                ['recovery_url' => pSQL($recoveryUrl)],
                'id = ' . (int)$row['id']
            );
        }

        return [
            'coupon_code' => $couponCode,
            'discount_percent' => (float)$row['discount_percent'],
            'recovery_url' => $recoveryUrl,
            'expires_at' => (string)$row['expires_at'],
        ];
    }

    private function generateCouponCode(int $cartId): string
    {
        for ($i = 0; $i < 6; $i++) {
            $suffix = strtoupper(bin2hex(random_bytes(4)));
            $code = 'NC-' . $cartId . '-' . $suffix;

            $query = new DbQuery();
            $query->select('id_cart_rule');
            $query->from('cart_rule');
            $query->where('code = \'' . pSQL($code) . '\'');

            $exists = (int)Db::getInstance()->getValue($query);
            if ($exists <= 0) {
                return $code;
            }
        }

        throw new \RuntimeException('Cannot generate unique coupon code');
    }

    private function createCartRule(
        string $couponCode,
        float $discountPercent,
        int $customerId,
        string $expiresAt
    ): int {
        $cartRule = new CartRule();

        $names = [];
        foreach (Language::getLanguages(false) as $languageData) {
            $langId = (int)($languageData['id_lang'] ?? 0);
            if ($langId <= 0) {
                continue;
            }

            $locale = (string)($languageData['locale'] ?? ($languageData['language_code'] ?? ($languageData['iso_code'] ?? 'en')));
            $names[$langId] = \NeuroCheckout\I18n\ModuleTranslator::trans('coupon_name', [], $locale);
        }
        if (empty($names)) {
            $defaultLangId = (int) Configuration::get('PS_LANG_DEFAULT');
            $names[$defaultLangId > 0 ? $defaultLangId : 1] = \NeuroCheckout\I18n\ModuleTranslator::trans('coupon_name');
        }
        $cartRule->name = $names;
        $cartRule->description = \NeuroCheckout\I18n\ModuleTranslator::trans('coupon_description');
        $cartRule->code = $couponCode;
        $cartRule->id_customer = max(0, $customerId);
        $cartRule->quantity = 1;
        $cartRule->quantity_per_user = 1;
        $cartRule->priority = 1;
        $cartRule->partial_use = 0;
        $cartRule->active = 1;
        $cartRule->highlight = 0;
        $cartRule->date_from = date('Y-m-d H:i:s', time() - 60);
        $cartRule->date_to = $expiresAt;
        $cartRule->minimum_amount = 0;
        $cartRule->minimum_amount_tax = 1;
        $cartRule->minimum_amount_shipping = 0;
        $cartRule->country_restriction = 0;
        $cartRule->carrier_restriction = 0;
        $cartRule->group_restriction = 0;
        $cartRule->cart_rule_restriction = 0;
        $cartRule->product_restriction = 0;
        $cartRule->shop_restriction = 0;
        $cartRule->free_shipping = 0;
        $cartRule->reduction_percent = round($discountPercent, 2);
        $cartRule->reduction_amount = 0;
        $cartRule->reduction_tax = 1;
        $cartRule->reduction_product = 0;

        $currencyId = (int)Configuration::get('PS_CURRENCY_DEFAULT');
        if ($currencyId > 0) {
            $cartRule->minimum_amount_currency = $currencyId;
            $cartRule->reduction_currency = $currencyId;
        }

        if (!$cartRule->add()) {
            throw new \RuntimeException('CartRule creation failed');
        }

        return (int)$cartRule->id;
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

    private function persistCoupon(
        int $shopId,
        string $requestUid,
        string $decisionId,
        string $actionId,
        string $cartId,
        string $customerEmail,
        int $cartRuleId,
        string $couponCode,
        float $discountPercent,
        string $cartFingerprint,
        string $recoveryUrl,
        string $expiresAt
    ): void {
        Db::getInstance()->insert(
            'neurocheckout_coupon',
            [
                'shop_id' => (int)$shopId,
                'request_uid' => pSQL($requestUid),
                'decision_id' => pSQL($decisionId),
                'action_id' => pSQL($actionId),
                'cart_id' => pSQL($cartId),
                'customer_email' => pSQL($customerEmail),
                'cart_rule_id' => (int)$cartRuleId,
                'coupon_code' => pSQL($couponCode),
                'discount_percent' => (float)round($discountPercent, 2),
                'cart_fingerprint' => $cartFingerprint !== '' ? pSQL($cartFingerprint) : null,
                'recovery_url' => pSQL($recoveryUrl),
                'expires_at' => pSQL($expiresAt),
                'created_at' => date('Y-m-d H:i:s'),
            ]
        );
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

    private function resolveCartFingerprint(int $cartId): string
    {
        $cart = new Cart($cartId);
        if (!(int) $cart->id) {
            return '';
        }

        return (new CartFingerprintService())->fromCart($cart);
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
            'coupon_code' => $data['coupon_code'] ?? null,
            'discount_percent' => $data['discount_percent'] ?? null,
            'recovery_url' => $data['recovery_url'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
        exit;
    }
}

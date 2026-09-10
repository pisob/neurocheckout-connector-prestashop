<?php

use NeuroCheckout\Http\SecureHttpClient;
use NeuroCheckout\I18n\ModuleTranslator;
use NeuroCheckout\Security\SecretConfiguration;

class NeuroCheckoutConnectorApitestModuleFrontController extends ModuleFrontController
{
    public $ssl  = true;
    public $auth = false;
    public $ajax = true;

    private const MAX_TIME_DRIFT = 900;
    private const CRON_INTERVAL_SECONDS = 300;
    private const CRON_SETUP_STATUS_CONFIGURED = 'configured';
    private const CRON_SETUP_STATUS_MANUAL_REQUIRED = 'manual_required';

    public function initContent()
    {
        parent::initContent();

        header('Content-Type: application/json');

        $timestamp = (int) Tools::getValue('ts');
        $nonce = (string) Tools::getValue('nonce');
        $signature = (string) Tools::getValue('sig');

        if (!$timestamp || !$nonce || !$signature) {
            $this->jsonExit(false, 403, ModuleTranslator::trans('missing_signature_parameters'));
        }

        if (abs(time() - $timestamp) > self::MAX_TIME_DRIFT) {
            $this->jsonExit(false, 403, ModuleTranslator::trans('expired_signature'));
        }

        $secret = $this->ensureInternalSecret();
        if (!$secret) {
            $this->jsonExit(false, 500, ModuleTranslator::trans('internal_secret_missing'));
        }

        $expected = hash_hmac(
            'sha256',
            $timestamp . '.' . $nonce,
            $secret
        );

        if (!hash_equals($expected, $signature)) {
            $this->jsonExit(false, 403, ModuleTranslator::trans('invalid_signature'));
        }

        $endpoint = trim((string) Configuration::get('NC_API_ENDPOINT'));
        $apiKey = trim(SecretConfiguration::get('NC_API_KEY'));
        $shopExternalId = trim((string) Configuration::get('NC_SHOP_EXTERNAL_ID'));

        if (empty($endpoint) || empty($apiKey)) {
            $this->jsonExit(false, 500, ModuleTranslator::trans('api_configuration_incomplete'));
        }

        if (!$this->isIaConfigurationReady()) {
            $this->jsonExit(false, 422, ModuleTranslator::trans('ia_setup_required_popup'));
        }

        $hookDiagnostics = [
            'success' => true,
            'repaired' => [],
            'missing' => [],
        ];
        if ($this->module && method_exists($this->module, 'ensureRequiredRuntimeHooks')) {
            $hookDiagnostics = $this->module->ensureRequiredRuntimeHooks();
        }
        if (empty($hookDiagnostics['success'])) {
            $missingHooks = implode(', ', (array) ($hookDiagnostics['missing'] ?? []));
            $this->jsonExit(false, 500, 'Required PrestaShop hooks missing: ' . $missingHooks);
        }

        $payload = $this->buildApiTestPayload($shopExternalId);

        $start = microtime(true);

        try {
            $client = new SecureHttpClient();
            $result = $client->send($payload, [
                'is_test_event' => true,
                'is_api_test' => true,
            ]);

            $duration = (int) round((microtime(true) - $start) * 1000);
            $cronModeActivated = false;
            $cronIntervalMinutes = 0;
            $cronSetupResult = [
                'status' => '',
                'attempted' => false,
                'message' => '',
                'manual_command' => '',
            ];

            if ((bool) ($result['success'] ?? false)) {
                if ($this->module && method_exists($this->module, 'markApiTestValidationSuccess')) {
                    $this->module->markApiTestValidationSuccess();
                }
                $this->enableModuleCronMode();
                $cronModeActivated = true;
                $cronIntervalMinutes = (int) floor(self::CRON_INTERVAL_SECONDS / 60);
                $cronSetupResult = $this->ensureCronSetupAutoOnce($cronIntervalMinutes);
            }

            http_response_code($result['status'] ?? 500);

            $this->jsonExit(
                (bool) ($result['success'] ?? false),
                (int) ($result['status'] ?? 0),
                $result['error'] ?? null,
                $duration,
                $result['body'] ?? null,
                [
                    'cron_mode_activated' => $cronModeActivated,
                    'cron_interval_minutes' => $cronIntervalMinutes,
                    'cron_setup_status' => $cronSetupResult['status'],
                    'cron_setup_attempted' => $cronSetupResult['attempted'],
                    'cron_setup_message' => $cronSetupResult['message'],
                    'cron_setup_manual_command' => $cronSetupResult['manual_command'],
                    'hooks_repaired' => array_values((array) ($hookDiagnostics['repaired'] ?? [])),
                ]
            );
        } catch (\Throwable $e) {
            $this->jsonExit(false, 500, $e->getMessage());
        }
    }

    private function buildApiTestPayload(string $shopExternalId): array
    {
        $context = Context::getContext();
        $nowUtc = gmdate('c');

        $resolvedShopId = trim($shopExternalId);
        if ($resolvedShopId === '') {
            $resolvedShopId = (
                $context
                && isset($context->shop)
                && $context->shop
                && isset($context->shop->id)
            )
                ? (string) (int) $context->shop->id
                : 'ps_local_1';
        }

        $shopName = null;
        if (
            $context
            && isset($context->shop)
            && $context->shop
            && !empty($context->shop->name)
        ) {
            $shopName = trim((string) $context->shop->name);
            if ($shopName === '') {
                $shopName = null;
            }
        }

        $locale = 'fr-FR';
        if (
            $context
            && isset($context->language)
            && $context->language
            && !empty($context->language->locale)
        ) {
            $localeCandidate = trim((string) $context->language->locale);
            if ($localeCandidate !== '') {
                $locale = $localeCandidate;
            }
        }

        $currencyCode = null;
        $currencyPrecision = 2;
        if (
            $context
            && isset($context->currency)
            && is_object($context->currency)
        ) {
            if (!empty($context->currency->iso_code)) {
                $iso = strtoupper(trim((string) $context->currency->iso_code));
                if ($iso !== '') {
                    $currencyCode = $iso;
                }
            }

            if (isset($context->currency->precision) && $context->currency->precision !== null) {
                $currencyPrecision = max(0, min(4, (int) $context->currency->precision));
            } elseif (isset($context->currency->decimals)) {
                $currencyPrecision = (bool) $context->currency->decimals ? 2 : 0;
            }
        }

        if ($currencyCode === null) {
            try {
                $defaultCurrencyId = (int) Configuration::get('PS_CURRENCY_DEFAULT');
                if ($defaultCurrencyId > 0) {
                    $currency = new Currency($defaultCurrencyId);
                    if ($currency->id && !empty($currency->iso_code)) {
                        $currencyCode = strtoupper(trim((string) $currency->iso_code));
                    }
                }
            } catch (\Throwable $e) {
                $currencyCode = null;
            }
        }

        if ($currencyCode === null || $currencyCode === '') {
            $currencyCode = 'EUR';
        }

        $shopTimezone = trim((string) Configuration::get('PS_TIMEZONE'));
        if ($shopTimezone === '') {
            $shopTimezone = 'UTC';
        }

        try {
            $dt = new \DateTime('now', new \DateTimeZone($shopTimezone));
            $shopLocalHour = (int) $dt->format('G');
        } catch (\Throwable $e) {
            $shopLocalHour = (int) gmdate('G');
        }

        $customerId = null;
        $customerEmail = null;
        $customerFirstName = null;
        $customerLastName = null;
        $customerIsGuest = true;

        if (
            $context
            && isset($context->customer)
            && $context->customer
            && isset($context->customer->id)
            && (int) $context->customer->id > 0
        ) {
            $customerId = (string) (int) $context->customer->id;
            $customerEmail = !empty($context->customer->email)
                ? trim((string) $context->customer->email)
                : null;
            if ($customerEmail === '') {
                $customerEmail = null;
            }
            $customerFirstName = !empty($context->customer->firstname)
                ? trim((string) $context->customer->firstname)
                : null;
            if ($customerFirstName === '') {
                $customerFirstName = null;
            }
            $customerLastName = !empty($context->customer->lastname)
                ? trim((string) $context->customer->lastname)
                : null;
            if ($customerLastName === '') {
                $customerLastName = null;
            }
            $customerIsGuest = isset($context->customer->is_guest)
                ? (bool) $context->customer->is_guest
                : false;
        }

        if ($customerId === null && $customerEmail === null) {
            $customerId = 'api_test_customer';
        }

        $baseUrl = '';
        try {
            if ($context && isset($context->link) && $context->link instanceof Link) {
                $baseUrl = rtrim((string) $context->link->getPageLink('index', true), '/');
            }
        } catch (\Throwable $e) {
            $baseUrl = '';
        }
        if ($baseUrl === '') {
            $baseUrl = rtrim((string) Tools::getShopDomainSsl(true), '/');
            if ($baseUrl !== '' && strpos($baseUrl, 'http') !== 0) {
                $baseUrl = 'https://' . ltrim($baseUrl, '/');
            }
        }

        $cartId = 'api-test-' . date('YmdHis');
        $cartUid = sha1($resolvedShopId . '-' . $cartId . '-' . $nowUtc);
        $testUnitPrice = 49.90;
        $productUrl = $baseUrl !== '' ? ($baseUrl . '/?nc_api_test_product=1') : null;
        $imageUrl = $baseUrl !== '' ? ($baseUrl . '/img/p/default-home_default.jpg') : null;

        return [
            'event_id' => 'test-' . bin2hex(random_bytes(6)),
            'event_type' => 'cart.created',
            'occurred_at' => $nowUtc,
            'source' => [
                'platform' => 'prestashop',
                'shop_id' => $resolvedShopId,
                'shop_name' => $shopName,
            ],
            'cart' => [
                'id' => $cartId,
                'uid' => $cartUid,
                'total' => $testUnitPrice,
                'items' => [
                    [
                        'product_id' => 1,
                        'attribute_id' => 0,
                        'name' => 'API Test Product',
                        'category_path' => 'api_test',
                        'brand_name' => 'NeuroCheckout',
                        'quantity' => 1,
                        'unit_price' => $testUnitPrice,
                        'line_total' => $testUnitPrice,
                        'availability' => 'in_stock',
                        'in_stock' => true,
                        'stock' => 1,
                        'product_url' => $productUrl,
                        'image_url' => $imageUrl,
                    ],
                ],
            ],
            'customer' => [
                'id' => $customerId,
                'email' => $customerEmail,
                'is_guest' => $customerIsGuest,
                'first_name' => $customerFirstName,
                'last_name' => $customerLastName,
                'locale' => $locale,
            ],
            'context' => [
                'shop_timezone' => $shopTimezone,
                'shop_local_hour' => $shopLocalHour,
                'currency_precision' => $currencyPrecision,
                'shop_locale' => $locale,
                'currency_code' => $currencyCode,
            ],
            'rules' => [
                'recovery_enabled' => true,
                'allow_discount' => false,
                'min_cart_total' => 0.0,
                'allow_guest' => true,
                'no_discount_max' => 0.0,
                'discount_5_min' => 0.0,
                'discount_5_max' => 0.0,
                'discount_10_min' => 0.0,
                'max_discount_percent' => 20.0,
            ],
        ];
    }

    private function jsonExit(
        bool $success,
        int $status,
        ?string $error = null,
        int $duration = 0,
        ?string $body = null,
        array $extra = []
    ): void {
        $httpStatus = ($status >= 100 && $status <= 599)
            ? $status
            : ($success ? 200 : 500);
        http_response_code($httpStatus);

        $payload = [
            'success' => $success,
            'http_status' => $status,
            'duration_ms' => $duration,
            'error' => $error,
            'response_body' => $body,
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        if (!empty($extra)) {
            $payload = array_merge($payload, $extra);
        }

        echo json_encode($payload);

        exit;
    }

    private function enableModuleCronMode(): void
    {
        Configuration::updateValue('NC_EXECUTION_MODE', 'cron_module');
        Configuration::updateValue('NC_CRON_INTERVAL_SECONDS', self::CRON_INTERVAL_SECONDS);
    }

    private function ensureCronSetupAutoOnce(int $cronIntervalMinutes): array
    {
        $manualCommand = $this->buildManualSetupCommand($cronIntervalMinutes);
        $status = trim((string) Configuration::get('NC_CRON_SETUP_STATUS'));
        $autoOnceDone = (int) Configuration::get('NC_CRON_SETUP_AUTO_ONCE_DONE') === 1;
        $cronHeartbeatFresh = $this->isCronHeartbeatFresh();

        if ($cronHeartbeatFresh && $status !== self::CRON_SETUP_STATUS_CONFIGURED) {
            Configuration::updateValue('NC_CRON_SETUP_STATUS', self::CRON_SETUP_STATUS_CONFIGURED);
            Configuration::updateValue('NC_CRON_SETUP_LAST_ERROR', '');
            $status = self::CRON_SETUP_STATUS_CONFIGURED;
        }

        if ($autoOnceDone) {
            if ($status === self::CRON_SETUP_STATUS_CONFIGURED) {
                return [
                    'status' => self::CRON_SETUP_STATUS_CONFIGURED,
                    'attempted' => false,
                    'message' => $cronHeartbeatFresh
                        ? ModuleTranslator::trans('cron_setup_detected_active')
                        : ModuleTranslator::trans('cron_setup_auto_already_done'),
                    'manual_command' => $manualCommand,
                ];
            }

            $lastError = trim((string) Configuration::get('NC_CRON_SETUP_LAST_ERROR'));

            return [
                'status' => self::CRON_SETUP_STATUS_MANUAL_REQUIRED,
                'attempted' => false,
                'message' => '',
                'manual_command' => $manualCommand,
            ];
        }

        Configuration::updateValue('NC_CRON_SETUP_AUTO_ONCE_DONE', 1);
        Configuration::updateValue('NC_CRON_SETUP_LAST_ATTEMPT', time());
        Configuration::updateValue('NC_CRON_SETUP_STATUS', self::CRON_SETUP_STATUS_MANUAL_REQUIRED);
        Configuration::updateValue(
            'NC_CRON_SETUP_LAST_ERROR',
            ModuleTranslator::trans('cron_setup_manual_policy_reason')
        );

        return [
            'status' => self::CRON_SETUP_STATUS_MANUAL_REQUIRED,
            'attempted' => false,
            'message' => '',
            'manual_command' => $manualCommand,
        ];
    }

    private function isCronHeartbeatFresh(): bool
    {
        $lastAutoRun = (int) Configuration::get('NC_LAST_AUTO_RUN');
        if ($lastAutoRun <= 0) {
            return false;
        }

        $configuredInterval = (int) (Configuration::get('NC_CRON_INTERVAL_SECONDS') ?: self::CRON_INTERVAL_SECONDS);
        if ($configuredInterval < 60) {
            $configuredInterval = self::CRON_INTERVAL_SECONDS;
        }

        $maxAgeSeconds = max(180, $configuredInterval * 3);

        return (time() - $lastAutoRun) <= $maxAgeSeconds;
    }

    private function buildManualSetupCommand(int $cronIntervalMinutes): string
    {
        $minutes = $cronIntervalMinutes > 0 ? $cronIntervalMinutes : 2;
        $scriptPath = rtrim((string) $this->module->getLocalPath(), '/') . '/scripts/setup_cron.sh';

        return sprintf(
            'bash %s --runner auto --interval-minutes %d',
            escapeshellarg($scriptPath),
            $minutes
        );
    }

    private function buildCronSetupManualMessage(string $command, string $reason): string
    {
        if ($reason !== '') {
            return ModuleTranslator::trans('cron_setup_manual_required_with_reason', [
                'command' => $command,
                'reason' => $reason,
            ]);
        }

        return ModuleTranslator::trans('cron_setup_manual_required', [
            'command' => $command,
        ]);
    }

    private function ensureInternalSecret(): string
    {
        $secret = SecretConfiguration::get('NC_INTERNAL_SECRET');
        if ($secret !== '') {
            return $secret;
        }

        try {
            $secret = bin2hex(random_bytes(32));
        } catch (\Throwable $e) {
            return '';
        }

        if (!SecretConfiguration::set('NC_INTERNAL_SECRET', $secret)) {
            return '';
        }

        return SecretConfiguration::get('NC_INTERNAL_SECRET');
    }

    private function isIaConfigurationReady(): bool
    {
        if ((int) Configuration::get('NC_RECOVERY_ENABLED') !== 1) {
            Configuration::updateValue('NC_RECOVERY_ENABLED', 1);
        }
        if ((int) Configuration::get('NC_RECOVERY_ENABLED') !== 1) {
            return false;
        }

        $rawMinCartTotal = trim((string) Configuration::get('NC_MIN_CART_TOTAL'));
        if ($rawMinCartTotal === '' || !is_numeric($rawMinCartTotal)) {
            return false;
        }
        if ((float) $rawMinCartTotal < 0) {
            return false;
        }

        $rawMaxDiscount = trim((string) Configuration::get('NC_MAX_DISCOUNT_PERCENT'));
        if ($rawMaxDiscount === '' || !is_numeric($rawMaxDiscount)) {
            return false;
        }

        $maxDiscount = (float) $rawMaxDiscount;
        if ($maxDiscount < 0 || $maxDiscount > 100) {
            return false;
        }

        return true;
    }
}

<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

$ncAutoloadPath = __DIR__ . '/vendor/autoload.php';
if (is_file($ncAutoloadPath)) {
    require_once $ncAutoloadPath;
} else {
    if (class_exists('PrestaShopLogger')) {
        PrestaShopLogger::addLog(
            '[NC] Missing Composer autoload: ' . $ncAutoloadPath,
            3
        );
    }
}

use NeuroCheckout\I18n\ModuleTranslator;
use NeuroCheckout\Event\CustomerJourneyEventBuilder;
use NeuroCheckout\Event\TelemetryEventBuilder;
use NeuroCheckout\Infrastructure\CustomerJourneyEventRepository;
use NeuroCheckout\Infrastructure\TelemetryEventRepository;
use NeuroCheckout\Security\EndpointPolicy;
use NeuroCheckout\Security\SecretConfiguration;
use NeuroCheckout\Http\SecureHttpClient;

class NeuroCheckoutConnector extends Module
{
    private const ADMIN_TAB_CLASS_NAME = 'AdminNeuroCheckoutConnectorConfig';
    private const CART_MUTATION_DISPATCH_DELAY_SECONDS = 12;
    private const REQUIRED_RUNTIME_HOOKS = [
        'actionCartSave',
        'actionCartUpdateQuantityAfter',
        'actionObjectCustomizationAddAfter',
        'actionProductCustomizationSaveAfter',
        'actionObjectCartDeleteAfter',
        'actionValidateOrder',
        'actionOrderStatusPostUpdate',
        'actionObjectCustomerMessageAddAfter',
        'actionFrontControllerInitAfter',
        'displayBackOfficeHeader',
        'moduleRoutes',
    ];

    /**
     * Request-scope dedupe for cart hook storms.
     * Prevents repeated writes when several third-party modules trigger
     * multiple cart hooks for the same cart in the same request.
     *
     * @var array<string,bool>
     */
    private static $requestCartTouchGuard = [];
    private static $deferredCronDispatchRegistered = false;

    public function __construct()
    {
        $this->name = 'neurocheckoutconnector';
        $this->tab = 'analytics_stats';
        $this->version = '4.6.4';
        $this->author = 'NeuroCheckout';
        $this->need_instance = 0;
        $this->bootstrap = true;

        $this->ps_versions_compliancy = [
            'min' => '1.7.7.0',
            'max' => _PS_VERSION_,
        ];

        parent::__construct();

        if (class_exists(ModuleTranslator::class)) {
            $this->displayName = ModuleTranslator::trans('module_display_name');
            $this->description = ModuleTranslator::trans('module_description');
        } else {
            $this->displayName = 'NeuroCheckout - Enterprise Connector';
            $this->description = 'Secure asynchronous event connector with monitoring and resilience.';
        }

        if (class_exists(SecretConfiguration::class)) {
            SecretConfiguration::migrateKnownSecrets();
        }

        // Runtime self-healing: keep required hooks registered after module upgrades,
        // DB restores, or partial reinstalls where hook_module can drift.
        $this->ensureRequiredHooksRegistered();
        $this->ensureAdminTabRegistered();
    }

    private function ensureRequiredHooksRegistered(): void
    {
        if (empty($this->id)) {
            return;
        }

        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        $diagnostics = $this->ensureRequiredRuntimeHooks();
        foreach ($diagnostics['repaired'] as $hookName) {
            PrestaShopLogger::addLog('[NC] Hook auto-registered: ' . $hookName, 1);
        }
        foreach ($diagnostics['missing'] as $hookName) {
            PrestaShopLogger::addLog('[NC] Hook auto-registration failed: ' . $hookName, 2);
        }
    }

    private function ensureAdminTabRegistered(): void
    {
        if (empty($this->id) || !class_exists('Tab') || !class_exists('Language')) {
            return;
        }

        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        $existingTabId = (int) Tab::getIdFromClassName(self::ADMIN_TAB_CLASS_NAME);
        if ($existingTabId > 0) {
            return;
        }

        $this->installAdminTab();
    }

    /* ============================================================
     * INSTALL
     * ============================================================ */

    public function install()
    {
        if (
            version_compare(_PS_VERSION_, '1.7.7.0', '<') ||
            version_compare(PHP_VERSION, '7.4.0', '<') ||
            !extension_loaded('curl') ||
            !extension_loaded('openssl')
        ) {
            return false;
        }

        return parent::install()
            && $this->installDb()
            && $this->installAdminTab()

            /* CORE HOOK */
            && $this->registerHook('actionCartSave')

            /* 🔥 FULL CART MUTATION COVERAGE */
            && $this->registerHook('actionCartUpdateQuantityAfter')
            && $this->registerHook('actionObjectCustomizationAddAfter')
            && $this->registerHook('actionProductCustomizationSaveAfter')
            && $this->registerHook('actionObjectCartDeleteAfter')
            && $this->registerHook('actionValidateOrder')
            && $this->registerHook('actionOrderStatusPostUpdate')
            && $this->registerHook('actionObjectCustomerMessageAddAfter')

            && $this->registerHook('actionFrontControllerInitAfter')
            && $this->registerHook('displayBackOfficeHeader')
            && $this->registerHook('moduleRoutes')

            /* CONFIG */
            && Configuration::updateValue('NC_API_ENDPOINT', '')
            && SecretConfiguration::set('NC_API_KEY', '')
            && SecretConfiguration::set('NC_API_KEY_NEXT', '')
            && Configuration::updateValue('NC_API_KEY_ROTATION_ID', '')
            && SecretConfiguration::set('NC_API_KEY_PREV', '')
            && Configuration::updateValue('NC_API_KEY_PREV_UNTIL', 0)
            && Configuration::updateValue('NC_SHOP_EXTERNAL_ID', '')
            && Configuration::updateValue('NC_API_TEST_VALIDATED_AT', 0)
            && Configuration::updateValue('NC_API_TEST_VALIDATION_FINGERPRINT', '')
            && Configuration::updateValue('NC_OPAQUE_RECOVERY_LINKS', 1)

            /* IA */
            && Configuration::updateValue('NC_RECOVERY_ENABLED', 1)
            && Configuration::updateValue('NC_ENABLE_DISCOUNT', 0)
            && Configuration::updateValue('NC_MIN_CART_TOTAL', '')
            && Configuration::updateValue('NC_ALLOW_GUEST', 0)
            && Configuration::updateValue('NC_NO_DISCOUNT_MAX', '')
            && Configuration::updateValue('NC_DISCOUNT_5_MIN', '')
            && Configuration::updateValue('NC_DISCOUNT_5_MAX', '')
            && Configuration::updateValue('NC_DISCOUNT_10_MIN', '')
            && Configuration::updateValue('NC_MAX_DISCOUNT_PERCENT', '')

            /* EXECUTION */
            && Configuration::updateValue('NC_EXECUTION_MODE', 'cron_module')
            && Configuration::updateValue('NC_DEBUG_MODE', 0)
            && Configuration::updateValue('NC_DEBUG_ADVANCED', 0)
            && Configuration::updateValue('NC_LAST_AUTO_RUN', 0)
            && Configuration::updateValue('NC_AUTO_HOOK_LAST_CALL', 0)
            && Configuration::updateValue('NC_AUTO_HOOK_INTERVAL', 300)
            && Configuration::updateValue('NC_AUTO_CLI_LAST_KICK', 0)
            && Configuration::updateValue('NC_CRON_INTERVAL_SECONDS', 300)
            && Configuration::updateValue('NC_TELEMETRY_ENABLED', 1)
            && SecretConfiguration::set('NC_TELEMETRY_PUBLIC_TOKEN', bin2hex(random_bytes(16)))
            && Configuration::updateValue('NC_CUSTOMER_JOURNEY_ENABLED', 1)
            && SecretConfiguration::set('NC_CUSTOMER_JOURNEY_PUBLIC_TOKEN', bin2hex(random_bytes(16)))

            /* CRON SECURITY */
            && SecretConfiguration::set('NEURO_CRON_TOKEN', bin2hex(random_bytes(32)))
            && Configuration::updateValue('NC_CRON_LAST_RUN', 0)
            && Configuration::updateValue('NC_CRON_ALLOWED_IPS', '')
            && Configuration::updateValue('NC_TRUSTED_PROXY_IPS', '')
            && Configuration::updateValue('NC_CRON_ALERT_ENABLED', 1)
            && Configuration::updateValue('NC_CRON_BLOCKED_UNTIL', 0)

            /* CIRCUIT BREAKER */
            && Configuration::updateValue('NC_CB_FAILURE_THRESHOLD', 5)
            && Configuration::updateValue('NC_CB_COOLDOWN_SECONDS', 60)

            /* PURGE */
            && Configuration::updateValue('NC_EVENT_RETENTION_DAYS', 30)
            && Configuration::updateValue('NC_PURGE_BATCH_SIZE', 500)
            && Configuration::updateValue('NC_LAST_PURGE_RUN', 0)

            /* SECRET */
            && SecretConfiguration::set('NC_INTERNAL_SECRET', bin2hex(random_bytes(32)));
    }

    /* ============================================================
     * DB INSTALL
     * ============================================================ */

    private function installDb(): bool
    {
        $sqlFile = __DIR__ . '/sql/install.sql';

        if (!file_exists($sqlFile)) {
            return false;
        }

        $sql = file_get_contents($sqlFile);
        $sql = str_replace('PREFIX_', _DB_PREFIX_, $sql);

        $queries = preg_split("/;\s*[\r\n]+/", $sql);

        foreach ($queries as $query) {
            $query = trim($query);
            if (!empty($query) && !Db::getInstance()->execute($query)) {
                $this->logSqlError('install_db_failed', [
                    'query_prefix' => substr($query, 0, 120),
                ]);
                return false;
            }
        }

        return true;
    }

    public function uninstall()
    {
        return
            $this->uninstallAdminTab()
            &&
            $this->uninstallDb()
            && $this->deleteConfigurations()
            && parent::uninstall();
    }

    public function hookModuleRoutes($params)
    {
        return [
            'module-neurocheckoutconnector-orders-history' => [
                'controller' => 'orderhistory',
                'rule' => 'module/neurocheckoutconnector/orders/history',
                'keywords' => [],
                'params' => [
                    'fc' => 'module',
                    'module' => 'neurocheckoutconnector',
                ],
            ],
        ];
    }

    private function installAdminTab(): bool
    {
        if (!class_exists('Tab') || !class_exists('Language')) {
            return true;
        }

        $existingTabId = (int) Tab::getIdFromClassName(self::ADMIN_TAB_CLASS_NAME);
        if ($existingTabId > 0) {
            return true;
        }

        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = self::ADMIN_TAB_CLASS_NAME;
        $tab->module = $this->name;
        $tab->id_parent = $this->resolveAdminTabParentId();
        $tab->name = [];

        foreach (Language::getLanguages(false) as $lang) {
            $tab->name[(int) $lang['id_lang']] = 'NeuroCheckout';
        }

        $ok = (bool) $tab->add();
        if (!$ok && class_exists('PrestaShopLogger')) {
            PrestaShopLogger::addLog('[NC] Admin tab install failed', 2);
        }

        return $ok;
    }

    private function uninstallAdminTab(): bool
    {
        if (!class_exists('Tab')) {
            return true;
        }

        $tabId = (int) Tab::getIdFromClassName(self::ADMIN_TAB_CLASS_NAME);
        if ($tabId <= 0) {
            return true;
        }

        try {
            $tab = new Tab($tabId);
            if (!Validate::isLoadedObject($tab)) {
                return true;
            }

            return (bool) $tab->delete();
        } catch (\Throwable $e) {
            if (class_exists('PrestaShopLogger')) {
                PrestaShopLogger::addLog(
                    '[NC] Admin tab uninstall failed: ' . $e->getMessage(),
                    2
                );
            }

            return false;
        }
    }

    private function resolveAdminTabParentId(): int
    {
        $parentClassCandidates = [
            'AdminParentModulesSf',
            'AdminParentModulesCatalog',
            'AdminModules',
            'AdminParentPreferences',
        ];

        foreach ($parentClassCandidates as $className) {
            $parentId = (int) Tab::getIdFromClassName($className);
            if ($parentId > 0) {
                return $parentId;
            }
        }

        return 0;
    }

    private function uninstallDb(): bool
    {
        return
            Db::getInstance()->execute('DROP TABLE IF EXISTS `'._DB_PREFIX_.'neurocheckout_event`')
            && Db::getInstance()->execute('DROP TABLE IF EXISTS `'._DB_PREFIX_.'neurocheckout_order_event`')
            && Db::getInstance()->execute('DROP TABLE IF EXISTS `'._DB_PREFIX_.'neurocheckout_telemetry_event`')
            && Db::getInstance()->execute('DROP TABLE IF EXISTS `'._DB_PREFIX_.'neurocheckout_customer_journey_event`')
            && Db::getInstance()->execute('DROP TABLE IF EXISTS `'._DB_PREFIX_.'neurocheckout_cron_log`')
            && Db::getInstance()->execute('DROP TABLE IF EXISTS `'._DB_PREFIX_.'neurocheckout_nonce`')
            && Db::getInstance()->execute('DROP TABLE IF EXISTS `'._DB_PREFIX_.'neurocheckout_circuit_breaker`')
            && Db::getInstance()->execute('DROP TABLE IF EXISTS `'._DB_PREFIX_.'neurocheckout_lock`')
            && Db::getInstance()->execute('DROP TABLE IF EXISTS `'._DB_PREFIX_.'neurocheckout_coupon`')
            && Db::getInstance()->execute('DROP TABLE IF EXISTS `'._DB_PREFIX_.'neurocheckout_recovery_token`')
            && Db::getInstance()->execute('DROP TABLE IF EXISTS `'._DB_PREFIX_.'neurocheckout_security_rate_limit`')
            && Db::getInstance()->execute('DROP TABLE IF EXISTS `'._DB_PREFIX_.'neurocheckout_payload_alias`');
    }

    private function deleteConfigurations(): bool
    {
        $keys = [
            'NC_API_ENDPOINT','NC_API_KEY','NC_API_KEY_NEXT','NC_API_KEY_ROTATION_ID',
            'NC_API_KEY_PREV','NC_API_KEY_PREV_UNTIL','NC_SHOP_EXTERNAL_ID',
            'NC_API_TEST_VALIDATED_AT','NC_API_TEST_VALIDATION_FINGERPRINT',
            'NC_OPAQUE_RECOVERY_LINKS',
            'NC_RECOVERY_ENABLED','NC_ENABLE_DISCOUNT','NC_MIN_CART_TOTAL',
            'NC_ALLOW_GUEST','NC_NO_DISCOUNT_MAX','NC_DISCOUNT_5_MIN',
            'NC_DISCOUNT_5_MAX','NC_DISCOUNT_10_MIN','NC_MAX_DISCOUNT_PERCENT',
            'NC_EXECUTION_MODE','NC_DEBUG_MODE','NC_DEBUG_ADVANCED',
            'NC_LAST_AUTO_RUN','NEURO_CRON_TOKEN','NC_CRON_LAST_RUN',
            'NC_CRON_ALLOWED_IPS','NC_TRUSTED_PROXY_IPS','NC_CRON_ALERT_ENABLED','NC_CRON_BLOCKED_UNTIL',
            'NC_CB_FAILURE_THRESHOLD','NC_CB_COOLDOWN_SECONDS',
            'NC_INTERNAL_SECRET','NC_EVENT_RETENTION_DAYS',
            'NC_PURGE_BATCH_SIZE','NC_LAST_PURGE_RUN',
            'NC_AUTO_HOOK_LAST_CALL','NC_AUTO_HOOK_INTERVAL',
            'NC_AUTO_CLI_LAST_KICK','NC_CRON_INTERVAL_SECONDS',
            'NC_TELEMETRY_ENABLED','NC_TELEMETRY_PUBLIC_TOKEN',
            'NC_CUSTOMER_JOURNEY_ENABLED','NC_CUSTOMER_JOURNEY_PUBLIC_TOKEN'
        ];

        foreach ($keys as $key) {
            Configuration::deleteByName($key);
        }

        foreach (Shop::getShops(true, null, true) as $shopId) {
            $shopId = (int)$shopId;
            Configuration::deleteByName('NC_CRON_BLOCKED_UNTIL_' . $shopId);
            Configuration::deleteByName('NC_CRON_LAST_RUN_' . $shopId);
            Configuration::deleteByName('NC_HEALTH_CACHE_' . $shopId);
        }

        return true;
    }

    /* ============================================================
     * CART HOOKS
     * ============================================================ */

    public function hookActionCartSave($params)
    {
        $this->queueCartEventFromParams($params, 'actionCartSave');
    }

    /**
     * Repair hook drift during an explicit API test or module upgrade.
     *
     * @return array{success:bool,repaired:list<string>,missing:list<string>}
     */
    public function ensureRequiredRuntimeHooks(): array
    {
        $repaired = [];
        $missing = [];

        foreach (self::REQUIRED_RUNTIME_HOOKS as $hookName) {
            try {
                if ($this->isRegisteredInHook($hookName)) {
                    continue;
                }

                if ($this->registerHook($hookName)) {
                    $repaired[] = $hookName;
                    continue;
                }

                $missing[] = $hookName;
            } catch (\Throwable $e) {
                $missing[] = $hookName;
                PrestaShopLogger::addLog(
                    '[NC] Required hook repair failed [' . $hookName . ']: ' . $e->getMessage(),
                    2
                );
            }
        }

        return [
            'success' => empty($missing),
            'repaired' => $repaired,
            'missing' => $missing,
        ];
    }

    public function hookActionCartUpdateQuantityAfter($params)
    {
        $this->queueCartEventFromParams($params, 'actionCartUpdateQuantityAfter');
    }

    public function hookActionObjectCustomizationAddAfter($params)
    {
        $this->queueCartEventFromParams($params, 'actionObjectCustomizationAddAfter');
    }

    public function hookActionProductCustomizationSaveAfter($params)
    {
        $this->queueCartEventFromParams($params, 'actionProductCustomizationSaveAfter');
    }

    public function hookActionObjectCartDeleteAfter($params)
    {
        try {
            if ($this->isCustomerLogoutRequest()) {
                return;
            }

            $cart = $this->extractCartFromHookParams($params);
            if (!$cart) {
                return;
            }

            $this->queueCartClearedEvent($cart, 'cart_deleted');
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                '[NC] Cart delete hook error: '.$e->getMessage(),
                2
            );
        }
    }

    public function hookActionValidateOrder($params)
    {
        $payload = [];
        $queueShopId = 0;

        try {
            if (!$this->isIaConfigurationReady()) {
                return;
            }

            $endpoint = trim((string)Configuration::get('NC_API_ENDPOINT'));
            $apiKey = preg_replace(
                '/\s+/',
                '',
                SecretConfiguration::get('NC_API_KEY')
            );

            if ($endpoint === '' || $apiKey === '') {
                return;
            }

            if (!empty($params['order']) && $params['order'] instanceof Order) {
                $this->maybeQueueCustomerJourneyOrderCompleted($params['order'], 'actionValidateOrder');
            }

            $payload = \NeuroCheckout\Event\OrderEventBuilder::buildFromValidateOrderParams(
                (array)$params
            );

            if (empty($payload)) {
                return;
            }

            if (!empty($params['order']) && $params['order'] instanceof Order) {
                $queueShopId = (int)$params['order']->id_shop;
            }
            if ($queueShopId <= 0) {
                $context = Context::getContext();
                if ($context && isset($context->shop) && $context->shop) {
                    $queueShopId = (int)$context->shop->id;
                }
            }

            if (!$this->shouldSendOrderCompletedFromNeuroTables($payload, (array)$params)) {
                return;
            }

            $client = new \NeuroCheckout\Http\SecureHttpClient();
            $result = $client->sendOrderCompleted($payload);

            if (empty($result['success'])) {
                $status = (int)($result['status'] ?? 0);
                $error = (string)($result['error'] ?? ('HTTP ' . $status));

                PrestaShopLogger::addLog(
                    '[NC] Order completed send failed: ' . $error,
                    2
                );

                if ($queueShopId > 0) {
                    $this->queueOrderCompletedForRetry($payload, $queueShopId, $error);
                }
            } else {
                $orderId = trim((string)($payload['order_id'] ?? ''));
                if ($queueShopId > 0 && $orderId !== '') {
                    $this->markOrderRetryAsSent($queueShopId, $orderId);
                }
            }

        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                '[NC] ValidateOrder hook error: ' . $e->getMessage(),
                2
            );

            if (!empty($payload) && $queueShopId > 0) {
                $this->queueOrderCompletedForRetry($payload, $queueShopId, $e->getMessage());
            }
        }
    }

    public function hookActionOrderStatusPostUpdate($params)
    {
        try {
            if (!$this->isIaConfigurationReady()) {
                return;
            }

            $order = $this->extractOrderFromStatusHookParams($params);
            if (!$order || !$order->id) {
                return;
            }

            $state = $this->extractOrderStateFromStatusHookParams($params, $order);
            if (!$this->isPaymentFailureOrderState($state, $order)) {
                return;
            }

            $payload = TelemetryEventBuilder::buildPaymentFailed(
                $order,
                $state,
                $this->context,
                $this->version
            );
            if (empty($payload)) {
                return;
            }

            $this->enqueueTelemetryPayload($payload, 'server');
            $this->kickTelemetryDispatch();
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                '[NC] Order status telemetry hook error: ' . $e->getMessage(),
                2
            );
        }
    }

    public function hookActionObjectCustomerMessageAddAfter($params)
    {
        try {
            if (!$this->isIaConfigurationReady()) {
                return;
            }

            $message = null;
            if ($params instanceof CustomerMessage) {
                $message = $params;
            } elseif (is_array($params) && isset($params['object']) && $params['object'] instanceof CustomerMessage) {
                $message = $params['object'];
            } elseif (is_object($params) && property_exists($params, 'object') && $params->object instanceof CustomerMessage) {
                $message = $params->object;
            }

            if (!$message) {
                return;
            }

            $payload = \NeuroCheckout\Event\SupportEventBuilder::buildFromCustomerMessage($message);
            if (empty($payload)) {
                return;
            }

            $client = new \NeuroCheckout\Http\SecureHttpClient();
            $result = $client->sendSupportEvent($payload);
            if (empty($result['success'])) {
                $status = (int)($result['status'] ?? 0);
                $error = (string)($result['error'] ?? ('HTTP ' . $status));
                PrestaShopLogger::addLog(
                    '[NC] Support event send failed: ' . $error,
                    2
                );
            }
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                '[NC] CustomerMessage support hook error: ' . $e->getMessage(),
                2
            );
        }
    }

    private function shouldSendOrderCompletedFromNeuroTables(array $payload, array $params): bool
    {
        $cartId = trim((string)($payload['cart_id'] ?? ''));
        if ($cartId === '') {
            PrestaShopLogger::addLog('[NC] Order completed skipped: missing cart_id', 2);
            return false;
        }

        $shopId = 0;
        if (!empty($params['order']) && $params['order'] instanceof Order) {
            $shopId = (int)$params['order']->id_shop;
        }

        if ($shopId <= 0) {
            $context = Context::getContext();
            if ($context && isset($context->shop) && $context->shop) {
                $shopId = (int)$context->shop->id;
            }
        }

        if ($shopId <= 0) {
            PrestaShopLogger::addLog(
                '[NC] Order completed skipped: missing shop context for cart ' . $cartId,
                2
            );
            return false;
        }

        $eventExists = (bool)Db::getInstance()->getValue(
            'SELECT 1
             FROM `' . _DB_PREFIX_ . 'neurocheckout_event`
             WHERE shop_id = ' . (int)$shopId . '
               AND cart_id = "' . pSQL($cartId) . '"'
        );

        if (!$eventExists) {
            PrestaShopLogger::addLog(
                '[NC] Order completed skipped: cart not tracked in neurocheckout_event (cart='
                . $cartId . ', shop=' . $shopId . ')',
                2
            );
            return false;
        }

        $email = trim((string)($payload['customer_email'] ?? ''));
        $couponWhereEmail = '';
        if ($email !== '') {
            $couponWhereEmail = ' AND LOWER(customer_email) = LOWER("' . pSQL($email) . '")';
        }

        $couponExists = (bool)Db::getInstance()->getValue(
            'SELECT 1
             FROM `' . _DB_PREFIX_ . 'neurocheckout_coupon`
             WHERE shop_id = ' . (int)$shopId . '
               AND cart_id = "' . pSQL($cartId) . '"
               ' . $couponWhereEmail . ''
        );

        if (!$couponExists) {
            // Fallback path:
            // - recovery can be valid with 0% discount (no coupon row by design)
            // - keep coupon match as preferred signal when present
            // - still send order.completed so backend attribution can decide.
            $hasNeuroCouponCode = $this->orderContainsNeuroCouponCode($params);

            if ($hasNeuroCouponCode) {
                PrestaShopLogger::addLog(
                    '[NC] Order completed fallback: neuro coupon code present without coupon row (cart='
                    . $cartId . ', shop=' . $shopId . ')',
                    1
                );
                return true;
            }

            PrestaShopLogger::addLog(
                '[NC] Order completed fallback: no coupon row (likely no-discount recovery), sending for backend attribution (cart='
                . $cartId . ', shop=' . $shopId . ')',
                1
            );
        }

        return true;
    }

    private function orderContainsNeuroCouponCode(array $params): bool
    {
        if (empty($params['order']) || !($params['order'] instanceof Order)) {
            return false;
        }

        /** @var Order $order */
        $order = $params['order'];
        $orderCartRules = $order->getCartRules();
        if (!is_array($orderCartRules) || empty($orderCartRules)) {
            return false;
        }

        foreach ($orderCartRules as $rule) {
            $code = strtoupper(trim((string)($rule['code'] ?? '')));
            if ($code !== '' && strpos($code, 'NC-') === 0) {
                return true;
            }
        }

        return false;
    }

    private function queueCartEventFromParams($params, string $hookName = 'unknown'): void
    {
        try {
            if ($this->isCustomerLogoutRequest()) {
                return;
            }

            if (!$this->isIaConfigurationReady()) {
                return;
            }

            $cart = $this->extractCartFromHookParams($params);
            if (!$cart) {
                return;
            }

            if (!$cart->id) {
                return;
            }

            $shopId = $this->resolveShopIdForCart($cart);
            if ($shopId <= 0) {
                return;
            }

            $cartId = (string)$cart->id;
            if ($this->shouldSkipCartHookTouchInCurrentRequest($shopId, $cartId, $hookName)) {
                return;
            }

            $isSensitiveCartMutationRequest = $this->isSensitiveCartMutationRequest();

            $this->touchCartEventRow(
                $shopId,
                $cartId,
                $isSensitiveCartMutationRequest ? self::CART_MUTATION_DISPATCH_DELAY_SECONDS : 0
            );
            if (!$isSensitiveCartMutationRequest) {
                $this->maybeQueueCustomerJourneyCartSnapshot($cart, $hookName);
            }
            Configuration::deleteByName('NC_HEALTH_CACHE_' . $shopId);
            if (!$isSensitiveCartMutationRequest) {
                // Drain after the response so normal page views still work without
                // a server-level cron, while cart AJAX stays isolated.
                $this->triggerDeferredCronDispatch(false);
            }

        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                '[NC] Cart mutation hook error [' . $hookName . '] '
                . '(payload=' . $this->describeHookParamsType($params) . '): '
                . $e->getMessage(),
                2
            );
        }
    }

    private function isCustomerLogoutRequest(): bool
    {
        $requestUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        if ($requestUri !== '' && (
            stripos($requestUri, 'logout') !== false
            || stripos($requestUri, 'deconnexion') !== false
            || stripos($requestUri, 'se-deconnecter') !== false
        )) {
            return true;
        }

        foreach (['mylogout', 'logout', 'customer_logout', 'submitLogout'] as $key) {
            $value = Tools::getValue($key);
            if ($value !== false && $value !== null && $value !== '') {
                return true;
            }
        }

        $controller = strtolower((string) Tools::getValue('controller'));
        $action = strtolower((string) Tools::getValue('action'));

        return in_array($controller, ['authentication', 'auth'], true)
            && in_array($action, ['logout', 'customerlogout'], true);
    }

    private function isSensitiveCartMutationRequest(): bool
    {
        $requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($requestMethod, ['GET', 'HEAD'], true)) {
            return true;
        }

        $ajaxFlag = strtolower((string) Tools::getValue('ajax'));
        $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (
            in_array($ajaxFlag, ['1', 'true', 'yes', 'on'], true)
            || $requestedWith === 'xmlhttprequest'
            || strpos($accept, 'application/json') !== false
            || strpos($contentType, 'application/json') !== false
        ) {
            return true;
        }

        $controller = strtolower((string) Tools::getValue('controller'));
        $action = strtolower((string) Tools::getValue('action'));
        $requestUri = strtolower((string) ($_SERVER['REQUEST_URI'] ?? ''));

        $mutationParams = ['add', 'update', 'delete', 'qty', 'id_product', 'id_product_attribute'];
        foreach ($mutationParams as $param) {
            if (Tools::getValue($param) !== false && Tools::getValue($param) !== null) {
                return true;
            }
        }

        if (in_array($action, ['add-to-cart', 'refresh', 'update', 'delete'], true)) {
            return true;
        }

        if ($controller === 'cart' && $action !== '' && $action !== 'show') {
            return true;
        }

        $sensitivePathPatterns = [
            '/module/ps_shoppingcart/ajax',
            '/cart?',
            '/panier?',
            '/commande',
        ];
        foreach ($sensitivePathPatterns as $pattern) {
            if (strpos($requestUri, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    private function extractCartFromHookParams($params): ?Cart
    {
        if ($params instanceof Cart) {
            return $params;
        }

        if (is_array($params)) {
            if (isset($params['cart']) && $params['cart'] instanceof Cart) {
                return $params['cart'];
            }

            if (isset($params['object']) && $params['object'] instanceof Cart) {
                return $params['object'];
            }

            if (isset($params['id_cart'])) {
                $idCart = (int) $params['id_cart'];
                if ($idCart > 0) {
                    try {
                        $cart = new Cart($idCart);
                        if ($cart->id) {
                            return $cart;
                        }
                    } catch (\Throwable $e) {
                    }
                }
            }
        }

        if (is_object($params)) {
            if (property_exists($params, 'cart') && $params->cart instanceof Cart) {
                return $params->cart;
            }

            if (property_exists($params, 'object') && $params->object instanceof Cart) {
                return $params->object;
            }

            if (property_exists($params, 'id_cart')) {
                $idCart = (int) $params->id_cart;
                if ($idCart > 0) {
                    try {
                        $cart = new Cart($idCart);
                        if ($cart->id) {
                            return $cart;
                        }
                    } catch (\Throwable $e) {
                    }
                }
            }
        }

        return null;
    }

    private function describeHookParamsType($params): string
    {
        if (is_array($params)) {
            return 'array';
        }

        if (is_object($params)) {
            return 'object:' . get_class($params);
        }

        if ($params === null) {
            return 'null';
        }

        return gettype($params);
    }

    private function shouldSkipCartHookTouchInCurrentRequest(int $shopId, string $cartId, string $hookName): bool
    {
        if ($shopId <= 0 || $cartId === '') {
            return false;
        }

        $hookKey = preg_replace('/[^a-z0-9_]+/', '_', strtolower($hookName));
        if (!is_string($hookKey) || $hookKey === '') {
            $hookKey = 'unknown';
        }

        $key = $shopId . '|' . $cartId . '|' . $hookKey;
        if (isset(self::$requestCartTouchGuard[$key])) {
            return true;
        }

        self::$requestCartTouchGuard[$key] = true;
        return false;
    }

    private function touchCartEventRow(int $shopId, string $cartId, int $deferSeconds = 0): void
    {
        $deferSeconds = max(0, min(120, $deferSeconds));
        $nextRetrySql = $deferSeconds > 0
            ? 'DATE_ADD(NOW(), INTERVAL ' . (int) $deferSeconds . ' SECOND)'
            : 'NULL';

        $ok = Db::getInstance()->execute(
            '
            INSERT INTO `'._DB_PREFIX_.'neurocheckout_event`
            (shop_id,cart_id,event_hash,payload,status,attempts,priority,created_at,last_attempt_at,next_retry_at)
            VALUES
            (
                '.(int)$shopId.',
                "'.pSQL($cartId).'",
                "",
                "",
                "pending",
                0,
                0,
                NOW(),
                NULL,
                ' . $nextRetrySql . '
            )
            ON DUPLICATE KEY UPDATE
                event_hash = IF(status="processing", event_hash, ""),
                payload = IF(status="processing", payload, ""),
                status = IF(status="processing","processing","pending"),
                attempts = 0,
                next_retry_at = ' . $nextRetrySql . ',
                created_at = NOW()
            '
        );

        if (!$ok) {
            $this->logSqlError('cart_event_touch_failed', [
                'shop_id' => (string) $shopId,
                'cart_id' => $cartId,
            ]);
        }
    }

    private function queueCartClearedEvent(Cart $cart, string $reason): void
    {
        if (!$cart->id) {
            return;
        }

        $shopId = $this->resolveShopIdForCart($cart);
        if ($shopId <= 0) {
            return;
        }

        $cartId = (string)$cart->id;
        if (!$this->hasTrackedCartEvent($shopId, $cartId)) {
            return;
        }

        $payload = \NeuroCheckout\Event\CartEventBuilder::buildCleared($cart, $reason);
        if (empty($payload)) {
            return;
        }

        $this->upsertCartEventRow($shopId, $cartId, $payload);
        $this->maybeQueueCustomerJourneyCartSnapshot($cart, 'cart_cleared_' . $reason);
    }

    private function upsertCartEventRow(int $shopId, string $cartId, ?array $payload = null): void
    {
        $payloadJson = '';
        $eventHash = '';

        if ($payload !== null) {
            $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
            if ($payloadJson === false) {
                throw new \RuntimeException('Unable to encode cart event payload');
            }

            $eventHash = hash('sha256', $payloadJson);
        }

        $duplicateSet = '
            status="pending",
            attempts=0,
            priority=0,
            next_retry_at=NULL,
            created_at=NOW()
        ';

        if ($payload !== null) {
            $duplicateSet .= ',
            event_hash="' . pSQL($eventHash) . '",
            payload="' . pSQL($payloadJson, true) . '"';
        }

        $ok = Db::getInstance()->execute(
            '
            INSERT INTO `'._DB_PREFIX_.'neurocheckout_event`
            (shop_id,cart_id,event_hash,payload,status,attempts,priority,created_at,last_attempt_at,next_retry_at)
            VALUES
            (
                '.$shopId.',
                "'.pSQL($cartId).'",
                "'.pSQL($eventHash).'",
                "'.pSQL($payloadJson, true).'",
                "pending",
                0,
                0,
                NOW(),
                NULL,
                NULL
            )
            ON DUPLICATE KEY UPDATE
            '.$duplicateSet.'
            '
        );

        if (!$ok) {
            $this->logSqlError('cart_event_upsert_failed', [
                'shop_id' => (string) $shopId,
                'cart_id' => $cartId,
            ]);
        }
    }

    private function resolveShopIdForCart(Cart $cart): int
    {
        if (isset($cart->id_shop) && (int)$cart->id_shop > 0) {
            return (int)$cart->id_shop;
        }

        $context = Context::getContext();
        if ($context && isset($context->shop) && $context->shop && (int)$context->shop->id > 0) {
            return (int)$context->shop->id;
        }

        return 0;
    }

    private function cartHasNoProducts(Cart $cart): bool
    {
        try {
            $products = $cart->getProducts(true);
            return !is_array($products) || count($products) === 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function hasTrackedCartEvent(int $shopId, string $cartId): bool
    {
        $result = Db::getInstance()->getValue(
            'SELECT 1
             FROM `'._DB_PREFIX_.'neurocheckout_event`
             WHERE shop_id = '.(int)$shopId.'
               AND cart_id = "'.pSQL($cartId).'"
             LIMIT 1'
        );

        if ($result === false) {
            $this->logSqlError('cart_event_lookup_failed', [
                'shop_id' => (string) $shopId,
                'cart_id' => $cartId,
            ]);
            // Fail-open: if lookup errors transiently, do not skip cart.cleared emission.
            return true;
        }

        return (bool) $result;
    }

    /* ============================================================
     * AUTO MODE
     * ============================================================ */

    public function hookActionFrontControllerInitAfter($params = null)
    {
        $this->runCheckoutTelemetryCollectionSafely('FrontControllerInitAfter');

        try {
            $this->runAutoModeTrigger();
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                '[NC] FrontControllerInitAfter auto hook error: ' . $e->getMessage(),
                2
            );
        }
    }

    // Backward compatibility in case old installs still call this custom hook.
    public function hookActionFrontControllerAfterInit($params = null)
    {
        $this->runCheckoutTelemetryCollectionSafely('FrontControllerAfterInit');

        try {
            $this->runAutoModeTrigger();
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                '[NC] FrontControllerAfterInit auto hook error: ' . $e->getMessage(),
                2
            );
        }
    }

    private function runCheckoutTelemetryCollectionSafely(string $hookName): void
    {
        try {
            $this->maybeInjectCustomerJourneyTrackerScript();
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                '[NC] ' . $hookName . ' customer journey script error: ' . $e->getMessage(),
                2
            );
        }

        try {
            $this->maybeInjectCheckoutTelemetryScript();
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                '[NC] ' . $hookName . ' telemetry script error: ' . $e->getMessage(),
                2
            );
        }

        try {
            $this->maybeQueueShippingCostTelemetrySnapshot();
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                '[NC] ' . $hookName . ' telemetry snapshot error: ' . $e->getMessage(),
                2
            );
        }
    }

    private function runAutoModeTrigger(): void
    {
        if (!$this->isModuleCronModeEnabled()) {
            return;
        }

        if ($this->shouldSkipAutoHookRequest()) {
            return;
        }

        try {

            $now = time();
            $lastCall = (int)Configuration::get('NC_AUTO_HOOK_LAST_CALL');
            $interval = (int)Configuration::get('NC_AUTO_HOOK_INTERVAL');

            if ($interval <= 0) $interval = 60;
            $elapsed = $lastCall ? ($now - $lastCall) : PHP_INT_MAX;
            if ($lastCall && $elapsed < $interval) {
                if (!$this->shouldFastTrackPendingAutoRun($elapsed)) {
                    return;
                }
            }

            Configuration::updateValue('NC_AUTO_HOOK_LAST_CALL', $now);

            // A deferred run drains cart, order, telemetry and journey queues.
            // Server cron remains useful as a no-traffic fallback, not a
            // prerequisite for events generated during a storefront request.
            $this->triggerDeferredCronDispatch(true);

        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                '[NC] Auto hook error: '.$e->getMessage(),
                2
            );
        }
    }

    private function runAutoCronInlineFallback(): void
    {
        if (!$this->acquireCronLock()) {
            return;
        }

        try {
            $this->executeCronRun(false, false);
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                '[NC] Auto fallback execution error: ' . $e->getMessage(),
                2
            );
        } finally {
            $this->releaseCronLock();
        }
    }

    private function triggerDeferredCronDispatch(bool $allowInlineFallback): void
    {
        if (!$this->isModuleCronModeEnabled()) {
            return;
        }

        if (self::$deferredCronDispatchRegistered) {
            return;
        }

        try {
            self::$deferredCronDispatchRegistered = true;
            register_shutdown_function(function (): void {
                try {
                    ignore_user_abort(true);
                    @set_time_limit(30);
                    if (function_exists('fastcgi_finish_request')) {
                        @fastcgi_finish_request();
                    }
                    $this->runAutoCronInlineFallback();
                } catch (\Throwable $e) {
                    PrestaShopLogger::addLog(
                        '[NC] Deferred cron dispatch error: ' . $e->getMessage(),
                        2
                    );
                }
            });
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                '[NC] Deferred cron registration error: ' . $e->getMessage(),
                2
            );
            if ($allowInlineFallback) {
                $this->runAutoCronInlineFallback();
            }
        }
    }

    private function shouldSkipAutoHookRequest(): bool
    {
        $requestUri = strtolower((string) ($_SERVER['REQUEST_URI'] ?? ''));

        if (
            strpos($requestUri, 'order-confirmation') !== false
            || strpos($requestUri, 'confirmation-commande') !== false
            || strpos($requestUri, 'commande-confirmee') !== false
        ) {
            return false;
        }
        $requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $controller = strtolower((string) Tools::getValue('controller'));
        $action = strtolower((string) Tools::getValue('action'));
        $module = strtolower((string) Tools::getValue('module'));
        $fc = strtolower((string) Tools::getValue('fc'));
        $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        $phpSelf = '';
        if (
            isset($this->context)
            && isset($this->context->controller)
            && is_object($this->context->controller)
            && isset($this->context->controller->php_self)
        ) {
            $phpSelf = strtolower((string) $this->context->controller->php_self);
        }

        if ($requestMethod !== 'GET' && $requestMethod !== 'HEAD') {
            return true;
        }

        $ajaxFlag = strtolower((string) Tools::getValue('ajax'));
        $isAjax = in_array($ajaxFlag, ['1', 'true', 'yes', 'on'], true)
            || $requestedWith === 'xmlhttprequest'
            || strpos($accept, 'application/json') !== false
            || strpos($contentType, 'application/json') !== false;
        if ($isAjax) {
            return true;
        }

        if ($fc === 'module') {
            return true;
        }

        // Keep checkout/order out of auto mode, but allow plain cart page views
        // so pending events can be drained without waiting for another route.
        if (in_array($phpSelf, ['order', 'checkout'], true)) {
            return true;
        }

        $mutationParams = ['add', 'update', 'delete', 'qty', 'id_product', 'id_product_attribute'];
        foreach ($mutationParams as $param) {
            if (Tools::getValue($param) !== false && Tools::getValue($param) !== null) {
                return true;
            }
        }

        $sensitivePathPatterns = [
            '/module/ps_shoppingcart/ajax',
            '/module/blockwishlist/',
            '/module/productcomments/',
            '/commande',
        ];
        foreach ($sensitivePathPatterns as $pattern) {
            if (strpos($requestUri, $pattern) !== false) {
                return true;
            }
        }

        // Block cart mutation endpoints, but keep controller=cart?action=show eligible.
        if (in_array($action, ['add-to-cart', 'refresh', 'update', 'delete'], true)) {
            return true;
        }

        if ($controller === 'cart' && $action !== '' && $action !== 'show') {
            return true;
        }

        return false;
    }

    private function isModuleCronModeEnabled(): bool
    {
        $mode = trim((string) Configuration::get('NC_EXECUTION_MODE'));

        return in_array($mode, ['auto', 'cron_module'], true);
    }

    private function shouldFastTrackPendingAutoRun(int $elapsedSinceLastCall): bool
    {
        // Guard against tight-loop triggering on page navigation.
        if ($elapsedSinceLastCall < 8) {
            return false;
        }

        return $this->hasPendingEventsReadyForDispatch();
    }

    private function hasPendingEventsReadyForDispatch(): bool
    {
        $shopId = (int)($this->context->shop->id ?? 0);
        $shopFilter = $shopId > 0 ? ' AND shop_id = ' . $shopId : '';

        $hasPending = Db::getInstance()->getValue(
            '
            SELECT 1
            FROM `'._DB_PREFIX_.'neurocheckout_event`
            WHERE status = "pending"
            '.$shopFilter.'
              AND (next_retry_at IS NULL OR next_retry_at <= NOW())
            '
        );

        if ($hasPending) {
            return true;
        }

        try {
            if ($shopId > 0) {
                $telemetryRepo = new TelemetryEventRepository($shopId);
                if ($telemetryRepo->countPendingReady() > 0) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                '[NC] Pending telemetry lookup failed: ' . $e->getMessage(),
                2
            );
        }

        try {
            if ($shopId > 0) {
                $journeyRepo = new CustomerJourneyEventRepository($shopId);
                return $journeyRepo->countPendingReady() > 0;
            }
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                '[NC] Pending customer journey lookup failed: ' . $e->getMessage(),
                2
            );
        }

        return false;
    }

    private function getDbErrorMessage(): string
    {
        $db = Db::getInstance();
        if (method_exists($db, 'getMsgError')) {
            $msg = trim((string) $db->getMsgError());
            if ($msg !== '') {
                return $msg;
            }
        }
        return 'unknown_db_error';
    }

    private function logSqlError(string $context, array $meta = []): void
    {
        $parts = ['[NC] SQL error', 'context=' . $context, 'db=' . $this->getDbErrorMessage()];
        foreach ($meta as $key => $value) {
            $parts[] = $key . '=' . $value;
        }
        PrestaShopLogger::addLog(implode(' | ', $parts), 3);
    }

    public function acquireCronLock(): bool
    {
        return (int) Db::getInstance()->getValue(
            "SELECT GET_LOCK('nc_cron_lock', 0)"
        ) === 1;
    }

    public function releaseCronLock(): void
    {
        Db::getInstance()->execute("SELECT RELEASE_LOCK('nc_cron_lock')");
    }

    public function executeCronRun(bool $isTestRun = false, bool $isDebugForceRun = false): array
    {
        $startTime = microtime(true);

        try {
            $shops = Shop::getShops(true, null, true);
            $totalProcessed = 0;

            foreach ($shops as $shopId) {
                Shop::setContext(Shop::CONTEXT_SHOP, (int) $shopId);

                $logRepo = new \NeuroCheckout\Infrastructure\CronLogRepository((int) $shopId);
                $blockedUntil = (int) Configuration::get('NC_CRON_BLOCKED_UNTIL_' . $shopId);

                if ($blockedUntil && time() < $blockedUntil) {
                    continue;
                }

                if ($logRepo->countRecentErrors(10) >= 5) {
                    Configuration::updateValue(
                        'NC_CRON_BLOCKED_UNTIL_' . $shopId,
                        time() + 600
                    );

                    $logRepo->log('blocked', 0, 0, 'Auto-block triggered');
                    continue;
                }

                $lastRun = (int) Configuration::get('NC_CRON_LAST_RUN_' . $shopId);
                if ($lastRun && (time() - $lastRun) < 30) {
                    continue;
                }

                Configuration::updateValue('NC_CRON_LAST_RUN_' . $shopId, time());

                $shopStartTime = microtime(true);
                $dispatcher = new \NeuroCheckout\Application\EventDispatcher((int) $shopId);
                $processed = (int) $dispatcher->dispatch(100, $isTestRun);
                $stats = $dispatcher->getLastRunStats();
                $orderRetryStats = $this->retryPendingOrderCompletedEvents((int) $shopId, 30);
                $telemetryStats = $this->processPendingTelemetryEvents((int) $shopId, 50);
                $journeyStats = $this->processPendingCustomerJourneyEvents((int) $shopId, 75);

                $executionTime = (int) round((microtime(true) - $shopStartTime) * 1000);
                $processed += (int) ($orderRetryStats['sent'] ?? 0);
                $processed += (int) ($telemetryStats['sent'] ?? 0);
                $processed += (int) ($journeyStats['sent'] ?? 0);
                $failedEvents = (int) ($stats['failed'] ?? 0)
                    + (int) ($orderRetryStats['failed'] ?? 0)
                    + (int) ($telemetryStats['failed'] ?? 0)
                    + (int) ($journeyStats['failed'] ?? 0);
                $deadOrderRetries = (int) ($orderRetryStats['dead'] ?? 0);
                $deadTelemetryEvents = (int) ($telemetryStats['dead'] ?? 0);
                $deadJourneyEvents = (int) ($journeyStats['dead'] ?? 0);
                $isFatal = !empty($stats['fatal']);
                $reason = (string) ($stats['reason'] ?? '');
                $nonErrorReasons = ['no_pending_events'];
                $isOperationalError = $reason !== '' && !in_array($reason, $nonErrorReasons, true);
                $logStatus = ($failedEvents > 0 || $deadOrderRetries > 0 || $deadTelemetryEvents > 0 || $deadJourneyEvents > 0 || $isFatal || $isOperationalError) ? 'error' : 'success';
                $errorMessage = null;

                if ($logStatus === 'error') {
                    $parts = [];
                    if ($reason !== '') {
                        $parts[] = 'reason=' . $reason;
                    }
                    if ($failedEvents > 0) {
                        $parts[] = 'failed_events=' . $failedEvents;
                    }
                    if ($deadOrderRetries > 0) {
                        $parts[] = 'dead_order_retries=' . $deadOrderRetries;
                    }
                    if ($deadTelemetryEvents > 0) {
                        $parts[] = 'dead_telemetry_events=' . $deadTelemetryEvents;
                    }
                    if ($deadJourneyEvents > 0) {
                        $parts[] = 'dead_customer_journey_events=' . $deadJourneyEvents;
                    }
                    if ($isFatal) {
                        $parts[] = 'fatal=1';
                    }
                    $errorMessage = implode(' | ', $parts);
                }

                $logRepo->log($logStatus, $processed, $executionTime, $errorMessage);
                Configuration::deleteByName('NC_HEALTH_CACHE_' . (int) $shopId);
                $totalProcessed += $processed;
            }

            Configuration::updateValue('NC_LAST_AUTO_RUN', time());

            $result = [
                'success' => true,
                'status_code' => 200,
                'processed_events' => $totalProcessed,
                'execution_time' => (int) round((microtime(true) - $startTime) * 1000),
                'test_mode' => $isTestRun,
                'timestamp' => date('Y-m-d H:i:s'),
            ];

            if (($isTestRun || $isDebugForceRun) && $totalProcessed <= 0) {
                $result['success'] = false;
                $result['status_code'] = 422;
                $result['error'] = $isDebugForceRun
                    ? ModuleTranslator::trans('cron_no_cart_force')
                    : ModuleTranslator::trans('cron_no_cart_test');
            }

            return $result;
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                '[NeuroCheckout] Cron fatal: ' . $e->getMessage(),
                3
            );

            return [
                'success' => false,
                'status_code' => 500,
                'error' => ModuleTranslator::trans('internal_error'),
                'timestamp' => date('Y-m-d H:i:s'),
            ];
        }
    }

    private function ensureOrderRetryTable(): bool
    {
        $query = '
            CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'neurocheckout_order_event` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `shop_id` INT UNSIGNED NOT NULL,
                `order_id` VARCHAR(64) NOT NULL,
                `cart_id` VARCHAR(64) NOT NULL,
                `payload` LONGTEXT NOT NULL,
                `status` ENUM(\'pending\',\'sent\',\'dead\') NOT NULL DEFAULT \'pending\',
                `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `next_retry_at` DATETIME DEFAULT NULL,
                `last_error` VARCHAR(255) DEFAULT NULL,
                `created_at` DATETIME NOT NULL,
                `updated_at` DATETIME NOT NULL,
                `sent_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_shop_order` (`shop_id`, `order_id`),
                KEY `idx_dispatch` (`status`, `next_retry_at`, `created_at`),
                KEY `idx_shop_status` (`shop_id`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ';

        if (!Db::getInstance()->execute($query)) {
            $this->logSqlError('order_retry_table_create_failed');
            return false;
        }

        return true;
    }

    private function queueOrderCompletedForRetry(array $payload, int $shopId, string $errorMessage = ''): void
    {
        $orderId = trim((string)($payload['order_id'] ?? ''));
        $cartId = trim((string)($payload['cart_id'] ?? ''));

        if ($shopId <= 0 || $orderId === '' || $cartId === '') {
            return;
        }

        if (!$this->ensureOrderRetryTable()) {
            return;
        }

        $payloadJson = json_encode($payload);
        if (!is_string($payloadJson) || $payloadJson === '') {
            return;
        }

        $shortError = trim((string)$errorMessage);
        if ($shortError === '') {
            $shortError = 'order_send_failed';
        }
        if (strlen($shortError) > 255) {
            $shortError = substr($shortError, 0, 255);
        }

        $query = '
            INSERT INTO `' . _DB_PREFIX_ . 'neurocheckout_order_event`
                (`shop_id`, `order_id`, `cart_id`, `payload`, `status`, `attempts`, `next_retry_at`, `last_error`, `created_at`, `updated_at`)
            VALUES
                (' . (int)$shopId . ',
                 "' . pSQL($orderId) . '",
                 "' . pSQL($cartId) . '",
                 "' . pSQL($payloadJson, true) . '",
                 "pending",
                 0,
                 NOW(),
                 "' . pSQL($shortError) . '",
                 NOW(),
                 NOW())
            ON DUPLICATE KEY UPDATE
                `payload` = IF(`status` IN ("sent", "dead"), `payload`, VALUES(`payload`)),
                `cart_id` = IF(`status` IN ("sent", "dead"), `cart_id`, VALUES(`cart_id`)),
                `next_retry_at` = IF(`status` IN ("sent", "dead"), `next_retry_at`, NOW()),
                `last_error` = IF(`status` IN ("sent", "dead"), `last_error`, VALUES(`last_error`)),
                `updated_at` = VALUES(`updated_at`),
                `status` = IF(`status` IN ("sent", "dead"), `status`, "pending")
        ';

        if (!Db::getInstance()->execute($query)) {
            $this->logSqlError('order_retry_enqueue_failed', [
                'shop_id' => (string)$shopId,
                'order_id' => $orderId,
            ]);
            return;
        }

        PrestaShopLogger::addLog(
            '[NC] Order completed queued for retry (shop=' . $shopId . ', order=' . $orderId . ')',
            1
        );
    }

    private function markOrderRetryAsSent(int $shopId, string $orderId): void
    {
        if ($shopId <= 0 || trim($orderId) === '') {
            return;
        }

        if (!$this->ensureOrderRetryTable()) {
            return;
        }

        Db::getInstance()->execute(
            '
            UPDATE `' . _DB_PREFIX_ . 'neurocheckout_order_event`
            SET `status` = "sent",
                `payload` = "",
                `sent_at` = NOW(),
                `last_error` = NULL,
                `next_retry_at` = NULL,
                `updated_at` = NOW()
            WHERE `shop_id` = ' . (int)$shopId . '
              AND `order_id` = "' . pSQL($orderId) . '"
              AND `status` <> "sent"
            '
        );
    }

    private function retryPendingOrderCompletedEvents(int $shopId, int $limit = 30): array
    {
        $stats = [
            'sent' => 0,
            'failed' => 0,
            'dead' => 0,
        ];

        if ($shopId <= 0 || $limit <= 0) {
            return $stats;
        }

        if (!$this->ensureOrderRetryTable()) {
            return $stats;
        }

        Db::getInstance()->execute(
            '
            UPDATE `' . _DB_PREFIX_ . 'neurocheckout_order_event`
            SET `status` = "dead",
                `payload` = "",
                `next_retry_at` = NULL,
                `last_error` = "retry_window_expired",
                `updated_at` = NOW()
            WHERE `shop_id` = ' . (int)$shopId . '
              AND `status` = "pending"
              AND `created_at` < DATE_SUB(NOW(), INTERVAL 48 HOUR)
            '
        );

        $rows = Db::getInstance()->executeS(
            '
            SELECT `id`, `order_id`, `attempts`, `payload`
            FROM `' . _DB_PREFIX_ . 'neurocheckout_order_event`
            WHERE `shop_id` = ' . (int)$shopId . '
              AND `status` = "pending"
              AND (`next_retry_at` IS NULL OR `next_retry_at` <= NOW())
            ORDER BY `created_at` ASC
            LIMIT ' . (int)$limit
        );

        if (!is_array($rows) || empty($rows)) {
            return $stats;
        }

        $client = new \NeuroCheckout\Http\SecureHttpClient();

        foreach ($rows as $row) {
            $rowId = (int)($row['id'] ?? 0);
            $orderId = trim((string)($row['order_id'] ?? ''));
            $attempts = (int)($row['attempts'] ?? 0);
            $payloadRaw = (string)($row['payload'] ?? '');
            $payload = json_decode($payloadRaw, true);

            if ($rowId <= 0 || $orderId === '' || !is_array($payload) || empty($payload)) {
                if ($rowId > 0) {
                    Db::getInstance()->execute(
                        '
                        UPDATE `' . _DB_PREFIX_ . 'neurocheckout_order_event`
                        SET `status` = "dead",
                            `payload` = "",
                            `last_error` = "invalid_payload",
                            `updated_at` = NOW()
                        WHERE `id` = ' . $rowId
                    );
                    $stats['dead']++;
                }
                continue;
            }

            $result = $client->sendOrderCompleted($payload);

            if (!empty($result['success'])) {
                Db::getInstance()->execute(
                    '
                    UPDATE `' . _DB_PREFIX_ . 'neurocheckout_order_event`
                    SET `status` = "sent",
                        `payload` = "",
                        `sent_at` = NOW(),
                        `last_error` = NULL,
                        `next_retry_at` = NULL,
                        `updated_at` = NOW()
                    WHERE `id` = ' . $rowId
                );
                $stats['sent']++;
                continue;
            }

            $nextAttempts = $attempts + 1;
            $statusCode = (int)($result['status'] ?? 0);
            $error = (string)($result['error'] ?? ('HTTP ' . $statusCode));
            if ($error === '') {
                $error = 'order_send_failed';
            }
            if (strlen($error) > 255) {
                $error = substr($error, 0, 255);
            }

            if ($nextAttempts >= 12) {
                Db::getInstance()->execute(
                    '
                    UPDATE `' . _DB_PREFIX_ . 'neurocheckout_order_event`
                    SET `status` = "dead",
                        `payload` = "",
                        `attempts` = ' . (int)$nextAttempts . ',
                        `last_error` = "' . pSQL($error) . '",
                        `updated_at` = NOW()
                    WHERE `id` = ' . $rowId
                );
                $stats['dead']++;
            } else {
                $delaySeconds = $this->computeOrderRetryDelaySeconds($nextAttempts);
                Db::getInstance()->execute(
                    '
                    UPDATE `' . _DB_PREFIX_ . 'neurocheckout_order_event`
                    SET `status` = "pending",
                        `attempts` = ' . (int)$nextAttempts . ',
                        `next_retry_at` = DATE_ADD(NOW(), INTERVAL ' . (int)$delaySeconds . ' SECOND),
                        `last_error` = "' . pSQL($error) . '",
                        `updated_at` = NOW()
                    WHERE `id` = ' . $rowId
                );
                $stats['failed']++;
            }

            PrestaShopLogger::addLog(
                '[NC] Order completed retry failed (shop=' . $shopId . ', order=' . $orderId . ', error=' . $error . ')',
                2
            );
        }

        return $stats;
    }

    private function computeOrderRetryDelaySeconds(int $attempts): int
    {
        $attempt = max(1, $attempts);
        $delay = (int) (30 * pow(2, min($attempt - 1, 5)));

        return min(900, max(30, $delay));
    }

    public function getTelemetryPublicToken(): string
    {
        $token = trim(SecretConfiguration::get('NC_TELEMETRY_PUBLIC_TOKEN'));
        if ($token !== '') {
            return $token;
        }

        $token = bin2hex(random_bytes(16));
        if (!SecretConfiguration::set('NC_TELEMETRY_PUBLIC_TOKEN', $token)) {
            PrestaShopLogger::addLog('[NC] Telemetry token encryption failed; browser telemetry disabled', 3);
            return '';
        }

        return $token;
    }

    public function enqueueTelemetryPayload(array $payload, string $origin = 'server'): bool
    {
        try {
            if (!$this->isTelemetryEnabled()) {
                return false;
            }

            if ($origin === 'browser') {
                $payload = TelemetryEventBuilder::buildFromBrowserPayload(
                    $payload,
                    $this->context,
                    $this->version
                ) ?: [];
            }

            if (empty($payload)) {
                return false;
            }

            $eventType = trim((string) ($payload['event_type'] ?? ''));
            if (!TelemetryEventBuilder::isSupportedEventType($eventType)) {
                return false;
            }

            $shopId = $this->resolveCurrentTelemetryShopId();
            if ($shopId <= 0) {
                return false;
            }

            $repo = new TelemetryEventRepository($shopId);
            return $repo->enqueue($payload);
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('[NC] Telemetry enqueue failed: ' . $e->getMessage(), 2);
            return false;
        }
    }

    public function kickTelemetryDispatch(): void
    {
        try {
            $this->triggerDeferredCronDispatch(false);
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('[NC] Telemetry cron kick failed: ' . $e->getMessage(), 2);
        }
    }

    public function getCustomerJourneyPublicToken(): string
    {
        $token = trim(SecretConfiguration::get('NC_CUSTOMER_JOURNEY_PUBLIC_TOKEN'));
        if ($token !== '') {
            return $token;
        }

        $token = bin2hex(random_bytes(16));
        if (!SecretConfiguration::set('NC_CUSTOMER_JOURNEY_PUBLIC_TOKEN', $token)) {
            PrestaShopLogger::addLog('[NC] Customer journey token encryption failed; browser journey disabled', 3);
            return '';
        }

        return $token;
    }

    public function enqueueCustomerJourneyPayload(array $payload, string $origin = 'server'): bool
    {
        try {
            if (!$this->isCustomerJourneyEnabled()) {
                return false;
            }

            if ($origin === 'browser') {
                $payload = CustomerJourneyEventBuilder::buildFromBrowserPayload(
                    $payload,
                    $this->context,
                    $this->version
                ) ?: [];
            }

            if (empty($payload)) {
                return false;
            }

            $eventType = trim((string) ($payload['event_type'] ?? ''));
            if (!CustomerJourneyEventBuilder::isSupportedEventType($eventType)) {
                return false;
            }

            $shopId = $this->resolveCurrentCustomerJourneyShopId();
            if ($shopId <= 0) {
                return false;
            }

            $repo = new CustomerJourneyEventRepository($shopId);
            return $repo->enqueue($payload);
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('[NC] Customer journey enqueue failed: ' . $e->getMessage(), 2);
            return false;
        }
    }

    public function kickCustomerJourneyDispatch(): void
    {
        try {
            $this->triggerDeferredCronDispatch(false);
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('[NC] Customer journey cron kick failed: ' . $e->getMessage(), 2);
        }
    }

    private function processPendingCustomerJourneyEvents(int $shopId, int $limit = 75): array
    {
        $stats = [
            'sent' => 0,
            'failed' => 0,
            'dead' => 0,
        ];

        if ($shopId <= 0 || $limit <= 0 || !$this->isCustomerJourneyEnabled()) {
            return $stats;
        }

        $endpoint = trim((string) Configuration::get('NC_API_ENDPOINT'));
        $apiKey = preg_replace('/\s+/', '', SecretConfiguration::get('NC_API_KEY'));
        if ($endpoint === '' || $apiKey === '' || !$this->isIaConfigurationReady()) {
            return $stats;
        }

        try {
            $repo = new CustomerJourneyEventRepository($shopId);
            $repo->expireRetryableEvents();
            $repo->releaseStuckProcessing(5);
            $rows = $repo->lockBatchAtomic($limit);
            if (empty($rows)) {
                return $stats;
            }

            $client = new \NeuroCheckout\Http\SecureHttpClient();
            foreach ($rows as $row) {
                $rowId = (int) ($row['id'] ?? 0);
                $payloadRaw = (string) ($row['payload'] ?? '');
                $payload = json_decode($payloadRaw, true);

                if ($rowId <= 0 || !is_array($payload) || empty($payload)) {
                    if ($rowId > 0 && $repo->markAsDead($rowId, 'invalid_payload')) {
                        $stats['dead']++;
                    }
                    continue;
                }

                $result = $client->sendCustomerJourneyEvent($payload);
                if (!empty($result['success'])) {
                    if ($repo->markAsSent($rowId)) {
                        $stats['sent']++;
                    }
                    continue;
                }

                $error = (string) ($result['error'] ?? ('HTTP ' . (int) ($result['status'] ?? 0)));
                $beforeDead = (int) ($row['attempts'] ?? 0) + 1 >= 8;
                if ($repo->markAsFailed($rowId, $error)) {
                    if ($beforeDead) {
                        $stats['dead']++;
                    } else {
                        $stats['failed']++;
                    }
                }
            }

            $repo->purgeTerminalBatch(14, 300);
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('[NC] Customer journey dispatch failed: ' . $e->getMessage(), 2);
            $stats['failed']++;
        }

        return $stats;
    }

    private function processPendingTelemetryEvents(int $shopId, int $limit = 50): array
    {
        $stats = [
            'sent' => 0,
            'failed' => 0,
            'dead' => 0,
        ];

        if ($shopId <= 0 || $limit <= 0 || !$this->isTelemetryEnabled()) {
            return $stats;
        }

        $endpoint = trim((string) Configuration::get('NC_API_ENDPOINT'));
        $apiKey = preg_replace('/\s+/', '', SecretConfiguration::get('NC_API_KEY'));
        if ($endpoint === '' || $apiKey === '' || !$this->isIaConfigurationReady()) {
            return $stats;
        }

        try {
            $repo = new TelemetryEventRepository($shopId);
            $repo->expireRetryableEvents();
            $repo->releaseStuckProcessing(5);
            $rows = $repo->lockBatchAtomic($limit);
            if (empty($rows)) {
                return $stats;
            }

            $client = new \NeuroCheckout\Http\SecureHttpClient();
            foreach ($rows as $row) {
                $rowId = (int) ($row['id'] ?? 0);
                $payloadRaw = (string) ($row['payload'] ?? '');
                $payload = json_decode($payloadRaw, true);

                if ($rowId <= 0 || !is_array($payload) || empty($payload)) {
                    if ($rowId > 0 && $repo->markAsDead($rowId, 'invalid_payload')) {
                        $stats['dead']++;
                    }
                    continue;
                }

                $result = $client->sendTelemetryEvent($payload);
                if (!empty($result['success'])) {
                    if ($repo->markAsSent($rowId)) {
                        $stats['sent']++;
                    }
                    continue;
                }

                $error = (string) ($result['error'] ?? ('HTTP ' . (int) ($result['status'] ?? 0)));
                $beforeDead = (int) ($row['attempts'] ?? 0) + 1 >= 8;
                if ($repo->markAsFailed($rowId, $error)) {
                    if ($beforeDead) {
                        $stats['dead']++;
                    } else {
                        $stats['failed']++;
                    }
                }
            }

            $repo->purgeTerminalBatch(14, 300);
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('[NC] Telemetry dispatch failed: ' . $e->getMessage(), 2);
            $stats['failed']++;
        }

        return $stats;
    }

    private function maybeInjectCustomerJourneyTrackerScript(): void
    {
        static $registered = false;
        if ($registered || !$this->isCustomerJourneyEnabled() || !$this->shouldLoadCustomerJourneyTrackerScript()) {
            return;
        }

        $token = $this->getCustomerJourneyPublicToken();
        if ($token === '') {
            return;
        }

        $endpoint = '';
        if ($this->context && isset($this->context->link) && $this->context->link) {
            $endpoint = (string) $this->context->link->getModuleLink($this->name, 'journey', [], true);
        }
        if ($endpoint === '') {
            return;
        }

        $controller = $this->context->controller ?? null;
        if (class_exists('Media') && method_exists('Media', 'addJsDef')) {
            Media::addJsDef([
                'ncCustomerJourneyTracker' => [
                    'endpoint' => $endpoint,
                    'token' => $token,
                    'moduleVersion' => $this->version,
                    'maxEventsPerPage' => 18,
                    'context' => $this->buildCustomerJourneyTrackerPageContext(),
                ],
            ]);
        }

        if (is_object($controller) && method_exists($controller, 'registerJavascript')) {
            $controller->registerJavascript(
                'module-' . $this->name . '-customer-journey-tracker',
                'modules/' . $this->name . '/views/js/customer-journey-tracker.js',
                [
                    'position' => 'bottom',
                    'priority' => 175,
                ]
            );
            $registered = true;
            return;
        }

        if (is_object($controller) && method_exists($controller, 'addJS')) {
            $controller->addJS($this->_path . 'views/js/customer-journey-tracker.js');
            $registered = true;
        }
    }

    private function shouldLoadCustomerJourneyTrackerScript(): bool
    {
        if (!isset($this->context->controller) || !is_object($this->context->controller)) {
            return false;
        }

        $requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($requestMethod, ['GET', 'HEAD'], true)) {
            return false;
        }

        $ajaxFlag = strtolower((string) Tools::getValue('ajax'));
        $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        if (in_array($ajaxFlag, ['1', 'true', 'yes', 'on'], true) || $requestedWith === 'xmlhttprequest') {
            return false;
        }

        $controller = strtolower((string) Tools::getValue('controller'));
        $phpSelf = '';
        if (isset($this->context->controller->php_self)) {
            $phpSelf = strtolower((string) $this->context->controller->php_self);
        }
        $current = $controller !== '' ? $controller : $phpSelf;

        $sensitiveControllers = [
            'authentication',
            'auth',
            'identity',
            'password',
            'address',
            'addresses',
            'history',
            'order-detail',
            'guest-tracking',
            'contact',
        ];
        if (in_array($current, $sensitiveControllers, true) || in_array($phpSelf, $sensitiveControllers, true)) {
            return false;
        }

        return true;
    }

    private function buildCustomerJourneyTrackerPageContext(): array
    {
        $controller = strtolower((string) Tools::getValue('controller'));
        $phpSelf = '';
        if (isset($this->context->controller->php_self)) {
            $phpSelf = strtolower((string) $this->context->controller->php_self);
        }

        $cartId = null;
        if ($this->context && isset($this->context->cart) && $this->context->cart instanceof Cart && $this->context->cart->id) {
            $cartId = (string) (int) $this->context->cart->id;
        }

        return [
            'controller' => $controller,
            'phpSelf' => $phpSelf,
            'pageType' => $this->resolveCustomerJourneyPageType($controller, $phpSelf),
            'isCheckoutLikePage' => $this->isCustomerJourneyCheckoutLikePage($controller, $phpSelf),
            'cartId' => $cartId,
            'customerLoggedIn' => $this->context
                && isset($this->context->customer)
                && $this->context->customer
                && (bool) $this->context->customer->isLogged(),
            'product' => $this->resolveCustomerJourneyProductContext(),
            'category' => $this->resolveCustomerJourneyCategoryContext(),
        ];
    }

    private function resolveCustomerJourneyPageType(string $controller, string $phpSelf): string
    {
        $current = $controller !== '' ? $controller : $phpSelf;
        if (in_array($current, ['product'], true) || (int) Tools::getValue('id_product') > 0) {
            return 'product';
        }
        if (in_array($current, ['category'], true) || (int) Tools::getValue('id_category') > 0) {
            return 'category';
        }
        if (in_array($current, ['cart'], true)) {
            return 'cart';
        }
        if ($this->isCustomerJourneyCheckoutLikePage($controller, $phpSelf)) {
            return 'checkout';
        }
        if (in_array($current, ['search'], true)) {
            return 'search';
        }

        return 'page';
    }

    private function isCustomerJourneyCheckoutLikePage(string $controller, string $phpSelf): bool
    {
        $requestUri = strtolower((string) ($_SERVER['REQUEST_URI'] ?? ''));
        return in_array($controller, ['order', 'checkout', 'orderopc'], true)
            || in_array($phpSelf, ['order', 'checkout', 'orderopc'], true)
            || strpos($requestUri, 'checkout') !== false
            || strpos($requestUri, 'commande') !== false;
    }

    private function resolveCustomerJourneyProductContext(): array
    {
        $productId = (int) Tools::getValue('id_product');
        if ($productId <= 0 && isset($this->context->controller) && is_object($this->context->controller)) {
            if (isset($this->context->controller->product) && $this->context->controller->product instanceof Product) {
                $productId = (int) $this->context->controller->product->id;
            }
        }
        if ($productId <= 0) {
            return [];
        }

        try {
            $languageId = $this->resolveCurrentCustomerJourneyLanguageId();
            $product = new Product($productId, false, $languageId);
            if (!$product->id) {
                return ['id' => (string) $productId];
            }

            $categoryId = (int) ($product->id_category_default ?? 0);
            $categoryName = null;
            if ($categoryId > 0) {
                try {
                    $category = new Category($categoryId, $languageId);
                    $categoryName = !empty($category->name) ? (string) $category->name : null;
                } catch (\Throwable $e) {
                    $categoryName = null;
                }
            }

            return [
                'id' => (string) $productId,
                'name' => substr(trim((string) ($product->name ?? '')), 0, 180),
                'categoryId' => $categoryId > 0 ? (string) $categoryId : null,
                'categoryName' => $categoryName !== null ? substr(trim($categoryName), 0, 120) : null,
            ];
        } catch (\Throwable $e) {
            return ['id' => (string) $productId];
        }
    }

    private function resolveCustomerJourneyCategoryContext(): array
    {
        $categoryId = (int) Tools::getValue('id_category');
        if ($categoryId <= 0 && isset($this->context->controller) && is_object($this->context->controller)) {
            if (isset($this->context->controller->category) && $this->context->controller->category instanceof Category) {
                $categoryId = (int) $this->context->controller->category->id;
            }
        }
        if ($categoryId <= 0) {
            return [];
        }

        try {
            $category = new Category($categoryId, $this->resolveCurrentCustomerJourneyLanguageId());
            if (!$category->id) {
                return ['id' => (string) $categoryId];
            }

            return [
                'id' => (string) $categoryId,
                'name' => substr(trim((string) ($category->name ?? '')), 0, 180),
            ];
        } catch (\Throwable $e) {
            return ['id' => (string) $categoryId];
        }
    }

    private function resolveCurrentCustomerJourneyLanguageId(): int
    {
        if ($this->context && isset($this->context->language) && $this->context->language && (int) $this->context->language->id > 0) {
            return (int) $this->context->language->id;
        }

        return (int) Configuration::get('PS_LANG_DEFAULT');
    }

    private function maybeQueueCustomerJourneyCartSnapshot(Cart $cart, string $hookName): void
    {
        try {
            if (!$this->isCustomerJourneyEnabled() || !$this->isIaConfigurationReady()) {
                return;
            }

            $payload = CustomerJourneyEventBuilder::buildServerCartSnapshot(
                $cart,
                $this->context,
                $this->version,
                $hookName
            );
            if (empty($payload)) {
                return;
            }

            if ($this->enqueueCustomerJourneyPayload($payload, 'server')) {
                $this->kickCustomerJourneyDispatch();
            }
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('[NC] Customer journey cart snapshot failed: ' . $e->getMessage(), 2);
        }
    }

    private function maybeQueueCustomerJourneyOrderCompleted(Order $order, string $hookName): void
    {
        try {
            if (!$this->isCustomerJourneyEnabled() || !$this->isIaConfigurationReady()) {
                return;
            }

            $payload = CustomerJourneyEventBuilder::buildServerOrderCompleted(
                $order,
                $this->context,
                $this->version,
                $hookName
            );
            if (empty($payload)) {
                return;
            }

            if ($this->enqueueCustomerJourneyPayload($payload, 'server')) {
                $this->kickCustomerJourneyDispatch();
            }
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('[NC] Customer journey order completed failed: ' . $e->getMessage(), 2);
        }
    }

    private function maybeInjectCheckoutTelemetryScript(): void
    {
        static $registered = false;
        if ($registered || !$this->isTelemetryEnabled() || !$this->shouldLoadCheckoutTelemetryScript()) {
            return;
        }

        $token = $this->getTelemetryPublicToken();
        if ($token === '') {
            return;
        }

        $endpoint = '';
        if ($this->context && isset($this->context->link) && $this->context->link) {
            $endpoint = (string) $this->context->link->getModuleLink($this->name, 'telemetry', [], true);
        }
        if ($endpoint === '') {
            return;
        }

        $controller = $this->context->controller ?? null;
        $phpSelf = '';
        if (is_object($controller) && isset($controller->php_self)) {
            $phpSelf = (string) $controller->php_self;
        }

        if (class_exists('Media') && method_exists('Media', 'addJsDef')) {
            Media::addJsDef([
                'ncCheckoutTelemetry' => [
                    'endpoint' => $endpoint,
                    'token' => $token,
                    'controller' => (string) Tools::getValue('controller'),
                    'phpSelf' => $phpSelf,
                    'moduleVersion' => $this->version,
                    'slowRequestMs' => 5000,
                    'slowCheckoutMs' => 5000,
                    'maxEventsPerPage' => 12,
                    'maxIssueEventsPerPage' => 8,
                ],
            ]);
        }

        if (is_object($controller) && method_exists($controller, 'registerJavascript')) {
            $controller->registerJavascript(
                'module-' . $this->name . '-checkout-telemetry',
                'modules/' . $this->name . '/views/js/checkout-telemetry.js',
                [
                    'position' => 'bottom',
                    'priority' => 180,
                ]
            );
            $registered = true;
            return;
        }

        if (is_object($controller) && method_exists($controller, 'addJS')) {
            $controller->addJS($this->_path . 'views/js/checkout-telemetry.js');
            $registered = true;
        }
    }

    private function shouldLoadCheckoutTelemetryScript(): bool
    {
        if (!isset($this->context->controller) || !is_object($this->context->controller)) {
            return false;
        }

        $requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($requestMethod, ['GET', 'HEAD'], true)) {
            return false;
        }

        $ajaxFlag = strtolower((string) Tools::getValue('ajax'));
        $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        if (in_array($ajaxFlag, ['1', 'true', 'yes', 'on'], true) || $requestedWith === 'xmlhttprequest') {
            return false;
        }

        $controller = strtolower((string) Tools::getValue('controller'));
        $phpSelf = '';
        if (isset($this->context->controller->php_self)) {
            $phpSelf = strtolower((string) $this->context->controller->php_self);
        }
        $requestUri = strtolower((string) ($_SERVER['REQUEST_URI'] ?? ''));

        if (in_array($controller, ['order', 'cart', 'checkout', 'orderopc'], true)) {
            return true;
        }
        if (in_array($phpSelf, ['order', 'cart', 'checkout', 'orderopc'], true)) {
            return true;
        }

        return strpos($requestUri, 'checkout') !== false
            || strpos($requestUri, 'commande') !== false
            || strpos($requestUri, 'panier') !== false
            || strpos($requestUri, '/cart') !== false
            || strpos($requestUri, '/order') !== false;
    }

    private function maybeQueueShippingCostTelemetrySnapshot(): void
    {
        if (!$this->isTelemetryEnabled() || !$this->shouldLoadCheckoutTelemetryScript()) {
            return;
        }

        try {
            $cart = null;
            if ($this->context && isset($this->context->cart) && $this->context->cart instanceof Cart) {
                $cart = $this->context->cart;
            }
            if (!$cart || !$cart->id) {
                return;
            }

            $products = $cart->getProducts(true);
            if (!is_array($products) || empty($products)) {
                return;
            }

            $payload = TelemetryEventBuilder::buildShippingCostSnapshot(
                $cart,
                $this->context,
                $this->version
            );
            if (empty($payload)) {
                return;
            }

            if ($this->enqueueTelemetryPayload($payload, 'server')) {
                $this->kickTelemetryDispatch();
            }
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                '[NC] Shipping telemetry snapshot failed: ' . $e->getMessage(),
                2
            );
        }
    }

    private function resolveCurrentTelemetryShopId(): int
    {
        if ($this->context && isset($this->context->shop) && $this->context->shop && (int) $this->context->shop->id > 0) {
            return (int) $this->context->shop->id;
        }

        return (int) Configuration::get('PS_SHOP_DEFAULT');
    }

    private function resolveCurrentCustomerJourneyShopId(): int
    {
        if ($this->context && isset($this->context->shop) && $this->context->shop && (int) $this->context->shop->id > 0) {
            return (int) $this->context->shop->id;
        }

        return (int) Configuration::get('PS_SHOP_DEFAULT');
    }

    private function isTelemetryEnabled(): bool
    {
        return trim((string) Configuration::get('NC_TELEMETRY_ENABLED')) !== '0';
    }

    private function isCustomerJourneyEnabled(): bool
    {
        return trim((string) Configuration::get('NC_CUSTOMER_JOURNEY_ENABLED')) !== '0';
    }

    private function extractOrderFromStatusHookParams($params): ?Order
    {
        if (is_array($params)) {
            foreach (['order', 'object'] as $key) {
                if (isset($params[$key]) && $params[$key] instanceof Order) {
                    return $params[$key];
                }
            }

            $orderId = 0;
            if (isset($params['id_order'])) {
                $orderId = (int) $params['id_order'];
            } elseif (isset($params['id_order_state']) && isset($params['order_id'])) {
                $orderId = (int) $params['order_id'];
            }

            if ($orderId > 0) {
                try {
                    $order = new Order($orderId);
                    return $order->id ? $order : null;
                } catch (\Throwable $e) {
                    return null;
                }
            }
        }

        if ($params instanceof Order) {
            return $params;
        }

        if (is_object($params)) {
            if (property_exists($params, 'order') && $params->order instanceof Order) {
                return $params->order;
            }
            if (property_exists($params, 'id_order')) {
                try {
                    $order = new Order((int) $params->id_order);
                    return $order->id ? $order : null;
                } catch (\Throwable $e) {
                    return null;
                }
            }
        }

        return null;
    }

    private function extractOrderStateFromStatusHookParams($params, Order $order): ?OrderState
    {
        if (is_array($params)) {
            foreach (['newOrderStatus', 'newOrderState', 'orderStatus', 'orderState'] as $key) {
                if (isset($params[$key]) && $params[$key] instanceof OrderState) {
                    return $params[$key];
                }
            }

            $stateId = 0;
            if (isset($params['newOrderStatus']) && is_numeric($params['newOrderStatus'])) {
                $stateId = (int) $params['newOrderStatus'];
            } elseif (isset($params['id_order_state'])) {
                $stateId = (int) $params['id_order_state'];
            }

            if ($stateId > 0) {
                try {
                    $state = new OrderState($stateId);
                    return $state->id ? $state : null;
                } catch (\Throwable $e) {
                    return null;
                }
            }
        }

        $currentStateId = (int) ($order->current_state ?? 0);
        if ($currentStateId > 0) {
            try {
                $state = new OrderState($currentStateId);
                return $state->id ? $state : null;
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }

    private function isPaymentFailureOrderState(?OrderState $state, Order $order): bool
    {
        $stateId = $state && $state->id ? (int) $state->id : (int) ($order->current_state ?? 0);
        $paymentErrorStateId = (int) Configuration::get('PS_OS_ERROR');
        if ($paymentErrorStateId > 0 && $stateId === $paymentErrorStateId) {
            return true;
        }

        if (!$state) {
            return false;
        }

        $names = [];
        if (is_array($state->name ?? null)) {
            $names = array_map('strval', $state->name);
        } elseif (!empty($state->name)) {
            $names[] = (string) $state->name;
        }

        $joinedName = strtolower(trim(implode(' ', $names)));
        if ($joinedName === '') {
            return false;
        }

        $failureKeywords = [
            'payment error',
            'payment failed',
            'payment refused',
            'payment denied',
            'payment declined',
            'erreur paiement',
            'erreur de paiement',
            'paiement refuse',
            'paiement refus',
            'paiement echoue',
        ];

        foreach ($failureKeywords as $keyword) {
            if (strpos($joinedName, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /* ============================================================
     * BACK OFFICE
     * ============================================================ */

    public function hookDisplayBackOfficeHeader()
    {
        try {
            if (Tools::getValue('configure') !== $this->name) {
                return;
            }

            if (class_exists('Media') && method_exists('Media', 'addJsDef')) {
                Media::addJsDef([
                    'ncApiTestI18n' => ModuleTranslator::apiTestJsCatalog(),
                ]);
            }

            if (!isset($this->context->controller) || !is_object($this->context->controller)) {
                return;
            }

            if (!method_exists($this->context->controller, 'addJS')) {
                return;
            }

            $this->context->controller->addJS(
                $this->_path.'views/js/apitest.js'
            );
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                '[NC] BackOfficeHeader hook error: ' . $e->getMessage(),
                2
            );
        }
    }

    public function ajaxProcessSaveConfig()
    {
        header('Content-Type: application/json');

        try {
            $this->persistConfigurationFromRequest();
            die(json_encode([
                'success' => true,
                'message' => ModuleTranslator::trans('config_saved'),
            ]));
        } catch (\InvalidArgumentException $e) {
            die(json_encode([
                'success' => false,
                'message' => $e->getMessage(),
            ]));
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('[NC] Save config error: ' . $e->getMessage(), 3);
            die(json_encode([
                'success' => false,
                'message' => ModuleTranslator::trans('config_save_failed'),
            ]));
        }
    }

    public function ajaxProcessRunCron()
    {
        header('Content-Type: application/json');

        $employeeId = 0;
        if ($this->context && isset($this->context->employee) && $this->context->employee) {
            $employeeId = (int) $this->context->employee->id;
        }
        if ($employeeId <= 0) {
            http_response_code(403);
            die(json_encode([
                'success' => false,
                'error' => ModuleTranslator::trans('cron_admin_required'),
                'timestamp' => date('Y-m-d H:i:s'),
            ]));
        }

        $mode = strtolower(trim((string) Tools::getValue('mode')));
        $isTestRun = $mode === 'test';
        $isDebugForceRun = $mode === 'force';

        if (!$isTestRun && !$isDebugForceRun) {
            http_response_code(400);
            die(json_encode([
                'success' => false,
                'error' => ModuleTranslator::trans('cron_invalid_mode'),
                'timestamp' => date('Y-m-d H:i:s'),
            ]));
        }

        if ($isTestRun && !(bool) Configuration::get('NC_DEBUG_MODE')) {
            http_response_code(403);
            die(json_encode([
                'success' => false,
                'error' => ModuleTranslator::trans('debug_mode_disabled'),
                'timestamp' => date('Y-m-d H:i:s'),
            ]));
        }

        if ($isDebugForceRun && !(bool) Configuration::get('NC_DEBUG_ADVANCED')) {
            http_response_code(403);
            die(json_encode([
                'success' => false,
                'error' => ModuleTranslator::trans('advanced_debug_mode_disabled'),
                'timestamp' => date('Y-m-d H:i:s'),
            ]));
        }

        if (!$this->acquireCronLock()) {
            http_response_code(429);
            die(json_encode([
                'success' => false,
                'error' => ModuleTranslator::trans('cron_already_running'),
                'timestamp' => date('Y-m-d H:i:s'),
            ]));
        }

        try {
            $result = $this->executeCronRun($isTestRun, $isDebugForceRun);
        } finally {
            $this->releaseCronLock();
        }

        http_response_code((int) ($result['status_code'] ?? 500));
        die(json_encode($result));
    }

    private function isBackOfficeAjaxAction(string $expectedAction): bool
    {
        $ajaxFlag = strtolower(trim((string) Tools::getValue('ajax')));
        if (!in_array($ajaxFlag, ['1', 'true', 'yes', 'on'], true)) {
            return false;
        }

        $action = trim((string) Tools::getValue('action'));
        return strcasecmp($action, $expectedAction) === 0;
    }

    private function persistConfigurationFromRequest(): void
    {
        $this->validateIaConfigurationRequest();

        if (Tools::getIsset('NC_API_ENDPOINT')) {
            $submittedEndpoint = (string) Tools::getValue('NC_API_ENDPOINT');
            $normalizedEndpoint = EndpointPolicy::normalize($submittedEndpoint);
            if ($normalizedEndpoint === null) {
                throw new \InvalidArgumentException(
                    'The API endpoint must be an HTTPS NeuroCheckout Cloud URL without credentials, query or fragment.'
                );
            }
            $_POST['NC_API_ENDPOINT'] = $normalizedEndpoint;
        }

        $previousApiEndpoint = trim((string) Configuration::get('NC_API_ENDPOINT'));
        $previousApiKey = trim(SecretConfiguration::get('NC_API_KEY'));
        $previousShopExternalId = trim((string) Configuration::get('NC_SHOP_EXTERNAL_ID'));

        $map = [
            'NC_API_ENDPOINT'=>'string','NC_API_KEY'=>'string',
            'NC_SHOP_EXTERNAL_ID'=>'string','NC_EXECUTION_MODE'=>'string',
            'NC_OPAQUE_RECOVERY_LINKS'=>'bool',
            'NC_DEBUG_MODE'=>'bool',
            'NC_DEBUG_ADVANCED'=>'bool',
            'NC_CRON_ALLOWED_IPS'=>'string',
            'NC_TRUSTED_PROXY_IPS'=>'string',
            'NC_RECOVERY_ENABLED'=>'bool','NC_ENABLE_DISCOUNT'=>'bool',
            'NC_MIN_CART_TOTAL'=>'float','NC_ALLOW_GUEST'=>'bool',
            'NC_NO_DISCOUNT_MAX'=>'float','NC_DISCOUNT_5_MIN'=>'float',
            'NC_DISCOUNT_5_MAX'=>'float','NC_DISCOUNT_10_MIN'=>'float',
            'NC_MAX_DISCOUNT_PERCENT'=>'float'
        ];

        foreach ($map as $key=>$type) {

            if (!Tools::getIsset($key)) continue;

            $value = Tools::getValue($key);

            switch ($type) {
                case 'bool':  $value = $value ? 1 : 0; break;
                case 'float': $value = (float)$value; break;
                default:      $value = trim((string)$value); break;
            }

            if ($key === 'NC_API_KEY') {
                if ($value !== '') {
                    if (!SecretConfiguration::set('NC_API_KEY', (string) $value)) {
                        throw new \RuntimeException('The API key could not be encrypted; configuration was not saved.');
                    }
                }
                continue;
            }

            Configuration::updateValue($key,$value);
        }

        $apiSettingsChanged = (
            $previousApiEndpoint !== trim((string) Configuration::get('NC_API_ENDPOINT'))
            || $previousApiKey !== trim(SecretConfiguration::get('NC_API_KEY'))
            || $previousShopExternalId !== trim((string) Configuration::get('NC_SHOP_EXTERNAL_ID'))
        );
        if ($apiSettingsChanged) {
            $this->clearApiTestValidationState();
            if (!SecretConfiguration::set('NC_API_KEY_NEXT', '')) {
                throw new \RuntimeException('The pending API key state could not be cleared securely.');
            }
            Configuration::updateValue('NC_API_KEY_ROTATION_ID', '');
        }

        if ($this->isIaConfigurationSubmission()) {
            Configuration::updateValue('NC_RECOVERY_ENABLED', 1);
        }

        $debugMode = (int) Configuration::get('NC_DEBUG_MODE');
        $debugAdvanced = (int) Configuration::get('NC_DEBUG_ADVANCED');

        // Safety net: even if a crafted request sends both, keep modes mutually exclusive.
        if ($debugMode === 1 && $debugAdvanced === 1) {
            Configuration::updateValue('NC_DEBUG_ADVANCED', 0);
        }
    }

    private function validateIaConfigurationRequest(): void
    {
        if (!$this->isIaConfigurationSubmission()) {
            return;
        }

        if (!Tools::getIsset('NC_MIN_CART_TOTAL')) {
            throw new \InvalidArgumentException(
                ModuleTranslator::trans('min_cart_total_required')
            );
        }

        $rawMinCartTotal = trim((string) Tools::getValue('NC_MIN_CART_TOTAL'));
        if ($rawMinCartTotal === '') {
            throw new \InvalidArgumentException(
                ModuleTranslator::trans('min_cart_total_required')
            );
        }

        if (!is_numeric($rawMinCartTotal)) {
            throw new \InvalidArgumentException(
                ModuleTranslator::trans('min_cart_total_invalid')
            );
        }

        if ((float) $rawMinCartTotal < 0) {
            throw new \InvalidArgumentException(
                ModuleTranslator::trans('min_cart_total_invalid')
            );
        }

        if (!Tools::getIsset('NC_MAX_DISCOUNT_PERCENT')) {
            throw new \InvalidArgumentException(
                ModuleTranslator::trans('max_discount_percent_required')
            );
        }

        $rawValue = trim((string) Tools::getValue('NC_MAX_DISCOUNT_PERCENT'));
        if ($rawValue === '') {
            throw new \InvalidArgumentException(
                ModuleTranslator::trans('max_discount_percent_required')
            );
        }

        if (!is_numeric($rawValue)) {
            throw new \InvalidArgumentException(
                ModuleTranslator::trans('max_discount_percent_invalid')
            );
        }

        $numericValue = (float) $rawValue;
        if ($numericValue < 0 || $numericValue > 100) {
            throw new \InvalidArgumentException(
                ModuleTranslator::trans('max_discount_percent_invalid')
            );
        }
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

    private function isIaConfigurationSubmission(): bool
    {
        $iaKeys = [
            'NC_RECOVERY_ENABLED',
            'NC_ENABLE_DISCOUNT',
            'NC_MIN_CART_TOTAL',
            'NC_ALLOW_GUEST',
            'NC_NO_DISCOUNT_MAX',
            'NC_DISCOUNT_5_MIN',
            'NC_DISCOUNT_5_MAX',
            'NC_DISCOUNT_10_MIN',
            'NC_MAX_DISCOUNT_PERCENT',
        ];

        foreach ($iaKeys as $key) {
            if (Tools::getIsset($key)) {
                return true;
            }
        }

        return false;
    }

    public function detectRuntimeEnvironmentMode(): string
    {
        $override = strtolower(trim((string) getenv('NC_RUNTIME_ENV')));
        if ($override === '') {
            $override = strtolower(trim((string) Configuration::get('NC_RUNTIME_ENV')));
        }

        if (in_array($override, ['local', 'production'], true)) {
            return $override;
        }

        if (defined('_PS_MODE_DEV_') && _PS_MODE_DEV_) {
            return 'local';
        }

        $hostCandidates = [];
        if (!empty($_SERVER['HTTP_HOST'])) {
            $hostCandidates[] = (string) $_SERVER['HTTP_HOST'];
        }

        $shopDomainSsl = trim((string) Configuration::get('PS_SHOP_DOMAIN_SSL'));
        if ($shopDomainSsl !== '') {
            $hostCandidates[] = $shopDomainSsl;
        }

        $shopDomain = trim((string) Configuration::get('PS_SHOP_DOMAIN'));
        if ($shopDomain !== '') {
            $hostCandidates[] = $shopDomain;
        }

        foreach ($hostCandidates as $hostCandidate) {
            $host = strtolower(trim((string) $hostCandidate));
            $host = preg_replace('/:\d+$/', '', $host);

            if (
                $host === 'localhost'
                || $host === '127.0.0.1'
                || $host === '::1'
                || substr($host, -6) === '.local'
                || substr($host, -5) === '.test'
            ) {
                return 'local';
            }
        }

        return 'production';
    }

    public function markApiTestValidationSuccess(): void
    {
        Configuration::updateValue('NC_API_TEST_VALIDATED_AT', time());
        Configuration::updateValue(
            'NC_API_TEST_VALIDATION_FINGERPRINT',
            $this->buildApiTestValidationFingerprint()
        );
    }

    public function getApiTestValidatedAt(): int
    {
        return (int) Configuration::get('NC_API_TEST_VALIDATED_AT');
    }

    public function isApiTestValidationCurrent(): bool
    {
        $validatedAt = $this->getApiTestValidatedAt();
        if ($validatedAt <= 0) {
            return false;
        }

        $storedFingerprint = trim((string) Configuration::get('NC_API_TEST_VALIDATION_FINGERPRINT'));
        if ($storedFingerprint === '') {
            return false;
        }

        $currentFingerprint = $this->buildApiTestValidationFingerprint();

        return hash_equals($storedFingerprint, $currentFingerprint);
    }

    private function clearApiTestValidationState(): void
    {
        Configuration::updateValue('NC_API_TEST_VALIDATED_AT', 0);
        Configuration::updateValue('NC_API_TEST_VALIDATION_FINGERPRINT', '');
    }

    private function buildApiTestValidationFingerprint(): string
    {
        $endpoint = trim((string) Configuration::get('NC_API_ENDPOINT'));
        $apiKey = trim(SecretConfiguration::get('NC_API_KEY'));
        $shopExternalId = trim((string) Configuration::get('NC_SHOP_EXTERNAL_ID'));

        return hash(
            'sha256',
            implode('|', [$endpoint, $apiKey, $shopExternalId])
        );
    }

    public function getContent()
    {
        $saveConfigSubmitted = false;

        $this->refreshConnectorUpdateStatus();
        $updateNotice = $this->renderConnectorUpdateNotice();

        if ($this->isBackOfficeAjaxAction('saveConfig')) {
            $this->ajaxProcessSaveConfig();
        }

        if ($this->isBackOfficeAjaxAction('runCron')) {
            $this->ajaxProcessRunCron();
        }

        if (
            Tools::isSubmit('submitExecutionMode')
            || Tools::isSubmit('submitNcSaveConfig')
        ) {
            $this->persistConfigurationFromRequest();
            $saveConfigSubmitted = true;
        }

        $this->context->smarty->assign([
            'nc_i18n' => ModuleTranslator::backOfficeCatalog(
                (int) (Configuration::get('NC_AUTO_HOOK_INTERVAL') ?: 300)
            ),
            'nc_shop_currency_code' => $this->getDefaultShopCurrencyCode(),
            'nc_brand_mark_url' => $this->_path . 'views/img/connector-mark.svg',
            'nc_save_success' => $saveConfigSubmitted,
        ]);

        $general    = new \NeuroCheckout\BackOffice\GeneralFormRenderer($this);
        $execution  = new \NeuroCheckout\BackOffice\ExecutionFormRenderer($this);
        $ia         = new \NeuroCheckout\BackOffice\IAFormRenderer($this);
        $monitoring = new \NeuroCheckout\BackOffice\MonitoringFormRenderer($this);

        $this->context->smarty->assign([
            'general_content'=>$general->render(),
            'execution_content'=>$execution->render(),
            'ia_content'=>$ia->render(),
            'monitoring_content'=>$monitoring->render(),
            'ajax_save_url'=>$this->context->link->getAdminLink(
                'AdminModules',true,[],
                ['configure'=>$this->name,'ajax'=>1,'action'=>'saveConfig']
            )
        ]);

        return $updateNotice . $this->display(
            __FILE__,
            'views/templates/admin/configuration_agents.tpl'
        );
    }

    private function refreshConnectorUpdateStatus(): void
    {
        $lastChecked = (int) Configuration::get('NC_CONNECTOR_UPDATE_CHECKED_AT');
        if ($lastChecked > 0 && (time() - $lastChecked) < 86400) {
            return;
        }
        Configuration::updateValue('NC_CONNECTOR_UPDATE_CHECKED_AT', time());
        try {
            $result = (new SecureHttpClient())->checkConnectorVersion($this->version);
            if (empty($result['success']) || !is_string($result['body'] ?? null)) {
                return;
            }
            $payload = json_decode($result['body'], true);
            if (!is_array($payload) || ($payload['platform'] ?? '') !== 'prestashop') {
                return;
            }
            $releaseUrl = trim((string) ($payload['release_url'] ?? ''));
            if (strpos($releaseUrl, 'https://github.com/pisob/neurocheckout-connector-prestashop/releases') !== 0) {
                return;
            }
            Configuration::updateValue('NC_CONNECTOR_UPDATE_STATUS', (string) ($payload['status'] ?? 'current'));
            Configuration::updateValue('NC_CONNECTOR_LATEST_VERSION', (string) ($payload['latest_version'] ?? $this->version));
            Configuration::updateValue('NC_CONNECTOR_RELEASE_URL', $releaseUrl);
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('[NC] Connector version check failed: ' . $e->getMessage(), 2);
        }
    }

    private function renderConnectorUpdateNotice(): string
    {
        $status = trim((string) Configuration::get('NC_CONNECTOR_UPDATE_STATUS'));
        if (!in_array($status, ['available', 'required', 'blocked'], true)) {
            return '';
        }
        $latest = htmlspecialchars((string) Configuration::get('NC_CONNECTOR_LATEST_VERSION'), ENT_QUOTES, 'UTF-8');
        $url = htmlspecialchars((string) Configuration::get('NC_CONNECTOR_RELEASE_URL'), ENT_QUOTES, 'UTF-8');
        $message = 'NeuroCheckout Connector ' . $latest . ' is available. Back up your store, download the official release, then upload it over this installed module. Do not uninstall the existing module; its configuration and data will be preserved.';
        if ($url !== '') {
            $message .= ' <a href="' . $url . '" target="_blank" rel="noopener noreferrer">Download official update</a>';
        }
        return $status === 'available' ? $this->displayWarning($message) : $this->displayError($message);
    }

    private function getDefaultShopCurrencyCode(): string
    {
        try {
            $currencyId = (int) Configuration::get('PS_CURRENCY_DEFAULT');
            if ($currencyId > 0) {
                $currency = new Currency($currencyId);
                $iso = strtoupper(trim((string) ($currency->iso_code ?? '')));
                if ($iso !== '') {
                    return $iso;
                }
            }
        } catch (\Throwable $e) {
        }

        return '';
    }
}

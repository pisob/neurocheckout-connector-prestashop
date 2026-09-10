<?php

use NeuroCheckout\Application\EventDispatcher;
use NeuroCheckout\I18n\ModuleTranslator;
use NeuroCheckout\Infrastructure\CronLogRepository;
use NeuroCheckout\Infrastructure\NonceRepository;
use NeuroCheckout\Infrastructure\SecurityThrottleRepository;
use NeuroCheckout\Security\IpResolver;
use NeuroCheckout\Security\SecretConfiguration;

class NeuroCheckoutConnectorCronModuleFrontController extends ModuleFrontController
{
    public $ssl  = true;
    public $auth = false;
    public $ajax = true;

    const NONCE_TTL      = 120;
    const BLOCK_DURATION = 600;
    const LOCK_NAME      = 'nc_cron_lock';

    public function initContent()
    {
        parent::initContent();
        header('Content-Type: application/json');

        try {

            if (!$this->module->acquireCronLock()) {
                $this->jsonExit(429, ModuleTranslator::trans('cron_already_running'));
            }

            $mode  = (string) Tools::getValue('mode');
            $token = trim((string) ($_SERVER['HTTP_X_NEURO_CRON_TOKEN'] ?? ''));
            $isTestRun = (bool) filter_var(
                Tools::getValue('test', 0),
                FILTER_VALIDATE_BOOLEAN
            );
            $isDebugForceRun = (bool) filter_var(
                Tools::getValue('debug_force', 0),
                FILTER_VALIDATE_BOOLEAN
            );
            $expectedToken = SecretConfiguration::get('NEURO_CRON_TOKEN');
            $debugModeEnabled = (bool) Configuration::get('NC_DEBUG_MODE');
            $debugAdvancedEnabled = (bool) Configuration::get('NC_DEBUG_ADVANCED');
            $clientIp = $this->getRealIp();
            $shopId = max(1, (int) ($this->context->shop->id ?? Configuration::get('PS_SHOP_DEFAULT')));
            $throttleRepository = new SecurityThrottleRepository();
            $retryAfterSeconds = $throttleRepository->getRetryAfterSeconds($shopId, 'cron', $clientIp);

            if ($retryAfterSeconds > 0) {
                $this->module->releaseCronLock();
                $this->jsonExit(429, 'Too many invalid requests', [
                    'retry_after_seconds' => $retryAfterSeconds,
                ]);
            }

            if (empty($token) || !hash_equals($expectedToken, $token)) {
                $this->registerFailureAndExit($throttleRepository, $shopId, $clientIp, ModuleTranslator::trans('invalid_token'), 403);
            }

            if ($isTestRun && !$debugModeEnabled) {
                $this->module->releaseCronLock();
                $this->jsonExit(403, ModuleTranslator::trans('debug_mode_disabled'));
            }
            if ($isDebugForceRun && !$debugAdvancedEnabled) {
                $this->module->releaseCronLock();
                $this->jsonExit(403, ModuleTranslator::trans('advanced_debug_mode_disabled'));
            }

            /* ============================================================
             * MODE AUTO (interne serveur uniquement)
             * ============================================================ */

            if ($mode === 'auto') {

                if (!$this->isLoopbackIp($clientIp)) {
                    $this->module->releaseCronLock();
                    $this->jsonExit(403, ModuleTranslator::trans('auto_mode_restricted'));
                }

            } else {

                /* ============================================================
                 * HMAC VALIDATION (MODE CRON SERVEUR)
                 * ============================================================ */

                if (!$this->isAllowedCronIp($clientIp)) {
                    $this->registerFailureAndExit($throttleRepository, $shopId, $clientIp, ModuleTranslator::trans('cron_ip_not_allowed'), 403);
                }

                $timestamp = (int) ($_SERVER['HTTP_X_NEURO_TIMESTAMP'] ?? 0);
                $signature = trim((string) ($_SERVER['HTTP_X_NEURO_SIGNATURE'] ?? ''));
                $nonce     = trim((string) ($_SERVER['HTTP_X_NEURO_NONCE'] ?? ''));
                $apiKey    = SecretConfiguration::get('NC_API_KEY');

                if (!$timestamp || empty($signature) || empty($nonce) || empty($apiKey)) {
                    $this->registerFailureAndExit($throttleRepository, $shopId, $clientIp, ModuleTranslator::trans('missing_security_parameters'), 403);
                }

                if (abs(time() - $timestamp) > 60) {
                    $this->registerFailureAndExit($throttleRepository, $shopId, $clientIp, ModuleTranslator::trans('expired_signature'), 403);
                }

                $expectedSignature = hash_hmac(
                    'sha256',
                    $timestamp . '.' . $nonce . '.' . $token,
                    $apiKey
                );

                if (!hash_equals($expectedSignature, $signature)) {
                    $this->registerFailureAndExit($throttleRepository, $shopId, $clientIp, ModuleTranslator::trans('invalid_signature'), 403);
                }

                $nonceRepo = new NonceRepository();
                $nonceRepo->purgeExpired();

                if (!$nonceRepo->register($nonce, self::NONCE_TTL)) {
                    $this->registerFailureAndExit($throttleRepository, $shopId, $clientIp, ModuleTranslator::trans('replay_detected'), 409);
                }
            }

            $throttleRepository->clear($shopId, 'cron', $clientIp);

            $result = $this->module->executeCronRun($isTestRun, $isDebugForceRun);

            $this->module->releaseCronLock();

            if (empty($result['success'])) {
                $this->jsonExit(
                    (int) ($result['status_code'] ?? 500),
                    (string) ($result['error'] ?? ModuleTranslator::trans('internal_error'))
                );
            }

            unset($result['status_code']);

            echo json_encode($result);

            exit;

        } catch (\Throwable $e) {

            PrestaShopLogger::addLog(
                '[NeuroCheckout] Cron fatal: ' . $e->getMessage(),
                3
            );

            $this->module->releaseCronLock();
            $this->jsonExit(500, ModuleTranslator::trans('internal_error'));
        }
    }

    private function getRealIp(): string
    {
        $ipResolver = new IpResolver();
        $trustedProxyRules = $ipResolver->parseRules((string) Configuration::get('NC_TRUSTED_PROXY_IPS'));

        return $ipResolver->resolve($_SERVER, $trustedProxyRules);
    }

    private function isLoopbackIp(string $clientIp): bool
    {
        return in_array($clientIp, ['127.0.0.1', '::1'], true);
    }

    private function isAllowedCronIp(string $clientIp): bool
    {
        $rules = preg_split(
            '/[\s,;]+/',
            trim((string) Configuration::get('NC_CRON_ALLOWED_IPS'))
        );

        $rules = array_values(array_filter(array_map('trim', is_array($rules) ? $rules : [])));
        if (empty($rules)) {
            return true;
        }

        foreach ($rules as $rule) {
            if ($this->ipMatchesRule($clientIp, $rule)) {
                return true;
            }
        }

        return false;
    }

    private function ipMatchesRule(string $clientIp, string $rule): bool
    {
        if ($rule === '') {
            return false;
        }

        if (strpos($rule, '/') === false) {
            return hash_equals($rule, $clientIp);
        }

        return $this->ipMatchesCidr($clientIp, $rule);
    }

    private function ipMatchesCidr(string $clientIp, string $rule): bool
    {
        [$subnet, $prefix] = array_pad(explode('/', $rule, 2), 2, null);
        $prefix = is_numeric($prefix) ? (int) $prefix : -1;

        $ipBinary = @inet_pton($clientIp);
        $subnetBinary = @inet_pton((string) $subnet);

        if ($ipBinary === false || $subnetBinary === false || strlen($ipBinary) !== strlen($subnetBinary)) {
            return false;
        }

        $maxBits = strlen($ipBinary) * 8;
        if ($prefix < 0 || $prefix > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($fullBytes > 0 && substr($ipBinary, 0, $fullBytes) !== substr($subnetBinary, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return
            (ord($ipBinary[$fullBytes]) & $mask)
            === (ord($subnetBinary[$fullBytes]) & $mask);
    }

    private function jsonExit(int $statusCode, string $message, array $extra = []): void
    {
        http_response_code($statusCode);

        echo json_encode(array_merge([
            'success'   => false,
            'error'     => $message,
            'timestamp' => date('Y-m-d H:i:s'),
        ], $extra));

        exit;
    }

    private function registerFailureAndExit(
        SecurityThrottleRepository $throttleRepository,
        int $shopId,
        string $clientIp,
        string $message,
        int $statusCode
    ): void {
        $this->module->releaseCronLock();

        $retryAfterSeconds = $throttleRepository->recordFailure($shopId, 'cron', $clientIp, $message);
        if ($retryAfterSeconds > 0) {
            $this->jsonExit(429, 'Too many invalid requests', [
                'retry_after_seconds' => $retryAfterSeconds,
            ]);
        }

        $this->jsonExit($statusCode, $message);
    }
}

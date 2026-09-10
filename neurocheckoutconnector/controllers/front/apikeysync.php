<?php

use NeuroCheckout\Security\RequestSecurityValidator;
use NeuroCheckout\Security\SecretConfiguration;
use NeuroCheckout\Http\RequestBodyDecoder;

class NeuroCheckoutConnectorApikeysyncModuleFrontController extends ModuleFrontController
{
    public $ssl  = true;
    public $auth = false;
    public $ajax = true;

    private const MAX_TIME_DRIFT = 120;
    private const NONCE_TTL = 120;
    private const MIN_API_KEY_LENGTH = 32;
    private const MAX_API_KEY_LENGTH = 512;
    private const PREVIOUS_KEY_GRACE_SECONDS = 900;
    private const PHASE_PREPARE = 'prepare';
    private const PHASE_FINALIZE = 'finalize';
    private const PHASE_DIRECT = 'direct';
    private const MAX_REQUEST_BODY_BYTES = 16384;
    private const MAX_REQUEST_DECOMPRESSED_BYTES = 65536;

    public function initContent()
    {
        parent::initContent();
        header('Content-Type: application/json');

        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
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
            $security = (new RequestSecurityValidator())->validateSignedPost($rawBody, 'apikeysync', true);
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

            $phase = strtolower(trim((string) ($payload['phase'] ?? self::PHASE_DIRECT)));
            if (!in_array($phase, [self::PHASE_PREPARE, self::PHASE_FINALIZE, self::PHASE_DIRECT], true)) {
                $phase = self::PHASE_DIRECT;
            }

            $newApiKey = preg_replace('/\s+/', '', (string) ($payload['new_api_key'] ?? ''));
            if ($newApiKey === '') {
                $this->jsonExit(422, false, 'Missing new_api_key');
            }

            $newApiKeyLength = strlen($newApiKey);
            if (
                $newApiKeyLength < self::MIN_API_KEY_LENGTH
                || $newApiKeyLength > self::MAX_API_KEY_LENGTH
            ) {
                $this->jsonExit(422, false, 'Invalid new_api_key length');
            }

            $providedNewApiKeyHash = trim((string) ($payload['new_api_key_hash'] ?? ''));
            $computedNewApiKeyHash = hash('sha256', $newApiKey);
            if (
                $providedNewApiKeyHash !== ''
                && !hash_equals($computedNewApiKeyHash, $providedNewApiKeyHash)
            ) {
                $this->jsonExit(422, false, 'new_api_key_hash mismatch');
            }

            $currentApiKey = $this->normalizeApiKey(SecretConfiguration::get('NC_API_KEY'));
            $currentApiKeyHash = hash('sha256', $currentApiKey);
            if (hash_equals($currentApiKeyHash, $computedNewApiKeyHash)) {
                if (!$this->clearPendingRotationState()) {
                    $this->jsonExit(500, false, 'Unable to clear pending API key state');
                }
                $this->jsonExit(200, true, null, [
                    'status' => 'already_current',
                    'phase' => $phase,
                    'api_key_hash_prefix' => substr($computedNewApiKeyHash, 0, 10),
                ]);
            }

            $rotationId = trim((string) ($payload['rotation_id'] ?? $payload['request_uid'] ?? ''));

            if ($phase === self::PHASE_PREPARE) {
                if (!SecretConfiguration::set('NC_API_KEY_NEXT', $newApiKey)) {
                    $this->jsonExit(500, false, 'Unable to persist pending API key');
                }
                if (!Configuration::updateValue('NC_API_KEY_ROTATION_ID', $rotationId)) {
                    if (!SecretConfiguration::set('NC_API_KEY_NEXT', '')) {
                        PrestaShopLogger::addLog('[NC] Unable to roll back pending API key after rotation-context failure', 3);
                    }
                    $this->jsonExit(500, false, 'Unable to persist rotation context');
                }
                $this->jsonExit(200, true, null, [
                    'status' => 'prepared',
                    'phase' => self::PHASE_PREPARE,
                    'rotation_id' => $rotationId,
                    'api_key_hash_prefix' => substr($computedNewApiKeyHash, 0, 10),
                ]);
            }

            if ($phase === self::PHASE_FINALIZE) {
                $pendingApiKey = preg_replace('/\s+/', '', trim(SecretConfiguration::get('NC_API_KEY_NEXT')));
                if ($pendingApiKey === '') {
                    $this->jsonExit(409, false, 'Pending rotation not prepared');
                }

                $storedRotationId = trim((string) Configuration::get('NC_API_KEY_ROTATION_ID'));
                if ($rotationId === '' || $storedRotationId === '') {
                    $this->jsonExit(409, false, 'Pending rotation context missing');
                }

                if (!hash_equals($storedRotationId, $rotationId)) {
                    $this->jsonExit(409, false, 'Rotation ID mismatch');
                }

                $candidateApiKey = $pendingApiKey;

                if (!hash_equals(hash('sha256', $candidateApiKey), $computedNewApiKeyHash)) {
                    $this->jsonExit(409, false, 'Pending key mismatch');
                }

                if (!$this->storePreviousApiKeyForGrace($currentApiKey, $candidateApiKey)) {
                    $this->jsonExit(500, false, 'Unable to preserve previous API key');
                }
                if (!SecretConfiguration::set('NC_API_KEY', $candidateApiKey)) {
                    $this->jsonExit(500, false, 'Unable to persist new API key');
                }

                if (!$this->clearPendingRotationState()) {
                    PrestaShopLogger::addLog('[NC] Active API key updated but pending rotation state could not be cleared', 3);
                }
                $this->refreshApiTestValidationStateAfterApiKeySync($candidateApiKey);

                PrestaShopLogger::addLog(
                    '[NC] API key finalized remotely for shop ' . (int) ($this->context->shop->id ?? 0),
                    1
                );

                $this->jsonExit(200, true, null, [
                    'status' => 'updated',
                    'phase' => self::PHASE_FINALIZE,
                    'rotation_id' => $storedRotationId,
                    'api_key_hash_prefix' => substr($computedNewApiKeyHash, 0, 10),
                ]);
            }

            if (!$this->storePreviousApiKeyForGrace($currentApiKey, $newApiKey)) {
                $this->jsonExit(500, false, 'Unable to preserve previous API key');
            }
            if (!SecretConfiguration::set('NC_API_KEY', $newApiKey)) {
                $this->jsonExit(500, false, 'Unable to persist new API key');
            }
            if (!$this->clearPendingRotationState()) {
                PrestaShopLogger::addLog('[NC] Active API key updated but pending rotation state could not be cleared', 3);
            }
            $this->refreshApiTestValidationStateAfterApiKeySync($newApiKey);

            PrestaShopLogger::addLog(
                '[NC] API key synchronized remotely for shop ' . (int) ($this->context->shop->id ?? 0),
                1
            );

            $this->jsonExit(200, true, null, [
                'status' => 'updated',
                'phase' => self::PHASE_DIRECT,
                'api_key_hash_prefix' => substr($computedNewApiKeyHash, 0, 10),
            ]);
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('[NC] API key sync endpoint fatal: ' . $e->getMessage(), 3);
            $this->jsonExit(500, false, 'Internal error');
        }
    }

    private function clearPendingRotationState(): bool
    {
        $secretCleared = SecretConfiguration::set('NC_API_KEY_NEXT', '');
        $contextCleared = Configuration::updateValue('NC_API_KEY_ROTATION_ID', '');

        if (!$secretCleared || !$contextCleared) {
            PrestaShopLogger::addLog('[NC] Unable to clear pending API key rotation state', 3);
        }

        return $secretCleared && $contextCleared;
    }

    private function refreshApiTestValidationStateAfterApiKeySync(string $activeApiKey): void
    {
        $validatedAt = (int) Configuration::get('NC_API_TEST_VALIDATED_AT');
        if ($validatedAt <= 0) {
            // Keep first-install onboarding behavior untouched unless we already have
            // execution evidence (cron/events already running before key rotation).
            if (!$this->hasOperationalExecutionEvidence()) {
                Configuration::updateValue('NC_API_TEST_VALIDATION_FINGERPRINT', '');
                return;
            }
            $validatedAt = time();
        }

        $endpoint = trim((string) Configuration::get('NC_API_ENDPOINT'));
        $shopExternalId = trim((string) Configuration::get('NC_SHOP_EXTERNAL_ID'));
        $normalizedApiKey = preg_replace('/\s+/', '', trim((string) $activeApiKey));

        if ($endpoint === '' || $shopExternalId === '' || $normalizedApiKey === '') {
            Configuration::updateValue('NC_API_TEST_VALIDATED_AT', 0);
            Configuration::updateValue('NC_API_TEST_VALIDATION_FINGERPRINT', '');
            return;
        }

        Configuration::updateValue(
            'NC_API_TEST_VALIDATION_FINGERPRINT',
            hash('sha256', implode('|', [$endpoint, $normalizedApiKey, $shopExternalId]))
        );
        Configuration::updateValue('NC_API_TEST_VALIDATED_AT', $validatedAt > 0 ? $validatedAt : time());
    }

    private function hasOperationalExecutionEvidence(): bool
    {
        $lastAutoRun = (int) Configuration::get('NC_LAST_AUTO_RUN');
        if ($lastAutoRun > 0) {
            return true;
        }

        $lastCronRunGlobal = (int) Configuration::get('NC_CRON_LAST_RUN');
        if ($lastCronRunGlobal > 0) {
            return true;
        }

        $shopId = (int) ($this->context->shop->id ?? 0);
        if ($shopId > 0) {
            $lastCronRunShop = (int) Configuration::get('NC_CRON_LAST_RUN_' . $shopId);
            if ($lastCronRunShop > 0) {
                return true;
            }
        }

        return false;
    }

    private function storePreviousApiKeyForGrace(string $currentApiKey, string $newApiKey): bool
    {
        $normalizedCurrent = $this->normalizeApiKey($currentApiKey);
        $normalizedNew = $this->normalizeApiKey($newApiKey);

        if ($normalizedCurrent === '' || $this->isSameApiKey($normalizedCurrent, $normalizedNew)) {
            $secretCleared = SecretConfiguration::set('NC_API_KEY_PREV', '');
            $graceCleared = Configuration::updateValue('NC_API_KEY_PREV_UNTIL', 0);
            return $secretCleared && $graceCleared;
        }

        if (!SecretConfiguration::set('NC_API_KEY_PREV', $normalizedCurrent)) {
            return false;
        }

        return (bool) Configuration::updateValue(
            'NC_API_KEY_PREV_UNTIL',
            (string) (time() + self::PREVIOUS_KEY_GRACE_SECONDS)
        );
    }

    private function getValidPreviousApiKey(): string
    {
        $previousApiKey = $this->normalizeApiKey(SecretConfiguration::get('NC_API_KEY_PREV'));
        if ($previousApiKey === '') {
            return '';
        }

        $validUntil = (int) Configuration::get('NC_API_KEY_PREV_UNTIL');
        if ($validUntil <= time()) {
            if (!SecretConfiguration::set('NC_API_KEY_PREV', '')) {
                PrestaShopLogger::addLog('[NC] Unable to clear expired previous API key', 3);
            }
            Configuration::updateValue('NC_API_KEY_PREV_UNTIL', 0);
            return '';
        }

        return $previousApiKey;
    }

    private function normalizeApiKey(string $apiKey): string
    {
        return preg_replace('/\s+/', '', trim($apiKey));
    }

    private function isSameApiKey(string $left, string $right): bool
    {
        if ($left === '' || $right === '') {
            return false;
        }

        if (strlen($left) !== strlen($right)) {
            return false;
        }

        return hash_equals($left, $right);
    }

    private function headerValue(string $headerName): string
    {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));
        return (string)($_SERVER[$serverKey] ?? '');
    }

    private function jsonExit(int $status, bool $success, ?string $error = null, ?array $data = null): void
    {
        http_response_code($status);

        $payload = [
            'success' => $success,
            'status' => $status,
            'error' => $error,
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        if (is_array($data)) {
            $payload['data'] = $data;
        }

        echo json_encode($payload);
        exit;
    }
}

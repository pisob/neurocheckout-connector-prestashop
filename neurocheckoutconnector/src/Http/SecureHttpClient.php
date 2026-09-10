<?php

namespace NeuroCheckout\Http;

use Configuration;
use Context;
use PrestaShopLogger;
use NeuroCheckout\Infrastructure\PayloadAliasRepository;
use NeuroCheckout\Security\EndpointPolicy;
use NeuroCheckout\Security\SecretConfiguration;

class SecureHttpClient
{
    const DEFAULT_TIMEOUT = 8;
    const CONNECT_TIMEOUT = 5;
    const ORDER_TIMEOUT   = 2;
    const ORDER_CONNECT_TIMEOUT = 1;
    const MAX_RETRIES     = 2;
    const GZIP_THRESHOLD  = 1024;
    const COMPACT_V4      = 4;
    const COMPACT_V3      = 3;

    /* ============================================================
     * SEND ENTRY POINT
     * ============================================================ */

    public function send(array $payload, array $options = []): array
    {
        try {

            $endpoint = trim((string) Configuration::get('NC_API_ENDPOINT'));
            $apiKeyCandidates = $this->resolveApiKeyCandidatesForRequest();

            if (empty($endpoint) || empty($apiKeyCandidates)) {
                return $this->errorResponse(0, 'API configuration missing');
            }

            $endpoint = EndpointPolicy::normalize($endpoint);
            if ($endpoint === null) {
                return $this->errorResponse(0, 'Invalid API endpoint');
            }
            $compact = $this->buildCompactPayloads($payload);
            $lastResult = $this->errorResponse(0, 'API request not sent');
            $totalCandidates = count($apiKeyCandidates);

            foreach ($apiKeyCandidates as $index => $candidate) {
                $candidateName = $candidate['name'];
                $candidateKey = $candidate['key'];

                $result = $this->sendCartWithApiKey(
                    $endpoint,
                    $candidateKey,
                    $payload,
                    $compact,
                    $options
                );

                if (!empty($result['success'])) {
                    if ($candidateName === 'pending') {
                        $this->promotePendingApiKeyAfterSuccessfulSend($candidateKey);
                    }
                    return $result;
                }

                $lastResult = $result;
                $statusCode = (int)($result['status'] ?? 0);

                if (
                    $index < ($totalCandidates - 1)
                    && $this->shouldRetryWithAlternateApiKey($statusCode)
                ) {
                    PrestaShopLogger::addLog(
                        '[NC] Cart send retry with alternate API key after HTTP ' . $statusCode . ' (' . $candidateName . ')',
                        2
                    );
                    continue;
                }

                return $lastResult;
            }

            return $lastResult;

        } catch (\Throwable $e) {

            PrestaShopLogger::addLog(
                '[NC] SecureHttpClient fatal: ' . $e->getMessage(),
                3
            );

            return $this->errorResponse(0, $e->getMessage());
        }
    }

    public function sendOrderCompleted(array $payload): array
    {
        try {

            $endpoint = trim((string) Configuration::get('NC_API_ENDPOINT'));
            $apiKeyCandidates = $this->resolveApiKeyCandidatesForRequest();

            if (empty($endpoint) || empty($apiKeyCandidates)) {
                return $this->errorResponse(0, 'API configuration missing');
            }

            $endpoint = EndpointPolicy::normalize($endpoint);
            if ($endpoint === null) {
                return $this->errorResponse(0, 'Invalid API endpoint');
            }
            $lastResult = $this->errorResponse(0, 'API request not sent');
            $totalCandidates = count($apiKeyCandidates);

            foreach ($apiKeyCandidates as $index => $candidate) {
                $candidateName = $candidate['name'];
                $candidateKey = $candidate['key'];

                $result = $this->sendOrderCompletedWithApiKey(
                    $endpoint,
                    $candidateKey,
                    $payload
                );

                if (!empty($result['success'])) {
                    if ($candidateName === 'pending') {
                        $this->promotePendingApiKeyAfterSuccessfulSend($candidateKey);
                    }
                    return $result;
                }

                $lastResult = $result;
                $statusCode = (int)($result['status'] ?? 0);

                if (
                    $index < ($totalCandidates - 1)
                    && $this->shouldRetryWithAlternateApiKey($statusCode)
                ) {
                    PrestaShopLogger::addLog(
                        '[NC] Order send retry with alternate API key after HTTP ' . $statusCode . ' (' . $candidateName . ')',
                        2
                    );
                    continue;
                }

                return $lastResult;
            }

            return $lastResult;

        } catch (\Throwable $e) {

            PrestaShopLogger::addLog(
                '[NC] SecureHttpClient order fatal: ' . $e->getMessage(),
                3
            );

            return $this->errorResponse(0, $e->getMessage());
        }
    }

    public function sendSupportEvent(array $payload): array
    {
        try {
            $endpoint = trim((string) Configuration::get('NC_API_ENDPOINT'));
            $apiKeyCandidates = $this->resolveApiKeyCandidatesForRequest();

            if (empty($endpoint) || empty($apiKeyCandidates)) {
                return $this->errorResponse(0, 'API configuration missing');
            }

            $endpoint = EndpointPolicy::normalize($endpoint);
            if ($endpoint === null) {
                return $this->errorResponse(0, 'Invalid API endpoint');
            }
            $lastResult = $this->errorResponse(0, 'API request not sent');
            $totalCandidates = count($apiKeyCandidates);

            foreach ($apiKeyCandidates as $index => $candidate) {
                $candidateName = $candidate['name'];
                $candidateKey = $candidate['key'];

                $result = $this->sendSupportEventWithApiKey(
                    $endpoint,
                    $candidateKey,
                    $payload
                );

                if (!empty($result['success'])) {
                    if ($candidateName === 'pending') {
                        $this->promotePendingApiKeyAfterSuccessfulSend($candidateKey);
                    }
                    return $result;
                }

                $lastResult = $result;
                $statusCode = (int)($result['status'] ?? 0);

                if (
                    $index < ($totalCandidates - 1)
                    && $this->shouldRetryWithAlternateApiKey($statusCode)
                ) {
                    PrestaShopLogger::addLog(
                        '[NC] Support send retry with alternate API key after HTTP ' . $statusCode . ' (' . $candidateName . ')',
                        2
                    );
                    continue;
                }

                return $lastResult;
            }

            return $lastResult;
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                '[NC] SecureHttpClient support fatal: ' . $e->getMessage(),
                3
            );

            return $this->errorResponse(0, $e->getMessage());
        }
    }

    public function sendTelemetryEvent(array $payload): array
    {
        try {
            $endpoint = trim((string) Configuration::get('NC_API_ENDPOINT'));
            $apiKeyCandidates = $this->resolveApiKeyCandidatesForRequest();

            if (empty($endpoint) || empty($apiKeyCandidates)) {
                return $this->errorResponse(0, 'API configuration missing');
            }

            $endpoint = EndpointPolicy::normalize($endpoint);
            if ($endpoint === null) {
                return $this->errorResponse(0, 'Invalid API endpoint');
            }
            $lastResult = $this->errorResponse(0, 'API request not sent');
            $totalCandidates = count($apiKeyCandidates);

            foreach ($apiKeyCandidates as $index => $candidate) {
                $candidateName = $candidate['name'];
                $candidateKey = $candidate['key'];

                $result = $this->sendTelemetryEventWithApiKey(
                    $endpoint,
                    $candidateKey,
                    $payload
                );

                if (!empty($result['success'])) {
                    if ($candidateName === 'pending') {
                        $this->promotePendingApiKeyAfterSuccessfulSend($candidateKey);
                    }
                    return $result;
                }

                $lastResult = $result;
                $statusCode = (int)($result['status'] ?? 0);

                if (
                    $index < ($totalCandidates - 1)
                    && $this->shouldRetryWithAlternateApiKey($statusCode)
                ) {
                    PrestaShopLogger::addLog(
                        '[NC] Telemetry send retry with alternate API key after HTTP ' . $statusCode . ' (' . $candidateName . ')',
                        2
                    );
                    continue;
                }

                return $lastResult;
            }

            return $lastResult;
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                '[NC] SecureHttpClient telemetry fatal: ' . $e->getMessage(),
                3
            );

            return $this->errorResponse(0, $e->getMessage());
        }
    }

    public function sendCustomerJourneyEvent(array $payload): array
    {
        try {
            $endpoint = trim((string) Configuration::get('NC_API_ENDPOINT'));
            $apiKeyCandidates = $this->resolveApiKeyCandidatesForRequest();

            if (empty($endpoint) || empty($apiKeyCandidates)) {
                return $this->errorResponse(0, 'API configuration missing');
            }

            $endpoint = EndpointPolicy::normalize($endpoint);
            if ($endpoint === null) {
                return $this->errorResponse(0, 'Invalid API endpoint');
            }
            $lastResult = $this->errorResponse(0, 'API request not sent');
            $totalCandidates = count($apiKeyCandidates);

            foreach ($apiKeyCandidates as $index => $candidate) {
                $candidateName = $candidate['name'];
                $candidateKey = $candidate['key'];

                $result = $this->sendCustomerJourneyEventWithApiKey(
                    $endpoint,
                    $candidateKey,
                    $payload
                );

                if (!empty($result['success'])) {
                    if ($candidateName === 'pending') {
                        $this->promotePendingApiKeyAfterSuccessfulSend($candidateKey);
                    }
                    return $result;
                }

                $lastResult = $result;
                $statusCode = (int)($result['status'] ?? 0);

                if (
                    $index < ($totalCandidates - 1)
                    && $this->shouldRetryWithAlternateApiKey($statusCode)
                ) {
                    PrestaShopLogger::addLog(
                        '[NC] Customer journey send retry with alternate API key after HTTP ' . $statusCode . ' (' . $candidateName . ')',
                        2
                    );
                    continue;
                }

                return $lastResult;
            }

            return $lastResult;
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                '[NC] SecureHttpClient customer journey fatal: ' . $e->getMessage(),
                3
            );

            return $this->errorResponse(0, $e->getMessage());
        }
    }

    /* ============================================================
     * COMPACT PAYLOADS V4 + V3 (fallback)
     * ============================================================ */

    private function buildCompactPayloads(array $payload): array
    {
        $context = Context::getContext();
        $shopId  = $context->shop ? (int)$context->shop->id : 1;

        $aliasRepo = new PayloadAliasRepository($shopId);

        $cart     = $payload['cart'] ?? [];
        $customer = $payload['customer'] ?? [];
        $rules    = $payload['rules'] ?? [];
        $source   = $payload['source'] ?? [];
        $runtimeContext = $payload['context'] ?? [];

        $itemsBase = [];
        $perItemExtensions = [];
        $legacyExtensions = [];

        if (!empty($cart['items']) && is_array($cart['items'])) {

            foreach ($cart['items'] as $item) {

                $itemsBase[] = [
                    (int)($item['product_id'] ?? 0),
                    (int)($item['attribute_id'] ?? 0),
                    (int)($item['quantity'] ?? 0),
                    round((float)($item['unit_price'] ?? 0), 2),
                    (string)($item['name'] ?? ''),
                    (string)($item['product_url'] ?? ''),
                    (string)($item['image_url'] ?? ''),
                    array_key_exists('in_stock', $item) ? $item['in_stock'] : null,
                    $item['stock'] ?? null,
                    $item['availability'] ?? null,
                    $payload['occurred_at'] ?? null,
                ];

                $itemExtensions = [];

                foreach ($item as $key => $value) {

                    if (!in_array($key, [
                        'product_id',
                        'attribute_id',
                        'quantity',
                        'unit_price',
                        'name',
                        'product_url',
                        'image_url',
                        'in_stock',
                        'stock',
                        'availability',
                    ], true)) {

                        $alias = $aliasRepo->getOrCreateAlias($key);
                        $itemExtensions[$alias] = $value;
                        $legacyExtensions[$alias] = $value;
                    }
                }

                $perItemExtensions[] = $itemExtensions;
            }
        }

        $aliasMappings = $aliasRepo->getAllMappings();
        $aliasesForTransport = [];

        foreach ($aliasMappings as $original => $alias) {
            $aliasesForTransport[$alias] = $original;
        }

        $common = [
            'e' => $payload['event_id'] ?? null,
            't' => $payload['event_type'] ?? null,
            'o' => $payload['occurred_at'] ?? null,
            's' => [
                $source['platform'] ?? null,
                $source['shop_id'] ?? null,
                $source['shop_name'] ?? null,
            ],
            'c' => [
                (string)($cart['id'] ?? ''),
                (string)($cart['uid'] ?? ($cart['id'] ?? '')),
                round((float)($cart['total'] ?? 0), 2),
                $itemsBase
            ],
            'u' => [
                $customer['id'] ?? null,
                $customer['email'] ?? null,
                $customer['first_name'] ?? null,
                $customer['last_name'] ?? null,
                $customer['locale'] ?? null,
                (bool)($customer['is_guest'] ?? true),
                $customer['phone'] ?? null,
                array_key_exists('sms_opt_in', $customer) ? $customer['sms_opt_in'] : null,
                array_key_exists('email_marketing_opt_in', $customer)
                    ? $customer['email_marketing_opt_in']
                    : null,
                $customer['email_marketing_opt_in_source'] ?? null,
                $customer['email_marketing_opt_in_recorded_at'] ?? null,
            ],
            'r' => [
                (bool)($rules['recovery_enabled'] ?? false),
                (bool)($rules['allow_discount'] ?? false),
                (float)($rules['min_cart_total'] ?? 0),
                (bool)($rules['allow_guest'] ?? false),
                (float)($rules['no_discount_max'] ?? -1),
                (float)($rules['discount_5_min'] ?? 0),
                (float)($rules['discount_5_max'] ?? 0),
                (float)($rules['discount_10_min'] ?? 0),
                (float)($rules['max_discount_percent'] ?? 20),
            ],
            'z' => [
                $runtimeContext['shop_timezone'] ?? null,
                isset($runtimeContext['shop_local_hour'])
                    ? (int)$runtimeContext['shop_local_hour']
                    : null,
                isset($runtimeContext['currency_precision'])
                    ? (int)$runtimeContext['currency_precision']
                    : null,
                $runtimeContext['shop_locale'] ?? null,
                $runtimeContext['currency_code'] ?? null,
                $this->extractPrimaryColor($runtimeContext),
            ],
            'aliases' => $aliasesForTransport
        ];

        $v4Body = $common;
        $v4Body['v'] = self::COMPACT_V4;
        $v4Body['ix'] = $perItemExtensions;
        $v4Body['x'] = $legacyExtensions;

        $v3Body = $common;
        $v3Body['v'] = self::COMPACT_V3;
        $v3Body['x'] = $legacyExtensions;

        return [
            'v4' => $v4Body,
            'v3' => $v3Body,
            'schema_version' => $aliasRepo->getSchemaVersion()
        ];
    }

    private function sendCompactPayload(
        string $endpoint,
        string $apiKey,
        array $originalPayload,
        array $compactBody,
        int $schemaVersion,
        int $compactVersion,
        array $options = []
    ): array {
        $jsonPayload = json_encode(
            $compactBody,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        $useGzip = false;
        $bodyToSend = $jsonPayload;

        if (strlen($jsonPayload) > self::GZIP_THRESHOLD) {
            $bodyToSend = gzencode($jsonPayload, 6);
            $useGzip = true;
        }

        $timestamp = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $message   = $timestamp . '.' . $nonce . '.' . $bodyToSend;

        $signature = hash_hmac('sha256', $message, $apiKey, false);

        $eventId = $originalPayload['event_id'] ?? null;
        $idempotencyKey = $eventId
            ? hash('sha256', $eventId)
            : hash('sha256', $bodyToSend);

        $context = Context::getContext();
        $shop    = $context->shop ?? null;

        $shopId      = $shop ? (int)$shop->id : 1;
        $shopGroupId = $shop ? (int)$shop->id_shop_group : 0;

        $headers = [
            'Content-Type: application/json',
            'X-API-Key: ' . $apiKey,
            'X-Neuro-Timestamp: ' . $timestamp,
            'X-Neuro-Nonce: ' . $nonce,
            'X-Neuro-Signature: ' . $signature,
            'X-Neuro-Version: 7',
            'X-Neuro-Compact: ' . $compactVersion,
            'X-Neuro-Schema-Version: ' . $schemaVersion,
            'X-Neuro-Shop: ' . $shopId,
            'X-Neuro-Shop-Group: ' . $shopGroupId,
            'Idempotency-Key: ' . $idempotencyKey,
        ];

        $isTestEvent = !empty($options['is_test_event'])
            || !empty($options['is_cron_test'])
            || !empty($options['is_api_test']);

        if ($isTestEvent) {
            $headers[] = 'X-Neuro-Test-Mode: 1';
        }

        if (!empty($options['is_cron_test'])) {
            $headers[] = 'X-Neuro-Cron-Test: 1';
        }

        if (!empty($options['is_api_test'])) {
            $headers[] = 'X-Neuro-Api-Test: 1';
        }

        if ($useGzip) {
            $headers[] = 'Content-Encoding: gzip';
        }

        return $this->executeWithRetry(
            $endpoint . '/api/v1/events/cart',
            $bodyToSend,
            $headers
        );
    }

    private function sendCartWithApiKey(
        string $endpoint,
        string $apiKey,
        array $payload,
        array $compact,
        array $options
    ): array {
        $result = $this->sendCompactPayload(
            $endpoint,
            $apiKey,
            $payload,
            $compact['v4'],
            $compact['schema_version'],
            self::COMPACT_V4,
            $options
        );

        if (
            empty($result['success']) &&
            $this->shouldFallbackToV3((int)($result['status'] ?? 0))
        ) {
            PrestaShopLogger::addLog(
                '[NC] V4 not accepted, fallback to V3 compact schema',
                2
            );

            $result = $this->sendCompactPayload(
                $endpoint,
                $apiKey,
                $payload,
                $compact['v3'],
                $compact['schema_version'],
                self::COMPACT_V3,
                $options
            );
        }

        return $result;
    }

    private function sendOrderCompletedWithApiKey(
        string $endpoint,
        string $apiKey,
        array $payload
    ): array {
        $jsonPayload = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        $timestamp = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $message   = $timestamp . '.' . $nonce . '.' . $jsonPayload;
        $signature = hash_hmac('sha256', $message, $apiKey, false);

        $eventId = (string)($payload['event_id'] ?? '');
        $idempotencySeed = $eventId !== ''
            ? $eventId
            : (string)($payload['order_id'] ?? $jsonPayload);

        $headers = [
            'Content-Type: application/json',
            'X-API-Key: ' . $apiKey,
            'X-Neuro-Timestamp: ' . $timestamp,
            'X-Neuro-Nonce: ' . $nonce,
            'X-Neuro-Signature: ' . $signature,
            'X-Neuro-Version: 7',
            'Idempotency-Key: ' . hash('sha256', $idempotencySeed),
        ];

        return $this->executeCurl(
            $endpoint . '/api/v1/events/order',
            $jsonPayload,
            $headers,
            self::ORDER_TIMEOUT,
            self::ORDER_CONNECT_TIMEOUT
        );
    }

    private function sendSupportEventWithApiKey(
        string $endpoint,
        string $apiKey,
        array $payload
    ): array {
        $jsonPayload = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        $timestamp = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $message   = $timestamp . '.' . $nonce . '.' . $jsonPayload;
        $signature = hash_hmac('sha256', $message, $apiKey, false);

        $eventId = (string)($payload['event_id'] ?? '');
        $idempotencySeed = $eventId !== ''
            ? $eventId
            : (string)($payload['support']['message_id'] ?? $jsonPayload);

        $headers = [
            'Content-Type: application/json',
            'X-API-Key: ' . $apiKey,
            'X-Neuro-Timestamp: ' . $timestamp,
            'X-Neuro-Nonce: ' . $nonce,
            'X-Neuro-Signature: ' . $signature,
            'X-Neuro-Version: 7',
            'Idempotency-Key: ' . hash('sha256', $idempotencySeed),
        ];

        return $this->executeWithRetry(
            $endpoint . '/api/v1/events/support',
            $jsonPayload,
            $headers
        );
    }

    private function sendTelemetryEventWithApiKey(
        string $endpoint,
        string $apiKey,
        array $payload
    ): array {
        $jsonPayload = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        $timestamp = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $message   = $timestamp . '.' . $nonce . '.' . $jsonPayload;
        $signature = hash_hmac('sha256', $message, $apiKey, false);

        $eventId = (string)($payload['event_id'] ?? '');
        $idempotencySeed = $eventId !== ''
            ? $eventId
            : (string)($payload['event_type'] ?? $jsonPayload);

        $headers = [
            'Content-Type: application/json',
            'X-API-Key: ' . $apiKey,
            'X-Neuro-Timestamp: ' . $timestamp,
            'X-Neuro-Nonce: ' . $nonce,
            'X-Neuro-Signature: ' . $signature,
            'X-Neuro-Version: 7',
            'Idempotency-Key: ' . hash('sha256', $idempotencySeed),
        ];

        return $this->executeWithRetry(
            $endpoint . '/api/v1/events/telemetry',
            $jsonPayload,
            $headers
        );
    }

    private function sendCustomerJourneyEventWithApiKey(
        string $endpoint,
        string $apiKey,
        array $payload
    ): array {
        $jsonPayload = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        $timestamp = (string) time();
        $nonce     = bin2hex(random_bytes(16));
        $message   = $timestamp . '.' . $nonce . '.' . $jsonPayload;
        $signature = hash_hmac('sha256', $message, $apiKey, false);

        $eventId = (string)($payload['event_id'] ?? '');
        $idempotencySeed = $eventId !== ''
            ? $eventId
            : (string)($payload['event_type'] ?? $jsonPayload);

        $headers = [
            'Content-Type: application/json',
            'X-API-Key: ' . $apiKey,
            'X-Neuro-Timestamp: ' . $timestamp,
            'X-Neuro-Nonce: ' . $nonce,
            'X-Neuro-Signature: ' . $signature,
            'X-Neuro-Version: 7',
            'Idempotency-Key: ' . hash('sha256', $idempotencySeed),
        ];

        return $this->executeWithRetry(
            $endpoint . '/api/v1/events/customer-journey',
            $jsonPayload,
            $headers
        );
    }

    private function shouldFallbackToV3(int $status): bool
    {
        return in_array($status, [400, 404, 405, 406, 410, 415, 422, 426, 501], true);
    }

    /* ============================================================
     * RETRY WRAPPER
     * ============================================================ */

    private function executeWithRetry(string $url, string $body, array $headers): array
    {
        $attempt = 0;
        $result  = [];

        while ($attempt <= self::MAX_RETRIES) {

            $result = $this->executeCurl($url, $body, $headers);

            if (!empty($result['success'])) {
                return $result;
            }

            if (!$this->shouldRetry((int)$result['status'])) {
                return $result;
            }

            $attempt++;
            usleep(300000 * $attempt);
        }

        return $result;
    }

    /* ============================================================
     * CURL EXECUTION
     * ============================================================ */

    private function executeCurl(
        string $url,
        string $body,
        array $headers,
        int $timeout = self::DEFAULT_TIMEOUT,
        int $connectTimeout = self::CONNECT_TIMEOUT
    ): array
    {
        
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_FAILONERROR    => false,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        if (defined('CURLOPT_PROTOCOLS')) {
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            curl_setopt($ch, CURLOPT_PROTOCOLS, $scheme === 'http' ? CURLPROTO_HTTP : CURLPROTO_HTTPS);
        }

        $responseBody = curl_exec($ch);
        $httpCode     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($ch);
       
        curl_close($ch);

        if ($responseBody === false || !empty($curlError)) {
            PrestaShopLogger::addLog('[NC] CURL transport error: ' . $curlError, 3);
            return $this->errorResponse($httpCode ?: 0, $curlError ?: 'Transport error');
        }
        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'success' => true,
                'status'  => $httpCode,
                'body'    => $responseBody,
                'error'   => null
            ];
        }

        return $this->errorResponse($httpCode, 'HTTP ' . $httpCode);
    }

    private function shouldRetry(int $status): bool
    {
        return in_array($status, [0, 408, 429, 500, 502, 503, 504], true);
    }

    private function shouldRetryWithAlternateApiKey(int $status): bool
    {
        return in_array($status, [401, 403], true);
    }

    private function errorResponse(int $status, string $error): array
    {
        return [
            'success' => false,
            'status'  => $status,
            'body'    => null,
            'error'   => $error
        ];
    }

    private function resolveApiKeyForRequest(): string
    {
        $currentApiKey = $this->normalizeApiKey(SecretConfiguration::get('NC_API_KEY'));
        $pendingApiKey = $this->normalizeApiKey(SecretConfiguration::get('NC_API_KEY_NEXT'));

        if ($pendingApiKey === '') {
            return $currentApiKey;
        }

        if ($this->isSameApiKey($currentApiKey, $pendingApiKey)) {
            if (!SecretConfiguration::set('NC_API_KEY_NEXT', '')) {
                PrestaShopLogger::addLog('[NC] Unable to clear duplicate pending API key', 3);
            }
            Configuration::updateValue('NC_API_KEY_ROTATION_ID', '');
            return $currentApiKey;
        }

        // Never auto-promote pending key on send path.
        // Promotion must happen only after explicit apikeysync finalize.
        PrestaShopLogger::addLog(
            '[NC] Pending API key detected; using current API key until finalize',
            2
        );

        return $currentApiKey;
    }

    private function resolveApiKeyCandidatesForRequest(): array
    {
        $currentApiKey = $this->resolveApiKeyForRequest();
        $pendingApiKey = $this->normalizeApiKey(SecretConfiguration::get('NC_API_KEY_NEXT'));
        $previousApiKey = $this->getValidPreviousApiKeyForFallback();

        $candidates = [];

        if ($currentApiKey !== '') {
            $candidates[] = ['name' => 'current', 'key' => $currentApiKey];
        }

        if (
            $pendingApiKey !== ''
            && !$this->isSameApiKey($pendingApiKey, $currentApiKey)
        ) {
            $candidates[] = ['name' => 'pending', 'key' => $pendingApiKey];
        }

        if (
            $previousApiKey !== ''
            && !$this->isSameApiKey($previousApiKey, $currentApiKey)
            && !$this->isSameApiKey($previousApiKey, $pendingApiKey)
        ) {
            $candidates[] = ['name' => 'previous', 'key' => $previousApiKey];
        }

        return $candidates;
    }

    private function getValidPreviousApiKeyForFallback(): string
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

    private function promotePendingApiKeyAfterSuccessfulSend(string $activeApiKey): void
    {
        $pendingApiKey = $this->normalizeApiKey(SecretConfiguration::get('NC_API_KEY_NEXT'));
        if (
            $pendingApiKey === ''
            || !$this->isSameApiKey($pendingApiKey, $activeApiKey)
        ) {
            return;
        }

        $currentApiKey = $this->normalizeApiKey(SecretConfiguration::get('NC_API_KEY'));
        if ($this->isSameApiKey($currentApiKey, $pendingApiKey)) {
            if (!SecretConfiguration::set('NC_API_KEY_NEXT', '')) {
                PrestaShopLogger::addLog('[NC] Unable to clear promoted pending API key', 3);
            }
            Configuration::updateValue('NC_API_KEY_ROTATION_ID', '');
            return;
        }

        if ($currentApiKey !== '') {
            if (!SecretConfiguration::set('NC_API_KEY_PREV', $currentApiKey)) {
                PrestaShopLogger::addLog('[NC] Pending API key promotion aborted: grace key encryption failed', 3);
                return;
            }
            Configuration::updateValue('NC_API_KEY_PREV_UNTIL', (string)(time() + 900));
        }

        if (!SecretConfiguration::set('NC_API_KEY', $pendingApiKey)) {
            PrestaShopLogger::addLog('[NC] Pending API key promotion aborted: active key encryption failed', 3);
            return;
        }
        if (!SecretConfiguration::set('NC_API_KEY_NEXT', '')) {
            PrestaShopLogger::addLog('[NC] Active API key promoted but pending state could not be cleared', 3);
        }
        Configuration::updateValue('NC_API_KEY_ROTATION_ID', '');

        PrestaShopLogger::addLog(
            '[NC] Pending API key promoted after successful authenticated send',
            1
        );
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

    private function extractPrimaryColor(array $runtimeContext): ?string
    {
        $palette = $runtimeContext['theme_palette'] ?? null;
        if (is_array($palette)) {
            $paletteColor = trim((string)($palette['primary_color'] ?? ''));
            if ($paletteColor !== '') {
                return $paletteColor;
            }
        }

        $primaryColor = trim((string)($runtimeContext['primary_color'] ?? ''));
        return $primaryColor !== '' ? $primaryColor : null;
    }

    public function health(): array
    {
        $endpoint = EndpointPolicy::normalize((string) Configuration::get('NC_API_ENDPOINT'));
        $apiKey   = $this->resolveApiKeyForRequest();

        if ($endpoint === null || empty($apiKey)) {
            return [
                'success' => false,
                'status'  => 500,
                'error'   => 'API configuration missing',
            ];
        }

        $url = $endpoint . '/';

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-API-Key: ' . $apiKey,
            ],
        ]);

        if (defined('CURLOPT_PROTOCOLS')) {
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            curl_setopt($ch, CURLOPT_PROTOCOLS, $scheme === 'http' ? CURLPROTO_HTTP : CURLPROTO_HTTPS);
        }

        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['success' => false, 'status' => 0, 'error' => $error];
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'success' => $status >= 200 && $status < 300,
            'status'  => $status,
            'body'    => $response,
        ];
    }
}

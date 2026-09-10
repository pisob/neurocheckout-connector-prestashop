<?php

namespace NeuroCheckout\Security;

use Configuration;
use Context;
use NeuroCheckout\Infrastructure\NonceRepository;
use NeuroCheckout\Infrastructure\SecurityThrottleRepository;

class RequestSecurityValidator
{
    private const MAX_TIME_DRIFT = 120;
    // A timestamp accepted at the future-skew limit remains valid for twice
    // MAX_TIME_DRIFT after reception. Retain the nonce for that entire window.
    private const NONCE_TTL = (2 * self::MAX_TIME_DRIFT) + 1;

    private SecurityThrottleRepository $throttleRepository;
    private IpResolver $ipResolver;

    public function __construct(
        ?SecurityThrottleRepository $throttleRepository = null,
        ?IpResolver $ipResolver = null
    ) {
        $this->throttleRepository = $throttleRepository ?: new SecurityThrottleRepository();
        $this->ipResolver = $ipResolver ?: new IpResolver();
    }

    /**
     * @return array<string, mixed>
     */
    public function validateSignedPost(string $rawBody, string $nonceNamespace, bool $allowPreviousKey = false): array
    {
        $shopId = $this->resolveShopId();
        $endpoint = $this->normalizeEndpoint($nonceNamespace);
        $clientIp = $this->resolveClientIp();
        $retryAfterSeconds = $this->throttleRepository->getRetryAfterSeconds($shopId, $endpoint, $clientIp);
        if ($retryAfterSeconds > 0) {
            return $this->fail('Too many invalid requests', 429, $retryAfterSeconds);
        }

        $timestamp = (int) $this->headerValue('X-Neuro-Timestamp');
        $nonce = trim((string) $this->headerValue('X-Neuro-Nonce'));
        $signature = trim((string) $this->headerValue('X-Neuro-Signature'));

        if ($timestamp <= 0 || $nonce === '' || $signature === '') {
            return $this->failAndTrack($shopId, $endpoint, $clientIp, 'Missing security headers', 403);
        }

        if (abs(time() - $timestamp) > self::MAX_TIME_DRIFT) {
            return $this->failAndTrack($shopId, $endpoint, $clientIp, 'Signature expired', 403);
        }

        $currentApiKey = $this->normalizeApiKey(SecretConfiguration::get('NC_API_KEY'));
        if ($currentApiKey === '') {
            return $this->fail('Connector API key missing', 409);
        }

        $validApiKeys = [$currentApiKey];
        if ($allowPreviousKey) {
            $previousApiKey = $this->getValidPreviousApiKey();
            if ($previousApiKey !== '' && !in_array($previousApiKey, $validApiKeys, true)) {
                $validApiKeys[] = $previousApiKey;
            }
        }

        $providedApiKey = $this->normalizeApiKey((string) $this->headerValue('X-API-Key'));
        if ($providedApiKey === '') {
            return $this->failAndTrack($shopId, $endpoint, $clientIp, 'Missing API key', 403);
        }

        $authenticated = false;
        foreach ($validApiKeys as $candidateKey) {
            if ($candidateKey === '' || strlen($candidateKey) !== strlen($providedApiKey)) {
                continue;
            }
            if (!hash_equals($candidateKey, $providedApiKey)) {
                continue;
            }

            $expectedSignature = hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . $rawBody, $candidateKey);
            if (!hash_equals($expectedSignature, $signature)) {
                return $this->failAndTrack($shopId, $endpoint, $clientIp, 'Invalid signature', 403);
            }

            $authenticated = true;
            break;
        }

        if (!$authenticated) {
            return $this->failAndTrack($shopId, $endpoint, $clientIp, 'Invalid API key', 403);
        }

        $nonceRepo = new NonceRepository();
        $nonceRepo->purgeExpired();
        if (!$nonceRepo->register($endpoint . '-' . $nonce, self::NONCE_TTL)) {
            return $this->failAndTrack($shopId, $endpoint, $clientIp, 'Replay detected', 409);
        }

        $this->throttleRepository->clear($shopId, $endpoint, $clientIp);

        return [
            'success' => true,
            'status' => 200,
            'auth_mode' => 'api_key',
        ];
    }

    private function headerValue(string $headerName): string
    {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));
        return (string) ($_SERVER[$serverKey] ?? '');
    }

    private function resolveShopId(): int
    {
        $context = Context::getContext();
        if ($context && isset($context->shop) && (int) ($context->shop->id ?? 0) > 0) {
            return (int) $context->shop->id;
        }

        return max(1, (int) Configuration::get('PS_SHOP_DEFAULT'));
    }

    private function resolveClientIp(): string
    {
        $trustedProxyRules = $this->ipResolver->parseRules((string) Configuration::get('NC_TRUSTED_PROXY_IPS'));
        return $this->ipResolver->resolve($_SERVER, $trustedProxyRules);
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

    private function normalizeEndpoint(string $nonceNamespace): string
    {
        $endpoint = strtolower(trim($nonceNamespace));
        $endpoint = preg_replace('/[^a-z0-9_-]+/', '-', $endpoint) ?: 'unknown';

        return substr($endpoint, 0, 32);
    }

    /**
     * @return array<string, mixed>
     */
    private function fail(string $message, int $status, ?int $retryAfterSeconds = null): array
    {
        $payload = [
            'success' => false,
            'status' => $status,
            'error' => $message,
        ];

        if ($retryAfterSeconds !== null && $retryAfterSeconds > 0) {
            $payload['retry_after_seconds'] = $retryAfterSeconds;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function failAndTrack(int $shopId, string $endpoint, string $clientIp, string $message, int $status): array
    {
        $retryAfterSeconds = $this->throttleRepository->recordFailure($shopId, $endpoint, $clientIp, $message);
        if ($retryAfterSeconds > 0) {
            return $this->fail('Too many invalid requests', 429, $retryAfterSeconds);
        }

        return $this->fail($message, $status);
    }
}

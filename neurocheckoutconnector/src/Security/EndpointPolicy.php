<?php

namespace NeuroCheckout\Security;

class EndpointPolicy
{
    private const CLOUD_HOSTS = [
        'neurocheckout.com',
        'staging.neurocheckout.com',
        'community-api-staging.neurocheckout.com',
    ];

    /**
     * Only NeuroCheckout Cloud HTTPS endpoints are accepted in released builds.
     * Loopback endpoints can be enabled explicitly by a developer-owned PHP
     * constant; the option is deliberately absent from the back office.
     */
    public static function normalize(string $endpoint): ?string
    {
        $candidate = trim($endpoint);
        if ($candidate === '' || !filter_var($candidate, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($candidate);
        if (!is_array($parts)) {
            return null;
        }

        if (
            isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $host = trim($host, '[]');
        $path = (string) ($parts['path'] ?? '');
        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        if ($host === '' || !in_array($path, ['', '/'], true)) {
            return null;
        }

        if (in_array($host, self::CLOUD_HOSTS, true)) {
            if ($scheme !== 'https' || ($port !== null && $port !== 443)) {
                return null;
            }

            return 'https://' . $host;
        }

        if (self::localDevelopmentEndpointsEnabled() && self::isLoopbackHost($host)) {
            if (!in_array($scheme, ['http', 'https'], true)) {
                return null;
            }
            if ($port !== null && ($port < 1 || $port > 65535)) {
                return null;
            }

            $authorityHost = $host === '::1' ? '[::1]' : $host;
            return $scheme . '://' . $authorityHost . ($port !== null ? ':' . $port : '');
        }

        return null;
    }

    public static function isAllowed(string $endpoint): bool
    {
        return self::normalize($endpoint) !== null;
    }

    private static function isLoopbackHost(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    private static function localDevelopmentEndpointsEnabled(): bool
    {
        return defined('NEUROCHECKOUT_CONNECTOR_DEV_ENDPOINTS')
            && constant('NEUROCHECKOUT_CONNECTOR_DEV_ENDPOINTS') === true;
    }
}

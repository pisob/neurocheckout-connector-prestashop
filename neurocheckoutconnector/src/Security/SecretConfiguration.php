<?php

namespace NeuroCheckout\Security;

use Configuration;

class SecretConfiguration
{
    private const PREFIX = 'ncenc:v1:';
    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    /**
     * @return list<string>
     */
    private static function secretKeys(): array
    {
        return [
            'NC_API_KEY',
            'NC_INTERNAL_SECRET',
            'NEURO_CRON_TOKEN',
            'NC_API_KEY_NEXT',
            'NC_API_KEY_PREV',
            'NC_TELEMETRY_PUBLIC_TOKEN',
            'NC_CUSTOMER_JOURNEY_PUBLIC_TOKEN',
        ];
    }

    public static function get(string $key): string
    {
        $rawValue = trim((string) Configuration::get($key));
        if (!self::isSecretKey($key) || $rawValue === '') {
            return $rawValue;
        }

        if (self::hasPrefix($rawValue, self::PREFIX)) {
            $decrypted = self::decrypt(substr($rawValue, strlen(self::PREFIX)));
            return $decrypted !== null ? trim($decrypted) : '';
        }

        // Lazy migration from plaintext storage used by previous versions.
        if (!self::set($key, $rawValue)) {
            self::log('Unable to migrate plaintext secret configuration key ' . $key);
            return '';
        }

        return $rawValue;
    }

    public static function set(string $key, string $value): bool
    {
        if (!self::isSecretKey($key)) {
            return Configuration::updateValue($key, $value);
        }

        $normalized = trim($value);
        if ($normalized === '') {
            return Configuration::updateValue($key, '');
        }

        if (self::hasPrefix($normalized, self::PREFIX)) {
            if (self::decrypt(substr($normalized, strlen(self::PREFIX))) === null) {
                self::log('Invalid encrypted value refused for secret configuration key ' . $key);
                return false;
            }
            return Configuration::updateValue($key, $normalized);
        }

        $encrypted = self::encrypt($normalized);
        if ($encrypted === null) {
            self::log('Unable to encrypt secret configuration key ' . $key . '; write refused');
            return false;
        }

        return Configuration::updateValue($key, self::PREFIX . $encrypted);
    }

    public static function migrateKnownSecrets(): bool
    {
        foreach (self::secretKeys() as $key) {
            $stored = trim((string) Configuration::get($key));
            if ($stored !== '' && self::get($key) === '') {
                return false;
            }
        }

        return true;
    }

    private static function isSecretKey(string $key): bool
    {
        return in_array($key, self::secretKeys(), true);
    }

    /**
     * PHP 7.4-compatible prefix check. PrestaShop 1.7.7 installations can
     * still run on PHP 7.4, where str_starts_with() does not exist.
     */
    private static function hasPrefix(string $value, string $prefix): bool
    {
        return substr($value, 0, strlen($prefix)) === $prefix;
    }

    private static function encryptionKey(): ?string
    {
        $cookieKey = defined('_COOKIE_KEY_') ? trim((string) _COOKIE_KEY_) : '';
        $rijndaelKey = defined('_RIJNDAEL_KEY_') ? trim((string) _RIJNDAEL_KEY_) : '';
        if ($cookieKey === '' && $rijndaelKey === '') {
            return null;
        }

        $material = implode('|', [
            $cookieKey,
            $rijndaelKey,
            defined('_DB_NAME_') ? (string) _DB_NAME_ : '',
            'neurocheckoutconnector',
            'secret-config-v1',
        ]);

        return hash('sha256', $material, true);
    }

    private static function encrypt(string $value): ?string
    {
        if (!function_exists('openssl_encrypt')) {
            return null;
        }

        try {
            $encryptionKey = self::encryptionKey();
            if ($encryptionKey === null) {
                return null;
            }
            $iv = random_bytes(self::IV_LENGTH);
            $tag = '';
            $ciphertext = openssl_encrypt(
                $value,
                self::CIPHER,
                $encryptionKey,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                '',
                self::TAG_LENGTH
            );

            if (!is_string($ciphertext) || !is_string($tag) || $tag === '') {
                return null;
            }

            return base64_encode($iv . $tag . $ciphertext);
        } catch (\Throwable $e) {
            self::log('Secret encryption error: ' . $e->getMessage());
            return null;
        }
    }

    private static function decrypt(string $payload): ?string
    {
        if (!function_exists('openssl_decrypt')) {
            return null;
        }

        try {
            $encryptionKey = self::encryptionKey();
            if ($encryptionKey === null) {
                return null;
            }
            $binary = base64_decode($payload, true);
            if (!is_string($binary) || strlen($binary) <= (self::IV_LENGTH + self::TAG_LENGTH)) {
                return null;
            }

            $iv = substr($binary, 0, self::IV_LENGTH);
            $tag = substr($binary, self::IV_LENGTH, self::TAG_LENGTH);
            $ciphertext = substr($binary, self::IV_LENGTH + self::TAG_LENGTH);

            $decrypted = openssl_decrypt(
                $ciphertext,
                self::CIPHER,
                $encryptionKey,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            return is_string($decrypted) ? $decrypted : null;
        } catch (\Throwable $e) {
            self::log('Secret decryption error: ' . $e->getMessage());
            return null;
        }
    }

    private static function log(string $message): void
    {
        if (class_exists('PrestaShopLogger')) {
            \PrestaShopLogger::addLog('[NC] ' . $message, 3);
        }
    }
}

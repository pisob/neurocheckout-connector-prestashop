<?php

declare(strict_types=1);

const _COOKIE_KEY_ = 'test-cookie-key-that-never-leaves-this-process';
const _RIJNDAEL_KEY_ = 'test-rijndael-key-that-never-leaves-this-process';
const _DB_NAME_ = 'test_database';

class Configuration
{
    /** @var array<string, string> */
    public static array $values = [];

    public static function get(string $key): string
    {
        return self::$values[$key] ?? '';
    }

    public static function updateValue(string $key, $value): bool
    {
        self::$values[$key] = (string) $value;
        return true;
    }
}

class PrestaShopLogger
{
    public static function addLog(string $message, int $severity): void
    {
    }
}

require_once __DIR__ . '/../src/Security/SecretConfiguration.php';

use NeuroCheckout\Security\SecretConfiguration;

function assertSecretTest(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL {$label}\n");
        exit(1);
    }
}

$apiKey = bin2hex(random_bytes(24)); // Synthetic, generated only in this isolated test.
assertSecretTest(SecretConfiguration::set('NC_API_KEY', $apiKey), 'encrypted API key write');
$storedApiKey = Configuration::$values['NC_API_KEY'] ?? '';
assertSecretTest($storedApiKey !== $apiKey, 'API key is not stored as plaintext');
assertSecretTest(substr($storedApiKey, 0, 9) === 'ncenc:v1:', 'encrypted prefix present');
assertSecretTest(SecretConfiguration::get('NC_API_KEY') === $apiKey, 'API key decrypts');

$browserToken = 'browser-token-0123456789';
assertSecretTest(
    SecretConfiguration::set('NC_TELEMETRY_PUBLIC_TOKEN', $browserToken),
    'browser ingestion token write'
);
assertSecretTest(
    (Configuration::$values['NC_TELEMETRY_PUBLIC_TOKEN'] ?? '') !== $browserToken,
    'browser ingestion token is encrypted at rest'
);

$legacyValue = 'legacy-plaintext-key-012345';
Configuration::$values['NC_API_KEY_PREV'] = $legacyValue;
assertSecretTest(SecretConfiguration::get('NC_API_KEY_PREV') === $legacyValue, 'legacy key migrates');
assertSecretTest(
    (Configuration::$values['NC_API_KEY_PREV'] ?? '') !== $legacyValue,
    'legacy plaintext is replaced'
);

assertSecretTest(
    !SecretConfiguration::set('NC_API_KEY_NEXT', 'ncenc:v1:not-valid-base64'),
    'invalid encrypted payload refused'
);
assertSecretTest(SecretConfiguration::migrateKnownSecrets(), 'known secrets remain readable');

fwrite(STDOUT, "Secret configuration tests passed.\n");

<?php

declare(strict_types=1);

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

function assertNoKeyTest(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL {$label}\n");
        exit(1);
    }
}

$secret = 'must-never-be-written-in-plaintext';
assertNoKeyTest(
    !SecretConfiguration::set('NC_API_KEY', $secret),
    'secret write fails when PrestaShop key material is unavailable'
);
assertNoKeyTest(
    !array_key_exists('NC_API_KEY', Configuration::$values),
    'failed encryption does not create a plaintext configuration value'
);

Configuration::$values['NC_API_KEY_PREV'] = 'legacy-plaintext-value';
assertNoKeyTest(
    SecretConfiguration::get('NC_API_KEY_PREV') === '',
    'plaintext migration fails closed when key material is unavailable'
);
assertNoKeyTest(
    Configuration::$values['NC_API_KEY_PREV'] === 'legacy-plaintext-value',
    'failed migration does not replace the source with misleading data'
);

fwrite(STDOUT, "Secret configuration fail-closed tests passed.\n");

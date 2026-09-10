<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Security/EndpointPolicy.php';

use NeuroCheckout\Security\EndpointPolicy;

function assertSameValue($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, sprintf("FAIL %s: expected %s, got %s\n", $label, var_export($expected, true), var_export($actual, true)));
        exit(1);
    }
}

assertSameValue('https://neurocheckout.com', EndpointPolicy::normalize('https://neurocheckout.com/'), 'production endpoint');
assertSameValue('https://staging.neurocheckout.com', EndpointPolicy::normalize('HTTPS://STAGING.NEUROCHECKOUT.COM'), 'staging endpoint');
assertSameValue('https://community-api-staging.neurocheckout.com', EndpointPolicy::normalize('https://community-api-staging.neurocheckout.com/'), 'public authenticated staging API');
assertSameValue(null, EndpointPolicy::normalize('http://community-api-staging.neurocheckout.com'), 'staging API requires TLS');
assertSameValue(null, EndpointPolicy::normalize('https://community-api-staging.neurocheckout.com.evil.example'), 'staging API suffix confusion rejected');
assertSameValue(null, EndpointPolicy::normalize('https://community-api-staging.neurocheckout.com:444'), 'staging API alternate port refused');
assertSameValue(null, EndpointPolicy::normalize('http://neurocheckout.com'), 'production requires TLS');
assertSameValue(null, EndpointPolicy::normalize('https://neurocheckout.com.evil.example'), 'suffix confusion rejected');
assertSameValue(null, EndpointPolicy::normalize('https://untrusted.neurocheckout.com'), 'unlisted subdomain rejected');
assertSameValue(null, EndpointPolicy::normalize('https://user:pass@neurocheckout.com'), 'credentials rejected');
assertSameValue(null, EndpointPolicy::normalize('https://neurocheckout.com/api'), 'base path rejected');
assertSameValue(null, EndpointPolicy::normalize('https://neurocheckout.com?redirect=evil'), 'query rejected');
assertSameValue(null, EndpointPolicy::normalize('http://169.254.169.254'), 'metadata endpoint rejected');
assertSameValue(null, EndpointPolicy::normalize('http://127.0.0.1:8000'), 'loopback disabled by default');

define('NEUROCHECKOUT_CONNECTOR_DEV_ENDPOINTS', true);
assertSameValue('http://127.0.0.1:8000', EndpointPolicy::normalize('http://127.0.0.1:8000/'), 'explicit loopback development endpoint');
assertSameValue('http://[::1]:8000', EndpointPolicy::normalize('http://[::1]:8000'), 'explicit IPv6 loopback development endpoint');
assertSameValue(null, EndpointPolicy::normalize('http://10.0.0.1:8000'), 'private network remains rejected');

$recoveryRepositorySource = file_get_contents(
    __DIR__ . '/../src/Infrastructure/RecoveryTokenRepository.php'
);
assertSameValue(true, is_string($recoveryRepositorySource), 'recovery repository source is readable');
assertSameValue(
    false,
    strpos((string) $recoveryRepositorySource, 'mt_' . 'rand(') !== false,
    'predictable recovery token fallback is absent'
);
$recoveryLinkSource = file_get_contents(__DIR__ . '/../src/Security/RecoveryLinkService.php');
assertSameValue(true, is_string($recoveryLinkSource), 'recovery link source is readable');
assertSameValue(
    true,
    strpos((string) $recoveryLinkSource, 'Unable to issue opaque recovery token') !== false,
    'opaque recovery token issuance fails closed'
);

$cronControllerSource = file_get_contents(__DIR__ . '/../controllers/front/cron.php');
$cronRunnerSource = file_get_contents(__DIR__ . '/../scripts/cron_env_dispatch.php');
assertSameValue(true, is_string($cronControllerSource), 'cron controller source is readable');
assertSameValue(true, is_string($cronRunnerSource), 'cron runner source is readable');
assertSameValue(
    false,
    strpos((string) $cronControllerSource, "Tools::getValue('token')") !== false,
    'cron token is not accepted from the query string'
);
assertSameValue(
    false,
    strpos((string) $cronRunnerSource, "'token' => \$token") !== false,
    'cron token is not added to the request URL'
);
assertSameValue(
    true,
    strpos((string) $cronRunnerSource, 'X-Neuro-Cron-Token: ') !== false,
    'cron token uses a request header'
);

$requestSecuritySource = file_get_contents(
    __DIR__ . '/../src/Security/RequestSecurityValidator.php'
);
assertSameValue(true, is_string($requestSecuritySource), 'request security source is readable');
assertSameValue(
    false,
    strpos((string) $requestSecuritySource, 'X-Neuro-' . 'ApiKeyHash') !== false,
    'API key hashes are never accepted as authentication credentials'
);
assertSameValue(
    true,
    strpos((string) $requestSecuritySource, "'Missing API key'") !== false,
    'Cloud-to-connector authentication fails closed without the raw API key'
);

fwrite(STDOUT, "Release endpoint policy tests passed.\n");

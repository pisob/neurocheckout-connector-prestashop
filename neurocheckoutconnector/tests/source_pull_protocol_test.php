<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../src/Community/SourcePullProtocol.php';

use NeuroCheckout\Community\SourcePullProtocol;

function checkSource(bool $condition): void
{
    if (!$condition) {
        throw new RuntimeException('Source protocol assertion failed');
    }
}

$secret = str_repeat('ab', 32);
$path = '/module/neurocheckoutconnector/communitydata';
$shop = 'synthetic-shop';
$time = '1788969600000';
$nonce = str_repeat('a', 32);
$raw = json_encode(['schema' => 1, 'shopId' => $shop, 'streamId' => null, 'cursor' => '', 'limit' => 8]);
$sign = static function (string $body) use ($secret, $path, $shop, $time, $nonce): string {
    return hash_hmac('sha256', implode("\n", ['nc-source-pull-v1', 'POST', $path, $shop, $time, $nonce, hash('sha256', $body)]), hex2bin($secret));
};
$headers = ['content-type' => 'application/json', 'x-nc-source-time' => $time, 'x-nc-source-nonce' => $nonce, 'x-nc-source-signature' => $sign($raw)];
$used = [];
$consume = static function ($value, $ttl) use (&$used): bool {
    checkSource($ttl >= 240);
    if (isset($used[$value])) {
        return false;
    }
    $used[$value] = true;
    return true;
};
$invoke = static function (array $changes = []) use ($path, $headers, $raw, $shop, $secret, $time, $consume): array {
    return SourcePullProtocol::authenticate($changes['method'] ?? 'POST', $changes['path'] ?? $path,
        $changes['headers'] ?? $headers, $changes['raw'] ?? $raw, $changes['shop'] ?? $shop,
        $changes['secret'] ?? $secret, $changes['now'] ?? (int) $time, $changes['consume'] ?? $consume,
        $changes['enabled'] ?? true, $changes['environment'] ?? 'staging');
};
$mustReject = static function (array $changes) use ($invoke): void {
    try {
        $invoke($changes);
    } catch (Throwable $error) {
        return;
    }
    throw new RuntimeException('Invalid source request accepted');
};
checkSource($invoke()['shopId'] === $shop);
$mustReject([]); // Replay.
foreach ([
    ['enabled' => false], ['environment' => 'production'], ['method' => 'GET'],
    ['shop' => 'other-shop'], ['path' => '/module/neurocheckoutconnector/orderhistory'],
    ['secret' => 'legacy-api-key'], ['now' => (int) $time + 120001],
    ['headers' => $headers + ['origin' => 'https://evil.invalid']],
    ['headers' => $headers + ['cookie' => 'session=x']],
    ['headers' => $headers + ['content-encoding' => 'gzip']],
    ['headers' => ['content-type' => 'application/json']],
] as $invalid) {
    $called = false;
    $invalid['consume'] = static function () use (&$called): bool { $called = true; return true; };
    $mustReject($invalid);
    checkSource(!$called);
}
$malformed = json_encode(['schema' => 1, 'shopId' => $shop, 'streamId' => null, 'cursor' => '', 'limit' => 8, 'url' => 'http://169.254.169.254']);
$called = false;
$mustReject(['raw' => $malformed, 'headers' => array_replace($headers, ['x-nc-source-signature' => $sign($malformed)]),
    'consume' => static function () use (&$called): bool { $called = true; return true; }]);
checkSource(!$called);
checkSource(strlen(SourcePullProtocol::responseSignature($secret, $nonce, '{}')) === 64);
fwrite(STDOUT, "Source pull protocol tests passed.\n");

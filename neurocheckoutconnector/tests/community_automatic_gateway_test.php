<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Community/SourcePullProtocol.php';
require_once __DIR__ . '/../src/Community/SourcePullGateway.php';

use NeuroCheckout\Community\SourcePullGateway;

$root = sys_get_temp_dir() . '/nc-prestashop-auto-' . bin2hex(random_bytes(12));
$webRoot = $root . '/web';
$state = $root . '/private/source';
mkdir($webRoot, 0700, true);
$configuration = [
    'enabled' => true,
    'environment' => 'staging',
    'nativeScope' => 1,
    'platform' => 'prestashop',
    'secret' => str_repeat('ab', 32),
    'shopId' => 'synthetic-shop',
];
$path = '/module/neurocheckoutconnector/communitydata';
$raw = json_encode([
    'schema' => 1,
    'shopId' => 'synthetic-shop',
    'streamId' => null,
    'cursor' => '',
    'limit' => 8,
], JSON_THROW_ON_ERROR);
$time = (string) ((int) floor(microtime(true) * 1000));
$nonce = bin2hex(random_bytes(16));
$headers = [
    'content-type' => 'application/json',
    'x-nc-source-time' => $time,
    'x-nc-source-nonce' => $nonce,
];
$headers['x-nc-source-signature'] = hash_hmac('sha256', implode("\n", [
    'nc-source-pull-v1', 'POST', $path, 'synthetic-shop', $time, $nonce, hash('sha256', $raw),
]), hex2bin($configuration['secret']));

try {
    [$status, $responseHeaders, $response] = SourcePullGateway::handle(
        'prestashop', 1, $webRoot, 'POST', $path, $headers, $raw, true,
        static function (array $input): array {
            return [
                'schema' => 1,
                'shopId' => $input['shopId'],
                'streamId' => str_repeat('a', 32),
                'cursor' => $input['cursor'],
                'nextCursor' => str_repeat('b', 64),
                'complete' => true,
                'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
                'records' => [],
            ];
        },
        $configuration,
        $state
    );
    if ($status !== 200 || !isset($responseHeaders['X-NC-Source-Response'])) {
        throw new RuntimeException('automatic gateway rejected a valid signed pull');
    }
    $payload = json_decode($response, true, 16, JSON_THROW_ON_ERROR);
    if (($payload['shopId'] ?? null) !== 'synthetic-shop' || !is_dir($state)) {
        throw new RuntimeException('automatic gateway did not use its private state directory');
    }
    $configuration['environment'] = 'production';
    [$disabledStatus] = SourcePullGateway::handle(
        'prestashop', 1, $webRoot, 'POST', $path, $headers, $raw, true, null,
        $configuration, $state
    );
    if ($disabledStatus !== 404) {
        throw new RuntimeException('automatic gateway must remain disabled outside staging');
    }
    echo "automatic PrestaShop Community gateway test passed\n";
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}

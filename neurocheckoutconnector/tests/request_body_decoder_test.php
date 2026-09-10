<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Http/RequestBodyDecoder.php';

use NeuroCheckout\Http\RequestBodyDecoder;

function assertDecoder(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL {$label}\n");
        exit(1);
    }
}

$payload = json_encode(['event_id' => 'audit', 'value' => str_repeat('A', 2048)]);
assertDecoder(is_string($payload), 'fixture encoding');

$identity = RequestBodyDecoder::decodeJson((string) $payload, 'identity', 4096);
assertDecoder(!empty($identity['success']), 'identity JSON accepted');

$gzip = gzencode((string) $payload, 9);
assertDecoder(is_string($gzip), 'gzip fixture encoding');
$decoded = RequestBodyDecoder::decodeJson((string) $gzip, 'gzip', 4096);
assertDecoder(!empty($decoded['success']), 'bounded gzip accepted');

$oversized = RequestBodyDecoder::decode((string) $gzip, 'gzip', 128);
assertDecoder(($oversized['status'] ?? 0) === 413, 'decompressed payload limit enforced');

$concatenated = RequestBodyDecoder::decode((string) $gzip . (string) $gzip, 'gzip', 8192);
assertDecoder(($concatenated['status'] ?? 0) === 400, 'concatenated gzip rejected');

$unsupported = RequestBodyDecoder::decode((string) $payload, 'br', 4096);
assertDecoder(($unsupported['status'] ?? 0) === 415, 'unsupported encoding rejected');

$stream = fopen('php://memory', 'w+b');
assertDecoder(is_resource($stream), 'memory stream opened');
fwrite($stream, str_repeat('B', 33));
rewind($stream);
$read = RequestBodyDecoder::readStream($stream, 32);
fclose($stream);
assertDecoder(($read['status'] ?? 0) === 413, 'raw request body limit enforced');

fwrite(STDOUT, "Request body decoder tests passed.\n");

<?php

namespace NeuroCheckout\Http;

class RequestBodyDecoder
{
    private const GZIP_CHUNK_BYTES = 256;

    /**
     * Read at most maxBytes from a stream, plus one byte used only to detect
     * an oversized request without first materialising it in memory.
     *
     * @param resource $stream
     * @return array{success:bool,status:int,error:?string,body:string}
     */
    public static function readStream($stream, int $maxBytes): array
    {
        $limit = max(1, $maxBytes);
        if (!is_resource($stream)) {
            return self::failure(400, 'Unable to read request body');
        }

        $body = stream_get_contents($stream, $limit + 1);
        if (!is_string($body)) {
            return self::failure(400, 'Unable to read request body');
        }
        if (strlen($body) > $limit) {
            return self::failure(413, 'payload_too_large');
        }

        return self::success($body);
    }

    /**
     * @return array{success:bool,status:int,error:?string,body:string}
     */
    public static function readRaw(int $maxBytes): array
    {
        $stream = @fopen('php://input', 'rb');
        if ($stream === false) {
            return self::failure(400, 'Unable to read request body');
        }

        try {
            return self::readStream($stream, $maxBytes);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @return array{success:bool,status:int,error:?string,body:string}
     */
    public static function decode(string $rawBody, string $contentEncoding, int $maxBytes): array
    {
        $limit = max(1, $maxBytes);
        $encoding = strtolower(trim($contentEncoding));
        if ($encoding === '' || $encoding === 'identity') {
            return strlen($rawBody) <= $limit
                ? self::success($rawBody)
                : self::failure(413, 'payload_too_large');
        }
        if ($encoding !== 'gzip') {
            return self::failure(415, 'unsupported_content_encoding');
        }
        if (
            !function_exists('inflate_init')
            || !function_exists('inflate_add')
            || !function_exists('inflate_get_status')
            || !function_exists('inflate_get_read_len')
            || !defined('ZLIB_ENCODING_GZIP')
            || !defined('ZLIB_SYNC_FLUSH')
            || !defined('ZLIB_FINISH')
            || !defined('ZLIB_STREAM_END')
        ) {
            return self::failure(415, 'gzip_not_supported');
        }

        $context = @inflate_init(ZLIB_ENCODING_GZIP);
        if ($context === false) {
            return self::failure(400, 'Invalid gzip body');
        }

        $decodedBody = '';
        $rawLength = strlen($rawBody);
        for ($offset = 0; $offset < $rawLength; $offset += self::GZIP_CHUNK_BYTES) {
            $chunk = substr($rawBody, $offset, self::GZIP_CHUNK_BYTES);
            $isLastChunk = ($offset + strlen($chunk)) >= $rawLength;
            $decodedChunk = @inflate_add(
                $context,
                $chunk,
                $isLastChunk ? ZLIB_FINISH : ZLIB_SYNC_FLUSH
            );
            if ($decodedChunk === false) {
                return self::failure(400, 'Invalid gzip body');
            }
            if (strlen($decodedBody) + strlen($decodedChunk) > $limit) {
                return self::failure(413, 'payload_too_large');
            }
            $decodedBody .= $decodedChunk;
        }

        if (
            @inflate_get_status($context) !== ZLIB_STREAM_END
            || @inflate_get_read_len($context) !== $rawLength
        ) {
            return self::failure(400, 'Invalid gzip body');
        }

        return self::success($decodedBody);
    }

    /**
     * @return array{success:bool,status:int,error:?string,payload?:array<string,mixed>}
     */
    public static function decodeJson(string $rawBody, string $contentEncoding, int $maxBytes): array
    {
        $decoded = self::decode($rawBody, $contentEncoding, $maxBytes);
        if (empty($decoded['success'])) {
            return [
                'success' => false,
                'status' => (int) $decoded['status'],
                'error' => (string) $decoded['error'],
            ];
        }

        $payload = json_decode((string) $decoded['body'], true);
        if (!is_array($payload)) {
            return [
                'success' => false,
                'status' => 422,
                'error' => 'Invalid JSON payload',
            ];
        }

        return [
            'success' => true,
            'status' => 200,
            'error' => null,
            'payload' => $payload,
        ];
    }

    /** @return array{success:bool,status:int,error:?string,body:string} */
    private static function success(string $body): array
    {
        return [
            'success' => true,
            'status' => 200,
            'error' => null,
            'body' => $body,
        ];
    }

    /** @return array{success:bool,status:int,error:string,body:string} */
    private static function failure(int $status, string $error): array
    {
        return [
            'success' => false,
            'status' => $status,
            'error' => $error,
            'body' => '',
        ];
    }
}

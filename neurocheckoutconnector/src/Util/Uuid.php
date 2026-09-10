<?php

namespace NeuroCheckout\Util;

/**
 * Générateur UUID v4 simple
 */
class Uuid
{
    public static function v4(): string
    {
        $data = random_bytes(16);

        // Version 4
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);

        // Variant RFC 4122
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

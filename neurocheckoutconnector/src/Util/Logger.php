<?php

namespace NeuroCheckout\Util;

use PrestaShopLogger;

/**
 * Logger central NeuroCheckout
 */
class Logger
{
    public static function info(string $message): void
    {
        PrestaShopLogger::addLog(
            '[NeuroCheckout] ' . $message,
            1
        );
    }

    public static function warning(string $message): void
    {
        PrestaShopLogger::addLog(
            '[NeuroCheckout] ' . $message,
            2
        );
    }

    public static function error(string $message): void
    {
        PrestaShopLogger::addLog(
            '[NeuroCheckout] ' . $message,
            3
        );
    }
}

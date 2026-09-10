<?php

namespace NeuroCheckout\Util;

use Cache;

/**
 * Throttle simple basé sur cache PrestaShop
 */
class Throttle
{
    /**
     * Autorise action si délai écoulé
     *
     * @param string $key
     * @param float $seconds
     */
    public static function allow(string $key, float $seconds): bool
    {
        $cacheKey = 'nc_throttle_' . $key;

        $last = Cache::retrieve($cacheKey);

        $now = microtime(true);

        if ($last && ($now - $last) < $seconds) {
            return false;
        }

        Cache::store($cacheKey, $now);

        return true;
    }
}

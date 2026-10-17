<?php
declare(strict_types=1);
namespace NeuroCheckout\Community;

/** Bounded native one-unit offers. Never a checkout total or permission to send. */
final class PrestashopCustomerPriceProtocol
{
    public static function request(array $v, string $shopId): void
    {
        if (!self::keys($v, ['schema','shopId','operation','nativeShopId','customerId','customerEmail','productIds','currency'])
            || $v['schema'] !== 1 || $v['shopId'] !== $shopId || $v['operation'] !== 'customer_prices'
            || !self::id($v['nativeShopId']) || !self::id($v['customerId'])
            || !is_string($v['customerEmail']) || strlen($v['customerEmail']) > 254 || !filter_var($v['customerEmail'], FILTER_VALIDATE_EMAIL)
            || !is_string($v['currency']) || !preg_match('/^[A-Z]{3}$/D', $v['currency'])
            || !is_array($v['productIds']) || count($v['productIds']) < 1 || count($v['productIds']) > 16
            || array_keys($v['productIds']) !== range(0, count($v['productIds']) - 1)) { self::fail(); }
        foreach ($v['productIds'] as $id) { if (!self::id($id)) { self::fail(); } }
        if (count(array_unique($v['productIds'])) !== count($v['productIds'])) { self::fail(); }
    }

    public static function response(array $v, array $input, int $scope, string $raw): void
    {
        self::request($input, $input['shopId']);
        if ((string) $scope !== $input['nativeShopId']
            || !self::keys($v, ['schema','shopId','operation','requestHash','prices']) || $v['schema'] !== 1
            || $v['shopId'] !== $input['shopId'] || $v['operation'] !== 'customer_prices'
            || $v['requestHash'] !== hash('sha256', $raw)) { self::fail(); }
        self::prices($v['prices'], $input);
        if (strlen(json_encode($v, JSON_THROW_ON_ERROR)) > 16384) { self::fail(); }
    }

    public static function prices($v, array $input): void
    {
        if (!self::keys($v, ['schema','native_shop_id','customer_id','customer_email','currency','quantity',
                'price_decimals','context_hash','read_at','offers']) || $v['schema'] !== 'prestashop-customer-prices-v1'
            || !is_int($v['native_shop_id']) || (string) $v['native_shop_id'] !== $input['nativeShopId']
            || !is_int($v['customer_id']) || (string) $v['customer_id'] !== $input['customerId']
            || !is_string($v['customer_email']) || strcasecmp($v['customer_email'], $input['customerEmail']) !== 0
            || $v['currency'] !== $input['currency'] || $v['quantity'] !== 1
            || !is_int($v['price_decimals']) || $v['price_decimals'] < 0 || $v['price_decimals'] > 6
            || !is_string($v['context_hash']) || !preg_match('/^[a-f0-9]{64}$/D', $v['context_hash'])
            || !is_string($v['read_at']) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $v['read_at'])
            || !($date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $v['read_at'], new \DateTimeZone('UTC')))
            || $date->format('Y-m-d\TH:i:s\Z') !== $v['read_at'] || abs(time()-$date->getTimestamp()) > 15
            || !is_array($v['offers']) || array_keys($v['offers']) !== array_keys($input['productIds'])) { self::fail(); }
        foreach ($v['offers'] as $i => $offer) {
            if (!is_array($offer) || ($offer['product_id'] ?? null) !== $input['productIds'][$i]) { self::fail(); }
            if (($offer['status'] ?? null) === 'unavailable') {
                if (!self::keys($offer, ['product_id','status'])) { self::fail(); }
                continue;
            }
            if (!self::keys($offer, ['product_id','attribute_id','status','currency','price_excl_tax','price_incl_tax','display_mode','display_price'])
                || $offer['status'] !== 'available' || !self::id($offer['attribute_id'], true) || $offer['currency'] !== $v['currency']
                || !in_array($offer['display_mode'], ['including_tax','excluding_tax'], true)) { self::fail(); }
            foreach (['price_excl_tax','price_incl_tax','display_price'] as $k) {
                if ((!is_int($offer[$k]) && !is_float($offer[$k])) || !is_finite((float) $offer[$k]) || $offer[$k] < 0 || $offer[$k] > 1e9) { self::fail(); }
            }
            $price=$offer[$offer['display_mode'] === 'including_tax' ? 'price_incl_tax' : 'price_excl_tax'];
            // PS supports directed rounding too (up/down), not just half-up.
            $scale=10 ** $v['price_decimals'];
            if (abs($offer['display_price']-$price) > 1/$scale+1e-8
                || abs($offer['display_price']-round($offer['display_price']*$scale)/$scale) > 1e-8) { self::fail(); }
        }
    }

    private static function id($v, bool $zero=false): bool
    { return is_string($v) && (bool) preg_match($zero ? '/^(?:0|[1-9][0-9]{0,9})$/D' : '/^[1-9][0-9]{0,9}$/D', $v); }
    private static function keys($v, array $keys): bool
    { if (!is_array($v)) { return false; } $actual=array_keys($v); sort($actual); sort($keys); return $actual===$keys; }
    private static function fail(): void { throw new \RuntimeException('customer_prices_unavailable'); }
}

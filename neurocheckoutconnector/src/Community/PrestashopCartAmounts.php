<?php
declare(strict_types=1);

namespace NeuroCheckout\Community;

use RuntimeException;

/** Native checkout amounts; never reconstruct tax/discount rules from catalogue prices. */
final class PrestashopCartAmounts
{
    public static function capture(array $snapshot, int $scope): array
    {
        $cart = new \Cart((int) $snapshot['id_cart']);
        if (!(int) $cart->id || (int) $cart->id_shop !== $scope
            || (int) $cart->id_currency !== (int) $snapshot['id_currency']
            || (int) $cart->id_customer !== (int) $snapshot['id_customer']
            || (int) $cart->id_lang !== (int) $snapshot['id_lang']
            || (string) $cart->date_upd !== (string) $snapshot['date_upd']) {
            throw new RuntimeException('source_amounts_changed');
        }
        $context = \Context::getContext();
        $saved = [];
        foreach (['cart', 'shop', 'customer', 'currency', 'language', 'country'] as $key) {
            $saved[$key] = $context->$key;
        }
        try {
            $context->cart = $cart;
            $context->shop = new \Shop($scope);
            $context->customer = new \Customer((int) $cart->id_customer);
            $context->currency = new \Currency((int) $cart->id_currency);
            $context->language = new \Language((int) $cart->id_lang);
            if ((int) $cart->id_address_delivery) {
                $address = new \Address((int) $cart->id_address_delivery);
                $context->country = new \Country((int) $address->id_country);
            }
            $products = $cart->getProducts(true);
            $byKey = [];
            foreach ($products as $line) {
                $key = self::key($line);
                if (isset($byKey[$key])) { throw new RuntimeException('source_amounts_ambiguous'); }
                $byKey[$key] = $line;
            }
            $items = $snapshot['items'];
            foreach ($items as &$line) {
                $key = self::key($line);
                $priced = $byKey[$key] ?? null;
                if (!$priced || (int) $priced['cart_quantity'] !== (int) $line['quantity']) {
                    throw new RuntimeException('source_amounts_changed');
                }
                $line['unit_price'] = self::money($priced['price_wt'] ?? null);
                $line['line_total'] = self::money($priced['total_wt'] ?? null);
                unset($byKey[$key]);
            }
            unset($line);
            if ($byKey) { throw new RuntimeException('source_amounts_changed'); }
            $total = self::money($cart->getOrderTotal(true, \Cart::BOTH, $products));
            $taxExcluded = self::money($cart->getOrderTotal(false, \Cart::BOTH, $products));
            $fresh = new \Cart((int) $cart->id);
            if ((string) $fresh->date_upd !== (string) $snapshot['date_upd']
                || $fresh->orderExists()) {
                throw new RuntimeException('source_amounts_changed');
            }
            return ['items' => $items, 'cart_total' => $total,
                'cart_total_tax_excl' => $taxExcluded, 'amount_source' => 'prestashop-checkout-v1'];
        } finally {
            foreach ($saved as $key => $value) { $context->$key = $value; }
        }
    }

    private static function key(array $line): string
    {
        return implode(':', array_map(static function ($key) use ($line) {
            return (string) (int) ($line[$key] ?? 0);
        }, ['id_product', 'id_product_attribute', 'id_customization', 'id_address_delivery']));
    }

    private static function money($value): string
    {
        if (!is_numeric($value) || !is_finite((float) $value) || (float) $value < 0) {
            throw new RuntimeException('source_amounts_invalid');
        }
        return number_format((float) $value, 6, '.', '');
    }
}

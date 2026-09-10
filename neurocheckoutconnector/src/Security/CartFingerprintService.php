<?php

namespace NeuroCheckout\Security;

use Cart;

class CartFingerprintService
{
    public function fromCart(Cart $cart): string
    {
        $lines = [];

        try {
            $products = $cart->getProducts();
        } catch (\Throwable $e) {
            $products = [];
        }

        if (!is_array($products)) {
            return hash('sha256', '');
        }

        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }

            $qty = (int) ($product['cart_quantity'] ?? $product['quantity'] ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $lines[] = implode('|', [
                'pid:' . (int) ($product['id_product'] ?? 0),
                'attr:' . (int) ($product['id_product_attribute'] ?? 0),
                'ref:' . strtolower(trim((string) ($product['reference'] ?? ''))),
                'qty:' . $qty,
            ]);
        }

        sort($lines);

        return hash('sha256', implode("\n", $lines));
    }
}

<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../src/Community/PrestashopCartAmounts.php';

class Context {
    private static $instance;
    public $cart; public $shop; public $customer; public $currency; public $language; public $country;
    public static function getContext() { return self::$instance ?? (self::$instance = new self()); }
}
class Shop { public function __construct($id) {} }
class Customer { public function __construct($id) {} }
class Currency { public function __construct($id) {} }
class Language { public function __construct($id) {} }
class Cart {
    public const BOTH = 3;
    public $id = 28; public $id_shop = 1; public $id_currency = 2; public $id_customer = 13;
    public $id_lang = 1; public $id_address_delivery = 0; public $date_upd = '2026-09-14 15:49:32';
    public static $quantity = 3; public static $fail = false; public static $converted = false;
    public function __construct($id) {}
    public function getProducts($refresh) {
        return [['id_product'=>1,'id_product_attribute'=>2,'cart_quantity'=>self::$quantity,
            'price_wt'=>22.943333,'total_wt'=>68.83],
            ['id_product'=>9,'id_product_attribute'=>22,'cart_quantity'=>4,'price_wt'=>22.68,'total_wt'=>90.72]];
    }
    public function getOrderTotal($tax, $type, $products) {
        if (self::$fail) { throw new RuntimeException('fixture'); }
        return $tax ? 159.55 : 133.0;
    }
    public function orderExists() { return self::$converted; }
}
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
$payload = ['id_cart'=>28,'id_currency'=>2,'id_customer'=>13,'id_lang'=>1,'date_upd'=>'2026-09-14 15:49:32',
    'items'=>[['id_product'=>1,'id_product_attribute'=>2,'quantity'=>3],
              ['id_product'=>9,'id_product_attribute'=>22,'quantity'=>4]]];
$context = Context::getContext(); $context->cart = (object)['original'=>true];
$saved = clone $context;
$amounts = \NeuroCheckout\Community\PrestashopCartAmounts::capture($payload, 1);
check($amounts['cart_total'] === '159.550000', 'native total must survive');
check($amounts['items'][0]['line_total'] === '68.830000', 'native rounded line must survive');
check($context == $saved, 'context restored after success');
foreach (['quantity','fail','converted'] as $case) {
    Cart::$quantity = $case === 'quantity' ? 5 : 3;
    Cart::$fail = $case === 'fail'; Cart::$converted = $case === 'converted';
    $refused = false;
    try { \NeuroCheckout\Community\PrestashopCartAmounts::capture($payload, 1); }
    catch (RuntimeException $e) { $refused = true; }
    check($refused, 'changed or unpriceable cart refused: ' . $case);
    check($context == $saved, 'context restored after error');
}
echo "Native cart amount tests passed.\n";

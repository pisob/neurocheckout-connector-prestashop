<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
class Context {
    public $cart = null;
    public static $instance;
    public static function getContext() { return self::$instance; }
}
class Currency {
    public $iso_code = 'EUR';
    public function __construct($id) {}
}
class Cart {
    const BOTH = 3;
    public $id = 8;
    public $id_currency = 1;
    public $fail = false;
    private function checkContext() {
        if (Context::getContext()->cart !== $this) {
            // Faithfully reproduce PrestaShop 1.7's uncatchable pricing failure.
            fwrite(STDERR, "Uninitialized pricing context\n"); exit(1);
        }
        if ($this->fail) { throw new RuntimeException('synthetic pricing failure'); }
    }
    public function getOrderTotal($tax, $type) { $this->checkContext(); return 57.36; }
    public function getProducts($refresh = false) {
        $this->checkContext();
        return [['id_product'=>1,'cart_quantity'=>3,'price_wt'=>19.12,'total_wt'=>57.36,'name'=>'Test shirt']];
    }
}
require __DIR__.'/../neurocheckoutconnector/src/Event/CustomerJourneyEventBuilder.php';
$method = new ReflectionMethod('NeuroCheckout\\Event\\CustomerJourneyEventBuilder', 'buildCartPayload');
$method->setAccessible(true);
Context::$instance = new Context();
$cart = new Cart();
foreach ([null, new Cart()] as $previous) {
    Context::$instance->cart = $previous;
    $result = $method->invoke(null, $cart, true);
    if ($result['total'] !== 57.36 || count($result['items']) !== 1 || $result['items'][0]['quantity'] !== 3) {
        throw new RuntimeException('Cart payload was not calculated');
    }
    if (Context::$instance->cart !== $previous) { throw new RuntimeException('Visitor context was replaced'); }
    $cart->fail = true;
    $method->invoke(null, $cart, true);
    if (Context::$instance->cart !== $previous) { throw new RuntimeException('Context not restored after failure'); }
    $cart->fail = false;
}
echo "PASS: early cart hook, correct prices/items and context restoration on success/failure.\n";

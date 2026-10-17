<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
function prestaHistoryFixture(): array {
    $line=['item_id'=>'41','product_id'=>'10','attribute_id'=>'11','quantity'=>'2','quantity_refunded'=>'1','quantity_returned'=>'0',
        'total_price_tax_incl'=>'48.000000','total_price_tax_excl'=>'40.000000'];
    return ['schema'=>'prestashop-customer-history-v1','native_shop_id'=>2,'customer_id'=>47,'customer_email'=>'Reader@example.invalid',
        'customer_created_at'=>'2019-12-01 00:00:00','native_timezone'=>'Europe/Paris','read_at'=>gmdate('Y-m-d\TH:i:s\Z'),
        'complete'=>true,'order_count'=>1,'marketing_consent'=>'denied','orders'=>[
            ['order_id'=>'31','current_state_id'=>'3','state_paid'=>false,'state_logable'=>false,'valid'=>false,
                'created_at'=>'2020-01-01 00:00:00','updated_at'=>'2020-01-02 00:00:00','currency'=>'EUR',
                'total_paid_tax_incl'=>'48.000000','total_paid_real'=>'0.000000','items'=>[$line],
                'credit_slips'=>[['slip_id'=>'51','created_at'=>'2020-01-02 00:00:00','updated_at'=>'2020-01-02 00:00:00',
                    'type'=>1,'total_products_tax_incl'=>'24.000000','total_shipping_tax_incl'=>'0.000000',
                    'items'=>[['item_id'=>'41','quantity'=>'1','amount_tax_incl'=>'24.000000','amount_tax_excl'=>'20.000000']]]]]],
        'payments'=>[['payment_id'=>'61','created_at'=>'2020-01-02 00:00:00','amount'=>'24.000000','currency'=>'EUR',
            'conversion_rate'=>'1.000000','order_ids'=>['31'],'invoice_order_ids'=>[]]]];
}

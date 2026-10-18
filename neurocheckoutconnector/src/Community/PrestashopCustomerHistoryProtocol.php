<?php
declare(strict_types=1);
namespace NeuroCheckout\Community;
require_once __DIR__ . '/NativeHistoryV2.php';

/** Native account facts, not proof that an order or credit note was settled. */
final class PrestashopCustomerHistoryProtocol
{
    public static function request(array $v, string $shop): void
    {
        if (!self::keys($v,['schema','shopId','operation','nativeShopId','customerId','customerEmail'])
            || $v['schema']!==1 || $v['shopId']!==$shop || $v['operation']!=='customer_history') { self::fail(); }
        self::id($v['nativeShopId']); self::id($v['customerId']);
        if (!is_string($v['customerEmail']) || strlen($v['customerEmail'])>254
            || !filter_var($v['customerEmail'],FILTER_VALIDATE_EMAIL)) { self::fail(); }
    }

    public static function response($page, array $input, int $scope, string $raw): void
    {
        if (!self::keys($page,['schema','shopId','operation','requestHash','history']) || $page['schema']!==1
            || $page['shopId']!==$input['shopId'] || $page['operation']!=='customer_history'
            || $page['requestHash']!==hash('sha256',$raw) || (string)$scope!==$input['nativeShopId']) { self::fail(); }
        self::history($page['history'],$input);
        if (strlen(json_encode($page,JSON_THROW_ON_ERROR))>65536) { self::fail(); }
    }

    public static function history($h, array $input): void
    {
        $h=NativeHistoryV2::legacy($h,'prestashop-customer-history-v1');
        if (!self::keys($h,['schema','native_shop_id','customer_id','customer_email','customer_created_at',
                'native_timezone','read_at','complete','order_count','orders','payments','marketing_consent'])
            || $h['schema']!=='prestashop-customer-history-v1' || $h['complete']!==true
            || !is_int($h['native_shop_id']) || (string)$h['native_shop_id']!==$input['nativeShopId']
            || !is_int($h['customer_id']) || (string)$h['customer_id']!==$input['customerId']
            || !is_string($h['customer_email']) || strcasecmp($h['customer_email'],$input['customerEmail'])!==0
            || !is_string($h['native_timezone']) || !in_array($h['native_timezone'],\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC),true)
            || !in_array($h['marketing_consent'],['granted','denied','unknown'],true)
            || !is_string($h['read_at']) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$h['read_at'])
            || abs(time()-(strtotime($h['read_at'])?:0))>15 || !self::list($h['orders']) || count($h['orders'])>128
            || $h['order_count']!==count($h['orders']) || !self::list($h['payments']) || count($h['payments'])>128) { self::fail(); }
        self::date($h['customer_created_at']); self::date(str_replace(['T','Z'],[' ',''],$h['read_at']));
        $ids=[]; $lineIds=[]; $slipIds=[]; $lines=0;
        foreach ($h['orders'] as $o) {
            if (!self::keys($o,['order_id','current_state_id','state_paid','state_logable','valid','created_at','updated_at',
                    'currency','total_paid_tax_incl','total_paid_real','items','credit_slips']) || !is_bool($o['valid'])
                || !($o['state_paid']===null||is_bool($o['state_paid'])) || !($o['state_logable']===null||is_bool($o['state_logable']))
                || !self::list($o['items']) || !$o['items'] || !self::list($o['credit_slips'])) { self::fail(); }
            self::unique($o['order_id'],$ids); self::id($o['current_state_id'],true); self::currency($o['currency']);
            self::date($o['created_at']); self::date($o['updated_at']); self::decimal($o['total_paid_tax_incl']);
            if ($o['total_paid_real']!==null) { self::decimal($o['total_paid_real'],true); }
            $originals=[];
            foreach ($o['items'] as $item) {
                if (++$lines>512 || !self::keys($item,['item_id','product_id','attribute_id','quantity','quantity_refunded','quantity_returned',
                        'total_price_tax_incl','total_price_tax_excl'])) { self::fail(); }
                self::unique($item['item_id'],$lineIds); $originals[$item['item_id']]=true;
                foreach (['product_id','attribute_id','quantity','quantity_refunded','quantity_returned'] as $k) { self::id($item[$k],true); }
                self::decimal($item['total_price_tax_incl']); self::decimal($item['total_price_tax_excl']);
            }
            foreach ($o['credit_slips'] as $slip) {
                if (!self::keys($slip,['slip_id','created_at','updated_at','type','total_products_tax_incl','total_shipping_tax_incl','items'])
                    || !is_int($slip['type']) || !in_array($slip['type'],[0,1,2],true) || !self::list($slip['items'])) { self::fail(); }
                self::unique($slip['slip_id'],$slipIds); if (count($slipIds)>128) { self::fail(); }
                self::date($slip['created_at']); self::date($slip['updated_at']);
                self::decimal($slip['total_products_tax_incl']); self::decimal($slip['total_shipping_tax_incl']);
                $seen=[];
                foreach ($slip['items'] as $item) {
                    if (++$lines>512 || !self::keys($item,['item_id','quantity','amount_tax_incl','amount_tax_excl'])
                        || !is_string($item['item_id']) || !isset($originals[$item['item_id']])) { self::fail(); }
                    self::unique($item['item_id'],$seen); self::id($item['quantity'],true);
                    foreach (['amount_tax_incl','amount_tax_excl'] as $k) { if ($item[$k]!==null) { self::decimal($item[$k]); } }
                }
            }
        }
        $payments=[];
        foreach ($h['payments'] as $p) {
            if (!self::keys($p,['payment_id','created_at','amount','currency','conversion_rate','order_ids','invoice_order_ids'])
                || !self::list($p['order_ids']) || !$p['order_ids'] || count($p['order_ids'])>128
                || !self::list($p['invoice_order_ids']) || count($p['invoice_order_ids'])>128) { self::fail(); }
            self::unique($p['payment_id'],$payments); self::date($p['created_at']); self::currency($p['currency']);
            self::decimal($p['amount'],true); self::decimal($p['conversion_rate']);
            if (!preg_match('/[1-9]/',$p['conversion_rate'])) { self::fail(); }
            $orders=[]; $invoices=[];
            foreach ($p['order_ids'] as $id) { self::unique($id,$orders); if (!isset($ids[$id])) { self::fail(); } }
            foreach ($p['invoice_order_ids'] as $id) { self::unique($id,$invoices); if (!isset($orders[$id])) { self::fail(); } }
        }
        if (strlen(json_encode($h,JSON_THROW_ON_ERROR))>64000) { self::fail(); }
    }

    private static function date($v): void
    {
        // Native DATETIME is shop-local, not UTC. The current timezone does not
        // prove a historical offset (configuration changes / DST ambiguity).
        if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$v)
            || !($date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$v,new \DateTimeZone('UTC')))
            || $date->format('Y-m-d H:i:s')!==$v || substr($v,0,4)==='0000') { self::fail(); }
    }
    private static function decimal($v, bool $signed=false): void
    { if (!is_string($v) || !preg_match('/^'.($signed?'-?':'').'\d{1,26}(?:\.\d{1,8})?$/D',$v)) { self::fail(); } }
    private static function currency($v): void { if (!is_string($v)||!preg_match('/^[A-Z]{3}$/D',$v)) { self::fail(); } }
    private static function id($v, bool $zero=false): void
    { if (!is_string($v)||!preg_match($zero?'/^(?:0|[1-9][0-9]{0,9})$/D':'/^[1-9][0-9]{0,9}$/D',$v)) { self::fail(); } }
    private static function unique($v,array &$seen): void { self::id($v);if(isset($seen[$v]))self::fail();$seen[$v]=true; }
    private static function list($v): bool { return is_array($v)&&($v===[]||array_keys($v)===range(0,count($v)-1)); }
    private static function keys($v,array $expected): bool { if(!is_array($v))return false;$keys=array_keys($v);sort($keys);sort($expected);return $keys===$expected; }
    private static function fail(): void { throw new \RuntimeException('customer_history_unavailable'); }
}

<?php
declare(strict_types=1);
// Explicit opt-in for the disposable local PS 1.7.8.11 fixture only. No writes
// to merchant rows, configuration, installed modules or consent; no send path.
if (PHP_SAPI !== 'cli' || getenv('NC_PS17_PRICE_PROBE') !== '1') { exit(2); }
ini_set('zend.exception_ignore_args','1');
require_once '/var/www/html/config/config.inc.php';
if (_PS_VERSION_ !== '1.7.8.11' || _DB_NAME_ !== 'prestashop17') { exit(2); }
require_once __DIR__.'/../src/Community/PrestashopCustomerProductPrices.php';
use NeuroCheckout\Community\PrestashopCustomerProductPrices;
$mode=$argv[1]??'native';
$allowed=['native','email','account','scope','currency','caller_transaction','browser_customer','browser_cart','second_read'];
if (!in_array($mode,$allowed,true)) { exit(2); }
Shop::setContext(Shop::CONTEXT_SHOP,1);
$context=Context::getContext();$context->shop=new Shop(1);
$db=Db::getInstance()->getLink();
$customer=$db->query('SELECT c.id_customer,c.email FROM `'._DB_PREFIX_.'customer` c WHERE c.id_shop=1 AND c.active=1 AND c.deleted=0 AND c.is_guest=0'
    .' AND EXISTS (SELECT 1 FROM `'._DB_PREFIX_.'address` a JOIN `'._DB_PREFIX_.'country` co ON co.id_country=a.id_country'
    .' WHERE a.id_customer=c.id_customer AND a.active=1 AND a.deleted=0 AND co.active=1) ORDER BY c.id_customer LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$ids=$db->query('SELECT id_product FROM `'._DB_PREFIX_.'product_shop` WHERE id_shop=1 AND active=1 ORDER BY id_product LIMIT 8')->fetchAll(PDO::FETCH_COLUMN);
$ids=array_map('strval',$ids);
if (!$customer || count($ids)!==8) { throw new RuntimeException('missing native fixture'); }
$currency=new Currency((int)Configuration::get('PS_CURRENCY_DEFAULT'));
$args=[1,(int)$customer['id_customer'],$customer['email'],$ids,$currency->iso_code];
if($mode==='email')$args[2]='foreign@example.invalid';
if($mode==='account')$args[1]=2147483647;
if($mode==='scope')$args[0]=2;
if($mode==='currency')$args[4]='XXX';
if($mode==='browser_customer')$context->customer=new Customer((int)$customer['id_customer']);
if($mode==='browser_cart'){$context->cart=new Cart();$context->cart->id_address_delivery=1;}
if($mode==='caller_transaction')$db->beginTransaction();
$before=[$context->customer,$context->cart,$context->currency,$context->shop];
$limits=$db->query('SELECT @@session.max_statement_time,@@session.lock_wait_timeout')->fetch(PDO::FETCH_NUM);
$checks=0;
function pcheck(bool $ok,string $name):void {global $checks;++$checks;if(!$ok)throw new RuntimeException($name);}
try {
    try {
        $prices=(new PrestashopCustomerProductPrices())->read(...$args);
        if($mode==='second_read')(new PrestashopCustomerProductPrices())->read(...$args);
        pcheck($mode==='native','unsafe context accepted');
        $native=$context->cloneContext();$native->cart=new Cart();$native->currency=$currency;
        $address=(int)Address::getFirstCustomerAddressId((int)$customer['id_customer']);
        $display=(int)Group::getPriceDisplayMethod(Customer::getDefaultGroupId((int)$customer['id_customer']));
        $available=0;
        foreach($prices['offers'] as $offer){
            if($offer['status']!=='available')continue;
            ++$available;$specific=null;$attribute=(int)$offer['attribute_id'];
            $excl=Product::getPriceStatic((int)$offer['product_id'],false,$attribute?:false,6,null,false,true,1,false,
                (int)$customer['id_customer'],0,$address,$specific,true,true,$native,true,0);
            $incl=Product::getPriceStatic((int)$offer['product_id'],true,$attribute?:false,6,null,false,true,1,false,
                (int)$customer['id_customer'],0,$address,$specific,true,true,$native,true,0);
            pcheck(abs($offer['price_excl_tax']-$excl)<1e-8&&abs($offer['price_incl_tax']-$incl)<1e-8,'native taxes/reductions');
            pcheck(abs($offer['display_price']-Tools::ps_round($display===0?$incl:$excl,$currency->precision))<1e-8,'native display');
            pcheck($attribute===(int)Product::getDefaultAttribute((int)$offer['product_id']),'native default combination');
        }
        pcheck($available===8,'eight available native offers');
    } catch(RuntimeException $error) {
        if($mode==='native'||$error->getMessage()!=='customer_prices_unavailable')throw $error;
        pcheck(true,'constant refusal');
    }
    pcheck([$context->customer,$context->cart,$context->currency,$context->shop]===$before,'global context unchanged');
    pcheck($db->query('SELECT @@session.max_statement_time,@@session.lock_wait_timeout')->fetch(PDO::FETCH_NUM)===$limits,'session limits restored');
    pcheck($db->inTransaction()===($mode==='caller_transaction'),'transaction ownership');
} finally {if($mode==='caller_transaction'&&$db->inTransaction())$db->rollBack();}
echo "Presta native pricing $mode: $checks checks passed.\n";

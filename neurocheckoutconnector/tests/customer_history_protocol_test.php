<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
use NeuroCheckout\Community\SourcePullProtocol;
use NeuroCheckout\Community\SourcePullGateway;
use NeuroCheckout\Community\PrestashopCustomerHistoryProtocol as Protocol;
require_once __DIR__.'/../src/Community/SourcePullProtocol.php';
require_once __DIR__.'/../src/Community/SourcePullGateway.php';
require_once __DIR__.'/customer_history_fixture.php';
set_error_handler(static function($severity,$message){throw new RuntimeException($message);});
$checks=0;
function hcheck(bool $ok,string $label): void { global $checks;++$checks;if(!$ok)throw new RuntimeException($label); }
function hreject(callable $fn,string $label): void {
    try {$fn();} catch(Throwable $e) {hcheck($e->getMessage()==='customer_history_unavailable',$label.' constant error');return;}
    throw new RuntimeException($label.' unexpectedly accepted');
}
$input=['schema'=>1,'shopId'=>'synthetic-shop','operation'=>'customer_history','nativeShopId'=>'2',
    'customerId'=>'47','customerEmail'=>'reader@example.invalid'];
$history=prestaHistoryFixture(); Protocol::request($input,'synthetic-shop');Protocol::history($history,$input);
hcheck(true,'native facts accepted without paid inference');
$v2=$history;$v2['schema']='prestashop-customer-history-v2';
foreach([null,'0','7.12500000'] as $discount){$v2['orders'][0]['discount_total']=$discount;Protocol::history($v2,$input);hcheck(true,'v2 discount preserved');}
foreach([true,2,'-1','1e2',str_repeat('9',27)] as $discount){$bad=$v2;$bad['orders'][0]['discount_total']=$discount;hreject(static function()use($bad,$input){Protocol::history($bad,$input);},'invalid v2 discount');}
$bad=$v2;unset($bad['orders'][0]['discount_total']);hreject(static function()use($bad,$input){Protocol::history($bad,$input);},'missing v2 discount');
foreach (['schema'=>2,'shopId'=>'other','operation'=>'other','nativeShopId'=>'02','customerId'=>47,'customerEmail'=>"bad\n@example.invalid",'url'=>'https://other.invalid'] as $k=>$v) {
    $bad=array_replace($input,[$k=>$v]);hreject(static function()use($bad){Protocol::request($bad,'synthetic-shop');},'request '.$k);
}
foreach ([
    'schema'=>'woocommerce-customer-history-v1','native_shop_id'=>3,'customer_id'=>48,'customer_email'=>'foreign@example.invalid',
    'customer_created_at'=>'2020-02-30 00:00:00','native_timezone'=>'invalid','read_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-60),
    'complete'=>false,'order_count'=>true,'marketing_consent'=>true,'extra'=>'private',
] as $k=>$v) {$bad=array_replace($history,[$k=>$v]);hreject(static function()use($bad,$input){Protocol::history($bad,$input);},'history '.$k);}
foreach ([
    'order_id'=>'0','current_state_id'=>false,'state_paid'=>'1','state_logable'=>1,'valid'=>null,
    'created_at'=>'0000-00-00 00:00:00','updated_at'=>'2020-02-30 00:00:00','currency'=>'euro',
    'total_paid_tax_incl'=>'1e2','total_paid_real'=>false,'items'=>[],'extra'=>1,
] as $k=>$v) {$bad=$history;$bad['orders'][0][$k]=$v;hreject(static function()use($bad,$input){Protocol::history($bad,$input);},'order '.$k);}
foreach (['item_id'=>'0','product_id'=>10,'attribute_id'=>'01','quantity'=>'-1','quantity_refunded'=>'1.5','quantity_returned'=>null,
    'total_price_tax_incl'=>'-1','total_price_tax_excl'=>40,'extra'=>1] as $k=>$v) {
    $bad=$history;$bad['orders'][0]['items'][0][$k]=$v;hreject(static function()use($bad,$input){Protocol::history($bad,$input);},'line '.$k);
}
foreach (['slip_id'=>'0','type'=>3,'created_at'=>'invalid','total_products_tax_incl'=>'-1','total_shipping_tax_incl'=>null,'payment_refunded'=>true] as $k=>$v) {
    $bad=$history;$bad['orders'][0]['credit_slips'][0][$k]=$v;hreject(static function()use($bad,$input){Protocol::history($bad,$input);},'slip '.$k);
}
foreach (['item_id'=>'999','quantity'=>'-1','amount_tax_incl'=>'NaN','amount_tax_excl'=>20] as $k=>$v) {
    $bad=$history;$bad['orders'][0]['credit_slips'][0]['items'][0][$k]=$v;hreject(static function()use($bad,$input){Protocol::history($bad,$input);},'credit line '.$k);
}
foreach (['payment_id'=>'0','amount'=>24,'currency'=>'eur','conversion_rate'=>'0','order_ids'=>['999'],'invoice_order_ids'=>['999'],'extra'=>'card'] as $k=>$v) {
    $bad=$history;$bad['payments'][0][$k]=$v;hreject(static function()use($bad,$input){Protocol::history($bad,$input);},'payment '.$k);
}
foreach (['payments','orders','items','slips','slip_items','payment_orders'] as $kind) {
    $bad=$history;
    if($kind==='payments')$bad['payments'][]=$bad['payments'][0];
    if($kind==='orders'){$bad['orders'][]=$bad['orders'][0];$bad['order_count']=2;}
    if($kind==='items')$bad['orders'][0]['items'][]=$bad['orders'][0]['items'][0];
    if($kind==='slips')$bad['orders'][0]['credit_slips'][]=$bad['orders'][0]['credit_slips'][0];
    if($kind==='slip_items')$bad['orders'][0]['credit_slips'][0]['items'][]=$bad['orders'][0]['credit_slips'][0]['items'][0];
    if($kind==='payment_orders')$bad['payments'][0]['order_ids'][]='31';
    hreject(static function()use($bad,$input){Protocol::history($bad,$input);},'duplicate '.$kind);
}
$good=$history;$good['orders'][0]['state_paid']=null;$good['orders'][0]['total_paid_real']=null;
$good['orders'][0]['credit_slips'][0]['items'][0]['amount_tax_incl']=null;$good['payments'][0]['amount']='-24.000000';
Protocol::history($good,$input);hcheck(true,'unknown state, unknown credit amount and negative payment preserved');
$empty=$history;$empty['orders']=[];$empty['payments']=[];$empty['order_count']=0;Protocol::history($empty,$input);hcheck(true,'explicit complete empty account');
foreach (['orders','payments','lines','bytes'] as $kind) {
    $bad=$history;
    if($kind==='orders'){$bad['orders']=array_fill(0,129,$bad['orders'][0]);$bad['order_count']=129;}
    if($kind==='payments')$bad['payments']=array_fill(0,129,$bad['payments'][0]);
    if($kind==='lines'||$kind==='bytes'){$bad['orders'][0]['credit_slips']=[];$bad['orders'][0]['items']=[];
        for($i=0;$i<($kind==='lines'?513:512);$i++)$bad['orders'][0]['items'][]=array_replace($history['orders'][0]['items'][0],['item_id'=>(string)(100+$i)]);}
    hreject(static function()use($bad,$input){Protocol::history($bad,$input);},'capacity '.$kind);
}
$directory=sys_get_temp_dir().'/nc-presta-history-protocol-'.bin2hex(random_bytes(8));mkdir($directory,0700);
$config=['enabled'=>true,'environment'=>'production','nativeScope'=>2,'platform'=>'prestashop','secret'=>bin2hex(random_bytes(32)),'shopId'=>'synthetic-shop'];
$path='/shop/module/neurocheckoutconnector/communitydata';$calls=0;
$sign=static function($raw,$path)use($config):array{
    $time=(string)(int)floor(microtime(true)*1000);$nonce=bin2hex(random_bytes(16));
    return ['content-type'=>'application/json','x-nc-source-time'=>$time,'x-nc-source-nonce'=>$nonce,
        'x-nc-source-signature'=>hash_hmac('sha256',implode("\n",['nc-source-pull-v1','POST',$path,$config['shopId'],$time,$nonce,hash('sha256',$raw)]),hex2bin($config['secret']))];
};
$export=static function($v)use(&$calls,$history):array{++$calls;return ['schema'=>1,'shopId'=>$v['shopId'],'operation'=>'customer_history','requestHash'=>hash('sha256',json_encode($v)),'history'=>$history];};
$invoke=static function($v,$headers=null,$callback=null,$https=true)use($config,$directory,$path,$sign,$export):array{
    $raw=json_encode($v);return SourcePullGateway::handle('prestashop',2,__DIR__,'POST',$path,$headers??$sign($raw,$path),$raw,$https,$callback??$export,$config,$directory);
};
try {
    $raw=json_encode($input);$headers=$sign($raw,$path);$r=$invoke($input,$headers);
    hcheck($r[0]===200&&$calls===1,'signed native history');
    hcheck($r[1]['X-NC-Source-Response']===SourcePullProtocol::responseSignature($config['secret'],$headers['x-nc-source-nonce'],$r[2]),'response signature');
    hcheck($r[1]['Cache-Control']==='no-store, private','not cacheable');
    hcheck($invoke($input,$headers)[0]===409&&$calls===1,'replay rejected');
    hcheck($invoke(array_replace($input,['customerId'=>'48']),$headers)[0]===401&&$calls===1,'tampered identity rejected');
    hcheck($invoke(array_replace($input,['nativeShopId'=>'3']))[0]===403&&$calls===1,'scope bound to runtime');
    hcheck($invoke(array_replace($input,['shopId'=>'foreign']))[0]===400&&$calls===1,'external shop bound');
    hcheck($invoke($input,null,null,false)[0]===403&&$calls===1,'TLS required');
    foreach(['cookie'=>'session=synthetic','origin'=>'https://example.invalid','content-encoding'=>'gzip']as$k=>$v){$h=$sign($raw,$path);$h[$k]=$v;hcheck($invoke($input,$h)[0]===400&&$calls===1,'browser/encoding refused');}
    $bad=static function($v)use($export):array{$r=$export($v);$r['requestHash']=str_repeat('0',64);return $r;};
    $r=$invoke($input,null,$bad);hcheck($r[0]===503&&!isset($r[1]['X-NC-Source-Response'])&&strpos($r[2],'reader@')===false,'invalid reply never signed/leaked');
    hcheck($invoke($input,null,static function(){throw new RuntimeException('PRIVATE database detail');})[2]==='{"error":"source_unavailable"}','redacted errors');
    $page=['schema'=>1,'shopId'=>'synthetic-shop','streamId'=>null,'cursor'=>'','limit'=>8];
    $catalog=static function($v):array{return ['schema'=>1,'shopId'=>$v['shopId'],'streamId'=>str_repeat('a',32),'cursor'=>'','nextCursor'=>str_repeat('b',64),'complete'=>true,'generatedAt'=>gmdate('Y-m-d\TH:i:s\Z'),'records'=>[]];};
    hcheck($invoke($page,null,$catalog)[0]===200,'existing source page unchanged');
} finally {foreach(glob($directory.'/*')as$file)if(is_file($file))unlink($file);rmdir($directory);}
echo "Presta customer history protocol: $checks checks passed.\n";

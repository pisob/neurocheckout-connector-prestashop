<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
use NeuroCheckout\Community\SourcePullProtocol;
use NeuroCheckout\Community\SourcePullGateway;
use NeuroCheckout\Community\PrestashopCustomerPriceProtocol as Protocol;
require_once __DIR__.'/../src/Community/SourcePullProtocol.php';
require_once __DIR__.'/../src/Community/SourcePullGateway.php';
require_once __DIR__.'/../src/Community/PrestashopCustomerPriceProtocol.php';
set_error_handler(static function($severity,$message){throw new RuntimeException($message);});
$checks=0;
function hcheck(bool $ok,string $label): void { global $checks;++$checks;if(!$ok)throw new RuntimeException($label); }
function hreject(callable $fn,string $label): void {
    try {$fn();} catch(Throwable $e) {hcheck($e->getMessage()==='customer_prices_unavailable',$label.' constant error');return;}
    throw new RuntimeException($label.' unexpectedly accepted');
}
$input=['schema'=>1,'shopId'=>'synthetic-shop','operation'=>'customer_prices','nativeShopId'=>'2',
    'customerId'=>'47','customerEmail'=>'reader@example.invalid','productIds'=>['1','2'],'currency'=>'EUR'];
$prices=['schema'=>'prestashop-customer-prices-v1','native_shop_id'=>2,'customer_id'=>47,
    'customer_email'=>'reader@example.invalid','currency'=>'EUR','quantity'=>1,'price_decimals'=>2,
    'context_hash'=>str_repeat('a',64),'read_at'=>gmdate('Y-m-d\TH:i:s\Z'), 'offers'=>[
        ['product_id'=>'1','attribute_id'=>'3','status'=>'available','currency'=>'EUR',
         'price_excl_tax'=>10.1234,'price_incl_tax'=>12.14808,'display_mode'=>'including_tax','display_price'=>12.15],
        ['product_id'=>'2','status'=>'unavailable']]];
Protocol::request($input,'synthetic-shop');Protocol::prices($prices,$input);hcheck(true,'native offer and unavailable accepted');
foreach (['schema'=>2,'shopId'=>'other','operation'=>'other','nativeShopId'=>'02','customerId'=>47,
    'customerEmail'=>"bad\n@example.invalid",'url'=>'https://other.invalid','currency'=>'eur',
    'productIds'=>['1','1']] as $k=>$v) {
    $bad=array_replace($input,[$k=>$v]);hreject(static function()use($bad){Protocol::request($bad,'synthetic-shop');},'request '.$k);
}
foreach ([[],[1],array_map('strval',range(1,17)),[1=>'1']] as $ids) {
    $bad=array_replace($input,['productIds'=>$ids]);hreject(static function()use($bad){Protocol::request($bad,'synthetic-shop');},'product list');
}
foreach (['schema'=>'woocommerce-customer-prices-v1','native_shop_id'=>3,'customer_id'=>'47',
    'customer_email'=>'foreign@example.invalid','currency'=>'USD','quantity'=>true,'price_decimals'=>7,
    'context_hash'=>'bad','read_at'=>'2020-02-30T00:00:00Z','offers'=>[],'address'=>'private'] as $k=>$v) {
    $bad=array_replace($prices,[$k=>$v]);hreject(static function()use($bad,$input){Protocol::prices($bad,$input);},'prices '.$k);
}
foreach (['product_id'=>'2','attribute_id'=>'01','status'=>'unknown','currency'=>'USD',
    'price_excl_tax'=>true,'price_incl_tax'=>NAN,'display_mode'=>'both','display_price'=>12.17,'extra'=>'private'] as $k=>$v) {
    $bad=$prices;$bad['offers'][0][$k]=$v;hreject(static function()use($bad,$input){Protocol::prices($bad,$input);},'offer '.$k);
}
foreach ([12.148,INF,-1,'12.15'] as $amount) {
    $bad=$prices;$bad['offers'][0]['display_price']=$amount;hreject(static function()use($bad,$input){Protocol::prices($bad,$input);},'non native amount');
}
foreach ([-60,60] as $offset) {
    $bad=$prices;$bad['read_at']=gmdate('Y-m-d\TH:i:s\Z',time()+$offset);hreject(static function()use($bad,$input){Protocol::prices($bad,$input);},'freshness');
}
$bad=$prices;$bad['offers']=array_reverse($bad['offers']);hreject(static function()use($bad,$input){Protocol::prices($bad,$input);},'order binding');
$bad=$prices;$bad['offers'][1]['display_price']=0;hreject(static function()use($bad,$input){Protocol::prices($bad,$input);},'no invented zero');
foreach ([12.14,12.15] as $rounded) {
    $good=$prices;$good['offers'][0]['display_price']=$rounded;Protocol::prices($good,$input);hcheck(true,'native directed rounding');
}
$directory=sys_get_temp_dir().'/nc-presta-price-protocol-'.bin2hex(random_bytes(8));mkdir($directory,0700);
$config=['enabled'=>true,'environment'=>'production','nativeScope'=>2,'platform'=>'prestashop','secret'=>bin2hex(random_bytes(32)),'shopId'=>'synthetic-shop'];
$path='/shop/module/neurocheckoutconnector/communitydata';$calls=0;
$sign=static function($raw,$path)use($config):array{
    $time=(string)(int)floor(microtime(true)*1000);$nonce=bin2hex(random_bytes(16));
    return ['content-type'=>'application/json','x-nc-source-time'=>$time,'x-nc-source-nonce'=>$nonce,
        'x-nc-source-signature'=>hash_hmac('sha256',implode("\n",['nc-source-pull-v1','POST',$path,$config['shopId'],$time,$nonce,hash('sha256',$raw)]),hex2bin($config['secret']))];
};
$export=static function($v)use(&$calls,$prices):array{++$calls;return ['schema'=>1,'shopId'=>$v['shopId'],'operation'=>'customer_prices','requestHash'=>hash('sha256',json_encode($v)),'prices'=>$prices];};
$invoke=static function($v,$headers=null,$callback=null,$https=true)use($config,$directory,$path,$sign,$export):array{
    $raw=json_encode($v);return SourcePullGateway::handle('prestashop',2,__DIR__,'POST',$path,$headers??$sign($raw,$path),$raw,$https,$callback??$export,$config,$directory);
};
try {
    $raw=json_encode($input);$headers=$sign($raw,$path);$r=$invoke($input,$headers);
    hcheck($r[0]===200&&$calls===1,'signed native price');
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
echo "Presta customer price protocol: $checks checks passed.\n";

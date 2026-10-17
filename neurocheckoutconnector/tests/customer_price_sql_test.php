<?php
declare(strict_types=1);
// Disposable native schemas + controlled native API doubles. Price formulas
// are compared separately against the real PS 1.7.8.11 runtime probe.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../src/Community/PrestashopCustomerProductPrices.php';
use NeuroCheckout\Community\PrestashopCustomerProductPrices;
$socket=$argv[1]??'';
if (!preg_match('#^/tmp/nc-source-mariadb\.[A-Za-z0-9]+/mariadb\.sock$#D',$socket)||!file_exists($socket)) { exit(2); }
set_error_handler(static function($severity,$message){throw new RuntimeException($message);});
$checks=0;
function pcheck(bool $ok,string $label):void{global $checks;++$checks;if(!$ok)throw new RuntimeException($label);}
function preject(callable $fn,string $label):void{
    try{$fn();}catch(Throwable $e){pcheck($e->getMessage()==='customer_prices_unavailable',$label.' constant error');return;}
    throw new RuntimeException($label.' unexpectedly accepted');
}
class PriceTestPDO extends PDO {
    public $onCustomerRead=null;
    #[\ReturnTypeWillChange]
    public function prepare($query,$options=[]){
        if($this->onCustomerRead!==null&&strpos($query,'SELECT email,id_default_group,newsletter FROM')===0){
            $callback=$this->onCustomerRead;$this->onCustomerRead=null;$callback($this);
        }
        return parent::prepare($query,$options);
    }
}
class Context {
    public $shop; public $customer; public $cart; public $currency;
    public static $value;
    public static function getContext(){return self::$value;}
    public function cloneContext(){return clone $this;}
}
class DbCore {public static $db;public static function getInstance($slave=false){return new static();}public function getLink(){return self::$db;}}
class ShopCore {const CONTEXT_SHOP=1;public $id=2;public static function getContext(){return 1;}public static function getContextShopID(){return 2;}}
class CustomerCore {public static function getDefaultGroupId($id){return 3;}}
class GroupCore {public static function getPriceDisplayMethod($group){return 0;}}
class AddressCore {public static function getFirstCustomerAddressId($id){return 71;}}
class CurrencyCore {public $id;public $iso_code='EUR';public $precision=2;public function __construct($id){$this->id=$id;}}
class CartCore {public $id=0;public $id_address_delivery=0;public $id_address_invoice=0;}
class ToolsCore {public static function ps_round($v,$precision){return round($v,$precision);}}
class StockAvailableCore {public static $quantity=3;public static function getQuantityAvailableByProduct($p,$a,$s){return self::$quantity;}}
class CombinationCore {public static function isFeatureActive(){return true;}}
class ConfigurationCore {
    public static $values=['PS_TAX'=>1,'PS_TAX_ADDRESS_TYPE'=>'id_address_delivery','PS_PRICE_ROUND_MODE'=>2,
        'PS_STOCK_MANAGEMENT'=>1,'PS_CATALOG_MODE'=>0];
    public static function get($key){return self::$values[$key]??0;}
}
class ProductCore {
    public static $default=3;public static $access=true;public static $failure=false;public static $calls=[];
    public static function priceCalculation(){static $context=null,$address=null;}
    public static function getDefaultAttribute($id){return self::$default;}
    public static function checkAccessStatic($id,$customer){return self::$access;}
    public static function getPriceStatic(...$args){
        self::$calls[]=$args;
        if(self::$failure)throw new RuntimeException('PRIVATE native failure');
        $db=DbCore::$db;
        // Read real fixture data through the SAME native connection/snapshot.
        return (float)$db->query('SELECT reduction FROM ps_group WHERE id_group=3')->fetchColumn();
    }
}
foreach(['Product','Customer','Address','Currency','Group','Tools','StockAvailable','Combination','Configuration','Cart','Shop','Db']as$name){eval('class '.$name.' extends '.$name.'Core {}');}
foreach(['GroupReduction','SpecificPrice','Tax','TaxManagerFactory','TaxRulesTaxManager','TaxCalculator','Country','State','DbPDO']as$name){eval('class '.$name.'Core {} class '.$name.' extends '.$name.'Core {}');}
define('_PS_VERSION_','1.7.8.11');define('_DB_PREFIX_','ps_');define('_PS_USE_SQL_SLAVE_',false);
$context=new Context();$context->shop=new Shop();$context->cart=new Cart();$context->customer=(object)['id'=>0];$context->currency=new Currency(1);Context::$value=$context;
$database='nc_presta_price_fixture_'.bin2hex(random_bytes(6));
$dsn='mysql:unix_socket='.$socket.';charset=utf8mb4';
$admin=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$admin->exec('CREATE DATABASE `'.$database.'`');
try {
    $writer=new PDO($dsn.';dbname='.$database,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db=new PriceTestPDO($dsn.';dbname='.$database,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);DbCore::$db=$db;
    $schemas=[
        'shop'=>'id_shop INT,id_shop_group INT,active INT,deleted INT',
        'shop_group'=>'id_shop_group INT,share_stock INT,active INT,deleted INT',
        'customer'=>'id_customer INT,id_shop INT,id_shop_group INT,email VARCHAR(254),id_default_group INT,newsletter INT,active INT,deleted INT,is_guest INT',
        'group'=>'id_group INT,price_display_method INT,show_prices INT,reduction DECIMAL(10,4)',
        'group_shop'=>'id_group INT,id_shop INT','customer_group'=>'id_group INT,id_customer INT',
        'currency'=>'id_currency INT,`precision` INT,iso_code VARCHAR(3),active INT,deleted INT',
        'currency_shop'=>'id_currency INT,id_shop INT,conversion_rate DECIMAL(10,4)',
        'address'=>'id_address INT,id_customer INT,id_country INT,id_state INT,postcode VARCHAR(32),vat_number VARCHAR(32),deleted INT,active INT',
        'country'=>'id_country INT,active INT','country_shop'=>'id_country INT,id_shop INT','state'=>'id_state INT,id_country INT,active INT',
        'product'=>'id_product INT,cache_is_pack INT',
        'product_shop'=>'id_product INT,id_shop INT,active INT,available_for_order INT,show_price INT,visibility VARCHAR(16),minimal_quantity INT,customizable INT,cache_default_attribute INT',
        'product_attribute'=>'id_product_attribute INT,id_product INT',
        'product_attribute_shop'=>'id_product_attribute INT,id_product INT,id_shop INT,default_on INT,minimal_quantity INT',
        'hook'=>'id_hook INT,name VARCHAR(128)','module'=>'id_module INT,active INT',
        'hook_module'=>'id_hook INT,id_module INT,id_shop INT','module_shop'=>'id_module INT,id_shop INT',
    ];
    $tables=(new ReflectionClass(PrestashopCustomerProductPrices::class))->getConstant('TABLES');
    foreach($tables as $name)$writer->exec('CREATE TABLE `ps_'.$name.'` ('.($schemas[$name]??'id INT').') ENGINE=InnoDB');
    foreach([
        "INSERT INTO ps_shop VALUES (2,1,1,0)","INSERT INTO ps_shop_group VALUES (1,0,1,0)",
        "INSERT INTO ps_customer VALUES (47,2,1,'reader@example.invalid',3,1,1,0,0)",
        "INSERT INTO ps_group VALUES (3,0,1,12.1480)","INSERT INTO ps_group_shop VALUES (3,2)","INSERT INTO ps_customer_group VALUES (3,47)",
        "INSERT INTO ps_currency VALUES (1,2,'EUR',1,0)","INSERT INTO ps_currency_shop VALUES (1,2,1)",
        "INSERT INTO ps_address VALUES (71,47,8,0,'00000','',0,1)","INSERT INTO ps_country VALUES (8,1)","INSERT INTO ps_country_shop VALUES (8,2)",
        "INSERT INTO ps_product VALUES (1,0)","INSERT INTO ps_product_shop VALUES (1,2,1,1,1,'both',1,0,3)",
        "INSERT INTO ps_product_attribute VALUES (3,1)","INSERT INTO ps_product_attribute_shop VALUES (3,1,2,1,1)",
        "INSERT INTO ps_module VALUES (1,1)","INSERT INTO ps_module_shop VALUES (1,2)","INSERT INTO ps_hook_module VALUES (1,1,2)",
    ]as$sql)$writer->exec($sql);
    $read=static function(){return(new PrestashopCustomerProductPrices())->read(2,47,'reader@example.invalid',['1'],'EUR');};
    $limits=$db->query('SELECT @@session.max_statement_time,@@session.lock_wait_timeout')->fetch(PDO::FETCH_NUM);
    $result=$read();pcheck($result['offers'][0]['display_price']===12.15&&$result['offers'][0]['attribute_id']==='3','native offer passed through');
    pcheck(count(ProductCore::$calls)===2,'native exclusive/inclusive calls');
    foreach(ProductCore::$calls as $args){
        pcheck($args[0]===1&&$args[2]===3&&$args[7]===1&&$args[9]===47&&$args[10]===0&&$args[11]===71&&$args[17]===0,'explicit product/combination/quantity/customer/cart/address');
        pcheck($args[15]!==$context&&$args[15]->cart!==$context->cart&&$args[15]->currency->iso_code==='EUR','separate empty native context');
    }
    foreach([
        ['customer','is_guest=1','is_guest=0'],['customer','active=0','active=1'],['customer','deleted=1','deleted=0'],
        ['customer','id_shop=3','id_shop=2'],['customer','id_shop_group=2','id_shop_group=1'],['customer',"email='foreign@example.invalid'","email='reader@example.invalid'"],
        ['shop','active=0','active=1'],['shop_group','deleted=1','deleted=0'],['group','show_prices=0','show_prices=1'],
        ['group','price_display_method=2','price_display_method=0'],['group_shop','id_shop=3','id_shop=2'],['customer_group','id_customer=48','id_customer=47'],
        ['currency','`precision`=7','`precision`=2'],['currency_shop','conversion_rate=0','conversion_rate=1'],
        ['address','id_customer=48','id_customer=47'],['address','deleted=1','deleted=0'],['address','id_state=99','id_state=0'],
        ['country','active=0','active=1'],['country_shop','id_shop=3','id_shop=2'],
    ]as[$table,$bad,$restore]){
        $writer->exec("UPDATE ps_$table SET $bad");preject($read,'invalid native '.$table);
        pcheck(!$db->inTransaction(),'failed snapshot released');$writer->exec("UPDATE ps_$table SET $restore");
    }
    foreach(['active=0','available_for_order=0','show_price=0',"visibility='search'",'minimal_quantity=2','customizable=1','cache_default_attribute=4']as$bad){
        $writer->exec('UPDATE ps_product_shop SET '.$bad);pcheck($read()['offers']=== [['product_id'=>'1','status'=>'unavailable']],'unsaleable product not raw price');
        $writer->exec("UPDATE ps_product_shop SET active=1,available_for_order=1,show_price=1,visibility='both',minimal_quantity=1,customizable=0,cache_default_attribute=3");
    }
    foreach(['default_on=0','minimal_quantity=2']as$bad){$writer->exec('UPDATE ps_product_attribute_shop SET '.$bad);pcheck($read()['offers'][0]['status']==='unavailable','unqualified combination');$writer->exec('UPDATE ps_product_attribute_shop SET default_on=1,minimal_quantity=1');}
    StockAvailableCore::$quantity=0;pcheck($read()['offers'][0]['status']==='unavailable','exact default stock');StockAvailableCore::$quantity=3;
    ProductCore::$access=false;pcheck($read()['offers'][0]['status']==='unavailable','native group access');ProductCore::$access=true;
    ProductCore::$failure=true;preject($read,'native errors redacted');ProductCore::$failure=false;
    foreach(['taxManager','actionProductPriceCalculation','actionObjectAddressLoadAfter']as$hook){
        $writer->exec("INSERT INTO ps_hook VALUES (1,'$hook')");preject($read,'native custom hook');$writer->exec('DELETE FROM ps_hook');
    }
    $db->beginTransaction();preject($read,'caller transaction');pcheck($db->inTransaction(),'caller transaction preserved');$db->rollBack();
    $db->onCustomerRead=static function($db)use($writer){
        try{$db->exec('UPDATE ps_customer SET newsletter=0');throw new RuntimeException('missing read only');}
        catch(PDOException $error){pcheck(true,'database enforced read only');}
        $writer->exec('UPDATE ps_group SET reduction=23.456');
    };
    pcheck($read()['offers'][0]['display_price']===12.15,'native connection retains complete snapshot');
    pcheck($read()['offers'][0]['display_price']===23.46,'next snapshot observes commit');
    $writer->exec('SET SESSION lock_wait_timeout=1');
    $db->onCustomerRead=static function($db)use($writer){
        try{$writer->exec('ALTER TABLE ps_specific_price ENGINE=MyISAM');throw new RuntimeException('missing metadata lock');}
        catch(PDOException $error){pcheck(true,'pricing tables protected against engine changes');}
    };$read();
    $writer->exec('ALTER TABLE ps_specific_price ENGINE=MyISAM');preject($read,'nontransactional pricing');$writer->exec('ALTER TABLE ps_specific_price ENGINE=InnoDB');
    $writer->exec('RENAME TABLE ps_tax_rule TO ps_tax_rule_missing');preject($read,'missing tax schema');$writer->exec('RENAME TABLE ps_tax_rule_missing TO ps_tax_rule');
    pcheck($db->query('SELECT @@session.max_statement_time,@@session.lock_wait_timeout')->fetch(PDO::FETCH_NUM)===$limits,'session timeouts restored');
    pcheck($context->cart->id===0&&$context->customer->id===0,'global anonymous context retained');
} finally {$admin->exec('DROP DATABASE `'.$database.'`');}
echo "Presta customer price SQL: $checks checks passed.\n";

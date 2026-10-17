<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
use NeuroCheckout\Community\PrestashopCustomerOrderHistory;
require_once __DIR__.'/../src/Community/PrestashopCustomerHistoryProtocol.php';
require_once __DIR__.'/../src/Community/PrestashopCustomerOrderHistory.php';
$socket=$argv[1]??'';
if (!preg_match('#^/tmp/nc-source-mariadb\.[A-Za-z0-9]+/mariadb\.sock$#D',$socket)||!file_exists($socket)) { exit(2); }
set_error_handler(static function($severity,$message){throw new RuntimeException($message);});
$checks=0;
function scheck(bool $ok,string $label):void{global $checks;++$checks;if(!$ok)throw new RuntimeException($label);}
function sreject(callable $fn,string $label):void{
    try{$fn();}catch(Throwable $e){scheck($e->getMessage()==='customer_history_unavailable',$label.' constant error');return;}
    throw new RuntimeException($label.' unexpectedly accepted');
}
class HistoryTestPDO extends PDO {
    public $onCustomerRead=null;
    #[\ReturnTypeWillChange]
    public function prepare($query,$options=[]){
        if($this->onCustomerRead!==null&&strpos($query,'SELECT email,date_add,newsletter FROM')===0){$callback=$this->onCustomerRead;$this->onCustomerRead=null;$callback($this);}
        return parent::prepare($query,$options);
    }
}
class Context {public static $scope=2;public static function getContext(){return (object)['shop'=>(object)['id'=>self::$scope]];}}
$database='nc_presta_history_fixture_'.bin2hex(random_bytes(6));
define('_DB_SERVER_','localhost:'.$socket);define('_DB_NAME_',$database);define('_DB_USER_','root');define('_DB_PASSWD_','');define('_DB_PREFIX_','ps_');
$dsn='mysql:unix_socket='.$socket.';charset=utf8mb4';
$admin=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$admin->exec('CREATE DATABASE `'.$database.'`');
try {
    $writer=new PDO($dsn.';dbname='.$database,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $readerDb=new HistoryTestPDO($dsn.';dbname='.$database,'root','');
    $schemas=[
        'shop'=>'id_shop INT PRIMARY KEY,id_shop_group INT,active INT,deleted INT',
        'customer'=>'id_customer INT PRIMARY KEY,id_shop INT,id_shop_group INT,email VARCHAR(254),date_add DATETIME,newsletter INT,active INT,deleted INT,is_guest INT',
        'currency'=>'id_currency INT PRIMARY KEY,iso_code VARCHAR(3)',
        'order_state'=>'id_order_state INT PRIMARY KEY,paid INT,logable INT',
        'orders'=>'id_order INT PRIMARY KEY,id_shop INT,id_shop_group INT,id_customer INT,reference VARCHAR(64),current_state INT,valid INT,date_add DATETIME,date_upd DATETIME,id_currency INT,total_paid_tax_incl DECIMAL(20,6),total_paid_real DECIMAL(20,6)',
        'order_detail'=>'id_order_detail INT PRIMARY KEY,id_order INT,id_shop INT,product_id INT,product_attribute_id INT,product_quantity INT,product_quantity_refunded INT,product_quantity_return INT,total_price_tax_incl DECIMAL(20,6),total_price_tax_excl DECIMAL(20,6)',
        'order_slip'=>'id_order_slip INT PRIMARY KEY,id_order INT,id_customer INT,date_add DATETIME,date_upd DATETIME,order_slip_type INT,total_products_tax_incl DECIMAL(20,6),total_shipping_tax_incl DECIMAL(20,6)',
        'order_slip_detail'=>'id_order_slip INT,id_order_detail INT,product_quantity INT,amount_tax_incl DECIMAL(20,6),amount_tax_excl DECIMAL(20,6)',
        'order_payment'=>'id_order_payment INT PRIMARY KEY,order_reference VARCHAR(64),date_add DATETIME,amount DECIMAL(20,6),id_currency INT,conversion_rate DECIMAL(13,6)',
        'order_invoice'=>'id_order_invoice INT PRIMARY KEY,id_order INT',
        'order_invoice_payment'=>'id_order_invoice INT,id_order_payment INT,id_order INT',
    ];
    foreach($schemas as $name=>$schema)$writer->exec('CREATE TABLE ps_'.$name.'('.$schema.') ENGINE=InnoDB');
    foreach([
        "INSERT INTO ps_shop VALUES (2,1,1,0),(3,2,1,0)",
        "INSERT INTO ps_customer VALUES (47,2,1,'reader@example.invalid','2019-12-01',1,1,0,0),(48,3,2,'foreign@example.invalid','2019-12-01',1,1,0,0),(49,2,1,'empty@example.invalid','2019-12-01',0,1,0,0)",
        "INSERT INTO ps_currency VALUES (1,'EUR'),(2,'USD')",
        "INSERT INTO ps_order_state VALUES (3,0,0),(4,1,1)",
        "INSERT INTO ps_orders VALUES (31,2,1,47,'SPLIT',3,0,'2020-01-01','2020-01-02',1,48.125,0),(32,2,1,47,'SPLIT',4,1,'2020-01-01','2020-01-02',1,24,12),(33,3,2,47,'OTHER',4,1,'2020-01-01','2020-01-02',1,999,999),(34,2,1,48,'OTHER2',4,1,'2020-01-01','2020-01-02',1,999,999)",
        "INSERT INTO ps_order_detail VALUES (41,31,2,10,11,2,1,0,48.125,40.1),(42,32,2,10,12,1,0,0,24,20),(43,33,3,99,0,1,0,0,999,999)",
        "INSERT INTO ps_order_slip VALUES (51,31,47,'2020-01-02','2020-01-02',1,24.0625,0)",
        "INSERT INTO ps_order_slip_detail VALUES (51,41,1,24.0625,20.05)",
        "INSERT INTO ps_order_payment VALUES (61,'SPLIT','2020-01-02',36,1,1),(62,'SPLIT','2020-01-02',10,2,1.25),(63,'OTHER','2020-01-02',999,1,1)",
        "INSERT INTO ps_order_invoice VALUES (71,31),(72,32)",
        "INSERT INTO ps_order_invoice_payment VALUES (71,61,31),(72,61,32)",
    ]as$sql)$writer->exec($sql);
    $reader=new PrestashopCustomerOrderHistory($readerDb,'ps_',2,'Europe/Paris');
    $read=static function()use($reader){return $reader->read(2,47,'reader@example.invalid');};
    $h=$read();
    scheck($h['order_count']===2&&array_column($h['orders'],'order_id')===['31','32'],'all own orders independent of absent cart tables');
    scheck($h['orders'][0]['total_paid_tax_incl']==='48.125000'&&$h['orders'][0]['total_paid_real']==='0.000000','native order total not conflated with paid');
    scheck($h['orders'][0]['state_paid']===false&&$h['orders'][1]['state_paid']===true,'native state flags separate from money');
    scheck(count($h['payments'])===2&&$h['payments'][0]['order_ids']===['31','32']&&$h['payments'][0]['invoice_order_ids']===['31','32'],'split payment once with exact order relations');
    scheck($h['payments'][1]['currency']==='USD'&&$h['payments'][1]['conversion_rate']==='1.250000'&&$h['payments'][1]['invoice_order_ids']===[],'unallocated different-currency payment retained');
    scheck($h['orders'][0]['credit_slips'][0]['items'][0]['amount_tax_incl']==='24.062500','native credit note separate');
    scheck($h['marketing_consent']==='granted'&&!$readerDb->inTransaction(),'snapshot cleanup and native newsletter');
    scheck($h['native_timezone']==='Europe/Paris'&&$h['orders'][0]['created_at']==='2020-01-01 00:00:00','local dates not fabricated UTC');
    scheck($reader->read(2,49,'empty@example.invalid')['orders']===[],'native complete empty account');
    scheck(PrestashopCustomerOrderHistory::fromRuntime(2)->read(2,47,'READER@example.invalid')['orders']===$h['orders'],'actual factory connection and case-insensitive account email');
    Context::$scope=3;sreject(static function(){PrestashopCustomerOrderHistory::fromRuntime(2);},'runtime scope mismatch');Context::$scope=2;
    foreach([[3,47,'reader@example.invalid'],[2,48,'reader@example.invalid'],[2,47,'foreign@example.invalid'],[2,0,'reader@example.invalid']]as$args)
        sreject(static function()use($reader,$args){$reader->read(...$args);},'foreign identity');
    foreach(['is_guest=1','active=0','deleted=1','id_shop=3','id_shop_group=2']as$change){
        $writer->exec('UPDATE ps_customer SET '.$change.' WHERE id_customer=47');sreject($read,'unavailable account '.$change);
        $writer->exec('UPDATE ps_customer SET is_guest=0,active=1,deleted=0,id_shop=2,id_shop_group=1 WHERE id_customer=47');
    }
    foreach(['active=0','deleted=1']as$change){$writer->exec('UPDATE ps_shop SET '.$change.' WHERE id_shop=2');sreject($read,'unavailable shop');$writer->exec('UPDATE ps_shop SET active=1,deleted=0 WHERE id_shop=2');}
    foreach([0=>'denied',9=>'unknown',1=>'granted']as$value=>$state){$writer->exec('UPDATE ps_customer SET newsletter='.$value.' WHERE id_customer=47');scheck($read()['marketing_consent']===$state,'native consent '.$state);}
    foreach([
        ['ps_orders','id_order=31','id_shop_group=2','id_shop_group=1'],
        ['ps_order_detail','id_order_detail=41','id_shop=3','id_shop=2'],
        ['ps_order_slip','id_order_slip=51','id_customer=48','id_customer=47'],
        ['ps_order_slip_detail','id_order_slip=51','id_order_detail=42','id_order_detail=41'],
        ['ps_order_invoice','id_order_invoice=71','id_order=34','id_order=31'],
        ['ps_order_invoice_payment','id_order_invoice=71','id_order=34','id_order=31'],
        ['ps_orders','id_order=33',"reference='SPLIT'","reference='OTHER'"],
        ['ps_orders','id_order=34',"reference='SPLIT'","reference='OTHER2'"],
        ['ps_currency','id_currency=1',"iso_code='xxx'","iso_code='EUR'"],
        ['ps_order_payment','id_order_payment=61','conversion_rate=0','conversion_rate=1'],
    ]as[$table,$where,$bad,$restore]){
        $writer->exec("UPDATE $table SET $bad WHERE $where");sreject($read,'native inconsistency '.$table);scheck(!$readerDb->inTransaction(),'exception rolls back owned snapshot');
        $writer->exec("UPDATE $table SET $restore WHERE $where");
    }
    $writer->exec('UPDATE ps_order_payment SET amount=-36 WHERE id_order_payment=61');scheck($read()['payments'][0]['amount']==='-36.000000','native negative payment kept');$writer->exec('UPDATE ps_order_payment SET amount=36 WHERE id_order_payment=61');
    $readerDb->beginTransaction();sreject($read,'nested transaction refused');scheck($readerDb->inTransaction(),'caller transaction not rolled back');$readerDb->rollBack();
    $readerDb->onCustomerRead=static function($db)use($writer){
        try{$db->exec('UPDATE ps_customer SET newsletter=0 WHERE id_customer=47');throw new RuntimeException('read only not enforced');}
        catch(PDOException $error){scheck(true,'database enforces read only');}
        $writer->exec('UPDATE ps_customer SET newsletter=0 WHERE id_customer=47');
    };
    scheck($read()['marketing_consent']==='granted','repeatable-read stays consistent during native withdrawal');
    scheck($read()['marketing_consent']==='denied','next read sees withdrawal');
    $writer->exec('SET SESSION lock_wait_timeout=1');
    $readerDb->onCustomerRead=static function($db)use($writer){
        try{$writer->exec('ALTER TABLE ps_order_slip ENGINE=MyISAM');throw new RuntimeException('metadata lock missing');}
        catch(PDOException $error){scheck(true,'native engine protected by metadata lock');}
    };
    $read();$writer->exec('ALTER TABLE ps_order_slip ENGINE=MyISAM');sreject($read,'nontransactional table rejected');$writer->exec('ALTER TABLE ps_order_slip ENGINE=InnoDB');
    $writer->exec('RENAME TABLE ps_order_slip TO ps_order_slip_missing');sreject($read,'missing native table refused');$writer->exec('RENAME TABLE ps_order_slip_missing TO ps_order_slip');
    $insert=$writer->prepare("INSERT INTO ps_orders SELECT ?,2,1,47,?,3,0,'2020-01-01','2020-01-02',1,1,0");
    for($i=100;$i<227;$i++)$insert->execute([$i,'EXTRA'.$i]);sreject($read,'order count never truncated');
    $writer->exec('DELETE FROM ps_orders WHERE id_order>=100');
    for($i=100;$i<227;$i++)$writer->exec("INSERT INTO ps_order_payment VALUES ($i,'SPLIT','2020-01-02',1,1,1)");sreject($read,'payments never truncated');
    $writer->exec('DELETE FROM ps_order_payment WHERE id_order_payment>=100');
    for($i=100;$i<612;$i++)$writer->exec("INSERT INTO ps_order_detail VALUES ($i,31,2,10,0,1,0,0,1,1)");sreject($read,'line count never truncated');
    $writer->exec('DELETE FROM ps_order_detail WHERE id_order_detail>=100');
    scheck($read()['order_count']===2,'reader recovers after capacity failures');
} finally { $admin->exec('DROP DATABASE `'.$database.'`'); }
echo "Presta customer history SQL: $checks checks passed.\n";

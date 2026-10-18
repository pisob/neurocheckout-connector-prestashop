<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { exit(1); }
require_once __DIR__.'/../src/Community/PrestashopCustomerOrderHistory.php';
use NeuroCheckout\Community\PrestashopCustomerOrderHistory;
$socket=$argv[1]??'';
if (!preg_match('#^/tmp/nc-source-mariadb\.[A-Za-z0-9]+/mariadb\.sock$#D',$socket)||!file_exists($socket)) { exit(2); }
$checks=0;
function checkCart(bool $ok):void { global $checks;++$checks;if(!$ok)throw new RuntimeException('cart assertion failed'); }
function refuseCart(callable $fn):void {
    try{$fn();}catch(Throwable $e){checkCart($e->getMessage()==='customer_history_unavailable');return;}
    throw new RuntimeException('unexpected cart acceptance');
}
class CartTestPDO extends PDO {
    public $onCart=null;
    #[\ReturnTypeWillChange]
    public function prepare($query,$options=[]) {
        if ($this->onCart!==null && strpos($query,'SELECT id_cart,id_shop_group FROM')===0) {
            $fn=$this->onCart;$this->onCart=null;$fn($this);
        }
        return parent::prepare($query,$options);
    }
}
$dsn='mysql:unix_socket='.$socket.';charset=utf8mb4';$database='nc_presta_carts_fixture_'.bin2hex(random_bytes(6));
$admin=new PDO($dsn,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$admin->exec('CREATE DATABASE `'.$database.'`');
try {
    $writer=new PDO($dsn.';dbname='.$database,'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    foreach ([
        'shop'=>'id_shop INT PRIMARY KEY,id_shop_group INT,active INT,deleted INT',
        'customer'=>'id_customer INT PRIMARY KEY,id_shop INT,id_shop_group INT,email VARCHAR(254),date_add DATETIME,newsletter INT,active INT,deleted INT,is_guest INT',
        'cart'=>'id_cart INT PRIMARY KEY,id_customer INT,id_shop INT,id_shop_group INT',
        'cart_product'=>'id INT AUTO_INCREMENT PRIMARY KEY,id_cart INT,id_shop INT,quantity INT',
        'orders'=>'id_order INT PRIMARY KEY,id_cart INT',
    ] as $name=>$schema) {$writer->exec('CREATE TABLE ps_'.$name.' ('.$schema.') ENGINE=InnoDB');}
    $writer->exec("INSERT INTO ps_shop VALUES(1,1,1,0),(2,1,1,0)");
    $writer->exec("INSERT INTO ps_customer VALUES(7,1,1,'private@example.invalid','2020-01-01',1,1,0,0),(8,1,1,'other@example.invalid','2020-01-01',0,1,0,0)");
    $db=new CartTestPDO($dsn.';dbname='.$database,'root','');$reader=new PrestashopCustomerOrderHistory($db,'ps_',1,'UTC');
    $read=static function()use($reader){return $reader->read(1,7,'private@example.invalid',true);};
    checkCart($read()['has_active_cart']===false && !$db->inTransaction());
    $writer->exec('INSERT INTO ps_cart VALUES(1,7,1,1),(2,8,1,1),(3,7,2,1),(4,7,1,1)');
    $writer->exec('INSERT INTO ps_cart_product(id_cart,id_shop,quantity) VALUES(2,1,1),(3,2,1)');
    checkCart($read()['has_active_cart']===false); // Other account/shop never borrowed.
    $writer->exec('INSERT INTO ps_cart_product(id_cart,id_shop,quantity) VALUES(1,1,1),(4,1,2)');
    checkCart($read()['has_active_cart']===true);
    $writer->exec('INSERT INTO ps_orders VALUES(1,1)');checkCart($read()['has_active_cart']===true);
    $writer->exec('INSERT INTO ps_orders VALUES(2,4)');checkCart($read()['has_active_cart']===false);
    $writer->exec('DELETE FROM ps_orders WHERE id_cart=4');
    $writer->exec('UPDATE ps_cart_product SET quantity=0 WHERE id_cart=4');checkCart($read()['has_active_cart']===false);
    foreach (['quantity=-1','quantity=NULL','id_shop=2'] as $change) {
        $writer->exec('UPDATE ps_cart_product SET '.$change.' WHERE id_cart=4');refuseCart($read);
        $writer->exec('UPDATE ps_cart_product SET quantity=0,id_shop=1 WHERE id_cart=4');
    }
    $writer->exec('UPDATE ps_cart SET id_shop_group=2 WHERE id_cart=4');refuseCart($read);
    $writer->exec('UPDATE ps_cart SET id_shop_group=1 WHERE id_cart=4');
    foreach (['active=0','deleted=1','is_guest=1',"email='foreign@example.invalid'"] as $change) {
        $writer->exec('UPDATE ps_customer SET '.$change.' WHERE id_customer=7');refuseCart($read);
        $writer->exec("UPDATE ps_customer SET active=1,deleted=0,is_guest=0,email='private@example.invalid' WHERE id_customer=7");
    }
    foreach ([0=>'denied',1=>'granted',2=>'unknown'] as $flag=>$state) {
        $writer->exec('UPDATE ps_customer SET newsletter='.$flag.' WHERE id_customer=7');checkCart($read()['marketing_consent']===$state);
    }
    refuseCart(static function()use($reader){$reader->read(2,7,'private@example.invalid',true);});
    $db->beginTransaction();refuseCart($read);checkCart($db->inTransaction());$db->rollBack();
    $db->onCart=static function($connection) {try{$connection->exec('UPDATE ps_customer SET newsletter=1');}catch(PDOException $e){checkCart(true);return;}throw new RuntimeException('not read-only');};
    $read();checkCart(!$db->inTransaction());
    $db->onCart=static function()use($writer){$writer->exec('UPDATE ps_cart_product SET quantity=3 WHERE id_cart=4');};
    checkCart($read()['has_active_cart']===false);checkCart($read()['has_active_cart']===true);
    $writer->exec('ALTER TABLE ps_cart_product ENGINE=MyISAM');refuseCart($read);checkCart(!$db->inTransaction());
    $writer->exec('ALTER TABLE ps_cart_product ENGINE=InnoDB');
    $insert=$writer->prepare('INSERT INTO ps_cart VALUES(?,7,1,1)');
    for($id=10;$id<138;$id++){$insert->execute([$id]);}
    refuseCart($read);
    $writer->exec('DELETE FROM ps_cart WHERE id_cart>=10');
    $insert=$writer->prepare('INSERT INTO ps_cart_product(id_cart,id_shop,quantity) VALUES(4,1,1)');
    for($i=0;$i<512;$i++){$insert->execute();}refuseCart($read);
    $writer->exec('DROP TABLE ps_cart_product');refuseCart($read);
    echo "PrestaShop active carts: $checks assertions passed\n";
} finally { $admin->exec('DROP DATABASE `'.$database.'`'); }

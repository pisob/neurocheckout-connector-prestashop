<?php
declare(strict_types=1);
namespace NeuroCheckout\Community;

use PDO;
use RuntimeException;

/** Complete bounded native facts, independent of surviving checkout carts. */
final class PrestashopCustomerOrderHistory
{
    private PDO $db;
    private string $prefix;
    private string $timezone;
    private int $scope;
    private float $started=0;
    private const TABLES=['shop','customer','orders','currency','order_state','order_detail',
        'order_slip','order_slip_detail','order_payment','order_invoice_payment','order_invoice'];

    public function __construct(PDO $db,string $prefix,int $scope,string $timezone)
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/D',$prefix) || $scope<1 || $db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql'
            || !in_array($timezone,\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC),true)) { self::fail(); }
        $this->db=$db; $this->prefix=$prefix; $this->scope=$scope; $this->timezone=$timezone;
        $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES,false);
    }

    public static function fromRuntime(int $scope): self
    {
        if (!class_exists('Context') || (int)\Context::getContext()->shop->id!==$scope) { self::fail(); }
        // Own connection: never commit or roll back a merchant transaction.
        $host=(string)_DB_SERVER_; $name=(string)_DB_NAME_;
        if (strpos($host,';')!==false || !preg_match('/^[A-Za-z0-9_$-]+$/D',$name)) { self::fail(); }
        if (preg_match('/^(.*):([0-9]+)$/D',$host,$parts)) { $target='host='.$parts[1].';port='.$parts[2]; }
        elseif (preg_match('#^.*:(/.*)$#D',$host,$parts)) { $target='unix_socket='.$parts[1]; }
        else { $target='host='.$host; }
        return new self(new PDO('mysql:'.$target.';dbname='.$name.';charset=utf8mb4',(string)_DB_USER_,(string)_DB_PASSWD_,
            [PDO::ATTR_TIMEOUT=>2,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false]),(string)_DB_PREFIX_,$scope,date_default_timezone_get());
    }

    public function read(int $scope,int $customer,string $email): array
    {
        $owned=false;
        try {
            if ($scope!==$this->scope || $customer<1 || strlen($email)>254 || !filter_var($email,FILTER_VALIDATE_EMAIL)
                || $this->db->inTransaction()) { self::fail(); }
            $this->started=microtime(true);
            $this->db->exec(stripos((string)$this->db->getAttribute(PDO::ATTR_SERVER_VERSION),'mariadb')!==false
                ? 'SET SESSION max_statement_time=1' : 'SET SESSION MAX_EXECUTION_TIME=1000');
            $this->db->exec('SET SESSION lock_wait_timeout=1');
            $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->db->exec('SET TRANSACTION READ ONLY');
            $this->db->beginTransaction(); $owned=true;
            $this->engines();
            $shops=$this->rows('SELECT id_shop_group FROM '.$this->table('shop').' WHERE id_shop=? AND active=1 AND deleted=0',[$scope],1);
            if (count($shops)!==1) { self::fail(); }
            $group=(int)$shops[0]['id_shop_group'];
            $people=$this->rows('SELECT email,date_add,newsletter FROM '.$this->table('customer')
                .' WHERE id_customer=? AND id_shop=? AND id_shop_group=? AND active=1 AND deleted=0 AND is_guest=0',[$customer,$scope,$group],1);
            if (count($people)!==1 || strcasecmp($people[0]['email'],$email)!==0) { self::fail(); }
            $person=$people[0];
            $orders=$this->rows('SELECT CAST(o.id_order AS CHAR) order_id,o.reference,CAST(o.current_state AS CHAR) current_state_id,'
                .'s.paid state_paid,s.logable state_logable,o.valid,o.date_add created_at,o.date_upd updated_at,c.iso_code currency,'
                .'CAST(o.total_paid_tax_incl AS CHAR) total_paid_tax_incl,CAST(o.total_paid_real AS CHAR) total_paid_real,o.id_shop_group FROM '
                .$this->table('orders').' o LEFT JOIN '.$this->table('currency').' c ON c.id_currency=o.id_currency LEFT JOIN '
                .$this->table('order_state').' s ON s.id_order_state=o.current_state WHERE o.id_customer=? AND o.id_shop=? ORDER BY o.id_order',[$customer,$scope],128);
            $ids=array_column($orders,'order_id'); $references=[];
            foreach ($orders as &$order) {
                if ((int)$order['id_shop_group']!==$group || !is_string($order['reference'])
                    || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$order['reference'])) { self::fail(); }
                $references[$order['reference']][]=$order['order_id'];
                foreach (['valid','state_paid','state_logable'] as $key) { $order[$key]=$this->flag($order[$key],$key!=='valid'); }
                unset($order['id_shop_group'],$order['reference']);
            }
            unset($order);
            $items=$this->items($ids,$scope); $slips=$this->slips($ids,$customer);
            foreach ($orders as &$order) { $order['items']=$items[$order['order_id']]??[]; $order['credit_slips']=$slips[$order['order_id']]??[]; }
            unset($order);
            $consent=(string)$person['newsletter'];
            $result=['schema'=>'prestashop-customer-history-v1','native_shop_id'=>$scope,'customer_id'=>$customer,
                'customer_email'=>$person['email'],'customer_created_at'=>$person['date_add'],'native_timezone'=>$this->timezone,
                'read_at'=>gmdate('Y-m-d\TH:i:s\Z'),'complete'=>true,'order_count'=>count($orders),'orders'=>$orders,
                'payments'=>$this->payments($references,$scope,$customer),
                'marketing_consent'=>$consent==='1'?'granted':($consent==='0'?'denied':'unknown')];
            PrestashopCustomerHistoryProtocol::history($result,['nativeShopId'=>(string)$scope,'customerId'=>(string)$customer,'customerEmail'=>$email]);
            $this->checkTime(); return $result;
        } catch (\Throwable $error) { self::fail(); }
        finally {
            if ($owned) {
                try { if ($this->db->inTransaction()) { $this->db->rollBack(); } }
                catch (\Throwable $error) { self::fail(); }
            }
        }
    }

    private function items(array $ids,int $scope): array
    {
        if (!$ids) { return []; }
        $rows=$this->rows('SELECT CAST(id_order AS CHAR) order_id,id_shop,CAST(id_order_detail AS CHAR) item_id,'
            .'CAST(product_id AS CHAR) product_id,CAST(product_attribute_id AS CHAR) attribute_id,CAST(product_quantity AS CHAR) quantity,'
            .'CAST(product_quantity_refunded AS CHAR) quantity_refunded,CAST(product_quantity_return AS CHAR) quantity_returned,'
            .'CAST(total_price_tax_incl AS CHAR) total_price_tax_incl,CAST(total_price_tax_excl AS CHAR) total_price_tax_excl FROM '
            .$this->table('order_detail').' WHERE id_order IN ('.$this->in($ids).') ORDER BY id_order,id_order_detail',$ids,512);
        $result=[];
        foreach ($rows as $row) { if ((int)$row['id_shop']!==$scope) { self::fail(); }
            $id=$row['order_id']; unset($row['order_id'],$row['id_shop']); $result[$id][]=$row; }
        return $result;
    }

    private function slips(array $ids,int $customer): array
    {
        if (!$ids) { return []; }
        $slips=$this->rows('SELECT CAST(id_order AS CHAR) order_id,id_customer,CAST(id_order_slip AS CHAR) slip_id,'
            .'date_add created_at,date_upd updated_at,order_slip_type type,CAST(total_products_tax_incl AS CHAR) total_products_tax_incl,'
            .'CAST(total_shipping_tax_incl AS CHAR) total_shipping_tax_incl FROM '.$this->table('order_slip')
            .' WHERE id_order IN ('.$this->in($ids).') ORDER BY id_order,id_order_slip',$ids,128);
        $slipIds=array_column($slips,'slip_id'); $items=[];
        if ($slipIds) {
            $rows=$this->rows('SELECT CAST(id_order_slip AS CHAR) slip_id,CAST(id_order_detail AS CHAR) item_id,'
                .'CAST(product_quantity AS CHAR) quantity,CAST(amount_tax_incl AS CHAR) amount_tax_incl,CAST(amount_tax_excl AS CHAR) amount_tax_excl FROM '
                .$this->table('order_slip_detail').' WHERE id_order_slip IN ('.$this->in($slipIds).') ORDER BY id_order_slip,id_order_detail',$slipIds,512);
            foreach ($rows as $row) { $id=$row['slip_id']; unset($row['slip_id']); $items[$id][]=$row; }
        }
        $result=[];
        foreach ($slips as $slip) {
            if ((int)$slip['id_customer']!==$customer) { self::fail(); }
            $id=$slip['order_id']; unset($slip['order_id'],$slip['id_customer']); $slip['type']=(int)$slip['type'];
            $slip['items']=$items[$slip['slip_id']]??[]; $result[$id][]=$slip;
        }
        return $result;
    }

    private function payments(array $references,int $scope,int $customer): array
    {
        if (!$references) { return []; }
        $refs=array_keys($references);
        // Native payments belong to references, which can span split orders.
        // Reject reference collisions outside this account/shop, not leak them.
        $owners=$this->rows('SELECT CAST(id_order AS CHAR) order_id,reference,id_shop,id_customer FROM '.$this->table('orders')
            .' WHERE reference IN ('.$this->in($refs).') ORDER BY id_order',$refs,128);
        $count=0;
        foreach ($owners as $owner) {
            if ((int)$owner['id_shop']!==$scope || (int)$owner['id_customer']!==$customer
                || !in_array($owner['order_id'],$references[$owner['reference']]??[],true)) { self::fail(); }
            ++$count;
        }
        if ($count!==array_sum(array_map('count',$references))) { self::fail(); }
        $payments=$this->rows('SELECT CAST(p.id_order_payment AS CHAR) payment_id,p.order_reference,p.date_add created_at,'
            .'CAST(p.amount AS CHAR) amount,c.iso_code currency,CAST(p.conversion_rate AS CHAR) conversion_rate FROM '
            .$this->table('order_payment').' p LEFT JOIN '.$this->table('currency').' c ON c.id_currency=p.id_currency'
            .' WHERE p.order_reference IN ('.$this->in($refs).') ORDER BY p.id_order_payment',$refs,128);
        $pids=array_column($payments,'payment_id'); $links=[];
        if ($pids) {
            $rows=$this->rows('SELECT CAST(ip.id_order_payment AS CHAR) payment_id,CAST(ip.id_order AS CHAR) order_id,'
                .'CAST(i.id_order AS CHAR) invoice_order_id FROM '.$this->table('order_invoice_payment').' ip LEFT JOIN '
                .$this->table('order_invoice').' i ON i.id_order_invoice=ip.id_order_invoice'
                .' WHERE ip.id_order_payment IN ('.$this->in($pids).') ORDER BY ip.id_order_payment,ip.id_order_invoice',$pids,512);
            foreach ($rows as $row) {
                if ($row['order_id']!==$row['invoice_order_id']) { self::fail(); }
                $links[$row['payment_id']][$row['order_id']]=$row['order_id'];
            }
        }
        foreach ($payments as &$payment) {
            $payment['order_ids']=$references[$payment['order_reference']]??[];
            $payment['invoice_order_ids']=array_values($links[$payment['payment_id']]??[]);
            unset($payment['order_reference']);
        }
        unset($payment); return $payments;
    }

    private function flag($v,bool $nullable): ?bool
    { if ($v===null && $nullable) { return null; } if (!in_array((string)$v,['0','1'],true)) { self::fail(); } return (string)$v==='1'; }
    private function engines(): void
    {
        $names=array_map(function($name){return $this->prefix.$name;},self::TABLES);
        foreach ($names as $name) { $this->rows('SELECT 1 FROM `'.$name.'` WHERE 1=0',[],0); }
        $rows=$this->rows('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('
            .$this->in($names).')',$names,count($names));
        if (count($rows)!==count($names)) { self::fail(); }
        foreach ($rows as $row) { if (strcasecmp((string)$row['ENGINE'],'InnoDB')!==0) { self::fail(); } }
    }
    private function rows(string $sql,array $parameters,int $limit): array
    { $this->checkTime(); $q=$this->db->prepare($sql.' LIMIT '.($limit+1)); $q->execute($parameters); $rows=$q->fetchAll();
        if (count($rows)>$limit) { self::fail(); } $this->checkTime(); return $rows; }
    private function table(string $name): string { return '`'.$this->prefix.$name.'`'; }
    private function in(array $values): string { return implode(',',array_fill(0,count($values),'?')); }
    private function checkTime(): void { if (microtime(true)-$this->started>3) { self::fail(); } }
    private static function fail(): void { throw new RuntimeException('customer_history_unavailable'); }
}

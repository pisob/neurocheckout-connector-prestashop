<?php
declare(strict_types=1);
namespace NeuroCheckout\Community;

use PDO;
use RuntimeException;

require_once __DIR__.'/PrestashopCustomerPriceProtocol.php';

/** Native recipient pricing, read-only, one unit, no cart voucher or shipping. */
final class PrestashopCustomerProductPrices
{
    private PDO $db;
    private float $started;
    private const TABLES = ['shop','shop_group','customer','customer_group','address','country','country_shop','state',
        'currency','currency_shop','group','group_shop','group_reduction','product_group_reduction_cache',
        'product','product_shop','product_attribute','product_attribute_shop','stock_available','category_product','category_group',
        'specific_price','specific_price_priority','tax','tax_rule','tax_rules_group','tax_rules_group_shop',
        'configuration','configuration_lang','module','module_shop','hook','hook_module'];

    public function read(int $scope, int $customerId, string $email, array $ids, string $currency): array
    {
        $owned=false; $saved=[];
        try {
            $this->started=microtime(true);
            $input=['schema'=>1,'shopId'=>'native-price-read','operation'=>'customer_prices','nativeShopId'=>(string)$scope,
                'customerId'=>(string)$customerId,'customerEmail'=>$email,'productIds'=>$ids,'currency'=>$currency];
            PrestashopCustomerPriceProtocol::request($input, 'native-price-read');
            $this->runtime($scope);
            $db=\Db::getInstance()->getLink();
            if (!$db instanceof PDO || $db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql' || $db->inTransaction()
                || \Db::getInstance(_PS_USE_SQL_SLAVE_)->getLink()!==$db) { self::fail(); }
            $this->db=$db;
            // The native calculator uses this connection. Own only a new READ
            // ONLY transaction; never commit/roll back an existing merchant one.
            $maria=stripos((string)$db->getAttribute(PDO::ATTR_SERVER_VERSION),'mariadb')!==false;
            $limit=$maria ? 'max_statement_time' : 'max_execution_time';
            $saved=$db->query('SELECT @@session.'.$limit.' statement_limit, @@session.lock_wait_timeout lock_timeout')->fetch(PDO::FETCH_ASSOC);
            $db->exec('SET SESSION '.$limit.'='.($maria ? '1' : '1000'));
            $db->exec('SET SESSION lock_wait_timeout=1');
            $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            if (!$db->beginTransaction()) { self::fail(); } $owned=true;
            $this->engines(); $this->hooks($scope);
            $context=$this->context($scope,$customerId,$email,$currency);
            $offers=[];
            foreach ($ids as $id) {
                $this->deadline();
                $attribute=$this->attribute($scope,$customerId,(int)$id);
                if ($attribute===null) { $offers[]=['product_id'=>$id,'status'=>'unavailable']; continue; }
                $specific=null;
                // Never mutate Context::getContext(), its customer or its cart.
                // An explicit empty cart prevents another cart's quantity tiers
                // or vouchers leaking into a standalone one-unit offer.
                $excl=\Product::getPriceStatic((int)$id,false,$attribute ?: false,6,null,false,true,1,false,
                    $customerId,0,$context['address_id'],$specific,true,true,$context['native'],true,0);
                $incl=\Product::getPriceStatic((int)$id,true,$attribute ?: false,6,null,false,true,1,false,
                    $customerId,0,$context['address_id'],$specific,true,true,$context['native'],true,0);
                $excl=self::money($excl); $incl=self::money($incl);
                $display=self::money(\Tools::ps_round($context['display']===0 ? $incl : $excl,$context['decimals']));
                $offers[]=['product_id'=>$id,'attribute_id'=>(string)$attribute,'status'=>'available','currency'=>$currency,
                    'price_excl_tax'=>$excl,'price_incl_tax'=>$incl,
                    'display_mode'=>$context['display']===0 ? 'including_tax' : 'excluding_tax','display_price'=>$display];
            }
            $this->deadline();
            $result=['schema'=>'prestashop-customer-prices-v1','native_shop_id'=>$scope,'customer_id'=>$customerId,
                'customer_email'=>$email,'currency'=>$currency,'quantity'=>1,'price_decimals'=>$context['decimals'],
                'context_hash'=>$context['fingerprint'],'read_at'=>gmdate('Y-m-d\TH:i:s\Z',(int)$this->started),'offers'=>$offers];
            PrestashopCustomerPriceProtocol::prices($result,$input);
            return $result;
        } catch (\Throwable $error) {
            self::fail(); // No native exception, SQL, email or address in response/logs.
        } finally {
            if ($owned && $this->db->inTransaction()) { $this->db->rollBack(); }
            if ($saved) {
                $this->db->exec('SET SESSION '.$limit.'='.(float)$saved['statement_limit']);
                $this->db->exec('SET SESSION lock_wait_timeout='.(int)$saved['lock_timeout']);
            }
        }
    }

    private function runtime(int $scope): void
    {
        // Qualify other native major/minor pricing implementations separately.
        // They must not silently receive a 1.7 formula or catalog base price.
        if (!defined('_PS_VERSION_') || !preg_match('/^1\.7\.8\.\d+$/D',_PS_VERSION_)
            || !defined('_DB_PREFIX_') || !preg_match('/^[A-Za-z0-9_]+$/D',_DB_PREFIX_)
            || (defined('_PS_CACHE_ENABLED_') && _PS_CACHE_ENABLED_)) { self::fail(); }
        $context=\Context::getContext();
        if ((int)$context->shop->id!==$scope || \Shop::getContext()!==\Shop::CONTEXT_SHOP
            || (int)\Shop::getContextShopID()!==$scope || (int)($context->customer->id ?? 0)!==0
            || (int)($context->cart->id ?? 0)!==0 || (int)($context->cart->id_address_delivery ?? 0)!==0
            || (int)($context->cart->id_address_invoice ?? 0)!==0) { self::fail(); }
        foreach (['Product','Customer','Address','Currency','Group','GroupReduction','SpecificPrice','Tools','Tax','TaxManagerFactory',
            'TaxRulesTaxManager','TaxCalculator','StockAvailable','Combination','Configuration','Cart','Shop','Country','State','Db','DbPDO'] as $name) {
            $type=new \ReflectionClass($name); $core=new \ReflectionClass($name.'Core');
            // Native autoloaded aliases are empty subclasses. Don't sidestep
            // merchant overrides that may require a browser/session/custom tax.
            if (!$type->isSubclassOf($core)) { self::fail(); }
            foreach ($type->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName()===$name) { self::fail(); }
            }
            foreach ($type->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName()===$name) { self::fail(); }
            }
        }
        // priceCalculation retains a static global Context/address. Refuse a
        // previously initialized calculator, including a second read in one PHP
        // request, instead of rebinding another customer's cached state.
        $statics=(new \ReflectionMethod('Product','priceCalculation'))->getStaticVariables();
        if (($statics['context'] ?? null)!==null || ($statics['address'] ?? null)!==null) { self::fail(); }
    }

    private function context(int $scope,int $customer,string $email,string $currency): array
    {
        $shop=$this->rows('SELECT s.id_shop_group,g.share_stock FROM '.$this->table('shop').' s JOIN '.$this->table('shop_group')
            .' g ON g.id_shop_group=s.id_shop_group WHERE s.id_shop=? AND s.active=1 AND s.deleted=0 AND g.active=1 AND g.deleted=0',[$scope],1);
        $people=$this->rows('SELECT email,id_default_group,newsletter FROM '.$this->table('customer')
            .' WHERE id_customer=? AND id_shop=? AND id_shop_group=? AND active=1 AND deleted=0 AND is_guest=0',[$customer,$scope,$shop[0]['id_shop_group'] ?? 0],1);
        if (count($shop)!==1 || count($people)!==1 || strcasecmp($people[0]['email'],$email)!==0) { self::fail(); }
        $group=(int)$people[0]['id_default_group'];
        $groups=$this->rows('SELECT g.price_display_method,g.show_prices,g.reduction FROM '.$this->table('group').' g JOIN '
            .$this->table('group_shop').' gs ON gs.id_group=g.id_group JOIN '.$this->table('customer_group').' cg ON cg.id_group=g.id_group'
            .' WHERE g.id_group=? AND gs.id_shop=? AND cg.id_customer=?',[$group,$scope,$customer],1);
        if (count($groups)!==1 || (int)$groups[0]['show_prices']!==1 || !in_array((string)$groups[0]['price_display_method'],['0','1'],true)
            || (int)\Customer::getDefaultGroupId($customer)!==$group
            || (int)\Group::getPriceDisplayMethod($group)!==(int)$groups[0]['price_display_method']) { self::fail(); }
        $currencies=$this->rows('SELECT c.id_currency,c.precision,cs.conversion_rate FROM '.$this->table('currency').' c JOIN '
            .$this->table('currency_shop').' cs ON cs.id_currency=c.id_currency WHERE cs.id_shop=? AND c.iso_code=? AND c.active=1 AND c.deleted=0',[$scope,$currency],1);
        if (count($currencies)!==1 || !preg_match('/^[0-6]$/D',(string)$currencies[0]['precision'])
            || (float)$currencies[0]['conversion_rate']<=0) { self::fail(); }
        // Match the native FrontController's address selection for a new cart.
        // This is a standalone offer, not a quote for an existing browser cart.
        $addressId=(int)\Address::getFirstCustomerAddressId($customer);
        $addresses=$this->rows('SELECT a.id_address,a.id_country,a.id_state,a.postcode,a.vat_number FROM '.$this->table('address').' a'
            .' JOIN '.$this->table('country').' c ON c.id_country=a.id_country JOIN '.$this->table('country_shop').' cs ON cs.id_country=c.id_country'
            .' WHERE a.id_customer=? AND a.id_address=? AND a.deleted=0 AND a.active=1 AND c.active=1 AND cs.id_shop=?',[$customer,$addressId,$scope],1);
        if (!$addresses) { self::fail(); }
        $taxAddress=null;
        foreach ($addresses as $address) {
            $tuple=array_intersect_key($address,array_flip(['id_country','id_state','postcode','vat_number']));
            if ($taxAddress!==null && $tuple!==$taxAddress) { self::fail(); }
            $taxAddress=$tuple;
            if ((int)$address['id_state']>0 && !$this->rows('SELECT id_state FROM '.$this->table('state')
                .' WHERE id_state=? AND id_country=? AND active=1',[(int)$address['id_state'],(int)$address['id_country']],1)) { self::fail(); }
        }
        $settings=[];
        foreach (['PS_TAX','PS_TAX_ADDRESS_TYPE','VATNUMBER_MANAGEMENT','VATNUMBER_COUNTRY','PS_CURRENCY_DEFAULT',
            'PS_PRICE_ROUND_MODE','PS_ECOTAX_TAX_RULES_GROUP_ID','PS_USE_ECOTAX','PS_GROUP_FEATURE_ACTIVE',
            'PS_COMBINATION_FEATURE_ACTIVE','PS_SPECIFIC_PRICE_FEATURE_ACTIVE','PS_QTY_DISCOUNT_ON_COMBINATION',
            'PS_STOCK_MANAGEMENT','PS_ORDER_OUT_OF_STOCK','PS_CATALOG_MODE'] as $key) { $settings[$key]=\Configuration::get($key); }
        if ((bool)$settings['PS_CATALOG_MODE'] || !in_array((int)$settings['PS_PRICE_ROUND_MODE'],[0,1,2,3,4,5],true)
            || !in_array($settings['PS_TAX_ADDRESS_TYPE'],['id_address_delivery','id_address_invoice'],true)
            || !(bool)$settings['PS_STOCK_MANAGEMENT']) { self::fail(); }
        $native=\Context::getContext()->cloneContext();
        $native->cart=new \Cart();
        $native->currency=new \Currency((int)$currencies[0]['id_currency']);
        if ((int)$native->currency->id!==(int)$currencies[0]['id_currency'] || $native->currency->iso_code!==$currency
            || (int)$native->currency->precision!==(int)$currencies[0]['precision']) { self::fail(); }
        return ['native'=>$native,'address_id'=>(int)$addresses[0]['id_address'],
            'display'=>(int)$groups[0]['price_display_method'],'decimals'=>(int)$currencies[0]['precision'],
            'fingerprint'=>hash('sha256',json_encode([$scope,$customer,strtolower($email),$people,$groups,$shop,$currencies,$taxAddress,$settings],JSON_THROW_ON_ERROR))];
    }

    private function attribute(int $scope,int $customer,int $id): ?int
    {
        $rows=$this->rows('SELECT ps.active,ps.available_for_order,ps.show_price,ps.visibility,ps.minimal_quantity,ps.customizable,
            ps.cache_default_attribute,p.cache_is_pack FROM '.$this->table('product_shop').' ps JOIN '.$this->table('product')
            .' p ON p.id_product=ps.id_product WHERE ps.id_product=? AND ps.id_shop=?',[$id,$scope],1);
        if (!$rows) { return null; } $p=$rows[0];
        if ((int)$p['active']!==1 || (int)$p['available_for_order']!==1 || (int)$p['show_price']!==1
            || !in_array($p['visibility'],['both','catalog'],true) || (int)$p['minimal_quantity']>1
            || (int)$p['customizable']!==0 || (int)$p['cache_is_pack']!==0 || !\Product::checkAccessStatic($id,$customer)) { return null; }
        $variants=$this->rows('SELECT pas.id_product_attribute,pas.default_on,pas.minimal_quantity FROM '.$this->table('product_attribute_shop')
            .' pas JOIN '.$this->table('product_attribute').' pa ON pa.id_product_attribute=pas.id_product_attribute'
            .' WHERE pa.id_product=? AND pas.id_product=? AND pas.id_shop=? ORDER BY pas.id_product_attribute',[$id,$id,$scope],128);
        $attribute=0;
        if ($variants) {
            $defaults=array_values(array_filter($variants,static function(array $v): bool { return (int)$v['default_on']===1; }));
            if (count($defaults)!==1 || (int)$defaults[0]['minimal_quantity']>1 || !\Combination::isFeatureActive()) { return null; }
            $attribute=(int)$defaults[0]['id_product_attribute'];
            if ($attribute!==(int)$p['cache_default_attribute'] || $attribute!==(int)\Product::getDefaultAttribute($id)) { return null; }
        } elseif ((int)$p['cache_default_attribute']!==0) { return null; }
        if ((int)\StockAvailable::getQuantityAvailableByProduct($id,$attribute,$scope)<1) { return null; }
        return $attribute;
    }

    private function hooks(int $scope): void
    {
        $hooks=$this->rows('SELECT DISTINCT LOWER(h.name) name FROM '.$this->table('hook').' h JOIN '.$this->table('hook_module')
            .' hm ON hm.id_hook=h.id_hook JOIN '.$this->table('module').' m ON m.id_module=hm.id_module JOIN '
            .$this->table('module_shop').' ms ON ms.id_module=m.id_module AND ms.id_shop=hm.id_shop'
            .' WHERE m.active=1 AND hm.id_shop=? AND (LOWER(h.name) IN (\'taxmanager\',\'actionproductpricecalculation\')'
            .' OR LOWER(h.name) REGEXP \'^actionobject.*load(before|after)$\')',[$scope],128);
        if ($hooks) { self::fail(); }
    }

    private function engines(): void
    {
        foreach (self::TABLES as $table) { $this->rows('SELECT 1 FROM '.$this->table($table).' LIMIT 0',[],1); }
        $names=array_map(static function(string $v): string { return _DB_PREFIX_.$v; },self::TABLES);
        $engines=$this->rows('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('
            .implode(',',array_fill(0,count($names),'?')).')',$names,count($names));
        if (count($engines)!==count($names)) { self::fail(); }
        foreach ($engines as $engine) { if (strcasecmp((string)$engine['ENGINE'],'InnoDB')!==0) { self::fail(); } }
    }
    private function rows(string $sql,array $params,int $cap): array
    {
        $this->deadline(); $query=$this->db->prepare($sql); if (!$query || !$query->execute($params)) { self::fail(); }
        $rows=[];
        while ($row=$query->fetch(PDO::FETCH_ASSOC)) { if (count($rows)>=$cap) { self::fail(); } $rows[]=$row; }
        $query->closeCursor(); $this->deadline(); return $rows;
    }
    private function table(string $v): string { return '`'._DB_PREFIX_.$v.'`'; }
    private function deadline(): void { if (microtime(true)-$this->started>3) { self::fail(); } }
    private static function money($v): float
    { if ((!is_int($v) && !is_float($v)) || !is_finite((float)$v) || $v<0 || $v>1e9) { self::fail(); } return (float)$v; }
    private static function fail(): void { throw new RuntimeException('customer_prices_unavailable'); }
}

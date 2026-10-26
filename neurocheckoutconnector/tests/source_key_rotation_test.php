<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = sys_get_temp_dir().'/nc-presta-key-rotation-'.bin2hex(random_bytes(8));
mkdir($root,0700);
define('_PS_ROOT_DIR_', $root);
define('_PS_CACHE_DIR_', $root.'/cache');
define('_COOKIE_KEY_', bin2hex(random_bytes(32)));
define('_DB_NAME_', 'fixture');
class Shop {
    const CONTEXT_SHOP=1, CONTEXT_GROUP=2, CONTEXT_ALL=4;
    public static int $context=1, $id=1, $group=1;
    public static array $groups=[1=>1,2=>1,3=>2];
    public int $id_shop_group;
    public function __construct($id) { $this->id_shop_group=self::$groups[$id]; }
    public static function getContext() { return self::$context; }
    public static function getContextShopID() { return self::$id; }
    public static function getContextShopGroupID() { return self::$group; }
    public static function getShops($active=true,$group=null,$ids=false) {
        if ($active!==false || !$ids) throw new RuntimeException('Inactive shops must be preserved too');
        return array_keys(array_filter(self::$groups,static function($g) use($group){return $group===null||$g===$group;}));
    }
}
class Configuration {
    public static array $values=[];
    public static bool $writable=true;
    public static function get($key,$lang=null,$group=null,$id=null) {
        if ($group===null && $id===null) {
            $id=Shop::$context===Shop::CONTEXT_SHOP?Shop::$id:0;
            $group=Shop::$context===Shop::CONTEXT_ALL?0:Shop::$group;
        }
        return self::$values['shop:'.$id][$key]??self::$values['group:'.$group][$key]??self::$values['all'][$key]??'';
    }
    public static function updateValue($key,$value) {
        if (!self::$writable) return false;
        $scope=Shop::$context===Shop::CONTEXT_SHOP?'shop:'.Shop::$id:(Shop::$context===Shop::CONTEXT_GROUP?'group:'.Shop::$group:'all');
        self::$values[$scope][$key]=$value; return true;
    }
}
class PrestaShopLogger { public static function addLog($message,$severity) {} }
foreach (['Community/SourceStorageKey','Community/ReconciledSourceExporter','Community/PrestashopSourceDirectory','Community/AutomaticSourceBinding','Security/SecretConfiguration'] as $file) require __DIR__.'/../src/'.$file.'.php';
use NeuroCheckout\Community\AutomaticSourceBinding;
use NeuroCheckout\Community\PrestashopSourceDirectory;
use NeuroCheckout\Community\ReconciledSourceExporter;
use NeuroCheckout\Security\SecretConfiguration;
$checks=0;
function expectKey(bool $condition,string $message): void {
    global $checks; $checks++;
    if (!$condition) throw new RuntimeException($message);
}
function configFor(int $id,string $key,string $shop): array {
    return ['environment'=>'production','platform'=>'prestashop','nativeScope'=>$id,'shopId'=>$shop,'secret'=>AutomaticSourceBinding::secret($key,$shop)];
}
function keyFile(string $directory,array $config): string {
    return $directory.'/storage-'.hash('sha256',json_encode(['source-storage-v1','prestashop','production',$config['nativeScope'],$config['shopId']])).'.key';
}
$initial=bin2hex(random_bytes(32)); $next=bin2hex(random_bytes(32));
Configuration::$values['all']=['NC_API_KEY'=>$initial,'NC_API_ENDPOINT'=>'https://www.neurocheckout.com'];
foreach ([1,2,3] as $id) Configuration::$values['shop:'.$id]['NC_SHOP_EXTERNAL_ID']='fixture-'.$id;
$reader=static function(){return [['kind'=>'cart','sourceId'=>'cart-1','payload'=>['status'=>'active']]];};
$input=static function($shop){return ['schema'=>1,'shopId'=>$shop,'streamId'=>null,'cursor'=>'','limit'=>8];};
try {
    $directory=PrestashopSourceDirectory::resolve($root,_PS_CACHE_DIR_.'/neurocheckout-community-source');
    $configs=[]; $pages=[];
    foreach ([1,2,3] as $id) {
        $configs[$id]=configFor($id,$initial,'fixture-'.$id);
        $pages[$id]=(new ReconciledSourceExporter($directory,$configs[$id],$reader))->page($input('fixture-'.$id));
    }
    // Automatic two-phase rotation: prepare does not change the active key.
    expectKey(SecretConfiguration::set('NC_API_KEY_NEXT',$next),'prepare accepted');
    expectKey(SecretConfiguration::get('NC_API_KEY')===$initial,'prepare/lazy encryption keeps active key');
    expectKey(!glob($directory.'/storage-*.key'),'prepare does not replace source storage');
    expectKey(SecretConfiguration::set('NC_API_KEY',SecretConfiguration::get('NC_API_KEY_NEXT')),'finalize accepted');
    expectKey(SecretConfiguration::get('NC_API_KEY')===$next,'active key changed');
    expectKey(count(glob($directory.'/storage-*.key'))===1,'shop rotation preserves only its scope');
    expectKey(SecretConfiguration::sourceStorageSecret(configFor(1,$next,'fixture-1'),$directory)===$configs[1]['secret'],'legacy source key survives');
    expectKey(!file_exists(keyFile($directory,$configs[2])),'other shop untouched');
    $storage=configFor(1,$next,'fixture-1'); $storage['secret']=SecretConfiguration::sourceStorageSecret($storage,$directory);
    $continuation=$input('fixture-1'); $continuation['streamId']=$pages[1]['streamId']; $continuation['cursor']=$pages[1]['nextCursor'];
    $page=(new ReconciledSourceExporter($directory,$storage,$reader))->page($continuation);
    expectKey($page['streamId']===$pages[1]['streamId'] && $page['records']===[],'finalize preserves continuity');
    // A group change must also preserve a disabled child shop, but not another group.
    Shop::$context=Shop::CONTEXT_GROUP;
    expectKey(SecretConfiguration::set('NC_API_KEY',bin2hex(random_bytes(32))),'group manual save accepted');
    expectKey(count(glob($directory.'/storage-*.key'))===2,'group includes both children');
    expectKey(!file_exists(keyFile($directory,$configs[3])),'other group untouched');
    Shop::$context=Shop::CONTEXT_ALL;
    expectKey(SecretConfiguration::set('NC_API_KEY',bin2hex(random_bytes(32))),'global manual save accepted');
    expectKey(count(glob($directory.'/storage-*.key'))===3,'global includes all native shops');
    expectKey(SecretConfiguration::sourceStorageSecret($configs[3],$directory)===$configs[3]['secret'],'global preserves original snapshot key');
    Shop::$context=Shop::CONTEXT_SHOP;
    $old=SecretConfiguration::get('NC_API_KEY');
    $stored=Configuration::get('NC_API_KEY');
    expectKey(SecretConfiguration::set('NC_API_KEY',$stored),'already encrypted key accepted without rekeying');
    // Failed configuration write is retryable; the preserved source remains valid.
    Configuration::$writable=false;
    expectKey(!SecretConfiguration::set('NC_API_KEY',bin2hex(random_bytes(32))),'database failure reported');
    expectKey(SecretConfiguration::get('NC_API_KEY')===$old,'database failure retains active key');
    Configuration::$writable=true;
    $file=keyFile($directory,$configs[1]);
    $saved=file_get_contents($file); file_put_contents($file,'broken');
    expectKey(!SecretConfiguration::set('NC_API_KEY',bin2hex(random_bytes(32))),'corrupt source key blocks rotation');
    expectKey(SecretConfiguration::get('NC_API_KEY')===$old,'corrupt key never overwrites API key');
    file_put_contents($file,$saved);
    expectKey(SecretConfiguration::set('NC_API_KEY',''),'clearing credentials preserves source key');
    expectKey(SecretConfiguration::set('NC_API_KEY',bin2hex(random_bytes(32))),'reconnect accepted');
    expectKey(SecretConfiguration::sourceStorageSecret(configFor(1,SecretConfiguration::get('NC_API_KEY'),'fixture-1'),$directory)===$configs[1]['secret'],'reconnect resumes source');
    echo $checks." PrestaShop configuration rotation assertions passed.\n";
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());
    }
    rmdir($root);
}

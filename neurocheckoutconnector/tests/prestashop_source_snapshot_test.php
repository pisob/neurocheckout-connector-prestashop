<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../src/Community/PrestashopSourceSnapshot.php';
require_once __DIR__ . '/../src/Community/ReconciledSourceExporter.php';
require_once __DIR__ . '/../src/Community/SourcePullGateway.php';
require_once __DIR__ . '/../src/Community/SourcePullProtocol.php';
use NeuroCheckout\Community\PrestashopSourceSnapshot;
use NeuroCheckout\Community\ReconciledSourceExporter;
use NeuroCheckout\Community\SourcePullGateway;
use NeuroCheckout\Community\SourcePullProtocol;

// Only a disposable MariaDB Unix socket. Never load PrestaShop credentials.
$socket = $argv[1] ?? '';
if (!preg_match('#^/tmp/nc-source-mariadb\.[A-Za-z0-9]+/mariadb\.sock$#D', $socket) || !file_exists($socket)) {
    fwrite(STDERR, "Pass the disposable test socket.\n"); exit(2);
}
$database = 'nc_source_fixture_' . bin2hex(random_bytes(6));
$dsn = 'mysql:unix_socket=' . $socket . ';charset=utf8mb4';
$admin = new PDO($dsn, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec('CREATE DATABASE `' . $database . '`');
$checks = 0;
function expectSnapshot(bool $value, string $label): void {
    global $checks; $checks++;
    if (!$value) { throw new RuntimeException($label); }
}
class SnapshotTestPDO extends PDO {
    public $beforeCartRead = null;
    public $startedWithPdo = 0;
    public function beginTransaction(): bool {
        $this->startedWithPdo++;
        return parent::beginTransaction();
    }
    #[\ReturnTypeWillChange]
    public function prepare($query, $options = []) {
        if ($this->beforeCartRead !== null && strpos($query, 'FROM `ps_cart` c') !== false) {
            $callback = $this->beforeCartRead; $this->beforeCartRead = null; $callback($this);
        }
        return parent::prepare($query, $options);
    }
}
$directory = sys_get_temp_dir() . '/nc-native-export-test-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$oldConfiguration = getenv('NC_COMMUNITY_SOURCE_CONFIG');
try {
    $writer = new PDO($dsn . ';dbname=' . $database, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    foreach ([
        'shop' => 'id_shop INT PRIMARY KEY, id_shop_group INT, active INT',
        'product' => 'id_product INT PRIMARY KEY, reference VARCHAR(64)',
        'product_shop' => 'id_product INT, id_shop INT, active INT, price DECIMAL(20,6), id_tax_rules_group INT, id_category_default INT, PRIMARY KEY(id_product,id_shop)',
        'product_lang' => 'id_product INT, id_shop INT, id_lang INT, name VARCHAR(100), link_rewrite VARCHAR(100), description_short TEXT',
        'image' => 'id_image INT PRIMARY KEY, id_product INT, position INT',
        'image_shop' => 'id_image INT, id_shop INT, cover INT',
        'product_attribute' => 'id_product_attribute INT PRIMARY KEY, id_product INT, reference VARCHAR(64)',
        'product_attribute_shop' => 'id_product_attribute INT, id_shop INT, price DECIMAL(20,6), default_on INT',
        'product_attribute_image' => 'id_product_attribute INT, id_image INT, PRIMARY KEY(id_product_attribute,id_image)',
        'stock_available' => 'id_product INT, id_product_attribute INT, id_shop INT, id_shop_group INT, quantity INT, out_of_stock INT',
        'category_product' => 'id_product INT, id_category INT',
        'cart' => 'id_cart INT PRIMARY KEY, id_shop INT, id_customer INT, id_lang INT, id_currency INT, date_add DATETIME, date_upd DATETIME',
        'cart_product' => 'id_cart INT, id_shop INT, id_product INT, id_product_attribute INT, id_customization INT, quantity INT, id_address_delivery INT',
        'customer' => 'id_customer INT PRIMARY KEY, email VARCHAR(100), firstname VARCHAR(50), lastname VARCHAR(50), is_guest INT, newsletter INT DEFAULT 0, active INT DEFAULT 1, deleted INT DEFAULT 0, id_shop INT DEFAULT 1',
        'currency' => 'id_currency INT PRIMARY KEY, iso_code CHAR(3)',
        'orders' => 'id_order INT PRIMARY KEY, id_shop INT, id_cart INT, reference VARCHAR(64), current_state INT, total_paid_tax_incl DECIMAL(20,6), date_add DATETIME',
    ] as $table => $schema) { $writer->exec('CREATE TABLE `ps_' . $table . '` (' . $schema . ') ENGINE=InnoDB'); }
    foreach ([
        "INSERT INTO ps_shop VALUES (1,1,1),(2,2,1)",
        "INSERT INTO ps_product VALUES (10,'SHARED'),(20,'OTHER')",
        "INSERT INTO ps_image VALUES (70,10,1),(71,10,2),(72,10,3),(73,10,3),(74,20,0)",
        "INSERT INTO ps_image_shop VALUES (71,1,1),(70,2,1),(72,1,0),(73,1,0),(74,1,0)",
        "INSERT INTO ps_product_shop VALUES (10,1,1,39.9,1,3),(10,2,1,999,1,4),(20,2,1,100,1,4)",
        "INSERT INTO ps_product_lang VALUES (10,1,1,'Produit été','produit-ete','Description'),(10,2,1,'Other store','other','Other description')",
        "INSERT INTO ps_product_attribute VALUES (100,10,'VARIANT')",
        "INSERT INTO ps_product_attribute_shop VALUES (100,1,5,1),(100,2,777,1)",
        "INSERT INTO ps_product_attribute_image VALUES (100,70),(100,72),(100,73),(100,74)",
        "INSERT INTO ps_stock_available VALUES (10,100,1,0,7,0),(10,100,0,1,8,0),(10,100,2,0,999,0),(10,100,0,2,999,0)",
        "INSERT INTO ps_category_product VALUES (10,3)",
        "INSERT INTO ps_customer(id_customer,email,firstname,lastname,is_guest) VALUES (1,'fixture@example.invalid','Test','Only',0),(2,'other@example.invalid','Other','Store',1)",
        "INSERT INTO ps_currency VALUES (1,'USD')",
        "INSERT INTO ps_cart VALUES (1,1,1,1,1,NOW(),NOW()),(2,2,2,1,1,NOW(),NOW())",
        "INSERT INTO ps_cart_product VALUES (1,1,10,100,0,1,0),(2,2,20,0,0,1,0)",
    ] as $sql) { $writer->exec($sql); }
    $readerPDO = new SnapshotTestPDO($dsn . ';dbname=' . $database, 'root', '');
    $reader = new PrestashopSourceSnapshot($readerPDO, 'ps_');
    $snapshot = $reader->capture(1);
    expectSnapshot(count($snapshot) === 2, 'only selected shop products/carts');
    expectSnapshot($snapshot[0]['payload']['cover_image_id'] === 71, 'retain released shop-specific native image identity');
    expectSnapshot($snapshot[0]['payload']['translations'][0]['name'] === 'Produit été', 'selected shop language');
    expectSnapshot((float) $snapshot[0]['payload']['price_tax_excl'] === 39.9, 'selected shop price');
    expectSnapshot(count($snapshot[0]['payload']['variants']) === 1 && (float) $snapshot[0]['payload']['variants'][0]['price_impact_tax_excl'] === 5.0, 'selected shop variants');
    expectSnapshot((int) $snapshot[0]['payload']['variants'][0]['cover_image_id'] === 72,
        'variant image differs from cover, excludes foreign shop/product and uses native position then image ID');
    expectSnapshot((int) $reader->capture(2)[0]['payload']['variants'][0]['cover_image_id'] === 70,
        'shared combination uses the image associated with the selected shop');
    $writer->exec('DELETE FROM ps_product_attribute_image WHERE id_product_attribute=100');
    expectSnapshot($reader->capture(1)[0]['payload']['variants'][0]['cover_image_id'] === null,
        'variant without image does not invent an image ID');
    $writer->exec('INSERT INTO ps_product_attribute_image VALUES (100,72)');
    expectSnapshot(count($snapshot[0]['payload']['stocks']) === 2, 'own stock and shared own-group stock only');
    expectSnapshot($snapshot[1]['payload']['status'] === 'active' && $snapshot[1]['payload']['email'] === 'fixture@example.invalid', 'active own cart');
    expectSnapshot(strpos(json_encode($snapshot), 'other@example.invalid') === false, 'no foreign customer');
    expectSnapshot(!$readerPDO->inTransaction(), 'read transaction closed');
    expectSnapshot($readerPDO->startedWithPdo === 3, 'PDO tracks snapshot transactions on PHP 7.4');
    expectSnapshot((int) $snapshot[1]['payload']['customer_is_guest'] === 0, 'registered customer exported');
    expectSnapshot($snapshot[1]['payload']['marketing_consent']['state'] === 'denied', 'native newsletter false stays denied');
    $writer->exec('UPDATE ps_customer SET newsletter=1 WHERE id_customer=1');
    $consent = $reader->capture(1)[1]['payload']['marketing_consent'];
    expectSnapshot($consent['state'] === 'granted' && $consent['email'] === 'fixture@example.invalid'
        && $consent['scope'] === '1' && $consent['changed_at'] === null, 'native grant scoped without invented timestamp');
    foreach (['deleted=1', 'active=0', 'id_shop=2', 'newsletter=9'] as $change) {
        $writer->exec('UPDATE ps_customer SET newsletter=1,active=1,deleted=0,id_shop=1 WHERE id_customer=1');
        $writer->exec('UPDATE ps_customer SET '.$change.' WHERE id_customer=1');
        expectSnapshot($reader->capture(1)[1]['payload']['marketing_consent']['state'] === 'unknown', 'unavailable or foreign account cannot grant');
    }
    $writer->exec('UPDATE ps_customer SET newsletter=0,active=1,deleted=0,id_shop=1 WHERE id_customer=1');
    expectSnapshot($reader->capture(1)[1]['payload']['marketing_consent']['state'] === 'denied', 'native withdrawal observed');
    $writer->exec('UPDATE ps_customer SET is_guest=1 WHERE id_customer=1');
    expectSnapshot((int) $reader->capture(1)[1]['payload']['customer_is_guest'] === 1, 'guest with customer ID stays guest');
    $writer->exec('UPDATE ps_cart SET id_customer=99 WHERE id_cart=1');
    expectSnapshot($reader->capture(1)[1]['payload']['customer_is_guest'] === null, 'missing customer is unknown');
    $writer->exec('UPDATE ps_cart SET id_customer=1 WHERE id_cart=1');
    $writer->exec('UPDATE ps_customer SET is_guest=0 WHERE id_customer=1');
    $writer->beginTransaction();
    $writer->exec("INSERT INTO ps_orders VALUES (1,1,1,'ORDER-TEST',2,39.9,NOW())");
    $readerPDO->beforeCartRead = static function (PDO $connection) use ($writer): void {
        $blocked = false;
        try { $connection->exec('UPDATE ps_shop SET active=0 WHERE id_shop=1'); }
        catch (PDOException $error) { $blocked = true; }
        expectSnapshot($blocked, 'native transaction enforces read only');
        $writer->commit(); // Commit after the reader acquired its snapshot.
    };
    expectSnapshot($reader->capture(1)[1]['payload']['status'] === 'active', 'one consistent snapshot despite concurrent commit');
    expectSnapshot($reader->capture(1)[1]['payload']['status'] === 'converted', 'next reconciliation sees committed conversion');
    $config = ['environment' => 'staging', 'platform' => 'prestashop', 'nativeScope' => 1, 'shopId' => 'synthetic-shop', 'secret' => str_repeat('ab', 32)];
    $export = new ReconciledSourceExporter($directory, $config, static function () use ($reader) { return $reader->capture(1); });
    $input = ['schema' => 1, 'shopId' => 'synthetic-shop', 'streamId' => null, 'cursor' => '', 'limit' => 8];
    $page = $export->page($input);
    expectSnapshot($page['complete'] && count($page['records']) === 2, 'native bootstrap feeds durable export');
    $gatewayConfig = array_replace($config, ['enabled' => true, 'shopId' => 'synthetic-gateway']);
    $configPath = $directory . '/gateway.json';
    file_put_contents($configPath, json_encode($gatewayConfig)); chmod($configPath, 0600);
    putenv('NC_COMMUNITY_SOURCE_CONFIG=' . $configPath);
    $path = '/module/neurocheckoutconnector/communitydata';
    $time = (string) (int) floor(microtime(true) * 1000); $nonce = bin2hex(random_bytes(16));
    $body = json_encode(array_replace($input, ['shopId' => 'synthetic-gateway']));
    $signature = hash_hmac('sha256', implode("\n", ['nc-source-pull-v1', 'POST', $path,
        'synthetic-gateway', $time, $nonce, hash('sha256', $body)]), hex2bin($config['secret']));
    [$status, $responseHeaders, $responseBody] = SourcePullGateway::handle('prestashop', 1, __DIR__, 'POST', $path,
        ['content-type' => 'application/json', 'x-nc-source-time' => $time, 'x-nc-source-nonce' => $nonce,
            'x-nc-source-signature' => $signature], $body, true,
        static function ($request, $scope, $configuration, $stateDirectory) use ($reader): array {
            return (new ReconciledSourceExporter($stateDirectory, $configuration,
                static function () use ($reader, $scope) { return $reader->capture($scope); }))->page($request);
        });
    expectSnapshot($status === 200, 'authenticated gateway invokes native export factory');
    expectSnapshot(count(json_decode($responseBody, true)['records']) === 2, 'native records in gateway response');
    expectSnapshot($responseHeaders['X-NC-Source-Response'] === SourcePullProtocol::responseSignature($config['secret'], $nonce, $responseBody), 'native response exact bytes signed');
    $input['cursor'] = $page['nextCursor']; $input['streamId'] = $page['streamId'];
    $writer->exec('DELETE FROM ps_product_shop WHERE id_product=10 AND id_shop=1');
    $deleted = $export->page($input);
    expectSnapshot($deleted['complete'] && count($deleted['records']) === 1 && $deleted['records'][0]['operation'] === 'delete', 'native shop unlink emits product tombstone');
    $writer->exec('UPDATE ps_product_lang SET description_short=REPEAT("x",17000) WHERE id_shop=2');
    $refused = false;
    try { $reader->capture(2); } catch (RuntimeException $error) { $refused = true; }
    expectSnapshot($refused && !$readerPDO->inTransaction(), 'oversized description refused, not exported truncated');
    $writer->exec('UPDATE ps_product_lang SET description_short="Other description" WHERE id_shop=2');
    $writer->exec('UPDATE ps_cart_product SET id_shop=1 WHERE id_cart=2');
    $refused = false;
    try { $reader->capture(2); } catch (RuntimeException $error) { $refused = true; }
    expectSnapshot($refused && !$readerPDO->inTransaction(), 'inconsistent cart line scope refused');
    $writer->exec('UPDATE ps_cart_product SET id_shop=2 WHERE id_cart=2');
    $writer->exec("INSERT INTO ps_orders VALUES (2,2,1,'WRONG-SCOPE',2,39.9,NOW())");
    $refused = false;
    try { $reader->capture(1); } catch (RuntimeException $error) { $refused = true; }
    expectSnapshot($refused && !$readerPDO->inTransaction(), 'inconsistent converted order scope refused');
    $writer->exec('DELETE FROM ps_orders WHERE id_order=2');
    $writer->exec('ALTER TABLE ps_category_product ENGINE=MyISAM');
    $refused = false;
    try { $reader->capture(1); } catch (RuntimeException $error) { $refused = true; }
    expectSnapshot($refused && !$readerPDO->inTransaction(), 'nontransactional source refused');
    $writer->exec('ALTER TABLE ps_category_product ENGINE=InnoDB');
    for ($i = 3; $i < 260; $i++) { $writer->exec("INSERT INTO ps_cart VALUES ($i,1,1,1,1,NOW(),NOW())"); }
    $refused = false;
    try { $reader->capture(1); } catch (RuntimeException $error) { $refused = true; }
    expectSnapshot($refused && !$readerPDO->inTransaction(), 'capacity overflow refused, not truncated');
    define('_DB_SERVER_', 'localhost:' . $socket); define('_DB_NAME_', $database);
    define('_DB_USER_', 'root'); define('_DB_PASSWD_', ''); define('_DB_PREFIX_', 'ps_');
    expectSnapshot(count(PrestashopSourceSnapshot::fromRuntime(static function () { return []; })->capture(2)) === 3, 'isolated runtime connection and socket syntax');
    if (($argv[2] ?? '') === '--pages-json') {
        echo json_encode(['assertions' => $checks, 'pages' => [$page, $deleted]], JSON_THROW_ON_ERROR);
    } else { echo $checks . " native MariaDB snapshot assertions passed (synthetic schema).\n"; }
} finally {
    putenv($oldConfiguration === false ? 'NC_COMMUNITY_SOURCE_CONFIG' : 'NC_COMMUNITY_SOURCE_CONFIG=' . $oldConfiguration);
    if (isset($writer) && $writer->inTransaction()) { $writer->rollBack(); }
    $admin->exec('DROP DATABASE `' . $database . '`');
    foreach (glob($directory . '/*') as $file) { unlink($file); }
    rmdir($directory);
}

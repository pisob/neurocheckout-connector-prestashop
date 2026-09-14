<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../src/Community/PrestashopSourceDirectory.php';
require_once __DIR__ . '/../src/Community/ReconciledSourceExporter.php';
use NeuroCheckout\Community\PrestashopSourceDirectory;
use NeuroCheckout\Community\ReconciledSourceExporter;

function check(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
function rejected(callable $call): void {
    try { $call(); } catch (RuntimeException $error) {
        check($error->getMessage() === 'source_unavailable', 'unsanitized failure'); return;
    }
    throw new RuntimeException('unsafe operation accepted');
}
function removeTree(string $path): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    foreach (array_diff(scandir($path), ['.', '..']) as $name) { removeTree($path . '/' . $name); }
    rmdir($path);
}
function fixture(string $base, string $name): array {
    $root = $base . '/' . $name;
    $old = $root . '/var/cache/prod/neurocheckout-community-source';
    mkdir($old, 0700, true);
    return [$root, $old];
}
$base = sys_get_temp_dir() . '/nc-directory-test-' . bin2hex(random_bytes(12));
mkdir($base, 0700);
try {
    [$root, $old] = fixture($base, 'migration');
    $config = ['environment'=>'staging', 'platform'=>'prestashop', 'nativeScope'=>1,
        'secret'=>str_repeat('ab',32), 'shopId'=>'synthetic-shop'];
    $snapshot = [];
    for ($i=0; $i<10; $i++) { $snapshot[] = ['kind'=>'product','sourceId'=>(string)$i,'payload'=>['name'=>'Synthetic '.$i]]; }
    $capture = static function () use (&$snapshot): array { return $snapshot; };
    $source = new ReconciledSourceExporter($old, $config, $capture);
    $input = ['shopId'=>'synthetic-shop', 'streamId'=>null, 'cursor'=>'', 'limit'=>8];
    $first = $source->page($input);
    check(count($first['records']) === 8 && !$first['complete'], 'expected pending records');
    file_put_contents($old.'/source-test.json', '{"window":123,"count":2,"nonces":{"synthetic":9999999999}}');
    chmod($old.'/source-test.json',0600);
    touch($old.'/source-test.lock');chmod($old.'/source-test.lock',0600);
    $before = [];
    foreach (glob($old.'/*') as $file) { $before[basename($file)] = hash_file('sha256', $file); }
    $target = PrestashopSourceDirectory::resolve($root, $old);
    check($target === $root.'/var/neurocheckout-community-source' && !file_exists($old), 'not moved out of cache');
    foreach ($before as $name=>$hash) { check(hash_file('sha256',$target.'/'.$name)===$hash, 'migration changed state'); }
    check((fileperms($target)&0777)===0700, 'directory is not private');
    check(strpos(file_get_contents($target.'/.htaccess'),'Require all denied')!==false, 'missing web guard');
    // Same cursor, stream, pending records and replay behaviour survive migration.
    $source = new ReconciledSourceExporter($target, $config, $capture);
    $replay = $source->page($input);
    check(json_encode($replay['records'])===json_encode($first['records']) && $replay['nextCursor']===$first['nextCursor'], 'replay changed');
    $input['streamId']=$first['streamId']; $input['cursor']=$first['nextCursor'];
    $next=$source->page($input);
    check(count($next['records'])===2 && $next['streamId']===$first['streamId'], 'pending records lost');
    removeTree($root.'/var/cache');
    check(PrestashopSourceDirectory::resolve($root,$old)===$target, 'cache purge reset state');
    check(PrestashopSourceDirectory::resolve($root,$root.'/var/cache/dev/neurocheckout-community-source')===$target, 'cache mode changed source');
    $snapshot[0]['payload']['name']='Updated';
    $input['cursor']=$next['nextCursor'];
    $updated=$source->page($input);
    check(count($updated['records'])===1 && $updated['records'][0]['revision']===2, 'revision did not continue');
    // A stale legacy copy must never overwrite the authoritative durable state.
    mkdir($old,0700,true); file_put_contents($old.'/stale','do not use');
    check(PrestashopSourceDirectory::resolve($root,$old)===$target, 'stale cache took precedence');
    // Missing durable state after initialization must fail closed, never start a new stream.
    rename($target,$target.'.saved');
    rejected(static function () use ($root,$old): void { PrestashopSourceDirectory::resolve($root,$old); });
    check(!file_exists($target), 'missing state silently recreated');

    [$root,$old]=fixture($base,'busy');
    $handle=fopen($old.'/source-test.lock','x+b');chmod($old.'/source-test.lock',0600);flock($handle,LOCK_EX);
    rejected(static function () use ($root,$old): void { PrestashopSourceDirectory::resolve($root,$old); });
    check(is_dir($old),'busy source moved');flock($handle,LOCK_UN);fclose($handle);
    check(is_dir(PrestashopSourceDirectory::resolve($root,$old)), 'retry failed after lock release');

    [$root,$old]=fixture($base,'symlink');
    symlink($base.'/migration/var/neurocheckout-community-source.saved',$root.'/var/neurocheckout-community-source');
    rejected(static function () use ($root,$old): void { PrestashopSourceDirectory::resolve($root,$old); });
    [$root,$old]=fixture($base,'unsafe-file');
    file_put_contents($old.'/state.bin','private');chmod($old.'/state.bin',0644);
    rejected(static function () use ($root,$old): void { PrestashopSourceDirectory::resolve($root,$old); });
    check(file_get_contents($old.'/state.bin')==='private','rejected migration changed data');
    [$root,$old]=fixture($base,'legacy-link');
    rename($old,$old.'.saved');symlink($old.'.saved',$old);
    rejected(static function () use ($root,$old): void { PrestashopSourceDirectory::resolve($root,$old); });
    [$root,$old]=fixture($base,'unsafe-directory');chmod($old,0755);
    rejected(static function () use ($root,$old): void { PrestashopSourceDirectory::resolve($root,$old); });
    [$root,$old]=fixture($base,'new-install');rmdir($old);
    check(is_dir(PrestashopSourceDirectory::resolve($root,$old)), 'new install failed');
    echo "Durable source directory: migration, replay, revisions, pending records, cache purge, locks and unsafe paths passed.\n";
} finally { removeTree($base); }

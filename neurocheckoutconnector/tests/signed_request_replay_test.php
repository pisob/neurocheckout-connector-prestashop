<?php
/** Private, isolated checks. No PrestaShop bootstrap, real DB or HTTP calls. */
namespace {
    $GLOBALS['nc_test_time'] = 2000000000;
    $GLOBALS['nc_test_key'] = 'internal-synthetic-connector-key-not-a-credential';
    define('_DB_PREFIX_', 'synthetic_');
    function pSQL($value) { return $value; }
    class Context {
        public static function getContext() { return (object) ['shop' => (object) ['id' => 1]]; }
    }
    class Configuration {
        public static function get($key) { return $key === 'PS_SHOP_DEFAULT' ? 1 : ''; }
    }
    class Db {
        public static $rows = [];
        public static function getInstance() { return new self(); }
        public function insert($table, $data) {
            if ($table !== 'neurocheckout_nonce') throw new \RuntimeException('Unexpected DB access');
            $key = $data['shop_id'] . ':' . $data['nonce'];
            if (isset(self::$rows[$key])) return false;
            self::$rows[$key] = $data;
            return true;
        }
        public function execute($sql) {
            if (!preg_match('/^DELETE FROM `synthetic_neurocheckout_nonce`\s+WHERE expires_at < ([0-9]+)$/', trim($sql), $match)) {
                throw new \RuntimeException('Unexpected DB operation');
            }
            foreach (self::$rows as $key => $row) {
                if ($row['expires_at'] < (int) $match[1]) unset(self::$rows[$key]);
            }
            return true;
        }
    }
}
namespace NeuroCheckout\Infrastructure {
    function time() { return $GLOBALS['nc_test_time']; }
    class SecurityThrottleRepository {
        public function getRetryAfterSeconds(...$args) { return 0; }
        public function recordFailure(...$args) { return 0; }
        public function clear(...$args) {}
    }
}
namespace NeuroCheckout\Security {
    function time() { return $GLOBALS['nc_test_time']; }
    class IpResolver {
        public function parseRules($value) { return []; }
        public function resolve(...$args) { return '127.0.0.1'; }
    }
    class SecretConfiguration {
        public static function get($key) { return $key === 'NC_API_KEY' ? $GLOBALS['nc_test_key'] : ''; }
    }
}
namespace {
    $root = realpath($argv[1] ?? dirname(__DIR__));
    if (!$root || !is_file($root . '/src/Security/RequestSecurityValidator.php')) {
        throw new \RuntimeException('Supply the exact candidate module directory');
    }
    require $root . '/src/Infrastructure/NonceRepository.php';
    require $root . '/src/Security/RequestSecurityValidator.php';
    require $root . '/src/Http/RequestBodyDecoder.php';
    $checks = [];
    function check($name, $callback) {
        global $checks;
        try {
            $callback();
            $checks[] = ['name' => $name, 'passed' => true];
        } catch (\Throwable $error) {
            $checks[] = ['name' => $name, 'passed' => false, 'failure' => $error->getMessage()];
        }
    }
    function expect($actual, $expected) {
        if ($actual !== $expected) throw new \RuntimeException('Expected ' . json_encode($expected) . ', received ' . json_encode($actual));
    }
    function sign($offset = 0) {
        $body = '{"synthetic":true}';
        $timestamp = $GLOBALS['nc_test_time'] + $offset;
        $nonce = 'synthetic-nonce';
        \Db::$rows = [];
        $_SERVER = [
            'HTTP_X_NEURO_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_NEURO_NONCE' => $nonce,
            'HTTP_X_API_KEY' => $GLOBALS['nc_test_key'],
            'HTTP_X_NEURO_SIGNATURE' => hash_hmac('sha256', $timestamp . '.' . $nonce . '.' . $body, $GLOBALS['nc_test_key']),
        ];
        return $body;
    }
    function validate($body) {
        return (new \NeuroCheckout\Security\RequestSecurityValidator())->validateSignedPost($body, 'synthetic-endpoint');
    }
    check('valid HMAC accepted', function () { expect(validate(sign())['status'], 200); });
    check('modified body rejected', function () { expect(validate(sign() . 'x')['status'], 403); });
    check('API key hash cannot replace raw key', function () {
        $body = sign();
        $_SERVER['HTTP_X_API_KEY'] = hash('sha256', $GLOBALS['nc_test_key']);
        expect(validate($body)['status'], 403);
    });
    check('immediate signed request replay rejected', function () {
        $body = sign();
        expect(validate($body)['status'], 200);
        expect(validate($body)['status'], 409);
    });
    check('expired timestamp rejected', function () { expect(validate(sign(-121))['status'], 403); });
    check('timestamp beyond future skew rejected', function () { expect(validate(sign(121))['status'], 403); });
    check('nonce remains reserved for entire accepted clock-skew window', function () {
        $body = sign(119);
        expect(validate($body)['status'], 200);
        $GLOBALS['nc_test_time'] += 121;
        try { expect(validate($body)['status'], 409); }
        finally { $GLOBALS['nc_test_time'] -= 121; }
    });
    check('valid gzip decoded', function () {
        $body = '{"synthetic":true}';
        $result = \NeuroCheckout\Http\RequestBodyDecoder::decode(gzencode($body), 'gzip', 1024);
        expect($result['status'], 200);
        expect($result['body'], $body);
    });
    check('gzip expansion bounded', function () {
        expect(\NeuroCheckout\Http\RequestBodyDecoder::decode(gzencode(str_repeat('x', 1000000)), 'gzip', 1024)['status'], 413);
    });
    foreach ([0, 119, 120] as $skew) {
        check('replay rejected at last valid second for skew ' . $skew, function () use ($skew) {
            $body = sign($skew);
            expect(validate($body)['status'], 200);
            $elapsed = $skew + 120;
            $GLOBALS['nc_test_time'] += $elapsed;
            try {
                expect(validate($body)['status'], 409);
                $GLOBALS['nc_test_time'] += 1;
                expect(validate($body)['status'], 403);
            } finally { $GLOBALS['nc_test_time'] = 2000000000; }
        });
    }
    check('expired nonce is eventually purged', function () {
        $body = sign(120);
        expect(validate($body)['status'], 200);
        $GLOBALS['nc_test_time'] += 242;
        try {
            (new \NeuroCheckout\Infrastructure\NonceRepository())->purgeExpired();
            expect(count(\Db::$rows), 0);
            expect(validate($body)['status'], 403);
        } finally { $GLOBALS['nc_test_time'] = 2000000000; }
    });
    check('truncated gzip rejected', function () {
        expect(\NeuroCheckout\Http\RequestBodyDecoder::decode(substr(gzencode('synthetic'), 0, -4), 'gzip', 1024)['status'], 400);
    });
    check('concatenated gzip rejected', function () {
        expect(\NeuroCheckout\Http\RequestBodyDecoder::decode(gzencode('one') . gzencode('two'), 'gzip', 1024)['status'], 400);
    });
    check('raw stream bounded', function () {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, str_repeat('x', 1025));
        rewind($stream);
        try { expect(\NeuroCheckout\Http\RequestBodyDecoder::readStream($stream, 1024)['status'], 413); }
        finally { fclose($stream); }
    });
    echo json_encode(['scope' => 'actual validator/nonce/decoder; synthetic DB, secrets and virtual clock', 'checks' => $checks], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(count(array_filter($checks, function ($check) { return !$check['passed']; })) ? 1 : 0);
}

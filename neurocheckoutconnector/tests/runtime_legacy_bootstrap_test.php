<?php
// Execute with a real PHP 7.1–7.3 binary, not a simulated version constant.
if (PHP_SAPI !== 'cli' || PHP_VERSION_ID >= 70400 || PHP_VERSION_ID < 70100) {
    throw new RuntimeException('This regression requires PHP 7.1–7.3 CLI');
}
define('_PS_VERSION_', '1.7.8.11');
class Module {
    public $name, $tab, $version, $author, $need_instance, $bootstrap;
    public $ps_versions_compliancy, $displayName, $description, $warning, $context;
    protected $_errors = [];
    public function __construct() {
        $this->context = (object) ['language' => (object) ['iso_code' => 'en']];
    }
    public function install() { throw new RuntimeException('Must not mutate an unsupported shop'); }
    public function displayError($message) { return $message; }
    public function getErrors() { return $this->_errors; }
}
require __DIR__ . '/../neurocheckoutconnector.php';
$module = new NeuroCheckoutConnector();
if ($module->install() !== false || !$module->getErrors()
    || strpos($module->warning, PHP_VERSION) === false
    || strpos($module->getContent(), 'PHP 7.4') === false
    || strpos($module->getContent(), 'cron') === false) {
    throw new RuntimeException('Unsupported PHP must retain an actionable administration screen');
}
echo 'Legacy PHP ' . PHP_VERSION . ": Composer fatal prevented; install refused with specific requirements.\n";

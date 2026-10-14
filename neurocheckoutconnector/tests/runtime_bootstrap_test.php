<?php
// Run with php -n: missing cURL must be an actionable error, not an install crash.
if (extension_loaded('curl')) { throw new RuntimeException('Run this test using php -n'); }
define('_PS_VERSION_', '1.7.8.11');
class Module {
    public $name, $tab, $version, $author, $need_instance, $bootstrap;
    public $ps_versions_compliancy, $displayName, $description;
    public $context;
    public $warning;
    protected $_errors = [];
    public function __construct() {
        $this->context = (object) ['language' => (object) ['iso_code' => 'en']];
    }
    public function install() { throw new RuntimeException('Must not mutate a shop before requirements pass'); }
    public function displayError($message) { return $message; }
    public function getErrors() { return $this->_errors; }
}
require __DIR__ . '/../neurocheckoutconnector.php';
$module = new NeuroCheckoutConnector();
if ($module->install() !== false || strpos($module->warning, 'curl') === false
    || strpos($module->getContent(), 'curl') === false || !$module->getErrors()) {
    throw new RuntimeException('Missing extension must leave module administration usable');
}
echo "Missing-extension bootstrap/configuration/install fail safely before mutations.\n";

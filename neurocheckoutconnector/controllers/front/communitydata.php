<?php

use NeuroCheckout\Community\SourcePullGateway;
use NeuroCheckout\Community\ReconciledSourceExporter;
use NeuroCheckout\Community\PrestashopSourceSnapshot;
use NeuroCheckout\Community\AutomaticSourceBinding;
use NeuroCheckout\Community\PrestashopSourceDirectory;
use NeuroCheckout\Security\SecretConfiguration;

/** Dedicated, disabled-by-default staging endpoint; never uses the Cloud API key. */
class NeuroCheckoutConnectorCommunitydataModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $auth = false;
    public $ajax = true;

    public function initContent()
    {
        parent::initContent();
        $automatic = null;
        if (class_exists(SecretConfiguration::class) && class_exists('Configuration')) {
            $apiKey = SecretConfiguration::get('NC_API_KEY');
            $shopId = trim((string) Configuration::get('NC_SHOP_EXTERNAL_ID'));
            $apiEndpoint = rtrim(trim((string) Configuration::get('NC_API_ENDPOINT')), '/');
            if ($apiEndpoint === 'https://community-api-staging.neurocheckout.com'
                && (int) Configuration::get('NC_API_TEST_VALIDATED_AT') > 0 && $apiKey !== '' && $shopId !== '') {
                $automatic = ['enabled' => true, 'environment' => 'staging',
                    'nativeScope' => (int) $this->context->shop->id, 'platform' => 'prestashop',
                    'secret' => AutomaticSourceBinding::secret($apiKey, $shopId), 'shopId' => $shopId];
            }
        }
        $directory = null;
        if ($automatic !== null) {
            try {
                $directory = PrestashopSourceDirectory::resolve(_PS_ROOT_DIR_,
                    rtrim(defined('_PS_CACHE_DIR_') ? _PS_CACHE_DIR_ : sys_get_temp_dir(), '/\\')
                    . '/neurocheckout-community-source');
            } catch (\Throwable $error) {
                http_response_code(503);
                header('Content-Type: application/json');
                header('Cache-Control: no-store');
                echo '{"error":"source_unavailable"}';
                exit;
            }
        }
        [$status, $headers, $body] = SourcePullGateway::handle(
            'prestashop', (int) $this->context->shop->id, _PS_ROOT_DIR_,
            (string) ($_SERVER['REQUEST_METHOD'] ?? ''), (string) ($_SERVER['REQUEST_URI'] ?? ''),
            SourcePullGateway::serverHeaders($_SERVER), SourcePullGateway::requestBody(),
            Tools::usingSecureMode(),
            static function (array $input, int $scope, array $configuration, string $directory): array {
                $exporter = new ReconciledSourceExporter($directory, $configuration, static function () use ($scope): array {
                    return PrestashopSourceSnapshot::fromRuntime()->capture($scope);
                });
                return $exporter->page($input);
            }, $automatic, $directory
        );
        http_response_code($status);
        foreach ($headers as $name => $value) { header($name . ': ' . $value); }
        echo $body;
        exit;
    }
}

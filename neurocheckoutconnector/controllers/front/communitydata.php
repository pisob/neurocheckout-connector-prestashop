<?php

use NeuroCheckout\Community\SourcePullGateway;
use NeuroCheckout\Community\ReconciledSourceExporter;
use NeuroCheckout\Community\PrestashopSourceSnapshot;

/** Dedicated, disabled-by-default staging endpoint; never uses the Cloud API key. */
class NeuroCheckoutConnectorCommunitydataModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $auth = false;
    public $ajax = true;

    public function initContent()
    {
        parent::initContent();
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
            }
        );
        http_response_code($status);
        foreach ($headers as $name => $value) { header($name . ': ' . $value); }
        echo $body;
        exit;
    }
}

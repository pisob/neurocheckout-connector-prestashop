<?php

class NeuroCheckoutConnectorJourneyModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $auth = false;
    public $ajax = true;

    private const MAX_BODY_BYTES = 131072;

    public function initContent()
    {
        parent::initContent();
        header('Content-Type: application/json');

        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
            $this->jsonExit(405, false, 'Method not allowed');
        }

        if ((int) Configuration::get('NC_CUSTOMER_JOURNEY_ENABLED') === 0) {
            $this->jsonExit(202, true, null);
        }

        try {
            if (!$this->isSameSiteRequest()) {
                $this->jsonExit(403, false, 'Forbidden');
            }

            $rawBody = file_get_contents('php://input') ?: '';
            if ($rawBody === '' || strlen($rawBody) > self::MAX_BODY_BYTES) {
                $this->jsonExit(413, false, 'Invalid payload size');
            }

            $payload = json_decode($rawBody, true);
            if (!is_array($payload)) {
                $this->jsonExit(422, false, 'Invalid JSON payload');
            }

            $expectedToken = '';
            if ($this->module && method_exists($this->module, 'getCustomerJourneyPublicToken')) {
                $expectedToken = (string) $this->module->getCustomerJourneyPublicToken();
            }
            $providedToken = trim((string) ($payload['token'] ?? $this->headerValue('X-Neuro-Journey-Token')));
            unset($payload['token']);

            if ($expectedToken === '' || $providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
                $this->jsonExit(403, false, 'Forbidden');
            }

            $queued = false;
            if ($this->module && method_exists($this->module, 'enqueueCustomerJourneyPayload')) {
                $queued = (bool) $this->module->enqueueCustomerJourneyPayload($payload, 'browser');
            }

            if ($queued && $this->module && method_exists($this->module, 'kickCustomerJourneyDispatch')) {
                $this->module->kickCustomerJourneyDispatch();
            }

            $this->jsonExit(202, true, null, ['queued' => $queued]);
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('[NC] Customer journey endpoint fatal: ' . $e->getMessage(), 3);
            $this->jsonExit(500, false, 'Internal error');
        }
    }

    private function headerValue(string $headerName): string
    {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));
        return (string) ($_SERVER[$serverKey] ?? '');
    }

    private function isSameSiteRequest(): bool
    {
        $allowedHosts = array_filter([
            $this->normalizeHost((string) ($_SERVER['HTTP_HOST'] ?? '')),
            $this->normalizeHost((string) Configuration::get('PS_SHOP_DOMAIN')),
            $this->normalizeHost((string) Configuration::get('PS_SHOP_DOMAIN_SSL')),
        ]);
        $allowedHosts = array_values(array_unique($allowedHosts));
        if (empty($allowedHosts)) {
            return true;
        }

        foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $serverKey) {
            $value = trim((string) ($_SERVER[$serverKey] ?? ''));
            if ($value === '') {
                continue;
            }

            $host = $this->normalizeHost((string) parse_url($value, PHP_URL_HOST));
            if ($host !== '' && !in_array($host, $allowedHosts, true)) {
                return false;
            }
        }

        return true;
    }

    private function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('/:\d+$/', '', $host);
        return trim((string) $host, " \t\n\r\0\x0B.");
    }

    private function jsonExit(int $status, bool $success, ?string $error = null, array $extra = []): void
    {
        http_response_code($status);
        echo json_encode(array_merge([
            'success' => $success,
            'error' => $error,
        ], $extra));
        exit;
    }
}

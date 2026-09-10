<?php

namespace NeuroCheckout\BackOffice;

use Module;
use Context;
use Configuration;
use Tools;
use NeuroCheckout\Security\SecretConfiguration;

class GeneralFormRenderer
{
    private Module $module;
    private Context $context;

    public function __construct(Module $module)
    {
        $this->module  = $module;
        $this->context = Context::getContext();
    }

    /**
     * Render General Configuration Tab
     */
    public function render(): string
    {
        $apiTestGateRequired = true;
        $apiTestValidatedAtDisplay = '';

        if (method_exists($this->module, 'isApiTestValidationCurrent')) {
            $apiTestGateRequired = !$this->module->isApiTestValidationCurrent();
        }

        if (method_exists($this->module, 'getApiTestValidatedAt')) {
            $validatedAt = (int) $this->module->getApiTestValidatedAt();
            if ($validatedAt > 0) {
                $apiTestValidatedAtDisplay = date('Y-m-d H:i:s', $validatedAt);
            }
        }

        $this->context->smarty->assign([
            'nc_api_endpoint'     => (string) Configuration::get('NC_API_ENDPOINT'),
            'nc_api_key'          => '',
            'nc_api_key_configured' => SecretConfiguration::get('NC_API_KEY') !== '',
            'nc_shop_external_id' => (string) Configuration::get('NC_SHOP_EXTERNAL_ID'),
            'nc_opaque_recovery_links' => $this->isOpaqueRecoveryLinksEnabled(),
            'apitest_url'         => $this->getApiTestUrl(),
            'api_test_gate_required' => $apiTestGateRequired,
            'api_test_validated_at_display' => $apiTestValidatedAtDisplay,
        ]);

        return $this->module->display(
            $this->module->getLocalPath(),
            'views/templates/admin/general.tpl'
        );
    }

    /**
     * Generate secure API test URL
     */
   
    private function getApiTestUrl(): string
    {
        $secret = $this->ensureInternalSecret();
        if ($secret === '') {
            return '';
        }

        $timestamp = time();
        try {
            $nonce = bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            return '';
        }

        $signature = hash_hmac(
            'sha256',
            $timestamp . '.' . $nonce,
            $secret
        );

        return $this->context->link->getModuleLink(
            $this->module->name,
            'apitest',
            [
                'ts'    => $timestamp,
                'nonce' => $nonce,
                'sig'   => $signature,
            ],
            true
        );
    }

    private function ensureInternalSecret(): string
    {
        $secret = SecretConfiguration::get('NC_INTERNAL_SECRET');
        if ($secret !== '') {
            return $secret;
        }

        try {
            $secret = bin2hex(random_bytes(32));
        } catch (\Throwable $e) {
            return '';
        }

        if (!SecretConfiguration::set('NC_INTERNAL_SECRET', $secret)) {
            return '';
        }

        return SecretConfiguration::get('NC_INTERNAL_SECRET');
    }

    private function isOpaqueRecoveryLinksEnabled(): bool
    {
        $raw = trim((string) Configuration::get('NC_OPAQUE_RECOVERY_LINKS'));
        if ($raw === '') {
            return true;
        }

        return $raw !== '0';
    }

}

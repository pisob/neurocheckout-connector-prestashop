<?php

namespace NeuroCheckout\Security;

use Configuration;
use Context;
use Link;
use NeuroCheckout\Infrastructure\RecoveryTokenRepository;

class RecoveryLinkService
{
    public const LINK_TTL_SECONDS = 604800;
    private const MODULE_NAME = 'neurocheckoutconnector';

    private RecoveryTokenRepository $recoveryTokenRepository;

    public function __construct(?RecoveryTokenRepository $recoveryTokenRepository = null)
    {
        $this->recoveryTokenRepository = $recoveryTokenRepository ?: new RecoveryTokenRepository();
    }

    public function build(
        string $cartId,
        string $customerEmail,
        string $couponCode = '',
        ?string $cartFingerprint = null
    ): string
    {
        $context = Context::getContext();
        $link = (
            $context
            && isset($context->link)
            && $context->link instanceof Link
        ) ? $context->link : new Link();

        $languageId = (
            $context
            && isset($context->language)
            && (int) $context->language->id > 0
        ) ? (int) $context->language->id : null;

        $shopId = (
            $context
            && isset($context->shop)
            && (int) $context->shop->id > 0
        ) ? (int) $context->shop->id : (int) Configuration::get('PS_SHOP_DEFAULT');

        $normalizedFingerprint = trim((string) $cartFingerprint);

        if ($this->isOpaqueRecoveryLinksEnabled()) {
            $token = $this->recoveryTokenRepository->issue(
                max(1, $shopId),
                $cartId,
                $customerEmail,
                $couponCode,
                self::LINK_TTL_SECONDS,
                $normalizedFingerprint
            );
            if ($token !== null && $token !== '') {
                return $link->getModuleLink(
                    self::MODULE_NAME,
                    'recover',
                    ['rt' => $token],
                    true,
                    $languageId
                );
            }

            throw new \RuntimeException('Unable to issue opaque recovery token');
        }

        $secret = SecretConfiguration::get('NC_INTERNAL_SECRET');
        if ($secret === '') {
            throw new \RuntimeException('Internal secret missing');
        }

        $timestamp = time();
        $payload = $cartId . ':' . strtolower(trim($customerEmail)) . ':' . $couponCode . ':' . $timestamp . ':' . $normalizedFingerprint;
        $signature = hash_hmac('sha256', $payload, $secret);

        $params = [
            'cart_id' => $cartId,
            'email' => $customerEmail,
            'ts' => $timestamp,
            'sig' => $signature,
        ];

        if ($normalizedFingerprint !== '') {
            $params['fp'] = $normalizedFingerprint;
        }

        if ($couponCode !== '') {
            $params['coupon'] = $couponCode;
        }

        return $link->getModuleLink(
            self::MODULE_NAME,
            'recover',
            $params,
            true,
            $languageId
        );
    }

    public function buildCustomerSession(
        string $customerId,
        string $customerEmail,
        string $targetUrl
    ): string {
        $context = Context::getContext();
        $link = (
            $context
            && isset($context->link)
            && $context->link instanceof Link
        ) ? $context->link : new Link();

        $languageId = (
            $context
            && isset($context->language)
            && (int) $context->language->id > 0
        ) ? (int) $context->language->id : null;

        $shopId = (
            $context
            && isset($context->shop)
            && (int) $context->shop->id > 0
        ) ? (int) $context->shop->id : (int) Configuration::get('PS_SHOP_DEFAULT');

        $token = $this->recoveryTokenRepository->issueCustomerSession(
            max(1, (int) $shopId),
            (int) $customerId,
            $customerEmail,
            $targetUrl,
            self::LINK_TTL_SECONDS
        );
        if ($token === null || $token === '') {
            throw new \RuntimeException('Unable to issue customer session recovery token');
        }

        return $link->getModuleLink(
            self::MODULE_NAME,
            'recover',
            ['rt' => $token],
            true,
            $languageId
        );
    }

    public function resolveOpaque(string $token): ?array
    {
        return $this->recoveryTokenRepository->resolveUsable($token);
    }

    public function consumeOpaque(string $token): bool
    {
        return $this->recoveryTokenRepository->consume($token);
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

<?php

use NeuroCheckout\Security\CartFingerprintService;
use NeuroCheckout\Security\RecoveryLinkService;
use NeuroCheckout\Security\SecretConfiguration;

class NeuroCheckoutConnectorRecoverModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $auth = false;
    public $guestAllowed = true;

    private const LINK_TTL_SECONDS = 604800; // 7 days

    protected function canonicalRedirection($canonical_url = '')
    {
        // Recovery links are signed URLs and must not be rewritten before validation.
        return;
    }

    public function initContent()
    {
        parent::initContent();

        $recoveryToken = trim((string)(Tools::getValue('rt') ?: Tools::getValue('token')));
        $recoveryLinkService = new RecoveryLinkService();

        $cartId = 0;
        $email = '';
        $couponCode = '';
        $cartFingerprint = '';
        $targetUrl = $this->sanitizeSameShopTargetUrl(trim((string)Tools::getValue('u')));

        if ($recoveryToken !== '') {
            $opaquePayload = $recoveryLinkService->resolveOpaque($recoveryToken);
            if (!$opaquePayload) {
                PrestaShopLogger::addLog('[NC] Recover link invalid or expired opaque token', 2);
                Tools::redirect($this->buildCartUrl());
                return;
            }
            if ((int) ($opaquePayload['shop_id'] ?? 0) !== $this->currentShopId()) {
                PrestaShopLogger::addLog('[NC] Recover link rejected for a different shop context', 2);
                Tools::redirect($this->buildCartUrl());
                return;
            }

            $opaqueMode = trim((string)($opaquePayload['mode'] ?? 'cart'));
            if ($opaqueMode === 'customer_session') {
                $customerId = (int)($opaquePayload['customer_id'] ?? 0);
                $email = trim((string)($opaquePayload['customer_email'] ?? ''));
                $tokenTargetUrl = $this->sanitizeSameShopTargetUrl(trim((string)($opaquePayload['target_url'] ?? '')));

                if (!$recoveryLinkService->consumeOpaque($recoveryToken)) {
                    PrestaShopLogger::addLog('[NC] Recover link opaque token already consumed', 2);
                    Tools::redirect($this->buildCartUrl());
                    return;
                }

                if (!$this->restoreCustomerSessionById($customerId, $email)) {
                    PrestaShopLogger::addLog('[NC] Recover link customer session restore failed', 2);
                    Tools::redirect($this->buildCartUrl());
                    return;
                }

                Tools::redirect($tokenTargetUrl !== '' ? $tokenTargetUrl : $this->buildCartUrl());
                return;
            }

            $cartId = (int)($opaquePayload['cart_id'] ?? 0);
            $email = trim((string)($opaquePayload['customer_email'] ?? ''));
            $couponCode = trim((string)($opaquePayload['coupon_code'] ?? ''));
            $cartFingerprint = trim((string)($opaquePayload['cart_fingerprint'] ?? ''));
        } else {
            $cartId = (int)Tools::getValue('cart_id');
            $email = trim((string)Tools::getValue('email'));
            $couponCode = trim((string)Tools::getValue('coupon'));
            $cartFingerprint = trim((string)Tools::getValue('fp'));
            $timestamp = (int)Tools::getValue('ts');
            $signature = trim((string)Tools::getValue('sig'));

            if (!$this->isValidSignature($cartId, $email, $couponCode, $timestamp, $signature, $cartFingerprint)) {
                PrestaShopLogger::addLog('[NC] Recover link invalid signature', 2);
                Tools::redirect($this->buildCartUrl());
                return;
            }
        }

        if ($couponCode !== '') {
            $couponMeta = $this->getCouponMeta($cartId, $couponCode);
            if (!$couponMeta) {
                PrestaShopLogger::addLog('[NC] Recover link coupon metadata not found', 2);
                Tools::redirect($this->buildCartUrl());
                return;
            }

            if (!hash_equals(strtolower($couponMeta['customer_email']), strtolower($email))) {
                PrestaShopLogger::addLog('[NC] Recover link email mismatch', 2);
                Tools::redirect($this->buildCartUrl());
                return;
            }

            if ($couponMeta['expires_at'] !== '' && strtotime($couponMeta['expires_at']) < time()) {
                PrestaShopLogger::addLog('[NC] Recover link expired coupon', 2);
                Tools::redirect($this->buildCartUrl());
                return;
            }

            if (($couponMeta['cart_fingerprint'] ?? '') !== '' && !hash_equals((string) $couponMeta['cart_fingerprint'], (new CartFingerprintService())->fromCart(new Cart($cartId)))) {
                PrestaShopLogger::addLog('[NC] Recover link cart fingerprint mismatch', 2);
                Tools::redirect($this->buildCartUrl());
                return;
            }
        }

        $cart = new Cart($cartId);
        if (!(int)$cart->id) {
            PrestaShopLogger::addLog('[NC] Recover link cart not found', 2);
            Tools::redirect($this->buildCartUrl());
            return;
        }
        if ((int) $cart->id_shop !== $this->currentShopId()) {
            PrestaShopLogger::addLog('[NC] Recover link cart rejected for a different shop context', 2);
            Tools::redirect($this->buildCartUrl());
            return;
        }

        if (!$this->emailMatchesCart($cart, $email)) {
            PrestaShopLogger::addLog('[NC] Recover link cart/customer mismatch', 2);
            Tools::redirect($this->buildCartUrl());
            return;
        }

        if (!$this->cartContainsProducts($cart)) {
            // Cart can exist but be emptied/deleted by the customer.
            PrestaShopLogger::addLog('[NC] Recover link cart empty', 2);
            Tools::redirect($this->buildCartUrl());
            return;
        }

        if ($cartFingerprint !== '' && !hash_equals($cartFingerprint, (new CartFingerprintService())->fromCart($cart))) {
            PrestaShopLogger::addLog('[NC] Recover link cart fingerprint mismatch', 2);
            Tools::redirect($this->buildCartUrl());
            return;
        }

        if ($recoveryToken !== '' && !$recoveryLinkService->consumeOpaque($recoveryToken)) {
            PrestaShopLogger::addLog('[NC] Recover link opaque token already consumed', 2);
            Tools::redirect($this->buildCartUrl());
            return;
        }

        $this->restoreCartContext($cart, $email);

        Tools::redirect($targetUrl !== '' ? $targetUrl : $this->buildCartUrl($couponCode));
    }

    private function isValidSignature(
        int $cartId,
        string $email,
        string $couponCode,
        int $timestamp,
        string $signature,
        string $cartFingerprint = ''
    ): bool {
        if ($cartId <= 0 || $email === '' || $timestamp <= 0 || $signature === '') {
            return false;
        }

        if (abs(time() - $timestamp) > self::LINK_TTL_SECONDS) {
            return false;
        }

        $secret = SecretConfiguration::get('NC_INTERNAL_SECRET');
        if ($secret === '') {
            return false;
        }

        $normalizedEmail = strtolower($email);
        $normalizedFingerprint = trim($cartFingerprint);
        $payload = $cartId . ':' . $normalizedEmail . ':' . $couponCode . ':' . $timestamp . ':' . $normalizedFingerprint;
        $expected = hash_hmac('sha256', $payload, $secret);
        if (hash_equals($expected, $signature)) {
            return true;
        }

        if ($normalizedFingerprint !== '') {
            return false;
        }

        $legacyPayload = $cartId . ':' . $normalizedEmail . ':' . $couponCode . ':' . $timestamp;
        $legacyExpected = hash_hmac('sha256', $legacyPayload, $secret);

        return hash_equals($legacyExpected, $signature);
    }

    private function getCouponMeta(int $cartId, string $couponCode): ?array
    {
        $query = new DbQuery();
        $query->select('customer_email, expires_at, cart_fingerprint');
        $query->from('neurocheckout_coupon');
        $query->where('cart_id = \'' . pSQL((string)$cartId) . '\'');
        $query->where('coupon_code = \'' . pSQL($couponCode) . '\'');
        $query->orderBy('id DESC');

        $row = Db::getInstance()->getRow($query);
        if (!$row) {
            return null;
        }

        return [
            'customer_email' => (string)$row['customer_email'],
            'expires_at' => (string)$row['expires_at'],
            'cart_fingerprint' => (string)($row['cart_fingerprint'] ?? ''),
        ];
    }

    private function emailMatchesCart(Cart $cart, string $email): bool
    {
        $normalized = strtolower(trim($email));
        if ($normalized === '') {
            return false;
        }

        $customerId = (int)$cart->id_customer;
        if ($customerId <= 0) {
            return true;
        }

        $customer = new Customer($customerId);
        if (!(int)$customer->id) {
            return false;
        }

        return hash_equals($normalized, strtolower((string)$customer->email));
    }

    private function cartContainsProducts(Cart $cart): bool
    {
        try {
            $products = $cart->getProducts();
            return is_array($products) && count($products) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function restoreCartContext(Cart $cart, string $email): void
    {
        $context = Context::getContext();
        $context->cart = $cart;

        $cookie = $context->cookie;
        $cookie->id_cart = (int)$cart->id;

        if ((int)$cart->id_lang > 0) {
            $cookie->id_lang = (int)$cart->id_lang;
        }

        if ((int)$cart->id_currency > 0) {
            $cookie->id_currency = (int)$cart->id_currency;
        }

        if (!$this->restoreRegisteredCustomerSession($cart, $email)) {
            if ((int)$cart->id_guest > 0) {
                $cookie->id_guest = (int)$cart->id_guest;
                $cookie->is_guest = 1;
            } else {
                $cookie->id_guest = 0;
                $cookie->is_guest = 0;
            }
            $this->clearCustomerSession($context, $cookie);
        }

        $cookie->write();
    }

    private function restoreRegisteredCustomerSession(Cart $cart, string $email): bool
    {
        $customerId = (int)$cart->id_customer;
        if ($customerId <= 0) {
            return false;
        }

        $customer = new Customer($customerId);
        if (!(int)$customer->id) {
            return false;
        }

        if (!$this->emailMatchesCart($cart, $email)) {
            return false;
        }

        $context = Context::getContext();
        $cookie = $context->cookie;

        $context->cart = $cart;
        $context->updateCustomer($customer);
        $context->cart = $cart;
        $customer->logged = true;

        $cart->id_customer = (int)$customer->id;
        $cart->secure_key = (string)$customer->secure_key;
        if ((int)$cart->id_guest <= 0 && (int)$cookie->id_guest > 0) {
            $cart->id_guest = (int)$cookie->id_guest;
        }
        $cart->save();

        $cookie->id_cart = (int)$cart->id;
        $cookie->email = (string)$customer->email;
        $cookie->secure_key = (string)$customer->secure_key;
        $cookie->is_guest = (int)$customer->isGuest();
        $cookie->id_guest = (int)$cart->id_guest > 0 ? (int)$cart->id_guest : 0;

        PrestaShopLogger::addLog('[NC] Recover link restored customer session', 1);

        return true;
    }

    private function restoreCustomerSessionById(int $customerId, string $email): bool
    {
        if ($customerId <= 0) {
            return false;
        }

        $normalizedEmail = strtolower(trim($email));
        if ($normalizedEmail === '') {
            return false;
        }

        $customer = new Customer($customerId);
        if (!(int)$customer->id) {
            return false;
        }

        if (!hash_equals($normalizedEmail, strtolower((string)$customer->email))) {
            return false;
        }

        $context = Context::getContext();
        $cookie = $context->cookie;

        $context->updateCustomer($customer);
        $customer->logged = true;

        $cookie->id_customer = (int)$customer->id;
        $cookie->customer_lastname = (string)$customer->lastname;
        $cookie->customer_firstname = (string)$customer->firstname;
        $cookie->logged = 1;
        $cookie->email = (string)$customer->email;
        $cookie->secure_key = (string)$customer->secure_key;
        $cookie->passwd = (string)$customer->passwd;
        $cookie->is_guest = (int)$customer->isGuest();
        $cookie->write();

        PrestaShopLogger::addLog('[NC] Recover link restored customer-only session', 1);

        return true;
    }

    private function clearCustomerSession(Context $context, $cookie): void
    {
        $context->customer = null;
        unset(
            $cookie->id_customer,
            $cookie->customer_lastname,
            $cookie->customer_firstname,
            $cookie->passwd,
            $cookie->logged,
            $cookie->email,
            $cookie->secure_key
        );
    }

    private function buildCartUrl(string $couponCode = ''): string
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
            && (int)$context->language->id > 0
        ) ? (int)$context->language->id : null;

        $params = ['action' => 'show'];
        if ($couponCode !== '') {
            $params['submitAddDiscount'] = 1;
            $params['discount_name'] = $couponCode;
        }

        return $link->getPageLink('cart', true, $languageId, $params);
    }

    private function sanitizeSameShopTargetUrl(string $targetUrl): string
    {
        $candidate = trim($targetUrl);
        if ($candidate === '') {
            return '';
        }

        $parsedTarget = parse_url($candidate);
        if (!is_array($parsedTarget)) {
            return '';
        }

        if (isset($parsedTarget['user']) || isset($parsedTarget['pass'])) {
            return '';
        }

        $scheme = strtolower((string)($parsedTarget['scheme'] ?? ''));
        $targetHost = strtolower((string)($parsedTarget['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $targetHost === '') {
            return '';
        }

        $shopUrl = Tools::getShopDomainSsl(true, true);
        $parsedShop = parse_url($shopUrl);
        $shopHost = strtolower((string)($parsedShop['host'] ?? ''));
        $shopScheme = strtolower((string)($parsedShop['scheme'] ?? ''));
        $targetPort = (int)($parsedTarget['port'] ?? ($scheme === 'https' ? 443 : 80));
        $shopPort = (int)($parsedShop['port'] ?? ($shopScheme === 'https' ? 443 : 80));

        return (
            $shopHost !== ''
            && hash_equals($shopHost, $targetHost)
            && hash_equals($shopScheme, $scheme)
            && $shopPort === $targetPort
        ) ? $candidate : '';
    }

    private function currentShopId(): int
    {
        $context = Context::getContext();
        $shopId = ($context && isset($context->shop)) ? (int) ($context->shop->id ?? 0) : 0;

        return max(1, $shopId > 0 ? $shopId : (int) Configuration::get('PS_SHOP_DEFAULT'));
    }
}

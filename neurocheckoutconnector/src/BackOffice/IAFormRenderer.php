<?php

namespace NeuroCheckout\BackOffice;

use Module;
use Context;
use Configuration;

class IAFormRenderer
{
    private Module $module;
    private Context $context;

    public function __construct(Module $module)
    {
        $this->module  = $module;
        $this->context = Context::getContext();
    }

    /**
     * Render IA Configuration Tab
     */
    public function render(): string
    {
        $rawMinCartTotal = Configuration::get('NC_MIN_CART_TOTAL');
        $rawNoDiscountMax = Configuration::get('NC_NO_DISCOUNT_MAX');
        $rawDiscount5Min = Configuration::get('NC_DISCOUNT_5_MIN');
        $rawDiscount5Max = Configuration::get('NC_DISCOUNT_5_MAX');
        $rawDiscount10Min = Configuration::get('NC_DISCOUNT_10_MIN');
        $rawMaxDiscountPercent = Configuration::get('NC_MAX_DISCOUNT_PERCENT');

        $this->context->smarty->assign([
            'nc_recovery_enabled' => (bool) Configuration::get('NC_RECOVERY_ENABLED'),
            'nc_enable_discount'  => (bool) Configuration::get('NC_ENABLE_DISCOUNT'),
            'nc_min_cart_total'   => $rawMinCartTotal !== false ? (string) $rawMinCartTotal : '',
            'nc_allow_guest'      => (bool) Configuration::get('NC_ALLOW_GUEST'),

            'nc_no_discount_max'  => $rawNoDiscountMax !== false ? (string) $rawNoDiscountMax : '',
            'nc_discount_5_min'   => $rawDiscount5Min !== false ? (string) $rawDiscount5Min : '',
            'nc_discount_5_max'   => $rawDiscount5Max !== false ? (string) $rawDiscount5Max : '',
            'nc_discount_10_min'  => $rawDiscount10Min !== false ? (string) $rawDiscount10Min : '',
            'nc_max_discount_percent' => $rawMaxDiscountPercent !== false ? (string) $rawMaxDiscountPercent : '',
        ]);

        return $this->module->display(
            $this->module->getLocalPath(),
            'views/templates/admin/ia.tpl'
        );
    }
}

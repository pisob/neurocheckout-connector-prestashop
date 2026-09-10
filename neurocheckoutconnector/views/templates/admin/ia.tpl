<form class="nc-config-form" method="post">
<input type="hidden" name="submitNcSaveConfig" value="1">

<div class="panel nc-bo-card">
    <h3 class="nc-bo-section-title">🤖 {$nc_i18n.ia_title|escape:'html':'UTF-8'}</h3>

    <p>{$nc_i18n.ia_intro|escape:'html':'UTF-8'}</p>
    <p>{$nc_i18n.ia_coupon_notice|escape:'html':'UTF-8'}</p>

    <hr>

    <div class="form-group">
        <input type="hidden"
               name="NC_RECOVERY_ENABLED"
               value="1">
        <label>
            <input type="checkbox"
                   id="nc-recovery-enabled-checkbox"
                   name="NC_RECOVERY_ENABLED_DISPLAY"
                   value="1"
                   checked
                   disabled
                   aria-disabled="true">
            {$nc_i18n.recovery_enabled_label|escape:'html':'UTF-8'}
        </label>
        <p class="help-block" style="margin-top:8px;">
            {$nc_i18n.recovery_forced_notice|escape:'html':'UTF-8'}
        </p>
    </div>

    <div class="form-group">
        <input type="hidden"
               name="NC_ENABLE_DISCOUNT"
               value="0">
        <label>
            <input type="checkbox"
                   id="nc-enable-discount-checkbox"
                   name="NC_ENABLE_DISCOUNT"
                   value="1"
                   {if $nc_enable_discount}checked{/if}>
            {$nc_i18n.discount_enabled_label|escape:'html':'UTF-8'}
        </label>
    </div>

    <div id="nc-discount-confirm-modal"
         style="display:none;
                position:fixed;
                inset:0;
                background:rgba(0,0,0,0.45);
                z-index:10000;
                align-items:center;
                justify-content:center;">
        <div style="background:#fff;
                    width:92%;
                    max-width:560px;
                    border-radius:8px;
                    padding:18px;
                    box-shadow:0 8px 30px rgba(0,0,0,0.25);">
            <h4 style="margin-top:0;">{$nc_i18n.discount_confirmation_title|escape:'html':'UTF-8'}</h4>
            <p style="margin-bottom:14px;">
                {$nc_i18n.discount_confirmation_body|escape:'html':'UTF-8'}
            </p>
            <button type="button"
                    id="nc-discount-confirm-accept"
                    class="btn btn-primary">
                {$nc_i18n.discount_confirmation_accept|escape:'html':'UTF-8'}
            </button>
            <button type="button"
                    id="nc-discount-confirm-cancel"
                    class="btn btn-default"
                    style="margin-left:8px;">
                {$nc_i18n.cancel_button|escape:'html':'UTF-8'}
            </button>
        </div>
    </div>

    <div class="form-group">
        <label for="nc-min-cart-total">
            {$nc_i18n.min_cart_total_label|escape:'html':'UTF-8'}{if $nc_shop_currency_code} ({$nc_shop_currency_code|escape:'html':'UTF-8'}){/if}
            <span style="color:#d9534f;font-weight:bold;">*</span>
            <span style="
                display:inline-block;
                margin-left:6px;
                padding:2px 6px;
                border-radius:10px;
                font-size:11px;
                line-height:1.4;
                color:#8a6d3b;
                background:#fcf8e3;
                border:1px solid #faebcc;">
                {$nc_i18n.required_field_badge|escape:'html':'UTF-8'}
            </span>
        </label>
        <input type="number"
               id="nc-min-cart-total"
               name="NC_MIN_CART_TOTAL"
               value="{$nc_min_cart_total|escape:'html'}"
               step="0.01"
               min="0"
               required
               aria-required="true"
               class="form-control">
        <p class="help-block" style="margin-top:8px;">
            {$nc_i18n.min_cart_total_help|escape:'html':'UTF-8'}
        </p>
    </div>

    <div class="form-group">
        <input type="hidden"
               name="NC_ALLOW_GUEST"
               value="0">
        <label>
            <input type="checkbox"
                   name="NC_ALLOW_GUEST"
                   value="1"
                   {if $nc_allow_guest}checked{/if}>
            {$nc_i18n.allow_guest_label|escape:'html':'UTF-8'}
        </label>
    </div>

    <hr>

    <h4 class="nc-bo-subsection-title">🎯 {$nc_i18n.discount_strategy_title|escape:'html':'UTF-8'}</h4>

    <div class="form-group">
        <label>{$nc_i18n.no_discount_max_label|escape:'html':'UTF-8'}{if $nc_shop_currency_code} ({$nc_shop_currency_code|escape:'html':'UTF-8'}){/if}</label>
        <input type="number"
               name="NC_NO_DISCOUNT_MAX"
               value="{$nc_no_discount_max|escape:'html'}"
               step="0.01"
               min="0"
               class="form-control">
    </div>

    <div class="form-group">
        <label>{$nc_i18n.discount_5_min_label|escape:'html':'UTF-8'}{if $nc_shop_currency_code} ({$nc_shop_currency_code|escape:'html':'UTF-8'}){/if}</label>
        <input type="number"
               name="NC_DISCOUNT_5_MIN"
               value="{$nc_discount_5_min|escape:'html'}"
               step="0.01"
               min="0"
               class="form-control">
    </div>

    <div class="form-group">
        <label>{$nc_i18n.discount_5_max_label|escape:'html':'UTF-8'}{if $nc_shop_currency_code} ({$nc_shop_currency_code|escape:'html':'UTF-8'}){/if}</label>
        <input type="number"
               name="NC_DISCOUNT_5_MAX"
               value="{$nc_discount_5_max|escape:'html'}"
               step="0.01"
               min="0"
               class="form-control">
    </div>

    <div class="form-group">
        <label>{$nc_i18n.discount_10_min_label|escape:'html':'UTF-8'}{if $nc_shop_currency_code} ({$nc_shop_currency_code|escape:'html':'UTF-8'}){/if}</label>
        <input type="number"
               name="NC_DISCOUNT_10_MIN"
               value="{$nc_discount_10_min|escape:'html'}"
               step="0.01"
               min="0"
               class="form-control">
    </div>

    <div class="form-group">
        <label for="nc-max-discount-percent">
            {$nc_i18n.max_discount_percent_label|escape:'html':'UTF-8'}
            <span style="color:#d9534f;font-weight:bold;">*</span>
            <span style="
                display:inline-block;
                margin-left:6px;
                padding:2px 6px;
                border-radius:10px;
                font-size:11px;
                line-height:1.4;
                color:#8a6d3b;
                background:#fcf8e3;
                border:1px solid #faebcc;">
                {$nc_i18n.required_field_badge|escape:'html':'UTF-8'}
            </span>
        </label>
        <input type="number"
               id="nc-max-discount-percent"
               name="NC_MAX_DISCOUNT_PERCENT"
               value="{$nc_max_discount_percent|escape:'html'}"
               step="0.1"
               min="0"
               max="100"
               required
               aria-required="true"
               class="form-control">
        <p class="help-block" style="margin-top:8px;">
            {$nc_i18n.max_discount_percent_help|escape:'html':'UTF-8'}
        </p>
    </div>

    <hr>

    <div class="nc-bo-actions">
        <button type="submit"
                class="btn btn-primary">
            💾 {$nc_i18n.save_button|escape:'html':'UTF-8'}
        </button>
    </div>

</div>

</form>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const discountCheckbox = document.getElementById("nc-enable-discount-checkbox");
    const recoveryCheckbox = document.getElementById("nc-recovery-enabled-checkbox");
    const minCartInput = document.getElementById("nc-min-cart-total");
    const maxDiscountInput = document.getElementById("nc-max-discount-percent");
    const iaNotice = document.getElementById("nc-ia-required-notice");
    const modal = document.getElementById("nc-discount-confirm-modal");
    const confirmButton = document.getElementById("nc-discount-confirm-accept");
    const cancelButton = document.getElementById("nc-discount-confirm-cancel");

    if (!discountCheckbox || !modal || !confirmButton || !cancelButton || !minCartInput || !maxDiscountInput) {
        return;
    }

    let confirmed = discountCheckbox.checked;

    function closeModal() {
        modal.style.display = "none";
    }

    discountCheckbox.addEventListener("change", function () {
        if (!discountCheckbox.checked) {
            confirmed = false;
            closeModal();
            return;
        }

        if (confirmed) {
            return;
        }

        modal.style.display = "flex";
    });

    confirmButton.addEventListener("click", function () {
        confirmed = true;
        closeModal();
    });

    cancelButton.addEventListener("click", function () {
        discountCheckbox.checked = false;
        confirmed = false;
        closeModal();
    });

    modal.addEventListener("click", function (event) {
        if (event.target !== modal) {
            return;
        }

        discountCheckbox.checked = false;
        confirmed = false;
        closeModal();
    });

    function parseNumericValue(input) {
        const raw = String(input.value || "").trim();
        if (raw === "") {
            return null;
        }
        const value = Number(raw);
        if (!Number.isFinite(value)) {
            return null;
        }
        return value;
    }

    function isIaConfigurationComplete() {
        const minCartTotal = parseNumericValue(minCartInput);
        const maxDiscountPercent = parseNumericValue(maxDiscountInput);

        const hasRecovery = !recoveryCheckbox || Boolean(recoveryCheckbox.checked);
        const hasMinCart = minCartTotal !== null && minCartTotal >= 0;
        const hasMaxDiscount = maxDiscountPercent !== null && maxDiscountPercent >= 0 && maxDiscountPercent <= 100;

        return hasRecovery && hasMinCart && hasMaxDiscount;
    }

    function refreshIaRequirementNotice() {
        if (!iaNotice) {
            return;
        }
        iaNotice.style.display = isIaConfigurationComplete() ? "none" : "block";
    }

    window.NeuroCheckoutConnector = window.NeuroCheckoutConnector || {};
    window.NeuroCheckoutConnector.isIaConfigurationComplete = isIaConfigurationComplete;
    window.NeuroCheckoutConnector.refreshIaRequirementNotice = refreshIaRequirementNotice;

    // Backward compatibility aliases
    window.ncIsIaConfigurationComplete = window.NeuroCheckoutConnector.isIaConfigurationComplete;
    window.ncRefreshIaRequirementNotice = window.NeuroCheckoutConnector.refreshIaRequirementNotice;

    [minCartInput, maxDiscountInput, discountCheckbox].forEach(function (element) {
        if (!element) {
            return;
        }
        element.addEventListener("input", refreshIaRequirementNotice);
        element.addEventListener("change", refreshIaRequirementNotice);
    });

    refreshIaRequirementNotice();
});
</script>

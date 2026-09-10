<div id="nc-module-root" class="panel nc-bo-root">
    <style>
    #nc-module-root.nc-bo-root {
        background: linear-gradient(180deg, #fcfcff 0%, #f6f8fc 100%);
        border: 1px solid #dfe6f2;
        border-radius: 10px;
        padding: 14px;
    }
    #nc-module-root .nc-bo-heading {
        margin-top: 0;
        margin-bottom: 0;
        font-size: 26px;
        font-weight: 700;
        color: #1f2947;
    }
    #nc-module-root .nc-bo-hero {
        display: flex;
        align-items: center;
        gap: 14px;
        margin-bottom: 14px;
    }
    #nc-module-root .nc-bo-brand-mark {
        width: 52px;
        height: 52px;
        flex: 0 0 auto;
        border-radius: 16px;
        box-shadow: 0 12px 28px rgba(17, 49, 78, 0.14);
    }
    #nc-module-root .nc-bo-heading-wrap {
        min-width: 0;
    }
    #nc-module-root .nc-bo-eyebrow {
        display: inline-flex;
        margin-bottom: 6px;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.14em;
        text-transform: uppercase;
        color: #2b7498;
    }
    #nc-module-root .nc-bo-main-tabs {
        margin-bottom: 8px;
    }
    #nc-module-root .nc-bo-main-content {
        margin-top: 14px;
    }
    #nc-module-root .nc-bo-surface {
        background: #fff;
        border: 1px solid #dbe4f0;
        border-radius: 10px;
        padding: 16px;
    }
    #nc-module-root .nc-bo-subtabs {
        margin-bottom: 14px;
    }
    #nc-module-root .nc-bo-subtabs > li > a {
        border-radius: 999px;
        padding: 8px 13px;
        font-weight: 600;
    }
    #nc-module-root .nc-bo-subtabs > li.active > a,
    #nc-module-root .nc-bo-subtabs > li.active > a:focus,
    #nc-module-root .nc-bo-subtabs > li.active > a:hover {
        background: #2f6fed;
        color: #fff;
    }
    #nc-module-root .nc-bo-subtab-content {
        margin-top: 16px;
    }
    #nc-module-root .nc-bo-card {
        border: 1px solid #dbe4f0;
        border-radius: 10px;
        box-shadow: 0 2px 8px rgba(31, 41, 71, 0.06);
        padding: 14px;
        background: #fff;
    }
    #nc-module-root .nc-bo-section-title {
        margin-top: 0;
        margin-bottom: 14px;
        font-size: 21px;
        color: #20284a;
    }
    #nc-module-root .nc-bo-subsection-title {
        margin-top: 4px;
        margin-bottom: 10px;
        font-size: 18px;
        color: #273257;
    }
    #nc-module-root .nc-bo-alert {
        border-radius: 8px;
    }
    #nc-module-root .nc-bo-actions {
        display: flex;
        align-items: center;
        justify-content: flex-start;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 12px;
        margin-bottom: 6px;
    }
    #nc-module-root .nc-bo-kpi-line {
        margin-bottom: 8px;
    }
    #nc-module-root .nc-bo-health-pill {
        color: #fff;
        padding: 6px 12px;
        border-radius: 999px;
        font-weight: 700;
        display: inline-block;
    }
    #nc-module-root .nc-bo-kpi-ok {
        color: #2d8a3b;
        font-weight: 600;
    }
    #nc-module-root .nc-bo-kpi-warn {
        color: #b76b00;
        font-weight: 600;
    }
    #nc-module-root .nc-bo-muted-line {
        color: #5c6787;
    }
    #nc-module-root .nc-bo-well {
        margin-top: 10px;
        border-radius: 8px;
    }
    #nc-module-root .nc-cron-mode-card {
        border: 1px solid #dbe4f0;
        border-radius: 10px;
        background: #fbfdff;
        padding: 12px;
        margin-bottom: 12px;
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }
    #nc-module-root .nc-cron-mode-card.is-selected {
        border-color: #2f6fed;
        box-shadow: 0 0 0 3px rgba(47, 111, 237, 0.12);
        background: #f7fbff;
    }
    #nc-module-root .nc-cron-mode-help {
        margin-top: 8px;
        margin-bottom: 0;
    }
    #nc-module-root .nc-bo-json-title {
        margin-top: 12px;
        margin-bottom: 6px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    #nc-module-root .nc-bo-json-output {
        margin-top: 8px;
        max-height: 300px;
        overflow: auto;
        background: #f8f9fc;
        color: #19233d;
        border: 1px solid #dce4ef;
        border-radius: 8px;
        padding: 10px;
        font-size: 12px;
        line-height: 1.45;
    }
    #nc-module-root .nc-bo-health-bar-wrap {
        width: 100%;
        background: #e9edf4;
        border-radius: 8px;
        overflow: hidden;
        height: 18px;
        margin-bottom: 14px;
    }
    #nc-module-root .nc-bo-health-bar {
        height: 100%;
        transition: width 0.35s ease;
    }
    #nc-module-root .nc-bo-metrics-grid {
        margin-top: 4px;
        margin-bottom: 6px;
    }
    #nc-module-root .nc-bo-metric-card {
        border: 1px solid #e3e9f3;
        border-radius: 8px;
        background: #fcfdff;
        padding: 10px;
        min-height: 92px;
        margin-bottom: 10px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        gap: 6px;
    }
    #nc-module-root .nc-bo-metric-label {
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: #67749a;
        font-weight: 700;
    }
    #nc-module-root .nc-bo-metric-value {
        font-size: 15px;
        color: #1f2947;
        font-weight: 700;
    }
    #nc-module-root .nc-bo-metric-note {
        color: #6a7699;
    }
    #nc-module-root .nc-bo-logs-table {
        margin-bottom: 0;
        background: #fff;
    }
    #nc-module-root .nc-bo-logs-table th {
        background: #f4f7fc;
        color: #38456c;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    .nc-bo-toast {
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 10px 20px;
        color: #fff;
        border-radius: 6px;
        z-index: 2147483000;
        box-shadow: 0 10px 24px rgba(0, 0, 0, 0.22);
        max-width: 380px;
        font-weight: 700;
        pointer-events: none;
    }
    #nc-module-root .nc-ia-onboarding-modal {
        position: fixed;
        inset: 0;
        z-index: 10050;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 18px;
        background:
            radial-gradient(circle at 18% 12%, rgba(77, 143, 255, 0.16), transparent 44%),
            radial-gradient(circle at 82% 88%, rgba(124, 84, 255, 0.17), transparent 46%),
            rgba(20, 29, 55, 0.62);
        backdrop-filter: blur(3px);
    }
    #nc-module-root .nc-ia-onboarding-card {
        position: relative;
        width: 100%;
        max-width: 660px;
        border-radius: 18px;
        border: 1px solid #d5e0f3;
        box-shadow: 0 26px 52px rgba(18, 35, 78, 0.34);
        background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
        overflow: hidden;
        animation: ncIaPopupIn 220ms ease-out;
    }
    #nc-module-root .nc-ia-onboarding-card::before {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, #2a74ff 0%, #28d3ff 48%, #6c5dff 100%);
    }
    #nc-module-root .nc-ia-onboarding-close-icon {
        position: absolute;
        top: 10px;
        right: 10px;
        width: 30px;
        height: 30px;
        border: 1px solid #d8e3f6;
        border-radius: 50%;
        background: #ffffff;
        color: #5f7097;
        font-size: 18px;
        font-weight: 700;
        line-height: 1;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.16s ease;
    }
    #nc-module-root .nc-ia-onboarding-close-icon:hover {
        border-color: #bdd1f1;
        color: #334e82;
        transform: translateY(-1px);
    }
    #nc-module-root .nc-ia-onboarding-head {
        padding: 18px 22px 14px;
        display: flex;
        align-items: center;
        gap: 14px;
        background:
            linear-gradient(90deg, #eef4ff 0%, #f8f0ff 62%, #f4fcff 100%);
        border-bottom: 1px solid #dee8f7;
    }
    #nc-module-root .nc-ia-onboarding-icon {
        width: 48px;
        height: 48px;
        border-radius: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 24px;
        background: linear-gradient(145deg, #edf3ff 0%, #e2ecff 100%);
        border: 1px solid #cad9f5;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.7);
    }
    #nc-module-root .nc-ia-onboarding-head-copy {
        min-width: 0;
        display: flex;
        flex-direction: column;
        width: 100%;
    }
    #nc-module-root .nc-ia-onboarding-badge {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 5px 11px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: #855800;
        background: #fff6dd;
        border: 1px solid #f2d89a;
        align-self: center;
        margin-bottom: 14px;
    }
    #nc-module-root .nc-ia-onboarding-title {
        margin: 0;
        font-size: 29px;
        line-height: 1.14;
        color: #1e2b4d;
        font-weight: 900;
        letter-spacing: -0.015em;
    }
    #nc-module-root .nc-ia-onboarding-body {
        padding: 18px 22px 22px;
        color: #455374;
        font-size: 14px;
        line-height: 1.65;
    }
    #nc-module-root .nc-ia-onboarding-text {
        margin: 0;
        font-size: 16px;
    }
    #nc-module-root .nc-ia-onboarding-checklist {
        margin: 12px 0 0;
        padding: 0;
        list-style: none;
    }
    #nc-module-root .nc-ia-onboarding-checklist li {
        display: flex;
        align-items: center;
        gap: 8px;
        margin: 0 0 6px;
        color: #2f436f;
        font-weight: 600;
    }
    #nc-module-root .nc-ia-onboarding-check {
        width: 18px;
        height: 18px;
        border-radius: 50%;
        flex: 0 0 18px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        color: #fff;
        background: linear-gradient(180deg, #2a74ff 0%, #2869df 100%);
    }
    #nc-module-root .nc-ia-onboarding-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 16px;
    }
    #nc-module-root .nc-ia-onboarding-actions .btn {
        border-radius: 999px;
        font-weight: 700;
        padding: 8px 15px;
    }
    #nc-module-root .nc-ia-onboarding-actions .btn.btn-primary {
        border-color: #1878db;
        background: linear-gradient(135deg, #1d86d6 0%, #1ec4e9 60%, #44ddff 100%);
        color: #fff;
        box-shadow: 0 8px 16px rgba(29, 134, 214, 0.28);
    }
    #nc-module-root .nc-ia-onboarding-actions .btn.btn-primary:hover {
        transform: translateY(-1px);
        box-shadow: 0 12px 20px rgba(29, 134, 214, 0.32);
    }
    #nc-module-root .nc-ia-onboarding-actions .btn.btn-default {
        border-color: #c8d8f1;
        color: #4f658e;
        background: #f7fbff;
    }
    @keyframes ncIaPopupIn {
        from {
            opacity: 0;
            transform: translateY(10px) scale(0.985);
        }
        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }
    @media (max-width: 767px) {
        #nc-module-root .nc-bo-surface {
            padding: 12px;
        }
        #nc-module-root .nc-bo-subtabs > li {
            float: none;
            display: block;
            width: 100%;
            margin-bottom: 6px;
        }
        #nc-module-root .nc-bo-subtabs > li > a {
            display: block;
            text-align: left;
        }
        #nc-module-root .nc-ia-onboarding-title {
            font-size: 23px;
        }
        #nc-module-root .nc-ia-onboarding-head {
            gap: 10px;
            padding: 16px 16px 12px;
        }
        #nc-module-root .nc-ia-onboarding-icon {
            width: 42px;
            height: 42px;
            font-size: 21px;
            border-radius: 12px;
        }
        #nc-module-root .nc-ia-onboarding-body {
            padding: 14px 16px 18px;
        }
    }
    </style>

    <div class="nc-bo-hero">
        {if !empty($nc_brand_mark_url)}
            <img
                class="nc-bo-brand-mark"
                src="{$nc_brand_mark_url|escape:'html':'UTF-8'}"
                alt="NeuroCheckout"
            >
        {/if}
        <div class="nc-bo-heading-wrap">
            <span class="nc-bo-eyebrow">NeuroCheckout Connector</span>
            <h2 class="nc-bo-heading">{$nc_i18n.module_heading|escape:'html':'UTF-8'}</h2>
        </div>
    </div>

    {if !empty($nc_save_success)}
        <div class="alert alert-success nc-bo-alert" role="status">
            {$nc_i18n.config_saved|default:'Configuration sauvegardee.'|escape:'html':'UTF-8'}
        </div>
    {/if}

    <ul class="nav nav-tabs nc-bo-main-tabs" role="tablist">
        <li class="active">
            <a href="#agent_abandon" data-toggle="tab">
                🤖 {$nc_i18n.main_tab_abandon|escape:'html':'UTF-8'}
            </a>
        </li>
    </ul>

    <div class="tab-content nc-bo-main-content">
        <div class="tab-pane active nc-bo-surface" id="agent_abandon">
            <ul class="nav nav-pills nc-bo-subtabs" role="tablist">
                <li class="active">
                    <a href="#ab_general" data-toggle="tab">
                        ⚙ {$nc_i18n.subtab_general|escape:'html':'UTF-8'}
                    </a>
                </li>
                <li>
                    <a href="#ab_cron" data-toggle="tab">
                        🕒 {$nc_i18n.subtab_cron|escape:'html':'UTF-8'}
                    </a>
                </li>
                <li>
                    <a href="#ab_ia" data-toggle="tab">
                        🤖 {$nc_i18n.subtab_ia|escape:'html':'UTF-8'}
                    </a>
                </li>
                <li>
                    <a href="#ab_monitoring" data-toggle="tab">
                        📊 {$nc_i18n.subtab_monitoring|escape:'html':'UTF-8'}
                    </a>
                </li>
            </ul>

            <div class="tab-content nc-bo-subtab-content">
                <div class="tab-pane active nc-bo-panel" id="ab_general">
                    {$general_content nofilter}
                </div>
                <div class="tab-pane nc-bo-panel" id="ab_cron">
                    {$execution_content nofilter}
                </div>
                <div class="tab-pane nc-bo-panel" id="ab_ia">
                    {$ia_content nofilter}
                </div>
                <div class="tab-pane nc-bo-panel" id="ab_monitoring">
                    {$monitoring_content nofilter}
                </div>
            </div>
        </div>
    </div>

    <div id="nc-ia-onboarding-popup"
         class="nc-ia-onboarding-modal"
         role="dialog"
         aria-modal="true"
         aria-hidden="true"
         aria-labelledby="nc-ia-onboarding-title">
        <div class="nc-ia-onboarding-card">
            <button type="button"
                    id="nc-ia-onboarding-close-icon"
                    class="nc-ia-onboarding-close-icon"
                    aria-label="Fermer le message">
                ×
            </button>
            <div class="nc-ia-onboarding-head">
                <span class="nc-ia-onboarding-icon" aria-hidden="true">🤖</span>
                <div class="nc-ia-onboarding-head-copy">
                    <span class="nc-ia-onboarding-badge">
                        ⚠ {$nc_i18n.ia_onboarding_popup_badge|escape:'html':'UTF-8'}
                    </span>
                    <h3 id="nc-ia-onboarding-title" class="nc-ia-onboarding-title">
                        {$nc_i18n.ia_onboarding_popup_title|escape:'html':'UTF-8'}
                    </h3>
                </div>
            </div>
            <div class="nc-ia-onboarding-body">
                <p class="nc-ia-onboarding-text">
                    {$nc_i18n.ia_onboarding_popup_body|escape:'html':'UTF-8'}
                </p>
                <ul class="nc-ia-onboarding-checklist">
                    <li>
                        <span class="nc-ia-onboarding-check" aria-hidden="true">✓</span>
                        {$nc_i18n.min_cart_total_label|escape:'html':'UTF-8'}
                    </li>
                    <li>
                        <span class="nc-ia-onboarding-check" aria-hidden="true">✓</span>
                        {$nc_i18n.max_discount_percent_label|escape:'html':'UTF-8'}
                    </li>
                </ul>
                <div class="nc-ia-onboarding-actions">
                    <button type="button"
                            id="nc-ia-onboarding-go"
                            class="btn btn-primary">
                        🤖 {$nc_i18n.ia_onboarding_popup_go_to_ia|escape:'html':'UTF-8'}
                    </button>
                    <button type="button"
                            id="nc-ia-onboarding-close"
                            class="btn btn-default">
                        {$nc_i18n.ia_onboarding_popup_later|escape:'html':'UTF-8'}
                    </button>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const ajaxUrl = "{$ajax_save_url|escape:'javascript'}".replace(/&amp;/g, "&");
    const root = document.getElementById("nc-module-root");
    const iaOnboardingPopup = document.getElementById("nc-ia-onboarding-popup");
    const iaOnboardingGoButton = document.getElementById("nc-ia-onboarding-go");
    const iaOnboardingCloseButton = document.getElementById("nc-ia-onboarding-close");
    const iaOnboardingCloseIconButton = document.getElementById("nc-ia-onboarding-close-icon");
    if (!root) {
        return;
    }

    const forms = root.querySelectorAll("form.nc-config-form");
    const networkErrorMessage = "{$nc_i18n.network_error|escape:'javascript'}";
    const activeSubtabStorageKey = "neurocheckoutconnector_active_subtab";
    const toastId = "nc-connector-toast";

    forms.forEach(form => {
        if (form.dataset.ncBound === "1") {
            return;
        }
        form.dataset.ncBound = "1";

        form.addEventListener("submit", function (e) {
            e.preventDefault();

            if (typeof form.reportValidity === "function" && !form.reportValidity()) {
                return;
            }

            const formData = new FormData(form);
            formData.set("ajax", "1");
            formData.set("action", "saveConfig");

            const submitWithoutAjax = () => {
                form.dataset.ncNativeSubmit = "1";
                if (typeof form.submit === "function") {
                    form.submit();
                    return;
                }
                HTMLFormElement.prototype.submit.call(form);
            };

            fetch(ajaxUrl, {
                method: "POST",
                body: formData,
                credentials: "same-origin"
            })
            .then(response => response.text().then(text => {
                let data = {};

                try {
                    data = text ? JSON.parse(text) : {};
                } catch (error) {
                    if (response.ok) {
                        submitWithoutAjax();
                        return new Promise(() => {});
                    }
                    throw new Error(
                        networkErrorMessage
                        + " Reponse serveur invalide"
                        + (response.status ? " (HTTP " + response.status + ")" : "")
                    );
                }

                if (!response.ok) {
                    throw new Error(
                        data.message
                        || data.error
                        || data.detail
                        || (networkErrorMessage + " HTTP " + response.status)
                    );
                }

                return data;
            }))
            .then(data => {
                if (data.success) {
                    showNotification(data.message, "success");

                    localStorage.setItem(
                        activeSubtabStorageKey,
                        getActiveSubTab()
                    );

                    const isCronExecutionForm =
                        !!form.querySelector('input[name="NC_DEBUG_MODE"]') ||
                        !!form.querySelector('input[name="NC_DEBUG_ADVANCED"]');

                    if (isCronExecutionForm) {
                        setTimeout(() => window.location.reload(), 150);
                    }
                } else {
                    showNotification(data.message || networkErrorMessage, "error");
                }
            })
            .catch(error => {
                showNotification(
                    error && error.message ? error.message : networkErrorMessage,
                    "error"
                );
            });
        });
    });

    const savedTab = localStorage.getItem(activeSubtabStorageKey);
    if (savedTab) {
        const trigger = root.querySelector('a[href="' + savedTab + '"]');
        if (trigger) {
            trigger.click();
        }
    }

    let iaPopupDismissed = false;

    function parseNumericValue(input) {
        if (!input) {
            return null;
        }

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

    function isIaConfigurationCompleteForPopup() {
        if (
            window.NeuroCheckoutConnector
            && typeof window.NeuroCheckoutConnector.isIaConfigurationComplete === "function"
        ) {
            return Boolean(window.NeuroCheckoutConnector.isIaConfigurationComplete());
        }

        if (typeof window.ncIsIaConfigurationComplete === "function") {
            return Boolean(window.ncIsIaConfigurationComplete());
        }

        const minCartInput = document.querySelector('[name="NC_MIN_CART_TOTAL"]');
        const maxDiscountInput = document.querySelector('[name="NC_MAX_DISCOUNT_PERCENT"]');
        const recoveryInput = document.querySelector('[name="NC_RECOVERY_ENABLED"]');

        const minCart = parseNumericValue(minCartInput);
        const maxDiscount = parseNumericValue(maxDiscountInput);

        const hasRecovery = !recoveryInput || String(recoveryInput.value || "0") === "1";
        const hasMinCart = minCart !== null && minCart >= 0;
        const hasMaxDiscount = maxDiscount !== null && maxDiscount >= 0 && maxDiscount <= 100;

        return hasRecovery && hasMinCart && hasMaxDiscount;
    }

    function showIaOnboardingPopup() {
        if (!iaOnboardingPopup) {
            return;
        }

        iaOnboardingPopup.style.display = "flex";
        iaOnboardingPopup.setAttribute("aria-hidden", "false");
    }

    function hideIaOnboardingPopup() {
        if (!iaOnboardingPopup) {
            return;
        }

        iaOnboardingPopup.style.display = "none";
        iaOnboardingPopup.setAttribute("aria-hidden", "true");
    }

    function evaluateIaOnboardingPopup(allowShow) {
        if (!iaOnboardingPopup) {
            return;
        }

        if (isIaConfigurationCompleteForPopup()) {
            hideIaOnboardingPopup();
            return;
        }

        if (allowShow && !iaPopupDismissed) {
            showIaOnboardingPopup();
        }
    }

    function openIaTab() {
        const iaTrigger = root.querySelector('a[href="#ab_ia"]');
        if (iaTrigger) {
            iaTrigger.click();
        }
    }

    if (iaOnboardingGoButton) {
        iaOnboardingGoButton.addEventListener("click", function () {
            iaPopupDismissed = true;
            hideIaOnboardingPopup();
            openIaTab();
        });
    }

    if (iaOnboardingCloseButton) {
        iaOnboardingCloseButton.addEventListener("click", function () {
            iaPopupDismissed = true;
            hideIaOnboardingPopup();
        });
    }

    if (iaOnboardingCloseIconButton) {
        iaOnboardingCloseIconButton.addEventListener("click", function () {
            iaPopupDismissed = true;
            hideIaOnboardingPopup();
        });
    }

    if (iaOnboardingPopup) {
        iaOnboardingPopup.addEventListener("click", function (event) {
            if (event.target !== iaOnboardingPopup) {
                return;
            }
            iaPopupDismissed = true;
            hideIaOnboardingPopup();
        });
    }

    document.addEventListener("input", function (event) {
        const name = event && event.target && event.target.name
            ? String(event.target.name)
            : "";
        if (name === "NC_MIN_CART_TOTAL" || name === "NC_MAX_DISCOUNT_PERCENT" || name === "NC_RECOVERY_ENABLED") {
            evaluateIaOnboardingPopup(false);
        }
    }, true);

    document.addEventListener("change", function (event) {
        const name = event && event.target && event.target.name
            ? String(event.target.name)
            : "";
        if (name === "NC_MIN_CART_TOTAL" || name === "NC_MAX_DISCOUNT_PERCENT" || name === "NC_RECOVERY_ENABLED") {
            evaluateIaOnboardingPopup(false);
        }
    }, true);

    setTimeout(function () {
        evaluateIaOnboardingPopup(true);
    }, 0);

    function getActiveSubTab() {
        const active = root.querySelector(
            "#agent_abandon .nav-pills li.active a"
        );
        return active ? active.getAttribute("href") : null;
    }

    function showNotification(message, type) {
        const existing = document.getElementById(toastId);
        if (existing) {
            existing.remove();
        }

        const div = document.createElement("div");
        div.id = toastId;
        div.className = "nc-bo-toast";
        div.setAttribute("role", "status");
        div.setAttribute("aria-live", "polite");
        div.innerText = message;
        div.style.position = "fixed";
        div.style.top = "20px";
        div.style.right = "20px";
        div.style.zIndex = "2147483000";
        div.style.background = type === "success" ? "#28a745" : "#dc3545";

        document.body.appendChild(div);
        setTimeout(() => div.remove(), 3000);
    }
});
</script>

<form class="nc-config-form" method="post">
<input type="hidden" name="submitNcSaveConfig" value="1">

<div class="panel nc-bo-card">
    <h3 class="nc-bo-section-title">⚙ {$nc_i18n.general_title|escape:'html':'UTF-8'}</h3>

    <div class="form-group">
        <label>{$nc_i18n.api_endpoint_label|escape:'html':'UTF-8'}</label>
        <input type="text"
               name="NC_API_ENDPOINT"
               value="{$nc_api_endpoint|escape:'html'}"
               class="form-control"
               required>
    </div>

    <div class="form-group">
        <label>{$nc_i18n.api_key_label|escape:'html':'UTF-8'}</label>
        <input type="password"
               name="NC_API_KEY"
               value=""
               placeholder="{if $nc_api_key_configured}{$nc_i18n.api_key_configured_placeholder|escape:'html':'UTF-8'}{/if}"
               class="form-control"
               autocomplete="off">
        {if $nc_api_key_configured}
        <p class="help-block" style="margin-top:8px;">
            {$nc_i18n.api_key_configured_help|escape:'html':'UTF-8'}
        </p>
        {/if}
    </div>

    <div class="form-group">
        <label>{$nc_i18n.shop_external_id_label|escape:'html':'UTF-8'}</label>
        <input type="text"
               name="NC_SHOP_EXTERNAL_ID"
               value="{$nc_shop_external_id|escape:'html'}"
               class="form-control"
               required>
    </div>

    <div class="form-group">
        <input type="hidden"
               name="NC_OPAQUE_RECOVERY_LINKS"
               value="0">
        <label>
            <input type="checkbox"
                   name="NC_OPAQUE_RECOVERY_LINKS"
                   value="1"
                   {if $nc_opaque_recovery_links}checked{/if}>
            {$nc_i18n.opaque_recovery_links_label|escape:'html':'UTF-8'}
        </label>
        <p class="help-block" style="margin-top:8px;">
            {$nc_i18n.opaque_recovery_links_help|escape:'html':'UTF-8'}
        </p>
    </div>

    <hr>

    <div id="nc-ia-required-notice"
         class="alert alert-warning nc-bo-alert">
        {$nc_i18n.ia_setup_required_notice|escape:'html':'UTF-8'}
    </div>

    {if $api_test_gate_required}
    <div id="nc-api-test-gate-notice"
         class="alert alert-warning nc-bo-alert">
        {$nc_i18n.api_test_gate_required_notice|escape:'html':'UTF-8'}
    </div>
    {else}
    <div id="nc-api-test-gate-notice"
         class="alert alert-success nc-bo-alert">
        {$nc_i18n.api_test_gate_ready_notice|escape:'html':'UTF-8'}
        {if $api_test_validated_at_display|default:'' != ''}
        <br>
        <small>
            {$nc_i18n.api_test_gate_validated_at_label|escape:'html':'UTF-8'}:
            {$api_test_validated_at_display|escape:'html':'UTF-8'}
        </small>
        {/if}
    </div>
    {/if}

    <div class="nc-bo-actions">
        <button type="button"
                id="nc-test-api"
                class="btn btn-default"
                data-url="{$apitest_url}">
            🧪 {$nc_i18n.test_api_button|escape:'html':'UTF-8'}
        </button>

        <button type="submit"
                class="btn btn-primary">
            💾 {$nc_i18n.save_button|escape:'html':'UTF-8'}
        </button>
    </div>

    <div id="nc-test-result"
         class="nc-bo-result"
         role="alert"
         aria-live="polite"
         style="display:none;margin-top:10px;white-space:pre-line;"></div>

    <div class="nc-bo-json-title">
        <span style="display:inline-block;width:12px;height:12px;border-radius:50%;background:#2b7bb9;"></span>
        <strong>{$nc_i18n.minimal_json_label|escape:'html':'UTF-8'}</strong>
    </div>
    <pre id="nc-test-result-json"
         class="nc-bo-json-output"
         style="display:none;"></pre>

</div>

</form>

<script>
(function () {
    function initNcApiTestFallback() {
        var btn = document.getElementById('nc-test-api');
        var result = document.getElementById('nc-test-result');
        var resultJson = document.getElementById('nc-test-result-json');
        var labels = window.ncApiTestI18n || {};

        if (!btn || !result) {
            return;
        }

        if (btn.dataset.ncApiTestBound === '1') {
            return;
        }
        btn.dataset.ncApiTestBound = '1';

        function t(key, fallback) {
            return labels[key] || fallback;
        }

        function render(type, message) {
            var classMap = {
                info: 'alert alert-info',
                success: 'alert alert-success',
                warning: 'alert alert-warning',
                error: 'alert alert-danger'
            };

            if (!message) {
                result.style.display = 'none';
                result.className = '';
                result.textContent = '';
                return;
            }

            result.style.display = 'block';
            result.className = classMap[type] || classMap.info;
            result.textContent = message || '';
            result.style.whiteSpace = 'pre-line';
        }

        function showJson(payload, isError) {
            if (!resultJson) {
                return;
            }
            if (!payload) {
                resultJson.style.display = 'none';
                resultJson.textContent = '';
                return;
            }

            resultJson.style.display = 'block';
            resultJson.style.borderColor = isError ? '#e38d8d' : '#ddd';
            resultJson.style.background = isError ? '#fff5f5' : '#f8f8f8';

            var source = (payload && typeof payload === 'object') ? payload : {};
            var rawStatus = (source.http_status !== undefined && source.http_status !== null)
                ? source.http_status
                : (source.status !== undefined ? source.status : null);
            var rawDuration = (source.duration_ms !== undefined && source.duration_ms !== null)
                ? source.duration_ms
                : null;
            var normalized = {
                success: (typeof source.success === 'boolean') ? source.success : null,
                http_status: Number.isFinite(Number(rawStatus)) ? Number(rawStatus) : null,
                duration_ms: Number.isFinite(Number(rawDuration)) ? Number(rawDuration) : null
            };
            try {
                resultJson.textContent = JSON.stringify(normalized, null, 2);
            } catch (e) {
                resultJson.textContent = String(normalized);
            }
        }

        function template(message, params) {
            var out = message;
            var openBrace = String.fromCharCode(123);
            var closeBrace = String.fromCharCode(125);
            Object.keys(params || {}).forEach(function (key) {
                out = out.split(openBrace + key + closeBrace).join(String(params[key]));
            });
            return out;
        }

        function refreshApiTestGateNotice(validated) {
            var gateNotice = document.getElementById('nc-api-test-gate-notice');
            if (!gateNotice) {
                return;
            }

            if (validated) {
                gateNotice.className = 'alert alert-success';
                gateNotice.textContent = "{$nc_i18n.api_test_gate_ready_notice|escape:'javascript'}";
                return;
            }

            gateNotice.className = 'alert alert-warning';
            gateNotice.textContent = "{$nc_i18n.api_test_gate_required_notice|escape:'javascript'}";
        }

        function reflectCronModeSelection(minutes) {
            var cronRadio = document.querySelector('input[name="NC_EXECUTION_MODE"][value="cron_module"]')
                || document.querySelector('input[name="NC_EXECUTION_MODE"][value="cron"]');
            if (cronRadio) {
                cronRadio.checked = true;
                if (typeof cronRadio.dispatchEvent === 'function') {
                    cronRadio.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }

            var scheduleValue = document.getElementById('nc-cron-schedule-expression-value');
            if (scheduleValue && Number.isFinite(minutes) && minutes > 0) {
                scheduleValue.textContent = '*/' + String(minutes) + ' * * * *';
            }
        }

        function isIaConfigurationComplete() {
            if (
                window.NeuroCheckoutConnector
                && typeof window.NeuroCheckoutConnector.isIaConfigurationComplete === 'function'
            ) {
                return Boolean(window.NeuroCheckoutConnector.isIaConfigurationComplete());
            }

            if (typeof window.ncIsIaConfigurationComplete === 'function') {
                return Boolean(window.ncIsIaConfigurationComplete());
            }

            var minCartInput = document.querySelector('[name="NC_MIN_CART_TOTAL"]');
            var maxDiscountInput = document.querySelector('[name="NC_MAX_DISCOUNT_PERCENT"]');
            var recoveryCheckbox = document.querySelector('[name="NC_RECOVERY_ENABLED"]');

            function parseNumericValue(input) {
                if (!input) {
                    return null;
                }
                var raw = String(input.value || '').trim();
                if (raw === '') {
                    return null;
                }
                var value = Number(raw);
                return Number.isFinite(value) ? value : null;
            }

            var minCartTotal = parseNumericValue(minCartInput);
            var maxDiscountPercent = parseNumericValue(maxDiscountInput);

            var hasRecovery = !recoveryCheckbox
                || (
                    typeof recoveryCheckbox.checked === 'boolean' && recoveryCheckbox.type !== 'hidden'
                        ? Boolean(recoveryCheckbox.checked)
                        : String(recoveryCheckbox.value || '0') === '1'
                );
            var hasMinCart = minCartTotal !== null && minCartTotal >= 0;
            var hasMaxDiscount = maxDiscountPercent !== null && maxDiscountPercent >= 0 && maxDiscountPercent <= 100;

            return hasRecovery && hasMinCart && hasMaxDiscount;
        }

        function syncApiTestButtonState() {
            var ready = isIaConfigurationComplete();

            btn.disabled = !ready;
            btn.setAttribute('aria-disabled', ready ? 'false' : 'true');

            if (!ready) {
                btn.classList.add('disabled');
                btn.setAttribute(
                    'title',
                    t(
                        'ia_setup_required_popup',
                        'Complete the required settings in the AI tab before continuing.'
                    )
                );
                return;
            }

            btn.classList.remove('disabled');
            btn.removeAttribute('title');
        }

        function bindIaWatchers() {
            var watchedNames = {
                NC_RECOVERY_ENABLED: true,
                NC_MIN_CART_TOTAL: true,
                NC_MAX_DISCOUNT_PERCENT: true
            };

            var onPotentialIaChange = function (event) {
                var target = event && event.target;
                var name = target && target.name ? String(target.name) : '';
                if (!watchedNames[name]) {
                    return;
                }
                syncApiTestButtonState();
            };

            document.addEventListener('input', onPotentialIaChange, true);
            document.addEventListener('change', onPotentialIaChange, true);

            var iaTabTrigger = document.querySelector('a[href="#ab_ia"]');
            if (iaTabTrigger && typeof iaTabTrigger.addEventListener === 'function') {
                iaTabTrigger.addEventListener('click', function () {
                    setTimeout(syncApiTestButtonState, 0);
                });
            }
        }

        function notifyIaConfigurationRequired() {
            var message = t(
                'ia_setup_required_popup',
                'Complete the required settings in the AI tab before continuing.'
            );

            if (typeof window.alert === 'function') {
                window.alert(message);
            }

            render('error', message);

            if (
                window.NeuroCheckoutConnector
                && typeof window.NeuroCheckoutConnector.refreshIaRequirementNotice === 'function'
            ) {
                window.NeuroCheckoutConnector.refreshIaRequirementNotice();
            } else if (typeof window.ncRefreshIaRequirementNotice === 'function') {
                window.ncRefreshIaRequirementNotice();
            }

            var iaTabTrigger = document.querySelector('a[href="#ab_ia"]');
            if (iaTabTrigger && typeof iaTabTrigger.click === 'function') {
                iaTabTrigger.click();
            }
        }

        btn.addEventListener('click', function (event) {
            event.preventDefault();

            if (!isIaConfigurationComplete()) {
                notifyIaConfigurationRequired();
                return;
            }

            var url = btn.getAttribute('data-url') || '';
            if (!url) {
                render('error', "URL de test API manquante.");
                showJson({
                    success: false,
                    http_status: null,
                    duration_ms: null
                }, true);
                return;
            }

            render('info', "Test en cours...");
            showJson({
                success: null,
                http_status: null,
                duration_ms: null
            }, false);

            fetch(url, {
                method: 'GET',
                headers: {
                    Accept: 'application/json'
                },
                credentials: 'same-origin'
            })
            .then(function (response) {
                return response.json().catch(function () {
                    return {};
                }).then(function (data) {
                    if (!response.ok) {
                        throw {
                            error: data.error || data.detail || ('HTTP ' + response.status),
                            http_status: response.status,
                            payload: data
                        };
                    }
                    return data;
                });
            })
            .then(function (data) {
                showJson(data, !data.success);
                if (data && data.success) {
                    refreshApiTestGateNotice(true);
                    if (data.cron_mode_activated) {
                        var minutes = Number(data.cron_interval_minutes) || 5;
                        var successPattern = t('api_test_success_cron_enabled', '');
                        var successMessage = template(
                            successPattern || ('Connection successful. Module cron mode enabled (' + String(minutes) + ' min).'),
                            { minutes: minutes }
                        );

                        reflectCronModeSelection(minutes);

                        if (data.cron_setup_message) {
                            render('success', successMessage + '\n' + data.cron_setup_message);
                            return;
                        }

                        render('success', successMessage);
                        return;
                    }

                    render('success', (data.error || data.message || t('api_test_success', 'Connexion reussie')));
                } else {
                    refreshApiTestGateNotice(false);
                    render('error', (data && (data.error || data.detail)) ? (data.error || data.detail) : 'Erreur API');
                }
            })
            .catch(function (err) {
                var message = (err && (err.error || err.detail || err.message)) ? (err.error || err.detail || err.message) : 'Erreur API';
                render('error', message);
                refreshApiTestGateNotice(false);
                showJson({
                    success: false,
                    http_status: err && err.http_status ? err.http_status : null,
                    duration_ms: null
                }, true);
            });
        });

        bindIaWatchers();
        syncApiTestButtonState();
    }

    function bootApiTestHandler(attempt) {
        if (typeof window.initNcApiTest === 'function') {
            window.initNcApiTest();
            return;
        }

        if (attempt < 20) {
            setTimeout(function () {
                bootApiTestHandler(attempt + 1);
            }, 50);
            return;
        }

        initNcApiTestFallback();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            bootApiTestHandler(0);
        });
    } else {
        bootApiTestHandler(0);
    }
})();
</script>

function initNcApiTest() {
    const btn = document.getElementById('nc-test-api');
    const result = document.getElementById('nc-test-result');
    const resultJson = document.getElementById('nc-test-result-json');
    const labels = window.ncApiTestI18n || {};

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
        const classMap = {
            info: 'alert alert-info',
            success: 'alert alert-success',
            warning: 'alert alert-warning',
            error: 'alert alert-danger',
        };

        if (!message) {
            result.style.display = 'none';
            result.textContent = '';
            result.className = '';
            return;
        }

        result.style.display = 'block';
        result.className = classMap[type] || classMap.info;
        result.textContent = message;
        result.style.whiteSpace = 'pre-line';
    }

    function showJson(payload, forceError) {
        if (!resultJson) {
            return;
        }

        if (!payload) {
            resultJson.style.display = 'none';
            resultJson.textContent = '';
            return;
        }

        resultJson.style.display = 'block';
        resultJson.style.borderColor = forceError ? '#e38d8d' : '#ddd';
        resultJson.style.background = forceError ? '#fff5f5' : '#f8f8f8';

        const source = (payload && typeof payload === 'object') ? payload : {};
        const rawStatus = source.http_status ?? source.status ?? null;
        const rawDuration = source.duration_ms ?? null;

        const normalized = {
            success: typeof source.success === 'boolean' ? source.success : null,
            http_status: Number.isFinite(Number(rawStatus)) ? Number(rawStatus) : null,
            duration_ms: Number.isFinite(Number(rawDuration)) ? Number(rawDuration) : null,
        };

        try {
            resultJson.textContent = JSON.stringify(normalized, null, 2);
        } catch (e) {
            resultJson.textContent = String(normalized);
        }
    }

    function template(message, params) {
        let out = message;
        Object.keys(params || {}).forEach(function (key) {
            out = out.replace(new RegExp('\\{' + key + '\\}', 'g'), String(params[key]));
        });
        return out;
    }

    function refreshApiTestGateNotice(validated) {
        const gateNotice = document.getElementById('nc-api-test-gate-notice');
        if (!gateNotice) {
            return;
        }

        if (validated) {
            gateNotice.className = 'alert alert-success';
            gateNotice.textContent = t(
                'api_test_gate_ready_notice',
                'API test validated. Cron and event processing are authorized.'
            );
            return;
        }

        gateNotice.className = 'alert alert-warning';
        gateNotice.textContent = t(
            'api_test_gate_required_notice',
            'Action required: click "Test API" successfully to activate cron and event processing.'
        );
    }

    function reflectCronModeSelection(minutes) {
        const cronRadio =
            document.querySelector('input[name="NC_EXECUTION_MODE"][value="cron_module"]')
            || document.querySelector('input[name="NC_EXECUTION_MODE"][value="cron"]');
        if (cronRadio) {
            cronRadio.checked = true;
        }

        const scheduleValue = document.getElementById('nc-cron-schedule-expression-value');
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

        const minCartInput = document.querySelector('[name="NC_MIN_CART_TOTAL"]');
        const maxDiscountInput = document.querySelector('[name="NC_MAX_DISCOUNT_PERCENT"]');
        const recoveryCheckbox = document.querySelector('[name="NC_RECOVERY_ENABLED"]');

        const parseNumericValue = function (input) {
            if (!input) {
                return null;
            }
            const raw = String(input.value || '').trim();
            if (raw === '') {
                return null;
            }
            const value = Number(raw);
            return Number.isFinite(value) ? value : null;
        };

        const minCartTotal = parseNumericValue(minCartInput);
        const maxDiscountPercent = parseNumericValue(maxDiscountInput);

        const hasRecovery = !recoveryCheckbox
            || (typeof recoveryCheckbox.checked === 'boolean' && recoveryCheckbox.type !== 'hidden'
                ? Boolean(recoveryCheckbox.checked)
                : String(recoveryCheckbox.value || '0') === '1');
        const hasMinCart = minCartTotal !== null && minCartTotal >= 0;
        const hasMaxDiscount = maxDiscountPercent !== null && maxDiscountPercent >= 0 && maxDiscountPercent <= 100;

        return hasRecovery && hasMinCart && hasMaxDiscount;
    }

    function syncApiTestButtonState() {
        const ready = isIaConfigurationComplete();

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
        const watchedNames = new Set([
            'NC_RECOVERY_ENABLED',
            'NC_MIN_CART_TOTAL',
            'NC_MAX_DISCOUNT_PERCENT',
        ]);

        const onPotentialIaChange = function (event) {
            const target = event && event.target;
            const name = target && target.name ? String(target.name) : '';
            if (!watchedNames.has(name)) {
                return;
            }
            syncApiTestButtonState();
        };

        document.addEventListener('input', onPotentialIaChange, true);
        document.addEventListener('change', onPotentialIaChange, true);

        const iaTabTrigger = document.querySelector('a[href="#ab_ia"]');
        if (iaTabTrigger && typeof iaTabTrigger.addEventListener === 'function') {
            iaTabTrigger.addEventListener('click', function () {
                setTimeout(syncApiTestButtonState, 0);
            });
        }
    }

    function notifyIaConfigurationRequired() {
        const message = t(
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

        const iaTabTrigger = document.querySelector('a[href="#ab_ia"]');
        if (iaTabTrigger && typeof iaTabTrigger.click === 'function') {
            iaTabTrigger.click();
        }
    }

    btn.addEventListener('click', function (e) {
        e.preventDefault();

        if (!isIaConfigurationComplete()) {
            notifyIaConfigurationRequired();
            return;
        }

        render('info', t('api_test_in_progress', 'Test in progress...'));
        showJson({
            success: null,
            http_status: null,
            duration_ms: null,
        }, false);

        fetch(btn.dataset.url, {
            method: 'GET',
            headers: {
                Accept: 'application/json',
            },
        })
            .then(function (response) {
                if (!response.ok) {
                    return response.json().catch(function () {
                        return {};
                    }).then(function (err) {
                        throw {
                            error: err.error || err.detail || t('api_test_error_default', 'API error'),
                            http_status: response.status,
                        };
                    });
                }

                return response.json();
            })
            .then(function (data) {
                showJson(data, !data.success);

                if (data.success) {
                    refreshApiTestGateNotice(true);
                    if (data.cron_mode_activated) {
                        const minutes = Number(data.cron_interval_minutes) || 5;
                        const successMessage = template(
                            t(
                                'api_test_success_cron_enabled',
                                'Connection successful. Module cron mode enabled ({minutes} min).'
                            ),
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

                    render('success', t('api_test_success', 'Connection successful'));
                    return;
                }

                refreshApiTestGateNotice(false);
                render('error', data.error || t('api_test_error_default', 'API error'));
            })
            .catch(function (error) {
                const message = error && (error.error || error.detail || error.message)
                    ? (error.error || error.detail || error.message)
                    : t('api_test_error_default', 'API error');

                render('error', message);
                refreshApiTestGateNotice(false);
                showJson({
                    success: false,
                    http_status: error && error.http_status ? error.http_status : null,
                    duration_ms: null,
                }, true);
            });
    });

    bindIaWatchers();
    syncApiTestButtonState();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initNcApiTest);
} else {
    initNcApiTest();
}

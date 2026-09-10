<h4 class="nc-bo-section-title">🕒 {$nc_i18n.execution_title|escape:'html':'UTF-8'}</h4>

<div class="panel nc-bo-card">

    {if $health_score >= 80}
        {assign var="badgeColor" value="green"}
    {elseif $health_score >= 50}
        {assign var="badgeColor" value="orange"}
    {else}
        {assign var="badgeColor" value="red"}
    {/if}

    {assign var="healthStatusKey" value="status_"|cat:$health_status}
    {assign var="healthStatusLabel" value=$nc_i18n[$healthStatusKey]|default:$health_status|upper}

    <p class="nc-bo-kpi-line">
        <span class="nc-bo-health-pill"
              style="background:{$badgeColor};">
            {$healthStatusLabel|escape:'html':'UTF-8'} — {$health_score}/100
        </span>
    </p>

    <p class="nc-bo-kpi-line">
        <strong>{$nc_i18n.pending_events_label|escape:'html':'UTF-8'} :</strong>
        {if $backlog > 0}
            <span class="nc-bo-kpi-warn">{$backlog} {$nc_i18n.pending_events_suffix|escape:'html':'UTF-8'}</span>
        {else}
            <span class="nc-bo-kpi-ok">{$nc_i18n.no_pending_events|escape:'html':'UTF-8'}</span>
        {/if}
    </p>

    <hr>

    <form method="post" class="nc-config-form">

        <div class="form-group nc-cron-mode-card"
             data-mode="cron_module">
            <label>
                <input type="radio"
                       name="NC_EXECUTION_MODE"
                       value="cron_module"
                       {if $execution_mode == 'cron_module'}checked{/if}>
                <strong>{$nc_i18n.auto_mode_label|escape:'html':'UTF-8'}</strong>
            </label>
            <p class="help-block nc-cron-mode-help">
                {$nc_i18n.auto_mode_help|escape:'html':'UTF-8'}
            </p>

            <div class="alert alert-info nc-bo-alert">
                <strong>{$nc_i18n.cron_runtime_env_label|escape:'html':'UTF-8'} :</strong>
                {if $runtime_env_mode == 'local'}
                    {$nc_i18n.cron_runtime_env_local|escape:'html':'UTF-8'}
                {else}
                    {$nc_i18n.cron_runtime_env_production|escape:'html':'UTF-8'}
                {/if}
            </div>
        </div>

        <div class="form-group nc-cron-mode-card"
             data-mode="cron">
            <label>
                <input type="radio"
                       name="NC_EXECUTION_MODE"
                       value="cron"
                       {if $execution_mode == 'cron'}checked{/if}>
                <strong>{$nc_i18n.cron_mode_label|escape:'html':'UTF-8'}</strong>
            </label>

            <div id="nc-server-cron-only" style="{if $execution_mode != 'cron'}display:none;{/if}">
                <div class="well nc-bo-well">
                    <strong>{$nc_i18n.cron_runner_command_label|escape:'html':'UTF-8'} :</strong><br>
                    <code style="word-break: break-all;">
                        {$cron_runner_command|escape:'html':'UTF-8'}
                    </code>
                    <p class="help-block" style="margin-top:8px;margin-bottom:0;">
                        {$nc_i18n.cron_runner_command_help|escape:'html':'UTF-8'}
                    </p>
                </div>

                <div class="well nc-bo-well">
                    <strong>{$nc_i18n.cron_schedule_expression_label|escape:'html':'UTF-8'} :</strong><br>
                    <code id="nc-cron-schedule-expression-value" style="word-break: break-all;">
                        {$cron_schedule_expression|escape:'html':'UTF-8'}
                    </code>
                </div>

                <div class="alert alert-info nc-bo-alert">
                    <strong>{$nc_i18n.cron_mode_why_label|escape:'html':'UTF-8'}</strong><br>
                    {$nc_i18n.cron_mode_why_body|escape:'html':'UTF-8'}<br><br>
                    <strong>{$nc_i18n.cron_mode_how_label|escape:'html':'UTF-8'}</strong><br>
                    {$nc_i18n.cron_mode_how_body|escape:'html':'UTF-8'}
                </div>

                <div class="form-group" style="margin-top:12px;">
                    <label for="nc-cron-allowed-ips">
                        <strong>{$nc_i18n.cron_allowed_ips_label|escape:'html':'UTF-8'}</strong>
                    </label>
                    <input type="text"
                           id="nc-cron-allowed-ips"
                           name="NC_CRON_ALLOWED_IPS"
                           value="{$cron_allowed_ips|escape:'html':'UTF-8'}"
                           class="form-control"
                           placeholder="203.0.113.10, 203.0.113.11, 10.0.0.0/24">
                    <p class="help-block" style="margin-top:8px;">
                        {$nc_i18n.cron_allowed_ips_help|escape:'html':'UTF-8'}
                    </p>
                </div>

                <div class="form-group" style="margin-top:12px;">
                    <label for="nc-trusted-proxy-ips">
                        <strong>{$nc_i18n.trusted_proxy_ips_label|escape:'html':'UTF-8'}</strong>
                    </label>
                    <input type="text"
                           id="nc-trusted-proxy-ips"
                           name="NC_TRUSTED_PROXY_IPS"
                           value="{$trusted_proxy_ips|escape:'html':'UTF-8'}"
                           class="form-control"
                           placeholder="173.245.48.0/20, 103.21.244.0/22">
                    <p class="help-block" style="margin-top:8px;">
                        {$nc_i18n.trusted_proxy_ips_help|escape:'html':'UTF-8'}
                    </p>
                </div>
            </div>
        </div>

        <p class="nc-bo-kpi-line nc-bo-muted-line">
            <strong>{$nc_i18n.last_run_label|escape:'html':'UTF-8'} :</strong>
            {$last_run|escape:'html':'UTF-8'}
        </p>

        <div class="form-group" style="margin-top:10px;">
            <input type="hidden"
                   name="NC_DEBUG_MODE"
                   value="0">
            <label>
                <input type="checkbox"
                       id="nc-debug-mode-checkbox"
                       name="NC_DEBUG_MODE"
                       value="1"
                       {if $debug_mode}checked{/if}>
                <strong>{$nc_i18n.debug_mode_label|escape:'html':'UTF-8'}</strong>
            </label>
            <p class="help-block" style="margin-top:8px;">
                {$nc_i18n.debug_mode_help|escape:'html':'UTF-8'}
            </p>
        </div>

        <div class="form-group" style="margin-top:10px;">
            <input type="hidden"
                   name="NC_DEBUG_ADVANCED"
                   value="0">
            <label>
                <input type="checkbox"
                       id="nc-debug-advanced-checkbox"
                       name="NC_DEBUG_ADVANCED"
                       value="1"
                       {if $debug_advanced}checked{/if}>
                <strong>{$nc_i18n.debug_advanced_label|escape:'html':'UTF-8'}</strong>
            </label>
            <p class="help-block" style="margin-top:8px;">
                {$nc_i18n.debug_advanced_help|escape:'html':'UTF-8'}
            </p>
        </div>

        <div id="nc-debug-exclusive-warning"
             class="alert alert-warning"
             style="margin-top:10px;display:none;"></div>

        <div class="nc-bo-actions">
            <button type="submit"
                    name="submitExecutionMode"
                    class="btn btn-primary">
                {$nc_i18n.save_button|escape:'html':'UTF-8'}
            </button>
        </div>

    </form>

    <hr>

    {if $debug_mode || $debug_advanced}
        {if $debug_mode}
            <h4 class="nc-bo-subsection-title">🔎 {$nc_i18n.test_cron_title|escape:'html':'UTF-8'}</h4>
            <div class="nc-bo-actions">
                <button type="button"
                        id="nc-test-cron"
                        class="btn btn-default">
                    {$nc_i18n.test_cron_button|escape:'html':'UTF-8'}
                </button>
            </div>
        {/if}

        {if $debug_advanced}
            <h4 class="nc-bo-subsection-title">⚡ {$nc_i18n.force_execution_title|escape:'html':'UTF-8'}</h4>
            <div class="nc-bo-actions">
                <button type="button"
                        id="nc-force-cron"
                        class="btn btn-warning">
                    {$nc_i18n.force_execution_button|escape:'html':'UTF-8'}
                </button>
            </div>

            <div id="nc-force-confirm-modal"
                 style="display:none;
                        position:fixed;
                        inset:0;
                        background:rgba(0,0,0,0.45);
                        z-index:10000;
                        align-items:center;
                        justify-content:center;">
                <div style="background:#fff;
                            width:92%;
                            max-width:520px;
                            border-radius:8px;
                            padding:18px;
                            box-shadow:0 8px 30px rgba(0,0,0,0.25);">
                    <h4 style="margin-top:0;">{$nc_i18n.confirmation_required_title|escape:'html':'UTF-8'}</h4>
                    <p style="margin-bottom:14px;">
                        {$nc_i18n.confirm_force_execution_body|escape:'html':'UTF-8'}
                    </p>
                    <button type="button"
                            id="nc-force-confirm-accept"
                            class="btn btn-warning">
                        {$nc_i18n.confirm_force_execution_accept|escape:'html':'UTF-8'}
                    </button>
                    <button type="button"
                            id="nc-force-confirm-cancel"
                            class="btn btn-default"
                            style="margin-left:8px;">
                        {$nc_i18n.cancel_button|escape:'html':'UTF-8'}
                    </button>
                </div>
            </div>
        {/if}

        <p class="nc-bo-json-title">
            <strong>{$nc_i18n.minimal_json_label|escape:'html':'UTF-8'}</strong>
        </p>

        <pre id="nc-cron-result"
             class="nc-bo-json-output"
             style="display:none;"></pre>
    {/if}

</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const debugModeCheckbox = document.getElementById("nc-debug-mode-checkbox");
    const debugAdvancedCheckbox = document.getElementById("nc-debug-advanced-checkbox");
    const debugExclusiveWarning = document.getElementById("nc-debug-exclusive-warning");
    const warningAdvancedActive = "{$nc_i18n.warning_debug_advanced_active|escape:'javascript'}";
    const warningDebugActive = "{$nc_i18n.warning_debug_mode_active|escape:'javascript'}";
    const executionInProgress = "{$nc_i18n.cron_execution_in_progress|escape:'javascript'}";
    const invalidJsonResponse = "{$nc_i18n.invalid_json_response|escape:'javascript'}";
    const serverCronOnly = document.getElementById("nc-server-cron-only");
    const serverCronRadio = document.querySelector('input[name="NC_EXECUTION_MODE"][value="cron"]');
    const executionModeRadios = document.querySelectorAll('input[name="NC_EXECUTION_MODE"]');
    const modeCards = document.querySelectorAll(".nc-cron-mode-card");
    let debugWarningTimer = null;

    function refreshServerCronVisibility() {
        if (!serverCronOnly || !serverCronRadio) {
            return;
        }

        serverCronOnly.style.display = serverCronRadio.checked ? "block" : "none";
    }

    function refreshModeCards() {
        if (!modeCards || modeCards.length === 0) {
            return;
        }

        modeCards.forEach(function (card) {
            const mode = card.getAttribute("data-mode");
            const radio = document.querySelector(
                'input[name="NC_EXECUTION_MODE"][value="' + mode + '"]'
            );
            const active = !!(radio && radio.checked);
            card.classList.toggle("is-selected", active);
        });
    }

    function setDebugExclusiveWarning(message) {
        if (!debugExclusiveWarning) {
            return;
        }

        if (debugWarningTimer) {
            clearTimeout(debugWarningTimer);
            debugWarningTimer = null;
        }

        if (!message) {
            debugExclusiveWarning.style.display = "none";
            debugExclusiveWarning.innerText = "";
            return;
        }

        debugExclusiveWarning.style.display = "block";
        debugExclusiveWarning.innerText = message;
        debugWarningTimer = setTimeout(function () {
            debugExclusiveWarning.style.display = "none";
            debugExclusiveWarning.innerText = "";
            debugWarningTimer = null;
        }, 3500);
    }

    function enforceExclusiveDebugMode(changedCheckbox, otherCheckbox, warningMessage) {
        if (!changedCheckbox || !otherCheckbox) {
            return;
        }

        if (changedCheckbox.checked && otherCheckbox.checked) {
            changedCheckbox.checked = false;
            setDebugExclusiveWarning(warningMessage);
            return;
        }

        setDebugExclusiveWarning("");
    }

    if (debugModeCheckbox && debugAdvancedCheckbox) {
        if (debugModeCheckbox.checked && debugAdvancedCheckbox.checked) {
            debugAdvancedCheckbox.checked = false;
        }

        debugModeCheckbox.addEventListener("change", function () {
            enforceExclusiveDebugMode(
                debugModeCheckbox,
                debugAdvancedCheckbox,
                warningAdvancedActive
            );
        });

        debugAdvancedCheckbox.addEventListener("change", function () {
            enforceExclusiveDebugMode(
                debugAdvancedCheckbox,
                debugModeCheckbox,
                warningDebugActive
            );
        });

        setDebugExclusiveWarning("");
    }

    executionModeRadios.forEach(function (radio) {
        radio.addEventListener("change", function () {
            refreshServerCronVisibility();
            refreshModeCards();
        });
    });
    refreshServerCronVisibility();
    refreshModeCards();

    const result = document.getElementById("nc-cron-result");
    if (!result) {
        return;
    }

    function toMinimalPayload(data, statusCode) {
        const out = {
            success: !!(data && data.success),
            status_code: statusCode,
            processed_events:
                data && typeof data.processed_events === "number"
                    ? data.processed_events
                    : undefined,
            execution_time_ms:
                data && typeof data.execution_time === "number"
                    ? data.execution_time
                    : undefined,
            test_mode:
                data && typeof data.test_mode === "boolean"
                    ? data.test_mode
                    : undefined,
            error: data && data.error ? data.error : undefined,
            timestamp: data && data.timestamp ? data.timestamp : undefined,
        };

        return Object.fromEntries(
            Object.entries(out).filter(([, value]) => value !== undefined)
        );
    }

    function runCron(url) {
        result.style.display = "block";
        result.style.color = "black";
        result.innerText = executionInProgress;

        fetch(url, {
            method: "POST",
            headers: {
                "X-Requested-With": "XMLHttpRequest",
            },
        })
        .then(async (response) => {
            let data = {};
            try {
                data = await response.json();
            } catch (e) {
                data = {
                    success: false,
                    error: invalidJsonResponse,
                };
            }

            const minimal = toMinimalPayload(data, response.status);
            result.style.color = minimal.success ? "green" : "red";
            result.innerText = JSON.stringify(minimal, null, 2);
        })
        .catch(e => {
            result.style.color = "red";
            result.innerText = JSON.stringify(
                {
                    success: false,
                    error: String(e),
                },
                null,
                2
            );
        });
    }

    document.getElementById("nc-test-cron")
        ?.addEventListener("click", function () {
            runCron("{$cron_test_ajax_url|escape:'javascript'}");
        });

    document.getElementById("nc-force-cron")
        ?.addEventListener("click", function () {
            const modal = document.getElementById("nc-force-confirm-modal");
            if (!modal) {
                return;
            }
            modal.style.display = "flex";
        });

    document.getElementById("nc-force-confirm-cancel")
        ?.addEventListener("click", function () {
            const modal = document.getElementById("nc-force-confirm-modal");
            if (!modal) {
                return;
            }
            modal.style.display = "none";
        });

    document.getElementById("nc-force-confirm-accept")
        ?.addEventListener("click", function () {
            const modal = document.getElementById("nc-force-confirm-modal");
            if (modal) {
                modal.style.display = "none";
            }
            runCron("{$cron_force_ajax_url|escape:'javascript'}");
        });

    document.getElementById("nc-force-confirm-modal")
        ?.addEventListener("click", function (event) {
            if (event.target !== this) {
                return;
            }
            this.style.display = "none";
        });
});
</script>

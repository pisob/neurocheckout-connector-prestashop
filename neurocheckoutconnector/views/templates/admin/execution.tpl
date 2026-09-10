<h4 class="nc-bo-section-title">🩺 {$nc_i18n.system_health_title|escape:'html':'UTF-8'}</h4>

{if $health_score >= 80}
    {assign var="badgeColor" value="green"}
{elseif $health_score >= 50}
    {assign var="badgeColor" value="orange"}
{else}
    {assign var="badgeColor" value="red"}
{/if}

{assign var="healthStatusKey" value="status_"|cat:$health_status}
{assign var="healthStatusLabel" value=$nc_i18n[$healthStatusKey]|default:$health_status|upper}
{assign var="breakerStateKey" value="breaker_"|cat:$breaker_state}
{assign var="breakerStateLabel" value=$nc_i18n[$breakerStateKey]|default:$breaker_state|upper}

<div class="panel nc-bo-card">
    <p class="nc-bo-kpi-line">
        <span class="nc-bo-health-pill"
              style="background:{$badgeColor};">
            {$healthStatusLabel|escape:'html':'UTF-8'} — {$health_score}/100
        </span>
    </p>

    <div class="nc-bo-health-bar-wrap">
        <div class="nc-bo-health-bar"
             style="width:{$health_score}%;background:{$badgeColor};"></div>
    </div>

    <div class="row nc-bo-metrics-grid">
        <div class="col-sm-6 col-md-3">
            <div class="nc-bo-metric-card">
                <span class="nc-bo-metric-label">{$nc_i18n.queue_label|escape:'html':'UTF-8'}</span>
                {if $backlog > 0}
                    <span class="nc-bo-metric-value nc-bo-kpi-warn">
                        {$backlog} {$nc_i18n.queue_events_suffix|escape:'html':'UTF-8'}
                    </span>
                {else}
                    <span class="nc-bo-metric-value nc-bo-kpi-ok">
                        {$nc_i18n.queue_waiting_none|escape:'html':'UTF-8'}
                    </span>
                {/if}
            </div>
        </div>
        <div class="col-sm-6 col-md-3">
            <div class="nc-bo-metric-card">
                <span class="nc-bo-metric-label">{$nc_i18n.error_rate_label|escape:'html':'UTF-8'}</span>
                <span class="nc-bo-metric-value">{$error_rate}%</span>
            </div>
        </div>
        <div class="col-sm-6 col-md-3">
            <div class="nc-bo-metric-card">
                <span class="nc-bo-metric-label">{$nc_i18n.avg_latency_label|escape:'html':'UTF-8'}</span>
                <span class="nc-bo-metric-value">{$latency} ms</span>
            </div>
        </div>
        <div class="col-sm-6 col-md-3">
            <div class="nc-bo-metric-card">
                <span class="nc-bo-metric-label">{$nc_i18n.circuit_breaker_label|escape:'html':'UTF-8'}</span>
                {if $breaker_state == 'open'}
                    <span class="nc-bo-metric-value" style="color:#b92c28;">{$breakerStateLabel|escape:'html':'UTF-8'}</span>
                {elseif $breaker_state == 'half_open'}
                    <span class="nc-bo-metric-value nc-bo-kpi-warn">{$breakerStateLabel|escape:'html':'UTF-8'}</span>
                {elseif $breaker_state == 'closed'}
                    <span class="nc-bo-metric-value nc-bo-kpi-ok">{$breakerStateLabel|escape:'html':'UTF-8'}</span>
                {else}
                    <span class="nc-bo-metric-value">{$breakerStateLabel|escape:'html':'UTF-8'}</span>
                {/if}
                {if $breaker_state == 'open' && $circuit_open_seconds > 0}
                    <small class="nc-bo-metric-note">
                        {$nc_i18n.opened_since_label|escape:'html':'UTF-8'} {$circuit_open_seconds} {$nc_i18n.seconds|escape:'html':'UTF-8'}
                    </small>
                {/if}
            </div>
        </div>
    </div>

    <hr>

    <h4 class="nc-bo-subsection-title">{$nc_i18n.recent_cron_runs_title|escape:'html':'UTF-8'}</h4>
    {if $recent_logs|@count > 0}
        <div class="table-responsive">
            <table class="table table-bordered table-striped nc-bo-logs-table">
                <thead>
                    <tr>
                        <th>{$nc_i18n.cron_executed_at_header|escape:'html':'UTF-8'}</th>
                        <th>{$nc_i18n.status_header|escape:'html':'UTF-8'}</th>
                        <th>{$nc_i18n.events_header|escape:'html':'UTF-8'}</th>
                        <th>{$nc_i18n.latency_ms_header|escape:'html':'UTF-8'}</th>
                        <th>{$nc_i18n.ip_header|escape:'html':'UTF-8'}</th>
                        <th>{$nc_i18n.message_header|escape:'html':'UTF-8'}</th>
                    </tr>
                </thead>
                <tbody>
                    {foreach from=$recent_logs item=log}
                        <tr>
                            <td>{$log.executed_at|escape:'html':'UTF-8'}</td>
                            <td>
                                {if $log.status == 'success'}
                                    <span class="label label-success">{$nc_i18n.status_success|escape:'html':'UTF-8'}</span>
                                {elseif $log.status == 'error'}
                                    <span class="label label-danger">{$nc_i18n.status_error|escape:'html':'UTF-8'}</span>
                                {else}
                                    <span class="label label-default">{$log.status|escape:'html':'UTF-8'}</span>
                                {/if}
                            </td>
                            <td>{$log.processed_events|intval}</td>
                            <td>{$log.execution_time_ms|intval}</td>
                            <td>{$log.ip_address|escape:'html':'UTF-8'}</td>
                            <td>
                                {if $log.error_message|default:'' != ''}
                                    {$log.error_message|escape:'html':'UTF-8'}
                                {else}
                                    <span class="text-muted">-</span>
                                {/if}
                            </td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
        </div>
    {else}
        <div class="alert alert-info nc-bo-alert">
            {$nc_i18n.no_recent_cron_runs|escape:'html':'UTF-8'}
        </div>
    {/if}

    <hr>

    <h4 class="nc-bo-subsection-title">{$nc_i18n.recent_coupons_title|escape:'html':'UTF-8'}</h4>
    {if $recent_coupons|default:[]|@count > 0}
        <div class="table-responsive">
            <table class="table table-bordered table-striped nc-bo-logs-table">
                <thead>
                    <tr>
                        <th>{$nc_i18n.created_at_header|escape:'html':'UTF-8'}</th>
                        <th>{$nc_i18n.email_header|escape:'html':'UTF-8'}</th>
                        <th>{$nc_i18n.cart_header|escape:'html':'UTF-8'}</th>
                        <th>{$nc_i18n.coupon_header|escape:'html':'UTF-8'}</th>
                        <th>{$nc_i18n.discount_percent_header|escape:'html':'UTF-8'}</th>
                        <th>{$nc_i18n.expires_at_header|escape:'html':'UTF-8'}</th>
                    </tr>
                </thead>
                <tbody>
                    {foreach from=$recent_coupons item=row}
                        <tr>
                            <td>{$row.created_at|escape:'html':'UTF-8'}</td>
                            <td>{$row.customer_email|escape:'html':'UTF-8'}</td>
                            <td>{$row.cart_id|escape:'html':'UTF-8'}</td>
                            <td>{$row.coupon_code|escape:'html':'UTF-8'}</td>
                            <td>{$row.discount_percent|escape:'html':'UTF-8'}</td>
                            <td>{$row.expires_at|escape:'html':'UTF-8'}</td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
        </div>
    {else}
        <div class="alert alert-info nc-bo-alert">
            {$nc_i18n.no_recent_coupons|escape:'html':'UTF-8'}
        </div>
    {/if}

    <hr>

    <h4 class="nc-bo-subsection-title">{$nc_i18n.recent_security_audit_title|escape:'html':'UTF-8'}</h4>
    {if $recent_security_failures|default:[]|@count > 0}
        <div class="table-responsive">
            <table class="table table-bordered table-striped nc-bo-logs-table">
                <thead>
                    <tr>
                        <th>{$nc_i18n.updated_at_header|escape:'html':'UTF-8'}</th>
                        <th>{$nc_i18n.endpoint_header|escape:'html':'UTF-8'}</th>
                        <th>{$nc_i18n.ip_header|escape:'html':'UTF-8'}</th>
                        <th>{$nc_i18n.attempts_header|escape:'html':'UTF-8'}</th>
                        <th>{$nc_i18n.status_header|escape:'html':'UTF-8'}</th>
                        <th>{$nc_i18n.last_rejection_header|escape:'html':'UTF-8'}</th>
                        <th>{$nc_i18n.blocked_until_header|escape:'html':'UTF-8'}</th>
                    </tr>
                </thead>
                <tbody>
                    {foreach from=$recent_security_failures item=row}
                        <tr>
                            <td>{$row.updated_at|escape:'html':'UTF-8'}</td>
                            <td>{$row.endpoint|escape:'html':'UTF-8'}</td>
                            <td>{$row.client_ip|escape:'html':'UTF-8'}</td>
                            <td>{$row.attempt_count|intval}</td>
                            <td>
                                {if $row.status == 'blocked'}
                                    <span class="label label-danger">{$nc_i18n.status_blocked|escape:'html':'UTF-8'}</span>
                                {else}
                                    <span class="label label-warning">{$nc_i18n.status_watch|escape:'html':'UTF-8'}</span>
                                {/if}
                            </td>
                            <td>{$row.last_error|default:'-'|escape:'html':'UTF-8'}</td>
                            <td>
                                {if $row.blocked_until|default:'' != ''}
                                    {$row.blocked_until|escape:'html':'UTF-8'}
                                {else}
                                    <span class="text-muted">-</span>
                                {/if}
                            </td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
        </div>
    {else}
        <div class="alert alert-info nc-bo-alert">
            {$nc_i18n.no_recent_security_failures|escape:'html':'UTF-8'}
        </div>
    {/if}

    <hr>

    <h4 class="nc-bo-subsection-title">{$nc_i18n.recent_recovery_links_title|escape:'html':'UTF-8'}</h4>
    {if $recent_recovery_tokens|default:[]|@count > 0}
        <div class="table-responsive">
            <table class="table table-bordered table-striped nc-bo-logs-table">
                <thead>
                    <tr>
                        <th>{$nc_i18n.created_at_header|escape:'html':'UTF-8'}</th>
                        <th>{$nc_i18n.email_header|escape:'html':'UTF-8'}</th>
                        <th>{$nc_i18n.cart_header|escape:'html':'UTF-8'}</th>
                        <th>{$nc_i18n.coupon_header|escape:'html':'UTF-8'}</th>
                        <th>{$nc_i18n.status_header|escape:'html':'UTF-8'}</th>
                        <th>{$nc_i18n.expires_at_header|escape:'html':'UTF-8'}</th>
                    </tr>
                </thead>
                <tbody>
                    {foreach from=$recent_recovery_tokens item=row}
                        <tr>
                            <td>{$row.created_at|escape:'html':'UTF-8'}</td>
                            <td>{$row.customer_email|escape:'html':'UTF-8'}</td>
                            <td>{$row.cart_id|escape:'html':'UTF-8'}</td>
                            <td>{$row.coupon_code|escape:'html':'UTF-8'}</td>
                            <td>
                                {if $row.status == 'used'}
                                    <span class="label label-success">{$nc_i18n.status_used|escape:'html':'UTF-8'}</span>
                                {elseif $row.status == 'expired'}
                                    <span class="label label-danger">{$nc_i18n.status_expired|escape:'html':'UTF-8'}</span>
                                {else}
                                    <span class="label label-default">{$nc_i18n.status_ready|escape:'html':'UTF-8'}</span>
                                {/if}
                            </td>
                            <td>{$row.expires_at|escape:'html':'UTF-8'}</td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
        </div>
    {else}
        <div class="alert alert-info nc-bo-alert">
            {$nc_i18n.no_recent_recovery_links|escape:'html':'UTF-8'}
        </div>
    {/if}
</div>

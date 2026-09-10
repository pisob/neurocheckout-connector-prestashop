<?php

namespace NeuroCheckout\BackOffice;

use Module;
use Context;
use NeuroCheckout\Infrastructure\CronLogRepository;
use NeuroCheckout\Infrastructure\RecoveryAuditRepository;
use NeuroCheckout\Infrastructure\SecurityThrottleRepository;
use NeuroCheckout\Monitoring\HealthMonitor;

/**
 * MonitoringFormRenderer
 *
 * Responsable de :
 *  - Afficher la santé système
 *  - Afficher backlog
 *  - Afficher circuit breaker
 *  - Afficher logs récents
 *
 * Utilise le template execution.tpl
 */
class MonitoringFormRenderer
{
    private Module $module;
    private Context $context;

    public function __construct(Module $module)
    {
        $this->module  = $module;
        $this->context = Context::getContext();
    }

    public function render(): string
    {
        $shopId = (int) $this->context->shop->id;

        /* ============================================================
         * HEALTH MONITOR
         * ============================================================ */

        $monitor = new HealthMonitor($shopId);
        $health  = $monitor->getHealthReport();

        /* ============================================================
         * LOGS
         * ============================================================ */

        $logRepo = new CronLogRepository($shopId);
        $recentLogs = $logRepo->getLastLogs(5);
        $auditRepo = new RecoveryAuditRepository($shopId);
        $recentCoupons = $auditRepo->getRecentCoupons(5);
        $recentRecoveryTokens = $auditRepo->getRecentRecoveryTokens(5);
        $recentSecurityFailures = (new SecurityThrottleRepository())->getRecentFailures($shopId, 5);

        /* ============================================================
         * SMARTY ASSIGN
         * ============================================================ */

        $this->context->smarty->assign([
            'health_status'        => $health['status'],
            'health_score'         => $health['score'],
            'backlog'              => $health['backlog'],
            'error_rate'           => $health['error_rate'],
            'latency'              => $health['avg_latency'],
            'breaker_state'        => $health['circuit_state'],
            'circuit_open_seconds' => $health['circuit_open_seconds'],
            'recent_logs'          => $recentLogs,
            'recent_coupons'       => $recentCoupons,
            'recent_recovery_tokens' => $recentRecoveryTokens,
            'recent_security_failures' => $recentSecurityFailures,
        ]);

        return $this->module->display(
            $this->module->getLocalPath(),
            'views/templates/admin/execution.tpl'
        );
    }
}

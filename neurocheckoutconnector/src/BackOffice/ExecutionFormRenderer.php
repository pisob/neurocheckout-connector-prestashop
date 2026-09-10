<?php

namespace NeuroCheckout\BackOffice;

use Configuration;
use Context;
use Module;
use NeuroCheckout\I18n\ModuleTranslator;
use NeuroCheckout\Monitoring\HealthMonitor;

class ExecutionFormRenderer
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
        $executionMode = trim((string) Configuration::get('NC_EXECUTION_MODE'));
        if ($executionMode === '' || $executionMode === 'auto') {
            $executionMode = 'cron_module';
        }
        if (!in_array($executionMode, ['cron_module', 'cron'], true)) {
            $executionMode = 'cron_module';
        }
        $debugMode = (bool) Configuration::get('NC_DEBUG_MODE');
        $debugAdvanced = (bool) Configuration::get('NC_DEBUG_ADVANCED');
        $lastRun = (int) Configuration::get('NC_LAST_AUTO_RUN');
        $cronAllowedIps = (string) Configuration::get('NC_CRON_ALLOWED_IPS');
        $trustedProxyIps = (string) Configuration::get('NC_TRUSTED_PROXY_IPS');
        $cronIntervalSeconds = (int) (Configuration::get('NC_CRON_INTERVAL_SECONDS') ?: 300);
        if ($cronIntervalSeconds < 60) {
            $cronIntervalSeconds = 300;
        }
        $cronIntervalMinutes = (int) ceil($cronIntervalSeconds / 60);
        $cronScheduleExpression = sprintf('*/%d * * * *', $cronIntervalMinutes);
        $autoHookInterval = (int) (Configuration::get('NC_AUTO_HOOK_INTERVAL') ?: 300);
        $runtimeEnvMode = method_exists($this->module, 'detectRuntimeEnvironmentMode')
            ? (string) $this->module->detectRuntimeEnvironmentMode()
            : 'production';
        if ($runtimeEnvMode !== 'local' && $runtimeEnvMode !== 'production') {
            $runtimeEnvMode = 'production';
        }

        $runnerScriptPath = rtrim((string) $this->module->getLocalPath(), '/') . '/scripts/cron_env_dispatch.php';
        $cronRunnerCommand = sprintf(
            'php %s >/dev/null 2>&1',
            escapeshellarg($runnerScriptPath)
        );

        $lastRunDisplay = $lastRun
            ? date('Y-m-d H:i:s', $lastRun)
            : ModuleTranslator::trans('never_executed');

        $shopId = (int) $this->context->shop->id;
        $monitor = new HealthMonitor($shopId);
        $health = $monitor->getHealthReport();

        $cronAjaxUrl = $this->context->link->getAdminLink(
            'AdminModules',
            true,
            [],
            [
                'configure' => $this->module->name,
                'ajax' => 1,
                'action' => 'runCron',
            ]
        );

        $this->context->smarty->assign([
            'execution_mode' => $executionMode,
            'last_run' => $lastRunDisplay,
            'cron_test_ajax_url' => $cronAjaxUrl . '&mode=test',
            'cron_force_ajax_url' => $cronAjaxUrl . '&mode=force',
            'debug_mode' => $debugMode,
            'debug_advanced' => $debugAdvanced,
            'cron_allowed_ips' => $cronAllowedIps,
            'trusted_proxy_ips' => $trustedProxyIps,
            'runtime_env_mode' => $runtimeEnvMode,
            'cron_runner_command' => $cronRunnerCommand,
            'cron_interval_minutes' => $cronIntervalMinutes,
            'cron_schedule_expression' => $cronScheduleExpression,
            'auto_hook_interval' => $autoHookInterval,
            'health_status' => $health['status'],
            'health_score' => $health['score'],
            'backlog' => $health['backlog'],
        ]);

        return $this->module->display(
            $this->module->getLocalPath(),
            'views/templates/admin/cron_choice.tpl'
        );
    }
}

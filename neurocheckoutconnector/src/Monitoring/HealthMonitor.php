<?php

namespace NeuroCheckout\Monitoring;

use Db;
use Configuration;
use NeuroCheckout\Resilience\CircuitBreaker;
use PrestaShopLogger;

class HealthMonitor
{
    private int $shopId;
    private const CACHE_TTL = 30;

    public function __construct(int $shopId)
    {
        $this->shopId = (int) $shopId;
    }

    /* ============================================================
     * RECORD SUCCESS (ajout stable)
     * ============================================================ */

    /**
     * Appelé par EventDispatcher après envoi réussi.
     * Ne doit JAMAIS casser le flux.
     */
    public function recordSuccess(): void
    {
        try {
            // Pour l’instant, monitoring passif.
            // Placeholder futur pour métriques avancées.
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                '[NC] HealthMonitor recordSuccess error: ' . $e->getMessage(),
                2
            );
        }
    }

    /* ============================================================
     * RECORD FAILURE (ajout stable)
     * ============================================================ */

    /**
     * Appelé par EventDispatcher après échec réseau.
     * Important : ne jamais lancer d’exception ici.
     */
    public function recordFailure(): void
    {
        try {
            // Placeholder futur pour tracking avancé
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                '[NC] HealthMonitor recordFailure error: ' . $e->getMessage(),
                2
            );
        }
    }

    /* ============================================================
     * HEALTH REPORT (inchangé)
     * ============================================================ */

    public function getHealthReport(): array
    {
        $cacheKey = 'NC_HEALTH_CACHE_' . $this->shopId;
        $cached   = Configuration::get($cacheKey);

        if ($cached) {
            $data = json_decode($cached, true);
            if (isset($data['timestamp']) &&
                (time() - $data['timestamp']) < self::CACHE_TTL) {
                return $data['payload'];
            }
        }

        try {

            $cb = new CircuitBreaker($this->shopId);
            $circuitDetails = $cb->getStateDetails();
            $circuitState   = $circuitDetails['state'];
            $updatedAt      = $circuitDetails['updated_at'];

            $queueStats = $this->getQueueStats();
            $backlog    = (int) $queueStats['backlog'];
            $errorRate  = $this->getRecentErrorRate();
            $latency    = $this->getAverageLatency();

            $score = $this->calculateScore(
                $backlog,
                (int) $queueStats['processing_count'],
                (int) $queueStats['stale_processing_count'],
                (int) $queueStats['oldest_processing_age_seconds'],
                $errorRate,
                $latency,
                $circuitState
            );

            if ($score >= 80 && $circuitState === CircuitBreaker::STATE_OPEN) {
                $cb->forceResetIfOpen();
                $circuitState = CircuitBreaker::STATE_CLOSED;
            }

            $report = [
                'score'                => $score,
                'status'               => $this->mapScoreToStatus($score),
                'backlog'              => $backlog,
                'pending_count'        => (int) $queueStats['pending_count'],
                'processing_count'     => (int) $queueStats['processing_count'],
                'stale_processing_count' => (int) $queueStats['stale_processing_count'],
                'oldest_processing_age_seconds' => (int) $queueStats['oldest_processing_age_seconds'],
                'stale_processing_threshold_seconds' => (int) $queueStats['stale_processing_threshold_seconds'],
                'error_rate'           => $errorRate,
                'avg_latency'          => $latency,
                'circuit_state'        => $circuitState,
                'circuit_open_seconds' =>
                    $circuitState === CircuitBreaker::STATE_OPEN
                        ? time() - strtotime($updatedAt)
                        : 0,
                'timestamp'            => date('Y-m-d H:i:s'),
            ];

            Configuration::updateValue(
                $cacheKey,
                json_encode([
                    'timestamp' => time(),
                    'payload'   => $report
                ])
            );

            return $report;

        } catch (\Throwable $e) {

            return [
                'score'         => 0,
                'status'        => 'critical',
                'backlog'       => 0,
                'pending_count' => 0,
                'processing_count' => 0,
                'stale_processing_count' => 0,
                'oldest_processing_age_seconds' => 0,
                'stale_processing_threshold_seconds' => $this->staleProcessingThresholdSeconds(),
                'error_rate'    => 0,
                'avg_latency'   => 0,
                'circuit_state' => 'unknown',
                'circuit_open_seconds' => 0,
                'timestamp'     => date('Y-m-d H:i:s'),
            ];
        }
    }

    /* ============================================================
     * METRICS
     * ============================================================ */

    /**
     * @return array<string,int>
     */
    private function getQueueStats(): array
    {
        $thresholdSeconds = $this->staleProcessingThresholdSeconds();
        $rows = Db::getInstance()->executeS(
            'SELECT status, last_attempt_at, created_at
             FROM `' . _DB_PREFIX_ . 'neurocheckout_event` e
             WHERE e.shop_id = ' . (int)$this->shopId . '
               AND e.status IN ("pending", "processing")
               AND (
                    (e.payload IS NOT NULL AND e.payload <> "")
                    OR (e.event_hash IS NOT NULL AND e.event_hash <> "")
                    OR EXISTS (
                        SELECT 1
                        FROM `' . _DB_PREFIX_ . 'cart_product` cp
                        WHERE cp.id_cart = CAST(e.cart_id AS UNSIGNED)
                        LIMIT 1
                    )
               )'
        ) ?: [];

        $now = time();
        $pending = 0;
        $processing = 0;
        $stale = 0;
        $oldestAge = 0;

        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if ($status === 'pending') {
                $pending++;
                continue;
            }

            if ($status !== 'processing') {
                continue;
            }

            $processing++;
            $attemptedAt = strtotime((string) ($row['last_attempt_at'] ?? '')) ?: 0;
            if ($attemptedAt <= 0) {
                $attemptedAt = strtotime((string) ($row['created_at'] ?? '')) ?: 0;
            }
            $age = $attemptedAt > 0 ? max(0, $now - $attemptedAt) : ($thresholdSeconds + 1);
            $oldestAge = max($oldestAge, $age);
            if ($age > $thresholdSeconds) {
                $stale++;
            }
        }

        return [
            'pending_count' => $pending,
            'processing_count' => $processing,
            'stale_processing_count' => $stale,
            'oldest_processing_age_seconds' => $oldestAge,
            'stale_processing_threshold_seconds' => $thresholdSeconds,
            'backlog' => $pending + $processing,
        ];
    }

    private function getRecentErrorRate(): float
    {
        $threshold = $this->recentWindowThreshold(10);
        $total = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'neurocheckout_cron_log`
             WHERE shop_id=' . (int)$this->shopId . '
             AND executed_at > "' . pSQL($threshold) . '"'
        );

        if ($total === 0) return 0.0;

        $errors = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'neurocheckout_cron_log`
             WHERE shop_id=' . (int)$this->shopId . '
             AND status="error"
             AND executed_at > "' . pSQL($threshold) . '"'
        );

        return round(($errors / $total) * 100, 2);
    }

    private function getAverageLatency(): int
    {
        $threshold = $this->recentWindowThreshold(10);
        $latency = Db::getInstance()->getValue(
            'SELECT AVG(execution_time_ms)
             FROM `' . _DB_PREFIX_ . 'neurocheckout_cron_log`
             WHERE shop_id=' . (int)$this->shopId . '
             AND executed_at > "' . pSQL($threshold) . '"'
        );

        return (int) round((float) ($latency ?: 0));
    }

    private function recentWindowThreshold(int $minutes): string
    {
        $windowMinutes = max(1, (int) $minutes);
        return date('Y-m-d H:i:s', time() - ($windowMinutes * 60));
    }

    /* ============================================================
     * SCORING
     * ============================================================ */

    private function calculateScore(
        int $backlog,
        int $processingCount,
        int $staleProcessingCount,
        int $oldestProcessingAgeSeconds,
        float $errorRate,
        int $latency,
        string $circuitState
    ): int {

        $score = 100;

        if ($backlog > 50)  $score -= 20;
        if ($backlog > 200) $score -= 30;
        if ($processingCount > 0) $score -= 10;
        if ($staleProcessingCount > 0) $score -= 45;
        if ($oldestProcessingAgeSeconds > ($this->staleProcessingThresholdSeconds() * 2)) $score -= 20;

        if ($errorRate > 10) $score -= 20;
        if ($errorRate > 30) $score -= 30;

        if ($latency > 10000) $score -= 10;
        if ($latency > 30000) $score -= 20;
        if ($latency > 120000) $score -= 20;

        if ($circuitState === CircuitBreaker::STATE_OPEN) {
            $score -= 40;
        }

        return max(0, $score);
    }

    private function mapScoreToStatus(int $score): string
    {
        if ($score >= 80) return 'healthy';
        if ($score >= 50) return 'degraded';
        return 'critical';
    }

    private function staleProcessingThresholdSeconds(): int
    {
        $configured = (int) Configuration::get('NC_STALE_PROCESSING_MINUTES');
        if ($configured <= 0) {
            $configured = 3;
        }

        return max(1, min(60, $configured)) * 60;
    }
}

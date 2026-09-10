<?php

namespace NeuroCheckout\Application;

use Cart;
use Shop;
use Configuration;
use PrestaShopLogger;

use NeuroCheckout\Event\CartEventBuilder;
use NeuroCheckout\Infrastructure\EventRepository;
use NeuroCheckout\Http\SecureHttpClient;
use NeuroCheckout\Security\SecretConfiguration;
use NeuroCheckout\Resilience\CircuitBreaker;
use NeuroCheckout\Monitoring\HealthMonitor;

/**
 * EventDispatcher — Enterprise Auto-Healing Version
 *
 * Garanties :
 * - Lock batch atomique
 * - Release stuck processing
 * - Snapshot figé (jamais reconstruit après snapshot)
 * - Cleared si panier supprimé / vide
 * - Retry exponentiel via repository
 * - Dead letter isolation
 * - Circuit breaker
 * - Monitoring
 * - Purge terminal states
 */
class EventDispatcher
{
    private const MAX_RETRIES = 8;

    private EventRepository $repository;
    private SecureHttpClient $httpClient;
    private CircuitBreaker $circuitBreaker;
    private HealthMonitor $monitor;

    private int $shopId;
    private string $endpoint;
    private string $apiKey;
    private array $lastRunStats = [
        'processed' => 0,
        'failed' => 0,
        'cleared' => 0,
        'fatal' => false,
        'reason' => null,
    ];

    public function __construct(int $shopId)
    {
        if ($shopId <= 0) {
            throw new \InvalidArgumentException('Invalid shop_id');
        }

        $this->shopId         = $shopId;
        $this->repository     = new EventRepository($shopId);
        $this->httpClient     = new SecureHttpClient();
        $this->circuitBreaker = new CircuitBreaker($shopId);
        $this->monitor        = new HealthMonitor($shopId);

        $this->endpoint = rtrim((string) Configuration::get('NC_API_ENDPOINT'), '/');
        $this->apiKey   = SecretConfiguration::get('NC_API_KEY');
    }

    /* ============================================================
     * MAIN DISPATCH
     * ============================================================ */

    public function dispatch(int $limit = 100, bool $isCronTest = false): int
    {
        $processed = 0;
        $failed = 0;
        $cleared = 0;
        $this->lastRunStats = [
            'processed' => 0,
            'failed' => 0,
            'cleared' => 0,
            'fatal' => false,
            'reason' => null,
        ];

        try {

            // ------------------------------------------------------
            // 0️⃣ Pré-conditions globales
            // ------------------------------------------------------

            if (empty($this->endpoint) || empty($this->apiKey)) {
                $this->lastRunStats['reason'] = 'missing_api_configuration';
                return 0;
            }

            if (!$this->isIaConfigurationReady()) {
                $this->lastRunStats['reason'] = 'missing_ia_configuration';
                return 0;
            }

            if (!$this->circuitBreaker->isAvailable()) {
                $this->lastRunStats['reason'] = 'circuit_breaker_open';
                return 0;
            }

            // ------------------------------------------------------
            // 1️⃣ Release stuck processing (auto-healing)
            // ------------------------------------------------------

            $this->repository->expireRetryableEvents();
            $this->repository->releaseStuckProcessing($this->staleProcessingMinutes());

            // ------------------------------------------------------
            // 2️⃣ Lock batch intelligent
            // ------------------------------------------------------

            $locked = $this->repository->lockBatchAtomic($limit);

            if (empty($locked)) {
                $this->lastRunStats['reason'] = 'no_pending_events';
                return 0;
            }

            // ------------------------------------------------------
            // 3️⃣ Process loop
            // ------------------------------------------------------

            foreach ($locked as $event) {

                $eventId   = (int) $event['id'];
                $cartId    = (int) $event['cart_id'];
                $attempts  = (int) $event['attempts'];

                $storedPayload = !empty($event['payload'])
                    ? json_decode($event['payload'], true)
                    : null;

                try {

                    Shop::setContext(Shop::CONTEXT_SHOP, $this->shopId);

                    /* ========================================================
                     * A. Si snapshot inexistant → construire
                     * ======================================================== */

                    if (empty($storedPayload)) {

                        $cart = new Cart($cartId);

                        // 🔴 Panier supprimé → cleared
                        if (!$cart->id) {
                            $this->repository->markAsCleared($eventId);
                            $cleared++;
                            continue;
                        }

                        $payload = CartEventBuilder::build($cart);

                        if (empty($payload)) {
                            $payload = CartEventBuilder::buildCleared($cart, 'cart_empty');
                            if (empty($payload)) {
                                $this->repository->markAsCleared($eventId);
                                $cleared++;
                                continue;
                            }
                        }

                        $hash = $this->buildPayloadHash($payload);

                        $this->repository->updateSnapshot(
                            $eventId,
                            $payload,
                            $hash
                        );

                    } else {

                        // Snapshot figé — jamais reconstruit
                        $payload = $storedPayload;

                        $eventType = (string)($payload['event_type'] ?? '');
                        if (
                            $eventType !== 'cart.cleared'
                            && empty($payload['cart']['items'])
                        ) {
                            $this->repository->markAsCleared($eventId);
                            $cleared++;
                            continue;
                        }
                    }

                    /* ========================================================
                     * B. ENVOI HTTP
                     * ======================================================== */

                    $result = $this->httpClient->send(
                        $payload,
                        ['is_cron_test' => $isCronTest]
                    );

                    if (!empty($result['success'])) {

                        if (($payload['event_type'] ?? '') === 'cart.cleared') {
                            $this->repository->markAsCleared($eventId);
                            $cleared++;
                        } else {
                            $this->repository->markAsSent($eventId);
                            $processed++;
                        }
                        $this->circuitBreaker->recordSuccess();
                        $this->monitor->recordSuccess();

                    } else {

                        // 🔥 MAX RETRIES → dead
                        if ($attempts >= self::MAX_RETRIES) {
                            $this->repository->markAsDead($eventId);
                        } else {
                            $this->repository->markAsFailed($eventId);
                        }

                        $this->circuitBreaker->recordFailure();
                        $this->monitor->recordFailure();
                        $failed++;
                    }

                } catch (\Throwable $e) {

                    PrestaShopLogger::addLog(
                        '[NC] Dispatcher event error: ' . $e->getMessage(),
                        3
                    );

                    if ($attempts >= self::MAX_RETRIES) {
                        $this->repository->markAsDead($eventId);
                    } else {
                        $this->repository->markAsFailed($eventId);
                    }

                    $this->circuitBreaker->recordFailure();
                    $this->monitor->recordFailure();
                    $failed++;
                }
            }

            // ------------------------------------------------------
            // 4️⃣ Purge terminal states
            // ------------------------------------------------------

            $this->purgeOldSent();

            $this->lastRunStats = [
                'processed' => $processed,
                'failed' => $failed,
                'cleared' => $cleared,
                'fatal' => false,
                'reason' => null,
            ];

            return $processed;

        } catch (\Throwable $e) {

            PrestaShopLogger::addLog(
                '[NC] Dispatcher fatal: ' . $e->getMessage(),
                3
            );
            $this->lastRunStats['fatal'] = true;
            $this->lastRunStats['reason'] = $e->getMessage();

            return 0;
        }
    }

    private function staleProcessingMinutes(): int
    {
        $configured = (int) Configuration::get('NC_STALE_PROCESSING_MINUTES');
        if ($configured <= 0) {
            $configured = 3;
        }

        return max(1, min(60, $configured));
    }

    public function getLastRunStats(): array
    {
        return $this->lastRunStats;
    }

    /* ============================================================
     * PURGE TERMINAL STATES
     * ============================================================ */

    private function purgeOldSent(): void
    {
        try {

            $retentionDays = (int) Configuration::get('NC_EVENT_RETENTION_DAYS');
            $lastRun       = (int) Configuration::get('NC_LAST_PURGE_RUN');
            $batchSize     = (int) Configuration::get('NC_PURGE_BATCH_SIZE');

            if ($retentionDays <= 0) {
                return;
            }

            if ($batchSize <= 0) {
                $batchSize = 500;
            }

            // Max 1x / 24h
            if ($lastRun && (time() - $lastRun) < 86400) {
                return;
            }

            $deleted = $this->repository->purgeSentBatch(
                $retentionDays,
                $batchSize
            );

            Configuration::updateValue('NC_LAST_PURGE_RUN', time());

            if ($deleted > 0) {
                PrestaShopLogger::addLog(
                    "[NC] Progressive purge — deleted={$deleted}",
                    1
                );
            }

        } catch (\Throwable $e) {

            PrestaShopLogger::addLog(
                '[NC] Progressive purge error: ' . $e->getMessage(),
                3
            );
        }
    }

    private function isIaConfigurationReady(): bool
    {
        if ((int) Configuration::get('NC_RECOVERY_ENABLED') !== 1) {
            Configuration::updateValue('NC_RECOVERY_ENABLED', 1);
        }
        if ((int) Configuration::get('NC_RECOVERY_ENABLED') !== 1) {
            return false;
        }

        $rawMinCartTotal = trim((string) Configuration::get('NC_MIN_CART_TOTAL'));
        if ($rawMinCartTotal === '' || !is_numeric($rawMinCartTotal)) {
            return false;
        }
        if ((float) $rawMinCartTotal < 0) {
            return false;
        }

        $rawMaxDiscount = trim((string) Configuration::get('NC_MAX_DISCOUNT_PERCENT'));
        if ($rawMaxDiscount === '' || !is_numeric($rawMaxDiscount)) {
            return false;
        }

        $maxDiscount = (float) $rawMaxDiscount;
        if ($maxDiscount < 0 || $maxDiscount > 100) {
            return false;
        }

        return true;
    }

    private function buildPayloadHash(array $payload): string
    {
        return hash('sha256', json_encode($payload));
    }
}

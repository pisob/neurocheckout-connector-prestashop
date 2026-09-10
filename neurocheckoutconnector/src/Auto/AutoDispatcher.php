<?php

namespace NeuroCheckout\Auto;

use Configuration;
use Context;
use PrestaShopLogger;
use NeuroCheckout\Application\EventDispatcher;
use NeuroCheckout\Infrastructure\LockRepository;

class AutoDispatcher
{
    /**
     * Interval minimum entre deux exécutions AUTO (secondes)
     */
    const INTERVAL = 300; // 5 minutes

    /**
     * TTL lock (sécurité crash PHP)
     */
    const LOCK_TTL = 120; // 2 minutes

    private int $shopId;
    private LockRepository $lockRepository;

    public function __construct()
    {
        $context = Context::getContext();
        $this->shopId = (int) ($context->shop->id ?? 0);

        if ($this->shopId <= 0) {
            throw new \RuntimeException('Invalid shop context');
        }

        $this->lockRepository = new LockRepository($this->shopId);
    }

    /* ============================================================
     * MAIN ENTRY
     * ============================================================ */

    public function run(): void
    {
        try {

            /* ========================================================
             * 1️⃣ Execution mode check
             * ======================================================== */

            if (Configuration::get('NC_EXECUTION_MODE') !== 'auto') {
                return;
            }

            /* ========================================================
             * 2️⃣ Interval check
             * ======================================================== */

            $now  = time();
            $last = (int) Configuration::get('NC_LAST_AUTO_RUN');

            if ($last && ($now - $last) < self::INTERVAL) {
                return;
            }

            /* ========================================================
             * 3️⃣ Acquire SQL Lock (atomic)
             * ======================================================== */

            $acquired = $this->lockRepository->acquire(
                'auto_dispatch',
                self::LOCK_TTL
            );

            if (!$acquired) {
                return; // autre processus en cours
            }

            /* ========================================================
             * 4️⃣ Dispatch
             * ======================================================== */

            $dispatcher = new EventDispatcher($this->shopId);

            $processed = (int) $dispatcher->dispatch();

            PrestaShopLogger::addLog(
                "[NC] AUTO dispatch executed shop={$this->shopId} processed={$processed}",
                1
            );

            /* ========================================================
             * 5️⃣ Update last run timestamp
             * ======================================================== */

            Configuration::updateValue('NC_LAST_AUTO_RUN', $now);

        } catch (\Throwable $e) {

            PrestaShopLogger::addLog(
                '[NC] AUTO dispatch fatal error: ' . $e->getMessage(),
                3
            );

        } finally {

            /* ========================================================
             * 6️⃣ Always release lock
             * ======================================================== */

            try {
                $this->lockRepository->release('auto_dispatch');
            } catch (\Throwable $e) {
                PrestaShopLogger::addLog(
                    '[NC] AUTO lock release error: ' . $e->getMessage(),
                    2
                );
            }
        }
    }
}

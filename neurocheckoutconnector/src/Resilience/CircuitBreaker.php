<?php

namespace NeuroCheckout\Resilience;

use Db;
use Configuration;
use PrestaShopLogger;

class CircuitBreaker
{
    public const STATE_CLOSED    = 'closed';
    public const STATE_OPEN      = 'open';
    public const STATE_HALF_OPEN = 'half_open';

    private const DEFAULT_FAILURE_THRESHOLD = 5;
    private const DEFAULT_COOLDOWN_SECONDS  = 60;

    private int $shopId;
    private string $table;

    public function __construct(int $shopId)
    {
        $this->shopId = (int) $shopId;
        $this->table  = _DB_PREFIX_ . 'neurocheckout_circuit_breaker';
    }

    /* ================= PUBLIC API ================= */

    public function isAvailable(): bool
    {
        $state = $this->getStateRow();

        if ($state['state'] === self::STATE_CLOSED) {
            return true;
        }

        if ($state['state'] === self::STATE_OPEN) {

            if ($this->cooldownExpired($state)) {
                $this->setState(self::STATE_HALF_OPEN);
                return true;
            }

            return false;
        }

        return true; // HALF_OPEN
    }

    public function recordSuccess(): void
    {
        $this->setState(self::STATE_CLOSED, 0);
    }

    public function recordFailure(): void
    {
        $state     = $this->getStateRow();
        $failures  = (int) $state['failure_count'] + 1;
        $threshold = $this->getFailureThreshold();

        if ($failures >= $threshold) {

            $this->setState(self::STATE_OPEN, $failures);

            PrestaShopLogger::addLog(
                "[NC] Circuit OPEN (shop={$this->shopId}) failures={$failures}",
                2
            );

            return;
        }

        $this->setState(self::STATE_CLOSED, $failures);
    }

    public function getCurrentState(): string
    {
        return $this->getStateRow()['state'];
    }

    public function getStateDetails(): array
    {
        return $this->getStateRow();
    }

    public function forceResetIfOpen(): void
    {
        $state = $this->getStateRow();

        if ($state['state'] !== self::STATE_OPEN) {
            return;
        }

        $this->setState(self::STATE_CLOSED, 0);

        PrestaShopLogger::addLog(
            "[NC] Circuit AUTO-RESET (shop={$this->shopId})",
            1
        );
    }

    /* ================= INTERNAL ================= */

    private function getStateRow(): array
    {
        $row = Db::getInstance()->getRow(
            'SELECT state, failure_count, updated_at
             FROM `' . $this->table . '`
             WHERE shop_id = ' . (int)$this->shopId
        );

        if (!$row) {
            $this->insertInitialState();
            return [
                'state'         => self::STATE_CLOSED,
                'failure_count' => 0,
                'updated_at'    => date('Y-m-d H:i:s'),
            ];
        }

        return $row;
    }

    private function insertInitialState(): void
    {
        Db::getInstance()->insert(
            'neurocheckout_circuit_breaker',
            [
                'shop_id'       => $this->shopId,
                'state'         => self::STATE_CLOSED,
                'failure_count' => 0,
                'updated_at'    => date('Y-m-d H:i:s'),
            ]
        );
    }

    private function setState(string $state, int $failures = 0): void
    {
        Db::getInstance()->update(
            'neurocheckout_circuit_breaker',
            [
                'state'         => pSQL($state),
                'failure_count' => $failures,
                'updated_at'    => date('Y-m-d H:i:s'),
            ],
            'shop_id = ' . (int)$this->shopId
        );
    }

    private function cooldownExpired(array $state): bool
    {
        return (time() - strtotime($state['updated_at'])) >= $this->getCooldownSeconds();
    }

    private function getFailureThreshold(): int
    {
        $value = (int) Configuration::get('NC_CB_FAILURE_THRESHOLD');
        return $value > 0 ? $value : self::DEFAULT_FAILURE_THRESHOLD;
    }

    private function getCooldownSeconds(): int
    {
        $value = (int) Configuration::get('NC_CB_COOLDOWN_SECONDS');
        return $value > 0 ? $value : self::DEFAULT_COOLDOWN_SECONDS;
    }
}

<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Tables;

use Demo\BinanceBtcTracker\Hilos;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Tables\ProtectedMode\HilosVerifierCircleTable;

/**
 * BinanceBtcTrackerTableContext - App-specific table context ($table layer) for binance-btc-tracker.
 *
 * Registers whatever the project topology lists: today the maintenance section's verifier circle
 * table, accessed via Hilos::$table->hilosVerifierCircle. The other admin sections arrive with the
 * leaves that move their e2e onto this demo, each with its table.
 *
 * @property-read HilosVerifierCircleTable $hilosVerifierCircle
 */
final class BinanceBtcTrackerTableContext extends TableContext
{
    public const string hilosVerifierCircle = HilosVerifierCircleTable::TABLE;

    /**
     * Registers binance-btc-tracker table definitions from the project topology registry.
     */
    public function configure(): void
    {
        foreach (Hilos::TABLES as $tableName => $tableClass) {
            $this->register($tableName, new $tableClass());
        }
    }
}

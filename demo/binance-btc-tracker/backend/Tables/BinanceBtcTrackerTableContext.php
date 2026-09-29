<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Tables;

use Demo\BinanceBtcTracker\Hilos;
use Hilos\Core\Table\Context\TableContext;

/**
 * BinanceBtcTrackerTableContext - App-specific table context ($table layer) for binance-btc-tracker.
 *
 * Registers whatever the project topology lists, which is nothing yet: the admin sections
 * arrive with the leaves that move their e2e onto this demo, each with its table.
 */
final class BinanceBtcTrackerTableContext extends TableContext
{
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

<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Tables;

use Demo\BinanceBtcTracker\Hilos;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Tables\Backup\HilosBackupHistoryTable;
use Hilos\Tables\Logs\HilosLogKeysTable;
use Hilos\Tables\Logs\HilosLogRotationsTable;
use Hilos\Tables\Logs\HilosLogWorkersTable;
use Hilos\Tables\ProtectedMode\HilosVerifierCircleTable;
use Hilos\Tables\Settings\HilosSettingsTable;

/**
 * BinanceBtcTrackerTableContext - App-specific table context ($table layer) for binance-btc-tracker.
 *
 * Registers whatever the project topology lists: today the backup section's archive table, the
 * maintenance section's verifier circle table, the settings table and the three tables of the
 * logs section, accessed via Hilos::$table->hilosBackups, Hilos::$table->hilosVerifierCircle,
 * Hilos::$table->settings and Hilos::$table->hilosLogKeys / hilosLogRotations / hilosLogWorkers.
 * The other admin sections arrive with the leaves that move their e2e onto this demo, each with
 * its table.
 *
 * @property-read HilosBackupHistoryTable $hilosBackups
 * @property-read HilosVerifierCircleTable $hilosVerifierCircle
 * @property-read HilosSettingsTable $settings
 * @property-read HilosLogKeysTable $hilosLogKeys
 * @property-read HilosLogRotationsTable $hilosLogRotations
 * @property-read HilosLogWorkersTable $hilosLogWorkers
 */
final class BinanceBtcTrackerTableContext extends TableContext
{
    public const string hilosBackups = HilosBackupHistoryTable::TABLE;
    public const string hilosVerifierCircle = HilosVerifierCircleTable::TABLE;
    public const string settings = HilosSettingsTable::TABLE;
    public const string hilosLogKeys = HilosLogKeysTable::TABLE;
    public const string hilosLogRotations = HilosLogRotationsTable::TABLE;
    public const string hilosLogWorkers = HilosLogWorkersTable::TABLE;

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

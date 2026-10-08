<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Tables;

use Demo\BinanceBtcTracker\Hilos;
use Demo\BinanceBtcTracker\Tables\HilosUser\HilosUsersTable;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Tables\Appearance\HilosAppearanceSettingsTable;
use Hilos\Tables\Backup\HilosBackupHistoryTable;
use Hilos\Tables\Communications\HilosCommunicationsChannelFieldsTable;
use Hilos\Tables\Communications\HilosCommunicationsChannelsTable;
use Hilos\Tables\Communications\HilosNotificationDeliveriesTable;
use Hilos\Tables\Logs\HilosLogKeysTable;
use Hilos\Tables\Logs\HilosLogRotationsTable;
use Hilos\Tables\Daemon\HilosDaemonCronTable;
use Hilos\Tables\Logs\HilosLogWorkersTable;
use Hilos\Tables\ProtectedMode\HilosVerifierCircleTable;
use Hilos\Tables\Settings\HilosSettingsTable;

/**
 * BinanceBtcTrackerTableContext - App-specific table context ($table layer) for binance-btc-tracker.
 *
 * Registers whatever the project topology lists: today the backup section's archive table, the
 * maintenance section's verifier circle table, the settings table, the people table, the three
 * tables of the logs section and the three of the communications section, accessed via
 * Hilos::$table->hilosBackups, Hilos::$table->hilosVerifierCircle, Hilos::$table->settings,
 * Hilos::$table->hilosUsers, Hilos::$table->hilosLogKeys / hilosLogRotations / hilosLogWorkers and
 * Hilos::$table->hilosCommunicationsChannels / hilosCommunicationsChannelFields /
 * hilosNotificationDeliveries.
 * The other admin sections arrive with the leaves that move their e2e onto this demo, each with
 * its table.
 *
 * @property-read HilosBackupHistoryTable $hilosBackups
 * @property-read HilosVerifierCircleTable $hilosVerifierCircle
 * @property-read HilosSettingsTable $settings
 * @property-read HilosAppearanceSettingsTable $hilosAppearanceSettings
 * @property-read HilosUsersTable $hilosUsers
 * @property-read HilosLogKeysTable $hilosLogKeys
 * @property-read HilosLogRotationsTable $hilosLogRotations
 * @property-read HilosLogWorkersTable $hilosLogWorkers
 * @property-read HilosDaemonCronTable $hilosDaemonCron
 * @property-read HilosCommunicationsChannelsTable $hilosCommunicationsChannels
 * @property-read HilosCommunicationsChannelFieldsTable $hilosCommunicationsChannelFields
 * @property-read HilosNotificationDeliveriesTable $hilosNotificationDeliveries
 */
final class BinanceBtcTrackerTableContext extends TableContext
{
    public const string hilosBackups = HilosBackupHistoryTable::TABLE;
    public const string hilosVerifierCircle = HilosVerifierCircleTable::TABLE;
    public const string settings = HilosSettingsTable::TABLE;
    public const string hilosAppearanceSettings = HilosAppearanceSettingsTable::TABLE;
    public const string hilosUsers = 'hilosUsers';
    public const string hilosLogKeys = HilosLogKeysTable::TABLE;
    public const string hilosLogRotations = HilosLogRotationsTable::TABLE;
    public const string hilosLogWorkers = HilosLogWorkersTable::TABLE;
    public const string hilosDaemonCron = HilosDaemonCronTable::TABLE;
    public const string hilosCommunicationsChannels = HilosCommunicationsChannelsTable::TABLE;
    public const string hilosCommunicationsChannelFields = HilosCommunicationsChannelFieldsTable::TABLE;
    public const string hilosNotificationDeliveries = HilosNotificationDeliveriesTable::TABLE;

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

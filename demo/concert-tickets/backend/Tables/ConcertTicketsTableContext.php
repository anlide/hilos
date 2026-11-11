<?php

declare(strict_types=1);

namespace Demo\ConcertTickets\Tables;

use Demo\ConcertTickets\Hilos;
use Demo\ConcertTickets\Tables\HilosUser\HilosUsersTable;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Tables\Analytics\HilosAnalyticsActionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionListTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsPeopleTable;
use Hilos\Tables\ChangeLog\HilosChangeLogFeedTable;
use Hilos\Tables\ChangeLog\HilosChangeLogHistoryTable;
use Hilos\Tables\ChangeLog\HilosChangeLogNoisyColumnsTable;
use Hilos\Tables\ChangeLog\HilosChangeLogTableColumnsTable;
use Hilos\Tables\ChangeLog\HilosChangeLogTablesTable;
use Hilos\Tables\ChangeLog\HilosChangeLogTableTriggersTable;
use Hilos\Tables\Communications\HilosNotificationDeliveriesTable;
use Hilos\Tables\Daemon\HilosDaemonAgentsTable;
use Hilos\Tables\Daemon\HilosDaemonCronTable;
use Hilos\Tables\Daemon\HilosDaemonEnvKeysTable;
use Hilos\Tables\Daemon\HilosDaemonEnvMismatchTable;
use Hilos\Tables\Daemon\HilosDaemonEnvOrphansTable;
use Hilos\Tables\Daemon\HilosDaemonHttpRoutesTable;
use Hilos\Tables\Daemon\HilosDaemonPlacementTable;
use Hilos\Tables\Daemon\HilosDaemonWorkersTable;
use Hilos\Tables\Legal\HilosPrivacyHistoryTable;
use Hilos\Tables\Legal\HilosTermsHistoryTable;
use Hilos\Tables\Logs\HilosLogKeysTable;
use Hilos\Tables\Logs\HilosLogNodesTable;
use Hilos\Tables\Logs\HilosLogRotationsTable;
use Hilos\Tables\Logs\HilosLogWorkersTable;
use Hilos\Tables\Settings\HilosSettingsTable;
use Hilos\Tables\Appearance\HilosAppearanceSettingsTable;

/**
 * ConcertTicketsTableContext - table bindings for this demo's admin sections.
 *
 * @property-read HilosSettingsTable $settings
 * @property-read HilosAppearanceSettingsTable $hilosAppearanceSettings
 * @property-read HilosNotificationDeliveriesTable $hilosNotificationDeliveries
 * @property-read HilosUsersTable $hilosUsers
 * @property-read HilosAnalyticsActionsTable $hilosAnalyticsActions
 * @property-read HilosAnalyticsBrowserSessionListTable $hilosAnalyticsBrowserSessionList
 * @property-read HilosAnalyticsBrowserSessionsTable $hilosAnalyticsBrowserSessions
 * @property-read HilosAnalyticsPeopleTable $hilosAnalyticsPeople
 * @property-read HilosTermsHistoryTable $hilosTermsHistory
 * @property-read HilosPrivacyHistoryTable $hilosPrivacyHistory
 * @property-read HilosChangeLogFeedTable $hilosChangeLogFeed
 * @property-read HilosChangeLogHistoryTable $hilosChangeLogHistory
 * @property-read HilosChangeLogTableColumnsTable $hilosChangeLogTableColumns
 * @property-read HilosChangeLogTableTriggersTable $hilosChangeLogTableTriggers
 * @property-read HilosChangeLogNoisyColumnsTable $hilosChangeLogNoisyColumns
 * @property-read HilosChangeLogTablesTable $hilosChangeLogTables
 * @property-read HilosLogKeysTable $hilosLogKeys
 * @property-read HilosLogNodesTable $hilosLogNodes
 * @property-read HilosLogRotationsTable $hilosLogRotations
 * @property-read HilosLogWorkersTable $hilosLogWorkers
 * @property-read HilosDaemonCronTable $hilosDaemonCron
 * @property-read HilosDaemonWorkersTable $hilosDaemonWorkers
 * @property-read HilosDaemonEnvKeysTable $hilosDaemonEnvKeys
 * @property-read HilosDaemonEnvOrphansTable $hilosDaemonEnvOrphans
 * @property-read HilosDaemonAgentsTable $hilosDaemonAgents
 * @property-read HilosDaemonEnvMismatchTable $hilosDaemonEnvMismatch
 * @property-read HilosDaemonHttpRoutesTable $hilosDaemonHttpRoutes
 * @property-read HilosDaemonPlacementTable $hilosDaemonPlacement
 */
final class ConcertTicketsTableContext extends TableContext
{

    public const string settings = HilosSettingsTable::TABLE;
    public const string hilosAppearanceSettings = HilosAppearanceSettingsTable::TABLE;
    public const string hilosNotificationDeliveries = HilosNotificationDeliveriesTable::TABLE;
    public const string hilosUsers = 'hilosUsers';
    public const string hilosAnalyticsActions = HilosAnalyticsActionsTable::TABLE;
    public const string hilosAnalyticsBrowserSessionList = HilosAnalyticsBrowserSessionListTable::TABLE;
    public const string hilosAnalyticsBrowserSessions = HilosAnalyticsBrowserSessionsTable::TABLE;
    public const string hilosAnalyticsPeople = HilosAnalyticsPeopleTable::TABLE;
    public const string hilosTermsHistory = HilosTermsHistoryTable::TABLE;
    public const string hilosPrivacyHistory = HilosPrivacyHistoryTable::TABLE;
    public const string hilosChangeLogFeed = HilosChangeLogFeedTable::TABLE;
    public const string hilosChangeLogHistory = HilosChangeLogHistoryTable::TABLE;
    public const string hilosChangeLogTableColumns = HilosChangeLogTableColumnsTable::TABLE;
    public const string hilosChangeLogTableTriggers = HilosChangeLogTableTriggersTable::TABLE;
    public const string hilosChangeLogNoisyColumns = HilosChangeLogNoisyColumnsTable::TABLE;
    public const string hilosChangeLogTables = HilosChangeLogTablesTable::TABLE;
    public const string hilosLogKeys = HilosLogKeysTable::TABLE;
    public const string hilosLogNodes = HilosLogNodesTable::TABLE;
    public const string hilosLogRotations = HilosLogRotationsTable::TABLE;
    public const string hilosLogWorkers = HilosLogWorkersTable::TABLE;
    public const string hilosDaemonCron = HilosDaemonCronTable::TABLE;
    public const string hilosDaemonWorkers = HilosDaemonWorkersTable::TABLE;
    public const string hilosDaemonEnvKeys = HilosDaemonEnvKeysTable::TABLE;
    public const string hilosDaemonEnvOrphans = HilosDaemonEnvOrphansTable::TABLE;
    public const string hilosDaemonAgents = HilosDaemonAgentsTable::TABLE;
    public const string hilosDaemonEnvMismatch = HilosDaemonEnvMismatchTable::TABLE;
    public const string hilosDaemonHttpRoutes = HilosDaemonHttpRoutesTable::TABLE;
    public const string hilosDaemonPlacement = HilosDaemonPlacementTable::TABLE;

    /**
     * Registers table definitions from the project topology registry.
     */
    public function configure(): void
    {
        foreach (Hilos::TABLES as $tableName => $tableClass) {
            $this->register($tableName, new $tableClass());
        }
    }
}

<?php

declare(strict_types=1);

namespace Demo\RobloxGameServer\Tables;

use Demo\RobloxGameServer\Hilos;
use Demo\RobloxGameServer\Tables\HilosUser\HilosUsersTable;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Tables\ChangeLog\HilosChangeLogFeedTable;
use Hilos\Tables\Legal\HilosTermsHistoryTable;
use Hilos\Tables\Legal\HilosPrivacyHistoryTable;
use Hilos\Tables\ChangeLog\HilosChangeLogHistoryTable;
use Hilos\Tables\ChangeLog\HilosChangeLogTableColumnsTable;
use Hilos\Tables\ChangeLog\HilosChangeLogTableTriggersTable;
use Hilos\Tables\ChangeLog\HilosChangeLogNoisyColumnsTable;
use Hilos\Tables\ChangeLog\HilosChangeLogTablesTable;
use Hilos\Tables\Analytics\HilosAnalyticsActionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionListTable;
use Hilos\Tables\Analytics\HilosAnalyticsPeopleTable;
use Hilos\Tables\Appearance\HilosAppearanceSettingsTable;
use Hilos\Tables\Backup\HilosBackupHistoryTable;
use Hilos\Tables\Communications\HilosCommunicationsChannelFieldsTable;
use Hilos\Tables\Communications\HilosCommunicationsChannelsTable;
use Hilos\Tables\Communications\HilosNotificationDeliveriesTable;
use Hilos\Tables\Logs\HilosLogKeysTable;
use Hilos\Tables\Logs\HilosLogNodesTable;
use Hilos\Tables\Logs\HilosLogRotationsTable;
use Hilos\Tables\Daemon\HilosDaemonCronTable;
use Hilos\Tables\Daemon\HilosDaemonWorkersTable;
use Hilos\Tables\Daemon\HilosDaemonEnvKeysTable;
use Hilos\Tables\Daemon\HilosDaemonEnvOrphansTable;
use Hilos\Tables\Daemon\HilosDaemonAgentsTable;
use Hilos\Tables\Daemon\HilosDaemonEnvMismatchTable;
use Hilos\Tables\Daemon\HilosDaemonHttpRoutesTable;
use Hilos\Tables\Daemon\HilosDaemonPlacementTable;
use Hilos\Tables\Logs\HilosLogWorkersTable;
use Hilos\Tables\Moderation\HilosModerationKindsTable;
use Hilos\Tables\Moderation\HilosModerationRulesTable;
use Hilos\Tables\ProtectedMode\HilosVerifierCircleTable;
use Hilos\Tables\ProtectedMode\HilosMaintenanceNodesTable;
use Hilos\Tables\ProtectedMode\HilosMaintenanceWordsTable;
use Hilos\Tables\Settings\HilosSettingsTable;

/**
 * RobloxGameServerTableContext - App-specific table context ($table layer) for roblox-game-server.
 *
 * Registers whatever the project topology lists - the framework tables of every admin section
 * this demo activates (backup, maintenance, settings and appearance, users, logs, daemon,
 * communications, change log, analytics, moderation) and the two legal history tables - each
 * accessed under its key, such as Hilos::$table->hilosBackups or Hilos::$table->hilosUsers.
 *
 * @property-read HilosChangeLogFeedTable $hilosChangeLogFeed
 * @property-read HilosTermsHistoryTable $hilosTermsHistory
 * @property-read HilosPrivacyHistoryTable $hilosPrivacyHistory
 * @property-read HilosChangeLogHistoryTable $hilosChangeLogHistory
 * @property-read HilosChangeLogTableColumnsTable $hilosChangeLogTableColumns
 * @property-read HilosChangeLogTableTriggersTable $hilosChangeLogTableTriggers
 * @property-read HilosChangeLogNoisyColumnsTable $hilosChangeLogNoisyColumns
 * @property-read HilosChangeLogTablesTable $hilosChangeLogTables
 * @property-read HilosBackupHistoryTable $hilosBackups
 * @property-read HilosVerifierCircleTable $hilosVerifierCircle
 * @property-read HilosMaintenanceNodesTable $hilosMaintenanceNodes
 * @property-read HilosMaintenanceWordsTable $hilosMaintenanceWords
 * @property-read HilosSettingsTable $settings
 * @property-read HilosAppearanceSettingsTable $hilosAppearanceSettings
 * @property-read HilosUsersTable $hilosUsers
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
 * @property-read HilosCommunicationsChannelsTable $hilosCommunicationsChannels
 * @property-read HilosCommunicationsChannelFieldsTable $hilosCommunicationsChannelFields
 * @property-read HilosNotificationDeliveriesTable $hilosNotificationDeliveries
 * @property-read HilosAnalyticsActionsTable $hilosAnalyticsActions
 * @property-read HilosAnalyticsBrowserSessionsTable $hilosAnalyticsBrowserSessions
 * @property-read HilosAnalyticsBrowserSessionListTable $hilosAnalyticsBrowserSessionList
 * @property-read HilosAnalyticsPeopleTable $hilosAnalyticsPeople
 * @property-read HilosModerationKindsTable $hilosModerationKinds
 * @property-read HilosModerationRulesTable $hilosModerationRules
 */
final class RobloxGameServerTableContext extends TableContext
{
    public const string hilosChangeLogFeed = HilosChangeLogFeedTable::TABLE;
    public const string hilosTermsHistory = HilosTermsHistoryTable::TABLE;
    public const string hilosPrivacyHistory = HilosPrivacyHistoryTable::TABLE;
    public const string hilosChangeLogHistory = HilosChangeLogHistoryTable::TABLE;
    public const string hilosChangeLogTableColumns = HilosChangeLogTableColumnsTable::TABLE;
    public const string hilosChangeLogTableTriggers = HilosChangeLogTableTriggersTable::TABLE;
    public const string hilosChangeLogNoisyColumns = HilosChangeLogNoisyColumnsTable::TABLE;
    public const string hilosChangeLogTables = HilosChangeLogTablesTable::TABLE;
    public const string hilosBackups = HilosBackupHistoryTable::TABLE;
    public const string hilosVerifierCircle = HilosVerifierCircleTable::TABLE;
    public const string hilosMaintenanceNodes = HilosMaintenanceNodesTable::TABLE;
    public const string hilosMaintenanceWords = HilosMaintenanceWordsTable::TABLE;
    public const string settings = HilosSettingsTable::TABLE;
    public const string hilosAppearanceSettings = HilosAppearanceSettingsTable::TABLE;
    public const string hilosUsers = 'hilosUsers';
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
    public const string hilosCommunicationsChannels = HilosCommunicationsChannelsTable::TABLE;
    public const string hilosCommunicationsChannelFields = HilosCommunicationsChannelFieldsTable::TABLE;
    public const string hilosNotificationDeliveries = HilosNotificationDeliveriesTable::TABLE;
    public const string hilosAnalyticsActions = HilosAnalyticsActionsTable::TABLE;
    public const string hilosAnalyticsBrowserSessions = HilosAnalyticsBrowserSessionsTable::TABLE;
    public const string hilosAnalyticsBrowserSessionList = HilosAnalyticsBrowserSessionListTable::TABLE;
    public const string hilosAnalyticsPeople = HilosAnalyticsPeopleTable::TABLE;
    public const string hilosModerationKinds = HilosModerationKindsTable::TABLE;
    public const string hilosModerationRules = HilosModerationRulesTable::TABLE;

    /**
     * Registers roblox-game-server table definitions from the project topology registry.
     */
    public function configure(): void
    {
        foreach (Hilos::TABLES as $tableName => $tableClass) {
            $this->register($tableName, new $tableClass());
        }
    }
}

<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Tables;

use Demo\EcommerceShop\Hilos;
use Demo\EcommerceShop\Tables\HilosUser\HilosUsersTable;
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
use Hilos\Tables\Appearance\HilosAppearanceSettingsTable;
use Hilos\Tables\Communications\HilosCommunicationsChannelFieldsTable;
use Hilos\Tables\Communications\HilosCommunicationsChannelsTable;
use Hilos\Tables\Communications\HilosNotificationDeliveriesTable;
use Hilos\Tables\Daemon\HilosDaemonAgentsTable;
use Hilos\Tables\Daemon\HilosDaemonCronTable;
use Hilos\Tables\Daemon\HilosDaemonEnvMismatchTable;
use Hilos\Tables\Daemon\HilosDaemonEnvKeysTable;
use Hilos\Tables\Daemon\HilosDaemonEnvOrphansTable;
use Hilos\Tables\Daemon\HilosDaemonHttpRoutesTable;
use Hilos\Tables\Daemon\HilosDaemonPlacementTable;
use Hilos\Tables\Daemon\HilosDaemonWorkersTable;
use Hilos\Tables\I18n\HilosI18nCountriesTable;
use Hilos\Tables\I18n\HilosI18nCountryNamesTable;
use Hilos\Tables\I18n\HilosI18nLanguageLocalesTable;
use Hilos\Tables\I18n\HilosI18nLanguageNamesTable;
use Hilos\Tables\I18n\HilosI18nLanguagesTable;
use Hilos\Tables\Backup\HilosBackupHistoryTable;
use Hilos\Tables\ProtectedMode\HilosVerifierCircleTable;
use Hilos\Tables\ProtectedMode\HilosMaintenanceNodesTable;
use Hilos\Tables\ProtectedMode\HilosMaintenanceWordsTable;
use Hilos\Tables\Settings\HilosSettingsTable;

/**
 * EcommerceShopTableContext - App-specific table context ($table layer) for this demo.
 *
 * Registers whatever the project topology lists: today the backup section's archive table, the
 * maintenance section's verifier circle table, the settings table and the people table, accessed
 * via Hilos::$table->hilosBackups, Hilos::$table->hilosVerifierCircle, Hilos::$table->settings and
 * Hilos::$table->hilosUsers. The other admin sections arrive with the leaves that move their e2e
 * onto this demo, each with its table.
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
 * @property-read HilosCommunicationsChannelsTable $hilosCommunicationsChannels
 * @property-read HilosCommunicationsChannelFieldsTable $hilosCommunicationsChannelFields
 * @property-read HilosNotificationDeliveriesTable $hilosNotificationDeliveries
 * @property-read HilosUsersTable $hilosUsers
 * @property-read HilosI18nLanguageNamesTable $hilosI18nLanguageNames
 * @property-read HilosI18nLanguagesTable $hilosI18nLanguages
 * @property-read HilosI18nCountriesTable $hilosI18nCountries
 * @property-read HilosI18nCountryNamesTable $hilosI18nCountryNames
 * @property-read HilosI18nLanguageLocalesTable $hilosI18nLanguageLocales
 * @property-read HilosAnalyticsActionsTable $hilosAnalyticsActions
 * @property-read HilosAnalyticsBrowserSessionsTable $hilosAnalyticsBrowserSessions
 * @property-read HilosDaemonCronTable $hilosDaemonCron
 * @property-read HilosDaemonWorkersTable $hilosDaemonWorkers
 * @property-read HilosDaemonEnvKeysTable $hilosDaemonEnvKeys
 * @property-read HilosDaemonEnvOrphansTable $hilosDaemonEnvOrphans
 * @property-read HilosDaemonAgentsTable $hilosDaemonAgents
 * @property-read HilosDaemonEnvMismatchTable $hilosDaemonEnvMismatch
 * @property-read HilosDaemonHttpRoutesTable $hilosDaemonHttpRoutes
 * @property-read HilosDaemonPlacementTable $hilosDaemonPlacement
 */
final class EcommerceShopTableContext extends TableContext
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
    public const string hilosCommunicationsChannels = HilosCommunicationsChannelsTable::TABLE;
    public const string hilosCommunicationsChannelFields = HilosCommunicationsChannelFieldsTable::TABLE;
    public const string hilosNotificationDeliveries = HilosNotificationDeliveriesTable::TABLE;
    public const string hilosUsers = 'hilosUsers';
    public const string hilosI18nLanguageNames = HilosI18nLanguageNamesTable::TABLE;
    public const string hilosI18nLanguages = HilosI18nLanguagesTable::TABLE;
    public const string hilosI18nCountries = HilosI18nCountriesTable::TABLE;
    public const string hilosI18nCountryNames = HilosI18nCountryNamesTable::TABLE;
    public const string hilosI18nLanguageLocales = HilosI18nLanguageLocalesTable::TABLE;
    public const string hilosAnalyticsActions = HilosAnalyticsActionsTable::TABLE;
    public const string hilosAnalyticsBrowserSessions = HilosAnalyticsBrowserSessionsTable::TABLE;
    public const string hilosDaemonCron = HilosDaemonCronTable::TABLE;
    public const string hilosDaemonWorkers = HilosDaemonWorkersTable::TABLE;
    public const string hilosDaemonEnvKeys = HilosDaemonEnvKeysTable::TABLE;
    public const string hilosDaemonEnvOrphans = HilosDaemonEnvOrphansTable::TABLE;
    public const string hilosDaemonAgents = HilosDaemonAgentsTable::TABLE;
    public const string hilosDaemonEnvMismatch = HilosDaemonEnvMismatchTable::TABLE;
    public const string hilosDaemonHttpRoutes = HilosDaemonHttpRoutesTable::TABLE;
    public const string hilosDaemonPlacement = HilosDaemonPlacementTable::TABLE;

    /**
     * Registers this demo's table definitions from the project topology registry.
     */
    public function configure(): void
    {
        foreach (Hilos::TABLES as $tableName => $tableClass) {
            $this->register($tableName, new $tableClass());
        }
    }
}

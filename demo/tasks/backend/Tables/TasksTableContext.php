<?php

declare(strict_types=1);

namespace Demo\Tasks\Tables;

use Demo\Tasks\Tables\HilosLegal\HilosLegalAcceptancesTable;
use Hilos\Tables\Analytics\HilosAnalyticsActionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionsTable;
use Hilos\Tables\Appearance\HilosAppearanceSettingsTable;
use Hilos\Tables\Communications\HilosCommunicationsChannelFieldsTable;
use Hilos\Tables\Communications\HilosCommunicationsChannelsTable;
use Hilos\Tables\Communications\HilosNotificationDeliveriesTable;
use Hilos\Tables\Legal\HilosLegalDocumentsTable;
use Hilos\Tables\Legal\HilosLegalChecksTable;
use Hilos\Tables\Legal\HilosLegalRevisionsTable;
use Hilos\Tables\Legal\HilosLegalSettingsTable;

use Demo\Tasks\Hilos;
use Demo\Tasks\Tables\HilosUser\HilosUsersTable;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Tables\I18n\HilosI18nCountriesTable;
use Hilos\Tables\I18n\HilosI18nCountryNamesTable;
use Hilos\Tables\I18n\HilosI18nLanguageLocalesTable;
use Hilos\Tables\I18n\HilosI18nLanguageNamesTable;
use Hilos\Tables\I18n\HilosI18nLanguagesTable;
use Hilos\Tables\Backup\HilosBackupHistoryTable;
use Hilos\Tables\Logs\HilosLogKeysTable;
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
use Hilos\Tables\ProtectedMode\HilosVerifierCircleTable;
use Hilos\Tables\ProtectedMode\HilosMaintenanceWordsTable;
use Hilos\Tables\Security\HilosSecurityOAuthProviderFieldsTable;
use Hilos\Tables\Security\HilosSecurityOAuthProvidersTable;
use Hilos\Tables\Security\HilosSecurityOAuthRedirectTable;
use Hilos\Tables\Security\HilosSecuritySignInMethodsTable;
use Hilos\Tables\Security\HilosSecurityTwoFactorTable;
use Hilos\Tables\Security\HilosSecurityStepUpTable;
use Hilos\Tables\Security\HilosSecurityImpersonationTable;
use Hilos\Tables\Settings\HilosSettingsTable;
use Hilos\Tables\ChangeLog\HilosChangeLogFeedTable;
use Hilos\Tables\ChangeLog\HilosChangeLogHistoryTable;
use Hilos\Tables\ChangeLog\HilosChangeLogNoisyColumnsTable;
use Hilos\Tables\ChangeLog\HilosChangeLogTablesTable;

/**
 * TasksTableContext - App-specific table context ($table layer) for tasks.
 *
 * Registers the framework settings, log and backup tables (as-is) and the project's
 * Hilos users table activation; accessed via Hilos::$table->settings /
 * Hilos::$table->hilosUsers.
 *
 * @property-read HilosChangeLogFeedTable $hilosChangeLogFeed
 * @property-read HilosChangeLogHistoryTable $hilosChangeLogHistory
 * @property-read HilosChangeLogNoisyColumnsTable $hilosChangeLogNoisyColumns
 * @property-read HilosChangeLogTablesTable $hilosChangeLogTables
 * @property-read HilosBackupHistoryTable $hilosBackups
 * @property-read HilosLogKeysTable $hilosLogKeys
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
 * @property-read HilosUsersTable $hilosUsers
 * @property-read HilosSettingsTable $settings
 * @property-read HilosAppearanceSettingsTable $hilosAppearanceSettings
 * @property-read HilosVerifierCircleTable $hilosVerifierCircle
 * @property-read HilosMaintenanceWordsTable $hilosMaintenanceWords
 * @property-read HilosSecurityOAuthProvidersTable $hilosSecurityOauthProviders
 * @property-read HilosSecurityOAuthProviderFieldsTable $hilosSecurityOauthProviderFields
 * @property-read HilosSecurityOAuthRedirectTable $hilosSecurityOauthRedirect
 * @property-read HilosSecuritySignInMethodsTable $hilosSecuritySignInMethods
 * @property-read HilosSecurityTwoFactorTable $hilosSecurityTwoFactor
 * @property-read HilosLegalDocumentsTable $hilosLegalDocuments
 * @property-read HilosLegalChecksTable $hilosLegalChecks
 * @property-read HilosLegalRevisionsTable $hilosLegalRevisions
 * @property-read HilosLegalAcceptancesTable $hilosLegalAcceptances
 * @property-read HilosLegalSettingsTable $hilosLegalSettings
 * @property-read HilosSecurityStepUpTable $hilosSecurityStepUp
 * @property-read HilosSecurityImpersonationTable $hilosSecurityImpersonation
 * @property-read HilosI18nLanguageNamesTable $hilosI18nLanguageNames
 * @property-read HilosI18nLanguagesTable $hilosI18nLanguages
 * @property-read HilosI18nCountriesTable $hilosI18nCountries
 * @property-read HilosI18nCountryNamesTable $hilosI18nCountryNames
 * @property-read HilosI18nLanguageLocalesTable $hilosI18nLanguageLocales
 * @property-read HilosAnalyticsActionsTable $hilosAnalyticsActions
 * @property-read HilosAnalyticsBrowserSessionsTable $hilosAnalyticsBrowserSessions
 */
final class TasksTableContext extends TableContext
{
    public const string hilosChangeLogFeed = HilosChangeLogFeedTable::TABLE;
    public const string hilosChangeLogHistory = HilosChangeLogHistoryTable::TABLE;
    public const string hilosChangeLogNoisyColumns = HilosChangeLogNoisyColumnsTable::TABLE;
    public const string hilosChangeLogTables = HilosChangeLogTablesTable::TABLE;
    public const string hilosBackups = HilosBackupHistoryTable::TABLE;
    public const string hilosLogKeys = HilosLogKeysTable::TABLE;
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
    public const string hilosUsers = 'hilosUsers';
    public const string settings = HilosSettingsTable::TABLE;
    public const string hilosAppearanceSettings = HilosAppearanceSettingsTable::TABLE;
    public const string hilosVerifierCircle = HilosVerifierCircleTable::TABLE;
    public const string hilosMaintenanceWords = HilosMaintenanceWordsTable::TABLE;
    public const string hilosSecurityOauthProviders = HilosSecurityOAuthProvidersTable::TABLE;
    public const string hilosSecurityOauthProviderFields = HilosSecurityOAuthProviderFieldsTable::TABLE;
    public const string hilosSecurityOauthRedirect = HilosSecurityOAuthRedirectTable::TABLE;
    public const string hilosSecuritySignInMethods = HilosSecuritySignInMethodsTable::TABLE;
    public const string hilosSecurityTwoFactor = HilosSecurityTwoFactorTable::TABLE;
    public const string hilosLegalDocuments = HilosLegalDocumentsTable::TABLE;
    public const string hilosLegalChecks = HilosLegalChecksTable::TABLE;
    public const string hilosLegalRevisions = HilosLegalRevisionsTable::TABLE;
    public const string hilosLegalAcceptances = HilosLegalAcceptancesTable::TABLE;
    public const string hilosLegalSettings = HilosLegalSettingsTable::TABLE;
    public const string hilosSecurityStepUp = HilosSecurityStepUpTable::TABLE;
    public const string hilosSecurityImpersonation = HilosSecurityImpersonationTable::TABLE;
    public const string hilosI18nLanguageNames = HilosI18nLanguageNamesTable::TABLE;
    public const string hilosI18nLanguages = HilosI18nLanguagesTable::TABLE;
    public const string hilosI18nCountries = HilosI18nCountriesTable::TABLE;
    public const string hilosI18nCountryNames = HilosI18nCountryNamesTable::TABLE;
    public const string hilosI18nLanguageLocales = HilosI18nLanguageLocalesTable::TABLE;
    public const string hilosAnalyticsActions = HilosAnalyticsActionsTable::TABLE;
    public const string hilosAnalyticsBrowserSessions = HilosAnalyticsBrowserSessionsTable::TABLE;

    /**
     * Registers tasks table definitions from the project topology registry.
     */
    public function configure(): void
    {
        foreach (Hilos::TABLES as $tableName => $tableClass) {
            $this->register($tableName, new $tableClass());
        }
    }
}

<?php

declare(strict_types=1);

namespace Demo\OnlineTesting\Tables;

use Demo\OnlineTesting\Hilos;
use Demo\OnlineTesting\Tables\HilosUser\HilosUsersTable;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Tables\Analytics\HilosAnalyticsActionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionsTable;
use Hilos\Tables\I18n\HilosI18nCountriesTable;
use Hilos\Tables\I18n\HilosI18nCountryNamesTable;
use Hilos\Tables\I18n\HilosI18nLanguageLocalesTable;
use Hilos\Tables\I18n\HilosI18nLanguageNamesTable;
use Hilos\Tables\I18n\HilosI18nLanguagesTable;
use Hilos\Tables\Logs\HilosLogKeysTable;
use Hilos\Tables\Logs\HilosLogRotationsTable;
use Hilos\Tables\Daemon\HilosDaemonCronTable;
use Hilos\Tables\Daemon\HilosDaemonWorkersTable;
use Hilos\Tables\Daemon\HilosDaemonEnvKeysTable;
use Hilos\Tables\Daemon\HilosDaemonEnvOrphansTable;
use Hilos\Tables\Daemon\HilosDaemonAgentsTable;
use Hilos\Tables\Daemon\HilosDaemonEnvDivergedTable;
use Hilos\Tables\Daemon\HilosDaemonEnvSourcesDifferTable;
use Hilos\Tables\Daemon\HilosDaemonHttpRoutesTable;
use Hilos\Tables\Daemon\HilosDaemonPlacementTable;
use Hilos\Tables\Logs\HilosLogWorkersTable;
use Hilos\Tables\ProtectedMode\HilosVerifierCircleTable;
use Hilos\Tables\Settings\HilosSettingsTable;
use Hilos\Tables\Appearance\HilosAppearanceSettingsTable;
use Hilos\Tables\ChangeLog\HilosChangeLogFeedTable;
use Hilos\Tables\ChangeLog\HilosChangeLogHistoryTable;
use Hilos\Tables\ChangeLog\HilosChangeLogTablesTable;

/**
 * OnlineTestingTableContext - table bindings for this demo's admin sections.
 *
 * @property-read HilosChangeLogFeedTable $hilosChangeLogFeed
 * @property-read HilosChangeLogHistoryTable $hilosChangeLogHistory
 * @property-read HilosChangeLogTablesTable $hilosChangeLogTables
 * @property-read HilosSettingsTable $settings
 * @property-read HilosAppearanceSettingsTable $hilosAppearanceSettings
 * @property-read HilosUsersTable $hilosUsers
 * @property-read HilosVerifierCircleTable $hilosVerifierCircle
 * @property-read HilosLogKeysTable $hilosLogKeys
 * @property-read HilosLogRotationsTable $hilosLogRotations
 * @property-read HilosLogWorkersTable $hilosLogWorkers
 * @property-read HilosDaemonCronTable $hilosDaemonCron
 * @property-read HilosDaemonWorkersTable $hilosDaemonWorkers
 * @property-read HilosDaemonEnvKeysTable $hilosDaemonEnvKeys
 * @property-read HilosDaemonEnvOrphansTable $hilosDaemonEnvOrphans
 * @property-read HilosDaemonAgentsTable $hilosDaemonAgents
 * @property-read HilosDaemonEnvDivergedTable $hilosDaemonEnvDiverged
 * @property-read HilosDaemonEnvSourcesDifferTable $hilosDaemonEnvSourcesDiffer
 * @property-read HilosDaemonHttpRoutesTable $hilosDaemonHttpRoutes
 * @property-read HilosDaemonPlacementTable $hilosDaemonPlacement
 * @property-read HilosI18nLanguageNamesTable $hilosI18nLanguageNames
 * @property-read HilosI18nLanguagesTable $hilosI18nLanguages
 * @property-read HilosI18nCountriesTable $hilosI18nCountries
 * @property-read HilosI18nCountryNamesTable $hilosI18nCountryNames
 * @property-read HilosI18nLanguageLocalesTable $hilosI18nLanguageLocales
 * @property-read HilosAnalyticsActionsTable $hilosAnalyticsActions
 * @property-read HilosAnalyticsBrowserSessionsTable $hilosAnalyticsBrowserSessions
 */
final class OnlineTestingTableContext extends TableContext
{
    public const string hilosChangeLogFeed = HilosChangeLogFeedTable::TABLE;
    public const string hilosChangeLogHistory = HilosChangeLogHistoryTable::TABLE;
    public const string hilosChangeLogTables = HilosChangeLogTablesTable::TABLE;
    public const string settings = HilosSettingsTable::TABLE;
    public const string hilosAppearanceSettings = HilosAppearanceSettingsTable::TABLE;
    public const string hilosUsers = 'hilosUsers';
    public const string hilosVerifierCircle = HilosVerifierCircleTable::TABLE;
    public const string hilosLogKeys = HilosLogKeysTable::TABLE;
    public const string hilosLogRotations = HilosLogRotationsTable::TABLE;
    public const string hilosLogWorkers = HilosLogWorkersTable::TABLE;
    public const string hilosDaemonCron = HilosDaemonCronTable::TABLE;
    public const string hilosDaemonWorkers = HilosDaemonWorkersTable::TABLE;
    public const string hilosDaemonEnvKeys = HilosDaemonEnvKeysTable::TABLE;
    public const string hilosDaemonEnvOrphans = HilosDaemonEnvOrphansTable::TABLE;
    public const string hilosDaemonAgents = HilosDaemonAgentsTable::TABLE;
    public const string hilosDaemonEnvDiverged = HilosDaemonEnvDivergedTable::TABLE;
    public const string hilosDaemonEnvSourcesDiffer = HilosDaemonEnvSourcesDifferTable::TABLE;
    public const string hilosDaemonHttpRoutes = HilosDaemonHttpRoutesTable::TABLE;
    public const string hilosDaemonPlacement = HilosDaemonPlacementTable::TABLE;
    public const string hilosI18nLanguageNames = HilosI18nLanguageNamesTable::TABLE;
    public const string hilosI18nLanguages = HilosI18nLanguagesTable::TABLE;
    public const string hilosI18nCountries = HilosI18nCountriesTable::TABLE;
    public const string hilosI18nCountryNames = HilosI18nCountryNamesTable::TABLE;
    public const string hilosI18nLanguageLocales = HilosI18nLanguageLocalesTable::TABLE;
    public const string hilosAnalyticsActions = HilosAnalyticsActionsTable::TABLE;
    public const string hilosAnalyticsBrowserSessions = HilosAnalyticsBrowserSessionsTable::TABLE;

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

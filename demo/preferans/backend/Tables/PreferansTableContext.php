<?php

declare(strict_types=1);

namespace Demo\Preferans\Tables;

use Demo\Preferans\Hilos;
use Demo\Preferans\Tables\HilosUser\HilosUsersTable;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Tables\Analytics\HilosAnalyticsActionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionListTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsPeopleTable;
use Hilos\Tables\Communications\HilosNotificationDeliveriesTable;
use Hilos\Tables\I18n\HilosI18nCountriesTable;
use Hilos\Tables\I18n\HilosI18nCountryNamesTable;
use Hilos\Tables\I18n\HilosI18nLanguageLocalesTable;
use Hilos\Tables\I18n\HilosI18nLanguageNamesTable;
use Hilos\Tables\I18n\HilosI18nLanguagesTable;
use Hilos\Tables\Legal\HilosPrivacyHistoryTable;
use Hilos\Tables\Legal\HilosTermsHistoryTable;
use Hilos\Tables\Settings\HilosSettingsTable;
use Hilos\Tables\Appearance\HilosAppearanceSettingsTable;

/**
 * PreferansTableContext - table bindings for this demo's admin sections.
 *
 * @property-read HilosSettingsTable $settings
 * @property-read HilosAppearanceSettingsTable $hilosAppearanceSettings
 * @property-read HilosNotificationDeliveriesTable $hilosNotificationDeliveries
 * @property-read HilosUsersTable $hilosUsers
 * @property-read HilosI18nLanguageNamesTable $hilosI18nLanguageNames
 * @property-read HilosI18nLanguagesTable $hilosI18nLanguages
 * @property-read HilosI18nCountriesTable $hilosI18nCountries
 * @property-read HilosI18nCountryNamesTable $hilosI18nCountryNames
 * @property-read HilosI18nLanguageLocalesTable $hilosI18nLanguageLocales
 * @property-read HilosAnalyticsActionsTable $hilosAnalyticsActions
 * @property-read HilosAnalyticsBrowserSessionListTable $hilosAnalyticsBrowserSessionList
 * @property-read HilosAnalyticsBrowserSessionsTable $hilosAnalyticsBrowserSessions
 * @property-read HilosAnalyticsPeopleTable $hilosAnalyticsPeople
 * @property-read HilosTermsHistoryTable $hilosTermsHistory
 * @property-read HilosPrivacyHistoryTable $hilosPrivacyHistory
 */
final class PreferansTableContext extends TableContext
{

    public const string settings = HilosSettingsTable::TABLE;
    public const string hilosAppearanceSettings = HilosAppearanceSettingsTable::TABLE;
    public const string hilosNotificationDeliveries = HilosNotificationDeliveriesTable::TABLE;
    public const string hilosUsers = 'hilosUsers';
    public const string hilosI18nLanguageNames = HilosI18nLanguageNamesTable::TABLE;
    public const string hilosI18nLanguages = HilosI18nLanguagesTable::TABLE;
    public const string hilosI18nCountries = HilosI18nCountriesTable::TABLE;
    public const string hilosI18nCountryNames = HilosI18nCountryNamesTable::TABLE;
    public const string hilosI18nLanguageLocales = HilosI18nLanguageLocalesTable::TABLE;
    public const string hilosAnalyticsActions = HilosAnalyticsActionsTable::TABLE;
    public const string hilosAnalyticsBrowserSessionList = HilosAnalyticsBrowserSessionListTable::TABLE;
    public const string hilosAnalyticsBrowserSessions = HilosAnalyticsBrowserSessionsTable::TABLE;
    public const string hilosAnalyticsPeople = HilosAnalyticsPeopleTable::TABLE;
    public const string hilosTermsHistory = HilosTermsHistoryTable::TABLE;
    public const string hilosPrivacyHistory = HilosPrivacyHistoryTable::TABLE;

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

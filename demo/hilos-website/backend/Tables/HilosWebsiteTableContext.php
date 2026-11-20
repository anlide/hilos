<?php

declare(strict_types=1);

namespace Demo\HilosWebsite\Tables;

use Demo\HilosWebsite\Hilos;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Tables\Analytics\HilosAnalyticsActionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionListTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsPeopleTable;
use Hilos\Tables\Communications\HilosNotificationDeliveriesTable;
use Hilos\Tables\Legal\HilosPrivacyHistoryTable;
use Hilos\Tables\Legal\HilosTermsHistoryTable;
use Hilos\Tables\Settings\HilosSettingsTable;

/**
 * HilosWebsiteTableContext - table bindings for this demo's admin sections.
 *
 * @property-read HilosSettingsTable $settings
 * @property-read HilosAnalyticsActionsTable $hilosAnalyticsActions
 * @property-read HilosAnalyticsBrowserSessionListTable $hilosAnalyticsBrowserSessionList
 * @property-read HilosAnalyticsBrowserSessionsTable $hilosAnalyticsBrowserSessions
 * @property-read HilosAnalyticsPeopleTable $hilosAnalyticsPeople
 * @property-read HilosTermsHistoryTable $hilosTermsHistory
 * @property-read HilosPrivacyHistoryTable $hilosPrivacyHistory
 * @property-read HilosNotificationDeliveriesTable $hilosNotificationDeliveries
 */
final class HilosWebsiteTableContext extends TableContext
{
    public const string settings = HilosSettingsTable::TABLE;
    public const string hilosAnalyticsActions = HilosAnalyticsActionsTable::TABLE;
    public const string hilosAnalyticsBrowserSessionList = HilosAnalyticsBrowserSessionListTable::TABLE;
    public const string hilosAnalyticsBrowserSessions = HilosAnalyticsBrowserSessionsTable::TABLE;
    public const string hilosAnalyticsPeople = HilosAnalyticsPeopleTable::TABLE;
    public const string hilosTermsHistory = HilosTermsHistoryTable::TABLE;
    public const string hilosPrivacyHistory = HilosPrivacyHistoryTable::TABLE;
    public const string hilosNotificationDeliveries = HilosNotificationDeliveriesTable::TABLE;

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

<?php

declare(strict_types=1);

namespace Demo\SolarSystemModel\Tables;

use Demo\SolarSystemModel\Hilos;
use Demo\SolarSystemModel\Tables\HilosUser\HilosUsersTable;
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
 * Solar System Model server table bindings.
 *
 * @property-read HilosSettingsTable $settings Registered table
 * @property-read HilosUsersTable $hilosUsers Registered table
 * @property-read HilosTermsHistoryTable $hilosTermsHistory Registered table
 * @property-read HilosPrivacyHistoryTable $hilosPrivacyHistory Registered table
 * @property-read HilosAnalyticsActionsTable $hilosAnalyticsActions Registered table
 * @property-read HilosAnalyticsBrowserSessionsTable $hilosAnalyticsBrowserSessions Registered table
 * @property-read HilosAnalyticsBrowserSessionListTable $hilosAnalyticsBrowserSessionList Registered table
 * @property-read HilosAnalyticsPeopleTable $hilosAnalyticsPeople Registered table
 * @property-read HilosNotificationDeliveriesTable $hilosNotificationDeliveries Registered table
 */
final class SolarSystemModelTableContext extends TableContext
{
    public const string settings = HilosSettingsTable::TABLE;
    public const string hilosUsers = 'hilosUsers';
    public const string hilosTermsHistory = HilosTermsHistoryTable::TABLE;
    public const string hilosPrivacyHistory = HilosPrivacyHistoryTable::TABLE;
    public const string hilosAnalyticsActions = HilosAnalyticsActionsTable::TABLE;
    public const string hilosAnalyticsBrowserSessions = HilosAnalyticsBrowserSessionsTable::TABLE;
    public const string hilosAnalyticsBrowserSessionList = HilosAnalyticsBrowserSessionListTable::TABLE;
    public const string hilosAnalyticsPeople = HilosAnalyticsPeopleTable::TABLE;
    public const string hilosNotificationDeliveries = HilosNotificationDeliveriesTable::TABLE;

    /** Registers table definitions declared by the Solar System Model facade. */
    public function configure(): void
    {
        foreach (Hilos::TABLES as $tableName => $tableClass) {
            $this->register($tableName, new $tableClass());
        }
    }
}

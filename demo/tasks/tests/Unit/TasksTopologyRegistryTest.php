<?php

declare(strict_types=1);

namespace Demo\Tasks\Tests\Unit;

use Demo\Tasks\Agents\Hilos\DemoHilosAnalyticsAgent;
use Demo\Tasks\Core\Agent\Daemon\Hilos\DemoHilosAnalyticsAgentDaemon;
use Demo\Tasks\Pages\Hilos\AnalyticsPage;
use Hilos\Core\Analytics\AnalyticsJournalAgent;
use Hilos\Core\Analytics\AnalyticsJournalAgentDaemon;
use Hilos\Core\Analytics\AnalyticsWriterAgent;
use Hilos\Core\Analytics\AnalyticsWriterAgentDaemon;
use Hilos\Core\Analytics\DTO\AnalyticsJournalAppendSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalLoadedSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalPortionSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalReadSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalReadySignalData;

use Demo\Tasks\Agents\Hilos\DemoHilosLegalAgent;
use Demo\Tasks\Core\Agent\Daemon\Hilos\DemoHilosLegalAgentDaemon;
use Demo\Tasks\Pages\Hilos\Legal\LegalPage;
use Demo\Tasks\Pages\Hilos\Legal\LegalDocumentPage;
use Demo\Tasks\Pages\Hilos\Legal\LegalRevisionPage;
use Demo\Tasks\Pages\Hilos\Legal\LegalAcceptancesPage;
use Demo\Tasks\Pages\Hilos\Legal\LegalSettingsPage;
use Demo\Tasks\Tables\HilosLegal\HilosLegalAcceptancesTable;
use Hilos\Tables\I18n\HilosI18nCountryNamesTable;
use Hilos\Tables\I18n\HilosI18nLanguageLocalesTable;
use Hilos\Tables\I18n\HilosI18nLanguageNamesTable;
use Hilos\Tables\I18n\HilosI18nLanguagesTable;
use Hilos\Tables\Legal\HilosLegalDocumentsTable;
use Hilos\Tables\Legal\HilosLegalChecksTable;
use Hilos\Tables\Legal\HilosLegalRevisionsTable;
use Hilos\Tables\Legal\HilosLegalSettingsTable;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Legal\Export\LegalAcceptancesExportHttp;
use Hilos\Legal\LegalSettings;
use Hilos\Legal\LegalSettingsCatalog;
use Hilos\Theme\ThemeSettingsCatalog;

use Hilos\DataExport\DataExportHttp;
use Hilos\Constants\HttpConstants;
use Hilos\DataExport\DataExportAgentDaemon;
use Demo\Tasks\Agents\Hilos\DataExportAgent;
use Demo\Tasks\Agents\Hilos\DemoHilosAgent;
use Demo\Tasks\Agents\Hilos\DemoHilosDaemonAgent;
use Demo\Tasks\Agents\Hilos\DemoHilosLogsAgent;
use Demo\Tasks\Agents\Hilos\UserAgent;
use Demo\Tasks\Agents\Hilos\UsersLibraryAgent;
use Demo\Tasks\Agents\OAuthAgent;
use Demo\Tasks\Agents\TasksAgent;
use Demo\Tasks\Constants\AgentType;
use Demo\Tasks\Constants\PageConstants;
use Demo\Tasks\Core\Agent\Daemon\Hilos\DemoHilosAgentDaemon;
use Demo\Tasks\Core\Agent\Daemon\Hilos\DemoHilosDaemonAgentDaemon;
use Demo\Tasks\Core\Agent\Daemon\Hilos\DemoHilosLogsAgentDaemon;
use Demo\Tasks\Core\Agent\Daemon\Hilos\UserAgentDaemon;
use Demo\Tasks\Core\Agent\Daemon\Hilos\UsersLibraryAgentDaemon;
use Demo\Tasks\Core\Agent\Daemon\OAuthAgentDaemon;
use Demo\Tasks\Core\Agent\Daemon\TasksAgentDaemon;
use Demo\Tasks\Database\Settings\TasksSettingsCatalog;
use Demo\Tasks\Database\TasksDbContext;
use Demo\Tasks\Hilos;
use Demo\Tasks\Groups\Hilos\NotificationsGroup;
use Demo\Tasks\Pages\Hilos\DashboardPage;
use Demo\Tasks\Pages\Hilos\Backup\BackupPage;
use Demo\Tasks\Pages\Hilos\Daemon\DaemonPage;
use Demo\Tasks\Pages\Hilos\Daemon\DaemonWorkersPage;
use Demo\Tasks\Pages\Hilos\Daemon\DaemonAgentsPage;
use Demo\Tasks\Pages\Hilos\Daemon\DaemonCronPage;
use Demo\Tasks\Pages\Hilos\Daemon\DaemonWebsocketsPage;
use Demo\Tasks\Pages\Hilos\Daemon\DaemonHttpServerPage;
use Demo\Tasks\Pages\Hilos\Daemon\DaemonEnvPage;
use Demo\Tasks\Pages\Hilos\Daemon\DaemonEnvMismatchPage;
use Demo\Tasks\Pages\Hilos\Logs\LogsKeysPage;
use Demo\Tasks\Pages\Hilos\Logs\LogsOverviewPage;
use Demo\Tasks\Pages\Hilos\Logs\LogsRotationsPage;
use Demo\Tasks\Pages\Hilos\Logs\LogsSettingsPage;
use Demo\Tasks\Pages\Hilos\Logs\LogsViewPage;
use Demo\Tasks\Pages\Hilos\Logs\LogsWorkersPage;
use Demo\Tasks\Pages\Hilos\Maintenance\MaintenancePage;
use Demo\Tasks\Pages\Hilos\SettingsPage;
use Demo\Tasks\Pages\Hilos\Security\SecurityOAuthPage;
use Demo\Tasks\Pages\Hilos\Security\SecurityOAuthProviderPage;
use Demo\Tasks\Pages\Hilos\Security\SecuritySignInMethodsPage;
use Demo\Tasks\Pages\Hilos\Security\SecurityImpersonationPage;
use Demo\Tasks\Pages\Hilos\Security\SecurityStepUpPage;
use Demo\Tasks\Pages\Hilos\Security\SecurityTwoFactorPage;
use Demo\Tasks\Pages\Hilos\ProfileSignInPage;
use Demo\Tasks\Pages\Hilos\Users\UserPage;
use Demo\Tasks\Pages\Hilos\Users\UsersPage;
use Hilos\Pages\Profile\HilosProfileIdentitiesBrowserList;
use Demo\Tasks\Pages\MainPage;
use Demo\Tasks\Runtime\View\Context\TasksRtContext;
use Demo\Tasks\Tables\HilosUser\HilosUsersTable;
use Demo\Tasks\Tables\TasksTableContext;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Auth\Session\DTO\SessionsSweptSignalData;
use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\Agent\BackupAgentDaemon;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Daemon\DaemonCollectorAgentDaemon;
use Hilos\Core\Agent\Daemon\DaemonNodeAgentDaemon;
use Hilos\Core\Agent\Hilos\DaemonCollectorAgent;
use Hilos\Core\Agent\Hilos\DaemonNodeAgent;
use Hilos\Core\CLI\CliManager;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Log\LogSettingsCatalog;
use Hilos\Notification\NotificationAction;
use Hilos\Notification\NotificationPreferenceAction;
use Hilos\Push\PushSubscriptionAction;
use Hilos\Tables\Backup\HilosBackupHistoryTable;
use Hilos\Tables\Logs\HilosLogKeysTable;
use Hilos\Tables\Logs\HilosLogRotationsTable;
use Hilos\Tables\Daemon\HilosDaemonCronTable;
use Hilos\Tables\Daemon\HilosDaemonWorkersTable;
use Hilos\Tables\Daemon\HilosDaemonAgentsTable;
use Hilos\Tables\Logs\HilosLogWorkersTable;
use Hilos\Tables\ProtectedMode\HilosVerifierCircleTable;
use Hilos\Tables\Security\HilosSecurityOAuthProviderFieldsTable;
use Hilos\Tables\Security\HilosSecurityOAuthProvidersTable;
use Hilos\Tables\Security\HilosSecurityOAuthRedirectTable;
use Hilos\Tables\Security\HilosSecuritySignInMethodsTable;
use Hilos\Tables\Security\HilosSecurityTwoFactorTable;
use Hilos\Tables\Security\HilosSecurityStepUpTable;
use Hilos\Tables\Security\HilosSecurityImpersonationTable;
use Hilos\Tables\Settings\HilosSettingsTable;
use Hilos\Tables\Users\HilosUserDetailBrowserTable;
use Hilos\HilosException;
use Hilos\Database\Schema\FrameworkExtensionGuard;
use Hilos\Database\Schema\MountedCollectionKeyGuard;
use Demo\Tasks\Pages\Hilos\I18nPage;
use Demo\Tasks\Pages\Hilos\I18n\Lists\LanguagesListPage;
use Demo\Tasks\Pages\Hilos\I18n\Details\CountryDetailPage;
use Demo\Tasks\Pages\Hilos\I18n\Details\CountryNamesPage;
use Demo\Tasks\Pages\Hilos\I18n\Details\LanguageDetailPage;
use Demo\Tasks\Pages\Hilos\I18n\Details\LanguageLocalesPage;
use Demo\Tasks\Pages\Hilos\I18n\Details\LanguageNamesPage;
use Demo\Tasks\Pages\Hilos\I18n\Lists\CountriesListPage;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\I18n\Browser\CountryCardBrowserData;
use Hilos\I18n\Browser\LanguageCardBrowserData;
use Hilos\I18n\Library\I18nLibraryAgentDaemon;
use PHPUnit\Framework\TestCase;

/**
 * Guards the project-level tasks topology registry.
 */
final class TasksTopologyRegistryTest extends TestCase
{
    /** Legal administration is bound to its own worker, page routes and tables. */
    public function testLegalAdministrationUsesItsOwnMonopolisticAgentAndFrameworkTables(): void
    {
        $this->assertSame(DemoHilosLegalAgent::class, Hilos::AGENTS[AgentType::HILOS_LEGAL][AgentRegistryKey::WORKER]);
        $this->assertSame(DemoHilosLegalAgentDaemon::class, Hilos::AGENTS[AgentType::HILOS_LEGAL][AgentRegistryKey::DAEMON]);
        $this->assertTrue(new DemoHilosLegalAgentDaemon()->requiresMonopolisticProcess());
        foreach ([
            LegalPage::class, LegalDocumentPage::class, LegalRevisionPage::class, LegalAcceptancesPage::class, LegalSettingsPage::class,
        ] as $page) {
            $this->assertSame($page, Hilos::PAGES[$page::PAGE]);
            $this->assertSame(AgentType::HILOS_LEGAL, $page::SUBSCRIPTION_AGENT_TYPE);
        }
        $this->assertSame(LegalSettingsPage::PAGE, Hilos::getPageActionRoutes()[HilosSignalConstants::LEGAL_SETTING_SET]);
        $this->assertSame(AgentType::HILOS_LEGAL, Hilos::getActionAgentRoutes()[HilosSignalConstants::LEGAL_SETTING_SET]);
        $this->assertSame(LegalAcceptancesPage::PAGE, Hilos::getPageActionRoutes()[HilosSignalConstants::LEGAL_ACCEPTANCES_EXPORT]);
        $this->assertSame(AgentType::HILOS_LEGAL, Hilos::getActionAgentRoutes()[HilosSignalConstants::LEGAL_ACCEPTANCES_EXPORT]);
        $this->assertSame([
            TasksTableContext::hilosLegalDocuments => [],
            TasksTableContext::hilosLegalChecks => [],
            TasksTableContext::hilosLegalSettings => [],
        ], Hilos::PAGE_TABLES[LegalPage::PAGE]);
        $this->assertSame([TasksTableContext::hilosLegalRevisions => []], Hilos::PAGE_TABLES[LegalDocumentPage::PAGE]);
        $this->assertSame([TasksTableContext::hilosLegalRevisions => []], Hilos::PAGE_TABLES[LegalRevisionPage::PAGE]);
        $this->assertSame([TasksTableContext::hilosLegalAcceptances => []], Hilos::PAGE_TABLES[LegalAcceptancesPage::PAGE]);
        $this->assertSame([TasksTableContext::hilosLegalSettings => []], Hilos::PAGE_TABLES[LegalSettingsPage::PAGE]);
        $catalog = TasksSettingsCatalog::getCatalog();
        foreach (LegalSettings::KEYS as $key) {
            $this->assertSame(LegalSettingsCatalog::getCatalog()[$key], $catalog[$key]);
        }
        foreach (ThemeSettingsCatalog::KEYS as $key) {
            $this->assertSame(ThemeSettingsCatalog::getCatalog()[$key], $catalog[$key]);
        }
    }

    /** The archive address is answered by the export owner, the address of the acceptances file by the legal agent. */
    public function testDataExportHttpRoute(): void
    {
        self::assertSame([
            HttpConstants::METHOD_GET => [
                DataExportHttp::DOWNLOAD_PATH => HilosAgentType::HILOS_DATA_EXPORT,
                LegalAcceptancesExportHttp::DOWNLOAD_PATH => HilosAgentType::HILOS_LEGAL,
            ],
        ], Hilos::getHttpAgentRoutes());
    }

    /** The export runs once on the policy-selected node and has a worker of its own. */
    public function testDataExportAgentIsPolicyPlacedAndMonopolistic(): void
    {
        $entry = Hilos::AGENTS[HilosAgentType::HILOS_DATA_EXPORT];
        self::assertSame(DataExportAgent::class, AgentRegistry::workerClass($entry));
        self::assertSame(DataExportAgentDaemon::class, AgentRegistry::daemonClass($entry));
        self::assertSame(AgentPlacement::POLICY, AgentRegistry::placement($entry));
        self::assertTrue((new DataExportAgentDaemon())->requiresMonopolisticProcess());
    }

    public function testComputedPageRoutesCoverEveryRegisteredPage(): void
    {
        $this->assertSame(array_keys(Hilos::PAGES), array_keys(Hilos::getPageRoutes()));
    }

    /** The Daemon environment page names the node whose replica serves it. */
    public function testOnlyDaemonEnvironmentPageUsesNodeAddressedSubscription(): void
    {
        $routes = Hilos::getPageAgentIndexRoutes();
        $this->assertSame([DaemonEnvPage::PAGE], array_keys($routes));
        $this->assertSame('node_param', $routes[DaemonEnvPage::PAGE]->source->value);
        $this->assertSame('nodeId', $routes[DaemonEnvPage::PAGE]->param);
        $this->assertSame(HilosAgentType::HILOS_DAEMON, $routes[DaemonEnvPage::PAGE]->fallbackAgentType);
    }

    public function testPageRegistryKeysMatchPageClassConstants(): void
    {
        foreach (Hilos::PAGES as $page => $pageClass) {
            $this->assertSame($page, $pageClass::PAGE);
        }
    }

    /** The person agent is addressed by id and sleeps after the standard idle window. */
    public function testPersonAgentHasTheIndexedPolicyRegistryEntry(): void
    {
        $entry = Hilos::AGENTS[HilosAgentType::HILOS_USER];

        $this->assertSame(UserAgent::class, AgentRegistry::workerClass($entry));
        $this->assertSame(UserAgentDaemon::class, AgentRegistry::daemonClass($entry));
        $this->assertTrue(AgentRegistry::requiresIndex($entry));
        $this->assertSame(AgentScope::CLUSTER, AgentRegistry::scope($entry));
        $this->assertSame(AgentPlacement::POLICY, AgentRegistry::placement($entry));
        $this->assertSame(AgentRegistry::DEFAULT_IDLE_TIMEOUT_SEC, AgentRegistry::idleTimeout($entry));
        $daemon = new UserAgentDaemon('42');
        $this->assertSame('42', $daemon->getIndex());
        $this->assertFalse($daemon->requiresMonopolisticProcess());
    }

    public function testAgentRegistryEntriesAreConcreteAndConsistent(): void
    {
        foreach (Hilos::AGENTS as $agentType => $registryEntry) {
            $workerClass = AgentRegistry::workerClass($registryEntry);
            $daemonClass = AgentRegistry::daemonClass($registryEntry);

            $this->assertIsString($workerClass);
            $this->assertIsString($daemonClass);
            $this->assertTrue(class_exists($workerClass), "{$workerClass} must be a concrete worker class");
            $this->assertTrue(class_exists($daemonClass), "{$daemonClass} must be a concrete daemon class");
            $this->assertTrue(is_subclass_of($daemonClass, AbstractAgentDaemon::class));
            $this->assertSame($agentType, $workerClass::AGENT_TYPE);
        }
    }

    /** The i18n section and five detail pages route to one placed framework library. */
    public function testI18nSectionUsesFrameworkLibrary(): void
    {
        $this->assertContains(HilosFeature::I18N, Hilos::features());
        $this->assertSame([
            I18nPage::PAGE,
            LanguagesListPage::PAGE,
            CountriesListPage::PAGE,
            LanguageDetailPage::PAGE,
            LanguageNamesPage::PAGE,
            LanguageLocalesPage::PAGE,
            CountryDetailPage::PAGE,
            CountryNamesPage::PAGE,
        ], array_values(array_filter(
            array_keys(Hilos::PAGES),
            static fn (string $page): bool => str_starts_with($page, I18nPage::PAGE),
        )));

        foreach ([
            I18nPage::class, LanguagesListPage::class, CountriesListPage::class,
            LanguageDetailPage::class,
            LanguageNamesPage::class,
            LanguageLocalesPage::class,
            CountryDetailPage::class,
            CountryNamesPage::class,
        ] as $page) {
            $this->assertSame($page, Hilos::PAGES[$page::PAGE]);
            $this->assertSame(HilosAgentType::HILOS_I18N_LIBRARY, Hilos::getPageRoutes()[$page::PAGE]);
        }

        $this->assertSame(HilosI18nLanguageLocalesTable::class, Hilos::TABLES[TasksTableContext::hilosI18nLanguageLocales]);
        $this->assertSame([TasksTableContext::hilosI18nLanguageLocales => []], Hilos::PAGE_TABLES[LanguageLocalesPage::PAGE]);
        $this->assertSame(HilosI18nLanguagesTable::class, Hilos::TABLES[TasksTableContext::hilosI18nLanguages]);
        $this->assertSame([TasksTableContext::hilosI18nLanguages => []], Hilos::PAGE_TABLES[LanguagesListPage::PAGE]);
        $this->assertSame(LanguageCardBrowserData::class, Hilos::BROWSER_DATA[LanguageCardBrowserData::DATA]);
        $this->assertSame(
            [LanguageCardBrowserData::DATA => LanguageCardBrowserData::BINDING],
            Hilos::PAGE_DATA[LanguageDetailPage::PAGE],
        );
        $this->assertSame(
            [LanguageCardBrowserData::DATA => LanguageCardBrowserData::BINDING],
            Hilos::PAGE_DATA[LanguageNamesPage::PAGE],
        );
        $this->assertSame(
            [LanguageCardBrowserData::DATA => LanguageCardBrowserData::BINDING],
            Hilos::PAGE_DATA[LanguageLocalesPage::PAGE],
        );
        $this->assertSame(CountryCardBrowserData::class, Hilos::BROWSER_DATA[CountryCardBrowserData::DATA]);
        $this->assertSame(
            [CountryCardBrowserData::DATA => CountryCardBrowserData::BINDING],
            Hilos::PAGE_DATA[CountryDetailPage::PAGE],
        );

        $entry = Hilos::AGENTS[HilosAgentType::HILOS_I18N_LIBRARY];
        $this->assertSame(I18nLibraryAgent::class, AgentRegistry::workerClass($entry));
        $this->assertSame(I18nLibraryAgentDaemon::class, AgentRegistry::daemonClass($entry));
        $this->assertSame(AgentScope::CLUSTER, AgentRegistry::scope($entry));
        $this->assertSame(AgentPlacement::POLICY, AgentRegistry::placement($entry));
        $this->assertTrue((new I18nLibraryAgentDaemon())->requiresMonopolisticProcess());
    }

    public function testPageSubscriptionOwnersAreDeclaredByPageClasses(): void
    {
        $pageRoutes = Hilos::getPageRoutes();

        foreach (Hilos::PAGES as $page => $pageClass) {
            $this->assertSame($pageClass::SUBSCRIPTION_AGENT_TYPE, $pageRoutes[$page]);
            $this->assertNotSame('', $pageClass::SUBSCRIPTION_AGENT_TYPE, "{$page} must declare a subscription owner");
        }
    }

    public function testMainPageIsOwnedByTheTasksAgent(): void
    {
        $this->assertSame(MainPage::class, Hilos::PAGES[PageConstants::MAIN]);
        $this->assertSame(AgentType::TASKS, MainPage::SUBSCRIPTION_AGENT_TYPE);
        $this->assertSame(TasksAgent::class, AgentRegistry::workerClass(Hilos::AGENTS[AgentType::TASKS]));
        $this->assertSame(TasksAgentDaemon::class, AgentRegistry::daemonClass(Hilos::AGENTS[AgentType::TASKS]));
        $this->assertFalse(AgentRegistry::requiresIndex(Hilos::AGENTS[AgentType::TASKS]));
    }

    public function testHilosDashboardIsOwnedByTheIndexAgent(): void
    {
        $this->assertSame(DashboardPage::class, Hilos::PAGES[DashboardPage::PAGE]);
        $this->assertSame(AgentType::HILOS_INDEX, DashboardPage::SUBSCRIPTION_AGENT_TYPE);
        $this->assertSame(DemoHilosAgent::class, AgentRegistry::workerClass(Hilos::AGENTS[AgentType::HILOS_INDEX]));
        $this->assertSame(
            DemoHilosAgentDaemon::class,
            AgentRegistry::daemonClass(Hilos::AGENTS[AgentType::HILOS_INDEX]),
        );
        $this->assertFalse(AgentRegistry::requiresIndex(Hilos::AGENTS[AgentType::HILOS_INDEX]));
    }

    public function testTasksAppOwnSurfaceStaysTransportOnly(): void
    {
        // The tasks application's OWN surface stays transport-only: its main page
        // and worker push no server-driven data. The activated Hilos admin
        // features (settings, users, logs) own their actions/signals/browser tables —
        // asserted separately below.
        //
        // The two frames the worker is addressed by are not its surface but the seam the
        // sessions moved behind (HIL-710): the library says what a session became or removed,
        // and this agent updates the connection or guest rows that belong to the project.
        // The one group is not the application's own surface either: it is the framework's
        // notification channel, activated by the same feature that mounts the bell (HIL-721).
        $this->assertSame(
            [NotificationsGroup::GROUP => NotificationsGroup::class],
            Hilos::GROUPS,
        );
        $this->assertSame([], MainPage::ACTIONS);
        $this->assertSame([], MainPage::SIGNALS);
        $this->assertSame(
            [
                HilosSignalConstants::HILOS_SESSION_STATE => SessionStateSignalData::class,
                HilosSignalConstants::HILOS_SESSIONS_SWEPT => SessionsSweptSignalData::class,
            ],
            TasksAgent::AGENT_SIGNALS,
        );
        $this->assertSame(
            [
                HilosSignalConstants::HILOS_DATA_EXPORT_FORGET_USER => HilosAgentType::HILOS_DATA_EXPORT,
                HilosSignalConstants::HILOS_SESSION_STATE => AgentType::TASKS,
                HilosSignalConstants::HILOS_SESSIONS_SWEPT => AgentType::TASKS,
                // The other half of the seam, and the eight endings the users library hands
                // over: what a sign-in became reaches the library that owns the session.
                HilosSignalConstants::HILOS_AUTH_SESSION_GRANT => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_REGISTRATION_PROVEN => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_REGISTRATION_LANDED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_RECOVERY_GRANTED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_PASSWORD_CHANGED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_REGISTRATION_CANCELED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_REGISTRATION_WAIT_MOVED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_RECOVERY_WAIT_MOVED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_SESSION_REBIND => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                // The merge is mounted here as everywhere else and refuses, because this demo
                // wires neither of its seams (HIL-729).
                HilosSignalConstants::HILOS_ACCOUNT_MERGE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_ACCOUNT_ADMIN_SET => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_ACCOUNT_BLOCK_SET => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                // The one frame with no fixed sender: whoever finished something a person is
                // waiting on raises a toast on their session, and the stack is the library's
                // (HIL-768).
                HilosSignalConstants::HILOS_SESSION_TOAST_RAISE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                // The takeover the Hilos users page forwards: the page carries the ADMIN level
                // that closes the action, and the session it rebinds is the library's (HIL-824).
                HilosSignalConstants::HILOS_IMPERSONATE_REQUEST => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_CODE_SEND_STEP => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                // A restore's logins and letters, offered by whoever holds them (HIL-846). The
                // receipts go back to the backup agent, which this demo registers since HIL-911.
                HilosSignalConstants::HILOS_SESSION_CARRYOVER_HANDOVER => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_OAUTH_TRIP_OPENED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_OAUTH_TRIP_ENDED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AGENTS_GONE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_REGISTRATION_WAIT_HELD => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_MISSED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_SETUP_PROVEN => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_OFF => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_TRUST_DAYS_APPLY => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_TRUST_REVOKE_OTHERS => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_OTHER_SESSIONS_END => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_CANCEL => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_ACCOUNT_BLOCK_CHANGED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_PROFILE_FLOW_STEP => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_USER_ADMIN_WRITE_DONE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_USER_ADMIN_COMMAND_DONE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_USER_BLOCK_WRITE_DONE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_NOTIFICATION_EMIT => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
                HilosSignalConstants::HILOS_DELIVERY_RETRY => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
                HilosSignalConstants::HILOS_NOTIFICATION_HANDOVER => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
                HilosSignalConstants::HILOS_PUSH_SUBSCRIPTIONS_GONE => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
                HilosSignalConstants::HILOS_SETTING_WRITE => HilosAgentType::HILOS_SETTINGS_LIBRARY,
                HilosSignalConstants::HILOS_SETTING_RESET => HilosAgentType::HILOS_SETTINGS_LIBRARY,
                HilosSignalConstants::HILOS_SETTING_DELETE => HilosAgentType::HILOS_SETTINGS_LIBRARY,
                HilosSignalConstants::HILOS_SETTING_PRESET_APPLY => HilosAgentType::HILOS_SETTINGS_LIBRARY,
                // Sign-in's own frames (HIL-623). The users library waits for the throttle
                // verdict and for the provider exchange it handed off; the four delivery
                // agents and the two node-scoped auth agents answer on their own names.
                HilosSignalConstants::HILOS_USER_RENAME => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_ADMIN_WRITE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_ADMIN_COMMAND => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_BLOCK_WRITE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_PASSWORD_REHASH => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_ADDRESS_VERIFY => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_PASSKEY_USE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_PASSWORD_RESET => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_PASSWORD_CHANGE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_EMAIL_CHANGE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_IDENTITY_UNLINK => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_CONFIRM => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_REMOVE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_CANCEL => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_WAIT_WRITE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_DUE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_REMIND => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_UNLOCK => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_AUTH_THROTTLE_VERDICT => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_OAUTH_LOGIN_READY => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_ACCOUNT_DELETION_SET => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_ADMIN_RENAME => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_RENAME_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_PASSWORD_REHASH_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_ADDRESS_VERIFY_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_PASSKEY_USE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_PASSWORD_RESET_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_PASSWORD_CHANGE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_EMAIL_CHANGE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_IDENTITY_UNLINK_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_CONFIRM_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_REMOVE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_CANCEL_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_WAIT_WRITE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_DUE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_REMIND_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_UNLOCK_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_PROFILE_PHOTO_VERDICT => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_PROFILE_PHOTO_PUBLISHED => HilosAgentType::HILOS_USERS_LIBRARY,
                // The logs section's own frames (HIL-392): the section agent takes the
                // cluster picture in portions, the per-node store answers the reads the
                // viewer and the rotations screen ask for, and the aggregator collects what
                // each node reports and watches the index for the section agent.
                HilosSignalConstants::ANALYTICS_JOURNAL_APPEND => HilosAgentType::HILOS_ANALYTICS_JOURNAL,
                HilosSignalConstants::ANALYTICS_JOURNAL_READ => HilosAgentType::HILOS_ANALYTICS_JOURNAL,
                HilosSignalConstants::ANALYTICS_JOURNAL_LOADED => HilosAgentType::HILOS_ANALYTICS_JOURNAL,
                HilosSignalConstants::ANALYTICS_JOURNAL_PORTION => HilosAgentType::HILOS_ANALYTICS_WRITER,
                HilosSignalConstants::ANALYTICS_JOURNAL_READY => HilosAgentType::HILOS_ANALYTICS_WRITER,
                HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION => HilosAgentType::HILOS_DAEMON,
                HilosSignalConstants::LOGS_CLUSTER_INDEX_PORTION => HilosAgentType::HILOS_LOGS,
                // The legal section's own frame (HIL-1234): an erasure removes the exports of acceptance records.
                HilosSignalConstants::HILOS_LEGAL_ACCEPTANCES_EXPORT_FORGET => HilosAgentType::HILOS_LEGAL,
                // The backup section's own frames: the page hands every operation to the
                // monopoly agent, and the carry-over receipts of a restore come back to it
                // (HIL-911 activates the feature here, so the React surface has one).
                HilosSignalConstants::BACKUP_AGENT_CREATE => HilosAgentType::HILOS_BACKUP,
                HilosSignalConstants::BACKUP_AGENT_DELETE => HilosAgentType::HILOS_BACKUP,
                HilosSignalConstants::BACKUP_AGENT_SET_KEEP => HilosAgentType::HILOS_BACKUP,
                HilosSignalConstants::BACKUP_AGENT_RESTORE => HilosAgentType::HILOS_BACKUP,
                HilosSignalConstants::BACKUP_AGENT_REOPEN => HilosAgentType::HILOS_BACKUP,
                HilosSignalConstants::BACKUP_AGENT_SESSIONS_CARRIED => HilosAgentType::HILOS_BACKUP,
                HilosSignalConstants::BACKUP_AGENT_NOTICES_SENT => HilosAgentType::HILOS_BACKUP,
                HilosSignalConstants::HILOS_OAUTH_PENDING => HilosAgentType::HILOS_OAUTH,
                HilosSignalConstants::HILOS_MAIL_DELIVER => HilosAgentType::HILOS_MAIL,
                HilosSignalConstants::HILOS_MAIL_SEND => HilosAgentType::HILOS_MAIL,
                HilosSignalConstants::HILOS_SMS_DELIVER => HilosAgentType::HILOS_SMS,
                HilosSignalConstants::HILOS_SMS_SEND => HilosAgentType::HILOS_SMS,
                HilosSignalConstants::LOGS_AGENT_READ_LINES => HilosAgentType::HILOS_LOG_STORE,
                HilosSignalConstants::LOGS_AGENT_FOLLOW_START => HilosAgentType::HILOS_LOG_STORE,
                HilosSignalConstants::LOGS_AGENT_FOLLOW_STOP => HilosAgentType::HILOS_LOG_STORE,
                HilosSignalConstants::LOGS_AGENT_TAKEOUT_CONFIRM => HilosAgentType::HILOS_LOG_STORE,
                HilosSignalConstants::LOGS_AGENT_TAKEOUT_UNDO => HilosAgentType::HILOS_LOG_STORE,
                HilosSignalConstants::DAEMON_MASTER_PROCESS_ROSTER => HilosAgentType::HILOS_DAEMON_NODE,
                HilosSignalConstants::DAEMON_MASTER_CRON => HilosAgentType::HILOS_DAEMON_NODE,
                HilosSignalConstants::DAEMON_MASTER_STANDING => HilosAgentType::HILOS_DAEMON_NODE,
                HilosSignalConstants::DAEMON_MASTER_HTTP => HilosAgentType::HILOS_DAEMON_NODE,
                HilosSignalConstants::DAEMON_AGENT_CRON => HilosAgentType::HILOS_DAEMON_NODE,
                HilosSignalConstants::DAEMON_NODE_PICTURE_REPORT => HilosAgentType::HILOS_DAEMON_COLLECTOR,
                HilosSignalConstants::DAEMON_PICTURE_WATCH => HilosAgentType::HILOS_DAEMON_COLLECTOR,
                HilosSignalConstants::LOGS_NODE_INDEX_REPORT => HilosAgentType::HILOS_LOG_AGGREGATOR,
                HilosSignalConstants::LOGS_INDEX_WATCH => HilosAgentType::HILOS_LOG_AGGREGATOR,
                HilosSignalConstants::HILOS_AUTH_THROTTLE_CHECK => HilosAgentType::HILOS_AUTH_THROTTLE,
                HilosSignalConstants::HILOS_AUTH_THROTTLE_SUCCEEDED => HilosAgentType::HILOS_AUTH_THROTTLE,
                HilosSignalConstants::HILOS_AUTH_CODE_SEND => HilosAgentType::HILOS_AUTH_CODE,
            ],
            Hilos::getAgentSignalRoutes(),
        );
    }

    public function testSignInIsActivatedOnTheFrameworkLibrary(): void
    {
        // Sign-in is an activation, not a build (HIL-623): the demo declares the features
        // and registers six agent pairs, and every command name behind the surface is the
        // framework's. The snapshot is the whole action map on purpose - a command that
        // silently stopped being routed here would otherwise look like a working surface
        // until somebody submitted the form it belongs to.
        $this->assertSame([
            HilosFeature::ANALYTICS,
                HilosFeature::SETTINGS,
            HilosFeature::I18N,
            HilosFeature::HILOS_USERS,
            HilosFeature::LOGS,
            HilosFeature::DAEMON,
            HilosFeature::NOTIFICATIONS,
            HilosFeature::AUTH,
            HilosFeature::AUTH_THROTTLE,
            HilosFeature::CODE_CHANNELS,
            HilosFeature::BACKUP,
        ], Hilos::features());

        $this->assertSame(UsersLibraryAgent::class, AgentRegistry::workerClass(
            Hilos::AGENTS[HilosAgentType::HILOS_USERS_LIBRARY],
        ));
        $this->assertSame(UsersLibraryAgentDaemon::class, AgentRegistry::daemonClass(
            Hilos::AGENTS[HilosAgentType::HILOS_USERS_LIBRARY],
        ));
        $this->assertSame(OAuthAgent::class, AgentRegistry::workerClass(
            Hilos::AGENTS[HilosAgentType::HILOS_OAUTH],
        ));
        $this->assertSame(OAuthAgentDaemon::class, AgentRegistry::daemonClass(
            Hilos::AGENTS[HilosAgentType::HILOS_OAUTH],
        ));
        $this->assertSame(
            [
                HilosAgentType::HILOS_MAIL,
                HilosAgentType::HILOS_SMS,
                HilosAgentType::HILOS_AUTH_THROTTLE,
                HilosAgentType::HILOS_AUTH_CODE,
            ],
            array_values(array_intersect(array_keys(Hilos::AGENTS), [
                HilosAgentType::HILOS_MAIL,
                HilosAgentType::HILOS_SMS,
                HilosAgentType::HILOS_AUTH_THROTTLE,
                HilosAgentType::HILOS_AUTH_CODE,
            ])),
        );

        $this->assertSame([
            HilosSignalConstants::HILOS_DATA_EXPORT_ORDER => HilosAgentType::HILOS_DATA_EXPORT,
            // Signing out, dismissing an ack and leaving a takeover all write a session, so
            // the sessions library owns them (HIL-710, HIL-729) - this demo adds an action of
            // its own for none of them. STARTING a takeover is not among them since HIL-824:
            // only an administrator may, and an ADMIN level is a thing only a page carries.
            HilosSignalConstants::HILOS_LOGOUT => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_SESSION_END => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_SESSIONS_END_OTHERS => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_BROWSER_ERASE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_DISMISS_SESSION_ACK => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_DISMISS_ACCOUNT_BLOCKED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_IMPERSONATE_STOP => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            // The tabs of one session answering about the toasts the server raised for it
            // (HIL-768): the stack they answer about stands on the session.
            HilosSignalConstants::HILOS_TOAST_DISMISS => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_TOAST_EXPIRED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_TOAST_READING => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_OAUTH_RESUME => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosSignalConstants::HILOS_PROFILE_FLOW_CANCEL => HilosAgentType::HILOS_SESSIONS_LIBRARY,
            NotificationAction::MARK_READ => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            NotificationAction::MARK_ALL_READ => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            NotificationPreferenceAction::CHANNEL_SET => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            PushSubscriptionAction::SUBSCRIBE => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            PushSubscriptionAction::UNSUBSCRIBE => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            PushSubscriptionAction::REMOVE => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            HilosSignalConstants::HILOS_DETECT_IDENTIFIER => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_LEGAL_CONSENT => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_LEGAL_RECONSENT => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_LEGAL_ACCEPT => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_LEGAL_RECONSENT_PREVIEW => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_LOGIN => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_REGISTER => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_REQUEST_PASSWORD_RESET => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_CONFIRM_PASSWORD_RESET => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_COMPLETE_PASSWORD_RESET => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_REQUEST_REGISTER_CONFIRM => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_CONFIRM_REGISTER => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_COMPLETE_REGISTRATION => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_COMPLETE_REGISTRATION_PASSWORDLESS => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_COMPLETE_REGISTRATION_PASSKEY => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_CANCEL_REGISTRATION => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_REQUEST_PHONE_CODE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_CONFIRM_PHONE_CODE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_REQUEST_MAGIC_LINK => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_CONFIRM_MAGIC_LINK => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_CONFIRM_MAGIC_LINK_CODE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_OAUTH_START => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_OAUTH_CALLBACK => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_LINK_OAUTH_AFTER_REAUTH => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_OAUTH_CREATE_ACCOUNT => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_PASSKEY_REGISTER_OPTIONS => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_PASSKEY_REGISTER_CONFIRM => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_REGISTRATION_PASSKEY_OPTIONS => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_PASSKEY_DISCOVERABLE_LOGIN_OPTIONS => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_PASSKEY_LOGIN_CONFIRM => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_CONFIRM_SECOND_FACTOR => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_CANCEL_SECOND_FACTOR => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_SECOND_FACTOR_SETUP_START => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_SECOND_FACTOR_SETUP_CONFIRM => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_SECOND_FACTOR_SETUP_FINISH => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_SECOND_FACTOR_RESET_REQUEST => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_SECOND_FACTOR_RESET_CANCEL_LINK => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_START => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_ENROLL_CONFIRM => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_REMOVE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_CODES_SHOW => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_CODES_RENEW => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_WAIT_SET => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_REQUEST => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_SECOND_FACTOR_RESET_CANCEL => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_STEP_UP_START => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_STEP_UP_CONFIRM => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_SET_PASSWORD => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_UNLINK_IDENTITY => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_ADD_SMS_REQUEST => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_ADD_SMS_CONFIRM => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_ADD_PASSWORD_REQUEST => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_ADD_PASSWORD_CONFIRM => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_CHANGE_EMAIL_CURRENT_REQUEST => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_CHANGE_EMAIL_CURRENT_CONFIRM => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_CHANGE_EMAIL_NEW_REQUEST => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_CHANGE_EMAIL_NEW_CONFIRM => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_PHOTO_SET => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_PHOTO_REMOVE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_CHANGE_PASSWORD_OPEN => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_CHANGE_PASSWORD_CODE_REQUEST => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_CHANGE_PASSWORD_CODE_CONFIRM => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::PROFILE_CHANGE_PASSWORD => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_ACCOUNT_DELETION_OPEN => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_ACCOUNT_DELETION_CODE => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_ACCOUNT_DELETION_START => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_ACCOUNT_DELETION_CANCEL => HilosAgentType::HILOS_USERS_LIBRARY,
        ], Hilos::getAgentActionRoutes());
    }

    public function testSettingsAndUsersAdminFeaturesAreActivated(): void
    {
        // Both framework admin features are activated: settings (configure-only)
        // and hilos-users (the users list page + the user-detail page with its
        // rename action and a filtered detail browser table).
        $this->assertSame([
            TasksTableContext::settings => HilosSettingsTable::class,
            TasksTableContext::hilosUsers => HilosUsersTable::class,
            TasksTableContext::hilosBackups => HilosBackupHistoryTable::class,
            TasksTableContext::hilosVerifierCircle => HilosVerifierCircleTable::class,
            TasksTableContext::hilosLogKeys => HilosLogKeysTable::class,
            TasksTableContext::hilosLogRotations => HilosLogRotationsTable::class,
            TasksTableContext::hilosLogWorkers => HilosLogWorkersTable::class,
            TasksTableContext::hilosDaemonCron => HilosDaemonCronTable::class,
            TasksTableContext::hilosDaemonWorkers => HilosDaemonWorkersTable::class,
            TasksTableContext::hilosDaemonAgents => HilosDaemonAgentsTable::class,
            TasksTableContext::hilosSecurityOauthProviders => HilosSecurityOAuthProvidersTable::class,
            TasksTableContext::hilosSecurityOauthProviderFields => HilosSecurityOAuthProviderFieldsTable::class,
            TasksTableContext::hilosSecurityOauthRedirect => HilosSecurityOAuthRedirectTable::class,
            TasksTableContext::hilosSecuritySignInMethods => HilosSecuritySignInMethodsTable::class,
            TasksTableContext::hilosSecurityTwoFactor => HilosSecurityTwoFactorTable::class,
            TasksTableContext::hilosSecurityImpersonation => HilosSecurityImpersonationTable::class,
            TasksTableContext::hilosLegalDocuments => HilosLegalDocumentsTable::class,
            TasksTableContext::hilosLegalChecks => HilosLegalChecksTable::class,
            TasksTableContext::hilosLegalRevisions => HilosLegalRevisionsTable::class,
            TasksTableContext::hilosLegalAcceptances => HilosLegalAcceptancesTable::class,
            TasksTableContext::hilosLegalSettings => HilosLegalSettingsTable::class,
            TasksTableContext::hilosSecurityStepUp => HilosSecurityStepUpTable::class,
            TasksTableContext::hilosI18nLanguageNames => HilosI18nLanguageNamesTable::class,
            TasksTableContext::hilosI18nCountryNames => HilosI18nCountryNamesTable::class,
            TasksTableContext::hilosI18nLanguageLocales => HilosI18nLanguageLocalesTable::class,
            TasksTableContext::hilosI18nLanguages => HilosI18nLanguagesTable::class,
        ], Hilos::TABLES);

        $this->assertSame(
            [HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::class],
            Hilos::BROWSER_TABLES,
        );

        $this->assertSame(
            [
                SettingsPage::PAGE,
                BackupPage::PAGE,
                MaintenancePage::PAGE,
                LogsKeysPage::PAGE,
                LogsRotationsPage::PAGE,
                LogsWorkersPage::PAGE,
                DaemonCronPage::PAGE,
                DaemonWorkersPage::PAGE,
                DaemonAgentsPage::PAGE,
                SecurityOAuthPage::PAGE,
                SecurityOAuthProviderPage::PAGE,
                SecuritySignInMethodsPage::PAGE,
                SecurityTwoFactorPage::PAGE,
                SecurityStepUpPage::PAGE,
                SecurityImpersonationPage::PAGE,
                LegalPage::PAGE,
                LegalDocumentPage::PAGE,
                LegalRevisionPage::PAGE,
                LegalAcceptancesPage::PAGE,
                LegalSettingsPage::PAGE,
                UsersPage::PAGE,
                UserPage::PAGE,
                LanguagesListPage::PAGE,
                LanguageNamesPage::PAGE,
                LanguageLocalesPage::PAGE,
                CountryNamesPage::PAGE,
            ],
            array_keys(Hilos::PAGE_TABLES),
        );
        $this->assertSame([TasksTableContext::hilosUsers => []], Hilos::PAGE_TABLES[UsersPage::PAGE]);

        $this->assertSame(
            UserPage::PAGE,
            Hilos::getPageActionRoutes()[HilosSignalConstants::HILOS_USER_UPDATE],
        );
        $this->assertSame(
            UserPage::PAGE,
            Hilos::getPageActionRoutes()[HilosSignalConstants::HILOS_USER_MERGE],
        );
        $this->assertSame(
            UserPage::PAGE,
            Hilos::getPageSignalRoutes()[SignalTypeConstants::AGENT_SIGNAL]
                [HilosSignalConstants::HILOS_ACCOUNT_MERGE_DONE],
        );

        $this->assertSame(UserPage::PAGE, Hilos::getPageActionRoutes()[HilosSignalConstants::HILOS_USER_ADMIN_SET]);
        $this->assertSame(
            UserPage::PAGE,
            Hilos::getPageSignalRoutes()[SignalTypeConstants::AGENT_SIGNAL]
                [HilosSignalConstants::HILOS_ACCOUNT_ADMIN_SET_DONE],
        );

        $this->assertSame(UserPage::PAGE, Hilos::getPageActionRoutes()[HilosSignalConstants::HILOS_USER_BLOCK_SET]);
        $this->assertSame(
            UserPage::PAGE,
            Hilos::getPageSignalRoutes()[SignalTypeConstants::AGENT_SIGNAL]
                [HilosSignalConstants::HILOS_ACCOUNT_BLOCK_SET_DONE],
        );

        $this->assertSame(UserPage::PAGE, Hilos::getPageActionRoutes()[HilosSignalConstants::HILOS_USER_DELETION_SET]);
        $this->assertSame(
            UserPage::PAGE,
            Hilos::getPageSignalRoutes()[SignalTypeConstants::AGENT_SIGNAL]
                [HilosSignalConstants::HILOS_ACCOUNT_DELETION_SET_DONE],
        );
    }

    public function testBackupAdminFeatureIsActivated(): void
    {
        // Backup is a configure-only framework feature with a monopoly agent behind it. It is
        // activated here so the React surface has a backup page to prove the reopen block on
        // (HIL-911): the page answered by the index agent, the agent pair, and its
        // archive table. The verifier circle is drawn in Maintenance.
        $this->assertSame(BackupPage::class, Hilos::PAGES[BackupPage::PAGE]);
        $this->assertSame(AgentType::HILOS_INDEX, BackupPage::SUBSCRIPTION_AGENT_TYPE);
        $this->assertSame(BackupAgent::class, AgentRegistry::workerClass(
            Hilos::AGENTS[HilosAgentType::HILOS_BACKUP],
        ));
        $this->assertSame(BackupAgentDaemon::class, AgentRegistry::daemonClass(
            Hilos::AGENTS[HilosAgentType::HILOS_BACKUP],
        ));
        // Placed by policy, not hosted by the leader: the agent owns a directory on one node's
        // disk, and following leadership would move it away from its archives (HIL-940).
        $this->assertSame(AgentPlacement::POLICY, AgentRegistry::placement(
            Hilos::AGENTS[HilosAgentType::HILOS_BACKUP],
        ));
        $this->assertSame(
            [TasksTableContext::hilosBackups => []],
            Hilos::PAGE_TABLES[BackupPage::PAGE],
        );
    }

    /** Maintenance has no feature switch: page and table registration activate it for React (HIL-1123). */
    public function testMaintenanceSectionIsActivated(): void
    {
        $this->assertSame(MaintenancePage::class, Hilos::PAGES[MaintenancePage::PAGE]);
        $this->assertSame(AgentType::HILOS_INDEX, MaintenancePage::SUBSCRIPTION_AGENT_TYPE);
        $this->assertSame(
            [TasksTableContext::hilosVerifierCircle => []],
            Hilos::PAGE_TABLES[MaintenancePage::PAGE],
        );
        $this->assertSame(
            HilosPageConstants::HILOS_MAINTENANCE,
            Hilos::getPageActionRoutes()[HilosSignalConstants::MAINTENANCE_CIRCLE_ADD],
        );
        $this->assertSame(
            HilosPageConstants::HILOS_MAINTENANCE,
            Hilos::getPageActionRoutes()[HilosSignalConstants::MAINTENANCE_CIRCLE_REMOVE],
        );
    }

    /** Pins the Daemon feature, six page routes and all three agent placements. */
    public function testDaemonAdminFeatureIsActivated(): void
    {
        $this->assertContains(HilosFeature::DAEMON, Hilos::features());
        foreach ([
            DaemonPage::class,
            DaemonWorkersPage::class,
            DaemonAgentsPage::class,
            DaemonCronPage::class,
            DaemonWebsocketsPage::class,
            DaemonHttpServerPage::class,
            DaemonEnvMismatchPage::class,
        ] as $page) {
            $this->assertSame($page, Hilos::PAGES[$page::PAGE]);
            $this->assertSame(AgentType::HILOS_DAEMON, Hilos::getPageRoutes()[$page::PAGE]);
        }
        $this->assertSame(DaemonEnvPage::class, Hilos::PAGES[DaemonEnvPage::PAGE]);
        $this->assertSame(HilosAgentType::HILOS_DAEMON_NODE, Hilos::getPageRoutes()[DaemonEnvPage::PAGE]);

        $pageAgent = Hilos::AGENTS[AgentType::HILOS_DAEMON];
        $this->assertSame(DemoHilosDaemonAgent::class, AgentRegistry::workerClass($pageAgent));
        $this->assertSame(DemoHilosDaemonAgentDaemon::class, AgentRegistry::daemonClass($pageAgent));
        $this->assertSame(AgentScope::CLUSTER, AgentRegistry::scope($pageAgent));
        $this->assertSame(AgentPlacement::LEADER, AgentRegistry::placement($pageAgent));
        $this->assertFalse((new DemoHilosDaemonAgentDaemon())->requiresMonopolisticProcess());

        $nodeAgent = Hilos::AGENTS[HilosAgentType::HILOS_DAEMON_NODE];
        $this->assertSame(DaemonNodeAgent::class, AgentRegistry::workerClass($nodeAgent));
        $this->assertSame(DaemonNodeAgentDaemon::class, AgentRegistry::daemonClass($nodeAgent));
        $this->assertSame(AgentScope::NODE, AgentRegistry::scope($nodeAgent));
        $this->assertFalse((new DaemonNodeAgentDaemon())->requiresMonopolisticProcess());

        $collector = Hilos::AGENTS[HilosAgentType::HILOS_DAEMON_COLLECTOR];
        $this->assertSame(DaemonCollectorAgent::class, AgentRegistry::workerClass($collector));
        $this->assertSame(DaemonCollectorAgentDaemon::class, AgentRegistry::daemonClass($collector));
        $this->assertSame(AgentPlacement::POLICY, AgentRegistry::placement($collector));
        $this->assertFalse((new DaemonCollectorAgentDaemon())->requiresMonopolisticProcess());
    }

    public function testLogsAdminFeatureIsActivated(): void
    {
        // The logs section is a configure-only framework feature: the demo registers the six
        // section pages against their framework abstracts, mounts the section agent with the
        // per-node store and the cluster aggregator behind it, binds the three browser tables
        // the list screens read, and writes not a line of the section itself.
        $logPages = [
            LogsOverviewPage::PAGE => LogsOverviewPage::class,
            LogsKeysPage::PAGE => LogsKeysPage::class,
            LogsWorkersPage::PAGE => LogsWorkersPage::class,
            LogsRotationsPage::PAGE => LogsRotationsPage::class,
            LogsViewPage::PAGE => LogsViewPage::class,
            LogsSettingsPage::PAGE => LogsSettingsPage::class,
        ];
        $this->assertSame($logPages, array_intersect_key(Hilos::PAGES, $logPages));

        $this->assertSame(
            [
                AgentType::HILOS_LOGS,
                HilosAgentType::HILOS_LOG_STORE,
                HilosAgentType::HILOS_LOG_CARRIER,
                HilosAgentType::HILOS_LOG_AGGREGATOR,
            ],
            array_values(array_intersect(array_keys(Hilos::AGENTS), [
                AgentType::HILOS_LOGS,
                HilosAgentType::HILOS_LOG_STORE,
                HilosAgentType::HILOS_LOG_CARRIER,
                HilosAgentType::HILOS_LOG_AGGREGATOR,
            ])),
        );
        $this->assertSame(DemoHilosLogsAgent::class, AgentRegistry::workerClass(
            Hilos::AGENTS[AgentType::HILOS_LOGS],
        ));
        $this->assertSame(DemoHilosLogsAgentDaemon::class, AgentRegistry::daemonClass(
            Hilos::AGENTS[AgentType::HILOS_LOGS],
        ));

        $this->assertSame(
            [TasksTableContext::hilosLogKeys => []],
            Hilos::PAGE_TABLES[LogsKeysPage::PAGE],
        );
        $this->assertSame(
            [TasksTableContext::hilosLogRotations => []],
            Hilos::PAGE_TABLES[LogsRotationsPage::PAGE],
        );
        $this->assertSame(
            [TasksTableContext::hilosLogWorkers => []],
            Hilos::PAGE_TABLES[LogsWorkersPage::PAGE],
        );
        $this->assertSame(
            [TasksTableContext::hilosDaemonCron => []],
            Hilos::PAGE_TABLES[DaemonCronPage::PAGE],
        );
        $this->assertSame(
            [TasksTableContext::hilosDaemonWorkers => []],
            Hilos::PAGE_TABLES[DaemonWorkersPage::PAGE],
        );
        $this->assertSame(
            [TasksTableContext::hilosDaemonAgents => []],
            Hilos::PAGE_TABLES[DaemonAgentsPage::PAGE],
        );

        // The logging modes screen writes framework keys, and a key the project catalog does
        // not know is written and then silently treated as an orphan (HIL-857). Merging the
        // feature's own catalog in is the whole of the fix, and nothing else asserts it.
        $this->assertSame([], array_diff_key(
            LogSettingsCatalog::getCatalog(),
            TasksSettingsCatalog::getCatalog(),
        ));
    }

    public function testProjectTopologyPassesStartupValidation(): void
    {
        // The first check init() runs, and the one every project boots under. It judges
        // this project's real registry, unlike the framework's TopologyValidatorTest, which
        // only runs it against invented fixture facades.
        Hilos::validateTopology();

        $this->addToAssertionCount(1);
    }

    /**
     * The length of the shared-ownership debt, so no receipt can be written in silence.
     *
     * A ceiling and not the exact rows: an addition paints this red, a removal passes quietly,
     * because nothing should stand in the way of a debt getting smaller. Asserting the exact
     * contents instead would paint this test red on every PARTING - the one move the list
     * exists to bring about. Five database collections carry it today, all of them the tables of
     * signing in, and no runtime collection is shared here at all.
     */
    public function testSharedOwnershipDebtDoesNotGrow(): void
    {
        $this->assertLessThanOrEqual(5, count(Hilos::SHARED_DB_OWNERS));
        $this->assertSame([], Hilos::SHARED_RT_OWNERS);
    }

    /** Checks the project's complete activation of the framework Analytics section. */
    public function testAnalyticsFeatureIsActivated(): void
    {
        $this->assertContains(HilosFeature::ANALYTICS, Hilos::features());
        $this->assertSame(AnalyticsPage::class, Hilos::PAGES[AnalyticsPage::PAGE]);
        $this->assertSame(AgentType::HILOS_ANALYTICS, AnalyticsPage::SUBSCRIPTION_AGENT_TYPE);

        $reader = Hilos::AGENTS[AgentType::HILOS_ANALYTICS];
        $this->assertSame(DemoHilosAnalyticsAgent::class, AgentRegistry::workerClass($reader));
        $this->assertSame(DemoHilosAnalyticsAgentDaemon::class, AgentRegistry::daemonClass($reader));
        $this->assertTrue((new DemoHilosAnalyticsAgentDaemon())->requiresMonopolisticProcess());
        $this->assertArrayNotHasKey(AgentRegistryKey::SCOPE, $reader);
        $this->assertArrayNotHasKey(AgentRegistryKey::PLACEMENT, $reader);
        $this->assertSame(AgentScope::CLUSTER, AgentRegistry::scope($reader));
        $this->assertSame(AgentPlacement::LEADER, AgentRegistry::placement($reader));

        $journal = Hilos::AGENTS[HilosAgentType::HILOS_ANALYTICS_JOURNAL];
        $this->assertSame(AnalyticsJournalAgent::class, AgentRegistry::workerClass($journal));
        $this->assertSame(AnalyticsJournalAgentDaemon::class, AgentRegistry::daemonClass($journal));
        $this->assertSame(AgentScope::NODE, AgentRegistry::scope($journal));

        $writer = Hilos::AGENTS[HilosAgentType::HILOS_ANALYTICS_WRITER];
        $this->assertSame(AnalyticsWriterAgent::class, AgentRegistry::workerClass($writer));
        $this->assertSame(AnalyticsWriterAgentDaemon::class, AgentRegistry::daemonClass($writer));
        $this->assertSame(AgentPlacement::POLICY, AgentRegistry::placement($writer));

        $journalDtos = Hilos::getAgentSignalDtoRoutes();
        $this->assertSame(AnalyticsJournalAppendSignalData::class, $journalDtos[HilosSignalConstants::ANALYTICS_JOURNAL_APPEND]);
        $this->assertSame(AnalyticsJournalReadSignalData::class, $journalDtos[HilosSignalConstants::ANALYTICS_JOURNAL_READ]);
        $this->assertSame(AnalyticsJournalLoadedSignalData::class, $journalDtos[HilosSignalConstants::ANALYTICS_JOURNAL_LOADED]);
        $this->assertSame(AnalyticsJournalPortionSignalData::class, $journalDtos[HilosSignalConstants::ANALYTICS_JOURNAL_PORTION]);
        $this->assertSame(AnalyticsJournalReadySignalData::class, $journalDtos[HilosSignalConstants::ANALYTICS_JOURNAL_READY]);
        $nodeFields = Hilos::getAgentSignalNodeFields();
        $this->assertSame(AnalyticsJournalReadSignalData::nodeId, $nodeFields[HilosSignalConstants::ANALYTICS_JOURNAL_READ]);
        $this->assertSame(AnalyticsJournalLoadedSignalData::nodeId, $nodeFields[HilosSignalConstants::ANALYTICS_JOURNAL_LOADED]);
    }

    public function testDeclaredFeaturesAreFullyActivated(): void
    {
        // The startup activation check this project boots under: every declared feature has
        // its pages, agents, tables, bindings and catalogs, and nothing framework-owned is
        // registered without the declaration that switches it on.
        Hilos::validateFeatureActivation();

        $this->addToAssertionCount(1);
    }

    public function testDeclaredFeaturesHaveWhatStartupCannotCheck(): void
    {
        // The other half of the activation check, the half no starting process can make: the SQL
        // tables a declared feature reads live in migrations applied as a separate step, and the
        // presence source behind the users list is a runtime collection, not a constant.
        Hilos::validateDeferredFeatureRequirements(
            __DIR__ . '/../../backend/Database/Migration/Schema',
            CliManager::class,
            TasksRtContext::class,
        );

        $this->addToAssertionCount(1);
    }

    /**
     * @throws HilosException When the context refuses to configure, or the guard refuses a chain under a framework key
     */
    public function testDatabaseMountsAreWhole(): void
    {
        // The question the daemon asks first on its start, over this project's context and
        // without a database: configure() reads nothing. No framework key is extended here
        // today, so every key passes as the framework's own; the day one is, this names the
        // refusal in seconds instead of a stand that did not come up.
        $previous = Hilos::$db;
        try {
            Hilos::$db = new TasksDbContext();
            Hilos::$db->configure();
            FrameworkExtensionGuard::assertMountedExtensionsWhole();
            MountedCollectionKeyGuard::assertMountedKeysAgree();
        } finally {
            Hilos::$db = $previous;
        }

        $this->addToAssertionCount(1);
    }

    public function testProfileSignInPageIsActivated(): void
    {
        $this->assertSame(ProfileSignInPage::class, Hilos::PAGES[HilosPageConstants::HILOS_PROFILE_SIGN_IN]);
        $this->assertSame(AgentType::TASKS, ProfileSignInPage::SUBSCRIPTION_AGENT_TYPE);
        $this->assertSame(AgentType::TASKS, Hilos::getPageRoutes()[HilosPageConstants::HILOS_PROFILE_SIGN_IN]);
        $this->assertSame(
            HilosPageConstants::HILOS_PROFILE_SIGN_IN,
            Hilos::getPageActionRoutes()[HilosSignalConstants::HILOS_LINK_OAUTH_START],
        );
        $this->assertSame(
            [HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::BINDING],
            Hilos::PAGE_LISTS[HilosPageConstants::HILOS_PROFILE_SIGN_IN],
        );
    }
}

<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Tests\Unit;

use Demo\BinanceBtcTracker\Agents\BinanceBtcTrackerAgent;
use Demo\BinanceBtcTracker\Agents\Hilos\DataExportAgent;
use Demo\BinanceBtcTracker\Agents\Hilos\DemoHilosAgent;
use Demo\BinanceBtcTracker\Agents\Hilos\DemoHilosDaemonAgent;
use Demo\BinanceBtcTracker\Agents\Hilos\DemoHilosLogsAgent;
use Demo\BinanceBtcTracker\Agents\Hilos\NotificationsLibraryAgent;
use Demo\BinanceBtcTracker\Agents\Hilos\SessionsLibraryAgent;
use Demo\BinanceBtcTracker\Agents\Hilos\UsersLibraryAgent;
use Demo\BinanceBtcTracker\Constants\AgentType;
use Demo\BinanceBtcTracker\Constants\PageConstants;
use Demo\BinanceBtcTracker\Core\Agent\Daemon\BinanceBtcTrackerAgentDaemon;
use Demo\BinanceBtcTracker\Core\Agent\Daemon\Hilos\DemoHilosAgentDaemon;
use Demo\BinanceBtcTracker\Core\Agent\Daemon\Hilos\DemoHilosDaemonAgentDaemon;
use Demo\BinanceBtcTracker\Core\Agent\Daemon\Hilos\DemoHilosLogsAgentDaemon;
use Demo\BinanceBtcTracker\Core\Agent\Daemon\Hilos\NotificationsLibraryAgentDaemon;
use Demo\BinanceBtcTracker\Core\Agent\Daemon\Hilos\SessionsLibraryAgentDaemon;
use Demo\BinanceBtcTracker\Core\Agent\Daemon\Hilos\UsersLibraryAgentDaemon;
use Demo\BinanceBtcTracker\Database\BinanceBtcTrackerDbContext;
use Demo\BinanceBtcTracker\Database\Settings\BinanceBtcTrackerSettingsCatalog;
use Demo\BinanceBtcTracker\Groups\Hilos\NotificationsGroup;
use Demo\BinanceBtcTracker\Hilos;
use Demo\BinanceBtcTracker\Notification\BinanceBtcTrackerDeliveryChannelRegistry;
use Demo\BinanceBtcTracker\Pages\Hilos\AboutPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Backup\BackupPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Communications\CommunicationsChannelPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Communications\CommunicationsDeliveriesPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Communications\CommunicationsPage;
use Demo\BinanceBtcTracker\Pages\Hilos\DashboardPage;
use Demo\BinanceBtcTracker\Pages\Hilos\LicensePage;
use Demo\BinanceBtcTracker\Pages\Hilos\Daemon\DaemonPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Daemon\DaemonWorkersPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Daemon\DaemonAgentsPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Daemon\DaemonCronPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Daemon\DaemonWebsocketsPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Daemon\DaemonHttpServerPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Daemon\DaemonEnvPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Daemon\DaemonEnvMismatchPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Logs\LogsKeysPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Logs\LogsOverviewPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Logs\LogsRotationsPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Logs\LogsSettingsPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Logs\LogsViewPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Logs\LogsWorkersPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Maintenance\MaintenancePage;
use Demo\BinanceBtcTracker\Pages\Hilos\PrivacyPage;
use Demo\BinanceBtcTracker\Pages\Hilos\ProfileNotificationsPage;
use Demo\BinanceBtcTracker\Pages\Hilos\SettingsPage;
use Demo\BinanceBtcTracker\Pages\Hilos\TermsPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Users\UserPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Users\UsersPage;
use Demo\BinanceBtcTracker\Pages\MainPage;
use Demo\BinanceBtcTracker\Runtime\View\Context\BinanceBtcTrackerRtContext;
use Demo\BinanceBtcTracker\Tables\BinanceBtcTrackerTableContext;
use Demo\BinanceBtcTracker\Tables\HilosUser\HilosUsersTable;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\Agent\BackupAgentDaemon;
use Hilos\Cluster\Probe\ClusterProbe;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\HttpConstants;
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
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Schema\FrameworkExtensionGuard;
use Hilos\DataExport\DataExportAgentDaemon;
use Hilos\DataExport\DataExportHttp;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Database\Settings\Library\SettingsLibraryAgentDaemon;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\HilosException;
use Hilos\Log\LogSettingsCatalog;
use Hilos\Theme\ThemeSettingsCatalog;
use Hilos\Notification\Delivery\DeliveryLogSettingsCatalog;
use Hilos\Notification\NotificationAction;
use Hilos\Notification\NotificationPreferenceAction;
use Hilos\Push\PushSubscriptionAction;
use Hilos\Sms\Delivery\SmsDeliveryChannelAgent;
use Hilos\Sms\Delivery\SmsDeliveryChannelAgentDaemon;
use Hilos\Tables\Backup\HilosBackupHistoryTable;
use Hilos\Tables\Communications\HilosCommunicationsChannelFieldsTable;
use Hilos\Tables\Communications\HilosCommunicationsChannelsTable;
use Hilos\Tables\Communications\HilosNotificationDeliveriesTable;
use Hilos\Tables\Logs\HilosLogKeysTable;
use Hilos\Tables\Logs\HilosLogRotationsTable;
use Hilos\Tables\Logs\HilosLogWorkersTable;
use Hilos\Tables\ProtectedMode\HilosVerifierCircleTable;
use Hilos\Tables\Settings\HilosSettingsTable;
use Hilos\Tables\Users\HilosUserDetailBrowserTable;
use PHPUnit\Framework\TestCase;

/**
 * Guards the project-level binance-btc-tracker topology registry.
 *
 * The smallest complete shape: an app agent with its home page, the Hilos index agent with the
 * dashboard, the four footer pages and the admin sections activated so far - Backup, its page,
 * the archive table and the backup agent (HIL-1220), Maintenance, its page and the verifier
 * circle table (HIL-1221), Settings, its page, table and library, Logs, the six section pages,
 * the section agent and the three per-node and cluster agents behind it (HIL-1222),
 * Communications, its three pages and tables (HIL-1224), and Users, the people list and a
 * person's card with their two tables (HIL-1219) - sign-in activated on the framework
 * libraries, and notifications with delivery by email and SMS: the notifications library, its
 * group, the SMS channel agent and the one profile page, the person's channel switches
 * (HIL-1224). The agent registry closes on the framework's fleet and runtime-set probes,
 * which only its cluster stand runs (HIL-1215). The page registry
 * below is a snapshot, so every leaf that moves another admin section here turns it red on
 * purpose and rewrites it with its own.
 */
final class BinanceBtcTrackerTopologyRegistryTest extends TestCase
{
    /** The archive address is answered by the export owner. */
    public function testDataExportHttpRoute(): void
    {
        self::assertSame([
            HttpConstants::METHOD_GET => [DataExportHttp::DOWNLOAD_PATH => HilosAgentType::HILOS_DATA_EXPORT],
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

    public function testPageRegistryIsTheHomeTheDashboardTheAdminSectionsAndTheFooter(): void
    {
        $this->assertSame([
            MainPage::PAGE => MainPage::class,
            DashboardPage::PAGE => DashboardPage::class,
            BackupPage::PAGE => BackupPage::class,
            MaintenancePage::PAGE => MaintenancePage::class,
            SettingsPage::PAGE => SettingsPage::class,
            UsersPage::PAGE => UsersPage::class,
            UserPage::PAGE => UserPage::class,
            DaemonPage::PAGE => DaemonPage::class,
            DaemonWorkersPage::PAGE => DaemonWorkersPage::class,
            DaemonAgentsPage::PAGE => DaemonAgentsPage::class,
            DaemonCronPage::PAGE => DaemonCronPage::class,
            DaemonWebsocketsPage::PAGE => DaemonWebsocketsPage::class,
            DaemonHttpServerPage::PAGE => DaemonHttpServerPage::class,
            DaemonEnvPage::PAGE => DaemonEnvPage::class,
            DaemonEnvMismatchPage::PAGE => DaemonEnvMismatchPage::class,
            LogsOverviewPage::PAGE => LogsOverviewPage::class,
            LogsKeysPage::PAGE => LogsKeysPage::class,
            LogsWorkersPage::PAGE => LogsWorkersPage::class,
            LogsRotationsPage::PAGE => LogsRotationsPage::class,
            LogsViewPage::PAGE => LogsViewPage::class,
            LogsSettingsPage::PAGE => LogsSettingsPage::class,
            CommunicationsPage::PAGE => CommunicationsPage::class,
            CommunicationsChannelPage::PAGE => CommunicationsChannelPage::class,
            CommunicationsDeliveriesPage::PAGE => CommunicationsDeliveriesPage::class,
            ProfileNotificationsPage::PAGE => ProfileNotificationsPage::class,
            AboutPage::PAGE => AboutPage::class,
            TermsPage::PAGE => TermsPage::class,
            PrivacyPage::PAGE => PrivacyPage::class,
            LicensePage::PAGE => LicensePage::class,
        ], Hilos::PAGES);
    }

    public function testComputedPageRoutesCoverEveryRegisteredPage(): void
    {
        $this->assertSame(array_keys(Hilos::PAGES), array_keys(Hilos::getPageRoutes()));
    }

    /** No page is served by the agent of one entity instance. */
    public function testNoPageIsServedByTheAgentOfOneInstance(): void
    {
        $this->assertSame([], Hilos::getPageAgentIndexRoutes());
    }

    public function testPageRegistryKeysMatchPageClassConstants(): void
    {
        foreach (Hilos::PAGES as $page => $pageClass) {
            $this->assertSame($page, $pageClass::PAGE);
        }
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

    public function testAgentRegistryIsTheAppTheIndexSignInTheAdminSectionsAndTheClusterProbes(): void
    {
        $this->assertSame([
            AgentType::BINANCE_BTC_TRACKER,
            AgentType::HILOS_INDEX,
            AgentType::HILOS_DAEMON,
            AgentType::HILOS_LOGS,
            HilosAgentType::HILOS_DATA_EXPORT,
            HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosAgentType::HILOS_USERS_LIBRARY,
            AgentType::HILOS_NOTIFICATIONS_LIBRARY,
            HilosAgentType::HILOS_SETTINGS_LIBRARY,
            HilosAgentType::HILOS_MAIL,
            HilosAgentType::HILOS_SMS,
            HilosAgentType::HILOS_AUTH_THROTTLE,
            HilosAgentType::HILOS_BACKUP,
            HilosAgentType::HILOS_LOG_STORE,
            HilosAgentType::HILOS_LOG_CARRIER,
            HilosAgentType::HILOS_DAEMON_NODE,
            HilosAgentType::HILOS_DAEMON_COLLECTOR,
            HilosAgentType::HILOS_LOG_AGGREGATOR,
            HilosAgentType::HILOS_PROBE_FLEET,
            HilosAgentType::HILOS_PROBE_RT_SET,
        ], array_keys(Hilos::AGENTS));
    }

    public function testTheClusterProbesAreListedAsTheFrameworkWroteThem(): void
    {
        // The rows are the framework's records, not this demo's copy of them: the flags every
        // cluster scenario stands on are pinned once, in the framework's own registry test.
        foreach ([HilosAgentType::HILOS_PROBE_FLEET, HilosAgentType::HILOS_PROBE_RT_SET] as $agentType) {
            $this->assertSame(ClusterProbe::AGENTS[$agentType], Hilos::AGENTS[$agentType], "{$agentType} is listed as the framework wrote it");
        }
    }

    public function testPageSubscriptionOwnersAreDeclaredByPageClasses(): void
    {
        $pageRoutes = Hilos::getPageRoutes();

        foreach (Hilos::PAGES as $page => $pageClass) {
            $this->assertSame($pageClass::SUBSCRIPTION_AGENT_TYPE, $pageRoutes[$page]);
            $this->assertNotSame('', $pageClass::SUBSCRIPTION_AGENT_TYPE, "{$page} must declare a subscription owner");
        }
    }

    public function testMainPageIsOwnedByTheAppAgent(): void
    {
        $this->assertSame(MainPage::class, Hilos::PAGES[PageConstants::MAIN]);
        $this->assertSame(AgentType::BINANCE_BTC_TRACKER, MainPage::SUBSCRIPTION_AGENT_TYPE);
        $entry = Hilos::AGENTS[AgentType::BINANCE_BTC_TRACKER];
        $this->assertSame(BinanceBtcTrackerAgent::class, AgentRegistry::workerClass($entry));
        $this->assertSame(BinanceBtcTrackerAgentDaemon::class, AgentRegistry::daemonClass($entry));
        $this->assertFalse(AgentRegistry::requiresIndex($entry));
        $this->assertTrue((new BinanceBtcTrackerAgentDaemon())->requiresMonopolisticProcess());
        // The person's own channel switches are a profile page, and a profile page is the app
        // agent's, as in chat - not the index agent's, which serves the admin sections.
        $this->assertSame(AgentType::BINANCE_BTC_TRACKER, ProfileNotificationsPage::SUBSCRIPTION_AGENT_TYPE);
    }

    public function testHilosPagesAreOwnedByTheIndexAgent(): void
    {
        $hilosPages = [
            DashboardPage::class,
            BackupPage::class,
            MaintenancePage::class,
            SettingsPage::class,
            UsersPage::class,
            UserPage::class,
            CommunicationsPage::class,
            CommunicationsChannelPage::class,
            CommunicationsDeliveriesPage::class,
            AboutPage::class,
            TermsPage::class,
            PrivacyPage::class,
            LicensePage::class,
        ];
        foreach ($hilosPages as $page) {
            $this->assertSame(AgentType::HILOS_INDEX, $page::SUBSCRIPTION_AGENT_TYPE);
        }
        $entry = Hilos::AGENTS[AgentType::HILOS_INDEX];
        $this->assertSame(DemoHilosAgent::class, AgentRegistry::workerClass($entry));
        $this->assertSame(DemoHilosAgentDaemon::class, AgentRegistry::daemonClass($entry));
        $this->assertFalse(AgentRegistry::requiresIndex($entry));
    }

    public function testBackupAdminFeatureIsActivated(): void
    {
        // Backup is a configure-only framework feature with a monopoly agent behind it. It is
        // activated here because the backup e2e moved onto this demo (HIL-1220): the page
        // answered by the index agent, the agent pair, and its archive table. The verifier
        // circle is drawn in Maintenance.
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
            HilosBackupHistoryTable::class,
            Hilos::TABLES[BinanceBtcTrackerTableContext::hilosBackups],
        );
        $this->assertSame(
            [BinanceBtcTrackerTableContext::hilosBackups => []],
            Hilos::PAGE_TABLES[BackupPage::PAGE],
        );
    }

    /** Maintenance has no feature switch: page and table registration activate it (HIL-1221). */
    public function testMaintenanceSectionIsActivated(): void
    {
        $this->assertSame(MaintenancePage::class, Hilos::PAGES[MaintenancePage::PAGE]);
        $this->assertSame(AgentType::HILOS_INDEX, MaintenancePage::SUBSCRIPTION_AGENT_TYPE);
        $this->assertSame(
            HilosVerifierCircleTable::class,
            Hilos::TABLES[BinanceBtcTrackerTableContext::hilosVerifierCircle],
        );
        $this->assertSame(
            [BinanceBtcTrackerTableContext::hilosVerifierCircle => []],
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

    /** Settings is configure-only; its catalog carries the example keys and the fragments activated features require. */
    public function testSettingsAdminFeatureIsActivated(): void
    {
        $this->assertSame(SettingsPage::class, Hilos::PAGES[SettingsPage::PAGE]);
        $this->assertSame(AgentType::HILOS_INDEX, SettingsPage::SUBSCRIPTION_AGENT_TYPE);
        $this->assertSame(SettingsLibraryAgent::class, AgentRegistry::workerClass(
            Hilos::AGENTS[HilosAgentType::HILOS_SETTINGS_LIBRARY],
        ));
        $this->assertSame(SettingsLibraryAgentDaemon::class, AgentRegistry::daemonClass(
            Hilos::AGENTS[HilosAgentType::HILOS_SETTINGS_LIBRARY],
        ));
        $this->assertSame(AgentPlacement::POLICY, AgentRegistry::placement(
            Hilos::AGENTS[HilosAgentType::HILOS_SETTINGS_LIBRARY],
        ));
        $this->assertSame(
            HilosSettingsTable::class,
            Hilos::TABLES[BinanceBtcTrackerTableContext::settings],
        );
        $this->assertSame(
            [BinanceBtcTrackerTableContext::settings => []],
            Hilos::PAGE_TABLES[SettingsPage::PAGE],
        );
        // The rotation spec writes its thresholds through the settings screen, and a key the
        // project catalog does not know is written and then treated as an orphan (HIL-857).
        $catalog = BinanceBtcTrackerSettingsCatalog::getCatalog();
        $this->assertSame(LogSettingsCatalog::getCatalog(), array_intersect_key($catalog, LogSettingsCatalog::getCatalog()));
        foreach (ThemeSettingsCatalog::KEYS as $key) {
            $this->assertSame(ThemeSettingsCatalog::getCatalog()[$key], $catalog[$key]);
        }
        // The toast spec writes an example key through the same screen (HIL-1224).
        $this->assertArrayHasKey(SettingsCatalogConstants::STUB_KEY_EXAMPLE_BOOLEAN, $catalog);
    }

    /** The people are a bound framework feature: the demo binds its presence source and the card's table (HIL-1219). */
    public function testHilosUsersAdminFeatureIsActivated(): void
    {
        $this->assertContains(HilosFeature::HILOS_USERS, Hilos::features());
        $this->assertSame(UsersPage::class, Hilos::PAGES[UsersPage::PAGE]);
        $this->assertSame(UserPage::class, Hilos::PAGES[UserPage::PAGE]);
        $this->assertSame(AgentType::HILOS_INDEX, UsersPage::SUBSCRIPTION_AGENT_TYPE);
        $this->assertSame(AgentType::HILOS_INDEX, UserPage::SUBSCRIPTION_AGENT_TYPE);
        $this->assertSame(
            HilosUsersTable::class,
            Hilos::TABLES[BinanceBtcTrackerTableContext::hilosUsers],
        );
        $this->assertSame(
            [BinanceBtcTrackerTableContext::hilosUsers => []],
            Hilos::PAGE_TABLES[UsersPage::PAGE],
        );
        // The card is the framework's browser-only table, bound to the page's user id by its own binding;
        // no merge-candidates window is bound, because this demo wires no account merge.
        $this->assertSame(
            [
                HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::BINDING,
            ],
            Hilos::PAGE_TABLES[UserPage::PAGE],
        );
        $this->assertSame(
            [HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::class],
            Hilos::BROWSER_TABLES,
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
            DaemonEnvPage::class,
            DaemonEnvMismatchPage::class,
        ] as $page) {
            $this->assertSame($page, Hilos::PAGES[$page::PAGE]);
            $this->assertSame(AgentType::HILOS_DAEMON, Hilos::getPageRoutes()[$page::PAGE]);
        }

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
        // The logs section is a configure-only framework feature, activated here because the log
        // e2e moved onto this demo (HIL-1222): the six section pages against their framework
        // abstracts, the section agent with the per-node store and carrier and the cluster
        // aggregator behind it, and the three browser tables the list screens read.
        $logPages = [
            LogsOverviewPage::PAGE => LogsOverviewPage::class,
            LogsKeysPage::PAGE => LogsKeysPage::class,
            LogsWorkersPage::PAGE => LogsWorkersPage::class,
            LogsRotationsPage::PAGE => LogsRotationsPage::class,
            LogsViewPage::PAGE => LogsViewPage::class,
            LogsSettingsPage::PAGE => LogsSettingsPage::class,
        ];
        $this->assertSame($logPages, array_intersect_key(Hilos::PAGES, $logPages));
        foreach ($logPages as $page) {
            $this->assertSame(AgentType::HILOS_LOGS, $page::SUBSCRIPTION_AGENT_TYPE);
        }

        $this->assertSame(DemoHilosLogsAgent::class, AgentRegistry::workerClass(
            Hilos::AGENTS[AgentType::HILOS_LOGS],
        ));
        $this->assertSame(DemoHilosLogsAgentDaemon::class, AgentRegistry::daemonClass(
            Hilos::AGENTS[AgentType::HILOS_LOGS],
        ));
        $this->assertTrue((new DemoHilosLogsAgentDaemon())->requiresMonopolisticProcess());

        $this->assertSame(
            [BinanceBtcTrackerTableContext::hilosLogKeys => []],
            Hilos::PAGE_TABLES[LogsKeysPage::PAGE],
        );
        $this->assertSame(
            [BinanceBtcTrackerTableContext::hilosLogRotations => []],
            Hilos::PAGE_TABLES[LogsRotationsPage::PAGE],
        );
        $this->assertSame(
            [BinanceBtcTrackerTableContext::hilosLogWorkers => []],
            Hilos::PAGE_TABLES[LogsWorkersPage::PAGE],
        );
        $this->assertSame(HilosLogKeysTable::class, Hilos::TABLES[BinanceBtcTrackerTableContext::hilosLogKeys]);
        $this->assertSame(HilosLogRotationsTable::class, Hilos::TABLES[BinanceBtcTrackerTableContext::hilosLogRotations]);
        $this->assertSame(HilosLogWorkersTable::class, Hilos::TABLES[BinanceBtcTrackerTableContext::hilosLogWorkers]);
    }

    public function testNotificationsAndDeliveryAreActivated(): void
    {
        // Notifications and their delivery are an activation, not a build (HIL-1224): the demo
        // declares the two features, registers the library and its group, the channel agents
        // and the communications section, and every row and command behind them is the
        // framework's. The channels are exactly the two this demo's specs drive.
        $this->assertContains(HilosFeature::NOTIFICATIONS, Hilos::features());
        $this->assertContains(HilosFeature::NOTIFICATION_DELIVERY, Hilos::features());
        $this->assertSame([NotificationsGroup::GROUP => NotificationsGroup::class], Hilos::GROUPS);

        $library = Hilos::AGENTS[AgentType::HILOS_NOTIFICATIONS_LIBRARY];
        $this->assertSame(NotificationsLibraryAgent::class, AgentRegistry::workerClass($library));
        $this->assertSame(NotificationsLibraryAgentDaemon::class, AgentRegistry::daemonClass($library));
        $this->assertSame(AgentPlacement::POLICY, AgentRegistry::placement($library));
        $sms = Hilos::AGENTS[HilosAgentType::HILOS_SMS];
        $this->assertSame(SmsDeliveryChannelAgent::class, AgentRegistry::workerClass($sms));
        $this->assertSame(SmsDeliveryChannelAgentDaemon::class, AgentRegistry::daemonClass($sms));

        $channels = BinanceBtcTrackerDeliveryChannelRegistry::all();
        $this->assertSame(['email', 'sms'], array_keys($channels));
        $catalog = BinanceBtcTrackerSettingsCatalog::getCatalog();
        foreach ($channels as $channel => $descriptor) {
            // A notification queued from a worker has to find a live agent by the name the
            // channel descriptor gives its delivery frame.
            $this->assertArrayHasKey(
                $descriptor->deliverSignalName(),
                Hilos::getAgentSignalRoutes(),
                "Channel {$channel} lost its delivery route",
            );
            $this->assertArrayHasKey($descriptor->enabledSettingKey(), $catalog);
        }
        $this->assertSame([], array_diff_key(DeliveryLogSettingsCatalog::getCatalog(), $catalog));

        $this->assertSame(
            HilosCommunicationsChannelsTable::class,
            Hilos::TABLES[BinanceBtcTrackerTableContext::hilosCommunicationsChannels],
        );
        $this->assertSame(
            HilosCommunicationsChannelFieldsTable::class,
            Hilos::TABLES[BinanceBtcTrackerTableContext::hilosCommunicationsChannelFields],
        );
        $this->assertSame(
            HilosNotificationDeliveriesTable::class,
            Hilos::TABLES[BinanceBtcTrackerTableContext::hilosNotificationDeliveries],
        );
        $this->assertSame(
            [BinanceBtcTrackerTableContext::hilosCommunicationsChannels => []],
            Hilos::PAGE_TABLES[CommunicationsPage::PAGE],
        );
        $this->assertSame(
            [BinanceBtcTrackerTableContext::hilosCommunicationsChannelFields => []],
            Hilos::PAGE_TABLES[CommunicationsChannelPage::PAGE],
        );
        $this->assertSame(
            [BinanceBtcTrackerTableContext::hilosNotificationDeliveries => []],
            Hilos::PAGE_TABLES[CommunicationsDeliveriesPage::PAGE],
        );

        // The stand seeds twenty-five people with no agent behind the write (test:user:seed),
        // and that command lays down the claim this constant declares.
        $this->assertSame(
            [BinanceBtcTrackerDbContext::users => TruthSourceOperation::BY_KIND],
            Hilos::OWNS_DB,
        );
    }

    public function testAppOwnSurfaceStaysTransportOnly(): void
    {
        // The application's OWN surface is transport-only: its main page and worker push no
        // server-driven data. The one browser table is the card of the framework's people
        // (HIL-1219), the one group is the framework's notification group, and the tables and
        // page actions the admin sections bring are the framework's too; each pins its own above.
        //
        // The one frame the worker is addressed by is not its surface but the seam the sessions
        // moved behind (HIL-710): the library says what a session became, and this agent updates
        // the connection rows that belong to the project. The sweep frame is not declared, so the
        // library does not send it.
        $this->assertSame([NotificationsGroup::GROUP => NotificationsGroup::class], Hilos::GROUPS);
        $this->assertSame([HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::class], Hilos::BROWSER_TABLES);
        $this->assertSame([], MainPage::ACTIONS);
        $this->assertSame([], MainPage::SIGNALS);
        $this->assertSame(
            [HilosSignalConstants::HILOS_SESSION_STATE => SessionStateSignalData::class],
            BinanceBtcTrackerAgent::AGENT_SIGNALS,
        );
        $this->assertSame(
            [
                HilosSignalConstants::HILOS_SESSION_STATE => AgentType::BINANCE_BTC_TRACKER,
                // The logs section's own frames (HIL-1222 activates the feature here, with the
                // log e2e): the section agent takes the cluster picture in portions, the per-node
                // store answers the reads the viewer and the rotations screen ask for, and the
                // aggregator collects what each node reports and watches the index.
                HilosSignalConstants::LOGS_CLUSTER_INDEX_PORTION => HilosAgentType::HILOS_LOGS,
                HilosSignalConstants::HILOS_DATA_EXPORT_FORGET_USER => HilosAgentType::HILOS_DATA_EXPORT,
                // The other half of the seam, and the endings the users library hands over:
                // what a sign-in became reaches the library that owns the session.
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
                // waiting on raises a toast on their session (HIL-768).
                HilosSignalConstants::HILOS_SESSION_TOAST_RAISE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_IMPERSONATE_REQUEST => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_CODE_SEND_STEP => HilosAgentType::HILOS_SESSIONS_LIBRARY,
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
                // Sign-in's own frames (HIL-623): the users library waits for the throttle
                // verdict, and the mail agent and the node-scoped throttle answer on their own
                // names.
                HilosSignalConstants::HILOS_AUTH_THROTTLE_VERDICT => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_OAUTH_LOGIN_READY => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_ACCOUNT_DELETION_SET => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_ADMIN_RENAME => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_PROFILE_PHOTO_VERDICT => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_PROFILE_PHOTO_PUBLISHED => HilosAgentType::HILOS_USERS_LIBRARY,
                // The notifications library's own frames (HIL-1224 activates the feature here,
                // with the notification e2e): an emit from any worker, the retry of a delivery,
                // the hand-over of a person's rows and their erasure. The push frame is mounted
                // with the library whether or not the push channel is registered.
                HilosSignalConstants::HILOS_NOTIFICATION_EMIT => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
                HilosSignalConstants::HILOS_DELIVERY_RETRY => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
                HilosSignalConstants::HILOS_NOTIFICATION_HANDOVER => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
                HilosSignalConstants::HILOS_PUSH_SUBSCRIPTIONS_GONE => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
                // Every settings write goes through the library that owns the rows (HIL-1222).
                HilosSignalConstants::HILOS_SETTING_WRITE => HilosAgentType::HILOS_SETTINGS_LIBRARY,
                HilosSignalConstants::HILOS_SETTING_RESET => HilosAgentType::HILOS_SETTINGS_LIBRARY,
                HilosSignalConstants::HILOS_SETTING_DELETE => HilosAgentType::HILOS_SETTINGS_LIBRARY,
                HilosSignalConstants::HILOS_SETTING_PRESET_APPLY => HilosAgentType::HILOS_SETTINGS_LIBRARY,
                HilosSignalConstants::HILOS_MAIL_DELIVER => HilosAgentType::HILOS_MAIL,
                HilosSignalConstants::HILOS_MAIL_SEND => HilosAgentType::HILOS_MAIL,
                HilosSignalConstants::HILOS_SMS_DELIVER => HilosAgentType::HILOS_SMS,
                HilosSignalConstants::HILOS_SMS_SEND => HilosAgentType::HILOS_SMS,
                HilosSignalConstants::HILOS_AUTH_THROTTLE_CHECK => HilosAgentType::HILOS_AUTH_THROTTLE,
                HilosSignalConstants::HILOS_AUTH_THROTTLE_SUCCEEDED => HilosAgentType::HILOS_AUTH_THROTTLE,
                // The backup section's own frames: the page hands every operation to the
                // monopoly agent, and the carry-over receipts of a restore come back to it
                // (HIL-1220 activates the feature here, with the backup e2e).
                HilosSignalConstants::BACKUP_AGENT_CREATE => HilosAgentType::HILOS_BACKUP,
                HilosSignalConstants::BACKUP_AGENT_DELETE => HilosAgentType::HILOS_BACKUP,
                HilosSignalConstants::BACKUP_AGENT_SET_KEEP => HilosAgentType::HILOS_BACKUP,
                HilosSignalConstants::BACKUP_AGENT_RESTORE => HilosAgentType::HILOS_BACKUP,
                HilosSignalConstants::BACKUP_AGENT_REOPEN => HilosAgentType::HILOS_BACKUP,
                HilosSignalConstants::BACKUP_AGENT_SESSIONS_CARRIED => HilosAgentType::HILOS_BACKUP,
                HilosSignalConstants::BACKUP_AGENT_NOTICES_SENT => HilosAgentType::HILOS_BACKUP,
                HilosSignalConstants::LOGS_AGENT_READ_LINES => HilosAgentType::HILOS_LOG_STORE,
                HilosSignalConstants::LOGS_AGENT_FOLLOW_START => HilosAgentType::HILOS_LOG_STORE,
                HilosSignalConstants::LOGS_AGENT_FOLLOW_STOP => HilosAgentType::HILOS_LOG_STORE,
                HilosSignalConstants::LOGS_AGENT_TAKEOUT_CONFIRM => HilosAgentType::HILOS_LOG_STORE,
                HilosSignalConstants::LOGS_AGENT_TAKEOUT_UNDO => HilosAgentType::HILOS_LOG_STORE,
                HilosSignalConstants::LOGS_NODE_INDEX_REPORT => HilosAgentType::HILOS_LOG_AGGREGATOR,
                HilosSignalConstants::LOGS_INDEX_WATCH => HilosAgentType::HILOS_LOG_AGGREGATOR,
            ],
            Hilos::getAgentSignalRoutes(),
        );
    }

    public function testSignInIsActivatedOnTheFrameworkLibrary(): void
    {
        // Sign-in is an activation, not a build (HIL-623): the demo declares the two features
        // and registers the library pairs, and every command name behind the surface is the
        // framework's. The snapshot is the whole action map on purpose - a command that silently
        // stopped being routed here would otherwise look like a working surface until somebody
        // submitted the form it belongs to. The other features are backup (HIL-1220), settings
        // and logs (HIL-1222) and the people (HIL-1219), whose actions are their pages' and so
        // stay out of this map, and notifications with their delivery (HIL-1224), whose actions
        // close the map.
        $this->assertSame(
            [
                HilosFeature::AUTH,
                HilosFeature::AUTH_THROTTLE,
                HilosFeature::BACKUP,
                HilosFeature::SETTINGS,
                HilosFeature::HILOS_USERS,
                HilosFeature::LOGS,
                HilosFeature::DAEMON,
                HilosFeature::NOTIFICATIONS,
                HilosFeature::NOTIFICATION_DELIVERY,
            ],
            Hilos::features(),
        );

        $this->assertSame(UsersLibraryAgent::class, AgentRegistry::workerClass(
            Hilos::AGENTS[HilosAgentType::HILOS_USERS_LIBRARY],
        ));
        $this->assertSame(UsersLibraryAgentDaemon::class, AgentRegistry::daemonClass(
            Hilos::AGENTS[HilosAgentType::HILOS_USERS_LIBRARY],
        ));
        $this->assertSame(SessionsLibraryAgent::class, AgentRegistry::workerClass(
            Hilos::AGENTS[HilosAgentType::HILOS_SESSIONS_LIBRARY],
        ));
        $this->assertSame(SessionsLibraryAgentDaemon::class, AgentRegistry::daemonClass(
            Hilos::AGENTS[HilosAgentType::HILOS_SESSIONS_LIBRARY],
        ));

        $this->assertSame([
            HilosSignalConstants::HILOS_DATA_EXPORT_ORDER => HilosAgentType::HILOS_DATA_EXPORT,
            // Signing out, dismissing an ack and leaving a takeover all write a session, so
            // the sessions library owns them (HIL-710, HIL-729) - this demo adds an action of
            // its own for none of them.
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
            // The bell and the person's channel switches write the rows the notifications
            // library owns (HIL-1224); the push actions come with the library, as in every
            // demo that switches notifications on.
            NotificationAction::MARK_READ => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            NotificationAction::MARK_ALL_READ => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            NotificationPreferenceAction::CHANNEL_SET => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            PushSubscriptionAction::SUBSCRIBE => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            PushSubscriptionAction::UNSUBSCRIBE => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            PushSubscriptionAction::REMOVE => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
        ], Hilos::getAgentActionRoutes());
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
     * because nothing should stand in the way of a debt getting smaller. Three database
     * collections carry it today, all of them the tables of signing in, and no runtime
     * collection is shared here at all.
     */
    public function testSharedOwnershipDebtDoesNotGrow(): void
    {
        $this->assertLessThanOrEqual(3, count(Hilos::SHARED_DB_OWNERS));
        $this->assertSame([], Hilos::SHARED_RT_OWNERS);
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
        // tables a declared feature reads live in migrations applied as a separate step.
        Hilos::validateDeferredFeatureRequirements(
            __DIR__ . '/../../backend/Database/Migration/Schema',
            CliManager::class,
            BinanceBtcTrackerRtContext::class,
        );

        $this->addToAssertionCount(1);
    }

    /**
     * @throws HilosException When the context refuses to configure, or the guard refuses a chain under a framework key
     */
    public function testFrameworkExtensionsAreWhole(): void
    {
        // The question the daemon asks first on its start, over this project's context and
        // without a database: configure() reads nothing. No framework key is extended here, so
        // every key passes as the framework's own; the day one is, this names the refusal in
        // seconds instead of a stand that did not come up.
        $previous = Hilos::$db;
        try {
            Hilos::$db = new BinanceBtcTrackerDbContext();
            Hilos::$db->configure();
            FrameworkExtensionGuard::assertMountedExtensionsWhole();
        } finally {
            Hilos::$db = $previous;
        }

        $this->addToAssertionCount(1);
    }
}

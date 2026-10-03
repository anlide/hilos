<?php

declare(strict_types=1);

namespace Demo\OnlineTesting\Tests\Unit;

use Demo\OnlineTesting\Agents\OnlineTestingAgent;
use Demo\OnlineTesting\Agents\Hilos\DataExportAgent;
use Demo\OnlineTesting\Agents\Hilos\DemoHilosAgent;
use Demo\OnlineTesting\Agents\Hilos\DemoHilosLogsAgent;
use Demo\OnlineTesting\Agents\Hilos\NotificationsLibraryAgent;
use Demo\OnlineTesting\Agents\Hilos\SessionsLibraryAgent;
use Demo\OnlineTesting\Agents\Hilos\UsersLibraryAgent;
use Demo\OnlineTesting\Constants\AgentType;
use Demo\OnlineTesting\Constants\PageConstants;
use Demo\OnlineTesting\Core\Agent\Daemon\OnlineTestingAgentDaemon;
use Demo\OnlineTesting\Core\Agent\Daemon\Hilos\DemoHilosAgentDaemon;
use Demo\OnlineTesting\Core\Agent\Daemon\Hilos\DemoHilosLogsAgentDaemon;
use Demo\OnlineTesting\Core\Agent\Daemon\Hilos\NotificationsLibraryAgentDaemon;
use Demo\OnlineTesting\Core\Agent\Daemon\Hilos\SessionsLibraryAgentDaemon;
use Demo\OnlineTesting\Core\Agent\Daemon\Hilos\UsersLibraryAgentDaemon;
use Demo\OnlineTesting\Database\OnlineTestingDbContext;
use Demo\OnlineTesting\Database\Settings\OnlineTestingSettingsCatalog;
use Demo\OnlineTesting\Hilos;
use Demo\OnlineTesting\Groups\Hilos\NotificationsGroup;
use Demo\OnlineTesting\Pages\Hilos\AboutPage;
use Demo\OnlineTesting\Pages\Hilos\DashboardPage;
use Demo\OnlineTesting\Pages\Hilos\LicensePage;
use Demo\OnlineTesting\Pages\Hilos\Logs\LogsKeysPage;
use Demo\OnlineTesting\Pages\Hilos\Logs\LogsOverviewPage;
use Demo\OnlineTesting\Pages\Hilos\Logs\LogsRotationsPage;
use Demo\OnlineTesting\Pages\Hilos\Logs\LogsSettingsPage;
use Demo\OnlineTesting\Pages\Hilos\Logs\LogsViewPage;
use Demo\OnlineTesting\Pages\Hilos\Logs\LogsWorkersPage;
use Demo\OnlineTesting\Pages\Hilos\Maintenance\MaintenancePage;
use Demo\OnlineTesting\Pages\Hilos\PrivacyPage;
use Demo\OnlineTesting\Pages\Hilos\SettingsPage;
use Demo\OnlineTesting\Pages\Hilos\TermsPage;
use Demo\OnlineTesting\Pages\Hilos\Users\UserPage;
use Demo\OnlineTesting\Pages\Hilos\Users\UsersPage;
use Demo\OnlineTesting\Pages\MainPage;
use Demo\OnlineTesting\Runtime\View\Context\OnlineTestingRtContext;
use Demo\OnlineTesting\Tables\HilosUser\HilosUsersTable;
use Demo\OnlineTesting\Tables\OnlineTestingTableContext;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Cluster\Probe\ClusterProbe;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\HttpConstants;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\CLI\CliManager;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Database\Settings\Library\SettingsLibraryAgentDaemon;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\Schema\FrameworkExtensionGuard;
use Hilos\DataExport\DataExportAgentDaemon;
use Hilos\DataExport\DataExportHttp;
use Hilos\HilosException;
use Hilos\Log\LogAggregatorAgent;
use Hilos\Log\LogCarrierAgent;
use Hilos\Log\LogSettingsCatalog;
use Hilos\Log\LogStoreAgent;
use Hilos\Notification\NotificationAction;
use Hilos\Notification\NotificationPreferenceAction;
use Hilos\Push\PushSubscriptionAction;
use Hilos\Tables\Logs\HilosLogKeysTable;
use Hilos\Tables\Logs\HilosLogRotationsTable;
use Hilos\Tables\Logs\HilosLogWorkersTable;
use Hilos\Tables\ProtectedMode\HilosVerifierCircleTable;
use Hilos\Tables\Settings\HilosSettingsTable;
use Hilos\Tables\Users\HilosUserDetailBrowserTable;
use PHPUnit\Framework\TestCase;

/**
 * Guards the project-level online-testing topology registry.
 *
 * Guards the home, admin sections, notifications, sign-in and cluster probe registry.
 * Snapshots intentionally name every page and agent; a later activation must update them.
 */
final class OnlineTestingTopologyRegistryTest extends TestCase
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

    public function testPageRegistryIncludesTheAdminSections(): void
    {
        $this->assertSame([
            MainPage::PAGE => MainPage::class,
            DashboardPage::PAGE => DashboardPage::class,
            SettingsPage::PAGE => SettingsPage::class,
            LogsOverviewPage::PAGE => LogsOverviewPage::class,
            LogsKeysPage::PAGE => LogsKeysPage::class,
            LogsWorkersPage::PAGE => LogsWorkersPage::class,
            LogsRotationsPage::PAGE => LogsRotationsPage::class,
            LogsViewPage::PAGE => LogsViewPage::class,
            LogsSettingsPage::PAGE => LogsSettingsPage::class,
            MaintenancePage::PAGE => MaintenancePage::class,
            UsersPage::PAGE => UsersPage::class,
            UserPage::PAGE => UserPage::class,
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

    public function testAgentRegistryIncludesTheAdminSectionsAndTheClusterProbes(): void
    {
        $this->assertSame([
            AgentType::ONLINE_TESTING,
            AgentType::HILOS_INDEX,
            AgentType::HILOS_LOGS,
            HilosAgentType::HILOS_DATA_EXPORT,
            HilosAgentType::HILOS_SESSIONS_LIBRARY,
            AgentType::HILOS_NOTIFICATIONS_LIBRARY,
            HilosAgentType::HILOS_SETTINGS_LIBRARY,
            HilosAgentType::HILOS_USERS_LIBRARY,
            HilosAgentType::HILOS_MAIL,
            HilosAgentType::HILOS_LOG_STORE,
            HilosAgentType::HILOS_LOG_CARRIER,
            HilosAgentType::HILOS_LOG_AGGREGATOR,
            HilosAgentType::HILOS_AUTH_THROTTLE,
            HilosAgentType::HILOS_PROBE_FLEET,
            HilosAgentType::HILOS_PROBE_DB,
        ], array_keys(Hilos::AGENTS));
    }

    public function testTheClusterProbesAreListedAsTheFrameworkWroteThem(): void
    {
        // The rows are the framework's records, not this demo's copy of them: the flags every
        // cluster scenario stands on are pinned once, in the framework's own registry test.
        foreach ([HilosAgentType::HILOS_PROBE_FLEET, HilosAgentType::HILOS_PROBE_DB] as $agentType) {
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
        $this->assertSame(AgentType::ONLINE_TESTING, MainPage::SUBSCRIPTION_AGENT_TYPE);
        $entry = Hilos::AGENTS[AgentType::ONLINE_TESTING];
        $this->assertSame(OnlineTestingAgent::class, AgentRegistry::workerClass($entry));
        $this->assertSame(OnlineTestingAgentDaemon::class, AgentRegistry::daemonClass($entry));
        $this->assertFalse(AgentRegistry::requiresIndex($entry));
        $this->assertTrue((new OnlineTestingAgentDaemon())->requiresMonopolisticProcess());
    }

    public function testHilosPagesAreOwnedByTheIndexAgent(): void
    {
        $hilosPages = [
            DashboardPage::class,
            SettingsPage::class,
            MaintenancePage::class,
            UsersPage::class,
            UserPage::class,
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

    public function testAppOwnSurfaceStaysTransportOnly(): void
    {
        // The application's OWN surface is transport-only: its main page and worker push no
        // server-driven data. The admin tables and notification group belong to their features.
        //
        // The one frame the worker is addressed by is not its surface but the seam the sessions
        // moved behind (HIL-710): the library says what a session became, and this agent updates
        // the connection rows that belong to the project. The sweep frame is not declared, so the
        // library does not send it.
        $this->assertSame([], MainPage::ACTIONS);
        $this->assertSame([], MainPage::SIGNALS);
        $this->assertSame(
            [HilosSignalConstants::HILOS_SESSION_STATE => SessionStateSignalData::class],
            OnlineTestingAgent::AGENT_SIGNALS,
        );
        $this->assertSame(
            [
                HilosSignalConstants::HILOS_SESSION_STATE => AgentType::ONLINE_TESTING,
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
                HilosSignalConstants::HILOS_AUTH_OTHER_SESSIONS_END => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_CANCEL => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_ACCOUNT_BLOCK_CHANGED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_PROFILE_FLOW_STEP => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_NOTIFICATION_EMIT => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
                HilosSignalConstants::HILOS_DELIVERY_RETRY => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
                HilosSignalConstants::HILOS_NOTIFICATION_HANDOVER => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
                HilosSignalConstants::HILOS_PUSH_SUBSCRIPTIONS_GONE => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
                HilosSignalConstants::HILOS_SETTING_WRITE => HilosAgentType::HILOS_SETTINGS_LIBRARY,
                HilosSignalConstants::HILOS_SETTING_RESET => HilosAgentType::HILOS_SETTINGS_LIBRARY,
                HilosSignalConstants::HILOS_SETTING_DELETE => HilosAgentType::HILOS_SETTINGS_LIBRARY,
                HilosSignalConstants::HILOS_SETTING_PRESET_APPLY => HilosAgentType::HILOS_SETTINGS_LIBRARY,
                // Sign-in's own frames (HIL-623): the users library waits for the throttle
                // verdict, and the mail agent and the node-scoped throttle answer on their own
                // names.
                HilosSignalConstants::HILOS_AUTH_THROTTLE_VERDICT => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_OAUTH_LOGIN_READY => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_ACCOUNT_DELETION_SET => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_ADMIN_RENAME => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_PROFILE_PHOTO_VERDICT => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_PROFILE_PHOTO_PUBLISHED => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_MAIL_DELIVER => HilosAgentType::HILOS_MAIL,
                HilosSignalConstants::HILOS_MAIL_SEND => HilosAgentType::HILOS_MAIL,
                HilosSignalConstants::LOGS_AGENT_READ_LINES => HilosAgentType::HILOS_LOG_STORE,
                HilosSignalConstants::LOGS_AGENT_FOLLOW_START => HilosAgentType::HILOS_LOG_STORE,
                HilosSignalConstants::LOGS_AGENT_FOLLOW_STOP => HilosAgentType::HILOS_LOG_STORE,
                HilosSignalConstants::LOGS_AGENT_TAKEOUT_CONFIRM => HilosAgentType::HILOS_LOG_STORE,
                HilosSignalConstants::LOGS_AGENT_TAKEOUT_UNDO => HilosAgentType::HILOS_LOG_STORE,
                HilosSignalConstants::LOGS_NODE_INDEX_REPORT => HilosAgentType::HILOS_LOG_AGGREGATOR,
                HilosSignalConstants::LOGS_INDEX_WATCH => HilosAgentType::HILOS_LOG_AGGREGATOR,
                HilosSignalConstants::HILOS_AUTH_THROTTLE_CHECK => HilosAgentType::HILOS_AUTH_THROTTLE,
                HilosSignalConstants::HILOS_AUTH_THROTTLE_SUCCEEDED => HilosAgentType::HILOS_AUTH_THROTTLE,
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
        // submitted the form it belongs to.
        $this->assertSame([
            HilosFeature::SETTINGS,
            HilosFeature::HILOS_USERS,
            HilosFeature::LOGS,
            HilosFeature::NOTIFICATIONS,
            HilosFeature::AUTH,
            HilosFeature::AUTH_THROTTLE,
        ], Hilos::features());

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
        $this->assertSame([
            OnlineTestingTableContext::settings => HilosSettingsTable::class,
            OnlineTestingTableContext::hilosUsers => HilosUsersTable::class,
            OnlineTestingTableContext::hilosVerifierCircle => HilosVerifierCircleTable::class,
            OnlineTestingTableContext::hilosLogKeys => HilosLogKeysTable::class,
            OnlineTestingTableContext::hilosLogRotations => HilosLogRotationsTable::class,
            OnlineTestingTableContext::hilosLogWorkers => HilosLogWorkersTable::class,
        ], Hilos::TABLES);
        $this->assertSame(
            [HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::class],
            Hilos::BROWSER_TABLES,
        );
        $this->assertSame([
            SettingsPage::PAGE,
            MaintenancePage::PAGE,
            LogsKeysPage::PAGE,
            LogsRotationsPage::PAGE,
            LogsWorkersPage::PAGE,
            UsersPage::PAGE,
            UserPage::PAGE,
        ], array_keys(Hilos::PAGE_TABLES));
        $this->assertSame([OnlineTestingTableContext::settings => []], Hilos::PAGE_TABLES[SettingsPage::PAGE]);
        $this->assertSame([OnlineTestingTableContext::hilosUsers => []], Hilos::PAGE_TABLES[UsersPage::PAGE]);
        $this->assertSame([
            HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::BINDING,
        ], Hilos::PAGE_TABLES[UserPage::PAGE]);
        $this->assertSame(OnlineTestingDbContext::users, UserPage::READS_DB[0]);

        $settingsLibrary = Hilos::AGENTS[HilosAgentType::HILOS_SETTINGS_LIBRARY];
        $this->assertSame(SettingsLibraryAgent::class, AgentRegistry::workerClass($settingsLibrary));
        $this->assertSame(SettingsLibraryAgentDaemon::class, AgentRegistry::daemonClass($settingsLibrary));
        $this->assertSame(AgentPlacement::POLICY, AgentRegistry::placement($settingsLibrary));
        $catalog = OnlineTestingSettingsCatalog::getCatalog();
        foreach ([
            SettingsCatalogConstants::STUB_KEY_EXAMPLE_STRING,
            SettingsCatalogConstants::STUB_KEY_EXAMPLE_INTEGER,
            SettingsCatalogConstants::STUB_KEY_EXAMPLE_BOOLEAN,
        ] as $key) {
            $this->assertArrayHasKey($key, $catalog);
        }
        $this->assertSame([], array_diff_key(LogSettingsCatalog::getCatalog(), $catalog));
        $this->assertSame(UserPage::PAGE, Hilos::getPageActionRoutes()[HilosSignalConstants::HILOS_USER_UPDATE]);
    }

    /** Maintenance has no feature switch; its page and verifier table activate it. */
    public function testMaintenanceSectionIsActivated(): void
    {
        $this->assertSame(MaintenancePage::class, Hilos::PAGES[MaintenancePage::PAGE]);
        $this->assertSame(AgentType::HILOS_INDEX, MaintenancePage::SUBSCRIPTION_AGENT_TYPE);
        $this->assertSame(
            [OnlineTestingTableContext::hilosVerifierCircle => []],
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

    public function testLogsAdminFeatureIsActivated(): void
    {
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
        $this->assertSame(DemoHilosLogsAgent::class, AgentRegistry::workerClass(Hilos::AGENTS[AgentType::HILOS_LOGS]));
        $this->assertSame(DemoHilosLogsAgentDaemon::class, AgentRegistry::daemonClass(Hilos::AGENTS[AgentType::HILOS_LOGS]));
        $this->assertTrue((new DemoHilosLogsAgentDaemon())->requiresMonopolisticProcess());
        $this->assertSame(AgentScope::NODE, AgentRegistry::scope(Hilos::AGENTS[HilosAgentType::HILOS_LOG_STORE]));
        $this->assertSame(AgentScope::NODE, AgentRegistry::scope(Hilos::AGENTS[HilosAgentType::HILOS_LOG_CARRIER]));
        $this->assertSame(AgentPlacement::POLICY, AgentRegistry::placement(Hilos::AGENTS[HilosAgentType::HILOS_LOG_AGGREGATOR]));
        $this->assertSame(LogStoreAgent::class, AgentRegistry::workerClass(Hilos::AGENTS[HilosAgentType::HILOS_LOG_STORE]));
        $this->assertSame(LogCarrierAgent::class, AgentRegistry::workerClass(Hilos::AGENTS[HilosAgentType::HILOS_LOG_CARRIER]));
        $this->assertSame(LogAggregatorAgent::class, AgentRegistry::workerClass(Hilos::AGENTS[HilosAgentType::HILOS_LOG_AGGREGATOR]));
        $this->assertSame([OnlineTestingTableContext::hilosLogKeys => []], Hilos::PAGE_TABLES[LogsKeysPage::PAGE]);
        $this->assertSame([OnlineTestingTableContext::hilosLogRotations => []], Hilos::PAGE_TABLES[LogsRotationsPage::PAGE]);
        $this->assertSame([OnlineTestingTableContext::hilosLogWorkers => []], Hilos::PAGE_TABLES[LogsWorkersPage::PAGE]);
    }

    public function testNotificationCenterIsActivatedWithoutDelivery(): void
    {
        $this->assertContains(HilosFeature::NOTIFICATIONS, Hilos::features());
        $this->assertNotContains(HilosFeature::NOTIFICATION_DELIVERY, Hilos::features());
        $this->assertSame([NotificationsGroup::GROUP => NotificationsGroup::class], Hilos::GROUPS);
        $library = Hilos::AGENTS[AgentType::HILOS_NOTIFICATIONS_LIBRARY];
        $this->assertSame(NotificationsLibraryAgent::class, AgentRegistry::workerClass($library));
        $this->assertSame(NotificationsLibraryAgentDaemon::class, AgentRegistry::daemonClass($library));
        $this->assertSame(AgentPlacement::POLICY, AgentRegistry::placement($library));
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
            OnlineTestingRtContext::class,
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
            Hilos::$db = new OnlineTestingDbContext();
            Hilos::$db->configure();
            FrameworkExtensionGuard::assertMountedExtensionsWhole();
        } finally {
            Hilos::$db = $previous;
        }

        $this->addToAssertionCount(1);
    }
}

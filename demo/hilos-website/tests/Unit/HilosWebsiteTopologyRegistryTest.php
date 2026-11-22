<?php

declare(strict_types=1);

namespace Demo\HilosWebsite\Tests\Unit;

use Hilos\Users\DTO\UserIdentityAddDoneSignalData;
use Hilos\Users\DTO\UserIdentityAddSignalData;
use Demo\HilosWebsite\Browser\HilosWebsiteBrowserContext;
use Demo\HilosWebsite\Pages\Hilos\ProfileDataPage;
use Demo\HilosWebsite\Pages\Hilos\ProfilePage;
use Demo\HilosWebsite\Pages\Hilos\ProfileSignInPage;
use Hilos\Database\Context\HilosDbContext;
use Demo\HilosWebsite\Pages\Hilos\LicenseDependenciesPage;
use Demo\HilosWebsite\Database\Pages\PageCatalog;
use Hilos\Database\Pages\HilosPageCatalog;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageReach;
use Demo\HilosWebsite\Core\Daemon\HilosWebsiteWorkerManager;
use Demo\HilosWebsite\Pages\Hilos\TermsHistoryPage;
use Demo\HilosWebsite\Pages\Hilos\PrivacyHistoryPage;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Runtime\State\Item\HilosProfileNameChange;
use Demo\HilosWebsite\Agents\Hilos\DemoHilosAnalyticsAgent;
use Demo\HilosWebsite\Core\Agent\Daemon\Hilos\DemoHilosAnalyticsAgentDaemon;
use Demo\HilosWebsite\Pages\Hilos\AnalyticsPage;
use Demo\HilosWebsite\Pages\Hilos\Analytics\BrowserSessionsPage;
use Demo\HilosWebsite\Pages\Hilos\Analytics\BrowserSessionPage;
use Demo\HilosWebsite\Pages\Hilos\Analytics\PeoplePage;
use Demo\HilosWebsite\Pages\Hilos\Analytics\PersonPage;
use Hilos\Core\Analytics\AnalyticsJournalAgent;
use Hilos\Core\Analytics\AnalyticsJournalAgentDaemon;
use Hilos\Core\Analytics\AnalyticsWriterAgent;
use Hilos\Core\Analytics\AnalyticsWriterAgentDaemon;
use Hilos\Core\Analytics\DTO\AnalyticsJournalAppendSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalLoadedSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalPortionSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalReadSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalReadySignalData;
use Demo\HilosWebsite\Agents\HilosWebsiteAgent;
use Demo\HilosWebsite\Auth\HilosWebsiteAuthMethodDirectory;
use Demo\HilosWebsite\Agents\Hilos\DataExportAgent;
use Demo\HilosWebsite\Agents\Hilos\DemoHilosAgent;
use Demo\HilosWebsite\Agents\Hilos\NotificationsLibraryAgent;
use Demo\HilosWebsite\Agents\Hilos\SessionsLibraryAgent;
use Demo\HilosWebsite\Agents\Hilos\UserAgent;
use Demo\HilosWebsite\Agents\Hilos\UsersLibraryAgent;
use Demo\HilosWebsite\Constants\AgentType;
use Demo\HilosWebsite\Constants\PageConstants;
use Demo\HilosWebsite\Core\Agent\Daemon\HilosWebsiteAgentDaemon;
use Demo\HilosWebsite\Core\Agent\Daemon\Hilos\DemoHilosAgentDaemon;
use Demo\HilosWebsite\Core\Agent\Daemon\Hilos\NotificationsLibraryAgentDaemon;
use Demo\HilosWebsite\Core\Agent\Daemon\Hilos\SessionsLibraryAgentDaemon;
use Demo\HilosWebsite\Core\Agent\Daemon\Hilos\UserAgentDaemon;
use Demo\HilosWebsite\Core\Agent\Daemon\Hilos\UsersLibraryAgentDaemon;
use Demo\HilosWebsite\Database\HilosWebsiteDbContext;
use Demo\HilosWebsite\Database\Settings\HilosWebsiteSettingsCatalog;
use Demo\HilosWebsite\Groups\Hilos\NotificationsGroup;
use Demo\HilosWebsite\Notification\HilosWebsiteDeliveryChannelRegistry;
use Demo\HilosWebsite\Pages\Hilos\Communications\CommunicationsDeliveriesPage;
use Demo\HilosWebsite\Hilos;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Demo\HilosWebsite\Pages\Hilos\AboutPage;
use Demo\HilosWebsite\Pages\Hilos\DashboardPage;
use Demo\HilosWebsite\Pages\Hilos\LicensePage;
use Demo\HilosWebsite\Pages\Hilos\PrivacyPage;
use Demo\HilosWebsite\Pages\Hilos\SettingsPage;
use Demo\HilosWebsite\Pages\Hilos\TermsPage;
use Demo\HilosWebsite\Pages\HistoryPage;
use Demo\HilosWebsite\Pages\MainPage;
use Demo\HilosWebsite\Runtime\View\Context\HilosWebsiteRtContext;
use Demo\HilosWebsite\Tables\HilosWebsiteTableContext;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Pages\Communications\DTO\HilosDeliveryRetryActionDTO;
use Hilos\Auth\Library\DTO\ThemePickActionDTO;
use Hilos\Auth\Library\DTO\TimeZonePickActionDTO;
use Hilos\Users\DTO\UserThemePickWriteDoneSignalData;
use Hilos\Users\DTO\UserTimeZoneWriteDoneSignalData;
use Hilos\Users\DTO\UserTimeZoneWriteSignalData;
use Hilos\Users\DTO\UserLastBrowserTimeZoneWriteSignalData;
use Hilos\Users\DTO\UserIdentityProviderNameWriteSignalData;
use Hilos\Constants\HttpConstants;
use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\Throttle\Agent\AuthThrottleAgent;
use Hilos\Auth\Throttle\Agent\AuthThrottleAgentDaemon;
use Hilos\Auth\Throttle\AuthThrottleSettingsCatalog;
use Hilos\Core\Analytics\AnalyticsSettingsCatalog;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Mail\Delivery\MailDeliveryChannelAgentDaemon;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\CLI\CliManager;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Database\Settings\Library\SettingsLibraryAgentDaemon;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\Database;
use Hilos\Database\Schema\FrameworkExtensionGuard;
use Hilos\DataExport\DataExportAgentDaemon;
use Hilos\DataExport\DataExportHttp;
use Hilos\HilosException;
use Hilos\Tables\Analytics\HilosAnalyticsActionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionListTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsPeopleTable;
use Hilos\Tables\Communications\HilosNotificationDeliveriesTable;
use Hilos\Notification\Delivery\ChannelSettingsCatalog;
use Hilos\Notification\Delivery\DeliveryChannelSettings;
use Hilos\Notification\Delivery\DeliveryLogSettingsCatalog;
use Hilos\Notification\NotificationAction;
use Hilos\Notification\NotificationPreferenceAction;
use Hilos\Push\PushSubscriptionAction;
use Hilos\Tables\Legal\HilosPrivacyHistoryTable;
use Hilos\Tables\Legal\HilosTermsHistoryTable;
use Hilos\Tables\Settings\HilosSettingsTable;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Pages\Profile\HilosProfileIdentitiesBrowserList;
use PHPUnit\Framework\TestCase;
use Hilos\Core\Agent\TopologyAgentFactory;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Guards the project-level hilos-website topology registry.
 *
 * Guards the home, the admin sections and the sign-in registry of the site: Settings and
 * Analytics, password sign-in with registration closed, and the mail that carries the codes
 * with the notifications and the delivery journal it stands on.
 * Snapshots intentionally name every page and agent; a later activation must update them.
 */
final class HilosWebsiteTopologyRegistryTest extends TestCase
{
    public function testIdentityAdditionIsDeliveredToTheDeclaredOwnerAndCaller(): void
    {
        self::assertSame(HilosAgentType::HILOS_USER, Hilos::getAgentSignalRoutes()[HilosSignalConstants::HILOS_USER_IDENTITY_ADD]);
        self::assertSame(UserIdentityAddSignalData::userId, Hilos::getAgentSignalIndexFields()[HilosSignalConstants::HILOS_USER_IDENTITY_ADD]);
        self::assertSame(UserIdentityAddSignalData::class, Hilos::getAgentSignalDtoRoutes()[HilosSignalConstants::HILOS_USER_IDENTITY_ADD]);
        self::assertSame(
            HilosAgentType::HILOS_USERS_LIBRARY,
            Hilos::getAgentSignalRoutes()[HilosSignalConstants::HILOS_USER_IDENTITY_ADD_DONE],
        );
        self::assertSame(
            UserIdentityAddDoneSignalData::class,
            Hilos::getAgentSignalDtoRoutes()[HilosSignalConstants::HILOS_USER_IDENTITY_ADD_DONE],
        );
        self::assertArrayNotHasKey(HilosSignalConstants::HILOS_USER_IDENTITY_ADD_DONE, Hilos::getAgentSignalIndexFields());
        self::assertArrayNotHasKey(HilosSignalConstants::HILOS_OAUTH_IDENTITY_ADD_DONE, Hilos::getAgentSignalRoutes());
        self::assertArrayNotHasKey(HilosSignalConstants::HILOS_OAUTH_AGENTS_GONE, Hilos::getAgentSignalRoutes());
        self::assertSame(HilosAgentType::HILOS_SESSIONS_LIBRARY, Hilos::getAgentSignalRoutes()[HilosSignalConstants::HILOS_AGENTS_GONE]);
    }

    /** The dependency snapshot stays a build input on an ordinary public page. */
    public function testLicenseDependenciesUsesThePublicIndexPageWithoutServerData(): void
    {
        self::assertSame('hilos_license_dependencies', LicenseDependenciesPage::PAGE);
        self::assertSame(LicenseDependenciesPage::class, Hilos::PAGES[LicenseDependenciesPage::PAGE]);
        self::assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[LicenseDependenciesPage::PAGE]);
        self::assertSame(PageAccessLevel::PUBLIC, LicenseDependenciesPage::ACCESS_LEVEL);
        self::assertSame(PageReach::ROUTE, LicenseDependenciesPage::REACH);
        self::assertSame([], LicenseDependenciesPage::ACTIONS);
        self::assertSame([], LicenseDependenciesPage::SIGNALS);
        self::assertSame([], LicenseDependenciesPage::BROWSER);
        self::assertArrayNotHasKey(LicenseDependenciesPage::PAGE, Hilos::PAGE_TABLES);
        self::assertArrayNotHasKey(LicenseDependenciesPage::PAGE, HilosPageCatalog::CATALOG);
        self::assertArrayNotHasKey(LicenseDependenciesPage::PAGE, PageCatalog::pages());
        $factory = new HilosPageFactory($this->createStub(PageAgentInterface::class), Hilos::class);
        self::assertInstanceOf(LicenseDependenciesPage::class, $factory->getPage(LicenseDependenciesPage::PAGE));
    }

    /** Every served profile section is the site agent's and binds the common personal identity source once. */
    public function testEveryProfileSectionBindsTheIdentityList(): void
    {
        self::assertSame(
            HilosProfileIdentitiesBrowserList::class,
            Hilos::BROWSER_LISTS[HilosProfileIdentitiesBrowserList::LIST],
        );
        foreach ([
            ProfilePage::class,
            ProfileDataPage::class,
            ProfileSignInPage::class,
        ] as $page) {
            self::assertSame($page, Hilos::PAGES[$page::PAGE]);
            self::assertSame(AgentType::WEBSITE, $page::SUBSCRIPTION_AGENT_TYPE);
            self::assertSame(AgentType::WEBSITE, Hilos::getPageRoutes()[$page::PAGE]);
            self::assertSame([HilosProfileIdentitiesBrowserList::class], $page::REQUIRED_LISTS);
            self::assertSame(
                HilosProfileIdentitiesBrowserList::BINDING,
                Hilos::PAGE_LISTS[$page::PAGE][HilosProfileIdentitiesBrowserList::LIST],
            );
        }
    }

    public function testProfileDataPageIsServedByHilosWebsiteAgentWithItsSources(): void
    {
        self::assertSame(ProfileDataPage::class, Hilos::PAGES[ProfileDataPage::PAGE]);
        self::assertSame(AgentType::WEBSITE, ProfileDataPage::SUBSCRIPTION_AGENT_TYPE);
        self::assertSame(AgentType::WEBSITE, Hilos::getPageRoutes()[ProfileDataPage::PAGE]);
        self::assertSame(PageAccessLevel::AUTHENTICATED, ProfileDataPage::ACCESS_LEVEL);
        self::assertSame([HilosProfileIdentitiesBrowserList::class], ProfileDataPage::REQUIRED_LISTS);
        self::assertSame(
            [HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::BINDING],
            Hilos::PAGE_LISTS[ProfileDataPage::PAGE],
        );
        self::assertContains(HilosDbContext::dataExports, ProfileDataPage::READS_DB);

        $browser = new HilosWebsiteBrowserContext();
        $browser->bindHilosFacade(Hilos::class);
        $dbSources = $browser->dbSourceKeysOfPage(ProfileDataPage::PAGE);
        self::assertContains(HilosWebsiteDbContext::identities, $dbSources);
        self::assertContains(HilosWebsiteDbContext::passkeyCredentials, $dbSources);
        self::assertSame([HilosWebsiteRtContext::connections], $browser->rtSourceKeysOfPage(ProfileDataPage::PAGE));
    }

    public function testSessionNameChangesHaveOneAuthSessionOwner(): void
    {
        self::assertContains(HilosFeature::AUTH, Hilos::features());
        $owners = [];
        foreach (Hilos::AGENTS as $type => $entry) {
            $workerClass = AgentRegistry::workerClass($entry);
            if ($workerClass === null) {
                continue;
            }
            $claims = OwnershipDeclaration::rtCollectionsOf($workerClass);
            if (!array_key_exists(HilosProfileNameChange::RT_COLLECTION, $claims)) {
                continue;
            }
            self::assertSame(AgentScope::CLUSTER, AgentRegistry::scope($entry));
            $owners[$type] = $claims[HilosProfileNameChange::RT_COLLECTION]->asList();
        }
        self::assertSame([
            HilosAgentType::HILOS_SESSIONS_LIBRARY => TruthSourceOperation::ALL,
        ], $owners);
    }

    public function testWorkerFactoryReadsTheUsersLibraryVerdictDeclaration(): void
    {
        $manager = new ReflectionClass(HilosWebsiteWorkerManager::class)->newInstanceWithoutConstructor();
        $factory = new ReflectionMethod(HilosWebsiteWorkerManager::class, 'createPageSignalRouter');
        $name = new ReflectionProperty(PageSignalRouter::class, 'throttleVerdictSignal');
        $users = $factory->invoke($manager, TopologyAgentFactory::createWorker(Hilos::class, HilosAgentType::HILOS_USERS_LIBRARY, null));
        self::assertInstanceOf(PageSignalRouter::class, $users);
        self::assertSame(HilosSignalConstants::HILOS_AUTH_THROTTLE_VERDICT, $name->getValue($users));
    }

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
            HistoryPage::PAGE => HistoryPage::class,
            DashboardPage::PAGE => DashboardPage::class,
            AnalyticsPage::PAGE => AnalyticsPage::class,
            BrowserSessionsPage::PAGE => BrowserSessionsPage::class,
            BrowserSessionPage::PAGE => BrowserSessionPage::class,
            PeoplePage::PAGE => PeoplePage::class,
            PersonPage::PAGE => PersonPage::class,
            CommunicationsDeliveriesPage::PAGE => CommunicationsDeliveriesPage::class,
            SettingsPage::PAGE => SettingsPage::class,
            ProfilePage::PAGE => ProfilePage::class,
            ProfileDataPage::PAGE => ProfileDataPage::class,
            ProfileSignInPage::PAGE => ProfileSignInPage::class,
            AboutPage::PAGE => AboutPage::class,
            TermsPage::PAGE => TermsPage::class,
            PrivacyPage::PAGE => PrivacyPage::class,
            TermsHistoryPage::PAGE => TermsHistoryPage::class,
            PrivacyHistoryPage::PAGE => PrivacyHistoryPage::class,
            LicensePage::PAGE => LicensePage::class,
            LicenseDependenciesPage::PAGE => LicenseDependenciesPage::class,
        ], Hilos::PAGES);
    }

    public function testComputedPageRoutesCoverEveryRegisteredPage(): void
    {
        $this->assertSame(array_keys(Hilos::PAGES), array_keys(Hilos::getPageRoutes()));
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

    public function testAgentRegistryIncludesOnlyTheActivatedSections(): void
    {
        $this->assertSame([
            AgentType::WEBSITE,
            AgentType::HILOS_INDEX,
            AgentType::HILOS_ANALYTICS,
            HilosAgentType::HILOS_ANALYTICS_JOURNAL,
            HilosAgentType::HILOS_ANALYTICS_WRITER,
            HilosAgentType::HILOS_DATA_EXPORT,
            HilosAgentType::HILOS_SESSIONS_LIBRARY,
            HilosAgentType::HILOS_SETTINGS_LIBRARY,
            HilosAgentType::HILOS_USER,
            HilosAgentType::HILOS_USERS_LIBRARY,
            HilosAgentType::HILOS_MAIL,
            HilosAgentType::HILOS_AUTH_THROTTLE,
            AgentType::HILOS_NOTIFICATIONS_LIBRARY,
        ], array_keys(Hilos::AGENTS));
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
        $this->assertSame(AgentType::WEBSITE, MainPage::SUBSCRIPTION_AGENT_TYPE);
        $entry = Hilos::AGENTS[AgentType::WEBSITE];
        $this->assertSame(HilosWebsiteAgent::class, AgentRegistry::workerClass($entry));
        $this->assertSame(HilosWebsiteAgentDaemon::class, AgentRegistry::daemonClass($entry));
        $this->assertFalse(AgentRegistry::requiresIndex($entry));
        $this->assertTrue((new HilosWebsiteAgentDaemon())->requiresMonopolisticProcess());
    }

    public function testHistoryPageIsOwnedByTheAppAgent(): void
    {
        $this->assertSame(HistoryPage::class, Hilos::PAGES[PageConstants::HISTORY]);
        $this->assertSame(AgentType::WEBSITE, HistoryPage::SUBSCRIPTION_AGENT_TYPE);
    }

    public function testHilosPagesAreOwnedByTheIndexAgent(): void
    {
        $hilosPages = [
            DashboardPage::class,
            CommunicationsDeliveriesPage::class,
            SettingsPage::class,
            AboutPage::class,
            TermsPage::class,
            PrivacyPage::class,
            TermsHistoryPage::class,
            PrivacyHistoryPage::class,
            LicensePage::class,
            LicenseDependenciesPage::class,
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
        // server-driven data. The admin tables belong to their features.
        //
        // The one frame the worker is addressed by is not its surface but the seam the sessions
        // moved behind (HIL-710): the library says what a session became, and this agent updates
        // the connection rows that belong to the project. The sweep frame is not declared, so the
        // library does not send it.
        $this->assertSame([], MainPage::ACTIONS);
        $this->assertSame([], MainPage::SIGNALS);
        $this->assertSame([], HistoryPage::ACTIONS);
        $this->assertSame([], HistoryPage::SIGNALS);
        $this->assertSame(
            [HilosSignalConstants::HILOS_SESSION_STATE => SessionStateSignalData::class],
            HilosWebsiteAgent::AGENT_SIGNALS,
        );
        $this->assertSame(
            [
                HilosSignalConstants::HILOS_SESSION_STATE => AgentType::WEBSITE,
                HilosSignalConstants::MAINTENANCE_AGENT_ENABLE => HilosAgentType::HILOS_INDEX,
                HilosSignalConstants::MAINTENANCE_AGENT_DISABLE => HilosAgentType::HILOS_INDEX,
                HilosSignalConstants::MAINTENANCE_AGENT_PASS => HilosAgentType::HILOS_INDEX,
                HilosSignalConstants::ANALYTICS_JOURNAL_APPEND => HilosAgentType::HILOS_ANALYTICS_JOURNAL,
                HilosSignalConstants::ANALYTICS_JOURNAL_READ => HilosAgentType::HILOS_ANALYTICS_JOURNAL,
                HilosSignalConstants::ANALYTICS_JOURNAL_LOADED => HilosAgentType::HILOS_ANALYTICS_JOURNAL,
                HilosSignalConstants::ANALYTICS_JOURNAL_PORTION => HilosAgentType::HILOS_ANALYTICS_WRITER,
                HilosSignalConstants::ANALYTICS_JOURNAL_READY => HilosAgentType::HILOS_ANALYTICS_WRITER,
                HilosSignalConstants::HILOS_DATA_EXPORT_ACCOUNT_OPERATION_STEP => HilosAgentType::HILOS_DATA_EXPORT,
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
                HilosSignalConstants::HILOS_ACCOUNT_MERGE_CANCEL => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_ACCOUNT_OPERATION_ANSWER => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_ACCOUNT_ADMIN_SET => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_ACCOUNT_BLOCK_SET => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                // The one frame with no fixed sender: whoever finished something a person is
                // waiting on raises a toast on their session (HIL-768).
                HilosSignalConstants::HILOS_SESSION_TOAST_RAISE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_IMPERSONATE_REQUEST => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SESSIONS_END_REQUEST => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_CODE_SEND_STEP => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_SESSION_CARRYOVER_HANDOVER => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_OAUTH_TRIP_OPENED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_OAUTH_TRIP_ENDED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AGENTS_GONE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_REGISTRATION_WAIT_HELD => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_REGISTRATION_HOLD_EXPIRED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_MISSED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_SETUP_PROVEN => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_OFF => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_TRUST_DAYS_APPLY => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_DISABLE_APPLY => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_IMPERSONATION_OFF_APPLY => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_OTHER_SESSIONS_END => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_SECOND_FACTOR_CANCEL => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_ACCOUNT_BLOCK_CHANGED => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_PROFILE_FLOW_STEP => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_PROFILE_NAME_BEGIN => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_PROFILE_NAME_ADVANCE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SESSIONS_RESTATE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_USER_ADMIN_WRITE_DONE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_USER_ADMIN_COMMAND_DONE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_USER_ADMIN_CREATE_DONE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_USER_BLOCK_WRITE_DONE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_USER_STEP_UP_CREDIT_DONE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_USER_BROWSER_TRUST_REVOKE_DONE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_USER_BROWSER_TRUST_RENEW_DONE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT_DONE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_SETTING_WRITE => HilosAgentType::HILOS_SETTINGS_LIBRARY,
                HilosSignalConstants::HILOS_SETTING_RESET => HilosAgentType::HILOS_SETTINGS_LIBRARY,
                HilosSignalConstants::HILOS_SETTING_DELETE => HilosAgentType::HILOS_SETTINGS_LIBRARY,
                HilosSignalConstants::HILOS_SETTING_PRESET_APPLY => HilosAgentType::HILOS_SETTINGS_LIBRARY,
                HilosSignalConstants::HILOS_SETTING_COMMAND_DONE => HilosAgentType::HILOS_SETTINGS_LIBRARY,
                // Sign-in's own frames (HIL-623): the users library waits for the throttle
                // verdict, and the mail agent and the node-scoped throttle answer on their own
                // names.
                HilosSignalConstants::HILOS_USER_ACCOUNT_OPERATION_STEP => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_ACCOUNT_MERGE_HANDOVER => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_RENAME => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_ADMIN_WRITE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_ADMIN_COMMAND => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_BLOCK_WRITE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_THEME_PICK_WRITE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_TIME_ZONE_WRITE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_LAST_BROWSER_TIME_ZONE_WRITE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_IDENTITY_PROVIDER_NAME_WRITE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_PASSWORD_REHASH => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_ADDRESS_VERIFY => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_PASSKEY_USE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_PASSWORD_RESET => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_IDENTITY_ADD => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_PASSWORD_CHANGE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_EMAIL_CHANGE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_IDENTITY_UNLINK => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_CONFIRM => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_REMOVE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_CANCEL => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_WAIT_WRITE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_START => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_CODES_ISSUE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_REQUEST => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_DUE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_REMIND => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_UNLOCK => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_LEGAL_HOLD_FORGET => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_STEP_UP_RECORD => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_STEP_UP_CREDIT => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_BROWSER_TRUST_REVOKE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_BROWSER_TRUST_RENEW => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_ACCOUNT_DELETION_CANCEL => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_PHOTO_PUT => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_PHOTO_REMOVE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_NOTIFICATION_READ => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_NOTIFICATION_TEST_CLEANUP => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_NOTIFICATION_CHANNEL_SET => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_PUSH_SUBSCRIBE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_PUSH_UNSUBSCRIBE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_PUSH_REMOVE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_PUSH_ENDPOINT_GONE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_PUSH_RELEASE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USERS_LIBRARY_ACCOUNT_OPERATION_STEP => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_PROFILE_NAME_CONTINUE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_CODE_MINT => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_REGISTRATION_HOLD_RELEASE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_AUTH_THROTTLE_VERDICT => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_THEME_PICK_ADOPT_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_THEME_PICK_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_TIME_ZONE_WRITE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_OAUTH_LOGIN_READY => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_ACCOUNT_DELETION_SET => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_ADMIN_RENAME => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_ADMIN_CREATE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_RENAME_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_PASSWORD_REHASH_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_ADDRESS_VERIFY_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_PASSKEY_USE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_PASSWORD_RESET_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_IDENTITY_ADD_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_PASSWORD_CHANGE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_EMAIL_CHANGE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_IDENTITY_UNLINK_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_CONFIRM_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_REMOVE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_CANCEL_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_WAIT_WRITE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_START_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_CODES_ISSUE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_REQUEST_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_DUE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_REMIND_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_UNLOCK_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_LEGAL_HOLD_FORGET_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_STEP_UP_RECORD_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_ACCOUNT_DELETION_CANCEL_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_PHOTO_PUT_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_PHOTO_REMOVE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_PROFILE_PHOTO_PUBLISHED => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_MAIL_ACCOUNT_OPERATION_STEP => HilosAgentType::HILOS_MAIL,
                HilosSignalConstants::HILOS_MAIL_DELIVER => HilosAgentType::HILOS_MAIL,
                HilosSignalConstants::HILOS_MAIL_SEND => HilosAgentType::HILOS_MAIL,
                HilosSignalConstants::HILOS_MAIL_DELIVERY_RETRY => HilosAgentType::HILOS_MAIL,
                HilosSignalConstants::HILOS_AUTH_THROTTLE_CHECK => HilosAgentType::HILOS_AUTH_THROTTLE,
                HilosSignalConstants::HILOS_AUTH_THROTTLE_SUCCEEDED => HilosAgentType::HILOS_AUTH_THROTTLE,
                HilosSignalConstants::HILOS_NOTIFICATION_EMIT => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
                HilosSignalConstants::HILOS_DELIVERY_RETRY => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
                HilosSignalConstants::HILOS_NOTIFICATION_HANDOVER => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
                HilosSignalConstants::HILOS_USER_NOTIFICATION_WRITE_DONE => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
                HilosSignalConstants::HILOS_USER_PUSH_RELEASE_DONE => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
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
            HilosFeature::AUTH,
            HilosFeature::AUTH_THROTTLE,
            HilosFeature::ANALYTICS,
            HilosFeature::NOTIFICATIONS,
            HilosFeature::NOTIFICATION_DELIVERY,
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
            HilosSignalConstants::HILOS_DETECT_IDENTIFIER => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_THEME_ADOPT => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_THEME_PICK => HilosAgentType::HILOS_USERS_LIBRARY,
            HilosSignalConstants::HILOS_TIME_ZONE_PICK => HilosAgentType::HILOS_USERS_LIBRARY,
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
            HilosSignalConstants::PROFILE_NAME_SET => HilosAgentType::HILOS_USERS_LIBRARY,
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
            NotificationAction::MARK_READ => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            NotificationAction::MARK_ALL_READ => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            NotificationPreferenceAction::CHANNEL_SET => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            PushSubscriptionAction::SUBSCRIBE => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            PushSubscriptionAction::UNSUBSCRIBE => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            PushSubscriptionAction::REMOVE => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
        ], Hilos::getAgentActionRoutes());
        $this->assertSame(ThemePickActionDTO::class,
            Hilos::getAgentActionDtoRoutes()[HilosSignalConstants::HILOS_THEME_PICK]);
        $this->assertSame(TimeZonePickActionDTO::class,
            Hilos::getAgentActionDtoRoutes()[HilosSignalConstants::HILOS_TIME_ZONE_PICK]);
        $this->assertSame(UserThemePickWriteDoneSignalData::class,
            Hilos::getAgentSignalDtoRoutes()[HilosSignalConstants::HILOS_USER_THEME_PICK_DONE]);
        $this->assertSame(UserTimeZoneWriteSignalData::class,
            Hilos::getAgentSignalDtoRoutes()[HilosSignalConstants::HILOS_USER_TIME_ZONE_WRITE]);
        $this->assertSame('userId',
            Hilos::getAgentSignalIndexFields()[HilosSignalConstants::HILOS_USER_TIME_ZONE_WRITE]);
        $this->assertSame(UserLastBrowserTimeZoneWriteSignalData::class,
            Hilos::getAgentSignalDtoRoutes()[HilosSignalConstants::HILOS_USER_LAST_BROWSER_TIME_ZONE_WRITE]);
        $this->assertSame('userId',
            Hilos::getAgentSignalIndexFields()[HilosSignalConstants::HILOS_USER_LAST_BROWSER_TIME_ZONE_WRITE]);
        $this->assertSame(UserIdentityProviderNameWriteSignalData::class,
            Hilos::getAgentSignalDtoRoutes()[HilosSignalConstants::HILOS_USER_IDENTITY_PROVIDER_NAME_WRITE]);
        $this->assertSame('userId',
            Hilos::getAgentSignalIndexFields()[HilosSignalConstants::HILOS_USER_IDENTITY_PROVIDER_NAME_WRITE]);
        $this->assertSame(UserTimeZoneWriteDoneSignalData::class,
            Hilos::getAgentSignalDtoRoutes()[HilosSignalConstants::HILOS_USER_TIME_ZONE_WRITE_DONE]);
    }

    public function testSettingsAdminFeatureIsActivated(): void
    {
        $this->assertSame([
            HilosWebsiteTableContext::settings => HilosSettingsTable::class,
            HilosWebsiteTableContext::hilosTermsHistory => HilosTermsHistoryTable::class,
            HilosWebsiteTableContext::hilosPrivacyHistory => HilosPrivacyHistoryTable::class,
            HilosWebsiteTableContext::hilosAnalyticsActions => HilosAnalyticsActionsTable::class,
            HilosWebsiteTableContext::hilosAnalyticsBrowserSessionList => HilosAnalyticsBrowserSessionListTable::class,
            HilosWebsiteTableContext::hilosAnalyticsBrowserSessions => HilosAnalyticsBrowserSessionsTable::class,
            HilosWebsiteTableContext::hilosAnalyticsPeople => HilosAnalyticsPeopleTable::class,
            HilosWebsiteTableContext::hilosNotificationDeliveries => HilosNotificationDeliveriesTable::class,
        ], Hilos::TABLES);
        $this->assertSame([], Hilos::BROWSER_TABLES);
        $this->assertSame([], Hilos::BROWSER_DATA);
        $this->assertSame([], Hilos::PAGE_DATA);
        $this->assertSame([NotificationsGroup::GROUP => NotificationsGroup::class], Hilos::GROUPS);
        $this->assertSame([
            BrowserSessionsPage::PAGE,
            BrowserSessionPage::PAGE,
            PeoplePage::PAGE,
            PersonPage::PAGE,
            CommunicationsDeliveriesPage::PAGE,
            SettingsPage::PAGE,
            TermsHistoryPage::PAGE,
            PrivacyHistoryPage::PAGE,
        ], array_keys(Hilos::PAGE_TABLES));
        $this->assertSame([HilosWebsiteTableContext::settings => []], Hilos::PAGE_TABLES[SettingsPage::PAGE]);
        $this->assertSame([HilosWebsiteTableContext::hilosTermsHistory => []], Hilos::PAGE_TABLES[TermsHistoryPage::PAGE]);
        $this->assertSame([HilosWebsiteTableContext::hilosPrivacyHistory => []], Hilos::PAGE_TABLES[PrivacyHistoryPage::PAGE]);

        $settingsLibrary = Hilos::AGENTS[HilosAgentType::HILOS_SETTINGS_LIBRARY];
        $this->assertSame(SettingsLibraryAgent::class, AgentRegistry::workerClass($settingsLibrary));
        $this->assertSame(SettingsLibraryAgentDaemon::class, AgentRegistry::daemonClass($settingsLibrary));
        $this->assertSame(AgentPlacement::POLICY, AgentRegistry::placement($settingsLibrary));
        $this->assertSame(
            array_replace(
                ChannelSettingsCatalog::entriesFor(HilosWebsiteDeliveryChannelRegistry::all()),
                [
                    DeliveryChannelSettings::enabledKey(MailDeliveryChannel::NAME) => [
                        SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_BOOLEAN,
                        SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
                        SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => true,
                    ],
                ],
                DeliveryLogSettingsCatalog::getCatalog(),
                AnalyticsSettingsCatalog::getCatalog(),
                AuthThrottleSettingsCatalog::getCatalog(),
            ),
            HilosWebsiteSettingsCatalog::getCatalog(),
        );
        $this->assertArrayNotHasKey(SettingsCatalogConstants::STUB_KEY_EXAMPLE_STRING, HilosWebsiteSettingsCatalog::getCatalog());
    }

    /** Registration is closed: the one account is handed out by a command and signs in with a password. */
    public function testTheMethodDirectoryWiresOnlyThePasswordAndClosesRegistration(): void
    {
        self::assertSame(HilosWebsiteAuthMethodDirectory::class, Hilos::authMethodDirectoryClass());
        self::assertSame([AuthMethodKey::PASSWORD], HilosWebsiteAuthMethodDirectory::keys());
        self::assertFalse(HilosWebsiteAuthMethodDirectory::opensRegistration());
    }

    /** The auth throttle the site activates runs once, on the policy-selected node. */
    public function testAuthThrottleIsActivated(): void
    {
        self::assertContains(HilosFeature::AUTH_THROTTLE, Hilos::features());
        $entry = Hilos::AGENTS[HilosAgentType::HILOS_AUTH_THROTTLE];
        self::assertSame(AuthThrottleAgent::class, AgentRegistry::workerClass($entry));
        self::assertSame(AuthThrottleAgentDaemon::class, AgentRegistry::daemonClass($entry));
        self::assertSame(AgentScope::CLUSTER, AgentRegistry::scope($entry));
        self::assertSame(AgentPlacement::POLICY, AgentRegistry::placement($entry));
    }

    /**
     * The codes of a password reset and of an added password leave by mail, over the one email
     * channel of the delivery journal; the notifications the journal stands on are declared too.
     */
    public function testMailCarriesTheCodesOverTheEmailChannelOfTheDeliveryJournal(): void
    {
        self::assertContains(HilosFeature::NOTIFICATIONS, Hilos::features());
        self::assertContains(HilosFeature::NOTIFICATION_DELIVERY, Hilos::features());
        self::assertSame(HilosWebsiteDeliveryChannelRegistry::class, Hilos::notificationChannelRegistryClass());
        self::assertSame([MailDeliveryChannel::NAME], array_keys(HilosWebsiteDeliveryChannelRegistry::all()));
        $mail = Hilos::AGENTS[HilosAgentType::HILOS_MAIL];
        self::assertSame(MailDeliveryChannelAgent::class, AgentRegistry::workerClass($mail));
        self::assertSame(MailDeliveryChannelAgentDaemon::class, AgentRegistry::daemonClass($mail));
        self::assertTrue(AgentRegistry::requiresIndex($mail));
        self::assertSame(AgentPlacement::POLICY, AgentRegistry::placement($mail));
        $library = Hilos::AGENTS[AgentType::HILOS_NOTIFICATIONS_LIBRARY];
        self::assertSame(NotificationsLibraryAgent::class, AgentRegistry::workerClass($library));
        self::assertSame(NotificationsLibraryAgentDaemon::class, AgentRegistry::daemonClass($library));
        self::assertSame(AgentPlacement::POLICY, AgentRegistry::placement($library));
        self::assertSame(AgentType::HILOS_NOTIFICATIONS_LIBRARY, NotificationsGroup::SUBSCRIPTION_AGENT_TYPE);
        self::assertSame(
            [HilosWebsiteTableContext::hilosNotificationDeliveries => []],
            Hilos::PAGE_TABLES[CommunicationsDeliveriesPage::PAGE],
        );
        self::assertSame(
            HilosDeliveryRetryActionDTO::class,
            Hilos::getActionDtoRoutes()[HilosSignalConstants::COMMUNICATIONS_DELIVERY_RETRY],
        );
        self::assertSame(
            HandoverAnswerSignalData::class,
            Hilos::getPageSignalDtoRoutes()[SignalTypeConstants::AGENT_SIGNAL][HilosSignalConstants::HILOS_DELIVERY_RETRY_DONE],
        );
        self::assertArrayNotHasKey(HilosPageConstants::HILOS_COMMUNICATIONS, Hilos::PAGES);
        self::assertArrayNotHasKey(HilosPageConstants::HILOS_COMMUNICATIONS_CHANNEL, Hilos::PAGES);
        self::assertArrayNotHasKey(HilosPageConstants::HILOS_PROFILE_NOTIFICATIONS, Hilos::PAGES);
        self::assertArrayNotHasKey(HilosSignalConstants::COMMUNICATIONS_CHANNEL_SET, Hilos::getPageActionRoutes());
    }

    /** Keeps public history pages bound to their index owner and existing text actions. */
    public function testPublicLegalHistoryUsesIndexPagesAndSeparatePublicTables(): void
    {
        self::assertSame(TermsHistoryPage::class, Hilos::PAGES[TermsHistoryPage::PAGE]);
        self::assertSame(PrivacyHistoryPage::class, Hilos::PAGES[PrivacyHistoryPage::PAGE]);
        self::assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[TermsHistoryPage::PAGE]);
        self::assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[PrivacyHistoryPage::PAGE]);
        self::assertSame(HilosTermsHistoryTable::class, Hilos::TABLES[HilosWebsiteTableContext::hilosTermsHistory]);
        self::assertSame(HilosPrivacyHistoryTable::class, Hilos::TABLES[HilosWebsiteTableContext::hilosPrivacyHistory]);
        self::assertSame([HilosWebsiteTableContext::hilosTermsHistory => []], Hilos::PAGE_TABLES[TermsHistoryPage::PAGE]);
        self::assertSame([HilosWebsiteTableContext::hilosPrivacyHistory => []], Hilos::PAGE_TABLES[PrivacyHistoryPage::PAGE]);
        self::assertSame([], TermsHistoryPage::ACTIONS);
        self::assertSame([], PrivacyHistoryPage::ACTIONS);
        self::assertSame('hilos_terms', Hilos::getPageActionRoutes()[HilosSignalConstants::HILOS_TERMS_REVISION_TEXT]);
        self::assertSame('hilos_privacy', Hilos::getPageActionRoutes()[HilosSignalConstants::HILOS_PRIVACY_REVISION_TEXT]);
        self::assertSame(AgentType::HILOS_INDEX, Hilos::getActionAgentRoutes()[HilosSignalConstants::HILOS_TERMS_REVISION_TEXT]);
        self::assertSame(AgentType::HILOS_INDEX, Hilos::getActionAgentRoutes()[HilosSignalConstants::HILOS_PRIVACY_REVISION_TEXT]);
    }

    public function testPublicPrivacyReadUsesTheExistingPageOwner(): void
    {
        $this->assertSame(
            HilosPageConstants::HILOS_PRIVACY,
            Hilos::getPageActionRoutes()[HilosSignalConstants::HILOS_PRIVACY_REVISION_TEXT],
        );
        $this->assertSame(
            AgentType::HILOS_INDEX,
            Hilos::getActionAgentRoutes()[HilosSignalConstants::HILOS_PRIVACY_REVISION_TEXT],
        );
    }

    public function testTimeZoneChoiceRequiresAuthentication(): void
    {
        $agentClass = AgentRegistry::workerClass(Hilos::AGENTS[HilosAgentType::HILOS_USERS_LIBRARY]);
        self::assertNotNull($agentClass);
        self::assertContains(HilosSignalConstants::HILOS_TIME_ZONE_PICK, $agentClass::AUTH_ACTIONS);
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
     * The independent ceiling permits only prescribed DB transitions to retire; startup checks the exact vector.
     */
    public function testSharedOwnershipDebtDoesNotGrow(): void
    {
        $ceiling = [];
        foreach (Hilos::SHARED_DB_OWNERS as $collection => $pending) {
            $this->assertArrayHasKey($collection, $ceiling);
            $this->assertNotEmpty($pending);
            $this->assertSame(array_values(array_intersect($ceiling[$collection], $pending)), $pending);
        }
        Hilos::validateTopology();
        $this->assertSame([], Hilos::SHARED_RT_OWNERS);
    }

    /** Checks the project's complete activation of the framework Analytics section. */
    public function testAnalyticsFeatureIsActivated(): void
    {
        $this->assertContains(HilosFeature::ANALYTICS, Hilos::features());
        $this->assertSame(AnalyticsPage::class, Hilos::PAGES[AnalyticsPage::PAGE]);
        $this->assertSame(AgentType::HILOS_ANALYTICS, AnalyticsPage::SUBSCRIPTION_AGENT_TYPE);
        $this->assertSame(
            HilosAnalyticsActionsTable::class,
            Hilos::TABLES[HilosWebsiteTableContext::hilosAnalyticsActions],
        );
        $this->assertSame(
            HilosAnalyticsBrowserSessionsTable::class,
            Hilos::TABLES[HilosWebsiteTableContext::hilosAnalyticsBrowserSessions],
        );

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

    /** The numeric browser-session page uses the Analytics owner and its existing action table. */
    public function testAnalyticsBrowserSessionPageIsWired(): void
    {
        self::assertSame(BrowserSessionPage::class, Hilos::PAGES[BrowserSessionPage::PAGE]);
        self::assertSame(AgentType::HILOS_ANALYTICS, Hilos::getPageRoutes()[BrowserSessionPage::PAGE]);
        self::assertSame([HilosWebsiteTableContext::hilosAnalyticsActions => []], Hilos::PAGE_TABLES[BrowserSessionPage::PAGE]);
    }

    /** The period sessions list uses the Analytics owner and its own table, beside the person's sessions table. */
    public function testAnalyticsBrowserSessionsPageIsWired(): void
    {
        self::assertSame(BrowserSessionsPage::class, Hilos::PAGES[BrowserSessionsPage::PAGE]);
        self::assertSame(AgentType::HILOS_ANALYTICS, BrowserSessionsPage::SUBSCRIPTION_AGENT_TYPE);
        self::assertSame(AgentType::HILOS_ANALYTICS, Hilos::getPageRoutes()[BrowserSessionsPage::PAGE]);
        self::assertSame(
            HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_ANALYTICS_BROWSER_SESSIONS,
            BrowserSessionsPage::BROWSER[BrowserConfigKey::SIGNAL],
        );
        self::assertSame(
            HilosAnalyticsBrowserSessionListTable::TABLE,
            HilosWebsiteTableContext::hilosAnalyticsBrowserSessionList,
        );
        self::assertSame(
            HilosAnalyticsBrowserSessionListTable::class,
            Hilos::TABLES[HilosWebsiteTableContext::hilosAnalyticsBrowserSessionList],
        );
        self::assertSame(
            [HilosWebsiteTableContext::hilosAnalyticsBrowserSessionList => []],
            Hilos::PAGE_TABLES[BrowserSessionsPage::PAGE],
        );
        self::assertSame(
            [HilosWebsiteTableContext::hilosAnalyticsBrowserSessions => []],
            Hilos::PAGE_TABLES[PersonPage::PAGE],
        );
    }

    /** The People list uses the framework table and the Analytics agent's browser signal. */
    public function testAnalyticsPeoplePageIsWired(): void
    {
        self::assertSame(PeoplePage::class, Hilos::PAGES[PeoplePage::PAGE]);
        self::assertSame(AgentType::HILOS_ANALYTICS, PeoplePage::SUBSCRIPTION_AGENT_TYPE);
        self::assertSame(AgentType::HILOS_ANALYTICS, Hilos::getPageRoutes()[PeoplePage::PAGE]);
        self::assertSame([
            BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_ANALYTICS_PEOPLE,
        ], PeoplePage::BROWSER);
        self::assertSame(HilosAnalyticsPeopleTable::TABLE, HilosWebsiteTableContext::hilosAnalyticsPeople);
        self::assertSame(
            HilosAnalyticsPeopleTable::class,
            Hilos::TABLES[HilosWebsiteTableContext::hilosAnalyticsPeople],
        );
        self::assertSame([
            HilosWebsiteTableContext::hilosAnalyticsPeople => [],
        ], Hilos::PAGE_TABLES[PeoplePage::PAGE]);
    }

    /** The person's sessions page uses the Analytics owner and its existing sessions table. */
    public function testAnalyticsPersonPageIsWired(): void
    {
        self::assertSame(PersonPage::class, Hilos::PAGES[PersonPage::PAGE]);
        self::assertSame(AgentType::HILOS_ANALYTICS, Hilos::getPageRoutes()[PersonPage::PAGE]);
        self::assertSame([HilosWebsiteTableContext::hilosAnalyticsBrowserSessions => []], Hilos::PAGE_TABLES[PersonPage::PAGE]);
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
            HilosWebsiteRtContext::class,
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
        $databaseMetadata = [];
        foreach (['configurations', 'databaseBindings'] as $property) {
            $databaseMetadata[$property] = new ReflectionProperty(Database::class, $property)->getValue();
        }
        try {
            Database::configure(database: 'topology_unit');
            Hilos::$db = new HilosWebsiteDbContext();
            Hilos::$db->configure();
            FrameworkExtensionGuard::assertMountedExtensionsWhole();
        } finally {
            Hilos::$db = $previous;
            foreach ($databaseMetadata as $property => $value) {
                new ReflectionProperty(Database::class, $property)->setValue(null, $value);
            }
        }

        $this->addToAssertionCount(1);
    }
}

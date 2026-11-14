<?php

declare(strict_types=1);

namespace Demo\ConcertTickets\Tests\Unit;

use Demo\ConcertTickets\Pages\Hilos\ProfilePage;
use Demo\ConcertTickets\Pages\Hilos\LicenseDependenciesPage;
use Demo\ConcertTickets\Database\Pages\PageCatalog;
use Hilos\Database\Pages\HilosPageCatalog;
use Hilos\Core\Page\HilosPageFactory;
use Hilos\Core\Page\PageAgentInterface;
use Hilos\Core\Page\PageReach;
use Demo\ConcertTickets\Core\Daemon\ConcertTicketsWorkerManager;
use Demo\ConcertTickets\Pages\Hilos\TermsHistoryPage;
use Demo\ConcertTickets\Pages\Hilos\PrivacyHistoryPage;
use Hilos\Core\Page\PageSignalRouter;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Runtime\State\Item\HilosProfileNameChange;
use Demo\ConcertTickets\Agents\Hilos\DemoHilosAnalyticsAgent;
use Demo\ConcertTickets\Core\Agent\Daemon\Hilos\DemoHilosAnalyticsAgentDaemon;
use Demo\ConcertTickets\Pages\Hilos\AnalyticsPage;
use Demo\ConcertTickets\Pages\Hilos\Analytics\BrowserSessionsPage;
use Demo\ConcertTickets\Pages\Hilos\Analytics\BrowserSessionPage;
use Demo\ConcertTickets\Pages\Hilos\Analytics\PeoplePage;
use Demo\ConcertTickets\Pages\Hilos\Analytics\PersonPage;
use Hilos\Core\Analytics\AnalyticsJournalAgent;
use Hilos\Core\Analytics\AnalyticsJournalAgentDaemon;
use Hilos\Core\Analytics\AnalyticsWriterAgent;
use Hilos\Core\Analytics\AnalyticsWriterAgentDaemon;
use Hilos\Core\Analytics\DTO\AnalyticsJournalAppendSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalLoadedSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalPortionSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalReadSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalReadySignalData;

use Demo\ConcertTickets\Agents\ConcertTicketsAgent;
use Demo\ConcertTickets\Agents\Hilos\DataExportAgent;
use Demo\ConcertTickets\Agents\Hilos\DemoHilosAgent;
use Demo\ConcertTickets\Agents\Hilos\NotificationsLibraryAgent;
use Demo\ConcertTickets\Agents\Hilos\SessionsLibraryAgent;
use Demo\ConcertTickets\Agents\Hilos\UserAgent;
use Demo\ConcertTickets\Agents\Hilos\UsersLibraryAgent;
use Demo\ConcertTickets\Constants\AgentType;
use Demo\ConcertTickets\Constants\PageConstants;
use Demo\ConcertTickets\Core\Agent\Daemon\ConcertTicketsAgentDaemon;
use Demo\ConcertTickets\Core\Agent\Daemon\Hilos\DemoHilosAgentDaemon;
use Demo\ConcertTickets\Core\Agent\Daemon\Hilos\NotificationsLibraryAgentDaemon;
use Demo\ConcertTickets\Core\Agent\Daemon\Hilos\SessionsLibraryAgentDaemon;
use Demo\ConcertTickets\Core\Agent\Daemon\Hilos\UserAgentDaemon;
use Demo\ConcertTickets\Core\Agent\Daemon\Hilos\UsersLibraryAgentDaemon;
use Demo\ConcertTickets\Database\ConcertTicketsDbContext;
use Demo\ConcertTickets\Database\Settings\ConcertTicketsSettingsCatalog;
use Demo\ConcertTickets\Hilos;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Demo\ConcertTickets\Groups\Hilos\NotificationsGroup;
use Demo\ConcertTickets\Pages\Hilos\AboutPage;
use Demo\ConcertTickets\Pages\Hilos\DashboardPage;
use Demo\ConcertTickets\Pages\Hilos\LicensePage;
use Demo\ConcertTickets\Pages\Hilos\Communications\CommunicationsDeliveriesPage;
use Demo\ConcertTickets\Pages\Hilos\PrivacyPage;
use Demo\ConcertTickets\Pages\Hilos\ProfileNotificationsPage;
use Demo\ConcertTickets\Pages\Hilos\SettingsPage;
use Demo\ConcertTickets\Pages\Hilos\AppearancePage;
use Demo\ConcertTickets\Pages\Hilos\TermsPage;
use Demo\ConcertTickets\Pages\Hilos\Users\UserPage;
use Demo\ConcertTickets\Pages\Hilos\Users\UsersPage;
use Demo\ConcertTickets\Pages\MainPage;
use Demo\ConcertTickets\Runtime\View\Context\ConcertTicketsRtContext;
use Demo\ConcertTickets\Tables\HilosUser\HilosUsersTable;
use Demo\ConcertTickets\Tables\ConcertTicketsTableContext;
use Hilos\Auth\Session\DTO\SessionStateSignalData;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Auth\Library\DTO\ThemePickActionDTO;
use Hilos\Auth\Library\DTO\TimeZonePickActionDTO;
use Hilos\Users\DTO\UserThemePickWriteDoneSignalData;
use Hilos\Users\DTO\UserTimeZoneWriteDoneSignalData;
use Hilos\Users\DTO\UserTimeZoneWriteSignalData;
use Hilos\Users\DTO\UserLastBrowserTimeZoneWriteSignalData;
use Hilos\Users\DTO\UserIdentityProviderNameWriteSignalData;
use Hilos\Constants\HttpConstants;
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
use Hilos\Theme\ThemeSettingsCatalog;
use Hilos\Notification\NotificationAction;
use Hilos\Notification\NotificationPreferenceAction;
use Hilos\Push\PushSubscriptionAction;
use Hilos\Tables\Analytics\HilosAnalyticsActionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionListTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsPeopleTable;
use Hilos\Tables\Legal\HilosPrivacyHistoryTable;
use Hilos\Tables\Legal\HilosTermsHistoryTable;
use Hilos\Tables\Settings\HilosSettingsTable;
use Hilos\Tables\Appearance\HilosAppearanceSettingsTable;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Pages\Appearance\DTO\HilosAppearanceDefaultThemeSetActionDTO;
use Hilos\Pages\Appearance\DTO\HilosAppearanceThemeSwitchingSetActionDTO;
use Hilos\Pages\Communications\DTO\HilosDeliveryRetryActionDTO;
use Hilos\Pages\Moderation\DTO\HilosModerationRuleAddActionDTO;
use Hilos\Pages\Moderation\DTO\HilosModerationRuleDeleteActionDTO;
use Hilos\Pages\Moderation\DTO\HilosModerationRuleUpdateActionDTO;
use Hilos\Tables\Communications\HilosNotificationDeliveriesTable;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Notification\Delivery\DeliveryChannelSettings;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Tables\Users\HilosUserDetailBrowserTable;
use Hilos\Pages\Profile\HilosProfileIdentitiesBrowserList;
use Demo\ConcertTickets\Agents\Hilos\DemoHilosChangeLogAgent;
use Demo\ConcertTickets\Agents\Hilos\DemoHilosDaemonAgent;
use Demo\ConcertTickets\Agents\Hilos\DemoHilosLogsAgent;
use Demo\ConcertTickets\Agents\Hilos\FilesLibraryAgent;
use Demo\ConcertTickets\Agents\OAuthAgent;
use Demo\ConcertTickets\Auth\ConcertTicketsAuthMethodDirectory;
use Demo\ConcertTickets\Auth\ConcertTicketsCodeChannelRegistry;
use Demo\ConcertTickets\Auth\ConcertTicketsOAuthProviderDirectory;
use Demo\ConcertTickets\Browser\ConcertTicketsBrowserRef;
use Demo\ConcertTickets\Browser\Data\SelfConnectionBrowserData;
use Demo\ConcertTickets\Browser\List\ProfileSessionsBrowserList;
use Demo\ConcertTickets\Core\Agent\Daemon\Hilos\DemoHilosChangeLogAgentDaemon;
use Demo\ConcertTickets\Core\Agent\Daemon\Hilos\DemoHilosDaemonAgentDaemon;
use Demo\ConcertTickets\Core\Agent\Daemon\Hilos\DemoHilosLogsAgentDaemon;
use Demo\ConcertTickets\Core\Agent\Daemon\Hilos\FilesLibraryAgentDaemon;
use Demo\ConcertTickets\Core\Agent\Daemon\OAuthAgentDaemon;
use Demo\ConcertTickets\Pages\Hilos\ChangeLog\ChangeLogDashboardPage;
use Demo\ConcertTickets\Pages\Hilos\ChangeLog\ChangeLogFeedPage;
use Demo\ConcertTickets\Pages\Hilos\ChangeLog\ChangeLogNoisyPage;
use Demo\ConcertTickets\Pages\Hilos\ChangeLog\ChangeLogTableColumnsPage;
use Demo\ConcertTickets\Pages\Hilos\ChangeLog\ChangeLogTableHistoryPage;
use Demo\ConcertTickets\Pages\Hilos\ChangeLog\ChangeLogTablePage;
use Demo\ConcertTickets\Pages\Hilos\ChangeLog\ChangeLogTablesPage;
use Demo\ConcertTickets\Pages\Hilos\ChangeLog\ChangeLogTableTriggersPage;
use Demo\ConcertTickets\Pages\Hilos\Daemon\DaemonAgentsPage;
use Demo\ConcertTickets\Pages\Hilos\Daemon\DaemonCronPage;
use Demo\ConcertTickets\Pages\Hilos\Daemon\DaemonEnvMismatchPage;
use Demo\ConcertTickets\Pages\Hilos\Daemon\DaemonEnvOrphansPage;
use Demo\ConcertTickets\Pages\Hilos\Daemon\DaemonEnvPage;
use Demo\ConcertTickets\Pages\Hilos\Daemon\DaemonHttpServerPage;
use Demo\ConcertTickets\Pages\Hilos\Daemon\DaemonNodePage;
use Demo\ConcertTickets\Pages\Hilos\Daemon\DaemonPage;
use Demo\ConcertTickets\Pages\Hilos\Daemon\DaemonPlacementPage;
use Demo\ConcertTickets\Pages\Hilos\Daemon\DaemonWebsocketsPage;
use Demo\ConcertTickets\Pages\Hilos\Daemon\DaemonWorkersPage;
use Demo\ConcertTickets\Pages\Hilos\Logs\LogsKeysPage;
use Demo\ConcertTickets\Pages\Hilos\Logs\LogsOverviewPage;
use Demo\ConcertTickets\Pages\Hilos\Logs\LogsRotationsPage;
use Demo\ConcertTickets\Pages\Hilos\Logs\LogsSettingsPage;
use Demo\ConcertTickets\Pages\Hilos\Logs\LogsViewPage;
use Demo\ConcertTickets\Pages\Hilos\Logs\LogsWorkersPage;
use Demo\ConcertTickets\Pages\Hilos\Moderation\ModerationKindPage;
use Demo\ConcertTickets\Pages\Hilos\Moderation\ModerationKindRulesPage;
use Demo\ConcertTickets\Pages\Hilos\Moderation\ModerationPage;
use Demo\ConcertTickets\Pages\Hilos\ProfileAgreementsPage;
use Demo\ConcertTickets\Pages\Hilos\ProfileDataPage;
use Demo\ConcertTickets\Pages\Hilos\ProfileSecurityPage;
use Demo\ConcertTickets\Pages\Hilos\ProfileSessionsPage;
use Demo\ConcertTickets\Pages\Hilos\ProfileSignInPage;
use Hilos\Auth\AuthMethodKey;
use Hilos\Auth\Code\AuthCodeAgent;
use Hilos\Auth\Code\AuthCodeAgentDaemon;
use Hilos\Auth\CodeChannel\SmsCodeChannel;
use Hilos\Auth\OAuth\OAuthProviderPreset;
use Hilos\Core\Agent\Daemon\DaemonCollectorAgentDaemon;
use Hilos\Core\Agent\Daemon\DaemonNodeAgentDaemon;
use Hilos\Core\Agent\Hilos\DaemonCollectorAgent;
use Hilos\Core\Agent\Hilos\DaemonNodeAgent;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserRuntimeParam;
use Hilos\Core\Feature\FeatureRegistry;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Database\Schema\MountedCollectionKeyGuard;
use Hilos\Files\DTO\FileBindSignalData;
use Hilos\Files\DTO\FilePublishSignalData;
use Hilos\Files\DTO\FileRemoveSignalData;
use Hilos\Files\FilesSettingsCatalog;
use Hilos\Files\HilosFiles;
use Hilos\Files\Image\DTO\ImageRenderedSignalData;
use Hilos\Files\Image\DTO\ImageRenderSignalData;
use Hilos\Files\Image\ImageFit;
use Hilos\Files\Image\ImageFormat;
use Hilos\Files\Image\ImagesAgent;
use Hilos\Files\Image\ImagesAgentDaemon;
use Hilos\Files\Image\ImageVariant;
use Hilos\Files\Upload\DTO\UploadCancelActionDTO;
use Hilos\Files\Upload\DTO\UploadHoldSettleSignalData;
use Hilos\Files\Upload\DTO\UploadInitActionDTO;
use Hilos\Files\Upload\DTO\UploadPublishSignalData;
use Hilos\Files\Upload\ProfilePhotoUploadTarget;
use Hilos\Files\Upload\UploadsAgent;
use Hilos\Files\Upload\UploadsAgentDaemon;
use Hilos\Files\Upload\UploadsSettingsCatalog;
use Hilos\Log\LogSettingsCatalog;
use Hilos\Moderation\Library\ModerationRulesLibraryAgent;
use Hilos\Moderation\Library\ModerationRulesLibraryAgentDaemon;
use Hilos\Moderation\ModerationAgent;
use Hilos\Moderation\ModerationAgentDaemon;
use Hilos\Moderation\ModerationInput;
use Hilos\Moderation\ModerationKindDirectory;
use Hilos\Moderation\ModerationKindKey;
use Hilos\Moderation\ModerationSettingsCatalog;
use Hilos\Runtime\View\Collection\HilosModerationChecks;
use Hilos\Sms\Delivery\SmsRawSendAgent;
use Hilos\Tables\ChangeLog\HilosChangeLogFeedTable;
use Hilos\Tables\ChangeLog\HilosChangeLogHistoryTable;
use Hilos\Tables\ChangeLog\HilosChangeLogNoisyColumnsTable;
use Hilos\Tables\ChangeLog\HilosChangeLogTableColumnsTable;
use Hilos\Tables\ChangeLog\HilosChangeLogTablesTable;
use Hilos\Tables\ChangeLog\HilosChangeLogTableTriggersTable;
use Hilos\Tables\Daemon\HilosDaemonAgentsTable;
use Hilos\Tables\Daemon\HilosDaemonCronTable;
use Hilos\Tables\Daemon\HilosDaemonEnvKeysTable;
use Hilos\Tables\Daemon\HilosDaemonEnvMismatchTable;
use Hilos\Tables\Daemon\HilosDaemonEnvOrphansTable;
use Hilos\Tables\Daemon\HilosDaemonHttpRoutesTable;
use Hilos\Tables\Daemon\HilosDaemonPlacementTable;
use Hilos\Tables\Daemon\HilosDaemonWorkersTable;
use Hilos\Tables\Logs\HilosLogKeysTable;
use Hilos\Tables\Logs\HilosLogNodesTable;
use Hilos\Tables\Logs\HilosLogRotationsTable;
use Hilos\Tables\Logs\HilosLogWorkersTable;
use Hilos\Tables\Moderation\HilosModerationKindsTable;
use Hilos\Tables\Moderation\HilosModerationRulesTable;
use Hilos\Tables\Users\HilosUserPhotoBrowserTable;
use PHPUnit\Framework\TestCase;
use Hilos\Core\Agent\TopologyAgentFactory;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Guards the project-level concert-tickets topology registry.
 *
 * Guards the home, admin sections, notifications, sign-in registry.
 * Snapshots intentionally name every page and agent; a later activation must update them.
 */
final class ConcertTicketsTopologyRegistryTest extends TestCase
{
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

    /** Every served profile section binds the common personal identity source once. */
    public function testEveryProfileSectionBindsTheIdentityList(): void
    {
        self::assertSame(ProfilePage::class, Hilos::PAGES[ProfilePage::PAGE]);
        self::assertSame(AgentType::CONCERT_TICKETS, ProfilePage::SUBSCRIPTION_AGENT_TYPE);
        self::assertSame(AgentType::CONCERT_TICKETS, Hilos::getPageRoutes()[ProfilePage::PAGE]);
        self::assertSame(
            HilosProfileIdentitiesBrowserList::class,
            Hilos::BROWSER_LISTS[HilosProfileIdentitiesBrowserList::LIST],
        );
        foreach ([
            ProfilePage::class,
            ProfileNotificationsPage::class,
            ProfileSignInPage::class,
            ProfileSessionsPage::class,
            ProfileSecurityPage::class,
            ProfileAgreementsPage::class,
            ProfileDataPage::class,
        ] as $page) {
            self::assertSame([HilosProfileIdentitiesBrowserList::class], $page::REQUIRED_LISTS);
            self::assertSame(
                HilosProfileIdentitiesBrowserList::BINDING,
                Hilos::PAGE_LISTS[$page::PAGE][HilosProfileIdentitiesBrowserList::LIST],
            );
        }
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
        $manager = new ReflectionClass(ConcertTicketsWorkerManager::class)->newInstanceWithoutConstructor();
        $factory = new ReflectionMethod(ConcertTicketsWorkerManager::class, 'createPageSignalRouter');
        $name = new ReflectionProperty(PageSignalRouter::class, 'throttleVerdictSignal');
        $users = $factory->invoke($manager, TopologyAgentFactory::createWorker(Hilos::class, HilosAgentType::HILOS_USERS_LIBRARY, null));
        self::assertInstanceOf(PageSignalRouter::class, $users);
        self::assertSame(HilosSignalConstants::HILOS_AUTH_THROTTLE_VERDICT, $name->getValue($users));
    }

    /** The archive address is answered by the export owner, a published file by the files library. */
    public function testDataExportHttpRoute(): void
    {
        self::assertSame([
            HttpConstants::METHOD_GET => [
                DataExportHttp::DOWNLOAD_PATH => HilosAgentType::HILOS_DATA_EXPORT,
                HilosFiles::DOWNLOAD_PATH => HilosAgentType::HILOS_FILES_LIBRARY,
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

    public function testPageRegistryIncludesTheAdminSections(): void
    {
        $this->assertSame([
            MainPage::PAGE => MainPage::class,
            DashboardPage::PAGE => DashboardPage::class,
            AnalyticsPage::PAGE => AnalyticsPage::class,
            BrowserSessionsPage::PAGE => BrowserSessionsPage::class,
            BrowserSessionPage::PAGE => BrowserSessionPage::class,
            PeoplePage::PAGE => PeoplePage::class,
            PersonPage::PAGE => PersonPage::class,
            SettingsPage::PAGE => SettingsPage::class,
            AppearancePage::PAGE => AppearancePage::class,
            ChangeLogDashboardPage::PAGE => ChangeLogDashboardPage::class,
            ChangeLogFeedPage::PAGE => ChangeLogFeedPage::class,
            ChangeLogNoisyPage::PAGE => ChangeLogNoisyPage::class,
            ChangeLogTablesPage::PAGE => ChangeLogTablesPage::class,
            ChangeLogTablePage::PAGE => ChangeLogTablePage::class,
            ChangeLogTableColumnsPage::PAGE => ChangeLogTableColumnsPage::class,
            ChangeLogTableTriggersPage::PAGE => ChangeLogTableTriggersPage::class,
            ChangeLogTableHistoryPage::PAGE => ChangeLogTableHistoryPage::class,
            DaemonPage::PAGE => DaemonPage::class,
            DaemonPlacementPage::PAGE => DaemonPlacementPage::class,
            DaemonNodePage::PAGE => DaemonNodePage::class,
            DaemonWorkersPage::PAGE => DaemonWorkersPage::class,
            DaemonAgentsPage::PAGE => DaemonAgentsPage::class,
            DaemonCronPage::PAGE => DaemonCronPage::class,
            DaemonWebsocketsPage::PAGE => DaemonWebsocketsPage::class,
            DaemonHttpServerPage::PAGE => DaemonHttpServerPage::class,
            DaemonEnvPage::PAGE => DaemonEnvPage::class,
            DaemonEnvOrphansPage::PAGE => DaemonEnvOrphansPage::class,
            DaemonEnvMismatchPage::PAGE => DaemonEnvMismatchPage::class,
            LogsOverviewPage::PAGE => LogsOverviewPage::class,
            LogsKeysPage::PAGE => LogsKeysPage::class,
            LogsWorkersPage::PAGE => LogsWorkersPage::class,
            LogsRotationsPage::PAGE => LogsRotationsPage::class,
            LogsViewPage::PAGE => LogsViewPage::class,
            LogsSettingsPage::PAGE => LogsSettingsPage::class,
            ModerationPage::PAGE => ModerationPage::class,
            ModerationKindPage::PAGE => ModerationKindPage::class,
            ModerationKindRulesPage::PAGE => ModerationKindRulesPage::class,
            CommunicationsDeliveriesPage::PAGE => CommunicationsDeliveriesPage::class,
            ProfileNotificationsPage::PAGE => ProfileNotificationsPage::class,
            ProfilePage::PAGE => ProfilePage::class,
            ProfileSignInPage::PAGE => ProfileSignInPage::class,
            ProfileSessionsPage::PAGE => ProfileSessionsPage::class,
            ProfileSecurityPage::PAGE => ProfileSecurityPage::class,
            ProfileAgreementsPage::PAGE => ProfileAgreementsPage::class,
            ProfileDataPage::PAGE => ProfileDataPage::class,
            UsersPage::PAGE => UsersPage::class,
            UserPage::PAGE => UserPage::class,
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
            AgentType::CONCERT_TICKETS,
            AgentType::HILOS_INDEX,
            AgentType::HILOS_ANALYTICS,
            HilosAgentType::HILOS_ANALYTICS_JOURNAL,
            HilosAgentType::HILOS_ANALYTICS_WRITER,
            HilosAgentType::HILOS_DATA_EXPORT,
            HilosAgentType::HILOS_SESSIONS_LIBRARY,
            AgentType::HILOS_NOTIFICATIONS_LIBRARY,
            HilosAgentType::HILOS_SETTINGS_LIBRARY,
            HilosAgentType::HILOS_USER,
            HilosAgentType::HILOS_USERS_LIBRARY,
            HilosAgentType::HILOS_MAIL,
            HilosAgentType::HILOS_MODERATOR,
            HilosAgentType::HILOS_MODERATION_RULES_LIBRARY,
            HilosAgentType::HILOS_FILES_LIBRARY,
            HilosAgentType::HILOS_UPLOADS,
            HilosAgentType::HILOS_IMAGES,
            AgentType::HILOS_CHANGE_LOG,
            AgentType::HILOS_DAEMON,
            AgentType::HILOS_LOGS,
            HilosAgentType::HILOS_OAUTH,
            HilosAgentType::HILOS_SMS,
            HilosAgentType::HILOS_LOG_STORE,
            HilosAgentType::HILOS_LOG_CARRIER,
            HilosAgentType::HILOS_LOG_AGGREGATOR,
            HilosAgentType::HILOS_DAEMON_NODE,
            HilosAgentType::HILOS_DAEMON_COLLECTOR,
            HilosAgentType::HILOS_AUTH_CODE,
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
        $this->assertSame(AgentType::CONCERT_TICKETS, MainPage::SUBSCRIPTION_AGENT_TYPE);
        $entry = Hilos::AGENTS[AgentType::CONCERT_TICKETS];
        $this->assertSame(ConcertTicketsAgent::class, AgentRegistry::workerClass($entry));
        $this->assertSame(ConcertTicketsAgentDaemon::class, AgentRegistry::daemonClass($entry));
        $this->assertFalse(AgentRegistry::requiresIndex($entry));
        $this->assertTrue((new ConcertTicketsAgentDaemon())->requiresMonopolisticProcess());
    }

    public function testHilosPagesAreOwnedByTheIndexAgent(): void
    {
        $hilosPages = [
            DashboardPage::class,
            SettingsPage::class,
            CommunicationsDeliveriesPage::class,
            UsersPage::class,
            UserPage::class,
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
            ConcertTicketsAgent::AGENT_SIGNALS,
        );
        $this->assertSame(
            [
                HilosSignalConstants::HILOS_SESSION_STATE => AgentType::CONCERT_TICKETS,
                HilosSignalConstants::MAINTENANCE_AGENT_ENABLE => HilosAgentType::HILOS_INDEX,
                HilosSignalConstants::MAINTENANCE_AGENT_DISABLE => HilosAgentType::HILOS_INDEX,
                HilosSignalConstants::MAINTENANCE_AGENT_PASS => HilosAgentType::HILOS_INDEX,
                HilosSignalConstants::ANALYTICS_JOURNAL_APPEND => HilosAgentType::HILOS_ANALYTICS_JOURNAL,
                HilosSignalConstants::ANALYTICS_JOURNAL_READ => HilosAgentType::HILOS_ANALYTICS_JOURNAL,
                HilosSignalConstants::ANALYTICS_JOURNAL_LOADED => HilosAgentType::HILOS_ANALYTICS_JOURNAL,
                HilosSignalConstants::ANALYTICS_JOURNAL_PORTION => HilosAgentType::HILOS_ANALYTICS_WRITER,
                HilosSignalConstants::ANALYTICS_JOURNAL_READY => HilosAgentType::HILOS_ANALYTICS_WRITER,
                HilosSignalConstants::HILOS_DATA_EXPORT_ACCOUNT_OPERATION_STEP => HilosAgentType::HILOS_DATA_EXPORT,
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
                HilosSignalConstants::HILOS_NOTIFICATION_EMIT => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
                HilosSignalConstants::HILOS_DELIVERY_RETRY => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
                HilosSignalConstants::HILOS_NOTIFICATION_HANDOVER => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
                HilosSignalConstants::HILOS_USER_NOTIFICATION_WRITE_DONE => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
                HilosSignalConstants::HILOS_USER_PUSH_RELEASE_DONE => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
                HilosSignalConstants::HILOS_SETTING_WRITE => HilosAgentType::HILOS_SETTINGS_LIBRARY,
                HilosSignalConstants::HILOS_SETTING_RESET => HilosAgentType::HILOS_SETTINGS_LIBRARY,
                HilosSignalConstants::HILOS_SETTING_DELETE => HilosAgentType::HILOS_SETTINGS_LIBRARY,
                HilosSignalConstants::HILOS_SETTING_PRESET_APPLY => HilosAgentType::HILOS_SETTINGS_LIBRARY,
                HilosSignalConstants::HILOS_SETTING_COMMAND_DONE => HilosAgentType::HILOS_SETTINGS_LIBRARY,
                // Sign-in's own frames (HIL-623): the users library waits for the throttle
                // verdict, and the mail agent and the node-scoped throttle answer on their own
                // names.
                HilosSignalConstants::HILOS_USER_ACCOUNT_OPERATION_STEP => HilosAgentType::HILOS_USER,
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
                HilosSignalConstants::HILOS_USER_PASSWORD_CHANGE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_EMAIL_CHANGE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_IDENTITY_UNLINK => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_CONFIRM => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_REMOVE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_CANCEL => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_WAIT_WRITE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_DROP => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_CODES_DROP => HilosAgentType::HILOS_USER,
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
                HilosSignalConstants::HILOS_USER_PASSWORD_CHANGE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_EMAIL_CHANGE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_IDENTITY_UNLINK_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_PROVE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_CONFIRM_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_REMOVE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_RESET_CANCEL_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_WAIT_WRITE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_ENROLL_DROP_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_SECOND_FACTOR_CODES_DROP_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
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
                HilosSignalConstants::MODERATION_RULE_ADD => HilosAgentType::HILOS_MODERATION_RULES_LIBRARY,
                HilosSignalConstants::MODERATION_RULE_DELETE => HilosAgentType::HILOS_MODERATION_RULES_LIBRARY,
                HilosSignalConstants::MODERATION_RULE_UPDATE => HilosAgentType::HILOS_MODERATION_RULES_LIBRARY,
                HilosSignalConstants::HILOS_FILE_BIND => HilosAgentType::HILOS_FILES_LIBRARY,
                HilosSignalConstants::HILOS_FILE_REMOVE => HilosAgentType::HILOS_FILES_LIBRARY,
                HilosSignalConstants::HILOS_FILE_PUBLISH => HilosAgentType::HILOS_FILES_LIBRARY,
                HilosSignalConstants::HILOS_IMAGE_RENDERED => HilosAgentType::HILOS_FILES_LIBRARY,
                HilosSignalConstants::HILOS_UPLOAD_PUBLISH => HilosAgentType::HILOS_UPLOADS,
                HilosSignalConstants::HILOS_UPLOAD_HOLD_SETTLE => HilosAgentType::HILOS_UPLOADS,
                HilosSignalConstants::HILOS_IMAGE_RENDER => HilosAgentType::HILOS_IMAGES,
                HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION => HilosAgentType::HILOS_DAEMON,
                HilosSignalConstants::LOGS_CLUSTER_INDEX_PORTION => HilosAgentType::HILOS_LOGS,
                HilosSignalConstants::HILOS_OAUTH_PENDING => HilosAgentType::HILOS_OAUTH,
                HilosSignalConstants::HILOS_SMS_SEND => HilosAgentType::HILOS_SMS,
                HilosSignalConstants::LOGS_AGENT_READ_LINES => HilosAgentType::HILOS_LOG_STORE,
                HilosSignalConstants::LOGS_AGENT_FOLLOW_START => HilosAgentType::HILOS_LOG_STORE,
                HilosSignalConstants::LOGS_AGENT_FOLLOW_STOP => HilosAgentType::HILOS_LOG_STORE,
                HilosSignalConstants::LOGS_AGENT_TAKEOUT_CONFIRM => HilosAgentType::HILOS_LOG_STORE,
                HilosSignalConstants::LOGS_AGENT_TAKEOUT_UNDO => HilosAgentType::HILOS_LOG_STORE,
                HilosSignalConstants::LOGS_NODE_INDEX_REPORT => HilosAgentType::HILOS_LOG_AGGREGATOR,
                HilosSignalConstants::LOGS_INDEX_WATCH => HilosAgentType::HILOS_LOG_AGGREGATOR,
                HilosSignalConstants::DAEMON_MASTER_PROCESS_ROSTER => HilosAgentType::HILOS_DAEMON_NODE,
                HilosSignalConstants::DAEMON_MASTER_CRON => HilosAgentType::HILOS_DAEMON_NODE,
                HilosSignalConstants::DAEMON_MASTER_STANDING => HilosAgentType::HILOS_DAEMON_NODE,
                HilosSignalConstants::DAEMON_MASTER_HTTP => HilosAgentType::HILOS_DAEMON_NODE,
                HilosSignalConstants::DAEMON_MASTER_MEASUREMENTS => HilosAgentType::HILOS_DAEMON_NODE,
                HilosSignalConstants::DAEMON_AGENT_CRON => HilosAgentType::HILOS_DAEMON_NODE,
                HilosSignalConstants::DAEMON_MASTER_REPORT_NOW => HilosAgentType::HILOS_DAEMON_NODE,
                HilosSignalConstants::DAEMON_NODE_PICTURE_REPORT => HilosAgentType::HILOS_DAEMON_COLLECTOR,
                HilosSignalConstants::DAEMON_PICTURE_WATCH => HilosAgentType::HILOS_DAEMON_COLLECTOR,
                HilosSignalConstants::STATUS_DAEMON_PICTURE_WATCH => HilosAgentType::HILOS_DAEMON_COLLECTOR,
                HilosSignalConstants::HILOS_AUTH_CODE_SEND => HilosAgentType::HILOS_AUTH_CODE,
                HilosSignalConstants::HILOS_AUTH_CODE_MINTED => HilosAgentType::HILOS_AUTH_CODE,
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
            HilosFeature::AUTH,
            HilosFeature::SETTINGS,
            HilosFeature::ANALYTICS,
            HilosFeature::HILOS_USERS,
            HilosFeature::NOTIFICATIONS,
            HilosFeature::NOTIFICATION_DELIVERY,
            HilosFeature::CODE_CHANNELS,
            HilosFeature::FILES,
            HilosFeature::UPLOADS,
            HilosFeature::IMAGES,
            HilosFeature::PROFILE_PHOTO,
            HilosFeature::MODERATION,
            HilosFeature::DAEMON,
            HilosFeature::LOGS,
            HilosFeature::CHANGE_LOG,
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
            HilosSignalConstants::HILOS_UPLOAD_INIT => HilosAgentType::HILOS_UPLOADS,
            HilosSignalConstants::HILOS_UPLOAD_CANCEL => HilosAgentType::HILOS_UPLOADS,
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

    public function testSettingsAndUsersAdminFeaturesAreActivated(): void
    {
        $this->assertSame([
            ConcertTicketsTableContext::settings => HilosSettingsTable::class,
            ConcertTicketsTableContext::hilosAppearanceSettings => HilosAppearanceSettingsTable::class,
            ConcertTicketsTableContext::hilosUsers => HilosUsersTable::class,
            ConcertTicketsTableContext::hilosNotificationDeliveries => HilosNotificationDeliveriesTable::class,
            ConcertTicketsTableContext::hilosTermsHistory => HilosTermsHistoryTable::class,
            ConcertTicketsTableContext::hilosPrivacyHistory => HilosPrivacyHistoryTable::class,
            ConcertTicketsTableContext::hilosAnalyticsActions => HilosAnalyticsActionsTable::class,
            ConcertTicketsTableContext::hilosAnalyticsBrowserSessionList => HilosAnalyticsBrowserSessionListTable::class,
            ConcertTicketsTableContext::hilosAnalyticsBrowserSessions => HilosAnalyticsBrowserSessionsTable::class,
            ConcertTicketsTableContext::hilosAnalyticsPeople => HilosAnalyticsPeopleTable::class,
            ConcertTicketsTableContext::hilosChangeLogFeed => HilosChangeLogFeedTable::class,
            ConcertTicketsTableContext::hilosChangeLogHistory => HilosChangeLogHistoryTable::class,
            ConcertTicketsTableContext::hilosChangeLogTableColumns => HilosChangeLogTableColumnsTable::class,
            ConcertTicketsTableContext::hilosChangeLogTableTriggers => HilosChangeLogTableTriggersTable::class,
            ConcertTicketsTableContext::hilosChangeLogNoisyColumns => HilosChangeLogNoisyColumnsTable::class,
            ConcertTicketsTableContext::hilosChangeLogTables => HilosChangeLogTablesTable::class,
            ConcertTicketsTableContext::hilosLogKeys => HilosLogKeysTable::class,
            ConcertTicketsTableContext::hilosLogNodes => HilosLogNodesTable::class,
            ConcertTicketsTableContext::hilosLogRotations => HilosLogRotationsTable::class,
            ConcertTicketsTableContext::hilosLogWorkers => HilosLogWorkersTable::class,
            ConcertTicketsTableContext::hilosModerationKinds => HilosModerationKindsTable::class,
            ConcertTicketsTableContext::hilosModerationRules => HilosModerationRulesTable::class,
            ConcertTicketsTableContext::hilosDaemonCron => HilosDaemonCronTable::class,
            ConcertTicketsTableContext::hilosDaemonWorkers => HilosDaemonWorkersTable::class,
            ConcertTicketsTableContext::hilosDaemonEnvKeys => HilosDaemonEnvKeysTable::class,
            ConcertTicketsTableContext::hilosDaemonEnvOrphans => HilosDaemonEnvOrphansTable::class,
            ConcertTicketsTableContext::hilosDaemonAgents => HilosDaemonAgentsTable::class,
            ConcertTicketsTableContext::hilosDaemonEnvMismatch => HilosDaemonEnvMismatchTable::class,
            ConcertTicketsTableContext::hilosDaemonHttpRoutes => HilosDaemonHttpRoutesTable::class,
            ConcertTicketsTableContext::hilosDaemonPlacement => HilosDaemonPlacementTable::class,
        ], Hilos::TABLES);
        $this->assertSame([
            HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::class,
            HilosUserPhotoBrowserTable::TABLE => HilosUserPhotoBrowserTable::class,
        ], Hilos::BROWSER_TABLES);
        $this->assertSame([
            BrowserSessionsPage::PAGE,
            BrowserSessionPage::PAGE,
            PeoplePage::PAGE,
            PersonPage::PAGE,
            SettingsPage::PAGE,
            AppearancePage::PAGE,
            CommunicationsDeliveriesPage::PAGE,
            UsersPage::PAGE,
            UserPage::PAGE,
            ChangeLogFeedPage::PAGE,
            ChangeLogTablesPage::PAGE,
            ChangeLogNoisyPage::PAGE,
            ChangeLogTableHistoryPage::PAGE,
            ChangeLogTableColumnsPage::PAGE,
            ChangeLogTableTriggersPage::PAGE,
            LogsOverviewPage::PAGE,
            LogsKeysPage::PAGE,
            LogsRotationsPage::PAGE,
            LogsWorkersPage::PAGE,
            ModerationPage::PAGE,
            ModerationKindPage::PAGE,
            ModerationKindRulesPage::PAGE,
            DaemonCronPage::PAGE,
            DaemonWorkersPage::PAGE,
            DaemonEnvPage::PAGE,
            DaemonEnvOrphansPage::PAGE,
            DaemonPlacementPage::PAGE,
            DaemonAgentsPage::PAGE,
            DaemonEnvMismatchPage::PAGE,
            DaemonHttpServerPage::PAGE,
            TermsHistoryPage::PAGE,
            PrivacyHistoryPage::PAGE,
        ], array_keys(Hilos::PAGE_TABLES));
        $this->assertSame([ConcertTicketsTableContext::settings => []], Hilos::PAGE_TABLES[SettingsPage::PAGE]);
        $this->assertSame([ConcertTicketsTableContext::hilosUsers => []], Hilos::PAGE_TABLES[UsersPage::PAGE]);
        $this->assertSame([
            HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::BINDING,
            HilosUserPhotoBrowserTable::TABLE => HilosUserPhotoBrowserTable::BINDING,
        ], Hilos::PAGE_TABLES[UserPage::PAGE]);
        $this->assertSame([ConcertTicketsTableContext::hilosTermsHistory => []], Hilos::PAGE_TABLES[TermsHistoryPage::PAGE]);
        $this->assertSame([ConcertTicketsTableContext::hilosPrivacyHistory => []], Hilos::PAGE_TABLES[PrivacyHistoryPage::PAGE]);
        $this->assertSame(ConcertTicketsDbContext::users, UserPage::READS_DB[0]);

        $settingsLibrary = Hilos::AGENTS[HilosAgentType::HILOS_SETTINGS_LIBRARY];
        $this->assertSame(SettingsLibraryAgent::class, AgentRegistry::workerClass($settingsLibrary));
        $this->assertSame(SettingsLibraryAgentDaemon::class, AgentRegistry::daemonClass($settingsLibrary));
        $this->assertSame(AgentPlacement::POLICY, AgentRegistry::placement($settingsLibrary));
        $catalog = ConcertTicketsSettingsCatalog::getCatalog();
        foreach ([
            SettingsCatalogConstants::STUB_KEY_EXAMPLE_STRING,
            SettingsCatalogConstants::STUB_KEY_EXAMPLE_INTEGER,
            SettingsCatalogConstants::STUB_KEY_EXAMPLE_BOOLEAN,
        ] as $key) {
            $this->assertArrayHasKey($key, $catalog);
        }
        foreach (ThemeSettingsCatalog::KEYS as $key) {
            $this->assertSame(ThemeSettingsCatalog::getCatalog()[$key], $catalog[$key]);
        }
        $this->assertSame(UserPage::PAGE, Hilos::getPageActionRoutes()[HilosSignalConstants::HILOS_USER_UPDATE]);
    }

    /** Appearance reuses the index agent; its actions, answer and admin level come from the framework page. */
    public function testAppearanceAdminFeatureIsActivated(): void
    {
        $this->assertSame(AppearancePage::class, Hilos::PAGES[AppearancePage::PAGE]);
        $this->assertSame(AgentType::HILOS_INDEX, AppearancePage::SUBSCRIPTION_AGENT_TYPE);
        $this->assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[AppearancePage::PAGE]);
        $this->assertSame(PageAccessLevel::ADMIN, AppearancePage::ACCESS_LEVEL);
        $this->assertSame(
            HilosPageConstants::HILOS_APPEARANCE,
            Hilos::getPageActionRoutes()[HilosSignalConstants::APPEARANCE_THEME_SWITCHING_SET],
        );
        $this->assertSame(
            HilosPageConstants::HILOS_APPEARANCE,
            Hilos::getPageActionRoutes()[HilosSignalConstants::APPEARANCE_DEFAULT_THEME_SET],
        );
        $this->assertSame(
            AgentType::HILOS_INDEX,
            Hilos::getActionAgentRoutes()[HilosSignalConstants::APPEARANCE_THEME_SWITCHING_SET],
        );
        $this->assertSame(
            AgentType::HILOS_INDEX,
            Hilos::getActionAgentRoutes()[HilosSignalConstants::APPEARANCE_DEFAULT_THEME_SET],
        );
        $this->assertSame(
            HilosAppearanceThemeSwitchingSetActionDTO::class,
            Hilos::getActionDtoRoutes()[HilosSignalConstants::APPEARANCE_THEME_SWITCHING_SET],
        );
        $this->assertSame(
            HilosAppearanceDefaultThemeSetActionDTO::class,
            Hilos::getActionDtoRoutes()[HilosSignalConstants::APPEARANCE_DEFAULT_THEME_SET],
        );
        $this->assertSame(
            HilosPageConstants::HILOS_APPEARANCE,
            Hilos::getPageSignalRoutes()[SignalTypeConstants::AGENT_SIGNAL]
                [HilosSignalConstants::HILOS_APPEARANCE_SETTING_WRITE_DONE],
        );
        $this->assertSame(
            HandoverAnswerSignalData::class,
            Hilos::getPageSignalDtoRoutes()[SignalTypeConstants::AGENT_SIGNAL]
                [HilosSignalConstants::HILOS_APPEARANCE_SETTING_WRITE_DONE],
        );
        $this->assertSame(
            AgentType::HILOS_INDEX,
            Hilos::getPageSignalAgentRoutes()[SignalTypeConstants::AGENT_SIGNAL]
                [HilosSignalConstants::HILOS_APPEARANCE_SETTING_WRITE_DONE],
        );
        $this->assertSame(
            HilosAppearanceSettingsTable::class,
            Hilos::TABLES[ConcertTicketsTableContext::hilosAppearanceSettings],
        );
        $this->assertSame(
            [ConcertTicketsTableContext::hilosAppearanceSettings => []],
            Hilos::PAGE_TABLES[AppearancePage::PAGE],
        );
    }

    public function testNotificationsAreActivated(): void
    {
        $this->assertContains(HilosFeature::NOTIFICATIONS, Hilos::features());
        $this->assertContains(HilosFeature::NOTIFICATION_DELIVERY, Hilos::features());
        $this->assertSame([NotificationsGroup::GROUP => NotificationsGroup::class], Hilos::GROUPS);
        $library = Hilos::AGENTS[AgentType::HILOS_NOTIFICATIONS_LIBRARY];
        $this->assertSame(NotificationsLibraryAgent::class, AgentRegistry::workerClass($library));
        $this->assertSame(NotificationsLibraryAgentDaemon::class, AgentRegistry::daemonClass($library));
        $this->assertSame(AgentPlacement::POLICY, AgentRegistry::placement($library));
    }

    /** Mail delivery retains the required server page without the optional channel administration. */
    public function testNotificationDeliveriesAreBoundToTheIndexAgent(): void
    {
        self::assertSame(CommunicationsDeliveriesPage::class, Hilos::PAGES[CommunicationsDeliveriesPage::PAGE]);
        self::assertSame(AgentType::HILOS_INDEX, CommunicationsDeliveriesPage::SUBSCRIPTION_AGENT_TYPE);
        self::assertSame(
            [ConcertTicketsTableContext::hilosNotificationDeliveries => []],
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
        self::assertArrayNotHasKey(HilosSignalConstants::COMMUNICATIONS_CHANNEL_SET, Hilos::getPageActionRoutes());
        $key = DeliveryChannelSettings::enabledKey(MailDeliveryChannel::NAME);
        self::assertTrue(ConcertTicketsSettingsCatalog::getCatalog()[$key][SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE]);
    }

    /** Keeps public history pages bound to their index owner and existing text actions. */
    public function testPublicLegalHistoryUsesIndexPagesAndSeparatePublicTables(): void
    {
        self::assertSame(TermsHistoryPage::class, Hilos::PAGES[TermsHistoryPage::PAGE]);
        self::assertSame(PrivacyHistoryPage::class, Hilos::PAGES[PrivacyHistoryPage::PAGE]);
        self::assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[TermsHistoryPage::PAGE]);
        self::assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[PrivacyHistoryPage::PAGE]);
        self::assertSame(HilosTermsHistoryTable::class, Hilos::TABLES[ConcertTicketsTableContext::hilosTermsHistory]);
        self::assertSame(HilosPrivacyHistoryTable::class, Hilos::TABLES[ConcertTicketsTableContext::hilosPrivacyHistory]);
        self::assertSame([ConcertTicketsTableContext::hilosTermsHistory => []], Hilos::PAGE_TABLES[TermsHistoryPage::PAGE]);
        self::assertSame([ConcertTicketsTableContext::hilosPrivacyHistory => []], Hilos::PAGE_TABLES[PrivacyHistoryPage::PAGE]);
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
        $ceiling = [
            'accountDeletions' => ['HIL-1952'],
            'dataExports' => ['HIL-1952'],
            'identities' => ['HIL-1952', 'HIL-1999'],
            'legalAcceptances' => ['HIL-1952'],
            'notificationDeliveries' => ['HIL-1952'],
            'notificationPreferences' => ['HIL-1952'],
            'notifications' => ['HIL-1952'],
            'passkeyCredentials' => ['HIL-1952', 'HIL-1999'],
            'pushSubscriptions' => ['HIL-1952'],
            'secondFactorBackupCodes' => ['HIL-1952', 'HIL-1999'],
            'secondFactorResets' => ['HIL-1952', 'HIL-1999'],
            'secondFactorSettings' => ['HIL-1952', 'HIL-1999'],
            'secondFactorTrusts' => ['HIL-1952', 'HIL-1999'],
            'secondFactors' => ['HIL-1952', 'HIL-1999'],
            'stepUps' => ['HIL-1952'],
            'userPhotos' => ['HIL-1952'],
            'userRenames' => ['HIL-1952'],
            'users' => ['HIL-1952', 'HIL-1999'],
            'verifications' => ['HIL-1952'],
        ];
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
            Hilos::TABLES[ConcertTicketsTableContext::hilosAnalyticsActions],
        );
        $this->assertSame(
            HilosAnalyticsBrowserSessionsTable::class,
            Hilos::TABLES[ConcertTicketsTableContext::hilosAnalyticsBrowserSessions],
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
        self::assertSame([ConcertTicketsTableContext::hilosAnalyticsActions => []], Hilos::PAGE_TABLES[BrowserSessionPage::PAGE]);
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
            ConcertTicketsTableContext::hilosAnalyticsBrowserSessionList,
        );
        self::assertSame(
            HilosAnalyticsBrowserSessionListTable::class,
            Hilos::TABLES[ConcertTicketsTableContext::hilosAnalyticsBrowserSessionList],
        );
        self::assertSame(
            [ConcertTicketsTableContext::hilosAnalyticsBrowserSessionList => []],
            Hilos::PAGE_TABLES[BrowserSessionsPage::PAGE],
        );
        self::assertSame(
            [ConcertTicketsTableContext::hilosAnalyticsBrowserSessions => []],
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
        self::assertSame(HilosAnalyticsPeopleTable::TABLE, ConcertTicketsTableContext::hilosAnalyticsPeople);
        self::assertSame(
            HilosAnalyticsPeopleTable::class,
            Hilos::TABLES[ConcertTicketsTableContext::hilosAnalyticsPeople],
        );
        self::assertSame([
            ConcertTicketsTableContext::hilosAnalyticsPeople => [],
        ], Hilos::PAGE_TABLES[PeoplePage::PAGE]);
    }

    /** The person's sessions page uses the Analytics owner and its existing sessions table. */
    public function testAnalyticsPersonPageIsWired(): void
    {
        self::assertSame(PersonPage::class, Hilos::PAGES[PersonPage::PAGE]);
        self::assertSame(AgentType::HILOS_ANALYTICS, Hilos::getPageRoutes()[PersonPage::PAGE]);
        self::assertSame([ConcertTicketsTableContext::hilosAnalyticsBrowserSessions => []], Hilos::PAGE_TABLES[PersonPage::PAGE]);
    }

    /** Moderation runs its rules library and its moderator, and the section's three pages and two tables are mounted. */
    public function testModerationIsOnWithItsRulesLibraryAndItsModerator(): void
    {
        $this->assertContains(HilosFeature::MODERATION, Hilos::featuresOf(Hilos::class));
        self::assertSame(HilosModerationKindsTable::TABLE, ConcertTicketsTableContext::hilosModerationKinds);
        self::assertSame(HilosModerationKindsTable::class, Hilos::TABLES[ConcertTicketsTableContext::hilosModerationKinds]);
        self::assertSame(HilosModerationRulesTable::TABLE, ConcertTicketsTableContext::hilosModerationRules);
        self::assertSame(HilosModerationRulesTable::class, Hilos::TABLES[ConcertTicketsTableContext::hilosModerationRules]);

        foreach ([
            ModerationPage::class => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_MODERATION,
            ModerationKindPage::class => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_MODERATION_KIND,
            ModerationKindRulesPage::class => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_MODERATION_KIND_RULES,
        ] as $page => $signal) {
            self::assertSame($page, Hilos::PAGES[$page::PAGE]);
            self::assertSame(AgentType::HILOS_INDEX, $page::SUBSCRIPTION_AGENT_TYPE);
            self::assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[$page::PAGE]);
            self::assertSame([BrowserConfigKey::SIGNAL => $signal], $page::BROWSER);
        }
        self::assertSame(HilosPageConstants::HILOS_MODERATION, ModerationPage::PAGE);
        self::assertSame(HilosPageConstants::HILOS_MODERATION_KIND, ModerationKindPage::PAGE);
        self::assertSame(HilosPageConstants::HILOS_MODERATION_KIND_RULES, ModerationKindRulesPage::PAGE);

        self::assertSame([ConcertTicketsTableContext::hilosModerationKinds => []],
            Hilos::PAGE_TABLES[ModerationPage::PAGE]);
        self::assertSame([ConcertTicketsTableContext::hilosModerationKinds => []],
            Hilos::PAGE_TABLES[ModerationKindPage::PAGE]);
        self::assertSame([
            ConcertTicketsTableContext::hilosModerationKinds => [],
            ConcertTicketsTableContext::hilosModerationRules => [],
        ], Hilos::PAGE_TABLES[ModerationKindRulesPage::PAGE]);
        self::assertSame(PageAccessLevel::ADMIN, ModerationKindPage::ACCESS_LEVEL);
        self::assertSame(PageReach::ROUTE, ModerationKindPage::REACH);
        self::assertSame([], ModerationKindPage::ACTIONS);
        self::assertSame([], ModerationKindPage::SIGNALS);
        self::assertSame([
            HilosSignalConstants::HILOS_MODERATION_RULE_ADD => HilosModerationRuleAddActionDTO::class,
            HilosSignalConstants::HILOS_MODERATION_RULE_DELETE => HilosModerationRuleDeleteActionDTO::class,
            HilosSignalConstants::HILOS_MODERATION_RULE_UPDATE => HilosModerationRuleUpdateActionDTO::class,
        ], ModerationKindRulesPage::ACTIONS);
        self::assertSame([SignalTypeConstants::AGENT_SIGNAL => [
            HilosSignalConstants::HILOS_MODERATION_RULE_WRITE_DONE => HandoverAnswerSignalData::class,
        ]], ModerationKindRulesPage::SIGNALS);

        foreach (ModerationKindRulesPage::ACTIONS as $action => $dto) {
            self::assertSame(ModerationKindRulesPage::PAGE, Hilos::getPageActionRoutes()[$action]);
            self::assertSame(AgentType::HILOS_INDEX, Hilos::getActionAgentRoutes()[$action]);
            self::assertSame($dto, Hilos::getActionDtoRoutes()[$action]);
        }
        self::assertSame(ModerationKindRulesPage::PAGE,
            Hilos::getPageSignalRoutes()[SignalTypeConstants::AGENT_SIGNAL][HilosSignalConstants::HILOS_MODERATION_RULE_WRITE_DONE]);

        $entry = Hilos::AGENTS[HilosAgentType::HILOS_MODERATION_RULES_LIBRARY];
        $this->assertSame([
            AgentRegistryKey::WORKER => ModerationRulesLibraryAgent::class,
            AgentRegistryKey::DAEMON => ModerationRulesLibraryAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ], $entry);
        $this->assertSame(AgentScope::CLUSTER, AgentRegistry::scope($entry));
        $this->assertFalse(AgentRegistry::requiresIndex($entry));
        $this->assertTrue((new ModerationRulesLibraryAgentDaemon())->requiresMonopolisticProcess());

        $moderator = Hilos::AGENTS[HilosAgentType::HILOS_MODERATOR];
        $this->assertSame([
            AgentRegistryKey::WORKER => ModerationAgent::class,
            AgentRegistryKey::DAEMON => ModerationAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ], $moderator);
        $this->assertSame(AgentScope::CLUSTER, AgentRegistry::scope($moderator));
        $this->assertFalse(AgentRegistry::requiresIndex($moderator));
        $this->assertTrue((new ModerationAgentDaemon())->requiresMonopolisticProcess());

        $catalog = ConcertTicketsSettingsCatalog::getCatalog();
        foreach (ModerationSettingsCatalog::getCatalog() as $key => $settingEntry) {
            self::assertSame($settingEntry, $catalog[$key]);
        }
        self::assertSame(ModerationKindDirectory::class, Hilos::moderationKindDirectoryClass());
        $kinds = Hilos::moderationKinds();
        self::assertSame([ModerationKindKey::PROFILE_NAME, ModerationKindKey::PROFILE_PHOTO], array_keys($kinds));
        self::assertSame(ModerationInput::TEXT, $kinds[ModerationKindKey::PROFILE_NAME]->input);
        self::assertSame(ModerationInput::IMAGE, $kinds[ModerationKindKey::PROFILE_PHOTO]->input);
        foreach ($kinds as $kind) {
            self::assertSame(HilosAgentType::HILOS_USERS_LIBRARY, $kind->verdictTo);
        }
        self::assertArrayNotHasKey(HilosSignalConstants::HILOS_MODERATION_VERDICT, Hilos::getAgentSignalRoutes());

        $requirements = (new FeatureRegistry())->definition(HilosFeature::MODERATION)->requirements();
        self::assertSame([HilosAgentType::HILOS_MODERATOR], $requirements->requiredAgents);
        self::assertSame([HilosAgentType::HILOS_MODERATION_RULES_LIBRARY, HilosAgentType::HILOS_SETTINGS_LIBRARY],
            $requirements->requiredSharedAgents);
        self::assertSame([ModerationSettingsCatalog::class], $requirements->requiredCatalogFragments);
        self::assertSame(['hilos_moderation_rule'], $requirements->requiredDbTables);
        $previousRt = Hilos::$rt;
        try {
            Hilos::$rt = new ConcertTicketsRtContext();
            $registry = new FeatureRegistry();
            Hilos::$rt->mountFeatureRuntime(array_map($registry->definition(...), Hilos::features()));
            Hilos::$rt->configure();
            Hilos::$rt->assertFeatureRuntimeIntact();
            self::assertInstanceOf(HilosModerationChecks::class, Hilos::$rt->hilosModerationChecks);
        } finally {
            Hilos::$rt = $previousRt;
        }
    }

    /** The photo pipeline uses framework policies and one policy-placed holder per subsystem. */
    public function testProfilePhotoPipelineUsesTheFrameworkAgentsAndCatalogs(): void
    {
        foreach ([
            HilosAgentType::HILOS_FILES_LIBRARY => [FilesLibraryAgent::class, FilesLibraryAgentDaemon::class],
            HilosAgentType::HILOS_UPLOADS => [UploadsAgent::class, UploadsAgentDaemon::class],
            HilosAgentType::HILOS_IMAGES => [ImagesAgent::class, ImagesAgentDaemon::class],
        ] as $agentType => [$workerClass, $daemonClass]) {
            $entry = Hilos::AGENTS[$agentType];
            self::assertSame([
                AgentRegistryKey::WORKER => $workerClass,
                AgentRegistryKey::DAEMON => $daemonClass,
                AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
            ], $entry);
            self::assertSame(AgentScope::CLUSTER, AgentRegistry::scope($entry));
            self::assertFalse(AgentRegistry::requiresIndex($entry));
            self::assertTrue((new $daemonClass())->requiresMonopolisticProcess());
        }

        self::assertTrue(Hilos::hasFeature(HilosFeature::MODERATION));
        self::assertSame([], Hilos::UPLOAD_TARGETS);
        self::assertSame([], Hilos::IMAGE_VARIANTS);
        self::assertSame([ProfilePhotoUploadTarget::NAME => ProfilePhotoUploadTarget::class], Hilos::uploadTargets());
        self::assertSame([
            ProfilePhotoUploadTarget::VARIANT => [
                ImageVariant::WIDTH => 256,
                ImageVariant::HEIGHT => 256,
                ImageVariant::FIT => ImageFit::COVER,
                ImageVariant::FORMAT => ImageFormat::WEBP,
            ],
        ], Hilos::imageVariants());

        $catalog = ConcertTicketsSettingsCatalog::getCatalog();
        foreach ([FilesSettingsCatalog::getCatalog(), UploadsSettingsCatalog::getCatalog()] as $fragment) {
            foreach ($fragment as $key => $entry) {
                self::assertSame($entry, $catalog[$key]);
            }
        }

        foreach ([
            HilosSignalConstants::HILOS_FILE_BIND => FileBindSignalData::class,
            HilosSignalConstants::HILOS_FILE_REMOVE => FileRemoveSignalData::class,
            HilosSignalConstants::HILOS_FILE_PUBLISH => FilePublishSignalData::class,
            HilosSignalConstants::HILOS_IMAGE_RENDERED => ImageRenderedSignalData::class,
            HilosSignalConstants::HILOS_UPLOAD_PUBLISH => UploadPublishSignalData::class,
            HilosSignalConstants::HILOS_UPLOAD_HOLD_SETTLE => UploadHoldSettleSignalData::class,
            HilosSignalConstants::HILOS_IMAGE_RENDER => ImageRenderSignalData::class,
        ] as $signal => $dtoClass) {
            self::assertSame($dtoClass, Hilos::getAgentSignalDtoRoutes()[$signal]);
        }
        self::assertSame(UploadInitActionDTO::class, Hilos::getAgentActionDtoRoutes()[HilosSignalConstants::HILOS_UPLOAD_INIT]);
        self::assertSame(UploadCancelActionDTO::class, Hilos::getAgentActionDtoRoutes()[HilosSignalConstants::HILOS_UPLOAD_CANCEL]);
        self::assertSame([
            HilosUserPhotoBrowserTable::TABLE => HilosUserPhotoBrowserTable::BINDING,
        ], array_intersect_key(Hilos::PAGE_TABLES[UserPage::PAGE], [HilosUserPhotoBrowserTable::TABLE => true]));
    }

    /** All eight journal pages use the cluster reader and the framework tables. */
    public function testChangeLogSectionIsFullyActivated(): void
    {
        self::assertTrue(Hilos::hasFeature(HilosFeature::CHANGE_LOG));
        foreach ([ChangeLogDashboardPage::class, ChangeLogFeedPage::class, ChangeLogTablesPage::class,
            ChangeLogNoisyPage::class, ChangeLogTablePage::class,
            ChangeLogTableColumnsPage::class, ChangeLogTableTriggersPage::class, ChangeLogTableHistoryPage::class] as $page) {
            self::assertSame($page, Hilos::PAGES[$page::PAGE]);
            self::assertSame(AgentType::HILOS_CHANGE_LOG, $page::SUBSCRIPTION_AGENT_TYPE);
        }
        self::assertSame([
            AgentRegistryKey::WORKER => DemoHilosChangeLogAgent::class,
            AgentRegistryKey::DAEMON => DemoHilosChangeLogAgentDaemon::class,
        ], Hilos::AGENTS[AgentType::HILOS_CHANGE_LOG]);
        self::assertSame(AgentScope::CLUSTER, AgentRegistry::scope(Hilos::AGENTS[AgentType::HILOS_CHANGE_LOG]));
        self::assertTrue((new DemoHilosChangeLogAgentDaemon())->requiresMonopolisticProcess());
        self::assertSame(HilosChangeLogFeedTable::class, Hilos::TABLES[ConcertTicketsTableContext::hilosChangeLogFeed]);
        self::assertSame([ConcertTicketsTableContext::hilosChangeLogFeed => []], Hilos::PAGE_TABLES[ChangeLogFeedPage::PAGE]);
        self::assertArrayNotHasKey(ChangeLogDashboardPage::PAGE, Hilos::PAGE_TABLES);
        self::assertSame(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_FEED,
            ChangeLogFeedPage::BROWSER[BrowserConfigKey::SIGNAL]);
        self::assertSame(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_NOISY,
            ChangeLogNoisyPage::BROWSER[BrowserConfigKey::SIGNAL]);
        self::assertSame([], ChangeLogDashboardPage::ACTIONS);
        self::assertSame(HilosChangeLogNoisyColumnsTable::class, Hilos::TABLES[ConcertTicketsTableContext::hilosChangeLogNoisyColumns]);
        self::assertSame([ConcertTicketsTableContext::hilosChangeLogNoisyColumns => []], Hilos::PAGE_TABLES[ChangeLogNoisyPage::PAGE]);
        self::assertSame(HilosChangeLogTablesTable::class, Hilos::TABLES[ConcertTicketsTableContext::hilosChangeLogTables]);
        self::assertSame([ConcertTicketsTableContext::hilosChangeLogTables => []], Hilos::PAGE_TABLES[ChangeLogTablesPage::PAGE]);
        self::assertSame(HilosChangeLogHistoryTable::class, Hilos::TABLES[ConcertTicketsTableContext::hilosChangeLogHistory]);
        self::assertSame([ConcertTicketsTableContext::hilosChangeLogHistory => []],
            Hilos::PAGE_TABLES[ChangeLogTableHistoryPage::PAGE]);
        self::assertArrayNotHasKey(ChangeLogTablePage::PAGE, Hilos::PAGE_TABLES);
        self::assertSame([ConcertTicketsTableContext::hilosChangeLogTableColumns => []],
            Hilos::PAGE_TABLES[ChangeLogTableColumnsPage::PAGE]);
        self::assertSame(HilosChangeLogTableColumnsTable::class, Hilos::TABLES[ConcertTicketsTableContext::hilosChangeLogTableColumns]);
        self::assertSame([ConcertTicketsTableContext::hilosChangeLogTableTriggers => []],
            Hilos::PAGE_TABLES[ChangeLogTableTriggersPage::PAGE]);
        self::assertSame(HilosChangeLogTableTriggersTable::class, Hilos::TABLES[ConcertTicketsTableContext::hilosChangeLogTableTriggers]);
        foreach ([
            ChangeLogTablePage::class => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_TABLE,
            ChangeLogTableColumnsPage::class => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_TABLE_COLUMNS,
            ChangeLogTableTriggersPage::class => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_TABLE_TRIGGERS,
            ChangeLogTableHistoryPage::class => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_TABLE_HISTORY,
        ] as $page => $signal) {
            self::assertSame(AgentType::HILOS_CHANGE_LOG, Hilos::getPageRoutes()[$page::PAGE]);
            self::assertSame($signal, $page::BROWSER[BrowserConfigKey::SIGNAL]);
            self::assertSame([], $page::ACTIONS);
        }
        foreach ([HilosPageConstants::HILOS_CHANGE_LOG, HilosPageConstants::HILOS_CHANGE_LOG_TABLE] as $page) {
            self::assertSame(
                array_replace(HilosPageCatalog::CATALOG[$page], [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true]),
                PageCatalog::pages()[$page],
            );
        }
    }

    /** The Daemon environment pages name the node whose replica serves them. */
    public function testOnlyDaemonEnvironmentPageUsesNodeAddressedSubscription(): void
    {
        $routes = Hilos::getPageAgentIndexRoutes();
        $this->assertSame([DaemonEnvPage::PAGE, DaemonEnvOrphansPage::PAGE], array_keys($routes));
        foreach ([DaemonEnvPage::PAGE, DaemonEnvOrphansPage::PAGE] as $page) {
            $this->assertSame('node_param', $routes[$page]->source->value);
            $this->assertSame('nodeId', $routes[$page]->param);
            $this->assertSame(HilosAgentType::HILOS_DAEMON, $routes[$page]->fallbackAgentType);
        }
    }

    public function testProfileSectionsAreActivated(): void
    {
        foreach ([
            ProfileSignInPage::class,
            ProfileSessionsPage::class,
            ProfileSecurityPage::class,
            ProfileAgreementsPage::class,
            ProfileDataPage::class,
        ] as $page) {
            $this->assertSame($page, Hilos::PAGES[$page::PAGE]);
            $this->assertSame(AgentType::CONCERT_TICKETS, $page::SUBSCRIPTION_AGENT_TYPE);
        }
        $this->assertArrayNotHasKey(HilosPageConstants::HILOS_PROFILE_AGREEMENTS_HISTORY, Hilos::PAGES);

        $this->assertSame(
            [
                HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::BINDING,
                ProfileSessionsBrowserList::LIST => [
                    BrowserParamKey::PARAMS => [
                        BrowserRuntimeParam::ACCEPT_KEY => ConcertTicketsBrowserRef::ACCEPT_KEY,
                    ],
                ],
            ],
            Hilos::PAGE_LISTS[ProfileSessionsPage::PAGE],
        );
        $this->assertSame(ProfileSessionsBrowserList::class, Hilos::BROWSER_LISTS[ProfileSessionsBrowserList::LIST]);
        $this->assertSame(SelfConnectionBrowserData::class, Hilos::BROWSER_DATA[SelfConnectionBrowserData::DATA]);
        foreach ([ProfilePage::PAGE, ProfileSessionsPage::PAGE] as $page) {
            $this->assertSame(
                [
                    SelfConnectionBrowserData::DATA => [
                        BrowserParamKey::PARAMS => [
                            BrowserRuntimeParam::ACCEPT_KEY => ConcertTicketsBrowserRef::ACCEPT_KEY,
                        ],
                    ],
                ],
                Hilos::PAGE_DATA[$page],
            );
        }
        $this->assertSame([ProfilePage::PAGE, ProfileSessionsPage::PAGE], array_keys(Hilos::PAGE_DATA));

        $this->assertSame(
            ProfileSignInPage::PAGE,
            Hilos::getPageActionRoutes()[HilosSignalConstants::HILOS_LINK_OAUTH_START],
        );
    }

    /** Sign-in offers the password, the passkey, the phone code and GitHub, and codes leave by SMS alone. */
    public function testSignInMethodsCodeChannelsAndProvidersAreTheDemosOwn(): void
    {
        self::assertSame(ConcertTicketsAuthMethodDirectory::class, Hilos::authMethodDirectoryClass());
        self::assertSame(ConcertTicketsCodeChannelRegistry::class, Hilos::codeChannelRegistryClass());
        self::assertSame(ConcertTicketsOAuthProviderDirectory::class, Hilos::oauthProviderDirectoryClass());
        self::assertSame([OAuthProviderPreset::GITHUB->value], array_keys(ConcertTicketsOAuthProviderDirectory::all()));
        self::assertSame([SmsCodeChannel::NAME], array_keys(ConcertTicketsCodeChannelRegistry::all()));
        self::assertSame([
            AuthMethodKey::PASSWORD,
            AuthMethodKey::PASSKEY,
            AuthMethodKey::SMS,
            OAuthProviderPreset::GITHUB->value,
        ], ConcertTicketsAuthMethodDirectory::keys());

        $oauth = Hilos::AGENTS[HilosAgentType::HILOS_OAUTH];
        self::assertSame([
            AgentRegistryKey::WORKER => OAuthAgent::class,
            AgentRegistryKey::DAEMON => OAuthAgentDaemon::class,
        ], $oauth);
        $code = Hilos::AGENTS[HilosAgentType::HILOS_AUTH_CODE];
        self::assertSame(AuthCodeAgent::class, AgentRegistry::workerClass($code));
        self::assertSame(AuthCodeAgentDaemon::class, AgentRegistry::daemonClass($code));
        self::assertSame(AgentScope::NODE, AgentRegistry::scope($code));
    }

    /** Pins the Daemon feature, the page routes and all three agent placements. */
    public function testDaemonAdminFeatureIsActivated(): void
    {
        $this->assertContains(HilosFeature::DAEMON, Hilos::features());
        foreach ([
            DaemonPage::class,
            DaemonPlacementPage::class,
            DaemonNodePage::class,
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
        $this->assertArrayNotHasKey(DaemonNodePage::PAGE, Hilos::PAGE_TABLES);
        $this->assertArrayNotHasKey(DaemonPage::PAGE, Hilos::PAGE_TABLES);
        $this->assertSame(DaemonEnvPage::class, Hilos::PAGES[DaemonEnvPage::PAGE]);
        $this->assertSame(HilosAgentType::HILOS_DAEMON_NODE, Hilos::getPageRoutes()[DaemonEnvPage::PAGE]);
        $this->assertSame(DaemonEnvOrphansPage::class, Hilos::PAGES[DaemonEnvOrphansPage::PAGE]);
        $this->assertSame(HilosAgentType::HILOS_DAEMON_NODE, Hilos::getPageRoutes()[DaemonEnvOrphansPage::PAGE]);

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

        foreach ([
            DaemonCronPage::PAGE => ConcertTicketsTableContext::hilosDaemonCron,
            DaemonWorkersPage::PAGE => ConcertTicketsTableContext::hilosDaemonWorkers,
            DaemonEnvPage::PAGE => ConcertTicketsTableContext::hilosDaemonEnvKeys,
            DaemonEnvOrphansPage::PAGE => ConcertTicketsTableContext::hilosDaemonEnvOrphans,
            DaemonPlacementPage::PAGE => ConcertTicketsTableContext::hilosDaemonPlacement,
            DaemonAgentsPage::PAGE => ConcertTicketsTableContext::hilosDaemonAgents,
            DaemonEnvMismatchPage::PAGE => ConcertTicketsTableContext::hilosDaemonEnvMismatch,
            DaemonHttpServerPage::PAGE => ConcertTicketsTableContext::hilosDaemonHttpRoutes,
        ] as $page => $table) {
            $this->assertSame([$table => []], Hilos::PAGE_TABLES[$page]);
        }
    }

    public function testLogsAdminFeatureIsActivated(): void
    {
        // The logs section is a configure-only framework feature: the demo registers the six
        // section pages against their framework abstracts, mounts the section agent with the
        // per-node store and the cluster aggregator behind it, binds the browser tables the
        // list screens read, and writes not a line of the section itself.
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
            $this->assertSame(AgentType::HILOS_LOGS, Hilos::getPageRoutes()[$page::PAGE]);
        }

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
        $this->assertSame(DemoHilosLogsAgent::class, AgentRegistry::workerClass(Hilos::AGENTS[AgentType::HILOS_LOGS]));
        $this->assertSame(DemoHilosLogsAgentDaemon::class, AgentRegistry::daemonClass(Hilos::AGENTS[AgentType::HILOS_LOGS]));
        $this->assertSame(AgentScope::NODE, AgentRegistry::scope(Hilos::AGENTS[HilosAgentType::HILOS_LOG_STORE]));
        $this->assertSame(AgentScope::NODE, AgentRegistry::scope(Hilos::AGENTS[HilosAgentType::HILOS_LOG_CARRIER]));
        $this->assertSame(AgentPlacement::POLICY, AgentRegistry::placement(Hilos::AGENTS[HilosAgentType::HILOS_LOG_AGGREGATOR]));

        foreach ([
            LogsOverviewPage::PAGE => ConcertTicketsTableContext::hilosLogNodes,
            LogsKeysPage::PAGE => ConcertTicketsTableContext::hilosLogKeys,
            LogsRotationsPage::PAGE => ConcertTicketsTableContext::hilosLogRotations,
            LogsWorkersPage::PAGE => ConcertTicketsTableContext::hilosLogWorkers,
        ] as $page => $table) {
            $this->assertSame([$table => []], Hilos::PAGE_TABLES[$page]);
        }
        $this->assertSame(HilosLogNodesTable::class, Hilos::TABLES[ConcertTicketsTableContext::hilosLogNodes]);
        self::assertSame(
            array_replace(
                HilosPageCatalog::CATALOG[HilosPageConstants::HILOS_LOGS],
                [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
            ),
            PageCatalog::pages()[HilosPageConstants::HILOS_LOGS],
        );

        // The logging modes screen writes framework keys, and a key the project catalog does
        // not know is written and then silently treated as an orphan (HIL-857).
        $this->assertSame([], array_diff_key(
            LogSettingsCatalog::getCatalog(),
            ConcertTicketsSettingsCatalog::getCatalog(),
        ));
    }

    public function testSmsWorkerIsRawOnlyWithTheExactRemainingDeliveryDebt(): void
    {
        $entry = Hilos::AGENTS[HilosAgentType::HILOS_SMS];
        $this->assertSame(SmsRawSendAgent::class, $entry[AgentRegistryKey::WORKER]);
        $this->assertSame([], OwnershipDeclaration::dbCollectionsOf(SmsRawSendAgent::class));
        $this->assertSame([HilosSignalConstants::HILOS_SMS_SEND], array_keys(SmsRawSendAgent::AGENT_SIGNALS));
        $this->assertSame(['HIL-1952'], Hilos::SHARED_DB_OWNERS['notificationDeliveries']);
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
            ConcertTicketsRtContext::class,
        );

        $this->addToAssertionCount(1);
    }

    /**
     * @throws HilosException When the context refuses to configure, or a guard refuses a chain or a key
     */
    public function testDatabaseMountsAreWhole(): void
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
            // The mounted journal chains need physical schema identities; configuration alone opens no connection.
            Database::configure(database: 'topology_unit');
            Database::configure(index: 1, database: ChangeLogDatabase::configuredName());
            Hilos::$db = new ConcertTicketsDbContext();
            Hilos::$db->configure();
            FrameworkExtensionGuard::assertMountedExtensionsWhole();
            MountedCollectionKeyGuard::assertMountedKeysAgree();
        } finally {
            Hilos::$db = $previous;
            foreach ($databaseMetadata as $property => $value) {
                new ReflectionProperty(Database::class, $property)->setValue(null, $value);
            }
        }

        $this->addToAssertionCount(1);
    }
}

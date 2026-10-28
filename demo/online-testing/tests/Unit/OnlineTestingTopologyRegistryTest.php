<?php

declare(strict_types=1);

namespace Demo\OnlineTesting\Tests\Unit;

use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Runtime\State\Item\HilosProfileNameChange;
use Demo\OnlineTesting\Agents\Hilos\DemoHilosAnalyticsAgent;
use Demo\OnlineTesting\Core\Agent\Daemon\Hilos\DemoHilosAnalyticsAgentDaemon;
use Demo\OnlineTesting\Pages\Hilos\AnalyticsPage;
use Demo\OnlineTesting\Pages\Hilos\Analytics\BrowserSessionPage;
use Demo\OnlineTesting\Pages\Hilos\Analytics\PersonPage;
use Hilos\Core\Analytics\AnalyticsJournalAgent;
use Hilos\Core\Analytics\AnalyticsJournalAgentDaemon;
use Hilos\Core\Analytics\AnalyticsWriterAgent;
use Hilos\Core\Analytics\AnalyticsWriterAgentDaemon;
use Hilos\Core\Analytics\DTO\AnalyticsJournalAppendSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalLoadedSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalPortionSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalReadSignalData;
use Hilos\Core\Analytics\DTO\AnalyticsJournalReadySignalData;

use Demo\OnlineTesting\Agents\OnlineTestingAgent;
use Demo\OnlineTesting\Agents\Hilos\DataExportAgent;
use Demo\OnlineTesting\Agents\Hilos\DemoHilosAgent;
use Demo\OnlineTesting\Agents\Hilos\DemoHilosDaemonAgent;
use Demo\OnlineTesting\Agents\Hilos\DemoHilosLogsAgent;
use Demo\OnlineTesting\Agents\Hilos\NotificationsLibraryAgent;
use Demo\OnlineTesting\Agents\Hilos\SessionsLibraryAgent;
use Demo\OnlineTesting\Agents\Hilos\UserAgent;
use Demo\OnlineTesting\Agents\Hilos\UsersLibraryAgent;
use Demo\OnlineTesting\Constants\AgentType;
use Demo\OnlineTesting\Constants\PageConstants;
use Demo\OnlineTesting\Core\Agent\Daemon\OnlineTestingAgentDaemon;
use Demo\OnlineTesting\Core\Agent\Daemon\Hilos\DemoHilosAgentDaemon;
use Demo\OnlineTesting\Core\Agent\Daemon\Hilos\DemoHilosDaemonAgentDaemon;
use Demo\OnlineTesting\Core\Agent\Daemon\Hilos\DemoHilosLogsAgentDaemon;
use Demo\OnlineTesting\Core\Agent\Daemon\Hilos\NotificationsLibraryAgentDaemon;
use Demo\OnlineTesting\Core\Agent\Daemon\Hilos\SessionsLibraryAgentDaemon;
use Demo\OnlineTesting\Core\Agent\Daemon\Hilos\UserAgentDaemon;
use Demo\OnlineTesting\Core\Agent\Daemon\Hilos\UsersLibraryAgentDaemon;
use Demo\OnlineTesting\Database\OnlineTestingDbContext;
use Demo\OnlineTesting\Database\Settings\OnlineTestingSettingsCatalog;
use Demo\OnlineTesting\Hilos;
use Demo\OnlineTesting\Pages\Hilos\Status\StatusPage;
use Demo\OnlineTesting\Pages\Hilos\Status\StatusComponentsPage;
use Hilos\Pages\Status\AbstractHilosStatusPage;
use Hilos\Pages\Status\AbstractHilosStatusComponentsPage;
use Hilos\Pages\Status\DTO\HilosStatusIncidentActionDTO;
use Hilos\Pages\Status\DTO\HilosStatusAddUpdateActionDTO;
use Hilos\Tables\Status\HilosStatusComponentsTable;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Demo\OnlineTesting\Groups\Hilos\NotificationsGroup;
use Demo\OnlineTesting\Pages\Hilos\AboutPage;
use Demo\OnlineTesting\Pages\Hilos\DashboardPage;
use Demo\OnlineTesting\Pages\Hilos\LicensePage;
use Demo\OnlineTesting\Pages\Hilos\Daemon\DaemonPage;
use Demo\OnlineTesting\Pages\Hilos\Daemon\DaemonPlacementPage;
use Demo\OnlineTesting\Pages\Hilos\Daemon\DaemonNodePage;
use Demo\OnlineTesting\Pages\Hilos\Daemon\DaemonWorkersPage;
use Demo\OnlineTesting\Pages\Hilos\Daemon\DaemonAgentsPage;
use Demo\OnlineTesting\Pages\Hilos\Daemon\DaemonCronPage;
use Demo\OnlineTesting\Pages\Hilos\Daemon\DaemonWebsocketsPage;
use Demo\OnlineTesting\Pages\Hilos\Daemon\DaemonHttpServerPage;
use Demo\OnlineTesting\Pages\Hilos\Daemon\DaemonEnvPage;
use Demo\OnlineTesting\Pages\Hilos\Daemon\DaemonEnvOrphansPage;
use Demo\OnlineTesting\Pages\Hilos\Daemon\DaemonEnvMismatchPage;
use Demo\OnlineTesting\Pages\Hilos\Logs\LogsKeysPage;
use Demo\OnlineTesting\Pages\Hilos\Logs\LogsOverviewPage;
use Demo\OnlineTesting\Pages\Hilos\Logs\LogsRotationsPage;
use Demo\OnlineTesting\Pages\Hilos\Logs\LogsSettingsPage;
use Demo\OnlineTesting\Notification\OnlineTestingDeliveryChannelRegistry;
use Demo\OnlineTesting\Pages\Hilos\Communications\CommunicationsChannelPage;
use Demo\OnlineTesting\Pages\Hilos\Communications\CommunicationsChannelConfigurationPage;
use Demo\OnlineTesting\Pages\Hilos\Communications\CommunicationsChannelFramePage;
use Demo\OnlineTesting\Pages\Hilos\Communications\CommunicationsDeliveriesPage;
use Demo\OnlineTesting\Pages\Hilos\Communications\CommunicationsPage;
use Demo\OnlineTesting\Pages\Hilos\Logs\LogsViewPage;
use Demo\OnlineTesting\Pages\Hilos\Logs\LogsWorkersPage;
use Demo\OnlineTesting\Pages\Hilos\Maintenance\MaintenanceCirclePage;
use Demo\OnlineTesting\Pages\Hilos\Maintenance\MaintenanceWordsPage;
use Demo\OnlineTesting\Pages\Hilos\Maintenance\MaintenancePage;
use Hilos\Pages\Maintenance\DTO\MaintenancePassActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenanceRestoreCloseActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenanceRestoreOpenActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenanceRestorePassActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenanceDisableActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenanceEnableActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenanceCircleRemoveActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenanceCircleAddActionDTO;
use Demo\OnlineTesting\Pages\Hilos\PrivacyPage;
use Demo\OnlineTesting\Pages\Hilos\ProfileNotificationsPage;
use Demo\OnlineTesting\Pages\Hilos\SettingsPage;
use Demo\OnlineTesting\Pages\Hilos\AppearancePage;
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
use Hilos\Auth\Library\DTO\ThemePickActionDTO;
use Hilos\Auth\Library\DTO\TimeZonePickActionDTO;
use Hilos\Users\DTO\UserThemePickWriteDoneSignalData;
use Hilos\Users\DTO\UserTimeZoneWriteDoneSignalData;
use Hilos\Users\DTO\UserTimeZoneWriteSignalData;
use Hilos\Users\DTO\UserLastBrowserTimeZoneWriteSignalData;
use Hilos\Constants\HttpConstants;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Daemon\DaemonCollectorAgentDaemon;
use Hilos\Core\Agent\Daemon\DaemonNodeAgentDaemon;
use Hilos\Core\Agent\Hilos\DaemonCollectorAgent;
use Hilos\Core\Agent\Hilos\DaemonNodeAgent;
use Hilos\Core\CLI\CliManager;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Database\Settings\Library\SettingsLibraryAgentDaemon;
use Hilos\Database\Settings\SettingsCatalogConstants;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\Database;
use Hilos\Database\Schema\FrameworkExtensionGuard;
use Hilos\DataExport\DataExportAgentDaemon;
use Hilos\DataExport\DataExportHttp;
use Hilos\HilosException;
use Hilos\Log\LogAggregatorAgent;
use Hilos\Log\LogCarrierAgent;
use Hilos\Log\LogSettingsCatalog;
use Hilos\Theme\ThemeSettingsCatalog;
use Hilos\Log\LogStoreAgent;
use Hilos\Notification\NotificationAction;
use Hilos\Notification\NotificationPreferenceAction;
use Hilos\Push\PushSubscriptionAction;
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
use Hilos\Tables\Daemon\HilosDaemonEnvMismatchTable;
use Hilos\Tables\Daemon\HilosDaemonHttpRoutesTable;
use Hilos\Tables\Daemon\HilosDaemonPlacementTable;
use Hilos\Tables\Logs\HilosLogWorkersTable;
use Hilos\Tables\ProtectedMode\HilosVerifierCircleTable;
use Hilos\Tables\ProtectedMode\HilosMaintenanceWordsTable;
use Hilos\Tables\Settings\HilosSettingsTable;
use Hilos\Tables\Appearance\HilosAppearanceSettingsTable;
use Hilos\Core\Action\DTO\HandoverAnswerSignalData;
use Hilos\Core\Page\PageAccessLevel;
use Hilos\Pages\Appearance\DTO\HilosAppearanceDefaultThemeSetActionDTO;
use Hilos\Pages\Appearance\DTO\HilosAppearanceThemeSwitchingSetActionDTO;
use Hilos\Pages\Communications\DTO\HilosChannelSettingResetActionDTO;
use Hilos\Pages\Communications\DTO\HilosChannelSettingUpdateActionDTO;
use Hilos\Pages\Communications\DTO\HilosChannelTestActionDTO;
use Hilos\Pages\Communications\DTO\HilosDeliveryRetryActionDTO;
use Hilos\Tables\Communications\HilosCommunicationsChannelFieldsTable;
use Hilos\Tables\Communications\HilosCommunicationsChannelsTable;
use Hilos\Tables\Communications\HilosNotificationDeliveriesTable;
use Hilos\Mail\Delivery\MailDeliveryChannel;
use Hilos\Notification\Delivery\DeliveryChannelSettings;
use Hilos\Notification\Delivery\DeliveryLogSettingsCatalog;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Tables\Users\HilosUserDetailBrowserTable;
use Demo\OnlineTesting\Pages\Hilos\I18nPage;
use Demo\OnlineTesting\Pages\Hilos\I18n\Lists\LanguagesListPage;
use Demo\OnlineTesting\Pages\Hilos\I18n\Details\CountryDetailPage;
use Demo\OnlineTesting\Pages\Hilos\I18n\Details\CountryNamesPage;
use Demo\OnlineTesting\Pages\Hilos\I18n\Details\LanguageDetailPage;
use Demo\OnlineTesting\Pages\Hilos\I18n\Details\LanguageLocalesPage;
use Demo\OnlineTesting\Pages\Hilos\I18n\Details\LanguageNamesPage;
use Demo\OnlineTesting\Pages\Hilos\I18n\Lists\CountriesListPage;
use Hilos\I18n\Library\I18nLibraryAgent;
use Hilos\I18n\Browser\CountryCardBrowserData;
use Hilos\I18n\Browser\LanguageCardBrowserData;
use Hilos\I18n\Library\I18nLibraryAgentDaemon;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Demo\OnlineTesting\Agents\Hilos\DemoHilosChangeLogAgent;
use Demo\OnlineTesting\Core\Agent\Daemon\Hilos\DemoHilosChangeLogAgentDaemon;
use Demo\OnlineTesting\Pages\Hilos\ChangeLog\ChangeLogDashboardPage;
use Demo\OnlineTesting\Pages\Hilos\ChangeLog\ChangeLogFeedPage;
use Demo\OnlineTesting\Pages\Hilos\ChangeLog\ChangeLogNoisyPage;
use Demo\OnlineTesting\Pages\Hilos\ChangeLog\ChangeLogTablesPage;
use Demo\OnlineTesting\Pages\Hilos\ChangeLog\ChangeLogTablePage;
use Hilos\Tables\ChangeLog\HilosChangeLogFeedTable;
use Hilos\Tables\ChangeLog\HilosChangeLogHistoryTable;
use Hilos\Tables\ChangeLog\HilosChangeLogNoisyColumnsTable;
use Hilos\Tables\ChangeLog\HilosChangeLogTablesTable;
use Hilos\Core\Agent\Daemon\StatusAgentDaemon;
use Hilos\Core\Agent\TopologyAgentFactory;
use Hilos\Core\Feature\FeatureRegistry;
use Hilos\Database\Migration\FrameworkSchemaCatalog;
use Hilos\Database\Settings\Validation\NonNegativeIntegerRule;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\Status\DTO\StatusCancelPublicationSignalData;
use Hilos\Status\DTO\StatusPublishNowSignalData;
use Hilos\Status\DTO\StatusAddUpdateSignalData;
use Hilos\DaemonSection\DTO\DaemonPictureWatchSignalData;
use Hilos\Status\StatusAgent;
use Hilos\Status\StatusSettingsCatalog;

/**
 * Guards the project-level online-testing topology registry.
 *
 * Guards the home, admin sections, notifications, sign-in and cluster probe registry.
 * Snapshots intentionally name every page and agent; a later activation must update them.
 */
final class OnlineTestingTopologyRegistryTest extends TestCase
{
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

    public function testLetterFrameUsesTheExistingIndexOwnerWithoutTablesOrMutations(): void
    {
        self::assertSame(CommunicationsChannelFramePage::class, Hilos::PAGES[CommunicationsChannelFramePage::PAGE]);
        self::assertSame(CommunicationsChannelConfigurationPage::SUBSCRIPTION_AGENT_TYPE,
            Hilos::getPageRoutes()[CommunicationsChannelFramePage::PAGE]);
        self::assertSame([
            BrowserConfigKey::SIGNAL => HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_COMMUNICATIONS_CHANNEL_FRAME,
        ], CommunicationsChannelFramePage::BROWSER);
        self::assertSame([], CommunicationsChannelFramePage::ACTIONS);
        self::assertSame([], CommunicationsChannelFramePage::SIGNALS);
        self::assertArrayNotHasKey(CommunicationsChannelFramePage::PAGE, Hilos::PAGE_TABLES);
    }

    /** The shared Status owner is a permanent singleton placed on a regular worker. */
    public function testStatusOwnerUsesTheFrameworkPairAndFactories(): void
    {
        self::assertContains(HilosFeature::STATUS, Hilos::featuresOf(Hilos::class));
        $entry = Hilos::AGENTS[HilosAgentType::HILOS_STATUS];
        self::assertSame([
            AgentRegistryKey::WORKER => StatusAgent::class,
            AgentRegistryKey::DAEMON => StatusAgentDaemon::class,
            AgentRegistryKey::SCOPE => AgentScope::CLUSTER,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ], $entry);
        self::assertFalse(AgentRegistry::requiresIndex($entry));
        self::assertNull(AgentRegistry::idleTimeout($entry));
        self::assertSame(StatusAgent::class, TopologyAgentFactory::createWorker(Hilos::class, HilosAgentType::HILOS_STATUS, null)::class);
        $daemon = TopologyAgentFactory::createDaemon(Hilos::class, HilosAgentType::HILOS_STATUS, null);
        self::assertSame(StatusAgentDaemon::class, $daemon::class);
        self::assertFalse($daemon->requiresMonopolisticProcess());
    }

    /** Status owns its collector response and three internal writes, with no client actions. */
    public function testStatusConsumerAndInternalOperationsHaveTypedRoutes(): void
    {
        self::assertSame([
            HilosSignalConstants::STATUS_DAEMON_PICTURE_PORTION => DaemonClusterPicturePortionSignalData::class,
            HilosSignalConstants::STATUS_CANCEL_PUBLICATION => StatusCancelPublicationSignalData::class,
            HilosSignalConstants::STATUS_PUBLISH_NOW => StatusPublishNowSignalData::class,
            HilosSignalConstants::STATUS_ADD_UPDATE => StatusAddUpdateSignalData::class,
        ], StatusAgent::AGENT_SIGNALS);
        self::assertSame(DaemonPictureWatchSignalData::class,
            Hilos::getAgentSignalDtoRoutes()[HilosSignalConstants::STATUS_DAEMON_PICTURE_WATCH]);
        self::assertSame(DaemonClusterPicturePortionSignalData::class,
            Hilos::getAgentSignalDtoRoutes()[HilosSignalConstants::STATUS_DAEMON_PICTURE_PORTION]);
        self::assertSame(StatusCancelPublicationSignalData::class,
            Hilos::getAgentSignalDtoRoutes()[HilosSignalConstants::STATUS_CANCEL_PUBLICATION]);
        self::assertArrayNotHasKey(HilosSignalConstants::STATUS_CANCEL_PUBLICATION, Hilos::getActionAgentRoutes());
        self::assertNotContains(HilosSignalConstants::STATUS_CANCEL_PUBLICATION, StatusAgent::AUTH_ACTIONS);
        self::assertSame(StatusPublishNowSignalData::class,
            Hilos::getAgentSignalDtoRoutes()[HilosSignalConstants::STATUS_PUBLISH_NOW]);
        self::assertArrayNotHasKey(HilosSignalConstants::STATUS_PUBLISH_NOW, Hilos::getActionAgentRoutes());
        self::assertNotContains(HilosSignalConstants::STATUS_PUBLISH_NOW, StatusAgent::AUTH_ACTIONS);
        self::assertSame(StatusAddUpdateSignalData::class,
            Hilos::getAgentSignalDtoRoutes()[HilosSignalConstants::STATUS_ADD_UPDATE]);
        self::assertArrayNotHasKey(HilosSignalConstants::STATUS_ADD_UPDATE, Hilos::getActionAgentRoutes());
        self::assertNotContains(HilosSignalConstants::STATUS_ADD_UPDATE, StatusAgent::AUTH_ACTIONS);
        self::assertSame([], StatusAgent::AGENT_ACTIONS);
    }

    /** The project inherits Service and merges the live publication-delay setting. */
    public function testStatusComponentsAndSettingsUseFrameworkDefaults(): void
    {
        $components = iterator_to_array(Hilos::statusComponentsOf(Hilos::class));
        self::assertSame(['service'], array_keys($components));
        $service = $components['service'];
        self::assertSame([
            'service', 'Service', null, HilosAgentType::HILOS_INDEX, AgentScope::CLUSTER, AgentPlacement::LEADER,
        ], [
            $service->componentKey, $service->name, $service->description, $service->agentType, $service->scope, $service->placement,
        ]);
        self::assertSame([
            SettingsCatalogConstants::CATALOG_ENTRY_TYPE => SettingsCatalogConstants::TYPE_INTEGER,
            SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE => 300,
            SettingsCatalogConstants::CATALOG_ENTRY_ADMIN_VIEW_VISIBLE => true,
            SettingsCatalogConstants::CATALOG_ENTRY_RULE => NonNegativeIntegerRule::class,
        ], OnlineTestingSettingsCatalog::getCatalog()[StatusSettingsCatalog::PUBLISH_DELAY_SECONDS]);
    }

    /** Status requires its existing machine storage and both project-bound admin pages. */
    public function testStatusRequirementsAndSchemaSelectionAreComplete(): void
    {
        $definition = (new FeatureRegistry())->definition(HilosFeature::STATUS);
        $requirements = $definition->requirements();
        self::assertSame([HilosFeature::DAEMON, HilosFeature::NOTIFICATION_DELIVERY], $requirements->requires);
        self::assertSame([HilosAgentType::HILOS_STATUS], $requirements->requiredAgents);
        self::assertSame([StatusSettingsCatalog::class], $requirements->requiredCatalogFragments);
        self::assertSame(['hilos_status_incident', 'hilos_status_incident_update'], $requirements->requiredDbTables);
        self::assertSame([AbstractHilosStatusPage::class, AbstractHilosStatusComponentsPage::class], $requirements->requiredPages);
        self::assertSame([HilosStatusComponentsTable::class], $requirements->requiredTables);
        self::assertSame([AbstractHilosStatusComponentsPage::class => HilosStatusComponentsTable::class], $requirements->requiredPageTables);
        self::assertTrue($definition->mountsRuntime());

        $statusUnits = [];
        foreach ((new FrameworkSchemaCatalog())->selectedUnits(Hilos::class) as $unit) {
            if (str_starts_with($unit->key, 'status_')) {
                $statusUnits[$unit->key] = [$unit->tables, $unit->dependencies, $unit->connection];
            }
        }
        self::assertSame([
            'status_incident' => [['hilos_status_incident'], [], 0],
            'status_incident_update' => [['hilos_status_incident_update'], ['status_incident'], 0],
        ], $statusUnits);
    }

    /** All three journal pages use the cluster reader and the framework tables. */
    public function testChangeLogSectionIsFullyActivated(): void
    {
        self::assertTrue(Hilos::hasFeature(HilosFeature::CHANGE_LOG));
        foreach ([ChangeLogDashboardPage::class, ChangeLogFeedPage::class, ChangeLogTablesPage::class,
            ChangeLogNoisyPage::class, ChangeLogTablePage::class] as $page) {
            self::assertSame($page, Hilos::PAGES[$page::PAGE]);
            self::assertSame(AgentType::HILOS_CHANGE_LOG, $page::SUBSCRIPTION_AGENT_TYPE);
        }
        self::assertSame([
            AgentRegistryKey::WORKER => DemoHilosChangeLogAgent::class,
            AgentRegistryKey::DAEMON => DemoHilosChangeLogAgentDaemon::class,
        ], Hilos::AGENTS[AgentType::HILOS_CHANGE_LOG]);
        self::assertSame(AgentScope::CLUSTER, AgentRegistry::scope(Hilos::AGENTS[AgentType::HILOS_CHANGE_LOG]));
        self::assertTrue((new DemoHilosChangeLogAgentDaemon())->requiresMonopolisticProcess());
        self::assertSame(HilosChangeLogFeedTable::class, Hilos::TABLES[OnlineTestingTableContext::hilosChangeLogFeed]);
        self::assertSame([OnlineTestingTableContext::hilosChangeLogFeed => []], Hilos::PAGE_TABLES[ChangeLogFeedPage::PAGE]);
        self::assertArrayNotHasKey(ChangeLogDashboardPage::PAGE, Hilos::PAGE_TABLES);
        self::assertSame(AgentType::HILOS_CHANGE_LOG, ChangeLogFeedPage::SUBSCRIPTION_AGENT_TYPE);
        self::assertSame(AgentType::HILOS_CHANGE_LOG, ChangeLogNoisyPage::SUBSCRIPTION_AGENT_TYPE);
        self::assertSame(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_FEED,
            ChangeLogFeedPage::BROWSER[BrowserConfigKey::SIGNAL]);
        self::assertSame(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_NOISY,
            ChangeLogNoisyPage::BROWSER[BrowserConfigKey::SIGNAL]);
        self::assertSame([], ChangeLogDashboardPage::ACTIONS);
        self::assertSame(ChangeLogFeedPage::class, Hilos::PAGES[ChangeLogFeedPage::PAGE]);
        self::assertSame(ChangeLogNoisyPage::class, Hilos::PAGES[ChangeLogNoisyPage::PAGE]);
        self::assertSame(HilosChangeLogNoisyColumnsTable::class, Hilos::TABLES[OnlineTestingTableContext::hilosChangeLogNoisyColumns]);
        self::assertSame([OnlineTestingTableContext::hilosChangeLogNoisyColumns => []], Hilos::PAGE_TABLES[ChangeLogNoisyPage::PAGE]);
        self::assertSame(HilosChangeLogTablesTable::class, Hilos::TABLES[OnlineTestingTableContext::hilosChangeLogTables]);
        self::assertSame([OnlineTestingTableContext::hilosChangeLogTables => []], Hilos::PAGE_TABLES[ChangeLogTablesPage::PAGE]);
        self::assertSame(HilosChangeLogHistoryTable::class, Hilos::TABLES[OnlineTestingTableContext::hilosChangeLogHistory]);
        self::assertSame([OnlineTestingTableContext::hilosChangeLogHistory => []], Hilos::PAGE_TABLES[ChangeLogTablePage::PAGE]);
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
            ChangeLogDashboardPage::PAGE => ChangeLogDashboardPage::class,
            ChangeLogFeedPage::PAGE => ChangeLogFeedPage::class,
            ChangeLogNoisyPage::PAGE => ChangeLogNoisyPage::class,
            ChangeLogTablesPage::PAGE => ChangeLogTablesPage::class,
            ChangeLogTablePage::PAGE => ChangeLogTablePage::class,
            MainPage::PAGE => MainPage::class,
            DashboardPage::PAGE => DashboardPage::class,
            AnalyticsPage::PAGE => AnalyticsPage::class,
            BrowserSessionPage::PAGE => BrowserSessionPage::class,
            PersonPage::PAGE => PersonPage::class,
            SettingsPage::PAGE => SettingsPage::class,
            AppearancePage::PAGE => AppearancePage::class,
            I18nPage::PAGE => I18nPage::class,
            LanguagesListPage::PAGE => LanguagesListPage::class,
            CountriesListPage::PAGE => CountriesListPage::class,
            LanguageDetailPage::PAGE => LanguageDetailPage::class,
            LanguageNamesPage::PAGE => LanguageNamesPage::class,
            LanguageLocalesPage::PAGE => LanguageLocalesPage::class,
            CountryDetailPage::PAGE => CountryDetailPage::class,
            CountryNamesPage::PAGE => CountryNamesPage::class,
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
            CommunicationsPage::PAGE => CommunicationsPage::class,
            CommunicationsChannelPage::PAGE => CommunicationsChannelPage::class,
            CommunicationsChannelConfigurationPage::PAGE => CommunicationsChannelConfigurationPage::class,
            CommunicationsChannelFramePage::PAGE => CommunicationsChannelFramePage::class,
            CommunicationsDeliveriesPage::PAGE => CommunicationsDeliveriesPage::class,
            ProfileNotificationsPage::PAGE => ProfileNotificationsPage::class,
            MaintenancePage::PAGE => MaintenancePage::class,
            MaintenanceCirclePage::PAGE => MaintenanceCirclePage::class,
            MaintenanceWordsPage::PAGE => MaintenanceWordsPage::class,
            UsersPage::PAGE => UsersPage::class,
            UserPage::PAGE => UserPage::class,
            AboutPage::PAGE => AboutPage::class,
            TermsPage::PAGE => TermsPage::class,
            PrivacyPage::PAGE => PrivacyPage::class,
            LicensePage::PAGE => LicensePage::class,
            StatusPage::PAGE => StatusPage::class,
            StatusComponentsPage::PAGE => StatusComponentsPage::class,
        ], Hilos::PAGES);
    }

    /** Both addresses keep the table until the view moves, Words binds the words table without actions, while Circle alone owns its mutations. */
    public function testMaintenanceCircleUsesTheIndexAgentAndKeepsTheOverviewTableBinding(): void
    {
        $this->assertSame(MaintenanceCirclePage::class, Hilos::PAGES[HilosPageConstants::HILOS_MAINTENANCE_CIRCLE]);
        $this->assertSame(MaintenanceWordsPage::class, Hilos::PAGES[HilosPageConstants::HILOS_MAINTENANCE_WORDS]);
        $this->assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[MaintenanceCirclePage::PAGE]);
        $this->assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[MaintenanceWordsPage::PAGE]);
        $this->assertSame([], MaintenanceWordsPage::ACTIONS);
        $this->assertSame([
            HilosSignalConstants::MAINTENANCE_CIRCLE_ADD => MaintenanceCircleAddActionDTO::class,
            HilosSignalConstants::MAINTENANCE_CIRCLE_REMOVE => MaintenanceCircleRemoveActionDTO::class,
        ], MaintenanceCirclePage::ACTIONS);
        $this->assertSame([
            HilosSignalConstants::MAINTENANCE_ENABLE => MaintenanceEnableActionDTO::class,
            HilosSignalConstants::MAINTENANCE_DISABLE => MaintenanceDisableActionDTO::class,
            HilosSignalConstants::MAINTENANCE_PASS => MaintenancePassActionDTO::class,
            HilosSignalConstants::MAINTENANCE_RESTORE_PASS => MaintenanceRestorePassActionDTO::class,
            HilosSignalConstants::MAINTENANCE_RESTORE_OPEN => MaintenanceRestoreOpenActionDTO::class,
            HilosSignalConstants::MAINTENANCE_RESTORE_CLOSE => MaintenanceRestoreCloseActionDTO::class,
        ], MaintenancePage::ACTIONS);
        $this->assertSame(
            [OnlineTestingTableContext::hilosVerifierCircle => []],
            Hilos::PAGE_TABLES[MaintenanceCirclePage::PAGE],
        );
        $this->assertSame(
            [OnlineTestingTableContext::hilosMaintenanceWords => []],
            Hilos::PAGE_TABLES[MaintenanceWordsPage::PAGE],
        );
        $this->assertSame(
            [OnlineTestingTableContext::hilosVerifierCircle => []],
            Hilos::PAGE_TABLES[MaintenancePage::PAGE],
        );
    }

    /** Status uses the existing index agent, one read-only window and the ADMIN handover route. */
    public function testStatusPagesTablesActionsAndReplyAreWired(): void
    {
        self::assertSame(StatusPage::class, Hilos::PAGES[HilosPageConstants::HILOS_STATUS]);
        self::assertSame(StatusComponentsPage::class, Hilos::PAGES[HilosPageConstants::HILOS_STATUS_COMPONENTS]);
        self::assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[StatusPage::PAGE]);
        self::assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[StatusComponentsPage::PAGE]);
        self::assertSame(HilosStatusComponentsTable::class, Hilos::TABLES[HilosStatusComponentsTable::TABLE]);
        self::assertSame([HilosStatusComponentsTable::TABLE => []], Hilos::PAGE_TABLES[StatusComponentsPage::PAGE]);
        self::assertArrayNotHasKey(StatusPage::PAGE, Hilos::PAGE_TABLES);
        self::assertSame([], StatusComponentsPage::ACTIONS);
        self::assertSame([
            HilosSignalConstants::HILOS_STATUS_CANCEL_PUBLICATION => HilosStatusIncidentActionDTO::class,
            HilosSignalConstants::HILOS_STATUS_PUBLISH_NOW => HilosStatusIncidentActionDTO::class,
            HilosSignalConstants::HILOS_STATUS_ADD_UPDATE => HilosStatusAddUpdateActionDTO::class,
        ], StatusPage::ACTIONS);
        foreach (StatusPage::ACTIONS as $action => $dto) {
            self::assertSame(StatusPage::PAGE, Hilos::getPageActionRoutes()[$action]);
            self::assertSame(AgentType::HILOS_INDEX, Hilos::getActionAgentRoutes()[$action]);
            self::assertSame($dto, Hilos::getActionDtoRoutes()[$action]);
            self::assertNotContains($action, StatusPage::READING_ACTIONS);
        }
        self::assertSame(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_STATUS, StatusPage::BROWSER[BrowserConfigKey::SIGNAL]);
        self::assertSame(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_STATUS_COMPONENTS,
            StatusComponentsPage::BROWSER[BrowserConfigKey::SIGNAL]);
        self::assertSame(StatusPage::PAGE,
            Hilos::getPageSignalRoutes()[SignalTypeConstants::AGENT_SIGNAL][HilosSignalConstants::HILOS_STATUS_WRITE_DONE]);
        self::assertSame(AgentType::HILOS_INDEX,
            Hilos::getPageSignalAgentRoutes()[SignalTypeConstants::AGENT_SIGNAL][HilosSignalConstants::HILOS_STATUS_WRITE_DONE]);
        self::assertSame(HandoverAnswerSignalData::class,
            Hilos::getPageSignalDtoRoutes()[SignalTypeConstants::AGENT_SIGNAL][HilosSignalConstants::HILOS_STATUS_WRITE_DONE]);
    }

    public function testComputedPageRoutesCoverEveryRegisteredPage(): void
    {
        $this->assertSame(array_keys(Hilos::PAGES), array_keys(Hilos::getPageRoutes()));
    }

    /** The Daemon environment page names the node whose replica serves it. */
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

    public function testAgentRegistryIncludesTheAdminSectionsAndTheClusterProbes(): void
    {
        $this->assertSame([
            AgentType::HILOS_CHANGE_LOG,
            AgentType::ONLINE_TESTING,
            AgentType::HILOS_INDEX,
            AgentType::HILOS_ANALYTICS,
            HilosAgentType::HILOS_ANALYTICS_JOURNAL,
            HilosAgentType::HILOS_ANALYTICS_WRITER,
            AgentType::HILOS_DAEMON,
            AgentType::HILOS_LOGS,
            HilosAgentType::HILOS_DATA_EXPORT,
            HilosAgentType::HILOS_SESSIONS_LIBRARY,
            AgentType::HILOS_NOTIFICATIONS_LIBRARY,
            HilosAgentType::HILOS_SETTINGS_LIBRARY,
            HilosAgentType::HILOS_I18N_LIBRARY,
            HilosAgentType::HILOS_USER,
            HilosAgentType::HILOS_USERS_LIBRARY,
            HilosAgentType::HILOS_MAIL,
            HilosAgentType::HILOS_LOG_STORE,
            HilosAgentType::HILOS_LOG_CARRIER,
            HilosAgentType::HILOS_DAEMON_NODE,
            HilosAgentType::HILOS_DAEMON_COLLECTOR,
            HilosAgentType::HILOS_STATUS,
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

        $this->assertSame(HilosI18nLanguageLocalesTable::class, Hilos::TABLES[OnlineTestingTableContext::hilosI18nLanguageLocales]);
        $this->assertSame([OnlineTestingTableContext::hilosI18nLanguageLocales => []], Hilos::PAGE_TABLES[LanguageLocalesPage::PAGE]);
        $this->assertSame(HilosI18nLanguagesTable::class, Hilos::TABLES[OnlineTestingTableContext::hilosI18nLanguages]);
        $this->assertSame([OnlineTestingTableContext::hilosI18nLanguages => []], Hilos::PAGE_TABLES[LanguagesListPage::PAGE]);
        $this->assertSame(HilosI18nCountriesTable::class, Hilos::TABLES[OnlineTestingTableContext::hilosI18nCountries]);
        $this->assertSame([OnlineTestingTableContext::hilosI18nCountries => []], Hilos::PAGE_TABLES[CountriesListPage::PAGE]);
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
        $this->assertSame(
            [CountryCardBrowserData::DATA => CountryCardBrowserData::BINDING],
            Hilos::PAGE_DATA[CountryNamesPage::PAGE],
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
            CommunicationsPage::class,
            CommunicationsChannelPage::class,
            CommunicationsChannelConfigurationPage::class,
            CommunicationsChannelFramePage::class,
            CommunicationsDeliveriesPage::class,
            MaintenancePage::class,
            MaintenanceCirclePage::class,
            MaintenanceWordsPage::class,
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
                HilosSignalConstants::MAINTENANCE_AGENT_ENABLE => HilosAgentType::HILOS_INDEX,
                HilosSignalConstants::MAINTENANCE_AGENT_DISABLE => HilosAgentType::HILOS_INDEX,
                HilosSignalConstants::MAINTENANCE_AGENT_PASS => HilosAgentType::HILOS_INDEX,
                HilosSignalConstants::ANALYTICS_JOURNAL_APPEND => HilosAgentType::HILOS_ANALYTICS_JOURNAL,
                HilosSignalConstants::ANALYTICS_JOURNAL_READ => HilosAgentType::HILOS_ANALYTICS_JOURNAL,
                HilosSignalConstants::ANALYTICS_JOURNAL_LOADED => HilosAgentType::HILOS_ANALYTICS_JOURNAL,
                HilosSignalConstants::ANALYTICS_JOURNAL_PORTION => HilosAgentType::HILOS_ANALYTICS_WRITER,
                HilosSignalConstants::ANALYTICS_JOURNAL_READY => HilosAgentType::HILOS_ANALYTICS_WRITER,
                HilosSignalConstants::DAEMON_CLUSTER_PICTURE_PORTION => HilosAgentType::HILOS_DAEMON,
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
                HilosSignalConstants::HILOS_USER_BLOCK_WRITE_DONE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_USER_STEP_UP_CREDIT_DONE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_USER_BROWSER_TRUST_REVOKE_DONE => HilosAgentType::HILOS_SESSIONS_LIBRARY,
                HilosSignalConstants::HILOS_NOTIFICATION_EMIT => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
                HilosSignalConstants::HILOS_DELIVERY_RETRY => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
                HilosSignalConstants::HILOS_NOTIFICATION_HANDOVER => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
                HilosSignalConstants::HILOS_PUSH_SUBSCRIPTIONS_GONE => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
                HilosSignalConstants::HILOS_USER_NOTIFICATION_WRITE_DONE => HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
                HilosSignalConstants::HILOS_SETTING_WRITE => HilosAgentType::HILOS_SETTINGS_LIBRARY,
                HilosSignalConstants::HILOS_SETTING_RESET => HilosAgentType::HILOS_SETTINGS_LIBRARY,
                HilosSignalConstants::HILOS_SETTING_DELETE => HilosAgentType::HILOS_SETTINGS_LIBRARY,
                HilosSignalConstants::HILOS_SETTING_PRESET_APPLY => HilosAgentType::HILOS_SETTINGS_LIBRARY,
                HilosSignalConstants::HILOS_SETTING_COMMAND_DONE => HilosAgentType::HILOS_SETTINGS_LIBRARY,
                // Sign-in's own frames (HIL-623): the users library waits for the throttle
                // verdict, and the mail agent and the node-scoped throttle answer on their own
                // names.
                HilosSignalConstants::HILOS_USER_RENAME => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_ADMIN_WRITE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_ADMIN_COMMAND => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_BLOCK_WRITE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_THEME_PICK_WRITE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_TIME_ZONE_WRITE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_LAST_BROWSER_TIME_ZONE_WRITE => HilosAgentType::HILOS_USER,
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
                HilosSignalConstants::HILOS_USER_STEP_UP_RECORD => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_STEP_UP_CREDIT => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_BROWSER_TRUST_REVOKE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_ACCOUNT_DELETION_CANCEL => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_PHOTO_PUT => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_PHOTO_REMOVE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_NOTIFICATION_READ => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_NOTIFICATION_CHANNEL_SET => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_PUSH_UNSUBSCRIBE => HilosAgentType::HILOS_USER,
                HilosSignalConstants::HILOS_USER_PUSH_REMOVE => HilosAgentType::HILOS_USER,
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
                HilosSignalConstants::HILOS_USER_STEP_UP_RECORD_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_ACCOUNT_DELETION_CANCEL_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_PHOTO_PUT_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_USER_PHOTO_REMOVE_DONE => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_PROFILE_PHOTO_PUBLISHED => HilosAgentType::HILOS_USERS_LIBRARY,
                HilosSignalConstants::HILOS_MAIL_DELIVER => HilosAgentType::HILOS_MAIL,
                HilosSignalConstants::HILOS_MAIL_SEND => HilosAgentType::HILOS_MAIL,
                HilosSignalConstants::LOGS_AGENT_READ_LINES => HilosAgentType::HILOS_LOG_STORE,
                HilosSignalConstants::LOGS_AGENT_FOLLOW_START => HilosAgentType::HILOS_LOG_STORE,
                HilosSignalConstants::LOGS_AGENT_FOLLOW_STOP => HilosAgentType::HILOS_LOG_STORE,
                HilosSignalConstants::LOGS_AGENT_TAKEOUT_CONFIRM => HilosAgentType::HILOS_LOG_STORE,
                HilosSignalConstants::LOGS_AGENT_TAKEOUT_UNDO => HilosAgentType::HILOS_LOG_STORE,
                HilosSignalConstants::DAEMON_MASTER_PROCESS_ROSTER => HilosAgentType::HILOS_DAEMON_NODE,
                HilosSignalConstants::DAEMON_MASTER_CRON => HilosAgentType::HILOS_DAEMON_NODE,
                HilosSignalConstants::DAEMON_MASTER_STANDING => HilosAgentType::HILOS_DAEMON_NODE,
                HilosSignalConstants::DAEMON_MASTER_HTTP => HilosAgentType::HILOS_DAEMON_NODE,
                HilosSignalConstants::DAEMON_MASTER_MEASUREMENTS => HilosAgentType::HILOS_DAEMON_NODE,
                HilosSignalConstants::DAEMON_AGENT_CRON => HilosAgentType::HILOS_DAEMON_NODE,
                HilosSignalConstants::DAEMON_NODE_PICTURE_REPORT => HilosAgentType::HILOS_DAEMON_COLLECTOR,
                HilosSignalConstants::DAEMON_PICTURE_WATCH => HilosAgentType::HILOS_DAEMON_COLLECTOR,
                HilosSignalConstants::STATUS_DAEMON_PICTURE_WATCH => HilosAgentType::HILOS_DAEMON_COLLECTOR,
                HilosSignalConstants::STATUS_DAEMON_PICTURE_PORTION => HilosAgentType::HILOS_STATUS,
                HilosSignalConstants::STATUS_CANCEL_PUBLICATION => HilosAgentType::HILOS_STATUS,
                HilosSignalConstants::STATUS_PUBLISH_NOW => HilosAgentType::HILOS_STATUS,
                HilosSignalConstants::STATUS_ADD_UPDATE => HilosAgentType::HILOS_STATUS,
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
            HilosFeature::CHANGE_LOG,
            HilosFeature::ANALYTICS,
                HilosFeature::SETTINGS,
            HilosFeature::I18N,
            HilosFeature::HILOS_USERS,
            HilosFeature::LOGS,
            HilosFeature::DAEMON,
            HilosFeature::STATUS,
            HilosFeature::NOTIFICATIONS,
            HilosFeature::NOTIFICATION_DELIVERY,
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
        $this->assertSame(UserTimeZoneWriteDoneSignalData::class,
            Hilos::getAgentSignalDtoRoutes()[HilosSignalConstants::HILOS_USER_TIME_ZONE_WRITE_DONE]);
    }

    public function testSettingsAndUsersAdminFeaturesAreActivated(): void
    {
        $this->assertSame([
            OnlineTestingTableContext::hilosChangeLogFeed => HilosChangeLogFeedTable::class,
            OnlineTestingTableContext::hilosChangeLogHistory => HilosChangeLogHistoryTable::class,
            OnlineTestingTableContext::hilosChangeLogNoisyColumns => HilosChangeLogNoisyColumnsTable::class,
            OnlineTestingTableContext::hilosChangeLogTables => HilosChangeLogTablesTable::class,
            OnlineTestingTableContext::settings => HilosSettingsTable::class,
            OnlineTestingTableContext::hilosAppearanceSettings => HilosAppearanceSettingsTable::class,
            OnlineTestingTableContext::hilosUsers => HilosUsersTable::class,
            OnlineTestingTableContext::hilosVerifierCircle => HilosVerifierCircleTable::class,
            OnlineTestingTableContext::hilosMaintenanceWords => HilosMaintenanceWordsTable::class,
            OnlineTestingTableContext::hilosLogKeys => HilosLogKeysTable::class,
            OnlineTestingTableContext::hilosLogRotations => HilosLogRotationsTable::class,
            OnlineTestingTableContext::hilosLogWorkers => HilosLogWorkersTable::class,
            OnlineTestingTableContext::hilosDaemonCron => HilosDaemonCronTable::class,
            OnlineTestingTableContext::hilosDaemonWorkers => HilosDaemonWorkersTable::class,
            OnlineTestingTableContext::hilosDaemonEnvKeys => HilosDaemonEnvKeysTable::class,
            OnlineTestingTableContext::hilosDaemonEnvOrphans => HilosDaemonEnvOrphansTable::class,
            OnlineTestingTableContext::hilosDaemonAgents => HilosDaemonAgentsTable::class,
            OnlineTestingTableContext::hilosDaemonEnvMismatch => HilosDaemonEnvMismatchTable::class,
            OnlineTestingTableContext::hilosDaemonHttpRoutes => HilosDaemonHttpRoutesTable::class,
            OnlineTestingTableContext::hilosDaemonPlacement => HilosDaemonPlacementTable::class,
            OnlineTestingTableContext::hilosCommunicationsChannels => HilosCommunicationsChannelsTable::class,
            OnlineTestingTableContext::hilosCommunicationsChannelFields => HilosCommunicationsChannelFieldsTable::class,
            OnlineTestingTableContext::hilosNotificationDeliveries => HilosNotificationDeliveriesTable::class,
            OnlineTestingTableContext::hilosI18nLanguageNames => HilosI18nLanguageNamesTable::class,
            OnlineTestingTableContext::hilosI18nCountryNames => HilosI18nCountryNamesTable::class,
            OnlineTestingTableContext::hilosI18nLanguageLocales => HilosI18nLanguageLocalesTable::class,
            OnlineTestingTableContext::hilosI18nLanguages => HilosI18nLanguagesTable::class,
            OnlineTestingTableContext::hilosI18nCountries => HilosI18nCountriesTable::class,
            OnlineTestingTableContext::hilosAnalyticsActions => HilosAnalyticsActionsTable::class,
            OnlineTestingTableContext::hilosAnalyticsBrowserSessions => HilosAnalyticsBrowserSessionsTable::class,
            HilosStatusComponentsTable::TABLE => HilosStatusComponentsTable::class,
        ], Hilos::TABLES);
        $this->assertSame(
            [HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::class],
            Hilos::BROWSER_TABLES,
        );
        $this->assertSame([
            BrowserSessionPage::PAGE,
            PersonPage::PAGE,
            ChangeLogFeedPage::PAGE,
            ChangeLogTablesPage::PAGE,
            ChangeLogNoisyPage::PAGE,
            ChangeLogTablePage::PAGE,
            SettingsPage::PAGE,
            AppearancePage::PAGE,
            MaintenancePage::PAGE,
            MaintenanceCirclePage::PAGE,
            MaintenanceWordsPage::PAGE,
            LogsKeysPage::PAGE,
            LogsRotationsPage::PAGE,
            LogsWorkersPage::PAGE,
            DaemonCronPage::PAGE,
            DaemonWorkersPage::PAGE,
            DaemonEnvPage::PAGE,
            DaemonEnvOrphansPage::PAGE,
            DaemonPlacementPage::PAGE,
            DaemonAgentsPage::PAGE,
            DaemonEnvMismatchPage::PAGE,
            DaemonHttpServerPage::PAGE,
            CommunicationsPage::PAGE,
            CommunicationsChannelConfigurationPage::PAGE,
            CommunicationsDeliveriesPage::PAGE,
            UsersPage::PAGE,
            UserPage::PAGE,
            LanguagesListPage::PAGE,
            CountriesListPage::PAGE,
            LanguageNamesPage::PAGE,
            LanguageLocalesPage::PAGE,
            CountryNamesPage::PAGE,
            StatusComponentsPage::PAGE,
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
            Hilos::TABLES[OnlineTestingTableContext::hilosAppearanceSettings],
        );
        $this->assertSame(
            [OnlineTestingTableContext::hilosAppearanceSettings => []],
            Hilos::PAGE_TABLES[AppearancePage::PAGE],
        );
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
            [OnlineTestingTableContext::hilosMaintenanceWords => []],
            Hilos::PAGE_TABLES[MaintenanceWordsPage::PAGE],
        );
        $this->assertSame(
            HilosPageConstants::HILOS_MAINTENANCE_CIRCLE,
            Hilos::getPageActionRoutes()[HilosSignalConstants::MAINTENANCE_CIRCLE_ADD],
        );
        $this->assertSame(
            HilosPageConstants::HILOS_MAINTENANCE_CIRCLE,
            Hilos::getPageActionRoutes()[HilosSignalConstants::MAINTENANCE_CIRCLE_REMOVE],
        );
        $this->assertSame(
            HilosPageConstants::HILOS_MAINTENANCE,
            Hilos::getPageActionRoutes()[HilosSignalConstants::MAINTENANCE_ENABLE],
        );
        $this->assertSame(
            HilosPageConstants::HILOS_MAINTENANCE,
            Hilos::getPageActionRoutes()[HilosSignalConstants::MAINTENANCE_DISABLE],
        );
        $this->assertSame(
            HilosPageConstants::HILOS_MAINTENANCE,
            Hilos::getPageActionRoutes()[HilosSignalConstants::MAINTENANCE_PASS],
        );
        foreach ([
            HilosSignalConstants::MAINTENANCE_RESTORE_PASS => MaintenanceRestorePassActionDTO::class,
            HilosSignalConstants::MAINTENANCE_RESTORE_OPEN => MaintenanceRestoreOpenActionDTO::class,
            HilosSignalConstants::MAINTENANCE_RESTORE_CLOSE => MaintenanceRestoreCloseActionDTO::class,
        ] as $action => $dto) {
            $this->assertSame(HilosPageConstants::HILOS_MAINTENANCE, Hilos::getPageActionRoutes()[$action]);
            $this->assertSame(AgentType::HILOS_INDEX, Hilos::getActionAgentRoutes()[$action]);
            $this->assertSame($dto, Hilos::getActionDtoRoutes()[$action]);
        }
    }

    /** Pins the Daemon feature, six page routes and all three agent placements. */
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
        $this->assertSame([OnlineTestingTableContext::hilosDaemonCron => []], Hilos::PAGE_TABLES[DaemonCronPage::PAGE]);
        $this->assertSame([OnlineTestingTableContext::hilosDaemonWorkers => []], Hilos::PAGE_TABLES[DaemonWorkersPage::PAGE]);
        $this->assertSame([
            OnlineTestingTableContext::hilosDaemonEnvKeys => [],
        ], Hilos::PAGE_TABLES[DaemonEnvPage::PAGE]);
        $this->assertSame([
            OnlineTestingTableContext::hilosDaemonEnvOrphans => [],
        ], Hilos::PAGE_TABLES[DaemonEnvOrphansPage::PAGE]);
        $this->assertSame(
            [OnlineTestingTableContext::hilosDaemonPlacement => []],
            Hilos::PAGE_TABLES[DaemonPlacementPage::PAGE],
        );
        $this->assertSame([OnlineTestingTableContext::hilosDaemonAgents => []], Hilos::PAGE_TABLES[DaemonAgentsPage::PAGE]);
        $this->assertSame([
            OnlineTestingTableContext::hilosDaemonEnvMismatch => [],
        ], Hilos::PAGE_TABLES[DaemonEnvMismatchPage::PAGE]);
        $this->assertSame(
            [OnlineTestingTableContext::hilosDaemonHttpRoutes => []],
            Hilos::PAGE_TABLES[DaemonHttpServerPage::PAGE],
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

    public function testCommunicationsAdminFeatureIsActivated(): void
    {
        $this->assertContains(HilosFeature::NOTIFICATION_DELIVERY, Hilos::features());
        $this->assertSame(CommunicationsPage::class, Hilos::PAGES[CommunicationsPage::PAGE]);
        $this->assertSame(CommunicationsChannelPage::class, Hilos::PAGES[CommunicationsChannelPage::PAGE]);
        $this->assertSame(CommunicationsChannelConfigurationPage::class, Hilos::PAGES[CommunicationsChannelConfigurationPage::PAGE]);
        $this->assertSame(CommunicationsDeliveriesPage::class, Hilos::PAGES[CommunicationsDeliveriesPage::PAGE]);
        $this->assertArrayNotHasKey(CommunicationsChannelPage::PAGE, Hilos::PAGE_TABLES);
        $this->assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[CommunicationsChannelConfigurationPage::PAGE]);
        $this->assertSame(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_COMMUNICATIONS_CHANNEL_CONFIGURATION,
            CommunicationsChannelConfigurationPage::BROWSER[BrowserConfigKey::SIGNAL]);
        $this->assertSame([], CommunicationsChannelConfigurationPage::ACTIONS);
        $this->assertSame([], CommunicationsChannelConfigurationPage::SIGNALS);
        $this->assertSame(ProfileNotificationsPage::class, Hilos::PAGES[ProfileNotificationsPage::PAGE]);

        $this->assertSame(AgentType::HILOS_INDEX, CommunicationsPage::SUBSCRIPTION_AGENT_TYPE);
        $this->assertSame(AgentType::HILOS_INDEX, CommunicationsChannelPage::SUBSCRIPTION_AGENT_TYPE);
        $this->assertSame(AgentType::HILOS_INDEX, CommunicationsChannelConfigurationPage::SUBSCRIPTION_AGENT_TYPE);
        $this->assertSame(AgentType::HILOS_INDEX, CommunicationsDeliveriesPage::SUBSCRIPTION_AGENT_TYPE);
        $this->assertSame(AgentType::ONLINE_TESTING, ProfileNotificationsPage::SUBSCRIPTION_AGENT_TYPE);

        $this->assertSame(
            [OnlineTestingTableContext::hilosCommunicationsChannels => []],
            Hilos::PAGE_TABLES[CommunicationsPage::PAGE],
        );
        $this->assertSame(
            [OnlineTestingTableContext::hilosCommunicationsChannelFields => []],
            Hilos::PAGE_TABLES[CommunicationsChannelConfigurationPage::PAGE],
        );
        $this->assertSame(
            [OnlineTestingTableContext::hilosNotificationDeliveries => []],
            Hilos::PAGE_TABLES[CommunicationsDeliveriesPage::PAGE],
        );

        $this->assertSame(
            HilosChannelSettingUpdateActionDTO::class,
            Hilos::getActionDtoRoutes()[HilosSignalConstants::COMMUNICATIONS_CHANNEL_SET],
        );
        $this->assertSame(
            HilosChannelSettingResetActionDTO::class,
            Hilos::getActionDtoRoutes()[HilosSignalConstants::COMMUNICATIONS_CHANNEL_RESET],
        );
        $this->assertSame(
            HilosChannelTestActionDTO::class,
            Hilos::getActionDtoRoutes()[HilosSignalConstants::COMMUNICATIONS_CHANNEL_TEST],
        );
        $this->assertSame(
            HilosDeliveryRetryActionDTO::class,
            Hilos::getActionDtoRoutes()[HilosSignalConstants::COMMUNICATIONS_DELIVERY_RETRY],
        );

        $this->assertSame(
            HandoverAnswerSignalData::class,
            Hilos::getPageSignalDtoRoutes()[SignalTypeConstants::AGENT_SIGNAL]
                [HilosSignalConstants::HILOS_CHANNEL_SETTING_WRITE_DONE],
        );
        $this->assertSame(
            HandoverAnswerSignalData::class,
            Hilos::getPageSignalDtoRoutes()[SignalTypeConstants::AGENT_SIGNAL]
                [HilosSignalConstants::HILOS_DELIVERY_RETRY_DONE],
        );

        foreach ([
            HilosSignalConstants::COMMUNICATIONS_CHANNEL_SET,
            HilosSignalConstants::COMMUNICATIONS_CHANNEL_RESET,
            HilosSignalConstants::COMMUNICATIONS_CHANNEL_TEST,
        ] as $action) {
            $this->assertSame(CommunicationsChannelPage::PAGE, Hilos::getPageActionRoutes()[$action]);
            $this->assertSame(AgentType::HILOS_INDEX, Hilos::getActionAgentRoutes()[$action]);
        }
        $this->assertSame(
            CommunicationsChannelPage::PAGE,
            Hilos::getPageSignalRoutes()[SignalTypeConstants::AGENT_SIGNAL]
                [HilosSignalConstants::HILOS_CHANNEL_SETTING_WRITE_DONE],
        );

        $catalog = OnlineTestingSettingsCatalog::getCatalog();
        $emailKey = DeliveryChannelSettings::enabledKey(MailDeliveryChannel::NAME);
        $this->assertArrayHasKey($emailKey, $catalog);
        $this->assertTrue($catalog[$emailKey][SettingsCatalogConstants::CATALOG_ENTRY_DEFAULT_VALUE]);
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
     * No database ownership receipts remain since HIL-1411; any new receipt fails this test.
     */
    public function testSharedOwnershipDebtDoesNotGrow(): void
    {
        $this->assertSame([], Hilos::SHARED_DB_OWNERS);
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
            Hilos::TABLES[OnlineTestingTableContext::hilosAnalyticsActions],
        );
        $this->assertSame(
            HilosAnalyticsBrowserSessionsTable::class,
            Hilos::TABLES[OnlineTestingTableContext::hilosAnalyticsBrowserSessions],
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
        self::assertSame([OnlineTestingTableContext::hilosAnalyticsActions => []], Hilos::PAGE_TABLES[BrowserSessionPage::PAGE]);
    }

    /** The person's sessions page uses the Analytics owner and its existing sessions table. */
    public function testAnalyticsPersonPageIsWired(): void
    {
        self::assertSame(PersonPage::class, Hilos::PAGES[PersonPage::PAGE]);
        self::assertSame(AgentType::HILOS_ANALYTICS, Hilos::getPageRoutes()[PersonPage::PAGE]);
        self::assertSame([OnlineTestingTableContext::hilosAnalyticsBrowserSessions => []], Hilos::PAGE_TABLES[PersonPage::PAGE]);
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
        $databaseMetadata = [];
        foreach (['configurations', 'databaseBindings'] as $property) {
            $databaseMetadata[$property] = new ReflectionProperty(Database::class, $property)->getValue();
        }
        try {
            // The mounted journal chains need physical schema identities; configuration alone opens no connection.
            Database::configure(database: 'topology_unit');
            Database::configure(index: 1, database: ChangeLogDatabase::configuredName());
            Hilos::$db = new OnlineTestingDbContext();
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

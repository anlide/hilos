<?php

declare(strict_types=1);

namespace Demo\RobloxGameServer\Tests\Unit;

use Demo\RobloxGameServer\Agents\Hilos\DemoHilosAnalyticsAgent;
use Demo\RobloxGameServer\Agents\Hilos\DemoHilosChangeLogAgent;
use Demo\RobloxGameServer\Agents\Hilos\DemoHilosDaemonAgent;
use Demo\RobloxGameServer\Agents\Hilos\DemoHilosLogsAgent;
use Demo\RobloxGameServer\Browser\Data\SelfConnectionBrowserData;
use Demo\RobloxGameServer\Browser\RobloxGameServerBrowserContext;
use Demo\RobloxGameServer\Browser\RobloxGameServerBrowserRef;
use Demo\RobloxGameServer\Constants\AgentType;
use Demo\RobloxGameServer\Core\Agent\Daemon\Hilos\DemoHilosAnalyticsAgentDaemon;
use Demo\RobloxGameServer\Core\Agent\Daemon\Hilos\DemoHilosChangeLogAgentDaemon;
use Demo\RobloxGameServer\Core\Agent\Daemon\Hilos\DemoHilosDaemonAgentDaemon;
use Demo\RobloxGameServer\Core\Agent\Daemon\Hilos\DemoHilosLogsAgentDaemon;
use Demo\RobloxGameServer\Database\RobloxGameServerDbContext;
use Demo\RobloxGameServer\Hilos;
use Demo\RobloxGameServer\Pages\Hilos\AnalyticsPage;
use Demo\RobloxGameServer\Pages\Hilos\Backup\BackupPage;
use Demo\RobloxGameServer\Pages\Hilos\ChangeLog\ChangeLogDashboardPage;
use Demo\RobloxGameServer\Pages\Hilos\ChangeLog\ChangeLogFeedPage;
use Demo\RobloxGameServer\Pages\Hilos\ChangeLog\ChangeLogNoisyPage;
use Demo\RobloxGameServer\Pages\Hilos\ChangeLog\ChangeLogTableColumnsPage;
use Demo\RobloxGameServer\Pages\Hilos\ChangeLog\ChangeLogTableHistoryPage;
use Demo\RobloxGameServer\Pages\Hilos\ChangeLog\ChangeLogTablePage;
use Demo\RobloxGameServer\Pages\Hilos\ChangeLog\ChangeLogTablesPage;
use Demo\RobloxGameServer\Pages\Hilos\ChangeLog\ChangeLogTableTriggersPage;
use Demo\RobloxGameServer\Pages\Hilos\Daemon\DaemonAgentsPage;
use Demo\RobloxGameServer\Pages\Hilos\Daemon\DaemonCronPage;
use Demo\RobloxGameServer\Pages\Hilos\Daemon\DaemonEnvMismatchPage;
use Demo\RobloxGameServer\Pages\Hilos\Daemon\DaemonEnvOrphansPage;
use Demo\RobloxGameServer\Pages\Hilos\Daemon\DaemonEnvPage;
use Demo\RobloxGameServer\Pages\Hilos\Daemon\DaemonHttpServerPage;
use Demo\RobloxGameServer\Pages\Hilos\Daemon\DaemonNodePage;
use Demo\RobloxGameServer\Pages\Hilos\Daemon\DaemonPage;
use Demo\RobloxGameServer\Pages\Hilos\Daemon\DaemonPlacementPage;
use Demo\RobloxGameServer\Pages\Hilos\Daemon\DaemonWebsocketsPage;
use Demo\RobloxGameServer\Pages\Hilos\Daemon\DaemonWorkersPage;
use Demo\RobloxGameServer\Pages\Hilos\Logs\LogsKeysPage;
use Demo\RobloxGameServer\Pages\Hilos\Logs\LogsOverviewPage;
use Demo\RobloxGameServer\Pages\Hilos\Logs\LogsRotationsPage;
use Demo\RobloxGameServer\Pages\Hilos\Logs\LogsSettingsPage;
use Demo\RobloxGameServer\Pages\Hilos\Logs\LogsViewPage;
use Demo\RobloxGameServer\Pages\Hilos\Logs\LogsWorkersPage;
use Demo\RobloxGameServer\Pages\Hilos\Maintenance\MaintenanceCirclePage;
use Demo\RobloxGameServer\Pages\Hilos\Maintenance\MaintenanceNodesPage;
use Demo\RobloxGameServer\Pages\Hilos\Maintenance\MaintenancePage;
use Demo\RobloxGameServer\Pages\Hilos\Maintenance\MaintenanceWordsPage;
use Demo\RobloxGameServer\Pages\Hilos\Moderation\ModerationKindPage;
use Demo\RobloxGameServer\Pages\Hilos\Moderation\ModerationKindRulesPage;
use Demo\RobloxGameServer\Pages\Hilos\Moderation\ModerationPage;
use Demo\RobloxGameServer\Pages\Hilos\ProfileNotificationsPage;
use Demo\RobloxGameServer\Pages\Hilos\ProfilePage;
use Demo\RobloxGameServer\Pages\Hilos\Users\UserPage;
use Demo\RobloxGameServer\Pages\Hilos\Users\UserSessionsPage;
use Demo\RobloxGameServer\Pages\MainPage;
use Demo\RobloxGameServer\Runtime\State\Item\Connection;
use Demo\RobloxGameServer\Runtime\View\Context\RobloxGameServerRtContext;
use Demo\RobloxGameServer\Tables\RobloxGameServerTableContext;
use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\Agent\BackupAgentDaemon;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosPageConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Daemon\DaemonCollectorAgentDaemon;
use Hilos\Core\Agent\Daemon\DaemonNodeAgentDaemon;
use Hilos\Core\Agent\Hilos\DaemonCollectorAgent;
use Hilos\Core\Agent\Hilos\DaemonNodeAgent;
use Hilos\Core\Analytics\AnalyticsJournalAgent;
use Hilos\Core\Analytics\AnalyticsJournalAgentDaemon;
use Hilos\Core\Analytics\AnalyticsWriterAgent;
use Hilos\Core\Analytics\AnalyticsWriterAgentDaemon;
use Hilos\Core\Browser\Config\BrowserConfigKey;
use Hilos\Core\Browser\Config\BrowserDataConfigKey;
use Hilos\Core\Browser\Config\BrowserDataFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserRefKey;
use Hilos\Core\Browser\Config\BrowserRefType;
use Hilos\Core\Browser\Config\BrowserRuntimeParam;
use Hilos\Core\CLI\CliManager;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Group\AbstractGroup;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\Database;
use Hilos\Database\Migration\FrameworkMigrationStartupGuard;
use Hilos\Database\Object\Item\User;
use Hilos\Database\Schema\FrameworkExtensionGuard;
use Hilos\HilosException;
use Hilos\Moderation\Library\ModerationRulesLibraryAgent;
use Hilos\Moderation\Library\ModerationRulesLibraryAgentDaemon;
use Hilos\Moderation\ModerationAgent;
use Hilos\Moderation\ModerationAgentDaemon;
use Hilos\Notification\Delivery\DTO\NotificationDeliveryRetrySignalData;
use Hilos\Pages\Maintenance\DTO\MaintenanceCircleAddActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenanceCircleRemoveActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenanceDisableActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenanceEnableActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenancePassActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenanceRestoreCloseActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenanceRestoreOpenActionDTO;
use Hilos\Pages\Maintenance\DTO\MaintenanceRestorePassActionDTO;
use Hilos\Pages\Profile\HilosProfileIdentitiesBrowserList;
use Hilos\Tables\Analytics\HilosAnalyticsActionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionsTable;
use Hilos\Tables\Backup\HilosBackupHistoryTable;
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
use Hilos\Tables\ProtectedMode\HilosMaintenanceNodesTable;
use Hilos\Tables\ProtectedMode\HilosMaintenanceWordsTable;
use Hilos\Tables\ProtectedMode\HilosVerifierCircleTable;
use Hilos\Tables\Users\HilosUserDetailBrowserTable;
use Hilos\Tables\Users\HilosUserPhotoBrowserTable;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Users\DTO\UserPushSubscribeSignalData;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/** Database-free guards for the Roblox Game Server registries and their framework feature requirements. */
final class RobloxGameServerTopologyRegistryTest extends TestCase
{
    /** Every page key names a concrete page with that same declared identity. */
    public function testPageRegistryEntriesAreConcreteAndConsistent(): void
    {
        self::assertNotEmpty(Hilos::PAGES);
        foreach (Hilos::PAGES as $page => $pageClass) {
            self::assertTrue(is_subclass_of($pageClass, AbstractPage::class));
            self::assertTrue(new ReflectionClass($pageClass)->isInstantiable());
            self::assertSame($page, $pageClass::PAGE);
        }
    }

    /** Derived subscription routes retain the whole page registry. */
    public function testComputedPageRoutesCoverEveryRegisteredPage(): void
    {
        self::assertSame(array_keys(Hilos::PAGES), array_keys(Hilos::getPageRoutes()));
    }

    /** Every group key names a concrete group with that same declared identity. */
    public function testGroupRegistryEntriesAreConcreteAndConsistent(): void
    {
        self::assertNotEmpty(Hilos::GROUPS);
        foreach (Hilos::GROUPS as $group => $groupClass) {
            self::assertTrue(is_subclass_of($groupClass, AbstractGroup::class));
            self::assertTrue(new ReflectionClass($groupClass)->isInstantiable());
            self::assertSame($group, $groupClass::GROUP);
        }
    }

    /** Page and group subscriptions can reach a registered owner. */
    public function testSubscriptionOwnersAreDeclaredAndRegistered(): void
    {
        $pageRoutes = Hilos::getPageRoutes();
        foreach (Hilos::PAGES as $page => $pageClass) {
            self::assertNotSame('', $pageClass::SUBSCRIPTION_AGENT_TYPE, "{$page} must declare a subscription owner");
            self::assertSame($pageClass::SUBSCRIPTION_AGENT_TYPE, $pageRoutes[$page]);
            self::assertArrayHasKey($pageRoutes[$page], Hilos::AGENTS);
        }
        foreach (Hilos::GROUPS as $group => $groupClass) {
            self::assertNotSame('', $groupClass::SUBSCRIPTION_AGENT_TYPE, "{$group} must declare a subscription owner");
            self::assertArrayHasKey($groupClass::SUBSCRIPTION_AGENT_TYPE, Hilos::AGENTS);
        }
    }

    /** Worker and daemon entries are concrete and agree with the worker's agent type. */
    public function testAgentRegistryEntriesAreConcreteAndConsistent(): void
    {
        self::assertNotEmpty(Hilos::AGENTS);
        foreach (Hilos::AGENTS as $agentType => $entry) {
            $workerClass = AgentRegistry::workerClass($entry);
            $daemonClass = AgentRegistry::daemonClass($entry);

            self::assertIsString($workerClass);
            self::assertIsString($daemonClass);
            self::assertTrue(is_subclass_of($workerClass, AbstractAgent::class));
            self::assertTrue(is_subclass_of($daemonClass, AbstractAgentDaemon::class));
            self::assertTrue(new ReflectionClass($workerClass)->isInstantiable());
            self::assertTrue(new ReflectionClass($daemonClass)->isInstantiable());
            self::assertSame($agentType, $workerClass::AGENT_TYPE);
        }
    }

    /** Server tables are concrete; browser tables, lists and data retain their declared keys. */
    public function testTableAndListRegistryEntriesAreConcreteAndConsistent(): void
    {
        self::assertNotEmpty(Hilos::TABLES);
        foreach (Hilos::TABLES as $table => $tableClass) {
            self::assertTrue(is_subclass_of($tableClass, TableDefinition::class));
            self::assertTrue(new ReflectionClass($tableClass)->isInstantiable());
            self::assertIsString($table);
        }
        foreach (Hilos::BROWSER_TABLES as $table => $tableClass) {
            self::assertTrue(new ReflectionClass($tableClass)->isInstantiable());
            self::assertSame($table, $tableClass::TABLE);
        }
        foreach (Hilos::BROWSER_LISTS as $list => $listClass) {
            self::assertTrue(new ReflectionClass($listClass)->isInstantiable());
            self::assertSame($list, $listClass::LIST);
        }
        foreach (Hilos::BROWSER_DATA as $data => $dataClass) {
            self::assertTrue(new ReflectionClass($dataClass)->isInstantiable());
            self::assertSame($data, $dataClass::DATA);
        }
    }

    /** The project entry page belongs to its own agent type. */
    public function testMainPageIsOwnedByTheAppAgent(): void
    {
        self::assertSame('main', MainPage::PAGE);
        self::assertSame(MainPage::class, Hilos::PAGES[MainPage::PAGE]);
        self::assertSame('roblox_game_server', AgentType::ROBLOX_GAME_SERVER);
        self::assertSame(AgentType::ROBLOX_GAME_SERVER, Hilos::getPageRoutes()[MainPage::PAGE]);
    }

    /** The profile root and its notifications page carry the identity list; the root carries its own connection too. */
    public function testProfileCarriersAreRegisteredAndBoundToTheirConsumers(): void
    {
        self::assertSame(SelfConnectionBrowserData::class, Hilos::BROWSER_DATA[SelfConnectionBrowserData::DATA]);
        self::assertSame([ProfilePage::PAGE], array_keys(Hilos::PAGE_DATA));
        self::assertSame(
            SelfConnectionBrowserData::BINDING,
            Hilos::PAGE_DATA[ProfilePage::PAGE][SelfConnectionBrowserData::DATA],
        );
        foreach ([ProfilePage::class, ProfileNotificationsPage::class] as $pageClass) {
            self::assertSame($pageClass, Hilos::PAGES[$pageClass::PAGE]);
            self::assertSame(AgentType::ROBLOX_GAME_SERVER, Hilos::getPageRoutes()[$pageClass::PAGE]);
            self::assertSame(
                HilosProfileIdentitiesBrowserList::BINDING,
                Hilos::PAGE_LISTS[$pageClass::PAGE][HilosProfileIdentitiesBrowserList::LIST],
            );
        }

        $browser = new RobloxGameServerBrowserContext();
        $browser->bindHilosFacade(Hilos::class);
        self::assertContains(RobloxGameServerDbContext::users, $browser->dbSourceKeysOfPage(ProfilePage::PAGE));
        self::assertSame([RobloxGameServerRtContext::connections], $browser->rtSourceKeysOfPage(ProfilePage::PAGE));
    }

    /** The current connection resolves the person from the server's accept key and selects only safe fields. */
    public function testSelfConnectionIsSelfAnchoredAndSelectsOnlyBrowserSafeFields(): void
    {
        self::assertSame([BrowserRefKey::TYPE => BrowserRefType::ACCEPT_KEY], RobloxGameServerBrowserRef::ACCEPT_KEY);
        self::assertSame([
            BrowserRefKey::TYPE => BrowserRefType::TABLE_PARAM,
            BrowserRefKey::KEY => BrowserRuntimeParam::ACCEPT_KEY,
        ], RobloxGameServerBrowserRef::TABLE_ACCEPT_KEY);
        self::assertSame([
            BrowserParamKey::PARAMS => [BrowserRuntimeParam::ACCEPT_KEY => RobloxGameServerBrowserRef::ACCEPT_KEY],
        ], SelfConnectionBrowserData::BINDING);

        $selfRows = SelfConnectionBrowserData::BROWSER[BrowserDataConfigKey::ROWS];
        self::assertSame(
            [Connection::acceptKey => RobloxGameServerBrowserRef::TABLE_ACCEPT_KEY],
            $selfRows[0][BrowserDataFieldKey::WHERE],
        );
        self::assertSame([User::id => Connection::userId], $selfRows[1][BrowserDataFieldKey::VIA]);
        self::assertSame([
            [Connection::acceptKey, Connection::userId, Connection::connectedAt, Connection::sessionId],
            [User::id, User::name],
        ], array_column($selfRows, BrowserDataFieldKey::FIELDS));
        self::assertSame([User::name], $selfRows[1][BrowserDataFieldKey::TRIGGERS]);
    }

    /** A person's card carries the photo table beside the detail table, as the profile photo feature requires. */
    public function testThePersonsCardCarriesTheProfilePhoto(): void
    {
        self::assertSame(HilosUserPhotoBrowserTable::class, Hilos::BROWSER_TABLES[HilosUserPhotoBrowserTable::TABLE]);
        foreach ([UserPage::PAGE, UserSessionsPage::PAGE] as $page) {
            self::assertSame([
                HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::BINDING,
                HilosUserPhotoBrowserTable::TABLE => HilosUserPhotoBrowserTable::BINDING,
            ], Hilos::PAGE_TABLES[$page]);
        }
    }

    /** All journal pages use the cluster reader and the framework tables. */
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
        self::assertArrayNotHasKey(ChangeLogDashboardPage::PAGE, Hilos::PAGE_TABLES);
        self::assertArrayNotHasKey(ChangeLogTablePage::PAGE, Hilos::PAGE_TABLES);
        self::assertSame([], ChangeLogDashboardPage::ACTIONS);
        self::assertSame(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_FEED,
            ChangeLogFeedPage::BROWSER[BrowserConfigKey::SIGNAL]);
        self::assertSame(HilosSignalConstants::SUBSCRIPTION_PAGE_HILOS_CHANGE_LOG_NOISY,
            ChangeLogNoisyPage::BROWSER[BrowserConfigKey::SIGNAL]);
        foreach ([
            ChangeLogFeedPage::PAGE => [RobloxGameServerTableContext::hilosChangeLogFeed, HilosChangeLogFeedTable::class],
            ChangeLogNoisyPage::PAGE => [
                RobloxGameServerTableContext::hilosChangeLogNoisyColumns,
                HilosChangeLogNoisyColumnsTable::class,
            ],
            ChangeLogTablesPage::PAGE => [RobloxGameServerTableContext::hilosChangeLogTables, HilosChangeLogTablesTable::class],
            ChangeLogTableHistoryPage::PAGE => [
                RobloxGameServerTableContext::hilosChangeLogHistory,
                HilosChangeLogHistoryTable::class,
            ],
            ChangeLogTableColumnsPage::PAGE => [
                RobloxGameServerTableContext::hilosChangeLogTableColumns,
                HilosChangeLogTableColumnsTable::class,
            ],
            ChangeLogTableTriggersPage::PAGE => [
                RobloxGameServerTableContext::hilosChangeLogTableTriggers,
                HilosChangeLogTableTriggersTable::class,
            ],
        ] as $page => [$table, $tableClass]) {
            self::assertSame($tableClass, Hilos::TABLES[$table]);
            self::assertSame([$table => []], Hilos::PAGE_TABLES[$page]);
        }
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
    }

    /** Backup is configure-only: the page on the index agent, the policy-placed agent pair and its archive table. */
    public function testBackupAdminFeatureIsActivated(): void
    {
        self::assertSame(BackupPage::class, Hilos::PAGES[BackupPage::PAGE]);
        self::assertSame(AgentType::HILOS_INDEX, BackupPage::SUBSCRIPTION_AGENT_TYPE);
        $entry = Hilos::AGENTS[HilosAgentType::HILOS_BACKUP];
        self::assertSame(BackupAgent::class, AgentRegistry::workerClass($entry));
        self::assertSame(BackupAgentDaemon::class, AgentRegistry::daemonClass($entry));
        // Placed by policy, not hosted by the leader: the agent owns a directory on one node's
        // disk, and following leadership would move it away from its archives.
        self::assertSame(AgentPlacement::POLICY, AgentRegistry::placement($entry));
        self::assertSame(HilosBackupHistoryTable::class, Hilos::TABLES[RobloxGameServerTableContext::hilosBackups]);
        self::assertSame([RobloxGameServerTableContext::hilosBackups => []], Hilos::PAGE_TABLES[BackupPage::PAGE]);
    }

    /** Maintenance has no feature switch: its pages, tables and actions activate it. */
    public function testMaintenanceSectionIsActivated(): void
    {
        foreach ([MaintenancePage::class, MaintenanceCirclePage::class, MaintenanceWordsPage::class,
            MaintenanceNodesPage::class] as $page) {
            self::assertSame($page, Hilos::PAGES[$page::PAGE]);
            self::assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[$page::PAGE]);
        }
        foreach ([
            MaintenanceCirclePage::PAGE => [RobloxGameServerTableContext::hilosVerifierCircle, HilosVerifierCircleTable::class],
            MaintenanceWordsPage::PAGE => [RobloxGameServerTableContext::hilosMaintenanceWords, HilosMaintenanceWordsTable::class],
            MaintenanceNodesPage::PAGE => [RobloxGameServerTableContext::hilosMaintenanceNodes, HilosMaintenanceNodesTable::class],
        ] as $page => [$table, $tableClass]) {
            self::assertSame($tableClass, Hilos::TABLES[$table]);
            self::assertSame([$table => []], Hilos::PAGE_TABLES[$page]);
        }
        self::assertArrayNotHasKey(MaintenancePage::PAGE, Hilos::PAGE_TABLES);
        self::assertSame([], MaintenanceWordsPage::ACTIONS);
        self::assertSame([], MaintenanceNodesPage::ACTIONS);
        self::assertSame([
            HilosSignalConstants::MAINTENANCE_CIRCLE_ADD => MaintenanceCircleAddActionDTO::class,
            HilosSignalConstants::MAINTENANCE_CIRCLE_REMOVE => MaintenanceCircleRemoveActionDTO::class,
        ], MaintenanceCirclePage::ACTIONS);
        $overviewActions = [
            HilosSignalConstants::MAINTENANCE_ENABLE => MaintenanceEnableActionDTO::class,
            HilosSignalConstants::MAINTENANCE_DISABLE => MaintenanceDisableActionDTO::class,
            HilosSignalConstants::MAINTENANCE_PASS => MaintenancePassActionDTO::class,
            HilosSignalConstants::MAINTENANCE_RESTORE_PASS => MaintenanceRestorePassActionDTO::class,
            HilosSignalConstants::MAINTENANCE_RESTORE_OPEN => MaintenanceRestoreOpenActionDTO::class,
            HilosSignalConstants::MAINTENANCE_RESTORE_CLOSE => MaintenanceRestoreCloseActionDTO::class,
        ];
        self::assertSame($overviewActions, MaintenancePage::ACTIONS);
        foreach ([HilosSignalConstants::MAINTENANCE_CIRCLE_ADD, HilosSignalConstants::MAINTENANCE_CIRCLE_REMOVE] as $action) {
            self::assertSame(HilosPageConstants::HILOS_MAINTENANCE_CIRCLE, Hilos::getPageActionRoutes()[$action]);
        }
        foreach ($overviewActions as $action => $dto) {
            self::assertSame(HilosPageConstants::HILOS_MAINTENANCE, Hilos::getPageActionRoutes()[$action]);
            self::assertSame(AgentType::HILOS_INDEX, Hilos::getActionAgentRoutes()[$action]);
            self::assertSame($dto, Hilos::getActionDtoRoutes()[$action]);
        }
    }

    /** Pins the Daemon feature, its page routes and all three agent placements. */
    public function testDaemonAdminFeatureIsActivated(): void
    {
        self::assertContains(HilosFeature::DAEMON, Hilos::features());
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
            self::assertSame($page, Hilos::PAGES[$page::PAGE]);
            self::assertSame(AgentType::HILOS_DAEMON, Hilos::getPageRoutes()[$page::PAGE]);
        }
        self::assertArrayNotHasKey(DaemonNodePage::PAGE, Hilos::PAGE_TABLES);
        self::assertArrayNotHasKey(DaemonPage::PAGE, Hilos::PAGE_TABLES);
        self::assertSame(DaemonEnvPage::class, Hilos::PAGES[DaemonEnvPage::PAGE]);
        self::assertSame(HilosAgentType::HILOS_DAEMON_NODE, Hilos::getPageRoutes()[DaemonEnvPage::PAGE]);
        foreach ([
            DaemonCronPage::PAGE => [RobloxGameServerTableContext::hilosDaemonCron, HilosDaemonCronTable::class],
            DaemonWorkersPage::PAGE => [RobloxGameServerTableContext::hilosDaemonWorkers, HilosDaemonWorkersTable::class],
            DaemonEnvPage::PAGE => [RobloxGameServerTableContext::hilosDaemonEnvKeys, HilosDaemonEnvKeysTable::class],
            DaemonEnvOrphansPage::PAGE => [RobloxGameServerTableContext::hilosDaemonEnvOrphans, HilosDaemonEnvOrphansTable::class],
            DaemonPlacementPage::PAGE => [RobloxGameServerTableContext::hilosDaemonPlacement, HilosDaemonPlacementTable::class],
            DaemonAgentsPage::PAGE => [RobloxGameServerTableContext::hilosDaemonAgents, HilosDaemonAgentsTable::class],
            DaemonEnvMismatchPage::PAGE => [
                RobloxGameServerTableContext::hilosDaemonEnvMismatch,
                HilosDaemonEnvMismatchTable::class,
            ],
            DaemonHttpServerPage::PAGE => [RobloxGameServerTableContext::hilosDaemonHttpRoutes, HilosDaemonHttpRoutesTable::class],
        ] as $page => [$table, $tableClass]) {
            self::assertSame($tableClass, Hilos::TABLES[$table]);
            self::assertSame([$table => []], Hilos::PAGE_TABLES[$page]);
        }

        $pageAgent = Hilos::AGENTS[AgentType::HILOS_DAEMON];
        self::assertSame(DemoHilosDaemonAgent::class, AgentRegistry::workerClass($pageAgent));
        self::assertSame(DemoHilosDaemonAgentDaemon::class, AgentRegistry::daemonClass($pageAgent));
        self::assertSame(AgentScope::CLUSTER, AgentRegistry::scope($pageAgent));
        self::assertSame(AgentPlacement::LEADER, AgentRegistry::placement($pageAgent));
        self::assertFalse((new DemoHilosDaemonAgentDaemon())->requiresMonopolisticProcess());

        $nodeAgent = Hilos::AGENTS[HilosAgentType::HILOS_DAEMON_NODE];
        self::assertSame(DaemonNodeAgent::class, AgentRegistry::workerClass($nodeAgent));
        self::assertSame(DaemonNodeAgentDaemon::class, AgentRegistry::daemonClass($nodeAgent));
        self::assertSame(AgentScope::NODE, AgentRegistry::scope($nodeAgent));
        self::assertFalse((new DaemonNodeAgentDaemon())->requiresMonopolisticProcess());

        $collector = Hilos::AGENTS[HilosAgentType::HILOS_DAEMON_COLLECTOR];
        self::assertSame(DaemonCollectorAgent::class, AgentRegistry::workerClass($collector));
        self::assertSame(DaemonCollectorAgentDaemon::class, AgentRegistry::daemonClass($collector));
        self::assertSame(AgentPlacement::POLICY, AgentRegistry::placement($collector));
        self::assertFalse((new DaemonCollectorAgentDaemon())->requiresMonopolisticProcess());
    }

    /** The six Logs pages on the section agent, with their browser tables. */
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
        self::assertSame($logPages, array_intersect_key(Hilos::PAGES, $logPages));
        foreach ($logPages as $page) {
            self::assertSame(AgentType::HILOS_LOGS, $page::SUBSCRIPTION_AGENT_TYPE);
        }
        self::assertSame(DemoHilosLogsAgent::class, AgentRegistry::workerClass(Hilos::AGENTS[AgentType::HILOS_LOGS]));
        self::assertSame(DemoHilosLogsAgentDaemon::class, AgentRegistry::daemonClass(Hilos::AGENTS[AgentType::HILOS_LOGS]));
        self::assertTrue((new DemoHilosLogsAgentDaemon())->requiresMonopolisticProcess());
        foreach ([
            LogsOverviewPage::PAGE => [RobloxGameServerTableContext::hilosLogNodes, HilosLogNodesTable::class],
            LogsKeysPage::PAGE => [RobloxGameServerTableContext::hilosLogKeys, HilosLogKeysTable::class],
            LogsRotationsPage::PAGE => [RobloxGameServerTableContext::hilosLogRotations, HilosLogRotationsTable::class],
            LogsWorkersPage::PAGE => [RobloxGameServerTableContext::hilosLogWorkers, HilosLogWorkersTable::class],
        ] as $page => [$table, $tableClass]) {
            self::assertSame($tableClass, Hilos::TABLES[$table]);
            self::assertSame([$table => []], Hilos::PAGE_TABLES[$page]);
        }
    }

    /** The Analytics section: its reader on the leader, the journal on every node, the writer placed by policy. */
    public function testAnalyticsFeatureIsActivated(): void
    {
        self::assertContains(HilosFeature::ANALYTICS, Hilos::features());
        self::assertSame(AnalyticsPage::class, Hilos::PAGES[AnalyticsPage::PAGE]);
        self::assertSame(AgentType::HILOS_ANALYTICS, AnalyticsPage::SUBSCRIPTION_AGENT_TYPE);
        self::assertSame(HilosAnalyticsActionsTable::class, Hilos::TABLES[RobloxGameServerTableContext::hilosAnalyticsActions]);
        self::assertSame(
            HilosAnalyticsBrowserSessionsTable::class,
            Hilos::TABLES[RobloxGameServerTableContext::hilosAnalyticsBrowserSessions],
        );

        $reader = Hilos::AGENTS[AgentType::HILOS_ANALYTICS];
        self::assertSame(DemoHilosAnalyticsAgent::class, AgentRegistry::workerClass($reader));
        self::assertSame(DemoHilosAnalyticsAgentDaemon::class, AgentRegistry::daemonClass($reader));
        self::assertTrue((new DemoHilosAnalyticsAgentDaemon())->requiresMonopolisticProcess());
        self::assertSame(AgentPlacement::LEADER, AgentRegistry::placement($reader));

        $journal = Hilos::AGENTS[HilosAgentType::HILOS_ANALYTICS_JOURNAL];
        self::assertSame(AnalyticsJournalAgent::class, AgentRegistry::workerClass($journal));
        self::assertSame(AnalyticsJournalAgentDaemon::class, AgentRegistry::daemonClass($journal));
        self::assertSame(AgentScope::NODE, AgentRegistry::scope($journal));

        $writer = Hilos::AGENTS[HilosAgentType::HILOS_ANALYTICS_WRITER];
        self::assertSame(AnalyticsWriterAgent::class, AgentRegistry::workerClass($writer));
        self::assertSame(AnalyticsWriterAgentDaemon::class, AgentRegistry::daemonClass($writer));
        self::assertSame(AgentPlacement::POLICY, AgentRegistry::placement($writer));
    }

    /** Moderation: the framework moderator and rules library, the three thin pages on the index agent and both tables. */
    public function testModerationSectionIsActivated(): void
    {
        self::assertContains(HilosFeature::MODERATION, Hilos::features());
        foreach ([
            HilosAgentType::HILOS_MODERATOR => [ModerationAgent::class, ModerationAgentDaemon::class],
            HilosAgentType::HILOS_MODERATION_RULES_LIBRARY => [
                ModerationRulesLibraryAgent::class,
                ModerationRulesLibraryAgentDaemon::class,
            ],
        ] as $agentType => [$workerClass, $daemonClass]) {
            self::assertSame($workerClass, AgentRegistry::workerClass(Hilos::AGENTS[$agentType]));
            self::assertSame($daemonClass, AgentRegistry::daemonClass(Hilos::AGENTS[$agentType]));
            self::assertSame(AgentPlacement::POLICY, AgentRegistry::placement(Hilos::AGENTS[$agentType]));
        }
        foreach ([ModerationPage::class, ModerationKindPage::class, ModerationKindRulesPage::class] as $page) {
            self::assertSame($page, Hilos::PAGES[$page::PAGE]);
            self::assertSame(AgentType::HILOS_INDEX, Hilos::getPageRoutes()[$page::PAGE]);
        }
        self::assertSame(HilosModerationKindsTable::class, Hilos::TABLES[RobloxGameServerTableContext::hilosModerationKinds]);
        self::assertSame(HilosModerationRulesTable::class, Hilos::TABLES[RobloxGameServerTableContext::hilosModerationRules]);
        self::assertSame([RobloxGameServerTableContext::hilosModerationKinds => []], Hilos::PAGE_TABLES[ModerationPage::PAGE]);
        self::assertSame([RobloxGameServerTableContext::hilosModerationKinds => []], Hilos::PAGE_TABLES[ModerationKindPage::PAGE]);
        self::assertSame([
            RobloxGameServerTableContext::hilosModerationKinds => [],
            RobloxGameServerTableContext::hilosModerationRules => [],
        ], Hilos::PAGE_TABLES[ModerationKindRulesPage::PAGE]);
    }

    /** The independent ceiling permits only prescribed DB transitions to retire; startup checks the exact vector. */
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
            'pushSubscriptions' => ['HIL-1906', 'HIL-1952'],
            'secondFactorBackupCodes' => ['HIL-1904', 'HIL-1952', 'HIL-1999'],
            'secondFactorResets' => ['HIL-1952', 'HIL-1999'],
            'secondFactorSettings' => ['HIL-1952', 'HIL-1999'],
            'secondFactorTrusts' => ['HIL-1907', 'HIL-1908', 'HIL-1909', 'HIL-1952', 'HIL-1999'],
            'secondFactors' => ['HIL-1903', 'HIL-1952', 'HIL-1999'],
            'stepUps' => ['HIL-1952'],
            'userPhotos' => ['HIL-1952'],
            'userRenames' => ['HIL-1952'],
            'users' => ['HIL-1952', 'HIL-1999'],
            'verifications' => ['HIL-1952'],
        ];
        foreach (Hilos::SHARED_DB_OWNERS as $collection => $pending) {
            self::assertArrayHasKey($collection, $ceiling);
            self::assertNotEmpty($pending);
            self::assertSame(array_values(array_intersect($ceiling[$collection], $pending)), $pending);
        }
        Hilos::validateTopology();
        self::assertSame([], Hilos::SHARED_RT_OWNERS);
    }

    /** Each registered delivery channel's shard owner takes the retry request by shard key with the retry payload. */
    public function testDeliveryRetryRoutesBelongToTheThreeRegisteredChannels(): void
    {
        $owners = [
            HilosSignalConstants::HILOS_MAIL_DELIVERY_RETRY => HilosAgentType::HILOS_MAIL,
            HilosSignalConstants::HILOS_SMS_DELIVERY_RETRY => HilosAgentType::HILOS_SMS,
            HilosSignalConstants::HILOS_PUSH_DELIVERY_RETRY => HilosAgentType::HILOS_PUSH,
        ];
        foreach ($owners as $signal => $owner) {
            self::assertSame($owner, Hilos::getAgentSignalRoutes()[$signal]);
            self::assertSame(NotificationDeliveryRetrySignalData::shardKey, Hilos::getAgentSignalIndexFields()[$signal]);
            self::assertSame(NotificationDeliveryRetrySignalData::class, Hilos::getAgentSignalDtoRoutes()[$signal]);
        }
    }

    /** Startup validates this project's real registry rather than a synthetic facade. */
    public function testProjectTopologyPassesStartupValidation(): void
    {
        self::assertSame(HilosAgentType::HILOS_USER, Hilos::getAgentSignalRoutes()[HilosSignalConstants::HILOS_USER_PUSH_SUBSCRIBE]);
        self::assertSame('userId', Hilos::getAgentSignalIndexFields()[HilosSignalConstants::HILOS_USER_PUSH_SUBSCRIBE]);
        self::assertSame(
            UserPushSubscribeSignalData::class,
            Hilos::getAgentSignalDtoRoutes()[HilosSignalConstants::HILOS_USER_PUSH_SUBSCRIBE],
        );
        self::assertSame(['HIL-1906', 'HIL-1952'], Hilos::SHARED_DB_OWNERS[HilosDbContext::pushSubscriptions]);
        Hilos::validateTopology();

        $this->addToAssertionCount(1);
    }

    /** Every declared feature has its required project bindings. */
    public function testDeclaredFeaturesAreFullyActivated(): void
    {
        Hilos::validateFeatureActivation();

        $this->addToAssertionCount(1);
    }

    /** Migrations, CLI commands and runtime presence satisfy the deferred feature obligations. */
    public function testDeclaredFeaturesHaveWhatStartupCannotCheck(): void
    {
        Hilos::validateDeferredFeatureRequirements(
            __DIR__ . '/../../backend/Database/Migration/Schema',
            CliManager::class,
            RobloxGameServerRtContext::class,
        );

        $this->addToAssertionCount(1);
    }

    /** Verified source pairs cover the units and immutable changes selected by this project. */
    public function testFrameworkMigrationSourcesCoverSelectedUnits(): void
    {
        try {
            FrameworkMigrationStartupGuard::prepare(Hilos::class, dirname(__DIR__, 2));

            $this->addToAssertionCount(1);
        } finally {
            FrameworkMigrationStartupGuard::resetForTests();
        }
    }

    /**
     * @throws HilosException When the context refuses to configure, or the guard refuses a chain under a framework key
     */
    public function testFrameworkExtensionsAreWhole(): void
    {
        // The question the daemon asks first on its start, over this project's context and
        // without a database: configure() reads nothing. No framework key is extended here, so
        // every key passes as the framework's own.
        $previous = Hilos::$db;
        $databaseMetadata = [];
        foreach (['configurations', 'databaseBindings'] as $property) {
            $databaseMetadata[$property] = new ReflectionProperty(Database::class, $property)->getValue();
        }
        try {
            // The mounted journal chains need physical schema identities; configuration alone opens no connection.
            Database::configure(database: 'topology_unit');
            Database::configure(index: 1, database: ChangeLogDatabase::configuredName());
            Hilos::$db = new RobloxGameServerDbContext();
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

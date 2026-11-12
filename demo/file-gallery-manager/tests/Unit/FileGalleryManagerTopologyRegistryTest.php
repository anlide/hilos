<?php

declare(strict_types=1);

namespace Demo\FileGalleryManager\Tests\Unit;

use Demo\FileGalleryManager\Constants\AgentType;
use Demo\FileGalleryManager\Hilos;
use Demo\FileGalleryManager\Pages\Hilos\AboutPage;
use Demo\FileGalleryManager\Pages\Hilos\Analytics\BrowserSessionPage;
use Demo\FileGalleryManager\Pages\Hilos\Analytics\BrowserSessionsPage;
use Demo\FileGalleryManager\Pages\Hilos\Analytics\PeoplePage;
use Demo\FileGalleryManager\Pages\Hilos\Analytics\PersonPage;
use Demo\FileGalleryManager\Pages\Hilos\AnalyticsPage;
use Demo\FileGalleryManager\Pages\Hilos\ChangeLog\ChangeLogDashboardPage;
use Demo\FileGalleryManager\Pages\Hilos\ChangeLog\ChangeLogFeedPage;
use Demo\FileGalleryManager\Pages\Hilos\ChangeLog\ChangeLogNoisyPage;
use Demo\FileGalleryManager\Pages\Hilos\ChangeLog\ChangeLogTableColumnsPage;
use Demo\FileGalleryManager\Pages\Hilos\ChangeLog\ChangeLogTableHistoryPage;
use Demo\FileGalleryManager\Pages\Hilos\ChangeLog\ChangeLogTablePage;
use Demo\FileGalleryManager\Pages\Hilos\ChangeLog\ChangeLogTablesPage;
use Demo\FileGalleryManager\Pages\Hilos\ChangeLog\ChangeLogTableTriggersPage;
use Demo\FileGalleryManager\Pages\Hilos\Communications\CommunicationsChannelConfigurationPage;
use Demo\FileGalleryManager\Pages\Hilos\Communications\CommunicationsChannelFramePage;
use Demo\FileGalleryManager\Pages\Hilos\Communications\CommunicationsChannelPage;
use Demo\FileGalleryManager\Pages\Hilos\Communications\CommunicationsDeliveriesPage;
use Demo\FileGalleryManager\Pages\Hilos\Communications\CommunicationsPage;
use Demo\FileGalleryManager\Pages\Hilos\Daemon\DaemonAgentsPage;
use Demo\FileGalleryManager\Pages\Hilos\Daemon\DaemonCronPage;
use Demo\FileGalleryManager\Pages\Hilos\Daemon\DaemonEnvMismatchPage;
use Demo\FileGalleryManager\Pages\Hilos\Daemon\DaemonEnvOrphansPage;
use Demo\FileGalleryManager\Pages\Hilos\Daemon\DaemonEnvPage;
use Demo\FileGalleryManager\Pages\Hilos\Daemon\DaemonHttpServerPage;
use Demo\FileGalleryManager\Pages\Hilos\Daemon\DaemonNodePage;
use Demo\FileGalleryManager\Pages\Hilos\Daemon\DaemonPage;
use Demo\FileGalleryManager\Pages\Hilos\Daemon\DaemonPlacementPage;
use Demo\FileGalleryManager\Pages\Hilos\Daemon\DaemonWebsocketsPage;
use Demo\FileGalleryManager\Pages\Hilos\Daemon\DaemonWorkersPage;
use Demo\FileGalleryManager\Pages\Hilos\DashboardPage;
use Demo\FileGalleryManager\Pages\Hilos\I18n\Details\CountryDetailPage;
use Demo\FileGalleryManager\Pages\Hilos\I18n\Details\CountryNamesPage;
use Demo\FileGalleryManager\Pages\Hilos\I18n\Details\LanguageDetailPage;
use Demo\FileGalleryManager\Pages\Hilos\I18n\Details\LanguageLocalesPage;
use Demo\FileGalleryManager\Pages\Hilos\I18n\Details\LanguageNamesPage;
use Demo\FileGalleryManager\Pages\Hilos\I18n\Lists\CountriesListPage;
use Demo\FileGalleryManager\Pages\Hilos\I18n\Lists\LanguagesListPage;
use Demo\FileGalleryManager\Pages\Hilos\I18nPage;
use Demo\FileGalleryManager\Pages\Hilos\LicenseDependenciesPage;
use Demo\FileGalleryManager\Pages\Hilos\LicensePage;
use Demo\FileGalleryManager\Pages\Hilos\Logs\LogsKeysPage;
use Demo\FileGalleryManager\Pages\Hilos\Logs\LogsOverviewPage;
use Demo\FileGalleryManager\Pages\Hilos\Logs\LogsRotationsPage;
use Demo\FileGalleryManager\Pages\Hilos\Logs\LogsSettingsPage;
use Demo\FileGalleryManager\Pages\Hilos\Logs\LogsViewPage;
use Demo\FileGalleryManager\Pages\Hilos\Logs\LogsWorkersPage;
use Demo\FileGalleryManager\Pages\Hilos\PrivacyHistoryPage;
use Demo\FileGalleryManager\Pages\Hilos\PrivacyPage;
use Demo\FileGalleryManager\Pages\Hilos\ProfileNotificationsPage;
use Demo\FileGalleryManager\Pages\Hilos\ProfilePage;
use Demo\FileGalleryManager\Pages\Hilos\SettingsPage;
use Demo\FileGalleryManager\Pages\Hilos\TermsHistoryPage;
use Demo\FileGalleryManager\Pages\Hilos\TermsPage;
use Demo\FileGalleryManager\Pages\Hilos\Users\UserPage;
use Demo\FileGalleryManager\Pages\Hilos\Users\UsersPage;
use Demo\FileGalleryManager\Pages\MainPage;
use Demo\FileGalleryManager\Runtime\View\Context\FileGalleryManagerRtContext;
use Demo\FileGalleryManager\Tables\FileGalleryManagerTableContext;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\CLI\CliManager;
use Hilos\Core\Group\AbstractGroup;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Migration\FrameworkMigrationStartupGuard;
use Hilos\I18n\Browser\CountryCardBrowserData;
use Hilos\I18n\Browser\LanguageCardBrowserData;
use Hilos\Notification\Delivery\DTO\NotificationDeliveryRetrySignalData;
use Hilos\Pages\Profile\HilosProfileIdentitiesBrowserList;
use Hilos\Tables\Users\HilosUserDetailBrowserTable;
use Hilos\Users\DTO\UserPushSubscribeSignalData;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** Database-free guards for the File Gallery Manager registries and their framework feature requirements. */
final class FileGalleryManagerTopologyRegistryTest extends TestCase
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
        self::assertSame('file_gallery_manager', AgentType::FILE_GALLERY_MANAGER);
        self::assertSame(AgentType::FILE_GALLERY_MANAGER, Hilos::getPageRoutes()[MainPage::PAGE]);
    }

    /** The inherited mail shard owner takes the delivery retry request by shard key; no other channel opens here. */
    public function testDeliveryRetryRouteIsOpenOnlyForTheRegisteredMailChannel(): void
    {
        self::assertSame(HilosAgentType::HILOS_MAIL, Hilos::getAgentSignalRoutes()[HilosSignalConstants::HILOS_MAIL_DELIVERY_RETRY]);
        self::assertSame(
            NotificationDeliveryRetrySignalData::shardKey,
            Hilos::getAgentSignalIndexFields()[HilosSignalConstants::HILOS_MAIL_DELIVERY_RETRY],
        );
        self::assertSame(
            NotificationDeliveryRetrySignalData::class,
            Hilos::getAgentSignalDtoRoutes()[HilosSignalConstants::HILOS_MAIL_DELIVERY_RETRY],
        );
        self::assertArrayNotHasKey(HilosSignalConstants::HILOS_SMS_DELIVERY_RETRY, Hilos::getAgentSignalRoutes());
        self::assertArrayNotHasKey(HilosSignalConstants::HILOS_PUSH_DELIVERY_RETRY, Hilos::getAgentSignalRoutes());
    }

    /**
     * Each page is served by the agent that owns its section and binds exactly the tables, cards
     * and lists its screen reads: the carrier's own shell and profile on the project agent, the
     * public and admin pages on the index, every other section on its own framework agent - the
     * node's environment on the agent of the node that answers it.
     */
    public function testPagesAreServedByTheirSectionOwnersWithTheirBindings(): void
    {
        $owners = [
            AgentType::FILE_GALLERY_MANAGER => [
                MainPage::class,
                ProfilePage::class,
                ProfileNotificationsPage::class,
            ],
            AgentType::HILOS_INDEX => [
                DashboardPage::class,
                SettingsPage::class,
                UsersPage::class,
                UserPage::class,
                CommunicationsPage::class,
                CommunicationsChannelPage::class,
                CommunicationsChannelConfigurationPage::class,
                CommunicationsChannelFramePage::class,
                CommunicationsDeliveriesPage::class,
                AboutPage::class,
                TermsPage::class,
                PrivacyPage::class,
                TermsHistoryPage::class,
                PrivacyHistoryPage::class,
                LicensePage::class,
                LicenseDependenciesPage::class,
            ],
            AgentType::HILOS_ANALYTICS => [
                AnalyticsPage::class,
                BrowserSessionsPage::class,
                PeoplePage::class,
                BrowserSessionPage::class,
                PersonPage::class,
            ],
            AgentType::HILOS_CHANGE_LOG => [
                ChangeLogDashboardPage::class,
                ChangeLogFeedPage::class,
                ChangeLogNoisyPage::class,
                ChangeLogTablesPage::class,
                ChangeLogTablePage::class,
                ChangeLogTableColumnsPage::class,
                ChangeLogTableTriggersPage::class,
                ChangeLogTableHistoryPage::class,
            ],
            HilosAgentType::HILOS_I18N_LIBRARY => [
                I18nPage::class,
                LanguagesListPage::class,
                CountriesListPage::class,
                LanguageDetailPage::class,
                LanguageNamesPage::class,
                LanguageLocalesPage::class,
                CountryDetailPage::class,
                CountryNamesPage::class,
            ],
            AgentType::HILOS_DAEMON => [
                DaemonPage::class,
                DaemonPlacementPage::class,
                DaemonNodePage::class,
                DaemonWorkersPage::class,
                DaemonAgentsPage::class,
                DaemonCronPage::class,
                DaemonWebsocketsPage::class,
                DaemonHttpServerPage::class,
                DaemonEnvMismatchPage::class,
            ],
            HilosAgentType::HILOS_DAEMON_NODE => [
                DaemonEnvPage::class,
                DaemonEnvOrphansPage::class,
            ],
            AgentType::HILOS_LOGS => [
                LogsOverviewPage::class,
                LogsKeysPage::class,
                LogsWorkersPage::class,
                LogsRotationsPage::class,
                LogsViewPage::class,
                LogsSettingsPage::class,
            ],
        ];
        $routes = Hilos::getPageRoutes();
        $served = [];
        foreach ($owners as $owner => $pageClasses) {
            foreach ($pageClasses as $pageClass) {
                self::assertSame($owner, $routes[$pageClass::PAGE], $pageClass);
                $served[] = $pageClass;
            }
        }
        $registered = array_values(Hilos::PAGES);
        sort($served);
        sort($registered);
        self::assertSame($registered, $served);

        $tables = [
            TermsHistoryPage::PAGE => [FileGalleryManagerTableContext::hilosTermsHistory => []],
            PrivacyHistoryPage::PAGE => [FileGalleryManagerTableContext::hilosPrivacyHistory => []],
            ChangeLogFeedPage::PAGE => [FileGalleryManagerTableContext::hilosChangeLogFeed => []],
            ChangeLogTablesPage::PAGE => [FileGalleryManagerTableContext::hilosChangeLogTables => []],
            ChangeLogNoisyPage::PAGE => [FileGalleryManagerTableContext::hilosChangeLogNoisyColumns => []],
            ChangeLogTableHistoryPage::PAGE => [FileGalleryManagerTableContext::hilosChangeLogHistory => []],
            ChangeLogTableColumnsPage::PAGE => [FileGalleryManagerTableContext::hilosChangeLogTableColumns => []],
            ChangeLogTableTriggersPage::PAGE => [FileGalleryManagerTableContext::hilosChangeLogTableTriggers => []],
            BrowserSessionPage::PAGE => [FileGalleryManagerTableContext::hilosAnalyticsActions => []],
            PersonPage::PAGE => [FileGalleryManagerTableContext::hilosAnalyticsBrowserSessions => []],
            BrowserSessionsPage::PAGE => [FileGalleryManagerTableContext::hilosAnalyticsBrowserSessionList => []],
            PeoplePage::PAGE => [FileGalleryManagerTableContext::hilosAnalyticsPeople => []],
            SettingsPage::PAGE => [FileGalleryManagerTableContext::settings => []],
            UsersPage::PAGE => [FileGalleryManagerTableContext::hilosUsers => []],
            LanguagesListPage::PAGE => [FileGalleryManagerTableContext::hilosI18nLanguages => []],
            CountriesListPage::PAGE => [FileGalleryManagerTableContext::hilosI18nCountries => []],
            LanguageNamesPage::PAGE => [FileGalleryManagerTableContext::hilosI18nLanguageNames => []],
            LanguageLocalesPage::PAGE => [FileGalleryManagerTableContext::hilosI18nLanguageLocales => []],
            CountryNamesPage::PAGE => [FileGalleryManagerTableContext::hilosI18nCountryNames => []],
            LogsOverviewPage::PAGE => [FileGalleryManagerTableContext::hilosLogNodes => []],
            LogsKeysPage::PAGE => [FileGalleryManagerTableContext::hilosLogKeys => []],
            LogsRotationsPage::PAGE => [FileGalleryManagerTableContext::hilosLogRotations => []],
            LogsWorkersPage::PAGE => [FileGalleryManagerTableContext::hilosLogWorkers => []],
            DaemonCronPage::PAGE => [FileGalleryManagerTableContext::hilosDaemonCron => []],
            DaemonWorkersPage::PAGE => [FileGalleryManagerTableContext::hilosDaemonWorkers => []],
            DaemonEnvPage::PAGE => [FileGalleryManagerTableContext::hilosDaemonEnvKeys => []],
            DaemonEnvOrphansPage::PAGE => [FileGalleryManagerTableContext::hilosDaemonEnvOrphans => []],
            DaemonPlacementPage::PAGE => [FileGalleryManagerTableContext::hilosDaemonPlacement => []],
            DaemonAgentsPage::PAGE => [FileGalleryManagerTableContext::hilosDaemonAgents => []],
            DaemonEnvMismatchPage::PAGE => [FileGalleryManagerTableContext::hilosDaemonEnvMismatch => []],
            DaemonHttpServerPage::PAGE => [FileGalleryManagerTableContext::hilosDaemonHttpRoutes => []],
            CommunicationsPage::PAGE => [FileGalleryManagerTableContext::hilosCommunicationsChannels => []],
            CommunicationsChannelConfigurationPage::PAGE => [FileGalleryManagerTableContext::hilosCommunicationsChannelFields => []],
            CommunicationsDeliveriesPage::PAGE => [FileGalleryManagerTableContext::hilosNotificationDeliveries => []],
            UserPage::PAGE => [HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::BINDING],
        ];
        self::assertSame(self::byPage($tables), self::byPage(Hilos::PAGE_TABLES));

        $data = [
            LanguageDetailPage::PAGE => [LanguageCardBrowserData::DATA => LanguageCardBrowserData::BINDING],
            LanguageNamesPage::PAGE => [LanguageCardBrowserData::DATA => LanguageCardBrowserData::BINDING],
            LanguageLocalesPage::PAGE => [LanguageCardBrowserData::DATA => LanguageCardBrowserData::BINDING],
            CountryDetailPage::PAGE => [CountryCardBrowserData::DATA => CountryCardBrowserData::BINDING],
            CountryNamesPage::PAGE => [CountryCardBrowserData::DATA => CountryCardBrowserData::BINDING],
        ];
        self::assertSame(self::byPage($data), self::byPage(Hilos::PAGE_DATA));

        $lists = [
            ProfilePage::PAGE => [HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::BINDING],
            ProfileNotificationsPage::PAGE => [HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::BINDING],
        ];
        self::assertSame(self::byPage($lists), self::byPage(Hilos::PAGE_LISTS));
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
            FileGalleryManagerRtContext::class,
        );

        $this->addToAssertionCount(1);
    }

    /** Verified source pairs cover the units and immutable changes selected by File Gallery Manager. */
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
     * Orders a page binding map by page and, within a page, by binding key, so the comparison
     * reads what is bound and not the order the registry declares it in.
     *
     * @param array<string, array<string, mixed>> $bindings Page key to its bindings by key
     * @return array<string, array<string, mixed>> The same bindings in key order
     */
    private static function byPage(array $bindings): array
    {
        ksort($bindings);
        foreach ($bindings as &$bound) {
            ksort($bound);
        }

        return $bindings;
    }
}

<?php

declare(strict_types=1);

namespace Demo\Pmd\Tests\Unit;

use Demo\Pmd\Browser\Data\SelfConnectionBrowserData;
use Demo\Pmd\Browser\List\ProfileDevicesBrowserList;
use Demo\Pmd\Browser\List\ProfileSessionsBrowserList;
use Demo\Pmd\Browser\PmdBrowserContext;
use Demo\Pmd\Browser\PmdBrowserRef;
use Demo\Pmd\Browser\PmdBrowserSource;
use Demo\Pmd\Constants\AgentType;
use Demo\Pmd\Database\Pages\PageCatalog;
use Demo\Pmd\Database\PmdDbContext;
use Demo\Pmd\Hilos;
use Demo\Pmd\Pages\Hilos\ProfileAgreementsPage;
use Demo\Pmd\Pages\Hilos\ProfileDataPage;
use Demo\Pmd\Pages\Hilos\ProfileDevicesPage;
use Demo\Pmd\Pages\Hilos\ProfileNotificationsPage;
use Demo\Pmd\Pages\Hilos\ProfilePage;
use Demo\Pmd\Pages\Hilos\ProfileSecurityPage;
use Demo\Pmd\Pages\Hilos\ProfileSessionsPage;
use Demo\Pmd\Pages\Hilos\ProfileSignInPage;
use Demo\Pmd\Pages\MainPage;
use Demo\Pmd\Runtime\State\Item\Connection;
use Demo\Pmd\Runtime\View\Context\PmdRtContext;
use Hilos\Constants\HilosPageConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Browser\Config\BrowserDataConfigKey;
use Hilos\Core\Browser\Config\BrowserDataFieldKey;
use Hilos\Core\Browser\Config\BrowserListConfigKey;
use Hilos\Core\Browser\Config\BrowserListFieldKey;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserParamType;
use Hilos\Core\Browser\Config\BrowserRefKey;
use Hilos\Core\Browser\Config\BrowserRefType;
use Hilos\Core\Browser\Config\BrowserRuntimeParam;
use Hilos\Core\CLI\CliManager;
use Hilos\Core\Group\AbstractGroup;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Database\Migration\FrameworkMigrationStartupGuard;
use Hilos\Database\Object\Item\PushSubscription;
use Hilos\Database\Object\Item\Session;
use Hilos\Database\Object\Item\User;
use Hilos\Database\Pages\HilosPageCatalog;
use Hilos\Database\Pages\PageCatalogConstants;
use Hilos\Pages\Profile\HilosProfileIdentitiesBrowserList;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** Database-free guards for the PMD registries and their framework feature requirements. */
final class PmdTopologyRegistryTest extends TestCase
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

    /** The Security section and OAuth provider use the adopted PMD heads. */
    public function testSecurityHeadsAreAdoptedInTheProjectCatalog(): void
    {
        $pages = PageCatalog::pages();

        foreach ([
            HilosPageConstants::HILOS_SECURITY,
            HilosPageConstants::HILOS_SECURITY_OAUTH_PROVIDER,
        ] as $page) {
            self::assertSame(
                array_replace(
                    HilosPageCatalog::CATALOG[$page],
                    [PageCatalogConstants::CATALOG_ENTRY_PAGE_HEAD => true],
                ),
                $pages[$page],
            );
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

    /** Profile consumers retain their identities, sessions, devices and current-connection carriers. */
    public function testProfileCarriersAreRegisteredAndBoundToTheirConsumers(): void
    {
        self::assertSame(SelfConnectionBrowserData::class, Hilos::BROWSER_DATA['selfConnection']);
        self::assertSame(ProfileSessionsBrowserList::class, Hilos::BROWSER_LISTS['profileSessions']);
        self::assertSame(ProfileDevicesBrowserList::class, Hilos::BROWSER_LISTS['profileDevices']);
        self::assertSame([ProfilePage::PAGE, ProfileSessionsPage::PAGE], array_keys(Hilos::PAGE_DATA));

        foreach ([ProfilePage::PAGE, ProfileSessionsPage::PAGE] as $page) {
            self::assertSame(
                SelfConnectionBrowserData::BINDING,
                Hilos::PAGE_DATA[$page][SelfConnectionBrowserData::DATA],
            );
        }
        self::assertSame(
            ProfileSessionsBrowserList::BINDING,
            Hilos::PAGE_LISTS[ProfileSessionsPage::PAGE][ProfileSessionsBrowserList::LIST],
        );
        self::assertSame(
            ProfileDevicesBrowserList::BINDING,
            Hilos::PAGE_LISTS[ProfileDevicesPage::PAGE][ProfileDevicesBrowserList::LIST],
        );
        foreach ([ProfileDevicesPage::class, ProfileAgreementsPage::class] as $pageClass) {
            self::assertSame($pageClass, Hilos::PAGES[$pageClass::PAGE]);
            self::assertSame(AgentType::PMD, Hilos::getPageRoutes()[$pageClass::PAGE]);
        }

        foreach ([
            ProfilePage::PAGE,
            ProfileSignInPage::PAGE,
            ProfileSecurityPage::PAGE,
            ProfileSessionsPage::PAGE,
            ProfileDevicesPage::PAGE,
            ProfileDataPage::PAGE,
            ProfileAgreementsPage::PAGE,
            ProfileNotificationsPage::PAGE,
        ] as $page) {
            self::assertSame(
                HilosProfileIdentitiesBrowserList::BINDING,
                Hilos::PAGE_LISTS[$page][HilosProfileIdentitiesBrowserList::LIST],
            );
        }
    }

    /** The real page bindings subscribe to committed names, durable sessions, devices and live connections. */
    public function testProfileBindingsDeclareTheirDbAndRtSourceInterests(): void
    {
        $browser = new PmdBrowserContext();
        $browser->bindHilosFacade(Hilos::class);

        foreach ([ProfilePage::PAGE, ProfileSessionsPage::PAGE] as $page) {
            self::assertContains(PmdDbContext::users, $browser->dbSourceKeysOfPage($page));
            self::assertSame([PmdRtContext::connections], $browser->rtSourceKeysOfPage($page));
        }
        self::assertContains(PmdDbContext::sessions, $browser->dbSourceKeysOfPage(ProfileSessionsPage::PAGE));
        self::assertContains(PmdDbContext::pushSubscriptions, $browser->dbSourceKeysOfPage(ProfileDevicesPage::PAGE));
        foreach ([ProfileDevicesPage::PAGE, ProfileAgreementsPage::PAGE] as $page) {
            self::assertContains(PmdDbContext::identities, $browser->dbSourceKeysOfPage($page));
            self::assertSame([PmdRtContext::connections], $browser->rtSourceKeysOfPage($page));
        }
    }

    /** Profile carriers resolve the person from the server's accept key and select only safe fields. */
    public function testProfileCarriersAreSelfAnchoredAndSelectOnlyBrowserSafeFields(): void
    {
        self::assertSame([BrowserRefKey::TYPE => BrowserRefType::ACCEPT_KEY], PmdBrowserRef::ACCEPT_KEY);
        self::assertSame([
            BrowserRefKey::TYPE => BrowserRefType::TABLE_PARAM,
            BrowserRefKey::KEY => BrowserRuntimeParam::ACCEPT_KEY,
        ], PmdBrowserRef::TABLE_ACCEPT_KEY);
        foreach ([
            SelfConnectionBrowserData::BINDING,
            ProfileSessionsBrowserList::BINDING,
            ProfileDevicesBrowserList::BINDING,
        ] as $binding) {
            self::assertSame([
                BrowserParamKey::PARAMS => [BrowserRuntimeParam::ACCEPT_KEY => PmdBrowserRef::ACCEPT_KEY],
            ], $binding);
        }

        $selfRows = SelfConnectionBrowserData::BROWSER[BrowserDataConfigKey::ROWS];
        self::assertSame(
            [Connection::acceptKey => PmdBrowserRef::TABLE_ACCEPT_KEY],
            $selfRows[0][BrowserDataFieldKey::WHERE],
        );
        self::assertSame([User::id => Connection::userId], $selfRows[1][BrowserDataFieldKey::VIA]);
        self::assertSame([
            [Connection::acceptKey, Connection::userId, Connection::connectedAt, Connection::sessionId],
            [User::id, User::name],
        ], array_column($selfRows, BrowserDataFieldKey::FIELDS));
        self::assertSame([User::name], $selfRows[1][BrowserDataFieldKey::TRIGGERS]);

        $sessionItems = ProfileSessionsBrowserList::BROWSER[BrowserListConfigKey::ITEMS];
        self::assertSame(
            [Connection::acceptKey => PmdBrowserRef::TABLE_ACCEPT_KEY],
            $sessionItems[0][BrowserListFieldKey::WHERE],
        );
        self::assertSame([Session::userId => Connection::userId], $sessionItems[1][BrowserListFieldKey::VIA]);
        self::assertTrue($sessionItems[1][BrowserListFieldKey::MANY]);
        self::assertArrayNotHasKey(BrowserListFieldKey::TRIGGERS, $sessionItems[1]);
        self::assertSame([
            [Connection::userId],
            [
                Session::id,
                Session::userId,
                Session::deviceName,
                Session::browserTimeZone,
                Session::createdAt,
                Session::lastSeenAt,
                Session::expiresAt,
                Session::impersonatorUserId,
            ],
            [Connection::acceptKey, Connection::sessionId, Connection::connectedAt],
        ], array_column($sessionItems, BrowserListFieldKey::FIELDS));

        self::assertSame([
            BrowserRuntimeParam::ACCEPT_KEY => [
                BrowserParamKey::TYPE => BrowserParamType::STRING,
                BrowserParamKey::REQUIRED => true,
            ],
        ], ProfileDevicesBrowserList::BROWSER[BrowserListConfigKey::PARAMS]);
        self::assertSame([
            PmdBrowserSource::RT_CONNECTIONS,
            PmdBrowserSource::DB_PUSH_SUBSCRIPTIONS,
        ], ProfileDevicesBrowserList::BROWSER[BrowserListConfigKey::SOURCES]);
        $deviceItems = ProfileDevicesBrowserList::BROWSER[BrowserListConfigKey::ITEMS];
        self::assertSame([
            PmdBrowserSource::RT_CONNECTIONS,
            PmdBrowserSource::DB_PUSH_SUBSCRIPTIONS,
        ], array_column($deviceItems, BrowserListFieldKey::SOURCE));
        self::assertSame(
            [Connection::acceptKey => PmdBrowserRef::TABLE_ACCEPT_KEY],
            $deviceItems[0][BrowserListFieldKey::WHERE],
        );
        self::assertSame([Connection::userId], $deviceItems[0][BrowserListFieldKey::TRIGGERS]);
        self::assertSame(
            [Connection::userId, PushSubscription::userId],
            array_column($deviceItems, BrowserListFieldKey::ITEM_KEY),
        );
        self::assertSame([PushSubscription::userId => Connection::userId], $deviceItems[1][BrowserListFieldKey::VIA]);
        self::assertTrue($deviceItems[1][BrowserListFieldKey::MANY]);
        self::assertSame([
            [Connection::userId],
            [
                PushSubscription::id,
                PushSubscription::deviceName,
                PushSubscription::endpointHash,
                PushSubscription::createdAt,
                PushSubscription::goneAt,
            ],
        ], array_column($deviceItems, BrowserListFieldKey::FIELDS));
    }

    /** The project entry page belongs to its own agent type. */
    public function testMainPageIsOwnedByTheAppAgent(): void
    {
        self::assertSame('main', MainPage::PAGE);
        self::assertSame(MainPage::class, Hilos::PAGES[MainPage::PAGE]);
        self::assertSame('pmd', AgentType::PMD);
        self::assertSame(AgentType::PMD, Hilos::getPageRoutes()[MainPage::PAGE]);
    }

    /** Startup validates this project's real registry rather than a synthetic facade. */
    public function testProjectTopologyPassesStartupValidation(): void
    {
        Hilos::validateTopology();

        self::assertSame(['HIL-1910', 'HIL-1952'], Hilos::SHARED_DB_OWNERS['notificationDeliveries']);
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
            PmdRtContext::class,
        );

        $this->addToAssertionCount(1);
    }

    /** Verified source pairs cover the units and immutable changes selected by PMD. */
    public function testFrameworkMigrationSourcesCoverSelectedUnits(): void
    {
        try {
            FrameworkMigrationStartupGuard::prepare(Hilos::class, dirname(__DIR__, 2));

            $this->addToAssertionCount(1);
        } finally {
            FrameworkMigrationStartupGuard::resetForTests();
        }
    }
}

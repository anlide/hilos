<?php

declare(strict_types=1);

namespace Demo\SolarSystemModel\Tests\Unit;

use Demo\SolarSystemModel\Constants\AgentType;
use Demo\SolarSystemModel\Hilos;
use Demo\SolarSystemModel\Pages\MainPage;
use Demo\SolarSystemModel\Runtime\View\Context\SolarSystemModelRtContext;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\CLI\CliManager;
use Hilos\Core\Group\AbstractGroup;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Table\Definition\TableDefinition;
use Hilos\Database\Migration\FrameworkMigrationStartupGuard;
use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Notification\Delivery\DTO\NotificationDeliveryRetrySignalData;
use Hilos\Users\DTO\UserPushReleaseDoneSignalData;
use Hilos\Users\DTO\UserPushReleaseSignalData;
use Hilos\Users\DTO\UserPushSubscribeSignalData;
use Hilos\Users\DTO\UserSecondFactorTrustRestrictSignalData;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** Database-free guards for the Solar System Model registries and their framework feature requirements. */
final class SolarSystemModelTopologyRegistryTest extends TestCase
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
        self::assertSame('solar_system_model', AgentType::SOLAR_SYSTEM_MODEL);
        self::assertSame(AgentType::SOLAR_SYSTEM_MODEL, Hilos::getPageRoutes()[MainPage::PAGE]);
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

    /** Startup validates this project's real registry rather than a synthetic facade. */
    public function testProjectTopologyPassesStartupValidation(): void
    {
        self::assertSame(HilosAgentType::HILOS_USER, Hilos::getAgentSignalRoutes()[HilosSignalConstants::HILOS_USER_PUSH_SUBSCRIBE]);
        self::assertSame('userId', Hilos::getAgentSignalIndexFields()[HilosSignalConstants::HILOS_USER_PUSH_SUBSCRIBE]);
        self::assertSame(
            UserPushSubscribeSignalData::class,
            Hilos::getAgentSignalDtoRoutes()[HilosSignalConstants::HILOS_USER_PUSH_SUBSCRIBE],
        );
        self::assertSame(HilosAgentType::HILOS_USER, Hilos::getAgentSignalRoutes()[HilosSignalConstants::HILOS_USER_PUSH_RELEASE]);
        self::assertSame('userId', Hilos::getAgentSignalIndexFields()[HilosSignalConstants::HILOS_USER_PUSH_RELEASE]);
        self::assertSame(
            UserPushReleaseSignalData::class,
            Hilos::getAgentSignalDtoRoutes()[HilosSignalConstants::HILOS_USER_PUSH_RELEASE],
        );
        self::assertSame(HilosAgentType::HILOS_USER, Hilos::getAgentSignalRoutes()[HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT]);
        self::assertSame('userId', Hilos::getAgentSignalIndexFields()[HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT]);
        self::assertSame(
            UserSecondFactorTrustRestrictSignalData::class,
            Hilos::getAgentSignalDtoRoutes()[HilosSignalConstants::HILOS_USER_SECOND_FACTOR_TRUST_RESTRICT],
        );
        self::assertSame(
            HilosAgentType::HILOS_NOTIFICATIONS_LIBRARY,
            Hilos::getAgentSignalRoutes()[HilosSignalConstants::HILOS_USER_PUSH_RELEASE_DONE],
        );
        self::assertSame(
            UserPushReleaseDoneSignalData::class,
            Hilos::getAgentSignalDtoRoutes()[HilosSignalConstants::HILOS_USER_PUSH_RELEASE_DONE],
        );
        self::assertSame(['HIL-1952'], Hilos::SHARED_DB_OWNERS[HilosDbContext::pushSubscriptions]);
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
            SolarSystemModelRtContext::class,
        );

        $this->addToAssertionCount(1);
    }

    /** Verified source pairs cover the units and immutable changes selected by Solar System Model. */
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

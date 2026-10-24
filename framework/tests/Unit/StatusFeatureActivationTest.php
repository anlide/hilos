<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosAgentType;
use Hilos\Constants\HilosSignalConstants;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Daemon\StatusAgentDaemon;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Feature\Definition\StatusFeature;
use Hilos\Core\Feature\Exception\IncompleteFeatureActivationException;
use Hilos\Core\Feature\FeatureActivationValidator;
use Hilos\Core\Feature\FeatureDefinition;
use Hilos\Core\Feature\FeatureRegistry;
use Hilos\Core\Feature\FeatureRequirements;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Database\Actions\Collection\StatusIncidentsActions;
use Hilos\Database\Actions\Collection\StatusIncidentUpdatesActions;
use Hilos\Database\Actions\Item\StatusIncidentActions;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Entity\Item\StatusIncident;
use Hilos\Database\Entity\Item\StatusIncidentUpdate;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\StatusIncidents;
use Hilos\Database\View\Collection\StatusIncidentUpdates;
use Hilos\Hilos;
use Hilos\Pages\Status\AbstractHilosStatusPage;
use Hilos\Pages\Status\AbstractHilosStatusComponentsPage;
use Hilos\Tables\Status\HilosStatusComponentsTable;
use Hilos\Runtime\State\Item\HilosStatusComponent;
use Hilos\Status\DTO\StatusAddUpdateSignalData;
use Hilos\Status\DTO\StatusCancelPublicationSignalData;
use Hilos\Status\DTO\StatusPublishNowSignalData;
use Hilos\Status\StatusAgent;
use Hilos\Status\StatusSettingsCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Status-specific validation and lazy production mounts; no SQL connection or demo activation. */
final class StatusFeatureActivationTest extends TestCase
{
    public const array STATUS_PAIR = [HilosAgentType::HILOS_STATUS => [
        AgentRegistryKey::WORKER => StatusAgent::class,
        AgentRegistryKey::DAEMON => StatusAgentDaemon::class,
        AgentRegistryKey::SCOPE => AgentScope::CLUSTER,
        AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        AgentRegistryKey::INDEXED => false,
    ]];

    private string $previousApp;

    protected function setUp(): void
    {
        $this->previousApp = Hilos::appClass();
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(Hilos::class, 'appClass'))->setValue(null, $this->previousApp);
    }

    public function testDefinitionNamesMachineAndAdminRequirements(): void
    {
        $definition = (new FeatureRegistry())->definition(HilosFeature::STATUS);
        self::assertInstanceOf(StatusFeature::class, $definition);
        self::assertTrue($definition->mountsRuntime());
        self::assertEquals(new FeatureRequirements(
            requiredPages: [AbstractHilosStatusPage::class, AbstractHilosStatusComponentsPage::class],
            requiredTables: [HilosStatusComponentsTable::class],
            requiredPageTables: [AbstractHilosStatusComponentsPage::class => HilosStatusComponentsTable::class],
            requiredAgents: [HilosAgentType::HILOS_STATUS],
            requiredCatalogFragments: [StatusSettingsCatalog::class],
            requires: [HilosFeature::DAEMON, HilosFeature::NOTIFICATION_DELIVERY],
            requiredDbTables: [StatusIncident::_table, StatusIncidentUpdate::_table],
        ), $definition->requirements());
        self::assertFalse((new StatusAgentDaemon())->requiresMonopolisticProcess());
    }

    public function testCompleteDeclarationAndEmptyFallbackPassWithoutInitializingLayers(): void
    {
        (new FeatureActivationValidator(new StatusActivationRegistry()))->validate(StatusEnabledHilos::class);
        (new FeatureActivationValidator(new StatusActivationRegistry()))->validate(StatusDisabledHilos::class);
        self::assertSame([
            HilosSignalConstants::STATUS_DAEMON_PICTURE_PORTION => DaemonClusterPicturePortionSignalData::class,
            HilosSignalConstants::STATUS_CANCEL_PUBLICATION => StatusCancelPublicationSignalData::class,
            HilosSignalConstants::STATUS_PUBLISH_NOW => StatusPublishNowSignalData::class,
            HilosSignalConstants::STATUS_ADD_UPDATE => StatusAddUpdateSignalData::class,
        ], StatusAgent::AGENT_SIGNALS);
        self::assertSame([], StatusAgent::AGENT_ACTIONS);
    }

    /** @return iterable<string, array{class-string<Hilos>, string}> Incomplete declarations */
    public static function refused(): iterable
    {
        yield 'no pages' => [StatusNoPagesHilos::class, 'page'];
        yield 'no table' => [StatusNoTableHilos::class, 'table'];
        yield 'no binding' => [StatusNoBindingHilos::class, 'binds'];
        yield 'no dependencies' => [StatusNoDependenciesHilos::class, 'HilosFeature::DAEMON'];
        yield 'no owner pair' => [StatusNoOwnerHilos::class, 'is not registered in AGENTS'];
        yield 'no daemon' => [StatusNoDaemonHilos::class, 'does not declare both a worker and a daemon'];
        yield 'no setting fragment' => [StatusNoFragmentHilos::class, 'does not fold in'];
        yield 'indexed owner' => [StatusIndexedOwnerHilos::class, 'non-indexed permanent owner'];
        yield 'node owner' => [StatusNodeOwnerHilos::class, 'CLUSTER / POLICY'];
        yield 'leader owner' => [StatusLeaderOwnerHilos::class, 'CLUSTER / POLICY'];
        yield 'idle owner' => [StatusIdleOwnerHilos::class, 'without IDLE_TIMEOUT'];
        yield 'invalid component' => [StatusInvalidComponentHilos::class, 'unknown agent type'];
        yield 'owner without feature' => [StatusUndeclaredOwnerHilos::class, 'STATUS is not declared'];
    }

    /**
     * @param class-string<Hilos> $facade Invalid feature activation
     * @param string $reason Refusal fragment expected in the aggregate activation exception
     */
    #[DataProvider('refused')]
    public function testIncompleteActivationRefuses(string $facade, string $reason): void
    {
        $this->expectException(IncompleteFeatureActivationException::class);
        $this->expectExceptionMessage($reason);
        (new FeatureActivationValidator(new StatusActivationRegistry()))->validate($facade);
    }

    public function testStatusTablesAreAbsentWhenDisabledAndLazyWithBothActionLayersWhenEnabled(): void
    {
        $binding = new ReflectionProperty(Hilos::class, 'appClass');
        $binding->setValue(null, StatusDisabledHilos::class);
        $disabled = new StatusFoundationDbContext();
        $disabled->configure();
        self::assertArrayNotHasKey(HilosDbContext::statusIncidents, $disabled->getMountedEntities());
        self::assertArrayNotHasKey(HilosDbContext::statusIncidentUpdates, $disabled->getMountedEntities());
        self::assertNotContains(HilosDbContext::statusIncidents, $disabled->processWideReadsForTest());
        self::assertNotContains(HilosDbContext::statusIncidentUpdates, $disabled->processWideReadsForTest());

        $binding->setValue(null, StatusEnabledHilos::class);
        $enabled = new StatusFoundationDbContext();
        $enabled->configure();
        self::assertSame(StatusIncident::class, $enabled->getMountedEntities()[HilosDbContext::statusIncidents]);
        self::assertSame(StatusIncidentUpdate::class, $enabled->getMountedEntities()[HilosDbContext::statusIncidentUpdates]);
        foreach ([HilosDbContext::statusIncidents, HilosDbContext::statusIncidentUpdates] as $key) {
            self::assertSame(Objects::LAZY_STRATEGY_KEY, $enabled->mountedObjectCollection($key)->getLazyStrategy());
            self::assertFalse($enabled->mountedObjectCollection($key)->isAllLoaded());
        }
        $mounts = $enabled->frameworkRegistrations();
        self::assertSame(StatusIncidents::class, $mounts[HilosDbContext::statusIncidents]->collection);
        self::assertSame(StatusIncidentsActions::class, $mounts[HilosDbContext::statusIncidents]->actions);
        self::assertSame(StatusIncidentActions::class, $mounts[HilosDbContext::statusIncidents]->itemActions);
        self::assertSame(StatusIncidentUpdates::class, $mounts[HilosDbContext::statusIncidentUpdates]->collection);
        self::assertSame(StatusIncidentUpdatesActions::class, $mounts[HilosDbContext::statusIncidentUpdates]->actions);
        self::assertNull($mounts[HilosDbContext::statusIncidentUpdates]->itemActions);
    }

    public function testTheOwnerHasExactlyTheApprovedOperations(): void
    {
        self::assertSame([
            HilosDbContext::statusIncidents => [TruthSourceOperation::Add, TruthSourceOperation::Update],
            HilosDbContext::statusIncidentUpdates => [TruthSourceOperation::Add],
        ], StatusAgent::OWNS_DB);
        self::assertSame([HilosStatusComponent::RT_COLLECTION => TruthSourceOperation::ALL], StatusAgent::OWNS_RT);
        self::assertSame([HilosStatusComponent::RT_COLLECTION], array_keys(OwnershipDeclaration::rtCollectionsOf(StatusAgent::class)));
        self::assertSame(
            [HilosDbContext::statusIncidents, HilosDbContext::statusIncidentUpdates],
            array_keys(OwnershipDeclaration::dbCollectionsOf(StatusAgent::class)),
        );
    }
}

/** Isolates Status obligations; its dependency definitions have their own existing tests. */
final class StatusActivationRegistry extends FeatureRegistry
{
    /** @return list<FeatureDefinition> Status and requirement-free prerequisite stand-ins */
    protected function buildDefinitions(): array
    {
        return [
            new StatusFeature(),
            new StatusPrerequisiteFeature(HilosFeature::DAEMON),
            new StatusPrerequisiteFeature(HilosFeature::NOTIFICATION_DELIVERY),
        ];
    }
}

final class StatusPrerequisiteFeature extends FeatureDefinition
{
    /** @param HilosFeature $case Prerequisite whose own obligations are outside this fixture */
    public function __construct(private readonly HilosFeature $case)
    {
    }

    /** @return HilosFeature Prerequisite case */
    public function feature(): HilosFeature
    {
        return $this->case;
    }

    /** @return FeatureRequirements No obligations in this test double */
    public function requirements(): FeatureRequirements
    {
        return new FeatureRequirements();
    }
}

final class StatusActivationPage extends AbstractHilosStatusPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = HilosAgentType::HILOS_INDEX;
}

final class StatusActivationComponentsPage extends AbstractHilosStatusComponentsPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = HilosAgentType::HILOS_INDEX;
}

abstract class StatusEnabledHilos extends Hilos
{
    public const array PAGES = [StatusActivationPage::PAGE => StatusActivationPage::class,
        StatusActivationComponentsPage::PAGE => StatusActivationComponentsPage::class];
    public const array TABLES = [HilosStatusComponentsTable::TABLE => HilosStatusComponentsTable::class];
    public const array PAGE_TABLES = [StatusActivationComponentsPage::PAGE => [HilosStatusComponentsTable::TABLE => []]];
    public const array AGENTS = StatusFeatureActivationTest::STATUS_PAIR + [HilosAgentType::HILOS_INDEX => []];
    protected const array FEATURES = [HilosFeature::STATUS, HilosFeature::DAEMON, HilosFeature::NOTIFICATION_DELIVERY];
    protected const string SETTINGS_CATALOG = StatusSettingsCatalog::class;
}

abstract class StatusDisabledHilos extends Hilos
{
    public const array AGENTS = [HilosAgentType::HILOS_INDEX => []];
}

abstract class StatusNoDependenciesHilos extends StatusEnabledHilos
{
    protected const array FEATURES = [HilosFeature::STATUS];
}

abstract class StatusNoOwnerHilos extends StatusEnabledHilos
{
    public const array AGENTS = [HilosAgentType::HILOS_INDEX => []];
}

abstract class StatusNoDaemonHilos extends StatusEnabledHilos
{
    public const array AGENTS = [HilosAgentType::HILOS_STATUS => [AgentRegistryKey::WORKER => StatusAgent::class]];
}

abstract class StatusNoFragmentHilos extends StatusEnabledHilos
{
    protected const string SETTINGS_CATALOG = StatusEmptySettingsCatalog::class;
}

final class StatusEmptySettingsCatalog implements CatalogProviderInterface
{
    /** @return array<string, array<string, mixed>> Deliberately absent Status fragment */
    public static function getCatalog(): array
    {
        return [];
    }
}

abstract class StatusIndexedOwnerHilos extends StatusEnabledHilos
{
    public const array AGENTS = [HilosAgentType::HILOS_STATUS => [
        AgentRegistryKey::WORKER => StatusAgent::class, AgentRegistryKey::DAEMON => StatusAgentDaemon::class,
        AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY, AgentRegistryKey::INDEXED => true,
    ], HilosAgentType::HILOS_INDEX => []];
}

abstract class StatusNodeOwnerHilos extends StatusEnabledHilos
{
    public const array AGENTS = [HilosAgentType::HILOS_STATUS => [
        AgentRegistryKey::WORKER => StatusAgent::class, AgentRegistryKey::DAEMON => StatusAgentDaemon::class,
        AgentRegistryKey::SCOPE => AgentScope::NODE, AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
    ], HilosAgentType::HILOS_INDEX => []];
}

abstract class StatusLeaderOwnerHilos extends StatusEnabledHilos
{
    public const array AGENTS = [HilosAgentType::HILOS_STATUS => [
        AgentRegistryKey::WORKER => StatusAgent::class, AgentRegistryKey::DAEMON => StatusAgentDaemon::class,
    ], HilosAgentType::HILOS_INDEX => []];
}

abstract class StatusIdleOwnerHilos extends StatusEnabledHilos
{
    public const array AGENTS = [HilosAgentType::HILOS_STATUS => [
        AgentRegistryKey::WORKER => StatusAgent::class, AgentRegistryKey::DAEMON => StatusAgentDaemon::class,
        AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY, AgentRegistryKey::IDLE_TIMEOUT => 240,
    ], HilosAgentType::HILOS_INDEX => []];
}

abstract class StatusInvalidComponentHilos extends StatusEnabledHilos
{
    protected const array STATUS_COMPONENTS = ['service' => ['name' => 'Name', 'description' => null, 'agentType' => 'missing']];
}

abstract class StatusUndeclaredOwnerHilos extends StatusEnabledHilos
{
    protected const array FEATURES = [];
}

final class StatusFoundationDbContext extends HilosDbContext
{
    /** @return list<string> Framework process-wide DB read declarations */
    public function processWideReadsForTest(): array
    {
        return $this->processWideReadCollections();
    }
}

abstract class StatusNoPagesHilos extends StatusEnabledHilos
{
    public const array PAGES = [];
}

abstract class StatusNoTableHilos extends StatusEnabledHilos
{
    public const array TABLES = [];
}

abstract class StatusNoBindingHilos extends StatusEnabledHilos
{
    public const array PAGE_TABLES = [];
}

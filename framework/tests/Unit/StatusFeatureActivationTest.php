<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Auth\Throttle\DTO\ThrottleVerdictSignalData;
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
use Hilos\Database\Entity\Item\StatusSubscriber;
use Hilos\Database\Entity\Item\StatusWork;
use Hilos\Database\Entity\Item\StatusWorkComponent;
use Hilos\Database\Entity\Item\StatusMaintenancePeriod;
use Hilos\Database\View\Collection\StatusSubscribers;
use Hilos\Database\Actions\Collection\StatusSubscribersActions;
use Hilos\Database\Actions\Item\StatusSubscriberActions;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\StatusIncidents;
use Hilos\Database\View\Collection\StatusIncidentUpdates;
use Hilos\Database\View\Collection\StatusWorks;
use Hilos\Database\Actions\Collection\StatusWorksActions;
use Hilos\Database\Actions\Item\StatusWorkActions;
use Hilos\Database\View\Collection\StatusWorkComponents;
use Hilos\Database\Actions\Collection\StatusWorkComponentsActions;
use Hilos\Database\Actions\Item\StatusWorkComponentActions;
use Hilos\Database\View\Collection\StatusMaintenancePeriods;
use Hilos\Database\Actions\Collection\StatusMaintenancePeriodsActions;
use Hilos\Database\Actions\Item\StatusMaintenancePeriodActions;
use Hilos\Fs\Context\FsContext;
use Hilos\Fs\DirectoryScope;
use Hilos\Hilos;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Mail\Delivery\MailDeliveryChannelAgentDaemon;
use Hilos\Pages\Status\AbstractHilosStatusPage;
use Hilos\Pages\Status\AbstractHilosStatusComponentsPage;
use Hilos\Pages\Status\AbstractHilosStatusSubscribersPage;
use Hilos\Pages\Status\AbstractHilosStatusWorksPage;
use Hilos\Pages\Status\AbstractHilosStatusHistoryPage;
use Hilos\Tables\Status\HilosStatusComponentsTable;
use Hilos\Tables\Status\HilosStatusSubscribersTable;
use Hilos\Tables\Status\HilosStatusWorksTable;
use Hilos\Tables\Status\HilosStatusHistoryTable;
use Hilos\Runtime\State\Item\HilosStatusComponent;
use Hilos\Runtime\State\Item\HilosStatusMaintenance;
use Hilos\Status\DTO\StatusAddUpdateSignalData;
use Hilos\Status\DTO\StatusRemoveSubscriberSignalData;
use Hilos\Status\DTO\StatusScheduleWorkSignalData;
use Hilos\Status\DTO\StatusChangeWorkSignalData;
use Hilos\Status\DTO\StatusCancelWorkSignalData;
use Hilos\Status\DTO\StatusCancelPublicationSignalData;
use Hilos\Status\DTO\StatusPublishNowSignalData;
use Hilos\Status\DTO\StatusConfirmSubscriptionActionDTO;
use Hilos\Status\DTO\StatusSubscribeActionDTO;
use Hilos\Status\DTO\StatusUnsubscribeActionDTO;
use Hilos\Status\DTO\StatusUnsubscribePreviewActionDTO;
use Hilos\Runtime\State\Item\HilosClusterNode;
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
            requiredPages: [AbstractHilosStatusPage::class, AbstractHilosStatusComponentsPage::class,
                AbstractHilosStatusSubscribersPage::class, AbstractHilosStatusWorksPage::class, AbstractHilosStatusHistoryPage::class],
            requiredTables: [HilosStatusComponentsTable::class, HilosStatusSubscribersTable::class,
                HilosStatusWorksTable::class, HilosStatusHistoryTable::class],
            requiredPageTables: [
                AbstractHilosStatusComponentsPage::class => HilosStatusComponentsTable::class,
                AbstractHilosStatusSubscribersPage::class => HilosStatusSubscribersTable::class,
                AbstractHilosStatusWorksPage::class => HilosStatusWorksTable::class,
                AbstractHilosStatusHistoryPage::class => HilosStatusHistoryTable::class,
            ],
            requiredAgents: [HilosAgentType::HILOS_STATUS],
            requiredSharedAgents: [MailDeliveryChannelAgent::AGENT_TYPE],
            requiredCatalogFragments: [StatusSettingsCatalog::class],
            requires: [HilosFeature::DAEMON, HilosFeature::NOTIFICATION_DELIVERY, HilosFeature::AUTH_THROTTLE],
            requiredDbTables: [
                StatusIncident::_table, StatusIncidentUpdate::_table, StatusSubscriber::_table,
                StatusWork::_table, StatusWorkComponent::_table, StatusMaintenancePeriod::_table,
            ],
        ), $definition->requirements());
        self::assertFalse((new StatusAgentDaemon())->requiresMonopolisticProcess());
    }

    public function testCompleteDeclarationAndEmptyFallbackPassWithoutInitializingLayers(): void
    {
        (new FeatureActivationValidator(new StatusActivationRegistry()))->validate(StatusEnabledHilos::class);
        (new FeatureActivationValidator(new StatusActivationRegistry()))->validate(StatusDisabledHilos::class);
        (new FeatureActivationValidator(new StatusActivationRegistry()))->validate(StatusMailWithoutStatusHilos::class);
        self::assertSame([
            HilosSignalConstants::STATUS_DAEMON_PICTURE_PORTION => DaemonClusterPicturePortionSignalData::class,
            HilosSignalConstants::STATUS_CANCEL_PUBLICATION => StatusCancelPublicationSignalData::class,
            HilosSignalConstants::STATUS_PUBLISH_NOW => StatusPublishNowSignalData::class,
            HilosSignalConstants::STATUS_ADD_UPDATE => StatusAddUpdateSignalData::class,
            HilosSignalConstants::STATUS_REMOVE_SUBSCRIBER => StatusRemoveSubscriberSignalData::class,
            HilosSignalConstants::STATUS_SCHEDULE_WORK => StatusScheduleWorkSignalData::class,
            HilosSignalConstants::STATUS_CHANGE_WORK => StatusChangeWorkSignalData::class,
            HilosSignalConstants::STATUS_CANCEL_WORK => StatusCancelWorkSignalData::class,
            HilosSignalConstants::STATUS_THROTTLE_VERDICT => ThrottleVerdictSignalData::class,
        ], StatusAgent::AGENT_SIGNALS);
        self::assertSame([
            HilosSignalConstants::STATUS_SUBSCRIBE => StatusSubscribeActionDTO::class,
            HilosSignalConstants::STATUS_SUBSCRIPTION_CONFIRM => StatusConfirmSubscriptionActionDTO::class,
            HilosSignalConstants::STATUS_UNSUBSCRIBE_PREVIEW => StatusUnsubscribePreviewActionDTO::class,
            HilosSignalConstants::STATUS_UNSUBSCRIBE => StatusUnsubscribeActionDTO::class,
        ], StatusAgent::AGENT_ACTIONS);
        self::assertSame([
            HilosSignalConstants::STATUS_SUBSCRIBE,
            HilosSignalConstants::STATUS_SUBSCRIPTION_CONFIRM,
            HilosSignalConstants::STATUS_UNSUBSCRIBE_PREVIEW,
            HilosSignalConstants::STATUS_UNSUBSCRIBE,
        ], StatusAgent::THROTTLED_ACTIONS);
        self::assertSame([], StatusAgent::AUTH_ACTIONS);
        self::assertSame([HilosDbContext::sessions], StatusAgent::READS_DB);
        self::assertSame([HilosClusterNode::RT_COLLECTION], StatusAgent::READS_RT);
    }

    /** @return iterable<string, array{?FsContext}> Missing filesystem or missing registration */
    public static function missingStatusStorage(): iterable
    {
        yield 'no filesystem' => [null];
        yield 'no status directory' => [new StatusActivationFsContext()];
    }

    /** @param ?FsContext $context Filesystem without Status storage */
    #[DataProvider('missingStatusStorage')]
    public function testMissingStatusStorageIsRefusedOnlyWhenEnabled(?FsContext $context): void
    {
        $previousFs = Hilos::$fs;
        Hilos::$fs = $context;
        try {
            StatusDisabledHilos::checkDirectory();
            $this->expectException(IncompleteFeatureActivationException::class);
            $this->expectExceptionMessage('HilosFeature::STATUS keeps public files in the status directory');
            StatusEnabledHilos::checkDirectory();
        } finally {
            Hilos::$fs = $previousFs;
        }
    }

    public function testRegisteredStatusStoragePassesWithoutCreatingTheDirectory(): void
    {
        $previousFs = Hilos::$fs;
        Hilos::$fs = new StatusActivationFsContext();
        Hilos::$fs->configure();
        try {
            StatusEnabledHilos::checkDirectory();
            self::assertSame('/uncreated/status', Hilos::$fs->status->getPath());
            self::assertSame([], Hilos::$fs->declarationErrors());
        } finally {
            Hilos::$fs = $previousFs;
        }
    }

    /** @return iterable<string, array{class-string<Hilos>, string}> Incomplete declarations */
    public static function refused(): iterable
    {
        yield 'no pages' => [StatusNoPagesHilos::class, 'page'];
        yield 'no table' => [StatusNoTableHilos::class, 'table'];
        yield 'no binding' => [StatusNoBindingHilos::class, 'binds'];
        yield 'no subscriber page' => [StatusNoSubscriberPageHilos::class, 'page'];
        yield 'no subscriber table' => [StatusNoSubscriberTableHilos::class, 'table'];
        yield 'no subscriber binding' => [StatusNoSubscriberBindingHilos::class, 'binds'];
        yield 'no works page' => [StatusNoWorksPageHilos::class, 'page'];
        yield 'no works table' => [StatusNoWorksTableHilos::class, 'table'];
        yield 'no works binding' => [StatusNoWorksBindingHilos::class, 'binds'];
        yield 'no dependencies' => [StatusNoDependenciesHilos::class, 'HilosFeature::DAEMON'];
        yield 'no auth throttle' => [StatusNoThrottleHilos::class, 'HilosFeature::AUTH_THROTTLE'];
        yield 'no mail agent' => [StatusNoMailHilos::class, 'hilos_mail'];
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
        self::assertArrayNotHasKey(HilosDbContext::statusSubscribers, $disabled->getMountedEntities());
        self::assertNotContains(HilosDbContext::statusIncidents, $disabled->processWideReadsForTest());
        self::assertNotContains(HilosDbContext::statusIncidentUpdates, $disabled->processWideReadsForTest());
        self::assertNotContains(HilosDbContext::statusSubscribers, $disabled->processWideReadsForTest());

        $binding->setValue(null, StatusEnabledHilos::class);
        $enabled = new StatusFoundationDbContext();
        $enabled->configure();
        self::assertSame(StatusIncident::class, $enabled->getMountedEntities()[HilosDbContext::statusIncidents]);
        self::assertSame(StatusIncidentUpdate::class, $enabled->getMountedEntities()[HilosDbContext::statusIncidentUpdates]);
        self::assertSame(StatusSubscriber::class, $enabled->getMountedEntities()[HilosDbContext::statusSubscribers]);
        foreach ([HilosDbContext::statusIncidents, HilosDbContext::statusIncidentUpdates, HilosDbContext::statusSubscribers] as $key) {
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
        self::assertSame(StatusSubscribers::class, $mounts[HilosDbContext::statusSubscribers]->collection);
        self::assertSame(StatusSubscribersActions::class, $mounts[HilosDbContext::statusSubscribers]->actions);
        self::assertSame(StatusSubscriberActions::class, $mounts[HilosDbContext::statusSubscribers]->itemActions);
        self::assertArrayNotHasKey(HilosDbContext::statusWorks, $disabled->getMountedEntities());
        self::assertNotContains(HilosDbContext::statusWorks, $disabled->processWideReadsForTest());
        self::assertSame(StatusWork::class, $enabled->getMountedEntities()[HilosDbContext::statusWorks]);
        self::assertSame(Objects::LAZY_STRATEGY_KEY, $enabled->mountedObjectCollection(HilosDbContext::statusWorks)->getLazyStrategy());
        self::assertFalse($enabled->mountedObjectCollection(HilosDbContext::statusWorks)->isAllLoaded());
        self::assertSame(StatusWorks::class, $mounts[HilosDbContext::statusWorks]->collection);
        self::assertSame(StatusWorksActions::class, $mounts[HilosDbContext::statusWorks]->actions);
        self::assertSame(StatusWorkActions::class, $mounts[HilosDbContext::statusWorks]->itemActions);
        self::assertArrayNotHasKey(HilosDbContext::statusWorkComponents, $disabled->getMountedEntities());
        self::assertNotContains(HilosDbContext::statusWorkComponents, $disabled->processWideReadsForTest());
        self::assertSame(StatusWorkComponent::class, $enabled->getMountedEntities()[HilosDbContext::statusWorkComponents]);
        self::assertSame(Objects::LAZY_STRATEGY_KEY, $enabled->mountedObjectCollection(HilosDbContext::statusWorkComponents)->getLazyStrategy());
        self::assertFalse($enabled->mountedObjectCollection(HilosDbContext::statusWorkComponents)->isAllLoaded());
        self::assertSame(StatusWorkComponents::class, $mounts[HilosDbContext::statusWorkComponents]->collection);
        self::assertSame(StatusWorkComponentsActions::class, $mounts[HilosDbContext::statusWorkComponents]->actions);
        self::assertSame(StatusWorkComponentActions::class, $mounts[HilosDbContext::statusWorkComponents]->itemActions);
        self::assertArrayNotHasKey(HilosDbContext::statusMaintenancePeriods, $disabled->getMountedEntities());
        self::assertNotContains(HilosDbContext::statusMaintenancePeriods, $disabled->processWideReadsForTest());
        self::assertSame(StatusMaintenancePeriod::class, $enabled->getMountedEntities()[HilosDbContext::statusMaintenancePeriods]);
        self::assertSame(Objects::LAZY_STRATEGY_KEY, $enabled->mountedObjectCollection(HilosDbContext::statusMaintenancePeriods)->getLazyStrategy());
        self::assertFalse($enabled->mountedObjectCollection(HilosDbContext::statusMaintenancePeriods)->isAllLoaded());
        self::assertSame(StatusMaintenancePeriods::class, $mounts[HilosDbContext::statusMaintenancePeriods]->collection);
        self::assertSame(StatusMaintenancePeriodsActions::class, $mounts[HilosDbContext::statusMaintenancePeriods]->actions);
        self::assertSame(StatusMaintenancePeriodActions::class, $mounts[HilosDbContext::statusMaintenancePeriods]->itemActions);
    }

    public function testTheOwnerHasExactlyTheApprovedOperations(): void
    {
        self::assertSame([
            HilosDbContext::statusIncidents => [TruthSourceOperation::Add, TruthSourceOperation::Update],
            HilosDbContext::statusIncidentUpdates => [TruthSourceOperation::Add],
            HilosDbContext::statusSubscribers => [TruthSourceOperation::Add, TruthSourceOperation::Update, TruthSourceOperation::Remove],
            HilosDbContext::statusWorks => [TruthSourceOperation::Add, TruthSourceOperation::Update],
            HilosDbContext::statusWorkComponents => [TruthSourceOperation::Add, TruthSourceOperation::Remove],
            HilosDbContext::statusMaintenancePeriods => [TruthSourceOperation::Add, TruthSourceOperation::Update],
        ], StatusAgent::OWNS_DB);
        self::assertSame([
            HilosStatusComponent::RT_COLLECTION => TruthSourceOperation::ALL,
            HilosStatusMaintenance::RT_COLLECTION => TruthSourceOperation::ALL,
        ], StatusAgent::OWNS_RT);
        self::assertSame(
            [HilosStatusComponent::RT_COLLECTION, HilosStatusMaintenance::RT_COLLECTION],
            array_keys(OwnershipDeclaration::rtCollectionsOf(StatusAgent::class)),
        );
        self::assertSame(
            [
                HilosDbContext::statusIncidents, HilosDbContext::statusIncidentUpdates, HilosDbContext::statusSubscribers,
                HilosDbContext::statusWorks, HilosDbContext::statusWorkComponents, HilosDbContext::statusMaintenancePeriods,
            ],
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
            new StatusPrerequisiteFeature(HilosFeature::AUTH_THROTTLE),
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

final class StatusActivationSubscribersPage extends AbstractHilosStatusSubscribersPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = HilosAgentType::HILOS_INDEX;
}

final class StatusActivationWorksPage extends AbstractHilosStatusWorksPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = HilosAgentType::HILOS_INDEX;
}

final class StatusActivationHistoryPage extends AbstractHilosStatusHistoryPage
{
    public const string SUBSCRIPTION_AGENT_TYPE = HilosAgentType::HILOS_INDEX;
}

abstract class StatusEnabledHilos extends Hilos
{
    public const array PAGES = [StatusActivationPage::PAGE => StatusActivationPage::class,
        StatusActivationComponentsPage::PAGE => StatusActivationComponentsPage::class,
        StatusActivationSubscribersPage::PAGE => StatusActivationSubscribersPage::class,
        StatusActivationWorksPage::PAGE => StatusActivationWorksPage::class,
        StatusActivationHistoryPage::PAGE => StatusActivationHistoryPage::class];
    public const array TABLES = [HilosStatusComponentsTable::TABLE => HilosStatusComponentsTable::class,
        HilosStatusSubscribersTable::TABLE => HilosStatusSubscribersTable::class,
        HilosStatusWorksTable::TABLE => HilosStatusWorksTable::class,
        HilosStatusHistoryTable::TABLE => HilosStatusHistoryTable::class];
    public const array PAGE_TABLES = [StatusActivationComponentsPage::PAGE => [HilosStatusComponentsTable::TABLE => []],
        StatusActivationSubscribersPage::PAGE => [HilosStatusSubscribersTable::TABLE => []],
        StatusActivationWorksPage::PAGE => [HilosStatusWorksTable::TABLE => []],
        StatusActivationHistoryPage::PAGE => [HilosStatusHistoryTable::TABLE => []]];
    public const array AGENTS = StatusFeatureActivationTest::STATUS_PAIR + [
        HilosAgentType::HILOS_INDEX => [],
        MailDeliveryChannelAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => MailDeliveryChannelAgent::class,
            AgentRegistryKey::DAEMON => MailDeliveryChannelAgentDaemon::class,
        ],
    ];
    protected const array FEATURES = [
        HilosFeature::STATUS, HilosFeature::DAEMON, HilosFeature::NOTIFICATION_DELIVERY, HilosFeature::AUTH_THROTTLE,
    ];
    protected const string SETTINGS_CATALOG = StatusSettingsCatalog::class;

    /** Checks storage after the fixture context is configured. */
    public static function checkDirectory(): void
    {
        static::refuseStatusWithoutDirectory();
    }
}

abstract class StatusDisabledHilos extends Hilos
{
    public const array AGENTS = [HilosAgentType::HILOS_INDEX => []];

    /** No Status feature means no storage obligation. */
    public static function checkDirectory(): void
    {
        static::refuseStatusWithoutDirectory();
    }
}

abstract class StatusMailWithoutStatusHilos extends StatusDisabledHilos
{
    public const array AGENTS = [
        HilosAgentType::HILOS_INDEX => [],
        MailDeliveryChannelAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => MailDeliveryChannelAgent::class,
            AgentRegistryKey::DAEMON => MailDeliveryChannelAgentDaemon::class,
        ],
    ];
}

abstract class StatusNoDependenciesHilos extends StatusEnabledHilos
{
    protected const array FEATURES = [HilosFeature::STATUS];
}

abstract class StatusNoThrottleHilos extends StatusEnabledHilos
{
    protected const array FEATURES = [HilosFeature::STATUS, HilosFeature::DAEMON, HilosFeature::NOTIFICATION_DELIVERY];
}

abstract class StatusNoMailHilos extends StatusEnabledHilos
{
    public const array AGENTS = StatusFeatureActivationTest::STATUS_PAIR + [HilosAgentType::HILOS_INDEX => []];
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

final class StatusActivationFsContext extends FsContext
{
    /** Registers an intentionally uncreated shared path. */
    public function configure(): void
    {
        $this->registerDirectory(self::STATUS, '/uncreated/status', DirectoryScope::CLUSTER);
    }
}

abstract class StatusNoSubscriberPageHilos extends StatusEnabledHilos
{
    public const array PAGES = [StatusActivationPage::PAGE => StatusActivationPage::class,
        StatusActivationComponentsPage::PAGE => StatusActivationComponentsPage::class];
}

abstract class StatusNoSubscriberTableHilos extends StatusEnabledHilos
{
    public const array TABLES = [HilosStatusComponentsTable::TABLE => HilosStatusComponentsTable::class];
}

abstract class StatusNoSubscriberBindingHilos extends StatusEnabledHilos
{
    public const array PAGE_TABLES = [StatusActivationComponentsPage::PAGE => [HilosStatusComponentsTable::TABLE => []]];
}

abstract class StatusNoWorksPageHilos extends StatusEnabledHilos
{
    public const array PAGES = [StatusActivationPage::PAGE => StatusActivationPage::class,
        StatusActivationComponentsPage::PAGE => StatusActivationComponentsPage::class,
        StatusActivationSubscribersPage::PAGE => StatusActivationSubscribersPage::class];
}

abstract class StatusNoWorksTableHilos extends StatusEnabledHilos
{
    public const array TABLES = [HilosStatusComponentsTable::TABLE => HilosStatusComponentsTable::class,
        HilosStatusSubscribersTable::TABLE => HilosStatusSubscribersTable::class];
}

abstract class StatusNoWorksBindingHilos extends StatusEnabledHilos
{
    public const array PAGE_TABLES = [StatusActivationComponentsPage::PAGE => [HilosStatusComponentsTable::TABLE => []],
        StatusActivationSubscribersPage::PAGE => [HilosStatusSubscribersTable::TABLE => []]];
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Feature\Definition\StatusFeature;
use Hilos\Core\Feature\Exception\FeatureRuntimeOverwrittenException;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\StatusIncident as EntityStatusIncident;
use Hilos\Database\Object\Item\StatusIncident as ObjectStatusIncident;
use Hilos\Database\View\Collection\StatusIncidents;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Database\View\Item\StatusIncident;
use Hilos\Core\Router\SignalRouter;
use Hilos\Hilos;
use Hilos\Runtime\Exception\Rt\RtCollectionNotFoundException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\State\Item\HilosStatusComponent;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\State\Collection\HilosStatusComponents as StateHilosStatusComponents;
use Hilos\Runtime\View\Actions\Collection\HilosStatusComponentsActions;
use Hilos\Runtime\View\Actions\Item\HilosStatusComponentActions;
use Hilos\Runtime\View\Collection\HilosStatusComponents;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Status\StatusAgent;
use Hilos\Status\StatusComponentState;
use Hilos\Status\StatusSnapshot;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Error;

/** Four-field RT serialization, represented actions and the temporary Status read projection. */
final class StatusRuntimeDiffTest extends TestCase
{
    private ?SignalRouter $previousRouter;
    private string $previousApp;
    private ?RtContext $previousRuntime;
    private ?HilosDbContext $previousDb;
    private StatusAgent $owner;
    private StatusReadIncidents $incidents;
    private StatusReadDbContext $database;
    private StatusReadRuntime $runtime;

    protected function setUp(): void
    {
        $this->previousRouter = Hilos::$sr;
        Hilos::$sr = new SignalRouter();
        $this->previousApp = Hilos::appClass();
        $this->previousRuntime = Hilos::$rt;
        $this->previousDb = Hilos::$db;
        (new ReflectionProperty(Hilos::class, 'appClass'))->setValue(null, StatusRuntimeHilos::class);
        $this->runtime = new StatusReadRuntime();
        $this->runtime->mountFeatureRuntime([new StatusFeature()]);
        $this->runtime->configure();
        $this->runtime->bindStateCollectionNames();
        Hilos::$rt = $this->runtime;
        $this->incidents = StatusReadIncidents::initEmpty();
        $this->database = new StatusReadDbContext($this->incidents);
        Hilos::$db = $this->database;
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        $this->owner = new StatusAgent();
        OwnershipDeclaration::claimAll($this->owner);
        ExecutionContext::setCurrentAgentId($this->owner->getId());
        $this->owner->onStart();
    }

    protected function tearDown(): void
    {
        $this->owner->onStop();
        RtTruthSourceRegistry::unregisterAgent($this->owner->getId());
        TruthSourceRegistry::unregisterAgent($this->owner->getId());
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->owner->getId()));
        ExecutionContext::setCurrentAgentId(null);
        SourceChangeBus::reset();
        RtStaleness::clear(HilosStatusComponent::RT_COLLECTION, ['service', 'other']);
        Hilos::$sr = $this->previousRouter;
        Hilos::$rt = $this->previousRuntime;
        Hilos::$db = $this->previousDb;
        (new ReflectionProperty(Hilos::class, 'appClass'))->setValue(null, $this->previousApp);
    }

    public function testUnknownCreateAndStrictNullableRoundTrip(): void
    {
        $row = HilosStatusComponent::create('service', 2);
        self::assertSame([
            'componentKey' => 'service', 'expectedInstances' => 2, 'liveInstances' => null, 'observedAt' => null,
        ], $row->toArray());
        self::assertSame($row->toArray(), HilosStatusComponent::fromRow($row->toArray())->toArray());
        $row->applyDiff(['liveInstances' => 0, 'observedAt' => 1234567890123]);
        self::assertSame(0, $row->liveInstances);
        $row->applyDiff(['expectedInstances' => 3]);
        self::assertSame(1234567890123, $row->observedAt);
        self::assertSame(0, $row->liveInstances);
        $row->applyDiff(['liveInstances' => null, 'observedAt' => null]);
        self::assertNull($row->liveInstances);
        self::assertNull($row->observedAt);
        self::assertSame('service', $row->getId());
        self::assertSame(HilosStatusComponent::RT_COLLECTION, $row::getRtCollectionKey());
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid serialized row overrides */
    public static function malformedFields(): iterable
    {
        foreach (['componentKey' => [null, 1, '', 'UPPER'], 'expectedInstances' => [null, '1', 0, -1],
            'liveInstances' => ['0', false, -1], 'observedAt' => ['1000', false, 1.5]] as $field => $values) {
            foreach ($values as $index => $value) {
                yield $field . $index => [[$field => $value]];
            }
        }
    }

    /** @param array<string, mixed> $overrides Malformed serialized fields */
    #[DataProvider('malformedFields')]
    public function testMalformedRowsRefuse(array $overrides): void
    {
        $this->expectException(InvalidFormatException::class);
        HilosStatusComponent::fromRow(array_replace(HilosStatusComponent::create('service', 1)->toArray(), $overrides));
    }

    /** @param array<string, mixed> $overrides Malformed partial fields */
    #[DataProvider('malformedFields')]
    public function testMalformedDiffsRefuseWithoutPartiallyChangingTheRow(array $overrides): void
    {
        $row = HilosStatusComponent::create('service', 1);
        try {
            $row->applyDiff(array_replace(['observedAt' => 1000], $overrides));
            self::fail('Malformed diff was accepted');
        } catch (InvalidFormatException) {
            self::assertSame(HilosStatusComponent::create('service', 1)->toArray(), $row->toArray());
        }
    }

    public function testRequiredFieldsCannotDisappearAndTheKeyCannotChange(): void
    {
        foreach (['componentKey', 'expectedInstances'] as $required) {
            $row = HilosStatusComponent::create('service', 1)->toArray();
            unset($row[$required]);
            try {
                HilosStatusComponent::fromRow($row);
                self::fail('Missing required field was accepted');
            } catch (InvalidFormatException) {
                $this->addToAssertionCount(1);
            }
        }
        $row = HilosStatusComponent::create('service', 1);
        try {
            $row->applyDiff(['componentKey' => 'different', 'liveInstances' => 1]);
            self::fail('Changed key was accepted');
        } catch (InvalidFormatException) {
            self::assertSame('service', $row->componentKey);
            self::assertNull($row->liveInstances);
        }
        $this->expectException(Error::class);
        $row->componentKey = 'different';
    }

    public function testFeatureRepresentationAndOwnerInitializationAreBoundedAndUnknown(): void
    {
        self::assertInstanceOf(HilosStatusComponents::class, Hilos::$rt->hilosStatusComponents);
        self::assertInstanceOf(HilosStatusComponentsActions::class, Hilos::$rt->hilosStatusComponents->actions);
        self::assertCount(0, Hilos::$rt->hilosStatusComponents);
        $this->owner->onTick();
        self::assertCount(2, Hilos::$rt->hilosStatusComponents);
        self::assertInstanceOf(HilosStatusComponentActions::class, Hilos::$rt->hilosStatusComponents['service']->actions);
        self::assertNull(Hilos::$rt->hilosStatusComponents['service']->liveInstances);
        self::assertNull(Hilos::$rt->hilosStatusComponents['service']->observedAt);
        $this->owner->onTick();
        self::assertCount(2, Hilos::$rt->hilosStatusComponents);
        self::assertSame(1, $this->database->reads);
        self::assertSame(StatusComponentState::UNKNOWN, StatusSnapshot::read()->components['service']->state);
    }

    public function testItemActionsPreserveIdentityAndEnsureDoesNotOverwriteAnExistingObservation(): void
    {
        $this->owner->onTick();
        Hilos::$rt->hilosStatusComponents['service']->actions->update(2, 1, 1000);
        Hilos::$rt->hilosStatusComponents->actions->ensure('service', 7);
        self::assertSame(2, Hilos::$rt->hilosStatusComponents['service']->expectedInstances);
        self::assertSame(1, Hilos::$rt->hilosStatusComponents['service']->liveInstances);
        self::assertSame(1000, Hilos::$rt->hilosStatusComponents['service']->observedAt);
    }

    public function testAnotherAgentCannotWriteTheComponents(): void
    {
        $this->owner->onTick();
        ExecutionContext::setCurrentAgentId('foreign');
        $this->expectException(RtTruthSourceWriteNotAllowedException::class);
        Hilos::$rt->hilosStatusComponents['service']->actions->update(1, 1, 1000);
    }

    public function testColdMissingAndStaleCountsCannotAttestOperational(): void
    {
        self::assertSame(StatusComponentState::UNKNOWN, StatusSnapshot::read()->components['service']->state);
        $this->owner->onTick();
        Hilos::$rt->hilosStatusComponents['service']->actions->update(1, 1, 1000);
        self::assertSame(StatusComponentState::OPERATIONAL, StatusSnapshot::read()->components['service']->state);
        RtStaleness::mark(HilosStatusComponent::RT_COLLECTION, ['service'], 1.0);
        self::assertSame(StatusComponentState::UNKNOWN, StatusSnapshot::read()->components['service']->state);
        self::assertSame(StatusComponentState::UNKNOWN, StatusSnapshot::read()->components['other']->state);
        self::assertSame(1.0, Hilos::$rt->hilosStatusComponents['service']->staleSince());
    }

    public function testIncidentProjectionUsesActiveKeysAndKeepsDbDeadlinesAndSuppressionOnTheView(): void
    {
        $this->owner->onTick();
        Hilos::$rt->hilosStatusComponents['service']->actions->update(1, 0, 1000);
        $incident = new EntityStatusIncident();
        $incident->id = 1;
        $incident->component_key = 'service';
        $incident->component_name = 'Stored name';
        $incident->active_component_key = 'service';
        $incident->severity = 'major_outage';
        $incident->detected_at = 900;
        $incident->publish_at = 300900;
        $incident->suppressed_at = 950;
        $this->incidents->add(new StatusIncident(ObjectStatusIncident::fromEntity($incident)));
        $snapshot = StatusSnapshot::read();
        self::assertSame(StatusComponentState::CHECKING, $snapshot->components['service']->state);
        self::assertSame(300900, $snapshot->components['service']->incident->publishAt);
        self::assertSame(950, $snapshot->components['service']->incident->suppressedAt);
        self::assertSame([EntityStatusIncident::active_component_key, ['service', 'other']], $this->incidents->selection);
        $incident->published_at = 2000;
        $incident->suppressed_at = null;
        self::assertSame(StatusComponentState::MAJOR_OUTAGE, StatusSnapshot::read()->components['service']->state);
        $incident->severity = 'partial_outage';
        self::assertSame(StatusComponentState::PARTIAL_OUTAGE, StatusSnapshot::read()->components['service']->state);
    }

    /** @return iterable<string, array{string, bool}> Freeze phases and their DB-silence verdict */
    public static function freezePhases(): iterable
    {
        yield 'activating' => [ProtectedModeRuntime::PHASE_ACTIVATING, true];
        yield 'active' => [ProtectedModeRuntime::PHASE_ACTIVE, true];
        yield 'verifying' => [ProtectedModeRuntime::PHASE_VERIFYING, false];
        yield 'deactivating' => [ProtectedModeRuntime::PHASE_DEACTIVATING, false];
    }

    /**
     * @param string $phase Known freeze phase
     * @param bool $silenced Whether incident DB reads are forbidden
     */
    #[DataProvider('freezePhases')]
    public function testEveryFreezePhaseOverlaysMaintenanceAndSilenceNeverReadsDb(string $phase, bool $silenced): void
    {
        $this->runtime->phase($phase);
        $this->owner->onTick();
        self::assertCount(0, Hilos::$rt->hilosStatusComponents);
        $snapshot = StatusSnapshot::read();
        self::assertSame(StatusComponentState::MAINTENANCE, $snapshot->components['service']->state);
        self::assertSame($silenced ? 0 : 1, $this->database->reads);
        self::assertSame($silenced ? null : [], $snapshot->incidents);
    }

    public function testResumeAndRestartAwaitANewObservation(): void
    {
        $this->owner->onTick();
        Hilos::$rt->hilosStatusComponents['service']->actions->update(1, 1, 1000);
        $this->runtime->phase(ProtectedModeRuntime::PHASE_ACTIVE);
        $this->owner->onTick();
        $this->runtime->phase(ProtectedModeRuntime::PHASE_INACTIVE);
        $this->owner->onTick();
        self::assertNull(Hilos::$rt->hilosStatusComponents['service']->liveInstances);
        Hilos::$rt->hilosStatusComponents['service']->actions->update(1, 1, 2000);
        $this->owner->onStart();
        $this->owner->onTick();
        self::assertNull(Hilos::$rt->hilosStatusComponents['service']->observedAt);
    }

    public function testUnavailableIncidentsDifferFromKnownEmptyAndNeverMakeCountsHealthy(): void
    {
        $this->owner->onTick();
        Hilos::$rt->hilosStatusComponents['service']->actions->update(1, 1, 1000);
        self::assertSame([], StatusSnapshot::read()->incidents);
        $this->incidents->unavailable = true;
        self::assertNull(StatusSnapshot::read()->incidents);
        self::assertSame(StatusComponentState::UNKNOWN, StatusSnapshot::read()->components['service']->state);
        Hilos::$db = null;
        self::assertNull(StatusSnapshot::read()->incidents);
    }

    public function testDisabledStatusDoesNotMountOrReadItsSources(): void
    {
        (new ReflectionProperty(Hilos::class, 'appClass'))->setValue(null, StatusRuntimeOffHilos::class);
        Hilos::$rt = new StatusReadRuntime();
        Hilos::$rt->mountFeatureRuntime([]);
        Hilos::$rt->configure();
        self::assertFalse(isset(Hilos::$rt->hilosStatusComponents));
        self::assertSame(StatusComponentState::UNKNOWN, StatusSnapshot::read()->components['service']->state);
        self::assertSame(0, $this->database->reads);
        $this->expectException(RtCollectionNotFoundException::class);
        Hilos::$rt->hilosStatusComponents;
    }

    public function testProjectCannotRemountTheFeatureCollection(): void
    {
        $this->runtime->replaceComponentsForTest();
        $this->expectException(FeatureRuntimeOverwrittenException::class);
        Hilos::$rt->assertFeatureRuntimeIntact();
    }
}

abstract class StatusRuntimeHilos extends Hilos
{
    public const array AGENTS = [HilosAgentType::HILOS_INDEX => []];
    protected const array FEATURES = [HilosFeature::STATUS];
    protected const array STATUS_COMPONENTS = [
        'service' => ['name' => 'Service', 'description' => null, 'agentType' => HilosAgentType::HILOS_INDEX],
        'other' => ['name' => 'Other', 'description' => null, 'agentType' => HilosAgentType::HILOS_INDEX],
    ];
}

abstract class StatusRuntimeOffHilos extends Hilos
{
    public const array AGENTS = [HilosAgentType::HILOS_INDEX => []];
}

final class StatusReadRuntime extends RtContext
{
    public function configure(): void
    {
    }

    /** Deliberately invalid project registration, judged by the feature identity guard. */
    public function replaceComponentsForTest(): void
    {
        $this->_stateCollections[HilosStatusComponent::RT_COLLECTION] = StateHilosStatusComponents::init();
    }

    /** @param string $phase Isolated projection fixture, with no daemon or destructive operation */
    public function phase(string $phase): void
    {
        $freeze = ProtectedModeRuntime::create();
        $freeze->phase = $phase;
        $this->mountFeatureItem(ProtectedModeRuntime::RT_ITEM, $freeze);
    }
}

final class StatusReadDbContext extends HilosDbContext
{
    public int $reads = 0;

    /** @param StatusReadIncidents $incidents Scripted incident collection */
    public function __construct(private readonly StatusReadIncidents $incidents)
    {
        parent::__construct();
    }

    public function configure(): void
    {
    }

    /**
     * @param string $name Requested source
     * @return DbCollection Incident double or inherited source
     */
    public function __get(string $name): DbCollection
    {
        if ($name === HilosDbContext::statusIncidents) {
            ++$this->reads;
            return $this->incidents;
        }
        return parent::__get($name);
    }
}

/** Records the query's exact selection while serving scripted DB Views, with no SQL. */
final class StatusReadIncidents extends StatusIncidents
{
    public bool $unavailable = false;
    public array $selection = [];

    /**
     * @param string $column Queried incident column
     * @param int|string ...$values Declared active component keys
     * @return static Scripted open incident set
     */
    public function whereColumnIn(string $column, int|string ...$values): static
    {
        $this->selection = [$column, $values];
        if ($this->unavailable) {
            throw new DatabaseException('Incident storage is unavailable');
        }
        return $this;
    }
}

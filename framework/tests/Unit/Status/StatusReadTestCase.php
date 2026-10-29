<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Status;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Feature\Definition\StatusFeature;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\TruthSource\OwnershipDeclaration;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Item\StatusIncident as EntityStatusIncident;
use Hilos\Database\Entity\Item\Setting as EntitySetting;
use Hilos\Database\Object\Item\Setting as ObjectSetting;
use Hilos\Database\Entity\Item\StatusIncidentUpdate as EntityStatusIncidentUpdate;
use Hilos\Database\Object\Collection\StatusWorks as ObjectStatusWorks;
use Hilos\Database\View\Collection\StatusWorks;
use Hilos\Database\Object\Collection\StatusWorkComponents as ObjectStatusWorkComponents;
use Hilos\Database\View\Collection\StatusWorkComponents;
use Hilos\Database\Object\Collection\StatusMaintenancePeriods as ObjectStatusMaintenancePeriods;
use Hilos\Database\View\Collection\StatusMaintenancePeriods;
use Hilos\Database\Object\Collection\StatusIncidents as ObjectStatusIncidents;
use Hilos\Database\Object\Collection\StatusIncidentUpdates as ObjectStatusIncidentUpdates;
use Hilos\Database\Object\Collection\Settings as ObjectSettings;
use Hilos\Database\Object\Item\StatusIncident as ObjectStatusIncident;
use Hilos\Database\Object\Item\StatusIncidentUpdate as ObjectStatusIncidentUpdate;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\View\Collection\StatusIncidents;
use Hilos\Database\View\Collection\StatusIncidentUpdates;
use Hilos\Database\View\Collection\StatusSubscribers;
use Hilos\Status\StatusSubscriberCountsData;
use Hilos\Database\View\Collection\Settings;
use Hilos\Database\View\Collection\Users;
use Hilos\Database\View\Collection\AccountDeletions;
use Hilos\Database\View\Collection\UserMerges;
use Hilos\Hilos;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\State\Item\HilosStatusComponent;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Status\StatusAgent;
use Hilos\Status\StatusSettingsCatalog;
use Hilos\Tables\Status\HilosStatusComponentsTable;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Real manual ORM stores and represented feature runtime, without a database or daemon process. */
abstract class StatusReadTestCase extends TestCase
{
    protected StatusProjectionRuntime $runtime;
    protected StatusProjectionDbContext $database;
    protected StatusAgent $owner;

    private string $previousApp;
    private ?RtContext $previousRuntime;
    private ?HilosDbContext $previousDatabase;
    private ?SettingsAccessor $previousSettings;
    private ?TableContext $previousTables;
    private ?SignalRouter $previousRouter;
    private ?string $previousAgent;

    /** Mounts the production read APIs over isolated in-memory source rows. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApp = Hilos::appClass();
        $this->previousRuntime = Hilos::$rt;
        $this->previousDatabase = Hilos::$db;
        $this->previousSettings = Hilos::$setting;
        $this->previousTables = Hilos::$table;
        $this->previousRouter = Hilos::$sr;
        $this->previousAgent = ExecutionContext::currentAgentId();
        (new ReflectionProperty(Hilos::class, 'appClass'))->setValue(null, StatusProjectionHilos::class);
        Hilos::$sr = new SignalRouter();
        Hilos::$db = $this->database = new StatusProjectionDbContext();
        $this->database->configure();
        Hilos::$setting = new SettingsAccessor(StatusSettingsCatalog::class);
        Hilos::$rt = $this->runtime = new StatusProjectionRuntime();
        $this->runtime->mountFeatureRuntime([new StatusFeature()]);
        $this->runtime->configure();
        $this->runtime->bindStateCollectionNames();
        $this->runtime->phase(ProtectedModeRuntime::PHASE_INACTIVE);
        $this->owner = new StatusAgent();
        OwnershipDeclaration::claimAll($this->owner);
        ExecutionContext::setCurrentAgentId($this->owner->getId());
    }

    /** Releases claims and restores the facade after every case. */
    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterAgent($this->owner->getId());
        TruthSourceRegistry::unregisterAgent($this->owner->getId());
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent($this->owner->getId()));
        ExecutionContext::setCurrentAgentId($this->previousAgent);
        RtStaleness::clear(HilosStatusComponent::RT_COLLECTION, ['zeta', 'alpha', 'service']);
        Hilos::$rt = $this->previousRuntime;
        Hilos::$db = $this->previousDatabase;
        Hilos::$setting = $this->previousSettings;
        Hilos::$table = $this->previousTables;
        Hilos::$sr = $this->previousRouter;
        (new ReflectionProperty(Hilos::class, 'appClass'))->setValue(null, $this->previousApp);
        parent::tearDown();
    }

    /**
     * @param string $key Declared component
     * @param ?int $live Known live count, or null
     * @param ?int $at Observation epoch milliseconds UTC
     */
    protected function observe(string $key, ?int $live, ?int $at = 1700000000123): void
    {
        Hilos::$rt->hilosStatusComponents->actions->ensure($key, 2);
        Hilos::$rt->hilosStatusComponents[$key]->actions->update(2, $live, $at);
        $this->drain();
    }

    /**
     * @param int $id Incident id
     * @param string $key Component key
     * @param ?int $publishedAt Publication moment, or null for a candidate
     * @param ?int $suppressedAt Suppression moment, or null
     * @param bool $active Whether the incident is still open
     * @return EntityStatusIncident Mutable fixture source behind the real View item
     */
    protected function incident(
        int $id,
        string $key,
        ?int $publishedAt = null,
        ?int $suppressedAt = null,
        bool $active = true,
    ): EntityStatusIncident {
        $incident = new EntityStatusIncident();
        $incident->id = $id;
        $incident->component_key = $key;
        $incident->component_name = 'Stored ' . $key;
        $incident->active_component_key = $active ? $key : null;
        $incident->severity = 'major_outage';
        $incident->detected_at = 1700000000000;
        $incident->publish_at = 1700000300000;
        $incident->published_at = $publishedAt;
        $incident->suppressed_at = $suppressedAt;
        $this->database->addIncident($incident);
        return $incident;
    }

    /**
     * @param int $id Update id
     * @param int $incidentId Parent incident id
     * @param int $at Entry epoch milliseconds UTC
     * @param string $kind Timeline kind
     * @param string $text Plain text
     */
    protected function update(int $id, int $incidentId, int $at, string $kind = 'team', string $text = 'Team <text>'): void
    {
        $update = new EntityStatusIncidentUpdate();
        $update->id = $id;
        $update->incident_id = $incidentId;
        $update->kind = $kind;
        $update->severity = 'major_outage';
        $update->text = $text;
        $update->at = $at;
        $this->database->addUpdate($update);
    }

    /** @return list<object> Queued router messages, consumed */
    protected function drain(): array
    {
        $signals = [];
        while (($signal = Hilos::$sr->getNextQueuedSignal()) !== null) {
            $signals[] = $signal;
        }
        return $signals;
    }
}

abstract class StatusProjectionHilos extends Hilos
{
    public const array AGENTS = [HilosAgentType::HILOS_INDEX => [], 'mail' => []];
    public const array TABLES = [HilosStatusComponentsTable::TABLE => HilosStatusComponentsTable::class];
    protected const array FEATURES = [HilosFeature::STATUS];
    protected const array STATUS_COMPONENTS = [
        'zeta' => ['name' => 'Zeta API', 'description' => 'Public endpoints', 'agentType' => HilosAgentType::HILOS_INDEX],
        'alpha' => ['name' => 'Alpha mail', 'description' => null, 'agentType' => 'mail'],
    ];
}

abstract class StatusFallbackHilos extends Hilos
{
    public const array AGENTS = [HilosAgentType::HILOS_INDEX => []];
    protected const array FEATURES = [HilosFeature::STATUS];
}

final class StatusProjectionRuntime extends RtContext
{
    /** Feature runtime is mounted by the base fixture. */
    public function configure(): void
    {
    }

    /**
     * @param string $phase Protected-mode phase
     * @param ?int $startedAt Start epoch seconds UTC
     * @param ?string $operation Protected operation
     */
    public function phase(string $phase, ?int $startedAt = null, ?string $operation = null): void
    {
        $freeze = ProtectedModeRuntime::create();
        $freeze->phase = $phase;
        $freeze->startedAt = $startedAt;
        $freeze->operation = $operation;
        $freeze->initiatorAcceptKey = 'secret';
        $freeze->passHashes = ['secret-hash'];
        $this->mountFeatureItem(ProtectedModeRuntime::RT_ITEM, $freeze);
    }
}

final class StatusProjectionDbContext extends HilosDbContext
{
    /** Mounts actual ORM stores as complete manual sets. */
    public function configure(): void
    {
        $this->_objectCollections[self::statusIncidents] = ObjectStatusIncidents::initEmpty();
        $this->setRepresent(self::statusIncidents, StatusProjectionIncidents::class);
        $this->_objectCollections[self::statusIncidentUpdates] = ObjectStatusIncidentUpdates::initEmpty();
        $this->setRepresent(self::statusIncidentUpdates, StatusIncidentUpdates::class);
        $this->_objectCollections[self::statusWorks] = ObjectStatusWorks::initEmpty();
        $this->setRepresent(self::statusWorks, StatusWorks::class);
        $this->_objectCollections[self::statusWorkComponents] = ObjectStatusWorkComponents::initEmpty();
        $this->setRepresent(self::statusWorkComponents, StatusWorkComponents::class);
        $this->_objectCollections[self::statusMaintenancePeriods] = ObjectStatusMaintenancePeriods::initEmpty();
        $this->setRepresent(self::statusMaintenancePeriods, StatusProjectionMaintenancePeriods::class);
        $this->_objectCollections[self::settings] = ObjectSettings::initEmpty();
        $this->setRepresent(self::settings, Settings::class);
        $this->_dbItemCollections[self::statusSubscribers] = StatusProjectionSubscribers::initEmpty();
    }

    /**
     * @param Users $users Account standing reader
     * @param UserMerges $merges Merge standing reader
     * @param AccountDeletions $deletions Deletion standing reader
     */
    public function guardReaders(Users $users, UserMerges $merges, AccountDeletions $deletions): void
    {
        $this->_dbItemCollections[self::users] = $users;
        $this->_dbItemCollections[self::userMerges] = $merges;
        $this->_dbItemCollections[self::accountDeletions] = $deletions;
    }

    /** @param EntitySetting $setting Persisted setting fixture */
    public function addSetting(EntitySetting $setting): void
    {
        $this->_objectCollections[self::settings][$setting->id] = ObjectSetting::fromEntity($setting);
    }

    /** @param EntityStatusIncident $incident Fixture source */
    public function addIncident(EntityStatusIncident $incident): void
    {
        $this->_objectCollections[self::statusIncidents][$incident->id] = ObjectStatusIncident::fromEntity($incident);
    }

    /** @param EntityStatusIncidentUpdate $update Fixture source */
    public function addUpdate(EntityStatusIncidentUpdate $update): void
    {
        $this->_objectCollections[self::statusIncidentUpdates][$update->id] = ObjectStatusIncidentUpdate::fromEntity($update);
    }
}

/** Records the actual bounded selection; the production ORM still does the filtering. */
final class StatusProjectionIncidents extends StatusIncidents
{
    public bool $unavailable = false;
    public int $completed = 0;
    public array $historicalKeys = [];
    public int $historyReads = 0;
    public array $selection = [];
    public int $reads = 0;

    /**
     * @param string $column Queried column
     * @param int|string ...$values Selected active component keys
     * @return static Real filtered manual set
     */
    public function whereColumnIn(string $column, int|string ...$values): static
    {
        ++$this->reads;
        $this->selection = [$column, $values];
        if ($this->unavailable) {
            throw new DatabaseException('Incident storage unavailable');
        }
        return parent::whereColumnIn($column, ...$values);
    }

    /** @return int Supplied scalar boundary result, independent of the manual open-incident set */
    public function completedCount(): int
    {
        ++$this->historyReads;
        if ($this->unavailable) {
            throw new DatabaseException('Incident storage unavailable');
        }
        return $this->completed;
    }

    /** @return list<string> Supplied scalar historical key result */
    public function historyComponentKeys(): array
    {
        $this->completedCount();
        return $this->historicalKeys;
    }
}

/** Independent scalar boundary for the second history source. */
final class StatusProjectionMaintenancePeriods extends StatusMaintenancePeriods
{
    public bool $unavailable = false;
    public int $completed = 0;
    public array $historicalKeys = [];
    public int $historyReads = 0;

    /** @return int Supplied scalar boundary result */
    public function completedCount(): int
    {
        ++$this->historyReads;
        if ($this->unavailable) {
            throw new DatabaseException('Maintenance storage unavailable');
        }
        return $this->completed;
    }

    /** @return list<string> Supplied scalar historical key result */
    public function historyComponentKeys(): array
    {
        $this->completedCount();
        return $this->historicalKeys;
    }
}

/** The aggregate query is a separate typed boundary from incident fixture rows. */
final class StatusProjectionSubscribers extends StatusSubscribers
{
    /** @return StatusSubscriberCountsData Known empty subscriber set */
    public function counts(): StatusSubscriberCountsData
    {
        return new StatusSubscriberCountsData(0, 0, 0, 0);
    }
}

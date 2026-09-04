<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\SelfBroadcastRegistry;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Sync\DTO\DbSyncClearedSignalData;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Database\Context\DbContext;
use Hilos\Database\DatabaseException;
use Hilos\Database\DbSyncApplicator;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Hilos;
use Hilos\Socket\Worker\DTO\WorkerDbSyncClearedMessageDTO;
use PHPUnit\Framework\TestCase;

/**
 * Covers the HIL-472 fault: self-echo suppression for a collection clear used to ride
 * {@see SelfBroadcastRegistry}, which is deliberately best-effort — past its cap the
 * oldest registration is evicted. A clear registration evicted by ordinary row syncs
 * made the emitting process apply the truncate to itself a second time, blanking its
 * in-memory mirror on top of rows written after the clear.
 *
 * Suppression now compares the emitter identity carried in the payload, so it holds no
 * state that could be evicted, and applying a clear re-reads the collection instead of
 * blanking it.
 */
final class DbSyncClearSelfEchoTest extends TestCase
{
    /** Collection key the cleared and created syncs are queued for. */
    private const string COLLECTION_KEY = 'events';

    private ?DbContext $previousDb = null;

    private ?SignalRouter $previousSignalRouter = null;

    protected function setUp(): void
    {
        $this->previousDb = Hilos::$db;
        $this->previousSignalRouter = Hilos::$sr;
        // No router by default: a clear from another process must apply on its own merit.
        Hilos::$sr = null;
    }

    protected function tearDown(): void
    {
        Hilos::$db = $this->previousDb;
        Hilos::$sr = $this->previousSignalRouter;
    }

    public function testClearSuppressionSurvivesRegistryPressure(): void
    {
        $router = new SignalRouter();
        $router->queueDbSyncClearedSignal(new DbSyncClearedSignalData(self::COLLECTION_KEY));

        // One tick of ordinary row syncs used to be enough to push the clear registration out.
        for ($id = 1; $id <= SelfBroadcastRegistry::DEFAULT_MAX_ENTRIES; $id++) {
            $router->queueDbSyncSignal(
                SignalTypeConstants::DB_SYNC_CREATED,
                new DbSyncCreatedSignalData(self::COLLECTION_KEY, (string) $id, ['id' => $id]),
            );
        }

        // The verdict is read off the payload that actually left, not off the router's own
        // identity: an unstamped send would then read as someone else's clear and re-apply.
        $this->assertTrue($router->shouldSkipDbSyncClearApply($this->dequeueClear($router)->emitter));
    }

    public function testQueuedClearCarriesThisProcessEmitter(): void
    {
        $router = new SignalRouter();
        $router->queueDbSyncClearedSignal(new DbSyncClearedSignalData(self::COLLECTION_KEY, 'accept-key-7'));

        $signalData = $this->dequeueClear($router);
        $this->assertSame($router->getEmitter(), $signalData->emitter);
        // The accept key of the writing connection travels separately and is untouched.
        $this->assertSame('accept-key-7', $signalData->origin);
    }

    public function testEmitterSurvivesTheWorkerMessageRoundtrip(): void
    {
        $router = new SignalRouter();
        $router->queueDbSyncClearedSignal(new DbSyncClearedSignalData(self::COLLECTION_KEY));

        // The daemon fans the clear out to workers as this message, serialized as JSON.
        $transported = json_decode(
            (new WorkerDbSyncClearedMessageDTO($this->dequeueClear($router)))->toJson(),
            true,
        );
        $this->assertIsArray($transported);
        $delivered = WorkerDbSyncClearedMessageDTO::fromArray($transported)->signalData;

        $this->assertSame($router->getEmitter(), $delivered->emitter);
        $this->assertTrue($router->shouldSkipDbSyncClearApply($delivered->emitter));
    }

    public function testForeignClearIsApplied(): void
    {
        $router = new SignalRouter();

        $this->assertFalse($router->shouldSkipDbSyncClearApply('some-other-process'));
    }

    public function testUnstampedClearIsApplied(): void
    {
        $router = new SignalRouter();

        $this->assertFalse($router->shouldSkipDbSyncClearApply(null));
    }

    public function testTwoRoutersDoNotShareEmitterIdentity(): void
    {
        $this->assertNotSame((new SignalRouter())->getEmitter(), (new SignalRouter())->getEmitter());
    }

    public function testRepeatedClearApplyConvergesOnTheTableInsteadOfEmptyingTheMirror(): void
    {
        $objectCollection = $this->registerCollection([2]);

        // The truncate landed and the emitting process dropped its own rows. This is the
        // trap the re-read exists for: the mirror is empty AND still counts as fully
        // loaded, so nothing would ever read the table again.
        $objectCollection->clearInMemory();
        $this->assertTrue($objectCollection->isAllLoaded());

        // A row was written after the clear; then the same clear is applied once more.
        $objectCollection->setTableRows([7]);
        DbSyncApplicator::applyCleared(new DbSyncClearedSignalData(self::COLLECTION_KEY, emitter: 'other-process'));

        $this->assertSame(1, $objectCollection->count());
        $this->assertNotNull($objectCollection->get('7'));
    }

    public function testOwnClearEchoLeavesTheMirrorUntouched(): void
    {
        Hilos::$sr = $router = new SignalRouter();
        $objectCollection = $this->registerCollection([2]);

        // Rows written after this process ran the truncate itself must not be dropped by
        // the echo of its own clear.
        $objectCollection->setTableRows([]);
        DbSyncApplicator::applyCleared(
            new DbSyncClearedSignalData(self::COLLECTION_KEY, emitter: $router->getEmitter()),
        );

        $this->assertSame(1, $objectCollection->count());
        $this->assertNotNull($objectCollection->get('2'));
    }

    public function testUnreadableDatabaseLeavesTheCollectionMarkedForReReadInsteadOfKillingTheLoop(): void
    {
        $objectCollection = $this->registerCollection([2]);
        $objectCollection->failNextLoad();

        // Runs inside the worker message loop and the daemon signal loop: it must not throw.
        DbSyncApplicator::applyCleared(new DbSyncClearedSignalData(self::COLLECTION_KEY, emitter: 'other-process'));

        $this->assertSame(0, $objectCollection->count());
        // Not "loaded and empty" — otherwise the mirror would stay empty over a live table.
        $this->assertFalse($objectCollection->isAllLoaded());
    }

    /**
     * Registers a loaded eager collection as the active DB context.
     *
     * @param list<int> $tableRows Ids the fake table holds
     * @return ClearEchoObjects Registered collection, already loaded from the fake table
     */
    private function registerCollection(array $tableRows): ClearEchoObjects
    {
        $objectCollection = ClearEchoObjects::withTableRows($tableRows);
        $objectCollection->loadAllFromDB();
        Hilos::$db = ClearEchoDbContext::create(
            self::COLLECTION_KEY,
            $objectCollection,
            ClearEchoDbCollection::init(),
        );

        return $objectCollection;
    }

    /**
     * Takes the queued clear off the router and returns its payload.
     *
     * @param SignalRouter $router Router the clear was queued on
     * @return DbSyncClearedSignalData Payload as it leaves this process
     */
    private function dequeueClear(SignalRouter $router): DbSyncClearedSignalData
    {
        $signal = $router->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $signalData = $signal->data;
        $this->assertInstanceOf(DbSyncClearedSignalData::class, $signalData);

        return $signalData;
    }
}

/**
 * Minimal single-column entity fixture for the clear-echo reproduction.
 */
final class ClearEchoEntity extends Entity
{
    public const string _table = 'clear_echo_test';
    public const string _primary = 'id';
    public const array _columns = ['id'];
    public const array _types = ['id' => 'integer'];

    public ?int $id = null;

    public static function withId(int $id): self
    {
        $entity = new self();
        $entity->id = $id;

        return $entity;
    }
}

/**
 * Minimal object fixture wrapping the clear-echo entity.
 */
final class ClearEchoObject extends Object_
{
    public const string ENTITY_CLASS = ClearEchoEntity::class;
}

/**
 * Eager object collection whose "table" is an in-test row list, so a re-read is
 * observable without a database.
 */
final class ClearEchoObjects extends Objects
{
    public const string OBJECT_CLASS = ClearEchoObject::class;

    /** @var list<int> Ids the fake table currently holds */
    private array $tableRows = [];

    /** @var bool Whether the next read of the fake table fails as an unreachable database */
    private bool $failNextLoad = false;

    /**
     * @param list<int> $tableRows Ids the fake table holds
     */
    public static function withTableRows(array $tableRows): self
    {
        $collection = self::initDB(self::LAZY_STRATEGY_NONE);
        $collection->tableRows = $tableRows;

        return $collection;
    }

    /**
     * @param list<int> $tableRows Ids the fake table holds from now on
     */
    public function setTableRows(array $tableRows): void
    {
        $this->tableRows = $tableRows;
    }

    /**
     * Makes the next read fail the way an unreachable database would.
     */
    public function failNextLoad(): void
    {
        $this->failNextLoad = true;
    }

    /**
     * Reads the fake table instead of the database.
     *
     * @throws DatabaseException When the fake table was told to be unreachable
     */
    public function loadAllFromDB(): void
    {
        $this->clearInMemory();
        if ($this->failNextLoad) {
            $this->failNextLoad = false;
            throw new DatabaseException('fake table unreachable');
        }

        foreach ($this->tableRows as $id) {
            $this->hydrate((string) $id, ClearEchoObject::fromEntity(ClearEchoEntity::withId($id)));
        }
        $this->_allLoaded = true;
    }
}

/**
 * Minimal DB collection fixture wrapping the clear-echo object collection.
 */
final class ClearEchoDbCollection extends DbCollection
{
}

/**
 * Test context holding a single registered collection without a real DB.
 */
final class ClearEchoDbContext extends DbContext
{
    public static function create(string $name, Objects $objectCollection, DbCollection $dbCollection): self
    {
        $context = new self();
        $context->_objectCollections[$name] = $objectCollection;
        $context->_dbItemCollections[$name] = $dbCollection;

        return $context;
    }

    public function configure(): void
    {
    }
}

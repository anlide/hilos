<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncDeletedSignalData;
use Hilos\Core\Sync\DTO\DbSyncSignalDataInterface;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DbSyncApplicator;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Hilos;
use Hilos\Socket\Worker\DTO\WorkerDbSyncCreatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncDeletedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncUpdatedMessageDTO;
use PHPUnit\Framework\TestCase;

/**
 * Self-echo suppression for a ROW sync is the emitter stamp the payload carries, and nothing
 * else: a frame stamped by this process is its own echo, any other frame is applied.
 *
 * Keyed by the row alone, suppression once took a foreign write for an own echo (HIL-737).
 * Keyed by the stamp plus one mark per row, it let the echo of a stale own write through as
 * soon as an earlier echo of the same row had spent the mark, or a burst of other rows had
 * evicted it (HIL-2103). The verdict now holds no mark at all.
 */
final class DbSyncRowSelfEchoEmitterTest extends TestCase
{
    /** Collection key every row sync here is queued for. */
    private const string COLLECTION_KEY = 'events';

    /** Row id the cases are about. */
    private const string ID = '7';

    private ?HilosDbContext $previousDb = null;
    private ?SignalRouter $previousRouter = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousDb = Hilos::$db;
        $this->previousRouter = Hilos::$sr;
    }

    protected function tearDown(): void
    {
        Hilos::$db = $this->previousDb;
        Hilos::$sr = $this->previousRouter;
        parent::tearDown();
    }

    public function testForeignRowSyncIsAppliedAndOwnEchoStillSkipped(): void
    {
        $router = new SignalRouter();
        $this->queueOwnRowSync($router);

        $this->assertFalse($router->shouldSkipDbSyncApply('some-other-process'));
        // The foreign fact took nothing away, so this process's own echo is still caught.
        $this->assertTrue($router->shouldSkipDbSyncApply($router->getEmitter()));
    }

    /**
     * An unstamped row sync counts as someone else's, exactly as an unstamped clear does.
     * The CLI `db:announce` sends one on purpose and must reach every process.
     */
    public function testUnstampedRowSyncIsApplied(): void
    {
        $router = new SignalRouter();
        $this->queueOwnRowSync($router);

        $this->assertFalse($router->shouldSkipDbSyncApply(null));
    }

    public function testQueuedRowSyncCarriesThisProcessEmitter(): void
    {
        $router = new SignalRouter();
        $router->queueDbSyncSignal(
            SignalTypeConstants::DB_SYNC_CREATED,
            new DbSyncCreatedSignalData(self::COLLECTION_KEY, self::ID, ['id' => 7], 'accept-key-7'),
        );

        $signal = $router->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $signalData = $signal->data;
        $this->assertInstanceOf(DbSyncCreatedSignalData::class, $signalData);

        $this->assertSame($router->getEmitter(), $signalData->emitter);
        // The accept key of the writing connection travels separately and is untouched.
        $this->assertSame('accept-key-7', $signalData->origin);
        // The verdict is read off the payload that actually left, not off the router's own
        // identity: an unstamped send would read back as another process's write.
        $this->assertTrue($router->shouldSkipDbSyncApply($signalData->emitter));
    }

    /**
     * The stamp only works if it survives the pipe. Losing either the toArray() line or the
     * fromArray() one would leave every row frame unstamped, every frame would then read as
     * someone else's, and self-echo suppression would be dead without a single failing
     * assertion above — those read the payload object, this one reads what actually travels.
     */
    public function testReceiptMetadataAndEmitterSurviveEveryWorkerMessageRoundtrip(): void
    {
        foreach ([
            [
                SignalTypeConstants::DB_SYNC_CREATED,
                new DbSyncCreatedSignalData(
                    self::COLLECTION_KEY,
                    self::ID,
                    ['id' => 7],
                    receiptId: 47,
                    writerAgentId: 'chat:7',
                ),
                WorkerDbSyncCreatedMessageDTO::class,
            ],
            [
                SignalTypeConstants::DB_SYNC_UPDATED,
                new DbSyncUpdatedSignalData(
                    self::COLLECTION_KEY,
                    self::ID,
                    ['name' => 'Grace'],
                    previous: ['name' => 'Ada'],
                    receiptId: 47,
                    writerAgentId: 'chat:7',
                ),
                WorkerDbSyncUpdatedMessageDTO::class,
            ],
            [
                SignalTypeConstants::DB_SYNC_DELETED,
                new DbSyncDeletedSignalData(
                    self::COLLECTION_KEY,
                    self::ID,
                    ['name' => 'Grace'],
                    receiptId: 47,
                    writerAgentId: 'chat:7',
                ),
                WorkerDbSyncDeletedMessageDTO::class,
            ],
        ] as [$signalType, $payload, $workerMessageClass]) {
            $router = new SignalRouter();
            $router->queueDbSyncSignal($signalType, $payload);

            $signal = $router->getNextQueuedSignal();
            $this->assertNotNull($signal);
            $signalData = $signal->data;
            $this->assertInstanceOf(DbSyncSignalDataInterface::class, $signalData);

            // The daemon fans the row sync out to workers as this message, serialized as JSON.
            $transported = json_decode((new $workerMessageClass($signalData))->toJson(), true);
            $this->assertIsArray($transported);
            $delivered = $workerMessageClass::fromArray($transported)->signalData;

            $this->assertSame($router->getEmitter(), $delivered->emitter);
            $this->assertSame(47, $delivered->receiptId);
            $this->assertSame('chat:7', $delivered->writerAgentId);
            $this->assertTrue($router->shouldSkipDbSyncApply($delivered->emitter));
        }
    }

    /**
     * Three own writes of one row are in flight. The echo of the first may not spend anything
     * the echoes of the later ones rely on: the echo of v3 arrives after this process wrote v4,
     * and applied, it would put the stale v3 back over the newer value in this process's copy.
     */
    public function testNoOwnEchoOfARowLandsOverALaterWriteOfIt(): void
    {
        $collection = $this->holdRow('7', 'v3');
        $router = $this->installRouter();
        $first = $this->echoOf($router, SignalTypeConstants::DB_SYNC_UPDATED, $this->updated('7', 'v1'));
        $second = $this->echoOf($router, SignalTypeConstants::DB_SYNC_UPDATED, $this->updated('7', 'v2'));
        $third = $this->echoOf($router, SignalTypeConstants::DB_SYNC_UPDATED, $this->updated('7', 'v3'));

        DbSyncApplicator::applyUpdated($first);
        $this->heldObject($collection, '7')->rewrite('v4');
        $this->echoOf($router, SignalTypeConstants::DB_SYNC_UPDATED, $this->updated('7', 'v4'));
        DbSyncApplicator::applyUpdated($second);
        DbSyncApplicator::applyUpdated($third);

        $this->assertSame('v4', $this->heldValue($collection, '7'), 'A stale own echo landed over a later write');
    }

    /**
     * A row with a key of its own (not one the database hands out) can be updated, deleted and
     * created again under the same key. None of the three own echoes may touch the copy, which
     * already holds the row as the last of those writes left it.
     */
    public function testOwnEchoesOfARowDeletedAndCreatedAgainLeaveItAsTheLastWriteDid(): void
    {
        $router = $this->installRouter();
        $updated = $this->echoOf($router, SignalTypeConstants::DB_SYNC_UPDATED, $this->updated('alpha', 'updated'));
        $deleted = $this->echoOf(
            $router,
            SignalTypeConstants::DB_SYNC_DELETED,
            new DbSyncDeletedSignalData('rows', 'alpha', ['id' => 'alpha', 'value' => 'updated']),
        );
        $created = $this->echoOf(
            $router,
            SignalTypeConstants::DB_SYNC_CREATED,
            new DbSyncCreatedSignalData('rows', 'alpha', ['id' => 'alpha', 'value' => 'created again']),
        );
        $collection = $this->holdRow('alpha', 'created again');

        DbSyncApplicator::applyUpdated($updated);
        $this->assertSame('created again', $this->heldValue($collection, 'alpha'), 'The update echo changed the row');
        DbSyncApplicator::applyDeleted($deleted);
        $this->assertSame('created again', $this->heldValue($collection, 'alpha'), 'The delete echo took out the row');
        DbSyncApplicator::applyCreated($created);
        $this->assertSame('created again', $this->heldValue($collection, 'alpha'), 'The create echo changed the row');
    }

    /**
     * Many own writes are in flight at once: 10 001 rows, the oldest of them written twice so
     * that its copy is past the value its first echo carries. The echo of that oldest write is
     * still this process's own, however many writes were sent after it.
     */
    public function testTheEchoOfTheOldestOfManyOwnWritesIsStillSkipped(): void
    {
        $collection = $this->holdRow('0', 'v2');
        $router = $this->installRouter();
        $oldest = $this->echoOf($router, SignalTypeConstants::DB_SYNC_UPDATED, $this->updated('0', 'v1'));
        $this->echoOf($router, SignalTypeConstants::DB_SYNC_UPDATED, $this->updated('0', 'v2'));
        for ($row = 1; $row <= 10_000; $row++) {
            $router->queueDbSyncSignal(SignalTypeConstants::DB_SYNC_UPDATED, $this->updated((string)$row, 'v1'));
        }

        DbSyncApplicator::applyUpdated($oldest);

        $this->assertSame('v2', $this->heldValue($collection, '0'), 'The oldest own echo was applied as foreign');
    }

    /**
     * Queues a row sync of this process, so its echo is still to come.
     *
     * @param SignalRouter $router Router the sync is queued on
     */
    private function queueOwnRowSync(SignalRouter $router): void
    {
        $router->queueDbSyncSignal(
            SignalTypeConstants::DB_SYNC_CREATED,
            new DbSyncCreatedSignalData(self::COLLECTION_KEY, self::ID, ['id' => 7]),
        );
    }

    /**
     * Mounts a copy of the "rows" collection holding one row, as this process's memory holds it.
     *
     * @param string $id Key of the held row
     * @param string $value Value the copy holds for it
     * @return RowSelfEchoObjects Collection the applicator writes into
     */
    private function holdRow(string $id, string $value): RowSelfEchoObjects
    {
        $collection = RowSelfEchoObjects::initEmpty();
        $collection[$id] = RowSelfEchoObject::fromEntity(RowSelfEchoEntity::fromRow(['id' => $id, 'value' => $value]));
        Hilos::$db = RowSelfEchoDbContext::create($collection);

        return $collection;
    }

    /**
     * Makes a fresh router the one the applicator asks for its verdict.
     *
     * @return SignalRouter Router of this process
     */
    private function installRouter(): SignalRouter
    {
        Hilos::$sr = new SignalRouter();

        return Hilos::$sr;
    }

    /**
     * Sends a frame of this process and takes it back off the queue, stamped, as its echo arrives.
     *
     * @template T of DbSyncSignalDataInterface
     * @param SignalRouter $router Router of this process
     * @param string $signalType DB sync signal type of the frame
     * @param T $payload Frame this process sends
     * @return T The same frame as it left the process
     */
    private function echoOf(
        SignalRouter $router,
        string $signalType,
        DbSyncSignalDataInterface $payload,
    ): DbSyncSignalDataInterface {
        $router->queueDbSyncSignal($signalType, $payload);
        $signal = $router->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertInstanceOf($payload::class, $signal->data);

        return $signal->data;
    }

    /**
     * Builds the diff of one write of the "value" column.
     *
     * @param string $id Key of the written row
     * @param string $value Value the write left
     * @return DbSyncUpdatedSignalData Diff payload of that write
     */
    private function updated(string $id, string $value): DbSyncUpdatedSignalData
    {
        return new DbSyncUpdatedSignalData('rows', $id, ['value' => $value]);
    }

    /**
     * Reads the row out of the copy; a row missing from it fails the case.
     *
     * @param RowSelfEchoObjects $collection Copy of the collection
     * @param string $id Key of the row
     * @return RowSelfEchoObject Object the copy holds for that key
     */
    private function heldObject(RowSelfEchoObjects $collection, string $id): RowSelfEchoObject
    {
        $object = $collection[$id] ?? null;
        $this->assertInstanceOf(RowSelfEchoObject::class, $object, "The copy no longer holds row {$id}");

        return $object;
    }

    /**
     * Reads the value the copy holds for the row.
     *
     * @param RowSelfEchoObjects $collection Copy of the collection
     * @param string $id Key of the row
     * @return mixed Value of its "value" column
     */
    private function heldValue(RowSelfEchoObjects $collection, string $id): mixed
    {
        return $this->heldObject($collection, $id)->toArray()['value'];
    }
}

/**
 * Row with a key of its own and one value column.
 */
final class RowSelfEchoEntity extends Entity
{
    public const string _table = 'rows';
    public const string _primary = 'id';
    public const array _columns = ['id', 'value'];
    public const array _types = ['id' => 'string', 'value' => 'string'];

    public ?string $id = null;
    public string $value = '';
}

/**
 * Object over that row, with the in-memory half of a local write.
 */
final class RowSelfEchoObject extends Object_
{
    public const string ENTITY_CLASS = RowSelfEchoEntity::class;

    /** @param string $value Value a local write of this process leaves in its copy */
    public function rewrite(string $value): void
    {
        $this->entity->value = $value;
    }
}

/**
 * Copy of the "rows" collection held in memory.
 */
final class RowSelfEchoObjects extends Objects
{
    public const string OBJECT_CLASS = RowSelfEchoObject::class;
}

/**
 * Database context holding the "rows" collection.
 */
final class RowSelfEchoDbContext extends HilosDbContext
{
    /**
     * @param Objects $objectCollection Collection the applicator writes into
     * @return self Context holding that one collection
     */
    public static function create(Objects $objectCollection): self
    {
        $context = new self();
        $context->_objectCollections['rows'] = $objectCollection;

        return $context;
    }

    public function configure(): void
    {
    }
}

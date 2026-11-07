<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\SignalConstants;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DbSyncApplicator;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\PhpType;
use Hilos\Hilos;
use Hilos\Socket\Worker\DTO\WorkerDbSyncCreatedMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerDbSyncUpdatedMessageDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/**
 * Real commits are required: an always-rollback root would discard the DB_SYNC announcements.
 * Only this process's ORM, SQL storage and decoded payload application are exercised.
 */
final class EntityMomentRoundTripIntegrationTest extends FrameworkIntegrationTestCase
{
    private const string AGENT_ID = 'moment-round-trip-test';

    private ?SignalRouter $previousSignalRouter = null;
    private ?HilosDbContext $previousDb = null;

    /** @return iterable<string, array{int, ?int}> Signed BIGINT moments and SQL NULL */
    public static function momentValues(): iterable
    {
        yield 'absent' => [0, null];
        yield 'epoch' => [0, 0];
        yield 'before epoch' => [-1, -1];
        yield 'negative milliseconds' => [-1234567890123, -1234567890123];
        yield 'after 2038' => [2147483648001, 2147483648001];
        yield 'millisecond remainder' => [1767225600123, 1767225600123];
    }

    /** @return iterable<string, array{int, ?int, int, ?int}> Stored moments and their replacements */
    public static function momentUpdates(): iterable
    {
        yield 'absent to epoch' => [0, null, -1, 0];
        yield 'epoch to absent' => [0, 0, -1, null];
        yield 'negative to absent' => [-1, -1, -1234567890123, null];
        yield 'negative milliseconds to after 2038' => [-1234567890123, -1234567890123, 2147483648001, 2147483648001];
        yield 'after 2038 to milliseconds' => [2147483648001, 2147483648001, 1767225600123, 1767225600123];
        yield 'milliseconds to absent' => [1767225600123, 1767225600123, 0, null];
    }

    /**
     * @param int $required Required moment
     * @param ?int $optional Nullable moment
     */
    #[DataProvider('momentValues')]
    public function testOrmInsertStoresExactBigintsAndNull(int $required, ?int $optional): void
    {
        $object = $this->insertMoment($required, $optional);
        self::assertSame(['id' => 7, 'occurred_at' => $required, 'optional_at' => $optional], $object->toArray());
        $this->assertStoredMoments($required, $optional);

        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($signal);
        self::assertSame(SignalConstants::DB_SYNC_CREATED, $signal->signalType->getType());
        self::assertInstanceOf(DbSyncCreatedSignalData::class, $signal->data);
        self::assertSame($object->toArray(), $signal->data->row);
        self::assertSame(MomentRoundTripObjects::COLLECTION_KEY, $signal->data->collectionKey);
        self::assertSame('7', $signal->data->idString);
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
    }

    /**
     * @param int $required Required moment
     * @param ?int $optional Nullable moment
     */
    #[DataProvider('momentValues')]
    public function testEntityReadsRealBigintColumnsWithoutRescaling(int $required, ?int $optional): void
    {
        $this->insertMoment($required, $optional);
        $loaded = MomentRoundTripEntity::getById(7);

        self::assertNotNull($loaded);
        self::assertTrue($loaded->isRelated());
        self::assertSame(['id' => 7, 'occurred_at' => $required, 'optional_at' => $optional], $loaded->toArray());
    }

    /**
     * @param int $beforeRequired Stored required moment
     * @param ?int $beforeOptional Stored nullable moment
     * @param int $afterRequired Replacement required moment
     * @param ?int $afterOptional Replacement nullable moment
     */
    #[DataProvider('momentUpdates')]
    public function testUpdateAnnouncesExactRowAndPreviousOnlyAfterCommit(
        int $beforeRequired,
        ?int $beforeOptional,
        int $afterRequired,
        ?int $afterOptional,
    ): void {
        $object = $this->insertMoment($beforeRequired, $beforeOptional);
        self::assertNotNull(Hilos::$sr->getNextQueuedSignal());
        $object->setMoments($afterRequired, $afterOptional);
        $this->syncAndCommit($object);

        $signal = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($signal);
        self::assertSame(SignalConstants::DB_SYNC_UPDATED, $signal->signalType->getType());
        self::assertInstanceOf(DbSyncUpdatedSignalData::class, $signal->data);
        self::assertSame(MomentRoundTripObjects::COLLECTION_KEY, $signal->data->collectionKey);
        self::assertSame('7', $signal->data->idString);
        self::assertSame(['occurred_at' => $afterRequired, 'optional_at' => $afterOptional], $signal->data->row);
        self::assertSame(['occurred_at' => $beforeRequired, 'optional_at' => $beforeOptional], $signal->data->previous);
        self::assertSame([], $object->getChangedColumns());
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
        $this->assertStoredMoments($afterRequired, $afterOptional);
    }

    /**
     * @param int $beforeRequired Stored required moment
     * @param ?int $beforeOptional Stored nullable moment
     * @param int $afterRequired Replacement required moment
     * @param ?int $afterOptional Replacement nullable moment
     */
    #[DataProvider('momentUpdates')]
    public function testCommittedPayloadsDecodeAndApplyWithoutRepeatedConversion(
        int $beforeRequired,
        ?int $beforeOptional,
        int $afterRequired,
        ?int $afterOptional,
    ): void {
        $object = $this->insertMoment($beforeRequired, $beforeOptional);
        $created = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($created);
        self::assertInstanceOf(DbSyncCreatedSignalData::class, $created->data);
        $object->setMoments($afterRequired, $afterOptional);
        $this->syncAndCommit($object);
        $updated = Hilos::$sr->getNextQueuedSignal();
        self::assertNotNull($updated);
        self::assertInstanceOf(DbSyncUpdatedSignalData::class, $updated->data);

        $createdMessage = WorkerDbSyncCreatedMessageDTO::fromArray(json_decode(
            (new WorkerDbSyncCreatedMessageDTO($created->data))->toJson(),
            true,
            flags: JSON_THROW_ON_ERROR,
        ));
        $updatedMessage = WorkerDbSyncUpdatedMessageDTO::fromArray(json_decode(
            (new WorkerDbSyncUpdatedMessageDTO($updated->data))->toJson(),
            true,
            flags: JSON_THROW_ON_ERROR,
        ));
        self::assertSame(['id' => 7, 'occurred_at' => $beforeRequired, 'optional_at' => $beforeOptional], $createdMessage->signalData->row);
        self::assertSame(['occurred_at' => $afterRequired, 'optional_at' => $afterOptional], $updatedMessage->signalData->row);
        self::assertSame(['occurred_at' => $beforeRequired, 'optional_at' => $beforeOptional], $updatedMessage->signalData->previous);

        DbSyncApplicator::applyCreated($createdMessage->signalData, skipSelfBroadcastCheck: false);
        $received = Hilos::$db->mountedObjectCollection(MomentRoundTripObjects::COLLECTION_KEY)['7'];
        self::assertInstanceOf(MomentRoundTripObject::class, $received);
        self::assertSame($createdMessage->signalData->row, $received->toArray());
        foreach ([1, 2] as $application) {
            DbSyncApplicator::applyUpdated($updatedMessage->signalData, skipSelfBroadcastCheck: false);
            self::assertSame(
                ['id' => 7, 'occurred_at' => $afterRequired, 'optional_at' => $afterOptional],
                $received->toArray(),
                'Application ' . $application,
            );
            self::assertSame([], $received->getChangedColumns());
        }
        self::assertNull(Hilos::$sr->getNextQueuedSignal());
        $this->assertStoredMoments($afterRequired, $afterOptional);
    }

    /** Creates only the scratch schema, collection claim and facades used by this case. */
    protected function setUp(): void
    {
        $this->previousSignalRouter = Hilos::$sr;
        $this->previousDb = Hilos::$db;
        parent::setUp();

        Hilos::$sr = new SignalRouter();
        Hilos::$db = new MomentRoundTripDbContext();
        Hilos::$db->configure();
        TruthSourceRegistry::register(MomentRoundTripObjects::COLLECTION_KEY, TruthSourceKeys::all(), self::AGENT_ID);
        Database::sql('DROP TABLE IF EXISTS `' . MomentRoundTripEntity::_table . '`');
        Database::sql(
            'CREATE TABLE `' . MomentRoundTripEntity::_table . '` ('
            . '`id` INT NOT NULL PRIMARY KEY,'
            . '`occurred_at` BIGINT NOT NULL,'
            . '`optional_at` BIGINT NULL'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        );
    }

    /** Ends any failed handler before DDL, removes the fixture claim and restores both facades. */
    protected function tearDown(): void
    {
        try {
            Database::handlerEnd();
            if (Database::isConnected()) {
                Database::sql('DROP TABLE IF EXISTS `' . MomentRoundTripEntity::_table . '`');
            }
        } finally {
            TruthSourceRegistry::unregister(MomentRoundTripObjects::COLLECTION_KEY, self::AGENT_ID);
            Hilos::$db = $this->previousDb;
            Hilos::$sr = $this->previousSignalRouter;
            parent::tearDown();
        }
    }

    /**
     * @param int $required Required moment
     * @param ?int $optional Nullable moment
     * @return MomentRoundTripObject Committed row through the ordinary Object write path
     */
    private function insertMoment(int $required, ?int $optional): MomentRoundTripObject
    {
        $entity = new MomentRoundTripEntity();
        $entity->id = 7;
        $entity->occurred_at = $required;
        $entity->optional_at = $optional;
        $object = MomentRoundTripObject::fromEntity($entity);
        $this->syncAndCommit($object);

        return $object;
    }

    /** @param MomentRoundTripObject $object Row whose sync announcement must wait for commit */
    private function syncAndCommit(MomentRoundTripObject $object): void
    {
        Database::transactionStart();
        try {
            $object->sync();
            self::assertNull(Hilos::$sr->getNextQueuedSignal());
            Database::transactionCommit();
        } catch (Throwable $failure) {
            Database::transactionRollbackSilent();
            throw $failure;
        }
    }

    /**
     * @param int $required Exact signed integer stored by the ORM
     * @param ?int $optional Exact signed integer or SQL NULL stored by the ORM
     */
    private function assertStoredMoments(int $required, ?int $optional): void
    {
        $rows = Database::sql('SELECT `id`, `occurred_at`, `optional_at` FROM `' . MomentRoundTripEntity::_table . '`')->rows();
        self::assertCount(1, $rows);
        // SQL text casts keep this independent of mysqli's native integer return policy.
        self::assertSame('7', (string)$rows[0]['id']);
        self::assertSame((string)$required, (string)$rows[0]['occurred_at']);
        self::assertArrayHasKey('optional_at', $rows[0]);
        if ($optional === null) {
            self::assertNull($rows[0]['optional_at']);
        } else {
            self::assertNotNull($rows[0]['optional_at']);
            self::assertSame((string)$optional, (string)$rows[0]['optional_at']);
        }
    }
}

/** Scratch schema, deliberately absent from production migrations. */
final class MomentRoundTripEntity extends Entity
{
    public const string id = 'id';
    public const string occurred_at = 'occurred_at';
    public const string optional_at = 'optional_at';

    public const string _table = 'moment_round_trip_fixture';
    public const string _primary = self::id;
    public const array _columns = [self::id, self::occurred_at, self::optional_at];
    public const array _types = [
        self::id => PhpType::INTEGER->value,
        self::occurred_at => PhpType::INTEGER->value,
        self::optional_at => PhpType::INTEGER->value,
    ];
    public const array _moments = [self::occurred_at, self::optional_at];
    public const string _setVia = Entity::SET_STANDALONE;
    public const bool _setRoot = false;

    public int $id;
    public int $occurred_at;
    public ?int $optional_at;
}

/** @extends Object_<MomentRoundTripEntity> */
final class MomentRoundTripObject extends Object_
{
    public const string ENTITY_CLASS = MomentRoundTripEntity::class;
    public const string OBJECT_COLLECTION_CLASS = MomentRoundTripObjects::class;

    /**
     * @param int $required Required moment
     * @param ?int $optional Nullable moment
     */
    public function setMoments(int $required, ?int $optional): void
    {
        $this->entity->occurred_at = $required;
        $this->entity->optional_at = $optional;
    }
}

/** @extends Objects<MomentRoundTripObject> */
final class MomentRoundTripObjects extends Objects
{
    public const string OBJECT_CLASS = MomentRoundTripObject::class;
    public const string COLLECTION_KEY = MomentRoundTripEntity::_table;
}

/** Mounts only the scratch collection for real DB-sync application. */
final class MomentRoundTripDbContext extends HilosDbContext
{
    /** Installs an empty receiving mirror without loading the table. */
    public function configure(): void
    {
        $this->_objectCollections[MomentRoundTripObjects::COLLECTION_KEY] = MomentRoundTripObjects::initEmpty();
    }
}

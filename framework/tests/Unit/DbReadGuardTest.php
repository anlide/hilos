<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Source\Interest\SourceConsumer;
use Hilos\Core\Source\Interest\SourceInterestRegistry;
use Hilos\Core\Source\SourceChange;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Exception\DbCollectionNotReadableException;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Database\View\Item\DbItem;
use PHPUnit\Framework\TestCase;

/**
 * What a worker may read out of the database, and what it is refused (HIL-750).
 *
 * The rows would come back either way - they are in a database every process shares - and that
 * is exactly why the refusal exists. A worker the master has not written into its reader map is
 * a worker no later write reaches, so the copy it caches is right once and then silently wrong,
 * with nothing in the answer to say from when. The guard turns that into a wiring error at the
 * first read instead of a stale row at some read after it.
 *
 * The two messages are two different defects: nothing declared the collection means the reader
 * was never wired up, while declared-but-not-ready means it was and the confirmation is late.
 */
final class DbReadGuardTest extends TestCase
{
    /** @var string Consumer the cases declare their interest under */
    private const string CONSUMER = 'db_read_guard_test';

    protected function setUp(): void
    {
        // The guard only decides anything where the copy is addressed, which is a worker;
        // elsewhere every mounted collection answers a read and there is nothing to prove.
        SourceInterestRegistry::readsWhatIsDelivered();
    }

    protected function tearDown(): void
    {
        SourceInterestRegistry::readsWhatItMounts();
        SourceInterestRegistry::releaseConsumer(SourceConsumer::agent(self::CONSUMER));

        parent::tearDown();
    }

    public function testACollectionNobodyDeclaredIsRefusedRatherThanRead(): void
    {
        $db = DbReadGuardDbContext::create();

        $this->expectException(DbCollectionNotReadableException::class);
        $this->expectExceptionMessage("no reader interest is registered for database collection");

        $db->{DbReadGuardDbContext::COLLECTION};
    }

    /**
     * The window between saying what is read and being told it is addressed is the one a reader
     * must not be answered in: the master has not written this worker down yet, so a write
     * landing right now is a write this process will never hear about.
     */
    public function testADeclaredCollectionIsStillRefusedUntilItsReadinessArrives(): void
    {
        $db = DbReadGuardDbContext::create();
        SourceInterestRegistry::register(
            SourceChange::KIND_DB,
            DbReadGuardDbContext::COLLECTION,
            SourceConsumer::agent(self::CONSUMER),
        );

        $this->expectException(DbCollectionNotReadableException::class);
        $this->expectExceptionMessage("was declared but its readiness has not arrived yet");

        $db->{DbReadGuardDbContext::COLLECTION};
    }

    public function testAReadyCollectionIsHandedOverAsBefore(): void
    {
        $db = DbReadGuardDbContext::create();
        SourceInterestRegistry::register(
            SourceChange::KIND_DB,
            DbReadGuardDbContext::COLLECTION,
            SourceConsumer::agent(self::CONSUMER),
        );
        SourceInterestRegistry::markReady(SourceChange::KIND_DB, DbReadGuardDbContext::COLLECTION);

        $this->assertInstanceOf(DbReadGuardDbCollection::class, $db->{DbReadGuardDbContext::COLLECTION});
    }

    /**
     * The delivery paths are not judged by the guard, and must not be: the frame applicator and
     * the cache repair reach their collection through these accessors, and they are the very
     * thing that makes a collection ready. Guarded, they would refuse to deliver the state whose
     * absence is what they were refused for.
     */
    public function testTheDeliveryAccessorsAnswerForACollectionNobodyDeclared(): void
    {
        $db = DbReadGuardDbContext::create();

        $this->assertInstanceOf(
            DbReadGuardObjects::class,
            $db->getObjectCollection(DbReadGuardDbContext::COLLECTION),
        );
        $this->assertInstanceOf(
            DbReadGuardDbCollection::class,
            $db->getDbItemCollection(DbReadGuardDbContext::COLLECTION),
        );
    }

    /**
     * A collection that does not exist is a different defect from one that may not be read, and
     * has to stay so: the guard sits behind the existence check, or a typo in a collection name
     * would report itself as missing wiring.
     */
    public function testAMissingCollectionStillReportsItselfAsMissing(): void
    {
        $db = DbReadGuardDbContext::create();

        $this->expectExceptionMessage('does not exist');

        $db->noSuchCollection;
    }
}

/**
 * Minimal stored row: the guard never reaches one, and a lazy-by-key store never loads one.
 */
final class DbReadGuardObject extends Object_
{
    public const string ENTITY_CLASS = DbReadGuardEntity::class;
}

/**
 * Minimal single-column entity behind that row.
 */
final class DbReadGuardEntity extends Entity
{
    public const string _table = 'db_read_guard_test';
    public const string _primary = 'id';
    public const array _columns = ['id'];
    public const array _types = ['id' => 'integer'];

    public ?int $id = null;
}

/**
 * @extends Objects<DbReadGuardObject>
 */
final class DbReadGuardObjects extends Objects
{
    public const string OBJECT_CLASS = DbReadGuardObject::class;
    public const string COLLECTION_KEY = DbReadGuardDbContext::COLLECTION;
}

/**
 * Minimal view of that store.
 */
final class DbReadGuardDbCollection extends DbCollection
{
    protected function createDbItem(Object_ $object): DbItem
    {
        return new DbReadGuardDbItem($object);
    }
}

/**
 * Minimal view item, never built by these cases.
 */
final class DbReadGuardDbItem extends DbItem
{
}

/**
 * DB context mounting the one collection these cases read.
 */
final class DbReadGuardDbContext extends DbContext
{
    public const string COLLECTION = 'unitDbReadGuardRows';

    /**
     * Mounts the collection by key, so a read that passes the guard needs no database behind it.
     *
     * @return self Mounted context
     */
    public static function create(): self
    {
        $context = new self();
        $context->_objectCollections[self::COLLECTION] = DbReadGuardObjects::initDB(Objects::LAZY_STRATEGY_KEY);
        $context->setRepresent(self::COLLECTION, DbReadGuardDbCollection::class);

        return $context;
    }

    public function configure(): void
    {
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Sync\DTO\DbSyncCreatedSignalData;
use Hilos\Database\Context\DbContext;
use Hilos\Database\DbSyncApplicator;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Hilos;
use Hilos\HilosException;
use PHPUnit\Framework\TestCase;

/**
 * The border of the apply: which process takes a row created somewhere else (HIL-670).
 *
 * A change and a removal need no rule — they reach a row the process is already holding or they
 * reach nothing. A creation is the one fact with a choice in it, because the row is by
 * definition one this process has never seen, and taking it means holding it from now on.
 *
 * The rule is the collection's own claim about itself. A copy that says it holds the whole set
 * is what a list is drawn from, and a list missing a row that exists is a list that lies. A lazy
 * copy holds what somebody asked for, and a row nobody asked for is precisely what it is
 * entitled not to hold — taking it would put every row created anywhere in the cluster into
 * every process's memory, which is the cost lazy loading exists to avoid. Nothing is lost by
 * declining: the row is in the database, and a read fetches it.
 *
 * A row created on THIS node is a different question and is taken either way: something in this
 * process just wrote it, and it is about to be read.
 */
final class DbSyncApplicatorRemoteCreatedTest extends TestCase
{
    /** @var string Collection every case creates into */
    private const string COLLECTION_KEY = 'remote_created_test';

    /** @var string Node the remote facts in these cases arrive from */
    private const string REMOTE_NODE = 'node-b';

    /** @var string Id of the row every case creates */
    private const string ROW_ID = '7';

    protected function tearDown(): void
    {
        Hilos::$db = null;
        Hilos::$sr = null;

        parent::tearDown();
    }

    /**
     * @throws HilosException When the collection refuses the row
     */
    public function testARowFromAnotherNodeEntersACollectionThatHoldsTheWholeSet(): void
    {
        $collection = $this->registerCollection(allLoaded: true);

        DbSyncApplicator::applyCreated($this->created(), originNodeId: self::REMOTE_NODE);

        $this->assertTrue(isset($collection[self::ROW_ID]));
    }

    /**
     * @throws HilosException When the collection refuses the row
     */
    public function testARowFromAnotherNodeStaysOutOfACollectionThatDoesNot(): void
    {
        $collection = $this->registerCollection(allLoaded: false);

        DbSyncApplicator::applyCreated($this->created(), originNodeId: self::REMOTE_NODE);

        $this->assertFalse(
            isset($collection[self::ROW_ID]),
            'A lazy copy is entitled not to hold a row nobody asked for; the database still has it.',
        );
    }

    /**
     * The local case is not narrowed by the same rule, and this is the case that says so: before
     * the origin existed every created row entered every process here, and a lazy collection on a
     * single node must go on behaving exactly as it did.
     *
     * @throws HilosException When the collection refuses the row
     */
    public function testARowWrittenOnThisNodeEntersEvenALazyCollection(): void
    {
        $collection = $this->registerCollection(allLoaded: false);

        DbSyncApplicator::applyCreated($this->created());

        $this->assertTrue(isset($collection[self::ROW_ID]));
    }

    /**
     * Registers a collection making a definite claim about how much of its table it holds.
     *
     * @param bool $allLoaded Whether the collection claims to hold the whole set
     * @return RemoteCreatedObjects Collection the applicator writes into
     */
    private function registerCollection(bool $allLoaded): RemoteCreatedObjects
    {
        $collection = RemoteCreatedObjects::holding($allLoaded);
        Hilos::$db = RemoteCreatedDbContext::create(self::COLLECTION_KEY, $collection);

        return $collection;
    }

    /**
     * Builds the created-row fact these cases apply.
     *
     * @return DbSyncCreatedSignalData Payload announcing one created row
     */
    private function created(): DbSyncCreatedSignalData
    {
        return new DbSyncCreatedSignalData(self::COLLECTION_KEY, self::ROW_ID, ['id' => (int)self::ROW_ID]);
    }
}

/**
 * Minimal single-column entity fixture for the remote-created border.
 */
final class RemoteCreatedEntity extends Entity
{
    public const string _table = 'remote_created_test';
    public const string _primary = 'id';
    public const array _columns = ['id'];
    public const array _types = ['id' => 'integer'];

    public ?int $id = null;
}

/**
 * Minimal object fixture wrapping the remote-created entity.
 */
final class RemoteCreatedObject extends Object_
{
    public const string ENTITY_CLASS = RemoteCreatedEntity::class;
}

/**
 * Object collection that can be built holding the whole set or only part of it, which is the one
 * thing these cases turn on.
 */
final class RemoteCreatedObjects extends Objects
{
    public const string OBJECT_CLASS = RemoteCreatedObject::class;

    /**
     * @param bool $allLoaded Whether the collection claims to hold the whole set
     * @return self Empty collection making that claim
     */
    public static function holding(bool $allLoaded): self
    {
        $collection = self::initEmpty();
        $collection->_allLoaded = $allLoaded;

        return $collection;
    }
}

/**
 * Database context holding the one collection these cases create into.
 */
final class RemoteCreatedDbContext extends DbContext
{
    /**
     * @param string $name Key the collection is mounted under
     * @param Objects $objectCollection Collection the applicator writes into
     * @return self Context holding that one collection
     */
    public static function create(string $name, Objects $objectCollection): self
    {
        $context = new self();
        $context->_objectCollections[$name] = $objectCollection;

        return $context;
    }

    public function configure(): void
    {
    }
}

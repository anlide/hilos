<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Database;

use Hilos\Constants\SignalConstants;
use Hilos\Core\Exception\ItemNotFoundForUpdateException;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\Source\Subscriber\OutboundRtSyncSubscriber;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\Sync\DTO\RtSyncSignalDataInterface;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Core\TruthSource\Exception\WriteNotAllowedException;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseException;
use Hilos\Database\Entity\Collection\EntityCollection;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\Exception\CollectionNotFoundException;
use Hilos\Database\Exception\Transaction\MemoryRollbackFailedException;
use Hilos\Database\Object\Item\Object_;
use Hilos\Database\Object\Objects;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Database\View\Item\DbItem;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Rt\RtCollectionNotFoundException;
use Hilos\Runtime\Exception\Rt\StateCollectionNotFoundException;
use Hilos\Runtime\State\Collection\RtStates;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\RtActions;
use Hilos\Runtime\View\Collection\RtCollection;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\RtItem;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for the memory of a process going back with the database (HIL-1165).
 *
 * MySQL takes back only its own rows. What this process read or wrote in the meantime - the
 * object of a row, the rows a collection holds - stayed as the failed work left it, so every
 * reader after it saw what the table never kept. Now every write door leaves a step on the open
 * transaction level, and a rollback runs the steps backwards: the object goes back to what was
 * last saved, tied to its row, the collection holds again what it held, and rows read inside the
 * transaction are forgotten. The mirrors hear what the rollback put back; the reactions never
 * hear what they were never told.
 *
 * The object of a row has a door of its own outside any transaction too: a revert, and a save the
 * table refused, put it back to what was last saved, in the same entity instance, and the next
 * save writes the difference rather than inserting a row the table already holds.
 *
 * The runtime is covered by the same transaction: a state added, removed, wiped or edited goes
 * back with it, and its frames to the other processes - with the echo each one awaits - leave
 * only at the commit, as the frames of the rows do.
 *
 * Nothing here needs a database: the MySQL part of a transaction opens only at its first query,
 * the entity fixture reports its writes without making them and can be told to refuse them, and
 * a static table stands in for what the collection reads.
 */
final class TransactionMemoryRollbackTest extends TestCase
{
    private const string AGENT = 'unit-transaction-memory-rollback-host';

    private ?SignalRouter $previousSignalRouter = null;

    private ?DbContext $previousDb = null;

    private ?RtContext $previousRt = null;

    private MemoryRollbackMirror $mirror;

    private MemoryRollbackReaction $reaction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousSignalRouter = Hilos::$sr;
        $this->previousDb = Hilos::$db;
        $this->previousRt = Hilos::$rt;
        Hilos::$sr = new SignalRouter();
        $this->mirror = new MemoryRollbackMirror();
        $this->reaction = new MemoryRollbackReaction();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        SourceChangeBus::subscribe($this->mirror);
        SourceChangeBus::subscribe($this->reaction);
        SourceChangeBus::subscribe(new OutboundRtSyncSubscriber());
        MemoryRollbackEntity::$writes = [];
        MemoryRollbackEntity::$refuseWrites = false;
        MemoryRollbackEntity::$table = [];
        TruthSourceRegistry::register(MemoryRollbackObjects::COLLECTION_KEY, TruthSourceKeys::all(), self::AGENT);
        RtTruthSourceRegistry::register(MemoryRollbackRtContext::COLLECTION, TruthSourceKeys::all(), self::AGENT);
    }

    protected function tearDown(): void
    {
        Database::handlerEnd();
        TruthSourceRegistry::unregister(MemoryRollbackObjects::COLLECTION_KEY, self::AGENT);
        RtTruthSourceRegistry::unregister(MemoryRollbackRtContext::COLLECTION, self::AGENT);
        SourceChangeBus::reset();
        Hilos::$rt = $this->previousRt;
        Hilos::$db = $this->previousDb;
        Hilos::$sr = $this->previousSignalRouter;

        parent::tearDown();
    }

    /**
     * The edit goes back to the value saved before it, and the object stays tied to the row:
     * the next save is an UPDATE. The mirror hears the update undone; the reaction heard nothing
     * at the write and hears nothing at the rollback.
     *
     * @throws HilosException When the fixture object cannot be saved
     */
    public function testARolledBackEditLeavesTheObjectOnTheSavedValue(): void
    {
        $object = MemoryRollbackObject::stored(7, 'before');

        Database::transactionStart();
        $object->remark('after');
        $object->sync();
        Database::transactionRollback();

        $this->assertSame('before', $object->mark());
        $this->assertTrue($object->isRelated());
        $this->assertSame(
            [[TableMutationType::Update, '7', ['mark' => 'after']], [TableMutationType::Update, '7', ['mark' => 'before']]],
            $this->mirror->seen,
        );
        $this->assertSame([], $this->reaction->seen);

        $object->remark('again');
        $object->sync();

        $this->assertSame([MemoryRollbackEntity::WRITE_UPDATE, MemoryRollbackEntity::WRITE_UPDATE], MemoryRollbackEntity::$writes);
    }

    /**
     * The object of a rolled-back insert is new again, without the key the insert gave it, and the
     * collection no longer holds it; the view forgets its wrapper, which the mirror fact tells it to.
     *
     * @throws HilosException When the fixture cannot be mounted or the object cannot be saved
     */
    public function testARolledBackInsertLeavesTheObjectNewAndOutOfItsCollection(): void
    {
        $objects = $this->mounted();
        $view = $this->view();

        Database::transactionStart();
        $object = MemoryRollbackObject::fresh('first');
        $object->sync();
        $objects[MemoryRollbackEntity::MINTED_ID] = $object;
        $this->assertNotNull($view[MemoryRollbackEntity::MINTED_ID], 'The read caches a wrapper the rollback must drop');
        Database::transactionRollback();

        $this->assertFalse($object->isRelated());
        $this->assertNull($object->id());
        $this->assertSame('first', $object->mark());
        $this->assertFalse(isset($objects[MemoryRollbackEntity::MINTED_ID]));
        $this->assertNull($view[MemoryRollbackEntity::MINTED_ID]);
        $this->assertSame([], $this->reaction->seen);
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal(), 'No frame leaves for a rolled-back insert');
    }

    /**
     * A rolled-back delete puts the very instance back under its key, tied to its row again, so a
     * caller and a View wrapper holding it keep their object.
     *
     * @throws HilosException When the fixture cannot be mounted or the object cannot be deleted
     */
    public function testARolledBackDeletePutsTheSameInstanceBack(): void
    {
        $objects = $this->mounted();
        $object = MemoryRollbackObject::stored(7, 'kept');
        $objects[7] = $object;

        Database::transactionStart();
        $object->delete();
        $objects->forget(7);
        Database::transactionRollback();

        $this->assertSame($object, $objects[7]);
        $this->assertTrue($object->isRelated());
        $this->assertSame('kept', $object->mark());
    }

    /**
     * Rows read inside a transaction may carry what it never commits, so a rollback forgets them,
     * and the completeness the whole read declared goes with them.
     *
     * @throws HilosException When the fixture cannot be mounted or read
     */
    public function testRowsReadInsideARolledBackTransactionAreForgotten(): void
    {
        MemoryRollbackEntity::$table = [7 => 'seven', 8 => 'eight'];
        $objects = $this->mounted();

        Database::transactionStart();
        $this->assertSame('seven', $objects[7]?->mark());
        $objects->preloadAll();
        $this->assertTrue($objects->isAllLoaded());
        Database::transactionRollback();

        $this->assertFalse(isset($objects[7]));
        $this->assertFalse(isset($objects[8]));
        $this->assertFalse($objects->isAllLoaded());
    }

    /**
     * A collection cleared inside a rolled-back transaction holds its rows again, the same instances.
     *
     * @throws HilosException When the fixture cannot be mounted or read
     */
    public function testAClearRolledBackBringsTheRowsBack(): void
    {
        $objects = $this->mounted();
        $seven = MemoryRollbackObject::stored(7, 'seven');
        $objects[7] = $seven;

        Database::transactionStart();
        $objects->clearInMemory();
        Database::transactionRollback();

        $this->assertSame($seven, $objects[7]);
    }

    /**
     * A commit keeps the memory as the writes left it and takes the journal with it, so a stray
     * rollback afterwards has nothing to put back.
     *
     * @throws HilosException When the fixture object cannot be saved
     */
    public function testACommitKeepsTheMemoryAndLeavesNothingToRollBack(): void
    {
        $object = MemoryRollbackObject::stored(7, 'before');

        Database::transactionStart();
        $object->remark('after');
        $object->sync();
        Database::transactionCommit();
        Database::transactionRollback();

        $this->assertSame('after', $object->mark());
        $this->assertSame([TableMutationType::Update, '7', ['mark' => 'after']], $this->reaction->seen[0] ?? null);
    }

    /**
     * A nested rollback takes back its own memory alone; a nested commit hands its steps to the
     * level under it, whose rollback then takes both.
     *
     * @throws HilosException When the fixture objects cannot be saved
     */
    public function testANestedLevelRollsBackItsOwnMemoryAndHandsItUpOnCommit(): void
    {
        $outer = MemoryRollbackObject::stored(7, 'outer-before');
        $inner = MemoryRollbackObject::stored(8, 'inner-before');

        Database::transactionStartNestable();
        $outer->remark('outer-after');
        $outer->sync();
        Database::transactionStartNestable();
        $inner->remark('inner-after');
        $inner->sync();
        Database::transactionRollback();

        $this->assertSame('outer-after', $outer->mark(), 'The nested rollback leaves the outer write');
        $this->assertSame('inner-before', $inner->mark());

        Database::transactionStartNestable();
        $inner->remark('inner-again');
        $inner->sync();
        Database::transactionCommit();
        $this->assertSame('inner-again', $inner->mark(), 'A nested commit puts nothing back');
        Database::transactionRollback();

        $this->assertSame('outer-before', $outer->mark());
        $this->assertSame('inner-before', $inner->mark());
    }

    /**
     * A failing step stops neither the others nor the rollback: every object is back, and what
     * reaches the caller is the first failure in the order the steps ran - the newest write first -
     * wrapped, because it is not one of the framework's.
     *
     * @throws HilosException When the fixture objects cannot be saved
     */
    public function testAFailingStepLetsTheOthersRunAndTheFirstFailureIsRaised(): void
    {
        $first = MemoryRollbackObject::stored(7, 'first-before');
        $second = MemoryRollbackObject::stored(8, 'second-before');

        Database::transactionStart();
        $first->remark('first-after');
        $first->sync();
        Database::onRollback(static function (): void {
            throw new RuntimeException('older step');
        });
        $second->remark('second-after');
        $second->sync();
        Database::onRollback(static function (): void {
            throw new RuntimeException('newer step');
        });

        try {
            Database::transactionRollback();
            $this->fail('A failing step reaches the caller of the rollback');
        } catch (MemoryRollbackFailedException $failure) {
            $this->assertSame('newer step', $failure->getPrevious()?->getMessage());
        }

        $this->assertSame('first-before', $first->mark());
        $this->assertSame('second-before', $second->mark());
        $this->assertSame([], Database::handlerEnd(), 'The level left the stack all the same');
    }

    /**
     * A failure of the framework's own tree is raised as it is: the caller catches it by its kind.
     */
    public function testAFailingStepOfTheFrameworkIsRaisedAsItIs(): void
    {
        Database::transactionStart();
        Database::onRollback(static function (): void {
            throw new LogicException('a framework step failed');
        });

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('a framework step failed');
        Database::transactionRollback();
    }

    /**
     * The MySQL part opens at the first query, so a transaction that sends none needs no
     * connection - here there is none at all - to start, commit and roll back, nested too.
     *
     * @throws HilosException When the transaction refuses a step
     */
    public function testATransactionWithoutAQueryNeedsNoConnection(): void
    {
        $this->assertFalse(Database::isConnected());

        Database::transactionStart();
        Database::transactionCommit();
        Database::transactionStartNestable();
        Database::transactionStartNestable();
        Database::transactionCommit();
        Database::transactionRollback();

        $this->assertSame([], Database::handlerEnd());
    }

    /**
     * Runtime membership goes back with the transaction: the state added inside is gone, the one
     * removed is back as the same instance, and no frame leaves for either - nor any echo awaited.
     * Inside the transaction the code reads its own writes.
     *
     * @throws HilosException When the fixture collection cannot be mounted or written
     */
    public function testRolledBackRuntimeMembershipComesBack(): void
    {
        $collection = $this->mountedRt();
        $collection->actions->put('kept', 'kept');
        $collection->actions->put('dropped', 'dropped');
        $dropped = $collection->getStateCollection()->get('dropped');
        // The setup's own frames and the echoes they await are not what is judged below.
        Hilos::$sr = new SignalRouter();

        Database::transactionStart();
        $collection->actions->put('added', 'added');
        $collection->actions->drop('dropped');
        $this->assertSame('added', $collection['added']?->mark());
        $this->assertNull($collection['dropped']);
        Database::transactionRollback();

        $this->assertNull($collection['added']);
        $this->assertSame($dropped, $collection->getStateCollection()->get('dropped'));
        $this->assertSame('dropped', $collection['dropped']?->mark());
        $this->assertSame('kept', $collection['kept']?->mark());
        $this->assertSame([], $this->drainRtFrames());
        $this->assertFalse(Hilos::$sr?->shouldSkipRtSyncApply(MemoryRollbackRtContext::COLLECTION, 'added'));
        $this->assertFalse(Hilos::$sr?->shouldSkipRtSyncApply(MemoryRollbackRtContext::COLLECTION, 'dropped'));
    }

    /**
     * A wipe inside a rolled-back transaction leaves every state where it was, the same instances.
     *
     * @throws HilosException When the fixture collection cannot be mounted or written
     */
    public function testARolledBackWipeBringsTheStatesBack(): void
    {
        $collection = $this->mountedRt();
        $collection->actions->put('a', 'first');
        $collection->actions->put('b', 'second');
        $first = $collection->getStateCollection()->get('a');
        $this->drainRtFrames();

        Database::transactionStart();
        $collection->actions->wipe();
        $this->assertNull($collection['a']);
        Database::transactionRollback();

        $this->assertSame($first, $collection->getStateCollection()->get('a'));
        $this->assertSame('second', $collection['b']?->mark());
        $this->assertSame([], $this->drainRtFrames());
    }

    /**
     * A state's own edit goes back through applyDiff(), and so does its baseline: the next sync()
     * after the rollback sends the very diff the rolled-back one would have sent.
     *
     * @throws HilosException When the fixture collection cannot be mounted or written
     */
    public function testARolledBackStateSyncIsSentAgainByTheNextOne(): void
    {
        $collection = $this->mountedRt();
        $collection->actions->put('a', 'first');
        $state = $collection->getStateCollection()->get('a');
        $this->assertInstanceOf(MemoryRollbackRtState::class, $state);
        $this->drainRtFrames();

        Database::transactionStart();
        $state->remark('second');
        $state->sync();
        Database::transactionRollback();

        $this->assertSame('first', $state->getMark());
        $this->assertSame([], $this->drainRtFrames());

        $state->remark('second');
        $state->sync();

        $this->assertSame([[SignalConstants::RT_SYNC_UPDATED, 'a']], $this->drainRtFrames());
    }

    /**
     * An edit by diff through the collection actions goes back too, baseline and all: nothing is
     * left for a later sync() to send.
     *
     * @throws HilosException When the fixture collection cannot be mounted or written
     */
    public function testARolledBackEditByDiffLeavesNothingToSend(): void
    {
        $collection = $this->mountedRt();
        $collection->actions->put('a', 'first');
        $state = $collection->getStateCollection()->get('a');
        $this->assertNotNull($state);
        $this->drainRtFrames();

        Database::transactionStart();
        $collection->actions->remark('a', 'second');
        Database::transactionRollback();

        $this->assertSame('first', $collection['a']?->mark());
        $state->sync();
        $this->assertSame([], $this->drainRtFrames());
    }

    /**
     * The frames of the runtime wait for the commit, as the frames of the rows do, and the echo
     * each one awaits is registered with it. A mirror hears the fact at the write; a reaction at
     * the commit. And none of it needs a connection: the transaction never sent a query.
     *
     * @throws HilosException When the fixture collection cannot be mounted or written
     */
    public function testRuntimeFramesAndReactionsWaitForTheCommit(): void
    {
        $this->assertFalse(Database::isConnected());
        $collection = $this->mountedRt();

        Database::transactionStart();
        $collection->actions->put('a', 'first');
        $collection->actions->remark('a', 'second');

        $this->assertSame([], $this->drainRtFrames());
        $this->assertSame([[TableMutationType::Create, 'a', ['id' => 'a', 'mark' => 'first']]], $this->mirror->seen);
        $this->assertSame([], $this->reaction->seen);

        Database::transactionCommit();

        $this->assertSame(
            [[SignalConstants::RT_SYNC_CREATED, 'a'], [SignalConstants::RT_SYNC_UPDATED, 'a']],
            $this->drainRtFrames(),
        );
        $this->assertTrue(Hilos::$sr?->shouldSkipRtSyncApply(MemoryRollbackRtContext::COLLECTION, 'a'));
        $this->assertSame([[TableMutationType::Create, 'a', ['id' => 'a', 'mark' => 'first']]], $this->reaction->seen);
        $this->assertSame('second', $collection['a']?->mark());
    }

    /**
     * The defect P-431 named: the revert used to put a clone of the saved state in, a clone is a
     * new row, and the next save inserted under a key the table already held.
     *
     * @throws HilosException When the fixture object cannot be saved
     */
    public function testARevertedObjectStaysTiedToItsRow(): void
    {
        $object = MemoryRollbackObject::stored(7, 'before');
        $object->remark('refused');

        $object->revert();

        $this->assertSame('before', $object->mark());
        $this->assertTrue($object->isRelated());

        $object->remark('after');
        $object->sync();

        $this->assertSame([MemoryRollbackEntity::WRITE_UPDATE], MemoryRollbackEntity::$writes);
    }

    public function testARevertOfAnUnsavedObjectLeavesItAsItIs(): void
    {
        $object = MemoryRollbackObject::fresh('first');

        $object->revert();

        $this->assertSame('first', $object->mark());
        $this->assertFalse($object->isRelated());
    }

    /**
     * Memory follows the database: the table did not take the value, so no reader of this process
     * sees it, and the next save does not carry it in its diff.
     *
     * @throws HilosException When the fixture object cannot be saved
     */
    public function testAnEditTheDatabaseRefusesPutsTheObjectBack(): void
    {
        $object = MemoryRollbackObject::stored(7, 'before');
        $object->remark('refused');
        MemoryRollbackEntity::$refuseWrites = true;

        try {
            $object->sync();
            $this->fail('The fixture refuses every write');
        } catch (DatabaseException) {
            $this->assertSame('before', $object->mark());
            $this->assertTrue($object->isRelated());
        }

        MemoryRollbackEntity::$refuseWrites = false;
        $object->remark('after');
        $object->sync();

        $this->assertSame([MemoryRollbackEntity::WRITE_UPDATE], MemoryRollbackEntity::$writes);
    }

    /**
     * The write guard refusing is the same outcome for memory as the database refusing: the
     * table did not take the value, whoever stopped it.
     */
    public function testAnEditTheWriteGuardRefusesPutsTheObjectBack(): void
    {
        $object = MemoryRollbackObject::stored(7, 'before');
        $object->remark('refused');
        TruthSourceRegistry::unregister(MemoryRollbackObjects::COLLECTION_KEY, self::AGENT);

        try {
            $object->sync();
            $this->fail('No truth source covers the fixture collection any more');
        } catch (WriteNotAllowedException) {
            $this->assertSame('before', $object->mark());
            $this->assertTrue($object->isRelated());
        }
    }

    /**
     * An insert has nothing saved to go back to: the object stays new, holding what the caller
     * gave it, for the caller to fix or drop.
     */
    public function testAnInsertTheDatabaseRefusesKeepsTheCallersValues(): void
    {
        $object = MemoryRollbackObject::fresh('first');
        MemoryRollbackEntity::$refuseWrites = true;

        try {
            $object->sync();
            $this->fail('The fixture refuses every write');
        } catch (DatabaseException) {
            $this->assertSame('first', $object->mark());
            $this->assertFalse($object->isRelated());
            $this->assertNull($object->id());
        }
    }

    /**
     * Mounts the fixture runtime collection through a context, so the view cache subscriber finds
     * the view by the name a fact carries.
     *
     * @return MemoryRollbackRtCollection Mounted view of a fresh state collection
     * @throws HilosException When the fixture context cannot represent its own collection
     */
    private function mountedRt(): MemoryRollbackRtCollection
    {
        $context = new MemoryRollbackRtContext();
        $context->configure();
        $context->bindStateCollectionNames();
        Hilos::$rt = $context;

        return $context->collection();
    }

    /**
     * Drains the queue and names the runtime frames in it.
     *
     * @return list<array{string, string}> Signal type and state id of each RT-sourced frame, in queue order
     */
    private function drainRtFrames(): array
    {
        $frames = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalSource->getSource() !== SignalSource::RT) {
                continue;
            }
            $this->assertInstanceOf(RtSyncSignalDataInterface::class, $signal->data);
            $frames[] = [$signal->signalType->getType(), $signal->data->stateId];
        }

        return $frames;
    }

    /**
     * Mounts the fixture collection through a DB context, the way a project context mounts it,
     * so the view cache subscriber finds the view by the name a fact carries.
     *
     * @return MemoryRollbackObjects Mounted lazy collection reading the fixture table
     * @throws HilosException When the fixture collection cannot be built
     */
    private function mounted(): MemoryRollbackObjects
    {
        $objects = MemoryRollbackObjects::initDB();
        $view = MemoryRollbackDbCollection::init();
        $view->setObjectCollection($objects);
        Hilos::$db = MemoryRollbackDbContext::create($objects, $view);

        return $objects;
    }

    /**
     * @return MemoryRollbackDbCollection View mounted by the last {@see self::mounted()} call
     * @throws HilosException When no DB context is mounted
     */
    private function view(): MemoryRollbackDbCollection
    {
        $view = Hilos::$db?->getDbItemCollection(MemoryRollbackObjects::COLLECTION_KEY);

        return $view instanceof MemoryRollbackDbCollection
            ? $view
            : throw new CollectionNotFoundException('Memory-rollback fixture DB collection is not mounted');
    }
}

/**
 * Mirror of this process's memory: records every change it is told, at the moment it is told.
 */
final class MemoryRollbackMirror implements SourceMirrorSubscriberInterface
{
    /** @var list<array{TableMutationType, string, array<string, mixed>}> Kind, id and row of each change, in order */
    public array $seen = [];

    /**
     * @param SourceChange $change Announced change to record
     * @param SourceChangeProvenance $provenance Announced provenance, not read here
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        $this->seen[] = [$change->mutationType, $change->sourceId, $change->row];
    }
}

/**
 * Reaction of this process to a change: records every change it hears.
 */
final class MemoryRollbackReaction implements SourceChangeSubscriberInterface
{
    /** @var list<array{TableMutationType, string, array<string, mixed>}> Kind, id and row of each change, in order */
    public array $seen = [];

    /**
     * @param SourceChange $change Announced change to record
     * @param SourceChangeProvenance $provenance Announced provenance, not read here
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        $this->seen[] = [$change->mutationType, $change->sourceId, $change->row];
    }
}

/**
 * Entity fixture that reports its writes without making them, and refuses them on demand.
 *
 * Unlike the fixture of the update announcement it keeps the base isRelated(): the tie to the row
 * is what a revert used to cut, and a fixture reading it off the id would hide exactly that.
 */
final class MemoryRollbackEntity extends Entity
{
    public const string _table = 'memory_rollback_test';
    public const string _primary = 'id';
    public const array _columns = ['id', 'mark'];
    public const array _types = ['id' => 'integer', 'mark' => 'string'];

    public const string WRITE_INSERT = 'insert';
    public const string WRITE_UPDATE = 'update';
    public const string WRITE_DELETE = 'delete';

    /** Id an insert mints when the row brings none. */
    public const int MINTED_ID = 100;

    /** @var list<string> Writes taken, in order, by kind */
    public static array $writes = [];

    /** Whether the next write is refused the way the database refuses one. */
    public static bool $refuseWrites = false;

    /** @var array<int, string> The table the collection reads: id => mark */
    public static array $table = [];

    public ?int $id = null;

    public string $mark = '';

    /**
     * Builds an entity standing for a row the table holds.
     *
     * @param int $id Row id
     * @param string $mark Stored value
     * @return self Entity tied to that row
     */
    public static function stored(int $id, string $mark): self
    {
        return self::fromRow(['id' => $id, 'mark' => $mark]);
    }

    /**
     * Reports the row as inserted without inserting it, minting an id as a real insert would.
     *
     * @param list<string> $columns Columns a real write would take; unused, nothing is written
     * @return bool Always true, the write having been skipped
     * @throws DatabaseException When the fixture is set to refuse writes
     */
    public function save(array $columns = []): bool
    {
        self::refuseIfAsked();
        self::$writes[] = self::WRITE_INSERT;
        $this->id ??= self::MINTED_ID;
        $this->flushRelated();

        return true;
    }

    /**
     * Reports the difference as written without writing it.
     *
     * @param Entity $originalEntity State the diff is taken against
     * @return bool Always true, the write having been skipped
     * @throws DatabaseException When the fixture is set to refuse writes
     */
    public function saveDiff(Entity $originalEntity): bool
    {
        self::refuseIfAsked();
        self::$writes[] = self::WRITE_UPDATE;
        $this->flushRelated();

        return true;
    }

    /**
     * Reports the row as deleted without deleting it, cutting the tie the way a real delete does.
     *
     * @throws DatabaseException When the fixture is set to refuse writes
     */
    public function delete(): void
    {
        self::refuseIfAsked();
        self::$writes[] = self::WRITE_DELETE;
        $this->restoreStored($this->toArray(), related: false);
    }

    /**
     * @throws DatabaseException When the fixture is set to refuse writes
     */
    private static function refuseIfAsked(): void
    {
        if (self::$refuseWrites) {
            throw new DatabaseException('The memory-rollback fixture refuses this write');
        }
    }
}

/**
 * Object fixture wrapping the memory-rollback entity, named so its writes are guarded and announced.
 */
final class MemoryRollbackObject extends Object_
{
    public const string ENTITY_CLASS = MemoryRollbackEntity::class;
    public const string OBJECT_COLLECTION_CLASS = MemoryRollbackObjects::class;

    /**
     * Builds an object standing for a row the table holds.
     *
     * @param int $id Row id
     * @param string $mark Stored value
     * @return self Object holding that row
     */
    public static function stored(int $id, string $mark): self
    {
        return self::fromEntity(MemoryRollbackEntity::stored($id, $mark));
    }

    /**
     * Builds an object standing for a row the table does not hold yet.
     *
     * @param string $mark Value to store
     * @return self Object holding that row
     */
    public static function fresh(string $mark): self
    {
        $object = self::create();
        $object->remark($mark);

        return $object;
    }

    /**
     * @return ?int Row id, null before the row is inserted
     */
    public function id(): ?int
    {
        return $this->entity->id;
    }

    /**
     * @return string Value the object holds now
     */
    public function mark(): string
    {
        return $this->entity->mark;
    }

    /**
     * Changes the one column the fixture carries, without saving it.
     *
     * @param string $mark New value
     */
    public function remark(string $mark): void
    {
        $this->entity->mark = $mark;
    }

}

/**
 * Entity collection fixture whose whole read is the fixture table, not a query.
 */
final class MemoryRollbackEntities extends EntityCollection
{
    public const string ENTITY_CLASS = MemoryRollbackEntity::class;

    /**
     * @return static Every row of the fixture table
     */
    public static function initFullDB(): static
    {
        $entities = [];
        foreach (MemoryRollbackEntity::$table as $id => $mark) {
            $entities[$id] = MemoryRollbackEntity::stored($id, $mark);
        }

        return static::fromArray($entities);
    }
}

/**
 * Object collection fixture reading the fixture table by key and whole, named so its changes are announced.
 *
 * @extends Objects<MemoryRollbackObject>
 */
final class MemoryRollbackObjects extends Objects
{
    public const string OBJECT_CLASS = MemoryRollbackObject::class;
    public const string ENTITY_COLLECTION_CLASS = MemoryRollbackEntities::class;
    public const string COLLECTION_KEY = 'unit-memory-rollback-db';

    /**
     * Drops one row the way the actions of a collection drop a deleted one - through the door.
     *
     * @param int $key Key whose row goes
     * @throws HilosException Whatever a subscriber to the store announcement raises
     */
    public function forget(int $key): void
    {
        unset($this[$key]);
    }

    protected function lazyLoadObject(int|string|null $key): ?Object_
    {
        $mark = MemoryRollbackEntity::$table[(int)$key] ?? null;

        return $mark === null ? null : MemoryRollbackObject::stored((int)$key, $mark);
    }
}

/**
 * DB view collection fixture over the memory-rollback store.
 */
final class MemoryRollbackDbCollection extends DbCollection
{
    protected function createDbItem(Object_ $object): DbItem
    {
        return new MemoryRollbackDbItem($object);
    }
}

/**
 * DB view item fixture; the cases only ask whether a key answers.
 */
final class MemoryRollbackDbItem extends DbItem
{
}

/**
 * DB context mounting the one store these cases change.
 */
final class MemoryRollbackDbContext extends HilosDbContext
{
    /**
     * @param MemoryRollbackObjects $objects Store to mount
     * @param MemoryRollbackDbCollection $view View of the store to mount
     * @return self Mounted context
     */
    public static function create(MemoryRollbackObjects $objects, MemoryRollbackDbCollection $view): self
    {
        $context = new self();
        $context->_objectCollections[MemoryRollbackObjects::COLLECTION_KEY] = $objects;
        $context->_dbItemCollections[MemoryRollbackObjects::COLLECTION_KEY] = $view;

        return $context;
    }

    public function configure(): void
    {
    }
}

/**
 * Runtime context mounting the one collection these cases change.
 */
final class MemoryRollbackRtContext extends RtContext
{
    public const string COLLECTION = 'unit-memory-rollback-rt';

    /**
     * Registers the state collection and its view, as a project context does.
     *
     * @throws StateCollectionNotFoundException When the state collection was not registered first
     */
    public function configure(): void
    {
        $this->_stateCollections[self::COLLECTION] = MemoryRollbackRtStates::init();
        $this->setRepresent(self::COLLECTION, MemoryRollbackRtCollection::class, MemoryRollbackRtActions::class);
    }

    /**
     * @return MemoryRollbackRtCollection Mounted view collection
     * @throws RtCollectionNotFoundException When configure() has not run
     */
    public function collection(): MemoryRollbackRtCollection
    {
        $collection = $this->getRtCollection(self::COLLECTION);

        return $collection instanceof MemoryRollbackRtCollection
            ? $collection
            : throw new RtCollectionNotFoundException('Memory-rollback fixture runtime collection is not mounted');
    }
}

/**
 * Runtime state fixture with one field that a foreign edit - and so a rollback - can change.
 */
final class MemoryRollbackRtState extends RtState
{
    private string $id = '';

    private string $mark = '';

    /**
     * @param string $id State id
     * @param string $mark Value telling one edit from another
     * @return self State with its baseline set
     */
    public static function create(string $id, string $mark): self
    {
        $state = new self();
        $state->id = $id;
        $state->mark = $mark;
        $state->markRtSyncBaseline();

        return $state;
    }

    public static function fromRow(array $row): static
    {
        return self::create(self::requireString($row, 'id'), self::requireString($row, 'mark'));
    }

    public static function getRtCollectionKey(): string
    {
        return MemoryRollbackRtContext::COLLECTION;
    }

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @return string Value the state holds now
     */
    public function getMark(): string
    {
        return $this->mark;
    }

    /**
     * Changes the one field, without syncing it.
     *
     * @param string $mark New value
     */
    public function remark(string $mark): void
    {
        $this->mark = $mark;
    }

    public function applyDiff(array $diff): void
    {
        $this->mark = self::patchString($diff, 'mark', $this->mark);
    }

    public function toArray(): array
    {
        return ['id' => $this->id, 'mark' => $this->mark];
    }
}

/**
 * Runtime state collection fixture.
 *
 * @extends RtStates<MemoryRollbackRtState>
 */
final class MemoryRollbackRtStates extends RtStates
{
    public const string STATE_CLASS = MemoryRollbackRtState::class;
}

/**
 * Runtime view item fixture reporting the mark of the state it wraps.
 *
 * @extends RtItem<MemoryRollbackRtState>
 */
final class MemoryRollbackRtItem extends RtItem
{
    /**
     * @return string Mark of the wrapped state
     */
    public function mark(): string
    {
        return $this->_state->getMark();
    }

    public function toArray(): array
    {
        return $this->_state->toArray();
    }
}

/**
 * Runtime view collection fixture.
 *
 * @extends RtCollection<MemoryRollbackRtItem, MemoryRollbackRtActions>
 */
final class MemoryRollbackRtCollection extends RtCollection
{
    protected function createRtItem(RtState $state): RtItem
    {
        return new MemoryRollbackRtItem($state);
    }
}

/**
 * Opens the protected write doors of the base runtime actions to the cases.
 *
 * @extends RtActions<MemoryRollbackRtItem, MemoryRollbackRtCollection, MemoryRollbackRtStates>
 */
final class MemoryRollbackRtActions extends RtActions
{
    /**
     * @param string $id State id to add
     * @param string $mark Value of the new state
     * @throws HilosException On a truth-source refusal or a failing subscriber
     */
    public function put(string $id, string $mark): void
    {
        $this->addStateToCollection(MemoryRollbackRtState::create($id, $mark));
    }

    /**
     * @param string $id State id to remove
     * @throws HilosException On a truth-source refusal or a failing subscriber
     */
    public function drop(string $id): void
    {
        $this->removeStateFromCollection($id);
    }

    /**
     * Edits one state by diff, the way an action that knows its diff does.
     *
     * @param string $id State id to edit
     * @param string $mark New value
     * @throws ItemNotFoundForUpdateException When no state stands under the id
     * @throws HilosException On a truth-source refusal or a failing queue
     */
    public function remark(string $id, string $mark): void
    {
        $state = $this->getStateCollection()->get($id)
            ?? throw new ItemNotFoundForUpdateException("No memory-rollback fixture state under '{$id}'");
        $this->applyDiffToState($state, ['mark' => $mark]);
    }

    /**
     * @throws HilosException On a truth-source refusal, a missing callback or a failing queue
     */
    public function wipe(): void
    {
        $this->clearAllStates();
    }
}

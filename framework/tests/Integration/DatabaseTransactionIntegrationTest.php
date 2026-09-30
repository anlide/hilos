<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Constants\EnvConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Source\SourceChangeSubscriberInterface;
use Hilos\Core\Source\SourceMirrorSubscriberInterface;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\Sync\DTO\DbSyncSignalDataInterface;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Database;
use Hilos\Database\DatabaseConnectionDefaults;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\DatabaseConnectionException;
use Hilos\Database\Exception\DatabaseRuntimeException;
use Hilos\Database\Exception\Transaction\NestedTransactionRefusedException;
use Hilos\Database\Exception\Transaction\TransactionNotOpenException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\View\Context\RtContext;
use RuntimeException;
use Throwable;

/**
 * A transaction holds its announcements until it commits (HIL-1164).
 *
 * The rows are written the way the framework writes them - through a collection's actions - so
 * both announcements a write makes are exercised: the DB-sync frame to the other processes and
 * the fact on the source bus. What is pinned is the moment each reaches its listener: the mirror
 * at the write, the frame and the reaction at the commit of the outermost level, neither after a
 * rollback. The nesting rules, the lost link and the end-of-handler cleanup are pinned here too,
 * against a real MySQL transaction: a savepoint and a link that dies cannot be faked.
 */
final class DatabaseTransactionIntegrationTest extends HilosSessionIntegrationTestCase
{
    private const int FIRST_USER_ID = 1164;
    private const int SECOND_USER_ID = 1165;

    private const string EFFECTIVE_AT = '2026-01-01 00:00:00';

    /** Connection index a second session is opened on, to kill the first one from outside. */
    private const int KILLER_INDEX = 2;

    /** Key of a database collection nothing mounts, for a fact published by hand. */
    private const string UNMOUNTED_DB_COLLECTION = 'transaction-test-db';

    /** Key of a runtime collection nothing mounts, for a runtime fact published by hand. */
    private const string UNMOUNTED_RT_COLLECTION = 'transaction-test-rt';

    private ?SignalRouter $previousSignalRouter = null;

    private ?RtContext $previousRt = null;

    private TransactionTestMirror $mirror;

    private TransactionTestReaction $reaction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousSignalRouter = Hilos::$sr;
        $this->previousRt = Hilos::$rt;
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = null;
        $this->mirror = new TransactionTestMirror();
        $this->reaction = new TransactionTestReaction();
        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        SourceChangeBus::subscribe($this->mirror);
        SourceChangeBus::subscribe($this->reaction);
    }

    protected function tearDown(): void
    {
        SourceChangeBus::reset();
        Hilos::$rt = $this->previousRt;
        Hilos::$sr = $this->previousSignalRouter;

        parent::tearDown();
    }

    /**
     * The frames and the reaction wait for the commit and then come in the order of the writes;
     * the mirror is told at each write, because the code inside the transaction reads through it.
     *
     * @throws HilosException When a write or the transaction fails
     */
    public function testACommitReleasesTheAnnouncementsInTheOrderOfTheWrites(): void
    {
        Database::transactionStart();
        $first = $this->request(self::FIRST_USER_ID);
        $second = $this->request(self::SECOND_USER_ID);

        self::assertSame([], $this->drainDbFrames(), 'No frame leaves before the commit');
        self::assertSame([], $this->reaction->seen, 'No reaction hears a write before the commit');
        self::assertSame([$first, $second], $this->mirror->seen, 'The mirror is told at the write');
        self::assertSame(2, self::requestRows(), 'The rows are visible inside the transaction');

        Database::transactionCommit();

        self::assertSame([$first, $second], $this->drainDbFrames());
        self::assertSame([$first, $second], $this->reaction->seen);
        self::assertSame(2, self::requestRows());
    }

    /**
     * @throws HilosException When a write or the transaction fails
     */
    public function testARollbackDropsTheAnnouncementsWithTheRows(): void
    {
        Database::transactionStart();
        $this->request(self::FIRST_USER_ID);
        Database::transactionRollback();

        self::assertSame([], $this->drainDbFrames());
        self::assertSame([], $this->reaction->seen);
        self::assertSame(0, self::requestRows());
    }

    /**
     * The self-broadcast registration travels with the frame: a dropped frame leaves no echo
     * awaited, and a released one leaves exactly the one its row is awaited back under.
     *
     * @throws HilosException When a write or the transaction fails
     */
    public function testTheEchoRegistrationTravelsWithTheFrame(): void
    {
        $emitter = Hilos::$sr->getEmitter();

        Database::transactionStart();
        $dropped = $this->request(self::FIRST_USER_ID);
        Database::transactionRollback();

        self::assertFalse(
            Hilos::$sr->shouldSkipDbSyncApply(HilosDbContext::accountDeletions, $dropped, $emitter),
            'A dropped frame leaves no registration behind',
        );

        Database::transactionStart();
        $released = $this->request(self::SECOND_USER_ID);
        Database::transactionCommit();

        self::assertSame([$released], $this->drainDbFrames());
        self::assertTrue(
            Hilos::$sr->shouldSkipDbSyncApply(HilosDbContext::accountDeletions, $released, $emitter),
            'A released frame is awaited back',
        );
    }

    /**
     * @throws HilosException When a write or the transaction fails
     */
    public function testAPlainStartInsideAnOpenTransactionIsRefusedAndTheOpenOneStands(): void
    {
        Database::transactionStart();
        $this->request(self::FIRST_USER_ID);

        try {
            Database::transactionStart();
            self::fail('A plain start never nests');
        } catch (NestedTransactionRefusedException $refusal) {
            self::assertSame(
                'A transaction is already open on connection 0 at depth 1; a nested start needs every level started'
                . ' with Database::transactionStartNestable(), and this start was not',
                $refusal->getMessage(),
            );
        }

        self::assertSame(1, self::requestRows(), 'The open transaction is untouched: its row is still there');
        Database::transactionRollback();
        self::assertSame(0, self::requestRows(), 'And it is still one transaction, so the rollback takes the row');
        self::assertSame([], $this->drainDbFrames());
    }

    /**
     * @throws HilosException When the transaction fails
     */
    public function testOneUnmarkedLevelInTheChainRefusesTheNestedStart(): void
    {
        Database::transactionStart();
        try {
            Database::transactionStartNestable();
            self::fail('A marked start inside a plain transaction is refused');
        } catch (NestedTransactionRefusedException $refusal) {
            self::assertStringEndsWith('and level 1 was not', $refusal->getMessage());
        }
        Database::transactionRollback();

        Database::transactionStartNestable();
        try {
            Database::transactionStart();
            self::fail('A plain start inside a marked transaction is refused');
        } catch (NestedTransactionRefusedException $refusal) {
            self::assertStringEndsWith('and this start was not', $refusal->getMessage());
        }
        Database::transactionRollback();

        self::assertNull(Database::rollBackLeftOpen(), 'Both refusals left the open transaction to its own rollback');
    }

    /**
     * @throws HilosException When a write or the transaction fails
     */
    public function testANestedRollbackTakesOnlyItsOwnRowsAndAnnouncements(): void
    {
        Database::transactionStartNestable();
        $outer = $this->request(self::FIRST_USER_ID);
        Database::transactionStartNestable();
        $this->request(self::SECOND_USER_ID);
        self::assertSame(2, self::requestRows());

        Database::transactionRollback();

        self::assertSame(1, self::requestRows(), 'The nested rollback takes its own row and leaves the outer one');
        self::assertSame([], $this->drainDbFrames(), 'Nothing leaves before the outermost commit');

        Database::transactionCommit();

        self::assertSame([$outer], $this->drainDbFrames());
        self::assertSame([$outer], $this->reaction->seen);
        self::assertSame(1, self::requestRows());
    }

    /**
     * @throws HilosException When a write or the transaction fails
     */
    public function testANestedCommitWaitsForTheOutermostOneWhoseRollbackTakesItAlong(): void
    {
        Database::transactionStartNestable();
        Database::transactionStartNestable();
        $this->request(self::FIRST_USER_ID);

        Database::transactionCommit();

        self::assertSame([], $this->drainDbFrames(), 'A nested commit releases nothing yet');
        self::assertSame([], $this->reaction->seen);
        self::assertSame(1, self::requestRows());

        Database::transactionRollback();

        self::assertSame([], $this->drainDbFrames());
        self::assertSame([], $this->reaction->seen);
        self::assertSame(0, self::requestRows());
    }

    /**
     * @throws HilosException When the rollback fails
     */
    public function testACommitWithNoTransactionIsRefusedAndARollbackWithNoneIsSilent(): void
    {
        try {
            Database::transactionCommit();
            self::fail('A commit with nothing to commit is a caller that believes its writes are saved');
        } catch (TransactionNotOpenException $refusal) {
            self::assertSame('No open transaction to commit on connection 0', $refusal->getMessage());
        }

        Database::transactionRollback();

        self::assertNull(Database::rollBackLeftOpen());
    }

    /**
     * Closing the connection under a transaction leaves the level standing as failed: the next
     * query is refused rather than reconnected, the commit is refused, and the rollback closes it.
     *
     * @throws HilosException When a write, the rollback or the reconnect fails
     */
    public function testAClosedConnectionEndsItsTransactionAsFailed(): void
    {
        Database::transactionStart();
        $this->request(self::FIRST_USER_ID);

        Database::close();

        try {
            Database::sql('SELECT 1');
            self::fail('A query on the closed connection is not reconnected inside a transaction');
        } catch (DatabaseConnectionException) {
            self::assertFalse(Database::isConnected());
        }
        try {
            Database::transactionCommit();
            self::fail('The commit of a transaction that went with its session is refused');
        } catch (TransactionNotOpenException $refusal) {
            self::assertSame(
                'The transaction at depth 1 on connection 0 is already rolled back - its commit did not stand, or its'
                . ' connection was closed; roll it back to close it',
                $refusal->getMessage(),
            );
        }
        Database::transactionRollback();

        self::assertNull(Database::rollBackLeftOpen(), 'The rollback closed the failed level');
        self::assertSame([], $this->drainDbFrames());
        self::assertSame([], $this->reaction->seen);

        Database::connect(DatabaseConnectionDefaults::PRIMARY_INDEX);
        self::assertSame(0, self::requestRows());
    }

    /**
     * A link lost inside a transaction is not reconnected: reconnected, the transaction would be
     * gone without a word, the next writes would autocommit, and the commit would release the
     * announcements of rows the broken transaction never wrote. The query is refused, the commit
     * fails and marks the level, the caller's rollback closes it.
     *
     * @throws HilosException When a write, the kill, the rollback or the reconnect fails
     */
    public function testALostLinkInsideATransactionIsNotReconnectedAndEndsIt(): void
    {
        Database::transactionStart();
        $this->request(self::FIRST_USER_ID);
        Database::sql('SELECT CONNECTION_ID() AS id');
        $this->killFromAnotherSession((int)Database::field('id'));

        try {
            Database::sql('SELECT 1');
            self::fail('A query on a lost link is not reconnected inside a transaction');
        } catch (DatabaseRuntimeException) {
            self::assertTrue(Database::isConnected(), 'The handle is the one that died: nothing reconnected');
        }
        try {
            Database::transactionCommit();
            self::fail('A commit on the lost link fails');
        } catch (DatabaseRuntimeException) {
            // The level is now failed and awaits the rollback below
        }
        try {
            Database::transactionCommit();
            self::fail('A second commit of the failed level is refused without SQL');
        } catch (TransactionNotOpenException) {
            // Refused by the stack, not by the link
        }
        Database::transactionRollback();

        self::assertNull(Database::rollBackLeftOpen(), 'The rollback closed the failed level');
        self::assertSame([], $this->drainDbFrames());
        self::assertSame([], $this->reaction->seen);

        Database::close();
        Database::connect(DatabaseConnectionDefaults::PRIMARY_INDEX);
        self::assertSame(0, self::requestRows(), 'The writes went with the link');
    }

    /**
     * @throws HilosException When a write fails
     */
    public function testAHandlerThatLeavesATransactionOpenIsRolledBackAndReported(): void
    {
        Database::transactionStart();
        $this->request(self::FIRST_USER_ID);

        $left = Database::rollBackLeftOpen();

        self::assertNotNull($left);
        self::assertMatchesRegularExpression(
            '/^A transaction was left open on connection 0 at depth 1; it was rolled back and its [1-9]\d* held'
            . ' announcements were dropped$/',
            $left->getMessage(),
        );
        self::assertSame(0, self::requestRows());
        self::assertSame([], $this->drainDbFrames());
        self::assertSame([], $this->reaction->seen);
        self::assertNull(Database::rollBackLeftOpen(), 'The second call finds nothing left');
    }

    /**
     * @throws HilosException When a write fails
     */
    public function testAnAnnouncementMadeOutsideATransactionIsMadeAtOnce(): void
    {
        $made = false;
        Database::afterCommit(static function () use (&$made): void {
            $made = true;
        });
        self::assertTrue($made);

        $id = $this->request(self::FIRST_USER_ID);

        self::assertSame([$id], $this->drainDbFrames());
        self::assertSame([$id], $this->reaction->seen);
        self::assertSame([$id], $this->mirror->seen);
    }

    /**
     * @throws HilosException When the transaction fails
     */
    public function testARuntimeFactInsideATransactionReachesTheReactionAtOnce(): void
    {
        Database::transactionStart();
        SourceChangeBus::publish(SourceChange::rtCreated(self::UNMOUNTED_RT_COLLECTION, 'a', []));

        self::assertSame(['a'], $this->reaction->seen);

        Database::transactionRollback();

        self::assertSame(['a'], $this->reaction->seen);
    }

    /**
     * The provenance a reaction is handed is the one the write had, not the one in force when
     * the commit releases it.
     *
     * @throws HilosException When the transaction fails
     */
    public function testAReactionHearsTheProvenanceTheWriteHad(): void
    {
        Database::transactionStart();
        SourceChangeBus::whileApplyingRemote(static function (): void {
            SourceChangeBus::publish(SourceChange::dbCreated(self::UNMOUNTED_DB_COLLECTION, 'a', []));
        });
        self::assertSame([], $this->reaction->seen);

        Database::transactionCommit();

        self::assertSame(['a'], $this->reaction->seen);
        self::assertSame([SourceChangeProvenance::AppliedRemote], $this->reaction->provenances);
    }

    /**
     * A reaction that fails when the commit releases it reaches the caller of the commit, after
     * every other held announcement was made: the commit already stands, and a swallowed failure
     * would be a sync that vanished without a trace. The caller's catch then rolls back a
     * transaction that no longer exists, and loses nothing by it.
     *
     * @throws HilosException When a write or the rollback fails
     */
    public function testAFailingReactionAtTheCommitReachesTheCallerAfterTheCommitStands(): void
    {
        SourceChangeBus::subscribe(new TransactionTestFailingReaction(new RuntimeException('the reaction could not be served')));
        Database::transactionStart();
        $id = $this->request(self::FIRST_USER_ID);

        try {
            Database::transactionCommit();
            self::fail('The failure of a released reaction reaches the caller of the commit');
        } catch (SourceChangeSubscriberException $wrapped) {
            self::assertSame('the reaction could not be served', $wrapped->getPrevious()?->getMessage());
        }

        self::assertSame(1, self::requestRows(), 'The commit already stood');
        self::assertSame([$id], $this->drainDbFrames(), 'The other announcements were still made');
        self::assertSame([$id], $this->reaction->seen);

        Database::transactionRollback();

        self::assertSame(1, self::requestRows(), 'There was nothing left to roll back');
        self::assertNull(Database::rollBackLeftOpen());
    }

    /**
     * Writes one deletion request the way the framework does, through the collection's actions.
     *
     * @param int $userId Person the request is opened for
     * @return string Id of the written row, as the frames and the facts carry it
     * @throws HilosException When the write fails
     */
    private function request(int $userId): string
    {
        return (string)Hilos::$db->accountDeletions->actions->request($userId, self::EFFECTIVE_AT)->id;
    }

    /**
     * Drains the queue and names the rows the DB-sync frames in it announced.
     *
     * @return list<string> Row id of each DB-sourced frame, in queue order
     */
    private function drainDbFrames(): array
    {
        $ids = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if ($signal->signalSource->getSource() !== SignalSource::DB) {
                continue;
            }
            self::assertSame(SignalConstants::DB_SYNC_CREATED, $signal->signalType->getType());
            self::assertInstanceOf(DbSyncSignalDataInterface::class, $signal->data);
            self::assertSame(HilosDbContext::accountDeletions, $signal->data->collectionKey);
            $ids[] = $signal->data->idString;
        }

        return $ids;
    }

    /**
     * @return int Deletion requests the database holds, read past the collection and its cache
     * @throws DatabaseException When the count fails
     */
    private static function requestRows(): int
    {
        Database::sql('SELECT COUNT(*) AS total FROM `hilos_account_deletion`');

        return (int)Database::row()['total'];
    }

    /**
     * Kills one server session from a second connection, the way a network failure would end it.
     *
     * @param int $connectionId Server-side id of the session to kill
     * @throws HilosException When the second connection or the kill fails
     */
    private function killFromAnotherSession(int $connectionId): void
    {
        Database::configure(
            index: self::KILLER_INDEX,
            host: Hilos::$env[EnvConstants::DB_HOST]->string(),
            user: Hilos::$env[EnvConstants::DB_USERNAME]->string(),
            password: Hilos::$env[EnvConstants::DB_PASSWORD]->string(),
            database: Hilos::$env[EnvConstants::DB_DATABASE]->string(),
            port: Hilos::$env[EnvConstants::DB_PORT]->int(),
            charset: DatabaseConnectionDefaults::CHARSET,
        );
        Database::connect(self::KILLER_INDEX);
        Database::useConnection(self::KILLER_INDEX);
        try {
            Database::sqlRun('KILL ?', [$connectionId]);
        } finally {
            Database::close(self::KILLER_INDEX);
            Database::useConnection(DatabaseConnectionDefaults::PRIMARY_INDEX);
        }
    }
}

/**
 * Reaction of this process to a changed row: records the id and the provenance it was handed.
 */
final class TransactionTestReaction implements SourceChangeSubscriberInterface
{
    /** @var list<string> Ids of the changes heard, in order */
    public array $seen = [];

    /** @var list<SourceChangeProvenance> Provenance each change was heard with, in order */
    public array $provenances = [];

    /**
     * @param SourceChange $change Announced change whose id is recorded
     * @param SourceChangeProvenance $provenance Provenance to record
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        $this->seen[] = $change->sourceId;
        $this->provenances[] = $provenance;
    }
}

/**
 * Mirror of this process's memory: records the id of every change, at the moment it is told.
 */
final class TransactionTestMirror implements SourceMirrorSubscriberInterface
{
    /** @var list<string> Ids of the changes heard, in order */
    public array $seen = [];

    /**
     * @param SourceChange $change Announced change whose id is recorded
     * @param SourceChangeProvenance $provenance Announced provenance, not read here
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        $this->seen[] = $change->sourceId;
    }
}

/**
 * Reaction that raises the failure it was built with on every change.
 */
final class TransactionTestFailingReaction implements SourceChangeSubscriberInterface
{
    /**
     * @param Throwable $failure Failure raised on every announcement
     */
    public function __construct(private readonly Throwable $failure)
    {
    }

    /**
     * @param SourceChange $change Announced change, not read here
     * @param SourceChangeProvenance $provenance Announced provenance, not read here
     * @throws Throwable The failure this reaction stands for
     */
    public function onSourceChange(SourceChange $change, SourceChangeProvenance $provenance): void
    {
        throw $this->failure;
    }
}

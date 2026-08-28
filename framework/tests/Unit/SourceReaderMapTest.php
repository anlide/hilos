<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Source\Interest\SourceReaderMap;
use Hilos\Core\Source\SourceChange;
use Hilos\Socket\Worker\DTO\WorkerDbInterestReadyMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSnapshotMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerSourceInterestDTO;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\TestCase;

/**
 * How the sender learns who holds a collection, and what it owes a new holder (HIL-717).
 *
 * The map is kept beside the sender because the answer lives in another process: a worker knows
 * what its pages and agents read, the master knows where a frame goes, and the two only meet
 * over the wire. So the cases here pin the same trip the ownership map is pinned by - what the
 * report carries, what the map remembers of it, and what it says is newly owed a state.
 *
 * An entry that outlives its holder is not a leak but a wrong answer: the master would keep
 * writing frames down a socket nobody reads, and the collection they describe would go on
 * being replicated to a process that stopped caring.
 */
final class SourceReaderMapTest extends TestCase
{
    /** @var string Worker the cases report as */
    private const string HOLDER = 'worker:3';

    /** @var string Second worker, for the cases about two holders of one collection */
    private const string OTHER_HOLDER = 'worker:4';

    /** @var string Collection the cases read */
    private const string COLLECTION = 'unitSourceReaderRows';

    /** @var string Second collection, for the cases about a holder reading several */
    private const string OTHER_COLLECTION = 'unitSourceReaderOther';

    public function testAFirstReportOwesAStateForEverythingItNames(): void
    {
        $map = new SourceReaderMap();

        $this->assertSame(
            [self::COLLECTION, self::OTHER_COLLECTION],
            $map->note(self::HOLDER, SourceChange::KIND_RT, [self::COLLECTION, self::OTHER_COLLECTION]),
        );
        $this->assertTrue($map->holds(self::HOLDER, SourceChange::KIND_RT, self::COLLECTION));
    }

    public function testACollectionAlreadyHeldIsNotOwedItsStateAgain(): void
    {
        $map = new SourceReaderMap();
        $map->note(self::HOLDER, SourceChange::KIND_RT, [self::COLLECTION]);

        $this->assertSame(
            [self::OTHER_COLLECTION],
            $map->note(self::HOLDER, SourceChange::KIND_RT, [self::COLLECTION, self::OTHER_COLLECTION]),
        );
    }

    public function testAReportReplacesTheWholeListRatherThanAddingToIt(): void
    {
        $map = new SourceReaderMap();
        $map->note(self::HOLDER, SourceChange::KIND_RT, [self::COLLECTION, self::OTHER_COLLECTION]);

        $map->note(self::HOLDER, SourceChange::KIND_RT, [self::OTHER_COLLECTION]);

        $this->assertFalse($map->holds(self::HOLDER, SourceChange::KIND_RT, self::COLLECTION));
        $this->assertTrue($map->holds(self::HOLDER, SourceChange::KIND_RT, self::OTHER_COLLECTION));
    }

    public function testACollectionDroppedAndTakenUpAgainIsOwedAFreshState(): void
    {
        $map = new SourceReaderMap();
        $map->note(self::HOLDER, SourceChange::KIND_RT, [self::COLLECTION]);
        $map->note(self::HOLDER, SourceChange::KIND_RT, []);

        $this->assertSame(
            [self::COLLECTION],
            $map->note(self::HOLDER, SourceChange::KIND_RT, [self::COLLECTION]),
        );
    }

    public function testAnEmptyReportLeavesTheHolderReadingNothing(): void
    {
        $map = new SourceReaderMap();
        $map->note(self::HOLDER, SourceChange::KIND_RT, [self::COLLECTION]);

        $map->note(self::HOLDER, SourceChange::KIND_RT, []);

        $this->assertFalse($map->holds(self::HOLDER, SourceChange::KIND_RT, self::COLLECTION));
        $this->assertSame([], $map->holders(SourceChange::KIND_RT, self::COLLECTION));
    }

    public function testEveryHolderOfACollectionIsNamed(): void
    {
        $map = new SourceReaderMap();
        $map->note(self::HOLDER, SourceChange::KIND_RT, [self::COLLECTION]);
        $map->note(self::OTHER_HOLDER, SourceChange::KIND_RT, [self::COLLECTION, self::OTHER_COLLECTION]);

        $this->assertSame(
            [self::HOLDER, self::OTHER_HOLDER],
            $map->holders(SourceChange::KIND_RT, self::COLLECTION),
        );
        $this->assertSame(
            [self::OTHER_HOLDER],
            $map->holders(SourceChange::KIND_RT, self::OTHER_COLLECTION),
        );
    }

    public function testTheTwoKindsAreHeldApart(): void
    {
        $map = new SourceReaderMap();
        $map->note(self::HOLDER, SourceChange::KIND_DB, [self::COLLECTION]);

        $this->assertFalse($map->holds(self::HOLDER, SourceChange::KIND_RT, self::COLLECTION));
        $this->assertTrue($map->holds(self::HOLDER, SourceChange::KIND_DB, self::COLLECTION));
    }

    /**
     * A worker that dies never reports that it stopped reading, so its link gives the list back
     * on its behalf. Frames addressed to a process that is gone are the same wrong answer an
     * ownership claim outliving its agent would be.
     */
    public function testADeadHolderStopsReadingEverything(): void
    {
        $map = new SourceReaderMap();
        $map->note(self::HOLDER, SourceChange::KIND_RT, [self::COLLECTION]);
        $map->note(self::OTHER_HOLDER, SourceChange::KIND_RT, [self::COLLECTION]);

        $map->release(self::HOLDER);

        $this->assertSame([self::OTHER_HOLDER], $map->holders(SourceChange::KIND_RT, self::COLLECTION));
    }

    public function testAWorkerIsNamedByItsIndexAndCannotReadAsANode(): void
    {
        $this->assertSame(self::HOLDER, AgentManagerDaemon::workerHolderId(3));
    }

    /**
     * The union is what the level above announces: a node tells the mesh what it reads as one
     * list, and which of its workers is behind a key changes nothing for the sender.
     */
    public function testTheUnionNamesEveryCollectionAnybodyReadsExactlyOnce(): void
    {
        $map = new SourceReaderMap();
        $map->note(self::HOLDER, SourceChange::KIND_RT, [self::COLLECTION]);
        $map->note(self::OTHER_HOLDER, SourceChange::KIND_RT, [self::COLLECTION, self::OTHER_COLLECTION]);

        $this->assertSame(
            [self::COLLECTION, self::OTHER_COLLECTION],
            $map->collections(SourceChange::KIND_RT),
        );
    }

    public function testTheUnionKeepsTheTwoKindsApartTheWayEverythingElseDoes(): void
    {
        $map = new SourceReaderMap();
        $map->note(self::HOLDER, SourceChange::KIND_DB, [self::COLLECTION]);

        $this->assertSame([], $map->collections(SourceChange::KIND_RT));
        $this->assertSame([self::COLLECTION], $map->collections(SourceChange::KIND_DB));
    }

    /**
     * A collection one worker gave up is still read here while another worker holds it, and that
     * is the case the union exists to get right: announcing the drop would stop the frames for
     * every reader on this node, not just the one that left.
     */
    public function testACollectionOneOfTwoReadersGaveUpStaysInTheUnion(): void
    {
        $map = new SourceReaderMap();
        $map->note(self::HOLDER, SourceChange::KIND_RT, [self::COLLECTION]);
        $map->note(self::OTHER_HOLDER, SourceChange::KIND_RT, [self::COLLECTION]);

        $map->release(self::HOLDER);

        $this->assertSame([self::COLLECTION], $map->collections(SourceChange::KIND_RT));
    }

    public function testTheUnionOfAMapNobodyReportedToIsEmpty(): void
    {
        $this->assertSame([], (new SourceReaderMap())->collections(SourceChange::KIND_RT));
    }

    /**
     * @throws InvalidFormatException When the frame is not the object its DTO needs
     */
    public function testTheInterestReportRoundTripsToTheMaster(): void
    {
        $dto = new WorkerSourceInterestDTO([self::COLLECTION, self::OTHER_COLLECTION], []);

        $parsed = WorkerDTO::factoryWorkerDTO($dto->toJson());

        $this->assertInstanceOf(WorkerSourceInterestDTO::class, $parsed);
        $this->assertSame([self::COLLECTION, self::OTHER_COLLECTION], $parsed->rtCollections);
    }

    /**
     * Both halves travel in the one frame, and each keeps its own names (HIL-750): a worker
     * reading a runtime collection and a database collection of the same name would otherwise
     * report one interest twice and leave the other kind unaddressed.
     *
     * @throws InvalidFormatException When the frame is not the object its DTO needs
     */
    public function testTheInterestReportCarriesBothKindsWithoutMixingThem(): void
    {
        $dto = new WorkerSourceInterestDTO([self::COLLECTION], [self::OTHER_COLLECTION]);

        $parsed = WorkerDTO::factoryWorkerDTO($dto->toJson());

        $this->assertInstanceOf(WorkerSourceInterestDTO::class, $parsed);
        $this->assertSame([self::COLLECTION], $parsed->rtCollections);
        $this->assertSame([self::OTHER_COLLECTION], $parsed->dbCollections);
    }

    /**
     * A worker of an older build names no database list at all, and that has to read as "asked
     * for nothing" rather than as a malformed frame - the same tolerance the RT list has always
     * had, for the same reason.
     *
     * @throws InvalidFormatException When the frame is not the object its DTO needs
     */
    public function testAnInterestReportWithoutTheDatabaseListReadsAsAskingForNothing(): void
    {
        $parsed = WorkerDTO::factoryWorkerDTO((string)json_encode([
            WorkerDTO::TYPE => WorkerSourceInterestDTO::MESSAGE_TYPE,
            WorkerSourceInterestDTO::FIELD_RT_COLLECTIONS => [self::COLLECTION],
        ]));

        $this->assertInstanceOf(WorkerSourceInterestDTO::class, $parsed);
        $this->assertSame([self::COLLECTION], $parsed->rtCollections);
        $this->assertSame([], $parsed->dbCollections);
    }

    /**
     * The last reader of a worker leaving is a report like any other, and the empty list is the
     * whole of what it says: a frame that never arrives cannot be told from one that says
     * nothing changed.
     *
     * @throws InvalidFormatException When the frame is not the object its DTO needs
     */
    public function testAnEmptyInterestReportSurvivesTheWire(): void
    {
        $dto = new WorkerSourceInterestDTO([], []);

        $parsed = WorkerDTO::factoryWorkerDTO($dto->toJson());

        $this->assertInstanceOf(WorkerSourceInterestDTO::class, $parsed);
        $this->assertSame([], $parsed->rtCollections);
        $this->assertSame([], $parsed->dbCollections);
    }

    /**
     * The confirmation the database half is answered with carries no rows on purpose, so what
     * has to survive the wire is the collection it is about - a worker that could not tell which
     * of its declared collections became readable would have to guess.
     *
     * @throws InvalidFormatException When the frame is not the object its DTO needs
     */
    public function testTheDatabaseConfirmationRoundTripsToTheWorker(): void
    {
        $dto = new WorkerDbInterestReadyMessageDTO(self::COLLECTION);

        $parsed = WorkerDTO::factoryWorkerDTO($dto->toJson());

        $this->assertInstanceOf(WorkerDbInterestReadyMessageDTO::class, $parsed);
        $this->assertSame(self::COLLECTION, $parsed->collectionKey);
    }

    /**
     * The two kinds are noted from the one report and stay apart in the map: a worker naming a
     * collection as a database reader must not come out of it holding the runtime collection of
     * that name, which is exactly what a map keyed by name alone would answer.
     */
    public function testAReportNotesBothKindsAndOwesEachOfThemSeparately(): void
    {
        $map = new SourceReaderMap();

        $this->assertSame(
            [self::COLLECTION],
            $map->note(self::HOLDER, SourceChange::KIND_DB, [self::COLLECTION]),
        );
        $this->assertTrue($map->holds(self::HOLDER, SourceChange::KIND_DB, self::COLLECTION));
        $this->assertFalse($map->holds(self::HOLDER, SourceChange::KIND_RT, self::COLLECTION));
    }

    /**
     * A database collection dropped from a later report stops being held, the same way a runtime
     * one does: the report replaces what the worker read before rather than adding to it.
     */
    public function testADatabaseCollectionLeftOutOfTheNextReportIsNoLongerHeld(): void
    {
        $map = new SourceReaderMap();
        $map->note(self::HOLDER, SourceChange::KIND_DB, [self::COLLECTION, self::OTHER_COLLECTION]);

        $map->note(self::HOLDER, SourceChange::KIND_DB, [self::OTHER_COLLECTION]);

        $this->assertFalse($map->holds(self::HOLDER, SourceChange::KIND_DB, self::COLLECTION));
        $this->assertTrue($map->holds(self::HOLDER, SourceChange::KIND_DB, self::OTHER_COLLECTION));
    }

    /**
     * @throws InvalidFormatException When the frame is not the object its DTO needs
     */
    public function testTheSnapshotRoundTripsToTheWorker(): void
    {
        $dto = new WorkerRtSnapshotMessageDTO(self::COLLECTION, ['7' => ['id' => '7', 'name' => 'row']]);

        $parsed = WorkerDTO::factoryWorkerDTO($dto->toJson());

        $this->assertInstanceOf(WorkerRtSnapshotMessageDTO::class, $parsed);
        $this->assertSame(self::COLLECTION, $parsed->collectionKey);
        $this->assertSame(['7' => ['id' => '7', 'name' => 'row']], $parsed->rows);
    }

    /**
     * An empty snapshot is an answer and not a missing one: a collection nobody has written yet
     * exists and is empty, and the reader waiting on it has to be let go.
     *
     * @throws InvalidFormatException When the frame is not the object its DTO needs
     */
    public function testAnEmptySnapshotIsStillAnAnswer(): void
    {
        $dto = new WorkerRtSnapshotMessageDTO(self::COLLECTION, []);

        $parsed = WorkerDTO::factoryWorkerDTO($dto->toJson());

        $this->assertInstanceOf(WorkerRtSnapshotMessageDTO::class, $parsed);
        $this->assertSame([], $parsed->rows);
    }
}

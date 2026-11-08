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
use WeakReference;

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

    public function testCompletedUnionValuesStayDetachedAcrossCallerWritesKindChangesAndWholeHolderRelease(): void
    {
        $map = new SourceReaderMap();
        $map->note(self::HOLDER, SourceChange::KIND_RT, ['0', '07', '0']);
        $map->note(self::HOLDER, SourceChange::KIND_DB, ['db']);
        $map->note(self::OTHER_HOLDER, SourceChange::KIND_RT, ['last', '07']);
        $original = $map->collections(SourceChange::KIND_RT);
        $caller = $map->collections(SourceChange::KIND_RT);
        $caller[] = 'caller-only';
        self::assertSame(['0', '07', 'last'], $map->collections(SourceChange::KIND_RT));
        $map->note(self::HOLDER, SourceChange::KIND_DB, ['new-db']);
        self::assertSame(['0', '07', 'last'], $map->collections(SourceChange::KIND_RT));
        self::assertSame(['new-db'], $map->collections(SourceChange::KIND_DB));
        $map->note(self::HOLDER, SourceChange::KIND_RT, ['last']);
        self::assertSame(['last', '07'], $map->collections(SourceChange::KIND_RT));
        self::assertSame(['0', '07', 'last'], $original);
        $map->release(self::OTHER_HOLDER);
        self::assertSame(['last'], $map->collections(SourceChange::KIND_RT));
        $map->release(self::HOLDER);
        self::assertSame([], $map->collections(SourceChange::KIND_RT));
        self::assertSame([], $map->collections(SourceChange::KIND_DB));
        $map->note(self::OTHER_HOLDER, SourceChange::KIND_RT, ['07']);
        self::assertSame(['07'], $map->collections(SourceChange::KIND_RT));
    }

    public function testIndexedMembershipKeepsOuterHolderOrderWhenAnotherKindPreservesItsPosition(): void
    {
        $map = new SourceReaderMap();
        $map->note(self::HOLDER, SourceChange::KIND_DB, [self::OTHER_COLLECTION]);
        $map->note(self::OTHER_HOLDER, SourceChange::KIND_RT, [self::COLLECTION]);
        $map->note(self::HOLDER, SourceChange::KIND_RT, [self::COLLECTION]);
        $this->assertSame([self::HOLDER, self::OTHER_HOLDER], $map->holders(SourceChange::KIND_RT, self::COLLECTION));
        $map->note(self::HOLDER, SourceChange::KIND_RT, []);
        $this->assertTrue($map->holds(self::HOLDER, SourceChange::KIND_DB, self::OTHER_COLLECTION));
        $this->assertFalse($map->holds(self::HOLDER, SourceChange::KIND_RT, self::COLLECTION));
        $map->note(self::HOLDER, SourceChange::KIND_RT, [self::COLLECTION]);
        $this->assertSame([self::HOLDER, self::OTHER_HOLDER], $map->holders(SourceChange::KIND_RT, self::COLLECTION));
        $map->release(self::HOLDER);
        $map->note(self::HOLDER, SourceChange::KIND_RT, [self::COLLECTION]);
        $this->assertSame([self::OTHER_HOLDER, self::HOLDER], $map->holders(SourceChange::KIND_RT, self::COLLECTION));
    }

    public function testUnionRetainsCurrentReportOrderAndNewDuplicateObligations(): void
    {
        $map = new SourceReaderMap();
        $this->assertSame(['0', '07', '07'], $map->note(self::HOLDER, SourceChange::KIND_RT, ['0', '07', '07']));
        $map->note(self::OTHER_HOLDER, SourceChange::KIND_RT, ['07', 'last']);
        $this->assertSame(['0', '07', 'last'], $map->collections(SourceChange::KIND_RT));
        $this->assertSame(['last'], $map->note(self::HOLDER, SourceChange::KIND_RT, ['last', '07']));
        $this->assertSame(['last', '07'], $map->collections(SourceChange::KIND_RT));
        $this->assertFalse($map->holds(self::HOLDER, SourceChange::KIND_RT, '0'));
        $this->assertFalse($map->holds(self::HOLDER, SourceChange::KIND_RT, '7'));
        $this->assertTrue($map->holds(self::HOLDER, SourceChange::KIND_RT, '07'));
        $map->release(self::OTHER_HOLDER);
        $map->note(self::HOLDER, SourceChange::KIND_RT, []);
        $this->assertSame([], $map->collections(SourceChange::KIND_RT));
        $this->assertFalse($map->holds(self::HOLDER, SourceChange::KIND_RT, '07'));
    }

    public function testAFiniteUnionCapturesBothReportsBeforeItsFirstAdvancement(): void
    {
        $map = new SourceReaderMap();
        $map->note(self::HOLDER, SourceChange::KIND_DB, ['db']);
        $map->note(self::OTHER_HOLDER, SourceChange::KIND_RT, ['07', '0', '07']);
        $map->note(self::HOLDER, SourceChange::KIND_RT, ['0', '7', '0']);
        $rt = $map->collectionCandidates(SourceChange::KIND_RT);
        $db = $map->collectionCandidates(SourceChange::KIND_DB);

        $map->note(self::HOLDER, SourceChange::KIND_RT, ['future']);
        $map->release(self::OTHER_HOLDER);
        $map->note(self::OTHER_HOLDER, SourceChange::KIND_RT, ['rejoined']);
        $reference = WeakReference::create($map);
        unset($map);
        self::assertNull($reference->get(), 'A finite reader owns original scalar values, never its mutable map');
        self::assertSame([null, '0', '7', null, null, '07', null, null], iterator_to_array($rt, false));
        self::assertSame([null, 'db', null], iterator_to_array($db, false));
    }

    public function testAPairedUnionPlanKeepsOriginalValuesAndRestartsAnAbandonedPrivateBuild(): void
    {
        $map = new SourceReaderMap();
        $map->note(self::HOLDER, SourceChange::KIND_RT, ['0', '7', '0']);
        $map->note(self::OTHER_HOLDER, SourceChange::KIND_DB, ['db', 'db']);
        $plan = $map->unionPlan();
        self::assertSame($plan, $map->unionPlan());
        $attempt = $plan->build();
        $attempt->rewind();
        $attempt->next();
        $attempt->next();
        self::assertFalse($plan->ready);
        self::assertSame([SourceChange::KIND_RT => [], SourceChange::KIND_DB => []], $plan->collections,
            'A partial RT half cannot become the published pair');
        unset($attempt);
        $map->note(self::HOLDER, SourceChange::KIND_RT, ['future']);
        $map->release(self::OTHER_HOLDER);
        self::assertNotSame($plan, $map->unionPlan());
        $reference = WeakReference::create($map);
        unset($map);
        self::assertNull($reference->get(), 'An unfinished union owns original paths, never the mutable holder map');
        foreach ($plan->build() as $_) {
        }
        self::assertTrue($plan->ready);
        self::assertSame([SourceChange::KIND_RT => ['0', '7'], SourceChange::KIND_DB => ['db']], $plan->collections);
        self::assertSame([], iterator_to_array($plan->build(), false), 'The next FIFO reader reuses the complete pair');
    }

    public function testEqualCompleteUnionsCompareByNamesAcrossPortionsAndKeepStrictOrder(): void
    {
        $map = new SourceReaderMap();
        $names = [];
        for ($index = 0; $index < 8192; $index++) {
            $names[] = (string)$index;
        }
        $map->note(self::HOLDER, SourceChange::KIND_RT, $names);
        $before = $map->unionPlan();
        foreach ($before->build() as $_) {
        }
        $map->note(self::OTHER_HOLDER, SourceChange::KIND_RT, $names);
        $after = $map->unionPlan();
        foreach ($after->build() as $_) {
        }
        $comparison = $before->compare($after);
        $steps = 0;
        foreach ($comparison as $_) {
            $steps++;
        }
        self::assertGreaterThanOrEqual(count($names), $steps, 'An equal large pair cannot hide its strict comparison in one next()');
        self::assertTrue($comparison->getReturn());
        self::assertSame($names, $after->collections[SourceChange::KIND_RT]);
        $map->release(self::HOLDER);
        $map->note(self::OTHER_HOLDER, SourceChange::KIND_RT, array_reverse($names));
        $reordered = $map->unionPlan();
        foreach ($reordered->build() as $_) {
        }
        $comparison = $after->compare($reordered);
        foreach ($comparison as $_) {
        }
        self::assertFalse($comparison->getReturn(), 'The old strict ordered-list verdict is not a set comparison');
    }

    public function testDuplicateNamesAndHoldersOfTheOtherKindEachPayAUnionStep(): void
    {
        $map = new SourceReaderMap();
        $map->note(self::HOLDER, SourceChange::KIND_DB, ['db']);
        $map->note(self::OTHER_HOLDER, SourceChange::KIND_RT, array_fill(0, 8192, 'duplicate'));
        $names = [];
        $steps = 0;
        foreach ($map->collectionCandidates(SourceChange::KIND_RT) as $candidate) {
            if ($candidate !== null) {
                $names[] = $candidate;
            }
            $steps++;
        }
        self::assertSame(['duplicate'], $names);
        self::assertSame(8194, $steps, 'Duplicates must not be skipped by an unbounded loop inside next()');
    }

    public function testCapturedUnionsKeepTheirValuesThroughReportReplacementReleaseAndRejoinChurn(): void
    {
        $map = new SourceReaderMap();
        $legacy = [];
        $captured = [];
        for ($turn = 0; $turn < 512; $turn++) {
            $holder = (string)(($turn * 73) % 127);
            $kind = $turn % 3 === 0 ? SourceChange::KIND_DB : SourceChange::KIND_RT;
            if ($turn % 5 === 0) {
                $map->release($holder);
                unset($legacy[$holder]);
            } else {
                $names = $turn % 7 === 0 ? [] : [(string)($turn % 11), '07', 'name-' . $turn, '07'];
                $map->note($holder, $kind, $names);
                if ($names === []) {
                    unset($legacy[$holder][$kind]);
                    if (($legacy[$holder] ?? []) === []) {
                        unset($legacy[$holder]);
                    }
                } else {
                    $legacy[$holder][$kind] = $names;
                }
            }
            foreach ([SourceChange::KIND_RT, SourceChange::KIND_DB] as $sourceKind) {
                $expected = [];
                $seen = [];
                foreach ($legacy as $report) {
                    foreach ($report[$sourceKind] ?? [] as $name) {
                        if (!isset($seen[$name])) {
                            $seen[$name] = true;
                            $expected[] = $name;
                        }
                    }
                }
                self::assertSame($expected, $map->collections($sourceKind), 'Live union at turn ' . $turn);
                if ($turn % 32 === 0) {
                    $captured[] = [$expected, $map->collectionCandidates($sourceKind)];
                }
            }
        }
        $reference = WeakReference::create($map);
        unset($map);
        self::assertNull($reference->get());
        foreach ($captured as [$expected, $cursor]) {
            $actual = [];
            foreach ($cursor as $candidate) {
                if ($candidate !== null) {
                    $actual[] = $candidate;
                }
            }
            self::assertSame($expected, $actual, 'Later changes cannot rewrite an unstarted original union');
        }
    }

    public function testAPortionedReportPublishesBothKindsTogetherAfterEveryOriginalName(): void
    {
        $map = new SourceReaderMap();
        $map->note(self::HOLDER, SourceChange::KIND_DB, ['old-db']);
        $map->note(self::HOLDER, SourceChange::KIND_RT, ['old-rt']);
        $rt = [];
        $db = [];
        for ($index = 0; $index < 4096; $index++) {
            $rt[] = 'rt-' . $index;
            $db[] = 'db-' . $index;
        }
        $delivery = $map->noteReportDelivery(self::HOLDER, $rt, $db, static fn(): bool => true);
        $steps = 0;
        foreach ($delivery as $_) {
            self::assertTrue($map->holds(self::HOLDER, SourceChange::KIND_DB, 'old-db'));
            self::assertTrue($map->holds(self::HOLDER, SourceChange::KIND_RT, 'old-rt'));
            self::assertFalse($map->holds(self::HOLDER, SourceChange::KIND_DB, 'db-0'));
            self::assertFalse($map->holds(self::HOLDER, SourceChange::KIND_RT, 'rt-4095'));
            $steps++;
        }
        self::assertGreaterThanOrEqual(8192, $steps, 'Every input name must pay a preparation step');
        self::assertSame([SourceChange::KIND_DB => $db, SourceChange::KIND_RT => $rt], $delivery->getReturn());
        self::assertSame($db, $map->collections(SourceChange::KIND_DB));
        self::assertSame($rt, $map->collections(SourceChange::KIND_RT));
    }

    public function testAPortionedReportKeepsRawOrderNumericNamesDuplicatesAndNewObligations(): void
    {
        $map = new SourceReaderMap();
        $map->note(self::HOLDER, SourceChange::KIND_DB, ['old']);
        $map->note(self::HOLDER, SourceChange::KIND_RT, ['0']);
        $map->note(self::OTHER_HOLDER, SourceChange::KIND_RT, ['other', '07']);
        $delivery = $map->noteReportDelivery(self::HOLDER, ['0', '07', '07'], ['0', '0', '7'], static fn(): bool => true);
        foreach ($delivery as $_) {
        }
        self::assertSame([SourceChange::KIND_DB => ['0', '0', '7'], SourceChange::KIND_RT => ['07', '07']], $delivery->getReturn());
        self::assertSame(['0', '07', 'other'], $map->collections(SourceChange::KIND_RT));
        self::assertSame([self::HOLDER, self::OTHER_HOLDER], $map->holders(SourceChange::KIND_RT, '07'));
        self::assertFalse($map->holds(self::HOLDER, SourceChange::KIND_DB, 'old'));
        $empty = $map->noteReportDelivery(self::HOLDER, [], [], static fn(): bool => true);
        foreach ($empty as $_) {
        }
        self::assertSame([SourceChange::KIND_DB => [], SourceChange::KIND_RT => []], $empty->getReturn());
        $map->note(self::HOLDER, SourceChange::KIND_RT, ['07']);
        self::assertSame([self::OTHER_HOLDER, self::HOLDER], $map->holders(SourceChange::KIND_RT, '07'));
    }

    public function testAPortionedReportStopsWithoutPublishingWhenItsTransportLeaves(): void
    {
        $map = new SourceReaderMap();
        $map->note(self::HOLDER, SourceChange::KIND_RT, ['old']);
        $current = true;
        $delivery = $map->noteReportDelivery(self::HOLDER, ['new-rt'], ['new-db'], static function () use (&$current): bool {
            return $current;
        });
        $delivery->rewind();
        $delivery->next();
        $delivery->next();
        $delivery->next();
        $current = false;
        while ($delivery->valid()) {
            $delivery->next();
        }
        self::assertSame([SourceChange::KIND_DB => [], SourceChange::KIND_RT => []], $delivery->getReturn());
        self::assertSame([], $map->collections(SourceChange::KIND_DB));
        self::assertSame(['old'], $map->collections(SourceChange::KIND_RT));
    }

    public function testAFiniteReportDoesNotOwnItsMapWhilePreparationIsSuspended(): void
    {
        $map = new SourceReaderMap();
        $map->note(self::HOLDER, SourceChange::KIND_RT, ['old']);
        $weak = WeakReference::create($map);
        $delivery = $map->noteReportDelivery(self::HOLDER, ['new'], [], static fn(): bool => true);
        $delivery->rewind();
        $delivery->next();
        unset($map);
        self::assertNull($weak->get(), 'The cursor must release its process-private owner without a GC pass');
        while ($delivery->valid()) {
            $delivery->next();
        }
        self::assertSame([SourceChange::KIND_DB => [], SourceChange::KIND_RT => []], $delivery->getReturn());
    }

    public function testQueuedReportsReadTheirPredecessorsCompleteMembershipWhenTheyStart(): void
    {
        $map = new SourceReaderMap();
        $first = $map->noteReportDelivery(self::HOLDER, ['rt'], ['db'], static fn(): bool => true);
        $second = $map->noteReportDelivery(self::HOLDER, ['rt', 'rt'], ['db', 'db'], static fn(): bool => true);
        foreach ($first as $_) {
        }
        foreach ($second as $_) {
        }
        self::assertSame([SourceChange::KIND_DB => ['db'], SourceChange::KIND_RT => ['rt']], $first->getReturn());
        self::assertSame([SourceChange::KIND_DB => [], SourceChange::KIND_RT => []], $second->getReturn());
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

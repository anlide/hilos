<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Tests\Fixtures\SignalDispatcherFixture;
use Closure;
use Generator;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\DTO\PeerDrainDTO;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerHelloDTO;
use Hilos\Cluster\Peer\DTO\PeerReadyAckDTO;
use Hilos\Cluster\Peer\DTO\PeerReadyDTO;
use Hilos\Cluster\Peer\DTO\PeerRtClaimEntry;
use Hilos\Cluster\Peer\DTO\PeerRtClaimsDTO;
use Hilos\Cluster\Peer\DTO\PeerRtReplicaOfferDTO;
use Hilos\Cluster\Peer\DTO\PeerRtSnapshotQueryDTO;
use Hilos\Cluster\Peer\DTO\PeerRtSnapshotRequestDTO;
use Hilos\Cluster\Peer\DTO\PeerRtSyncStreamDTO;
use Hilos\Cluster\Peer\DTO\PeerSourceInterestAnsweredDTO;
use Hilos\Cluster\Peer\DTO\PeerSourceInterestDTO;
use Hilos\Cluster\Peer\DTO\PeerWelcomeDTO;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\SignalDispatch;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Source\Interest\SourceReaderMap;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Runtime\RtSnapshot;
use Hilos\Runtime\Snapshot\RtSnapshotScope;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\Snapshot\RtWorkBudget;
use Hilos\Runtime\Snapshot\RtWorkDriver;
use Hilos\Runtime\Stream\RtSyncStreamChannel;
use Hilos\Runtime\Stream\RtSyncStreamDecoder;
use Hilos\Runtime\Stream\RtSyncStreamFrame;
use Hilos\Runtime\Stream\RtSyncStreamOperation;
use Hilos\Runtime\Stream\RtSyncStreamSnapshot;
use Hilos\Runtime\Stream\RtSyncStreamStatus;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Worker\DTO\WorkerRtSourceRegisteredDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSourceReleasedDTO;
use Hilos\Socket\Worker\DTO\WorkerSourceInterestDTO;
use Hilos\Tests\Unit\DaemonManagerRtSyncPeerTestRtContext;
use Hilos\Tests\Unit\ReaderNativeAgents;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Socket;
use SplQueue;

/** The private preparation window is shorter than a stand tick; both real peer protocols run in this process. */
final class PeerReaderRepairTest extends TestCase
{
    private ?EnvAccessor $previousEnv;
    private ?ClusterContext $previousCluster;
    private ?RtContext $previousRuntime;
    private ?SignalRouter $previousRouter;

    /** @var array<string, string|false> Original process environment */
    private array $previousValues = [];

    /** @var list<Socket> Sockets retained until every cursor has settled */
    private array $sockets = [];

    #[TestWith([false, false])]
    #[TestWith([false, true])]
    #[TestWith([true, false])]
    #[TestWith([true, true])]
    public function testOnlineReplacementRepairsBothDirectionsWithFreshCompleteReports(bool $empty, bool $preparing): void
    {
        $a = $this->server('node-a');
        $b = $this->server('node-b');
        // L1 was dialed by B; the preferred L2 is dialed by A.
        [$oldA, $oldB] = $this->connect($a, $b, false);
        $initial = $empty ? ['retired'] : [];
        $a->announceSourceInterest($initial, $initial, 1);
        $b->announceSourceInterest($initial, $initial, 1);
        $this->relay($oldA, $oldB);
        $this->relay($oldB, $oldA);
        $mapA = new ReflectionProperty(PeerServer::class, 'nodeReaderMap')->getValue($a);
        $mapB = new ReflectionProperty(PeerServer::class, 'nodeReaderMap')->getValue($b);
        $queues = [new SplQueue(), new SplQueue()];
        $bindings = [];
        foreach ([$a, $b] as $index => $server) {
            $queue = $queues[$index];
            $bindings[$index] = static function (Closure $delivery) use ($queue): void {
                $queue->enqueue($delivery(SignalDispatcherFixture::direct()));
            };
            $server->setSourceInterestDelivery($bindings[$index]);
        }
        $rt = $empty ? [] : ['rows', 'second'];
        $db = $empty ? [] : ['records', 'second-db'];
        $a->announceSourceInterest($rt, $db, 2);
        $b->announceSourceInterest($rt, $db, 2);
        self::assertEquals([new PeerSourceInterestDTO('node-a', $rt, $db, 2)], $this->relay($oldA, $oldB));
        self::assertEquals([new PeerSourceInterestDTO('node-b', $rt, $db, 2)], $this->relay($oldB, $oldA));
        $oldCursors = [];
        foreach ($queues as $queue) {
            self::assertCount(1, $queue);
            $delivery = $queue->dequeue();
            // An older finite source head, or private preparation of the report itself.
            $oldCursors[] = new SignalDispatch(null, static function () use ($delivery, $preparing): Generator {
                if (!$preparing) {
                    for ($visit = 0; $visit < 64; ++$visit) {
                        yield;
                    }
                }
                yield from $delivery;
            });
            $oldCursors[array_key_last($oldCursors)]->runPortion($preparing ? ($empty ? 3 : 6) : 1);
            self::assertTrue($oldCursors[array_key_last($oldCursors)]->hasWork());
        }
        $this->assertReport($mapA, 'node-b', $initial, $initial);
        $this->assertReport($mapB, 'node-a', $initial, $initial);

        [$newA, $newB] = $this->connect($a, $b, true, $rt, $db);
        foreach ($queues as $queue) {
            self::assertCount(0, $queue, 'The repeat on the new link waits behind the old link\'s drain marker');
        }
        $this->readOldDrain($oldB, $oldA, $newA);
        $this->readOldDrain($oldA, $oldB, $newB);
        foreach ($oldCursors as $cursor) {
            $cursor->runPortion(256);
            self::assertFalse($cursor->hasWork());
        }
        $this->assertReport($mapA, 'node-b', $initial, $initial);
        $this->assertReport($mapB, 'node-a', $initial, $initial);
        // Only actual new-link reports may publish the replacement's reader map.
        foreach ($queues as $queue) {
            self::assertCount(1, $queue, 'Each endpoint must receive one fresh replacement report without a union change');
            $delivery = $queue->dequeue();
            $cursor = new SignalDispatch(null, static fn(): Generator => $delivery);
            $cursor->runPortion(256);
            self::assertFalse($cursor->hasWork());
        }
        $this->assertReport($mapA, 'node-b', $rt, $db);
        $this->assertReport($mapB, 'node-a', $rt, $db);
        foreach ([[$a, $newA], [$b, $newB]] as [$server, $route]) {
            $server->streamRtSnapshotToNode($server === $a ? 'node-b' : 'node-a', $empty ? 'retired' : 'rows', RtSnapshotScope::whole(), false);
            $frames = $this->take($route);
            self::assertCount($empty ? 0 : 1, $frames, 'Interest filters must stop retired delivery and admit expanded delivery');
            foreach ($frames as $frame) {
                self::assertInstanceOf(PeerRtSyncStreamDTO::class, $frame);
                $snapshot = json_decode($frame->frame->data, true);
                self::assertSame(RtSyncStreamSnapshot::SIGNAL_TYPE, $snapshot[RtSyncStreamOperation::FIELD_SIGNAL_TYPE]);
                self::assertSame('rows', $snapshot[RtSyncStreamOperation::FIELD_COLLECTION_KEY]);
                self::assertSame($server === $a ? 'node-a' : 'node-b', $snapshot[RtSyncStreamOperation::FIELD_ORIGIN_NODE_ID]);
            }
        }
        self::assertSame(['node-b'], $a->linkedNodeIds());
        self::assertSame(['node-a'], $b->linkedNodeIds());
        $a->removeSourceInterestDelivery($bindings[0]);
        $b->removeSourceInterestDelivery($bindings[1]);
    }

    /**
     * The owner's answer to the reader's announcement waits on the old link behind a snapshot that link
     * never finishes writing: the replacement drops it, writing it neither there nor on the new link,
     * and the reader's repeat on the new link is answered behind a hand-over of everything it names.
     * An answer the old link did write leaves the repeat an ordinary one: it adds nothing, and is
     * answered with no snapshot.
     *
     * @param bool $waiting Whether the snapshot ahead of the answer spans several parts, so the answer waits
     */
    #[TestWith([true])]
    #[TestWith([false])]
    public function testAReplacementDropsAWaitingAnswerAndItsRepeatIsAnsweredBehindEverythingItNames(bool $waiting): void
    {
        $collection = DaemonManagerRtSyncPeerTestRtContext::ROWS;
        $a = $this->server('node-a');
        $b = $this->server('node-b');
        [$oldA, $oldB] = $this->connect($a, $b, false);
        $owner = new ReaderRepairManager();
        new ReflectionProperty(DaemonManager::class, 'servers')->setValue($owner, [$a]);
        $owner->getAgentManagerDaemon()->handleRtSourceRegistered(new WorkerRtSourceRegisteredDTO('owner', [$collection]));
        $runtime = new DaemonManagerRtSyncPeerTestRtContext();
        $runtime->configure();
        Hilos::$rt = $runtime;
        $name = $waiting ? str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES) : 'small';
        RtSnapshot::replace($collection, ['s0' => ['id' => 's0', 'name' => $name], 's1' => ['id' => 's1', 'name' => $name]]);
        Hilos::$cluster->registerRtSyncSink($owner);
        $a->announceSourceInterest([$collection], [], 2);
        $b->announceSourceInterest([$collection], [], 2);
        $this->take($oldA);

        $this->relay($oldB, $oldA);
        $before = $this->take($oldA);
        $queue = new SplQueue();
        $binding = static function (Closure $delivery) use ($queue): void {
            $queue->enqueue($delivery(SignalDispatcherFixture::direct()));
        };
        $a->setSourceInterestDelivery($binding);
        $migrated = $waiting ? [new PeerRtClaimsDTO('node-a', [new PeerRtClaimEntry('owner', 'owner', null, [$collection])])] : [];
        $new = $this->connect($a, $b, true, [$collection], [], $migrated);
        $drained = $this->take($oldA);
        self::assertCount(0, $queue, 'The repeat on the new link waits behind the old link\'s drain marker');
        $this->readOldDrain($oldB, $oldA, $new[0]);
        self::assertCount(1, $queue, 'The repeat on the new link waits for its delivery');
        $delivery = $queue->dequeue();
        $cursor = new SignalDispatch(null, static fn(): Generator => $delivery);
        $cursor->runPortion(256);
        $after = $this->take($new[0]);
        $a->removeSourceInterestDelivery($binding);

        $answer = new PeerSourceInterestAnsweredDTO('node-a', 2);
        $answers = static fn (array $frames): array => array_values(array_filter(
            $frames,
            static fn (PeerDTO $frame): bool => $frame instanceof PeerSourceInterestAnsweredDTO,
        ));
        self::assertInstanceOf(PeerRtSyncStreamDTO::class, $before[0], 'The snapshot of the area goes ahead of the answer');
        self::assertInstanceOf(PeerDrainDTO::class, $drained[array_key_last($drained)]);
        if ($waiting) {
            self::assertSame([], $answers($before), 'The answer waits behind the snapshot\'s next part');
            self::assertSame([], $answers($drained), 'The replacement drops the answer instead of writing it');
            self::assertInstanceOf(PeerRtSyncStreamDTO::class, $after[0], 'The repeat is answered behind a hand-over');
            $decoder = new RtSyncStreamDecoder();
            RtWorkDriver::runSynchronously($decoder->feed($after[0]->frame->data));
            self::assertSame($collection, RtWorkDriver::runSynchronously(RtSyncStreamSnapshot::read($decoder))->collectionKey);
            self::assertSame([], $answers($after), 'The answer waits behind it again, on the new link');
        } else {
            self::assertEquals([$answer], $answers($before));
            self::assertEquals([$answer], $after, 'An answer that was written leaves the repeat ordinary');
        }
    }

    /**
     * The accepted delta crosses the replacement after its source relinquishes its area, whether
     * alone or behind a canceled snapshot and dropped interest answer. The deferred repeat later
     * recovers a fresh base through the existing debt.
     *
     * @param ?int $stage Null for no snapshot, zero for queued, one for a consumed cut, two for a first part
     */
    #[TestWith([null])]
    #[TestWith([0])]
    #[TestWith([1])]
    #[TestWith([2])]
    public function testTransferredDeltaSurvivesLostOwnershipAndPrecedesTheRepeatedHandOver(?int $stage): void
    {
        $collection = DaemonManagerRtSyncPeerTestRtContext::ROWS;
        $a = $this->server('node-a');
        $b = $this->server('node-b');
        $a->setRtSyncStreamWake(static function (): void {
        });
        [$oldA, $oldB] = $this->connect($a, $b, false);
        $a->announceSourceInterest([$collection], [], 2);
        $b->announceSourceInterest([$collection], [], 2);
        $this->relay($oldA, $oldB);
        $this->relay($oldB, $oldA);
        $owner = new ReaderRepairManager();
        new ReflectionProperty(DaemonManager::class, 'servers')->setValue($owner, [$a]);
        $agents = $owner->getAgentManagerDaemon();
        $agents->handleRtSourceRegistered(new WorkerRtSourceRegisteredDTO('owner', [$collection]));
        $runtime = new DaemonManagerRtSyncPeerTestRtContext();
        $runtime->configure();
        Hilos::$rt = $runtime;
        $rows = [];
        for ($index = 0; $index < 40; ++$index) {
            $rows['s' . $index] = ['id' => 's' . $index, 'name' => str_repeat('x', 1000)];
        }
        RtSnapshot::replace($collection, $rows);
        Hilos::$cluster->registerRtSyncSink($owner);
        $this->take($oldA);
        $donor = $oldA[0]->rtSyncStream();
        if ($stage !== null) {
            $donor->sendSnapshot($collection, RtSnapshotScope::whole(), 'node-a');
            $a->sendSourceInterestAnswered('node-b', 2);
        }
        if ($stage === 1) {
            self::assertTrue($donor->pump(new RtWorkBudget(128, 4096)));
        } elseif ($stage === 2) {
            while ($donor->pump(new RtWorkBudget())) {
            }
        }
        $before = $this->take($oldA);
        self::assertCount($stage === 2 ? 1 : 0, $before);
        if ($stage === 2) {
            self::assertFalse($before[0]->frame->final, 'Only the old snapshot head and first rows went out');
        }
        self::assertTrue($agents->rtNodeSourceMap()->owns($collection));
        $a->broadcastRtSync(SignalTypeConstants::RT_SYNC_UPDATED, new SignalDTO(
            new SignalSource(SignalSource::RT), new SignalType(SignalTypeConstants::RT_SYNC_UPDATED),
            new SignalName(SignalConstants::RT_SYNC_UPDATED), new RtSyncUpdatedSignalData(
                $collection, 's0', ['name' => 'accepted'], 'accept-1', 'request-1', ['name' => 'previous'], 'emitter-1',
            ),
        ), partialOwner: true);
        $answered = false;
        $oldA[0]->afterRtWritesAnswered(static function () use (&$answered): void {
            $answered = true;
        });
        $agents->handleRtSourceReleased(new WorkerRtSourceReleasedDTO('owner'));
        RtSnapshot::replace($collection, []);
        self::assertFalse($agents->rtNodeSourceMap()->owns($collection), 'The accepted delta outlives this node\'s ownership');
        $queue = new SplQueue();
        $binding = static function (Closure $delivery) use ($queue): void {
            $queue->enqueue($delivery(SignalDispatcherFixture::direct()));
        };
        $a->setSourceInterestDelivery($binding);
        $new = $this->connect($a, $b, true, [$collection], []);
        self::assertCount(0, $queue, 'The repeat on the new link waits behind the old link\'s drain marker');
        $this->readOldDrain($oldB, $oldA, $new[0]);
        self::assertCount(1, $queue, 'The repeated interest has not yet recovered the base');
        $closed = [$oldA[0]->getSocket(), $oldB[0]->getSocket()];
        $oldA[0]->close();
        $oldB[0]->close();
        $this->sockets = array_values(array_filter($this->sockets, static fn (Socket $socket): bool => !in_array($socket, $closed, true)));
        $successor = $new[0][0]->rtSyncStream();
        while ($successor->pump(new RtWorkBudget())) {
        }
        $transferred = $this->take($new[0]);
        self::assertCount(1, $transferred, 'The old interest answer and canceled snapshot cannot precede the delta');
        self::assertInstanceOf(PeerRtSyncStreamDTO::class, $transferred[0]);
        self::assertSame([1, 0, true],
            [$transferred[0]->frame->sequence, $transferred[0]->frame->part, $transferred[0]->frame->final]);
        $decoder = new RtSyncStreamDecoder();
        RtWorkDriver::runSynchronously($decoder->feed($transferred[0]->frame->data));
        $operation = RtWorkDriver::runSynchronously(RtSyncStreamOperation::read($decoder->finish()));
        self::assertSame(
            [$collection, 's0', 'node-a', SignalTypeConstants::RT_SYNC_UPDATED, true, 'accept-1', 'request-1', 'emitter-1',
                ['name' => 'accepted'], ['name' => 'previous']],
            [$operation->collectionKey, $operation->stateId, $operation->originNodeId, $operation->signalType, $operation->partialOwner,
                $operation->origin, $operation->originRequestId, $operation->emitter, $operation->row->toNative(),
                $operation->previous?->toNative()],
        );
        $donor->receive(RtSyncStreamFrame::ack($donor->streamId, 1, 0, RtSyncStreamStatus::Applied));
        self::assertFalse($answered, 'Cancellation and old close or answer do not answer the transferred delta');

        $agents->handleRtSourceRegistered(new WorkerRtSourceRegisteredDTO('owner', [$collection]));
        RtSnapshot::replace($collection, ['s0' => ['id' => 's0', 'name' => 'fresh base']]);
        $delivery = $queue->dequeue();
        $cursor = new SignalDispatch(null, static fn(): Generator => $delivery);
        $cursor->runPortion(256);
        self::assertFalse($cursor->hasWork());
        $a->removeSourceInterestDelivery($binding);
        while ($successor->pump(new RtWorkBudget())) {
        }
        $recovered = $this->take($new[0]);
        $snapshots = array_values(array_filter($recovered, static fn (PeerDTO $frame): bool => $frame instanceof PeerRtSyncStreamDTO));
        self::assertCount(1, $snapshots, 'The unchanged interest recovers a snapshot even when only an ordinary write was transferred');
        $snapshot = json_decode($snapshots[0]->frame->data, true);
        self::assertSame(RtSyncStreamSnapshot::SIGNAL_TYPE, $snapshot[RtSyncStreamOperation::FIELD_SIGNAL_TYPE]);
        self::assertSame(['s0' => ['id' => 's0', 'name' => 'fresh base']], $snapshot[RtSyncStreamSnapshot::FIELD_ROWS],
            'Recovery takes a fresh cut rather than reusing the consumed old cursor');
        self::assertEquals([new PeerSourceInterestAnsweredDTO('node-a', 2)], array_values(array_filter(
            $recovered, static fn (PeerDTO $frame): bool => $frame instanceof PeerSourceInterestAnsweredDTO,
        )));
        self::assertFalse($answered, 'A repeated interest answer cannot answer an ordinary write');
        $successor->receive(RtSyncStreamFrame::ack($successor->streamId, 1, 0, RtSyncStreamStatus::Applied));
        self::assertTrue($answered, 'The later recovery snapshot does not extend the transferred delta\'s wait');
    }

    /**
     * The owner's snapshot the reader asked for is still being cut on the old link when the link is
     * replaced, with no answer waiting behind it: its rest is never written there, so the reader's
     * repeat of its announcement on the new link is answered behind a hand-over of everything it names.
     */
    public function testASnapshotTheOldLinkNeverFinishesLeavesTheRepeatAnsweredBehindAHandOver(): void
    {
        $collection = DaemonManagerRtSyncPeerTestRtContext::ROWS;
        $a = $this->server('node-a');
        $b = $this->server('node-b');
        [$oldA, $oldB] = $this->connect($a, $b, false);
        $owner = new ReaderRepairManager();
        new ReflectionProperty(DaemonManager::class, 'servers')->setValue($owner, [$a]);
        $owner->getAgentManagerDaemon()->handleRtSourceRegistered(new WorkerRtSourceRegisteredDTO('owner', [$collection]));
        $runtime = new DaemonManagerRtSyncPeerTestRtContext();
        $runtime->configure();
        Hilos::$rt = $runtime;
        RtSnapshot::replace($collection, ['s0' => ['id' => 's0', 'name' => 'small']]);
        Hilos::$cluster->registerRtSyncSink($owner);
        $a->announceSourceInterest([$collection], [], 2);
        $b->announceSourceInterest([$collection], [], 2);
        $this->take($oldA);
        $this->relay($oldB, $oldA);
        $answers = static fn (array $frames): array => array_values(array_filter(
            $frames,
            static fn (PeerDTO $frame): bool => $frame instanceof PeerSourceInterestAnsweredDTO,
        ));
        self::assertEquals([new PeerSourceInterestAnsweredDTO('node-a', 2)], $answers($this->take($oldA)),
            'The announcement is answered on the old link');

        $name = str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES);
        RtSnapshot::replace($collection, ['s0' => ['id' => 's0', 'name' => $name], 's1' => ['id' => 's1', 'name' => $name]]);
        $oldB[0]->requestRtSnapshot($collection);
        $this->relay($oldB, $oldA);
        $asked = $this->take($oldA);
        $queue = new SplQueue();
        $binding = static function (Closure $delivery) use ($queue): void {
            $queue->enqueue($delivery(SignalDispatcherFixture::direct()));
        };
        $a->setSourceInterestDelivery($binding);
        $new = $this->connect($a, $b, true, [$collection], []);
        self::assertCount(0, $queue, 'The repeat on the new link waits behind the old link\'s drain marker');
        $this->readOldDrain($oldB, $oldA, $new[0]);
        self::assertCount(1, $queue, 'The repeat on the new link waits for its delivery');
        $delivery = $queue->dequeue();
        $cursor = new SignalDispatch(null, static fn(): Generator => $delivery);
        $cursor->runPortion(256);
        $after = $this->take($new[0]);
        $a->removeSourceInterestDelivery($binding);

        self::assertCount(1, $asked);
        self::assertInstanceOf(PeerRtSyncStreamDTO::class, $asked[0]);
        self::assertFalse($asked[0]->frame->final, 'The snapshot asked for waits for its next part');
        self::assertInstanceOf(PeerRtSyncStreamDTO::class, $after[0] ?? null, 'The repeat is answered behind a hand-over');
        $decoder = new RtSyncStreamDecoder();
        RtWorkDriver::runSynchronously($decoder->feed($after[0]->frame->data));
        self::assertSame($collection, RtWorkDriver::runSynchronously(RtSyncStreamSnapshot::read($decoder))->collectionKey);
        self::assertSame([], $answers($after), 'The answer waits behind it, on the new link');
    }

    /**
     * The owner reads the reader's request for one more snapshot on the old link, and its work waits in
     * the owner's FIFO while the link is replaced: the work finds its route gone and cuts nothing, and
     * the reader's repeat of its announcement, queued behind it, is answered behind a hand-over of
     * everything it names on the new link.
     */
    public function testAHandOverThatFoundItsRouteReplacedLeavesTheRepeatAnsweredBehindAHandOver(): void
    {
        $collection = DaemonManagerRtSyncPeerTestRtContext::ROWS;
        $a = $this->server('node-a');
        $b = $this->server('node-b');
        [$oldA, $oldB] = $this->connect($a, $b, false);
        $owner = new ReaderRepairManager();
        new ReflectionProperty(DaemonManager::class, 'servers')->setValue($owner, [$a]);
        $owner->getAgentManagerDaemon()->handleRtSourceRegistered(new WorkerRtSourceRegisteredDTO('owner', [$collection]));
        $runtime = new DaemonManagerRtSyncPeerTestRtContext();
        $runtime->configure();
        Hilos::$rt = $runtime;
        RtSnapshot::replace($collection, ['s0' => ['id' => 's0', 'name' => 'small']]);
        Hilos::$cluster->registerRtSyncSink($owner);
        $loop = new EventLoop();
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($owner, $loop);
        $this->phase($owner, 'attachSignalDispatchEventLoop');
        try {
            $a->announceSourceInterest([$collection], [], 2);
            $b->announceSourceInterest([$collection], [], 2);
            $this->take($oldA);
            $this->relay($oldB, $oldA);
            $this->drainManager($owner);
            $answer = new PeerSourceInterestAnsweredDTO('node-a', 2);
            $answers = static fn (array $frames): array => array_values(array_filter(
                $frames,
                static fn (PeerDTO $frame): bool => $frame instanceof PeerSourceInterestAnsweredDTO,
            ));
            self::assertEquals([$answer], $answers($this->take($oldA)), 'The announcement is answered on the old link');

            $busy = true;
            SignalDispatcherFixture::queue($owner, static function () use (&$busy): Generator {
                // Work of the FIFO ahead of the request's, still going when the link is replaced.
                while ($busy) {
                    yield;
                }
            });
            $oldB[0]->requestRtSnapshot($collection);
            $this->relay($oldB, $oldA);
            self::assertSame([], $this->take($oldA), 'The work of the request waits in the FIFO');
            $new = $this->connect($a, $b, true, [$collection], []);
            // The repeat is read behind the old link's drain marker, still behind the request's work.
            $this->readOldDrain($oldB, $oldA, $new[0]);
            $busy = false;
            $this->drainManager($owner);
            $old = $this->take($oldA);
            $after = $this->take($new[0]);
        } finally {
            $this->phase($owner, 'discardSignalDispatch');
            $loop->close();
        }

        self::assertSame([], array_filter($old, static fn (PeerDTO $frame): bool => $frame instanceof PeerRtSyncStreamDTO),
            'Nothing of the request goes to the old link');
        self::assertInstanceOf(PeerRtSyncStreamDTO::class, $after[0] ?? null, 'The repeat is answered behind a hand-over');
        $decoder = new RtSyncStreamDecoder();
        RtWorkDriver::runSynchronously($decoder->feed($after[0]->frame->data));
        self::assertSame($collection, RtWorkDriver::runSynchronously(RtSyncStreamSnapshot::read($decoder))->collectionKey);
        self::assertEquals([$answer], $answers($after), 'The answer follows it');
    }

    /**
     * The reader's staging of a snapshot that came on the old link is canceled by a write of its area
     * here once the link was replaced: the snapshot is asked for again on the new link - the old one
     * drains and writes nothing more - and the copy awaits the owner once more, under an announcement
     * made behind the request. With no link to the owner left, nothing is asked, and nothing is to come.
     */
    public function testASnapshotCanceledOnceItsLinkWasReplacedIsAskedForOnTheNewLink(): void
    {
        $collection = DaemonManagerRtSyncPeerTestRtContext::ROWS;
        $a = $this->server('node-a');
        $b = $this->server('node-b');
        $woken = [];
        $b->setRtSyncStreamWake(static function (RtSyncStreamChannel $channel) use (&$woken): void {
            // The reader's pass comes once the link is replaced.
            $woken[] = $channel;
        });
        [$oldA, $oldB] = $this->connect($a, $b, false);
        $runtime = new DaemonManagerRtSyncPeerTestRtContext();
        $runtime->configure();
        Hilos::$rt = $runtime;
        RtSnapshot::replace($collection, ['s0' => ['id' => 's0', 'name' => 'Ada'], 's1' => ['id' => 's1', 'name' => 'Grace']]);
        $reader = new ReaderRepairManager();
        new ReflectionProperty(DaemonManager::class, 'servers')->setValue($reader, [$b]);
        $confirmation = $reader->getAgentManagerDaemon()->rtCopyConfirmation();
        $confirmation->awaitPeers(true);
        $confirmation->announce([], []);
        $announcement = $confirmation->announce([$collection], [$collection]);
        $confirmation->addressed($announcement, ['node-a']);
        $a->announceSourceInterest([$collection], [], 2);
        $b->announceSourceInterest([$collection], [], $announcement);
        $this->take($oldA);
        $this->take($oldB);
        $oldA[0]->rtSyncStream()->sendSnapshot($collection, RtSnapshotScope::whole(), 'node-a');
        $this->relay($oldA, $oldB);
        $new = $this->connect($a, $b, true, [$collection], []);
        Hilos::$cluster->registerRtSyncSink($reader);
        $reader->staging = static function () use ($runtime): void {
            $runtime->rows->remove('s1');
        };

        self::assertCount(1, $woken);
        $reader->wakeRtSyncStream($woken[0]);

        self::assertEquals([
            new PeerRtSnapshotRequestDTO('node-b', $collection),
            new PeerSourceInterestDTO('node-b', [$collection], [], $announcement + 1),
        ], $this->take($new[1]), 'Asked for on the new link, and announced behind the request');
        self::assertSame([], array_filter($this->take($oldB), static fn (PeerDTO $frame): bool => $frame instanceof PeerRtSnapshotRequestDTO),
            'The old link asks nothing');
        self::assertTrue($confirmation->isAwaited($collection), 'The copy awaits the owner once more');
        // The new link goes too; its socket is left to the tear-down.
        new ReflectionMethod(PeerLink::class, 'onClose')->invoke($new[1][0]);
        self::assertFalse($oldB[0]->requestRtSnapshotAgain($collection, false), 'Without a link to the owner nothing is to come');
    }

    /** Native completion and real peer filters reproduce the partial operation owner's lost-delta window. */
    public function testMissingQueryWaitsForItsOwnInterestBeforeAFullReplicaFromAPartialOwner(): void
    {
        $a = $this->server('node-a');
        $b = $this->server('node-b');
        [$routeA, $routeB] = $this->connect($a, $b, false);
        $reader = new ReaderRepairManager();
        $owner = new ReaderRepairManager();
        new ReflectionProperty(DaemonManager::class, 'servers')->setValue($reader, [$a]);
        new ReflectionProperty(DaemonManager::class, 'servers')->setValue($owner, [$b]);
        $runtime = new DaemonManagerRtSyncPeerTestRtContext();
        $runtime->configure();
        $remoteRuntime = new DaemonManagerRtSyncPeerTestRtContext();
        $remoteRuntime->configure();
        $collection = DaemonManagerRtSyncPeerTestRtContext::ROWS;
        $owner->getAgentManagerDaemon()->handleRtSourceRegistered(new WorkerRtSourceRegisteredDTO(
            'update-owner', [$collection], [$collection],
        ));
        self::assertTrue($owner->getAgentManagerDaemon()->rtNodeSourceMap()->owns($collection));
        self::assertFalse($owner->getAgentManagerDaemon()->rtNodeSourceMap()->ownsFully($collection));
        Hilos::$rt = $remoteRuntime;
        RtSnapshot::replace($collection, ['x' => ['id' => 'x', 'name' => 'v0']]);
        Hilos::$rt = $runtime;
        $loop = new EventLoop();
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($reader, $loop);
        $this->phase($reader, 'attachSignalDispatchEventLoop');
        $this->phase($reader, 'attachReaderInterestEventLoop');
        try {
            $this->nativeReport($loop, $reader, new WorkerSourceInterestDTO(array_fill(0, 2048, 'old'), ['old-db'], 1));
            $this->phase($reader, 'dispatchSignals');
            $this->nativeReport($loop, $reader, new WorkerSourceInterestDTO(array_fill(0, 2048, $collection), ['future-db'], 1));
            for ($portion = 0; $portion < 4096 && !$reader->getAgentManagerDaemon()->hasChangedReaderInterest(); ++$portion) {
                $this->phase($reader, 'dispatchSignals');
            }
            self::assertTrue($reader->getAgentManagerDaemon()->hasChangedReaderInterest());
            $this->phase($reader, 'dispatchReaderInterest');
            $this->phase($reader, 'dispatchMissingRtCollections');
            $this->drainManager($reader);
            Hilos::$rt = $remoteRuntime;
            Hilos::$cluster->registerRtSyncSink($owner);
            $first = $this->relay($routeA, $routeB);
            Hilos::$rt = $runtime;
            Hilos::$cluster->registerRtSyncSink($reader);
            $earlyOffers = $this->relay($routeB, $routeA);
            $this->drainManager($reader);
            // Between the two completed announcements, a lawful update owner changes its row.
            Hilos::$rt = $remoteRuntime;
            RtSnapshot::replace($collection, ['x' => ['id' => 'x', 'name' => 'v1']]);
            $b->broadcastRtSync(SignalTypeConstants::RT_SYNC_UPDATED, new SignalDTO(
                new SignalSource(SignalSource::RT), new SignalType(SignalTypeConstants::RT_SYNC_UPDATED),
                new SignalName(SignalConstants::RT_SYNC_UPDATED), new RtSyncUpdatedSignalData($collection, 'x', ['name' => 'v1']),
            ), partialOwner: true);
            Hilos::$rt = $runtime;
            self::assertSame([], $this->relay($routeB, $routeA), 'The old interest still filters the delta');
            $this->phase($reader, 'dispatchReaderInterest');
            $this->phase($reader, 'dispatchMissingRtCollections');
            $this->drainManager($reader);
            Hilos::$rt = $remoteRuntime;
            Hilos::$cluster->registerRtSyncSink($owner);
            $second = $this->relay($routeA, $routeB);
            Hilos::$rt = $runtime;
            Hilos::$cluster->registerRtSyncSink($reader);
            $answers = $this->relay($routeB, $routeA);
            $this->drainManager($reader);
            // The copy waited for the partial owner's answer, and no snapshot came with it: an owner
            // short of an operation hands nothing over, so the holders are asked once it is confirmed.
            Hilos::$rt = $remoteRuntime;
            Hilos::$cluster->registerRtSyncSink($owner);
            $query = $this->relay($routeA, $routeB);
            Hilos::$rt = $runtime;
            Hilos::$cluster->registerRtSyncSink($reader);
            $offers = $this->relay($routeB, $routeA);
            $this->drainManager($reader);
            self::assertSame(['x' => ['id' => 'x', 'name' => 'v1']], RtSnapshot::rows($collection),
                'The query after the fresh interest must obtain the update that the old interest filtered');
            self::assertEquals([new PeerSourceInterestDTO('node-a', ['old'], ['old-db'], 1)], $first,
                'A collection whose copy waits for the answer is not asked about on the move');
            $claims = new PeerRtClaimsDTO('node-b', [
                new PeerRtClaimEntry('update-owner', 'update-owner', null, [$collection], [$collection]),
            ]);
            self::assertEquals([new PeerSourceInterestAnsweredDTO('node-b', 1), $claims], $earlyOffers,
                'The partial owner answers, and announces its claim without a future-scope row');
            self::assertEquals([
                new PeerRtSnapshotQueryDTO('node-a', ['old']),
                new PeerSourceInterestDTO('node-a', [$collection], ['future-db'], 2),
            ], $second);
            self::assertEquals([new PeerSourceInterestAnsweredDTO('node-b', 2), $claims], $answers,
                'Its automatic whole hand-over remains empty');
            self::assertEquals([new PeerRtSnapshotQueryDTO('node-a', [$collection])], $query,
                'The query waits for its own interest and for the answer to it');
            self::assertEquals([new PeerRtReplicaOfferDTO('node-b', $collection, ['x' => ['id' => 'x', 'name' => 'v1']])], $offers,
                'The partial owner answers the query');
            $map = new ReflectionProperty(PeerServer::class, 'nodeReaderMap')->getValue($b);
            $this->assertReport($map, 'node-a', [$collection], ['future-db']);
            $update = new SignalDTO(
                new SignalSource(SignalSource::RT), new SignalType(SignalTypeConstants::RT_SYNC_UPDATED),
                new SignalName(SignalConstants::RT_SYNC_UPDATED), new RtSyncUpdatedSignalData($collection, 'x', ['name' => 'v2']),
            );
            $b->broadcastRtSync(SignalTypeConstants::RT_SYNC_UPDATED, $update, partialOwner: true);
            $relayed = $this->relay($routeB, $routeA);
            self::assertCount(1, $relayed);
            self::assertInstanceOf(PeerRtSyncStreamDTO::class, $relayed[0]);
            self::assertSame([1, 0, true], [$relayed[0]->frame->sequence, $relayed[0]->frame->part, $relayed[0]->frame->final]);
            $decoder = new RtSyncStreamDecoder();
            RtWorkDriver::runSynchronously($decoder->feed($relayed[0]->frame->data));
            $operation = RtWorkDriver::runSynchronously(RtSyncStreamOperation::read($decoder->finish()));
            self::assertSame(
                ['node-b', SignalTypeConstants::RT_SYNC_UPDATED, true, 'x', ['name' => 'v2'], []],
                [$operation->originNodeId, $operation->signalType, $operation->partialOwner, $operation->stateId,
                    $operation->row->toNative(), $operation->previous?->toNative()],
            );
            $this->drainManager($reader);
            self::assertSame(['x' => ['id' => 'x', 'name' => 'v2']], RtSnapshot::rows($collection));
            self::assertFalse($this->phase($reader, 'hasRunnableReaderInterest'));
        } finally {
            $this->phase($reader, 'detachReaderInterestEventLoop');
            $this->phase($reader, 'discardSignalDispatch');
            $loop->close();
        }
    }

    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        $this->previousCluster = Hilos::$cluster;
        $this->previousRuntime = Hilos::$rt;
        $this->previousRouter = Hilos::$sr;
        foreach (['CLUSTER_ENABLED' => 'true', 'CLUSTER_NODE_ID' => 'local', 'CLUSTER_NODE_NAME' => 'Local',
            'CLUSTER_NODE_ROLE' => 'master', 'SOCKET_READ_BUFFER_SIZE' => '65536'] as $key => $value) {
            $this->previousValues[$key] = getenv($key);
            putenv($key . '=' . $value);
        }
        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = new ClusterContext();
    }

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        Hilos::$env = $this->previousEnv;
        Hilos::$cluster = $this->previousCluster;
        Hilos::$rt = $this->previousRuntime;
        Hilos::$sr = $this->previousRouter;
        foreach ($this->previousValues as $key => $value) {
            putenv($key . ($value === false ? '' : '=' . $value));
        }
        parent::tearDown();
    }

    /**
     * @param string $nodeId Local endpoint
     * @return PeerServer Real server with the usual marker and identity validation
     */
    private function server(string $nodeId): PeerServer
    {
        return new PeerServer('127.0.0.1', 0, NodeIdentity::of($nodeId, $nodeId, NodeRole::Master, []), [],
            PeerTestTls::unread(), PeerTestMarkers::shared());
    }

    /**
     * @param PeerServer $a First endpoint
     * @param PeerServer $b Second endpoint
     * @param bool $replacement Whether the preferred A-dialed duplicate replaces B's stream
     * @param list<string> $rt Last complete writer RT values
     * @param list<string> $db Last complete writer DB values
     * @param list<PeerDTO> $migrated Exact frames transferred from the outgoing endpoint
     * @return array{array{PeerLink, Socket}, array{PeerLink, Socket}} Independent sockets, relayed byte for byte
     */
    private function connect(PeerServer $a, PeerServer $b, bool $replacement, array $rt = [], array $db = [], array $migrated = []): array
    {
        $first = $this->link($a, 'node-a', 'node-b', $replacement);
        $second = $this->link($b, 'node-b', 'node-a', !$replacement);
        [$dialer, $acceptor] = $replacement ? [$first, $second] : [$second, $first];
        $dialer[0]->startHandshake();
        $hello = $this->relay($dialer, $acceptor);
        self::assertCount(1, $hello);
        self::assertInstanceOf(PeerHelloDTO::class, $hello[0]);
        $welcome = $this->relay($acceptor, $dialer);
        self::assertCount(1, $welcome, 'No repair frame may precede ready');
        self::assertInstanceOf(PeerWelcomeDTO::class, $welcome[0]);
        self::assertEquals([new PeerReadyDTO($replacement)], $this->relay($dialer, $acceptor));
        $ack = $this->relay($acceptor, $dialer);
        self::assertEquals(new PeerReadyAckDTO(true), $ack[0]);
        if ($replacement) {
            self::assertEquals([new PeerReadyAckDTO(true), new PeerSourceInterestDTO('node-b', $rt, $db, 2)], $ack,
                'Only the fresh complete repair may follow the ack, without roster or full hand-over');
            self::assertEquals([...$migrated, new PeerSourceInterestDTO('node-a', $rt, $db, 2)], $this->relay($dialer, $acceptor),
                'The outgoing endpoint transfers its waiting frames and repairs only after receiving the ack');
        } else {
            $this->relay($dialer, $acceptor);
        }
        return [$first, $second];
    }

    /**
     * @param PeerServer $server Real owner
     * @param string $local Certified local identity
     * @param string $remote Certified remote identity
     * @param bool $dialer Protocol direction
     * @return array{PeerLink, Socket} Endpoint and relay socket
     */
    private function link(PeerServer $server, string $local, string $remote, bool $dialer): array
    {
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        foreach ($pair as $socket) {
            socket_set_nonblock($socket);
            $this->sockets[] = $socket;
        }
        $link = new PeerLink($pair[0], $server, NodeIdentity::of($local, $local, NodeRole::Master, []), dialer: $dialer,
            transport: new NamedPeerTestTransport($pair[0], $remote));
        new ReflectionMethod(PeerServer::class, 'addClient')->invoke($server, $link);
        new ReflectionMethod(PeerServer::class, 'attachClient')->invoke($server, $link);
        return [$link, $pair[1]];
    }

    /**
     * @param array{PeerLink, Socket} $from Sending endpoint
     * @param array{PeerLink, Socket} $to Receiving endpoint
     * @return list<PeerDTO> Complete decoded frames, with the same bytes delivered to the actual parser
     */
    private function relay(array $from, array $to): array
    {
        $from[0]->write();
        $bytes = socket_read($from[1], 65536);
        if ($bytes === false) {
            return [];
        }
        self::assertSame(strlen($bytes), socket_write($to[1], $bytes));
        $to[0]->read();
        return $this->decode($bytes);
    }

    /**
     * @param array{PeerLink, Socket} $from Sending endpoint
     * @return list<PeerDTO> Entire decoded output, without a consumer applying the snapshot
     */
    private function take(array $from): array
    {
        $from[0]->write();
        $bytes = socket_read($from[1], 65536);
        return $bytes === false ? [] : $this->decode($bytes);
    }

    /**
     * Relays what the peer's old endpoint still wrote, its drain marker last, which lets this node's
     * replacement parse the frames that waited behind its old endpoint's inbound.
     *
     * @param array{PeerLink, Socket} $oldPeer The peer's old endpoint
     * @param array{PeerLink, Socket} $old This node's old endpoint
     * @param array{PeerLink, Socket} $new This node's replacement endpoint
     */
    private function readOldDrain(array $oldPeer, array $old, array $new): void
    {
        $read = $this->relay($oldPeer, $old);
        self::assertInstanceOf(PeerDrainDTO::class, $read[array_key_last($read)] ?? null, 'The old endpoint ends with the drain marker');
        $new[0]->read();
    }

    /**
     * @param string $bytes Complete newline-delimited protocol bytes
     * @return list<PeerDTO> Every frame, in exact order
     */
    private function decode(string $bytes): array
    {
        return array_map(PeerDTO::fromWire(...), explode("\n", rtrim($bytes, "\n")));
    }

    /**
     * @param SourceReaderMap $map Actual endpoint reader ledger
     * @param string $holder Certified reporter
     * @param list<string> $rt Expected complete runtime half
     * @param list<string> $db Expected complete database half
     */
    private function assertReport(SourceReaderMap $map, string $holder, array $rt, array $db): void
    {
        self::assertSame($rt, $map->collections(SourceChange::KIND_RT));
        self::assertSame($db, $map->collections(SourceChange::KIND_DB));
        foreach ($rt as $key) {
            self::assertTrue($map->holds($holder, SourceChange::KIND_RT, $key));
        }
        foreach ($db as $key) {
            self::assertTrue($map->holds($holder, SourceChange::KIND_DB, $key));
        }
    }

    /**
     * @param EventLoop $loop Actual native callback owner
     * @param ReaderRepairManager $manager Actual source FIFO owner
     * @param WorkerSourceInterestDTO $report Complete worker report
     */
    private function nativeReport(EventLoop $loop, ReaderRepairManager $manager, WorkerSourceInterestDTO $report): void
    {
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        $loop->registerRead($pair[0], static function () use ($manager, $report, $pair): void {
            socket_read($pair[0], 1);
            $manager->getAgentManagerDaemon()->handleSourceInterest($report, 3);
        });
        try {
            socket_write($pair[1], 'x');
            $loop->runOnce();
        } finally {
            $loop->unregister($pair[0]);
            socket_close($pair[0]);
            socket_close($pair[1]);
        }
    }

    /** @param ReaderRepairManager $manager Actual source FIFO owner */
    private function drainManager(ReaderRepairManager $manager): void
    {
        for ($portion = 0; $portion < 4096 && $this->phase($manager, 'hasRunnableSignalDispatch'); ++$portion) {
            $this->phase($manager, 'dispatchSignals');
        }
        self::assertFalse($this->phase($manager, 'hasRunnableSignalDispatch'));
    }

    /**
     * @param ReaderRepairManager $manager Actual guarded master
     * @param string $method Existing phase
     * @return mixed Phase outcome
     */
    private function phase(ReaderRepairManager $manager, string $method): mixed
    {
        return new ReflectionMethod(DaemonManager::class, $method)->invoke($manager);
    }
}

/** Uses the real transport and all inherited source/snapshot phases. */
final class ReaderRepairManager extends DaemonManager
{
    /** @var ?Closure(): void What happens on this node once, as a staging asks for its first work on a snapshot's rows */
    public ?Closure $staging = null;

    /**
     * Stages a snapshot as the master does, letting {@see $staging} happen once meanwhile.
     *
     * @param string $originNodeId Id of the node at the other end of the link
     * @param RtSyncStreamSnapshot $snapshot Snapshot at its head
     * @param Closure(bool): void $settled The stream's answer
     * @param Closure(): void $recover Lifts the link's holds of the collection
     * @param Closure(bool): bool $askAgain Asks the node for another snapshot
     * @param ?SignalDispatch $origin Causal operation, or null for an independent fact
     * @return Generator<int, RtWork, int, void> The staging
     */
    public function stageRemoteRtSnapshot(
        string $originNodeId,
        RtSyncStreamSnapshot $snapshot,
        Closure $settled,
        Closure $recover,
        Closure $askAgain,
        ?SignalDispatch $origin = null,
    ): Generator {
        $staging = parent::stageRemoteRtSnapshot($originNodeId, $snapshot, $settled, $recover, $askAgain, $origin);
        $act = $this->staging;
        while ($staging->valid()) {
            $work = $staging->current();
            if ($act !== null && $work instanceof RtWork && $work->bytes > 0) {
                $act();
                $act = null;
            }
            $staging->send(yield $work);
        }
    }

    /** @return SignalRouter Ordinary source router */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /** @return AgentManagerDaemon Existing inert worker-report fixture */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new ReaderNativeAgents();
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Hilos\Tests\Fixtures\SignalDispatcherFixture;
use Hilos\Core\Daemon\SignalDispatch;
use Closure;
use Error;
use Generator;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\DTO\PeerAgentRestartDTO;
use Hilos\Cluster\Peer\DTO\PeerAgentStatusDTO;
use Hilos\Cluster\Peer\DTO\PeerCommandReplyDTO;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerDrainDTO;
use Hilos\Cluster\Peer\DTO\PeerHeartbeatDTO;
use Hilos\Cluster\Peer\DTO\PeerHelloDTO;
use Hilos\Cluster\Peer\DTO\PeerPingDTO;
use Hilos\Cluster\Peer\DTO\PeerPlaceAgentDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementReportDTO;
use Hilos\Cluster\Peer\DTO\PeerReadyAckDTO;
use Hilos\Cluster\Peer\DTO\PeerReadyDTO;
use Hilos\Cluster\Peer\DTO\PeerRtClaimEntry;
use Hilos\Cluster\Peer\DTO\PeerRtClaimsDTO;
use Hilos\Cluster\Peer\DTO\PeerRtSyncStreamDTO;
use Hilos\Cluster\Peer\DTO\PeerSignalDTO;
use Hilos\Cluster\Peer\DTO\PeerSourceInterestDTO;
use Hilos\Cluster\Peer\DTO\PeerStopAgentDTO;
use Hilos\Cluster\Peer\DTO\PeerWelcomeDTO;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\PageAgentRestarts;
use Hilos\Core\Daemon\PageAgentRestartWaiter;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\Exception\EventLoopException;
use Hilos\Core\Page\DTO\PageAccessReassessConnectionsSignalData;
use Hilos\Core\Page\DTO\PageAccessReassessSessionSignalData;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalNameInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalSourceInterface;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\SignalTypeInterface;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Sync\DTO\RtSyncCreatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\DaemonSection\DTO\DaemonAgentRestartRequestSignalData;
use Hilos\Hilos;
use Hilos\Runtime\Ordinary\RtOrdinaryAdmission;
use Hilos\Runtime\Row\RtRowArena;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\Snapshot\RtWorkBudget;
use Hilos\Runtime\Stream\RtSyncStreamChannel;
use Hilos\Runtime\Stream\RtSyncStreamFrame;
use Hilos\Runtime\Stream\RtSyncStreamOperation;
use Hilos\Runtime\Stream\RtSyncStreamSettle;
use Hilos\Runtime\Stream\RtSyncStreamSnapshot;
use Hilos\Runtime\Stream\RtSyncStreamStatus;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Transport\PlainSocketTransport;
use Hilos\Socket\WebSocket\DTO\HandshakeResponseSignalData;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\DTO\AgentStopDTO;
use Hilos\Socket\Worker\DTO\DbReHydrateCompleteDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\SystemSignalDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartedDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStoppedDTO;
use Hilos\Socket\Worker\DTO\WorkerPageAccessReassessConnectionsMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRegisterDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSourceRegisteredDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSnapshotRequestDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSourceReleasedDTO;
use Hilos\Socket\Worker\DTO\WorkerRtStalenessMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerRtSyncStreamDTO;
use Hilos\Socket\Worker\DTO\WorkerSourceInterestDTO;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\Tests\Unit\Cluster\Peer\NamedPeerTestTransport;
use Hilos\Tests\Unit\Cluster\Peer\PeerTestMarkers;
use Hilos\Tests\Unit\Cluster\Peer\PeerTestTls;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Socket;
use WeakReference;

/**
 * The ordinary RT stream over real socket pairs into a real master: the worker frames are parsed
 * by the worker factory on both ends and the master's own WorkerClient, router FIFO and dispatch
 * carry the operation on; the peer frames cross the peer factory into the real peer receive door
 * and the master's remote apply. The case moves every part and every pass, and asserts the
 * position after each move. Nothing here touches the database, so no rollback harness is opened.
 */
final class RtSyncStreamTransportTest extends TestCase
{
    private const string COLLECTION = 'streamRows';

    /** Agent on the worker whose link a case closes, the one owner of the collection on this node */
    private const string WRITER = 'stream_writer';

    private ?SignalRouter $previousRouter;
    private ?ClusterContext $previousCluster;
    private ?RtContext $previousRt;
    /** @var list<Socket> */
    private array $sockets = [];
    private RtSyncStreamTransportTestManager $master;
    private RtSyncStreamTransportTestWorker $reader;
    private WorkerClient $readerLink;

    protected function setUp(): void
    {
        $this->previousRouter = Hilos::$sr;
        $this->previousCluster = Hilos::$cluster;
        $this->previousRt = Hilos::$rt;
        Hilos::$cluster = null;
        Hilos::$rt = null;
        RtOrdinaryAdmission::reset();
        $this->master = new RtSyncStreamTransportTestManager();
        [$this->reader, $this->readerLink] = $this->link(2);
        $this->reader->send(new WorkerSourceInterestDTO([self::COLLECTION], [], 1));
        $this->readerLink->read();
        // The answer is a snapshot of the collection in the link's stream; the reader takes it before the case.
        $this->master->drain();
        $this->deliverToReader();
        $this->assertSame(['snapshot ' . self::COLLECTION], $this->reader->applied);
        $this->reader->applied = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        RtOrdinaryAdmission::reset();
        // Process-wide, so the rows one case froze would otherwise be frozen for the next.
        RtStaleness::reset();
        Hilos::$sr = $this->previousRouter;
        Hilos::$cluster = $this->previousCluster;
        Hilos::$rt = $this->previousRt;
    }

    /** A worker's large update crosses the master to a reading worker; applied comes only after the master queued it on. */
    public function testSourceWorkerUpdateCrossesTheMasterAndIsAnsweredOnlyAfterItsDeliveryIsQueued(): void
    {
        [$source, $sourceLink] = $this->link(1);
        $row = ['blob' => str_repeat("part \"of\" a row\n", 3000), 'count' => 3];
        $previous = ['blob' => 'before', 'count' => 2];
        $source->stream->send(new RtSyncUpdatedSignalData(
            self::COLLECTION, 'row-1', $row, 'accept-1', 'request-1', $previous, emitter: 'emitter-w1',
        ));

        $this->assertCount(1, $source->written, 'One part is out before the master answers');
        while (!end($source->written)->final) {
            $sourceLink->read();
            $this->assertFalse(Hilos::$sr->hasQueuedSignals(), 'Nothing is queued before the operation is whole');
            $sourceLink->write();
            $before = count($source->written);
            $source->read();
            $this->assertSame(RtSyncStreamStatus::Next, end($source->answers)->status);
            $this->assertCount($before + 1, $source->written, 'Each next lets exactly one more part out');
        }
        $this->assertGreaterThanOrEqual(3, count($source->written));
        $sourceLink->read();
        $this->assertTrue(Hilos::$sr->hasQueuedSignals(), 'The whole operation joins the router FIFO');
        $sourceLink->write();
        $answers = count($source->answers);
        $source->read();
        $this->assertCount($answers, $source->answers, 'Taking the final part is no answer');

        $source->stream->receive(RtSyncStreamFrame::ack($source->stream->streamId, 9, 0, RtSyncStreamStatus::Applied));
        $source->stream->receive(RtSyncStreamFrame::ack($source->stream->streamId, 1, 0, RtSyncStreamStatus::Applied));
        $this->assertFalse($source->stream->isIdle(), 'An answer to another position applies nothing');

        $this->master->drain();
        $this->assertTrue($this->readerLink->hasPendingWrite(), 'The reading worker\'s stream took the operation');
        $sourceLink->write();
        $source->read();
        $this->assertSame(RtSyncStreamStatus::Applied, end($source->answers)->status);
        $this->assertTrue($source->stream->isIdle());

        $this->deliverToReader();
        $this->assertCount(1, $this->reader->delivered);
        $operation = $this->reader->delivered[0];
        $data = $operation->signalData();
        $this->assertInstanceOf(RtSyncUpdatedSignalData::class, $data);
        $this->assertSame([$row, $previous, 'accept-1', 'request-1', 'emitter-w1', null], [$data->row, $data->previous, $data->origin,
            $data->originRequestId, $data->emitter, $operation->originNodeId]);
        $this->assertTrue($this->readerLink->rtSyncStream()->isIdle(), 'The reading worker answered applied');

        $stale = $this->staleFrame();
        $source->writeRaw(new WorkerRtSyncStreamDTO($stale));
        $sourceLink->read();
        $this->assertFalse(Hilos::$sr->hasQueuedSignals(), 'A part of another generation is not read');
        $this->assertFalse($sourceLink->hasPendingWrite(), 'and is not answered');
    }

    /**
     * A snapshot the master puts into a worker's stream stands behind the writes the stream took before
     * it, over the socket pair as on the stream alone: the snapshot a reader asks for while a write too
     * long for one part is still on its way goes out only behind the write's last part, where a frame
     * written to the socket directly would have gone between its parts. A request for a collection the
     * worker does not read is not answered.
     */
    public function testAnAskedForSnapshotReachesTheWorkerBehindTheLastPartOfTheWriteTakenBeforeIt(): void
    {
        $this->readerLink->rtSyncStream()->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1',
            ['blob' => str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES)]));
        $this->master->drain();
        $this->reader->send(new WorkerRtSnapshotRequestDTO(self::COLLECTION));
        $this->reader->send(new WorkerRtSnapshotRequestDTO('notReadThere'));
        $this->readerLink->read();
        $this->master->drain();
        $this->reader->received = [];
        $this->readerLink->write();
        $this->reader->read();
        $this->assertSame([WorkerRtSyncStreamDTO::class], $this->reader->received,
            'Only the write\'s first part is out: the snapshot waits behind its last');

        for ($round = 0; $round < 64 && count($this->reader->applied) < 2; $round++) {
            $this->readerLink->read();
            $this->master->drain();
            $this->readerLink->write();
            $this->reader->read();
        }

        $this->assertSame(['write row-1', 'snapshot ' . self::COLLECTION], $this->reader->applied);
        $this->readerLink->read();
        $this->assertTrue($this->readerLink->rtSyncStream()->isIdle(), 'The reader answered both, and nothing else was sent');
    }

    /**
     * The master's dispatch rounds advance the process arena's shared retirement on their share:
     * a release no write door retired itself keeps the dispatch cued until the rounds end it. It
     * cues the pass wake, not the signal predicate: a walk of the dispatch until no signal is
     * runnable retires nothing, so with the backlog in that predicate it would never end.
     */
    public function testDispatchRoundsRetireTheArenasBacklogOnTheirShare(): void
    {
        $loop = new EventLoop();
        try {
            new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->master, $loop);
            new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->master);
            $arena = RtRowArena::process();
            RtRowValues::fromNative(array_fill(0, 2000, str_repeat('backlog ', 40)))->release();
            $this->assertTrue($arena->hasPendingRetirement());
            $this->assertTrue($loop->hasReadyWork(), 'Pending retirement keeps the dispatch cued');

            $signals = new ReflectionMethod(DaemonManager::class, 'dispatchSignals');
            $runnable = new ReflectionMethod(DaemonManager::class, 'hasRunnableSignalDispatch');
            $walks = 0;
            do {
                $signals->invoke($this->master);
                $this->assertLessThan(1000, ++$walks, 'A walk until no signal is runnable ends under a backlog');
            } while ($runnable->invoke($this->master));
            $this->assertTrue($arena->hasPendingRetirement(), 'The signal walk retires nothing');

            $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignalsWithinBudget');
            $passes = 0;
            while ($arena->hasPendingRetirement()) {
                $dispatch->invoke($this->master);
                $this->assertTrue(!$arena->hasPendingRetirement() || $loop->hasReadyWork(),
                    'A remainder the deadline leaves keeps the dispatch cued');
                $this->assertLessThan(1000, ++$passes, 'The dispatch rounds retire the backlog');
            }
        } finally {
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($this->master);
            $loop->close();
        }
    }

    /**
     * With the master's loop attached, the stream is pumped on its RT share: a dispatch pass that
     * still has time opens a fresh share each round, so it carries more than one ordinary share of
     * streamed operations instead of idling until the next pass. The pass bound is the measure.
     */
    public function testADispatchPassWithTimeLeftCarriesMoreThanOneShareOfStreamedOperations(): void
    {
        $loop = new EventLoop();
        try {
            new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->master, $loop);
            new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->master);
            [$source, $sourceLink] = $this->link(1);
            $row = [];
            for ($field = 1; $field <= 48; $field++) {
                $row['field' . $field] = $field;
            }
            $operations = 8;
            for ($id = 1; $id <= $operations; $id++) {
                $source->stream->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-' . $id, $row));
            }
            $this->assertCount($operations, $source->written, 'Small operations go out without waiting for answers');
            $sourceLink->read();

            $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignalsWithinBudget');
            $passes = 0;
            while (count($source->answers) < $operations && $passes < 64) {
                $dispatch->invoke($this->master);
                ++$passes;
                $sourceLink->write();
                $source->read();
                $this->deliverToReader();
            }

            $this->assertSame(array_fill(0, $operations, RtSyncStreamStatus::Applied),
                array_map(static fn (RtSyncStreamFrame $answer) => $answer->status, $source->answers),
                'Every operation is answered within the bounded passes; one share per pass answered none of them');
        } finally {
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($this->master);
            $loop->close();
        }
    }

    /**
     * A worker's RT write and the signal it sends right after reach a reading worker in that order,
     * also when the master's effect queue is busy: the link parses nothing behind the streamed part
     * until the master has read it, so the write joins the router FIFO ahead of the signal.
     */
    public function testAStreamedWriteStaysAheadOfTheWorkersNextSignalOnABusyMaster(): void
    {
        $loop = new EventLoop();
        try {
            new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->master, $loop);
            new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->master);
            [$source, $sourceLink] = $this->link(1);
            $this->reader->received = [];
            $busy = 0;
            SignalDispatcherFixture::queue($this->master, static function () use (&$busy): Generator {
                for (; $busy < 256; $busy++) {
                    yield;
                }
            });
            $this->assertLessThan(256, $busy, 'An older effect is still queued');

            $source->stream->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1', ['owner' => null]));
            $source->send(new WorkerPageAccessReassessConnectionsMessageDTO(['accept-1']));
            $sourceLink->read();
            $this->assertFalse(Hilos::$sr->hasQueuedSignals(), 'The signal waits behind the part the master has not read');

            $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignalsWithinBudget');
            for ($pass = 0; $pass < 64 && count($this->reader->received) < 2; $pass++) {
                $dispatch->invoke($this->master);
                $sourceLink->read();
                $this->deliverToReader();
            }

            $this->assertSame([WorkerRtSyncStreamDTO::class, WorkerPageAccessReassessConnectionsMessageDTO::class], $this->reader->received,
                'The reading worker gets the write before the signal sent after it');
        } finally {
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($this->master);
            $loop->close();
        }
    }

    /**
     * A worker's word that an agent's work is done waits on the master for a part it has not read, as a
     * signal does: a command reply and the report of a stopped agent the worker wrote behind the part,
     * and behind the access re-decisions - by connection and by session - that wait for it, are handled
     * only once the master has read the write - the master starts the agent elsewhere on the report. The report of the same agent's start,
     * written behind its stop, waits behind it; the re-decision reaches the reading worker behind the write.
     */
    public function testACommandReplyAndAStopReportWaitForAPartTheMasterHasNotRead(): void
    {
        $loop = new EventLoop();
        try {
            new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->master, $loop);
            new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->master);
            [$source, $sourceLink] = $this->link(1);
            $agents = $this->master->getAgentManagerDaemon();
            $agent = new RtSyncStreamTransportTestAgent();
            $agent->setWorkerClient($sourceLink);
            $agents->addAgent(RtSyncStreamTransportTestAgent::AGENT_TYPE, $agent, 1, false);
            $agents->handleAgentStarted(new WorkerAgentStartedDTO(RtSyncStreamTransportTestAgent::AGENT_TYPE,
                RtSyncStreamTransportTestAgent::AGENT_TYPE));
            $this->reader->received = [];
            $busy = 0;
            SignalDispatcherFixture::queue($this->master, static function () use (&$busy): Generator {
                for (; $busy < 256; $busy++) {
                    yield;
                }
            });

            $source->stream->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1', ['owner' => null]));
            $source->send(new WorkerPageAccessReassessConnectionsMessageDTO(['accept-1']));
            $source->send(new WorkerAgentMessageDTO(null, new SignalDTO(new SignalSource(SignalSource::DAEMON),
                new SignalType(SignalTypeConstants::PAGE_ACCESS_REASSESS_SESSION), new SignalName(SignalConstants::PAGE_ACCESS_REASSESS_SESSION),
                new PageAccessReassessSessionSignalData(hash('sha256', 'session-1')))));
            $source->send(new WorkerAgentMessageDTO(RtSyncStreamTransportTestAgent::AGENT_TYPE, new SignalDTO(
                new SignalSource(SignalSource::AGENT, RtSyncStreamTransportTestAgent::AGENT_TYPE),
                new SignalType(SignalTypeConstants::COMMAND_REPLY), new SignalName('cid-1'), CommandReplyDTO::ok('cid-1'),
            )));
            $source->send(new WorkerAgentStoppedDTO(RtSyncStreamTransportTestAgent::AGENT_TYPE));
            $source->send(new WorkerAgentStartedDTO(RtSyncStreamTransportTestAgent::AGENT_TYPE, RtSyncStreamTransportTestAgent::AGENT_TYPE));
            $agent->heard = [];
            $sourceLink->read();

            $this->assertTrue($sourceLink->rtSyncStream()->isReading(), 'The master has not read the part yet');
            $this->assertTrue($agents->hasAgent(RtSyncStreamTransportTestAgent::AGENT_TYPE), 'The stop report waits behind the part');
            $this->assertSame([], $agent->heard, 'Neither the stop nor the start written behind it is handled yet');
            $this->assertFalse(Hilos::$sr->hasQueuedSignals(), 'The re-decisions and the reply wait behind the part the master has not read');

            $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignalsWithinBudget');
            $replyQueued = false;
            for ($pass = 0; $pass < 64 && count($this->reader->received) < 2; $pass++) {
                $dispatch->invoke($this->master);
                $sourceLink->read();
                $replyQueued = $replyQueued || $this->routerHolds(SignalTypeConstants::COMMAND_REPLY);
                $this->deliverToReader();
            }

            $this->assertTrue($replyQueued, 'The reply is queued once the master has read the part');
            $this->assertSame([WorkerRtSyncStreamDTO::class, WorkerPageAccessReassessConnectionsMessageDTO::class], $this->reader->received,
                'The reading worker gets the write before the re-decision sent after it');
            $this->assertFalse($agents->hasAgent(RtSyncStreamTransportTestAgent::AGENT_TYPE), 'The stop report is handled');
            $this->assertSame([RtSyncStreamTransportTestAgent::STOPPED], $agent->heard,
                'The stop is handled once the master has read the write, and the start written behind it after the stop');
        } finally {
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($this->master);
            $loop->close();
        }
    }

    /**
     * The release of what a stopped agent owned in RT, which the worker wrote behind the part of the
     * agent's write, waits for that part like the report of its stop: the master keeps the agent's claim
     * while it has not read the part, and lets it go only once its dispatch has handed the write on - so
     * the agent's last write is still this node's to announce.
     */
    public function testAReleaseOfAnAgentsRtSourcesWaitsForAPartTheMasterHasNotRead(): void
    {
        $loop = new EventLoop();
        try {
            new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->master, $loop);
            new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->master);
            [$source, $sourceLink] = $this->link(1);
            $agents = $this->master->getAgentManagerDaemon();
            $agents->handleRtSourceRegistered(new WorkerRtSourceRegisteredDTO(RtSyncStreamTransportTestAgent::AGENT_TYPE,
                [self::COLLECTION]));
            $this->reader->received = [];
            $busy = 0;
            SignalDispatcherFixture::queue($this->master, static function () use (&$busy): Generator {
                for (; $busy < 256; $busy++) {
                    yield;
                }
            });

            $source->stream->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1', ['owner' => null]));
            $source->send(new WorkerRtSourceReleasedDTO(RtSyncStreamTransportTestAgent::AGENT_TYPE));
            $sourceLink->read();

            $this->assertTrue($sourceLink->rtSyncStream()->isReading(), 'The master has not read the part yet');
            $this->assertTrue($agents->rtNodeSourceMap()->owns(self::COLLECTION), 'The release waits behind the part');

            $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignalsWithinBudget');
            for ($pass = 0; $pass < 64 && $this->reader->received === []; $pass++) {
                $dispatch->invoke($this->master);
                $sourceLink->read();
                $this->deliverToReader();
            }

            $this->assertSame([WorkerRtSyncStreamDTO::class], $this->reader->received, 'The write is handed on');
            $this->assertFalse($agents->rtNodeSourceMap()->owns(self::COLLECTION), 'The claim goes behind the write');
        } finally {
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($this->master);
            $loop->close();
        }
    }

    /**
     * The response to a browser's handshake, which the worker wrote behind the part of the write of the
     * socket's connection row, waits on the master for that part as a signal does: the browser joins its
     * groups on the response, and the worker of a group judges the socket by its copy of the row. The master
     * queues the response behind the write, which its dispatch hands on to the reading worker ahead of it;
     * the rotation ticket the worker wrote to the same socket behind the response waits behind it, and an
     * answer to another socket is queued at once.
     */
    public function testAHandshakeResponseWaitsForAPartTheMasterHasNotReadAndTheSocketsTicketBehindIt(): void
    {
        $loop = new EventLoop();
        try {
            new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->master, $loop);
            new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->master);
            [$source, $sourceLink] = $this->link(1);
            $this->reader->received = [];
            $busy = 0;
            SignalDispatcherFixture::queue($this->master, static function () use (&$busy): Generator {
                for (; $busy < 256; $busy++) {
                    yield;
                }
            });

            $source->stream->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1', ['owner' => null]));
            $source->send($this->answerSocket('handshake', 'accept-1', new HandshakeResponseSignalData()));
            $source->send($this->answerSocket('ticket', 'accept-1', new SystemSignalDTO('ticket')));
            $source->send($this->answerSocket('answer', 'accept-2', new SystemSignalDTO('answer')));
            $sourceLink->read();

            $this->assertTrue($sourceLink->rtSyncStream()->isReading(), 'The master has not read the part yet');
            $this->assertSame(['answer accept-2'], $this->master->router->queued, 'Only the answer to the other socket is queued');

            $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignalsWithinBudget');
            for ($pass = 0; $pass < 64 && $this->reader->received === []; $pass++) {
                $dispatch->invoke($this->master);
                $sourceLink->read();
                $this->deliverToReader();
            }

            $this->assertSame([WorkerRtSyncStreamDTO::class], $this->reader->received, 'The write is handed on');
            $this->assertSame(['answer accept-2', SignalTypeConstants::RT_SYNC_CREATED, 'handshake accept-1', 'ticket accept-1'],
                $this->master->router->queued, 'The response and the ticket behind it are queued behind the write');
        } finally {
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($this->master);
            $loop->close();
        }
    }

    /**
     * A write too long for one part, and the signal the worker wrote right behind its last part: the
     * master streams the write on to a reading worker part by part, each after the reader's `next`,
     * and writes the signal to that worker only behind the last part - so the reader takes the write
     * whole before the signal and holds the signal until the write is applied.
     */
    public function testATwoPartWriteReachesAReadingWorkerWholeBeforeTheSignalSentAfterIt(): void
    {
        $loop = new EventLoop();
        try {
            new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->master, $loop);
            new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->master);
            [$source, $sourceLink] = $this->link(1);
            $this->reader->received = [];
            $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignalsWithinBudget');

            $source->stream->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1',
                ['blob' => str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES)]));
            for ($pass = 0; $pass < 64 && !end($source->written)->final; $pass++) {
                $sourceLink->read();
                $dispatch->invoke($this->master);
                $sourceLink->write();
                $source->read();
            }
            $this->assertCount(2, $source->written, 'The write takes two parts');
            $source->send(new WorkerPageAccessReassessConnectionsMessageDTO(['accept-1']));

            for ($pass = 0; $pass < 64 && count($this->reader->received) < 3; $pass++) {
                $sourceLink->read();
                $dispatch->invoke($this->master);
                $sourceLink->write();
                $source->read();
                $this->deliverToReader();
            }

            $this->assertSame([WorkerRtSyncStreamDTO::class, WorkerRtSyncStreamDTO::class, WorkerPageAccessReassessConnectionsMessageDTO::class],
                $this->reader->received, 'The reading worker gets both parts of the write before the signal sent after it');
        } finally {
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($this->master);
            $loop->close();
        }
    }

    /**
     * Only what a worker hands to an agent or to its pages waits for a write streamed to it: an
     * agent's stop the master writes while a two-part write waits for the reader's `next` goes at
     * once, ahead of the write's last part.
     */
    public function testAnAgentStopDoesNotWaitForTheCreditOfAWriteStreamedToTheWorker(): void
    {
        $loop = new EventLoop();
        try {
            new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->master, $loop);
            new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->master);
            $this->reader->received = [];
            $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignalsWithinBudget');

            $this->readerLink->rtSyncStream()->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1',
                ['blob' => str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES)]));
            $dispatch->invoke($this->master);
            $this->readerLink->sendAgentStop(RtSyncStreamTransportTestAgent::AGENT_TYPE);
            for ($pass = 0; $pass < 64 && count($this->reader->received) < 3; $pass++) {
                $this->deliverToReader();
                $dispatch->invoke($this->master);
            }

            $this->assertSame([WorkerRtSyncStreamDTO::class, AgentStopDTO::class, WorkerRtSyncStreamDTO::class], $this->reader->received,
                'The stop does not wait for the write\'s last part');
        } finally {
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($this->master);
            $loop->close();
        }
    }

    /**
     * A signal to an agent waits on the reading worker's link behind a two-part write, and the master
     * stops that agent right after it: the stop goes behind the signal, so the worker never stops the
     * agent ahead of a signal sent to it before. The stop of an agent no signal waits for goes at once.
     */
    public function testAStopOfAnAgentWhoseSignalWaitsForAWriteGoesBehindTheSignal(): void
    {
        $loop = new EventLoop();
        try {
            new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->master, $loop);
            new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->master);
            $this->hostOnReader();
            $this->reader->received = [];
            $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignalsWithinBudget');

            $this->readerLink->rtSyncStream()->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1',
                ['blob' => str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES)]));
            $dispatch->invoke($this->master);
            $this->master->worker->sendSignalToAgent(RtSyncStreamTransportTestAgent::AGENT_TYPE, null,
                new DaemonAgentMessageDTO(RtSyncStreamTransportTestAgent::AGENT_TYPE, new SignalDTO(
                    new SignalSource(SignalSource::DAEMON), new SignalType(SignalTypeConstants::SYSTEM),
                    new SignalName('after-the-write'), new SignalData(),
                )));
            $this->readerLink->sendAgentStop(RtSyncStreamTransportTestAgent::AGENT_TYPE);
            $this->readerLink->sendAgentStop('stream_bystander');
            for ($pass = 0; $pass < 64 && count($this->reader->received) < 5; $pass++) {
                $this->deliverToReader();
                $dispatch->invoke($this->master);
            }

            $this->assertSame([WorkerRtSyncStreamDTO::class, AgentStopDTO::class, WorkerRtSyncStreamDTO::class,
                DaemonAgentMessageDTO::class, AgentStopDTO::class], $this->reader->received,
                'The stop of the agent the signal waits for goes behind the signal, the other stop at once');
            $this->assertSame(['stream_bystander', RtSyncStreamTransportTestAgent::AGENT_TYPE], $this->reader->stopped,
                'The stop that goes at once is the one of the agent no signal waits for');
        } finally {
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($this->master);
            $loop->close();
        }
    }

    /**
     * A signal to an agent waits on the reading worker's link behind a two-part write, and the master's
     * re-hydrate verdict for that agent follows it: the verdict goes behind the signal, as it stood
     * before, and not ahead of a signal sent to the agent earlier.
     */
    public function testAReHydrateVerdictForAnAgentWhoseSignalWaitsForAWriteGoesBehindTheSignal(): void
    {
        $loop = new EventLoop();
        try {
            new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->master, $loop);
            new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->master);
            $this->hostOnReader();
            $this->reader->received = [];
            $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignalsWithinBudget');

            $this->readerLink->rtSyncStream()->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1',
                ['blob' => str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES)]));
            $dispatch->invoke($this->master);
            $this->master->worker->sendSignalToAgent(RtSyncStreamTransportTestAgent::AGENT_TYPE, null,
                new DaemonAgentMessageDTO(RtSyncStreamTransportTestAgent::AGENT_TYPE, new SignalDTO(
                    new SignalSource(SignalSource::DAEMON), new SignalType(SignalTypeConstants::SYSTEM),
                    new SignalName('after-the-write'), new SignalData(),
                )));
            $this->readerLink->sendDbReHydrateComplete(new DbReHydrateCompleteDTO(RtSyncStreamTransportTestAgent::AGENT_TYPE, true));
            for ($pass = 0; $pass < 64 && count($this->reader->received) < 4; $pass++) {
                $this->deliverToReader();
                $dispatch->invoke($this->master);
            }

            $this->assertSame([WorkerRtSyncStreamDTO::class, WorkerRtSyncStreamDTO::class, DaemonAgentMessageDTO::class,
                DbReHydrateCompleteDTO::class], $this->reader->received, 'The verdict goes behind the signal sent to its agent before it');
        } finally {
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($this->master);
            $loop->close();
        }
    }

    /**
     * A write too long for one part waits on the reading worker's link for its next part's credit, and the
     * master starts an agent there: the new instance reads and writes the rows an old one wrote, so the
     * start goes only behind the write's last part, and a stop of the same agent goes behind the start.
     */
    public function testAnAgentStartReachesTheWorkerOnlyBehindTheLastPartOfAWriteStreamedToIt(): void
    {
        $loop = new EventLoop();
        try {
            new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->master, $loop);
            new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->master);
            $this->reader->received = [];
            $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignalsWithinBudget');

            $this->readerLink->rtSyncStream()->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1',
                ['blob' => str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES)]));
            $dispatch->invoke($this->master);
            $this->readerLink->sendAgentStart('stream_newcomer');
            $this->readerLink->sendAgentStop('stream_newcomer');
            for ($pass = 0; $pass < 64 && count($this->reader->received) < 4; $pass++) {
                $this->deliverToReader();
                $dispatch->invoke($this->master);
            }

            $this->assertSame([WorkerRtSyncStreamDTO::class, WorkerRtSyncStreamDTO::class, AgentStartDTO::class, AgentStopDTO::class],
                $this->reader->received, 'The start goes behind the write\'s last part, and the stop behind the start');
        } finally {
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($this->master);
            $loop->close();
        }
    }

    /**
     * @return iterable<string, array{bool, bool}> Whether the master asked for the stop, and whether the start was asked
     *     for before the report or after it
     */
    public static function startsHeldForAStopReport(): iterable
    {
        yield 'asked before the report' => [true, true];
        yield 'asked while the write read before the report settles' => [true, false];
        yield 'stopped unasked, the start asked while the write read before the report settles' => [false, false];
    }

    /**
     * An agent stopped on one worker - by the master, or by itself with nobody asking - wrote a row too long for one
     * part, and the master read the report of its stop while that write still waited in its router: the start it held
     * for the report, or one asked for while the write still waits, goes to another worker only once the dispatch has
     * handed the write to that worker's stream, so it reaches the worker behind the write's last part - the new
     * instance never reads the row without what the old one wrote.
     *
     * @param bool $stopAsked Whether the master asked for the stop
     * @param bool $askedBeforeReport Whether the start was asked for before the master read the report
     */
    #[DataProvider('startsHeldForAStopReport')]
    public function testAStartHeldForAStopReportReachesAnotherWorkerOnlyBehindTheLastPartOfTheOldInstancesWrite(
        bool $stopAsked,
        bool $askedBeforeReport,
    ): void {
        $loop = new EventLoop();
        try {
            new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->master, $loop);
            new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->master);
            [$source, $sourceLink] = $this->link(1);
            $agents = $this->master->getAgentManagerDaemon();
            $origin = SignalDispatcherFixture::direct();
            $originReference = WeakReference::create($origin);
            $notifications = [];
            $notify = static function (?SignalDispatch $dispatch) use (&$notifications): void {
                $notifications[] = $dispatch;
            };
            $agents->afterStreamedWrites($notify, $origin);
            $this->assertSame([$origin], $notifications, 'A clear barrier keeps the synchronous caller');
            $notifications = [];

            // The other agent keeps the stopping one's worker the busier, so the start picks the reading worker.
            foreach ([RtSyncStreamTransportTestAgent::AGENT_TYPE, 'stream_neighbour'] as $agentId) {
                $agent = new RtSyncStreamTransportTestAgent();
                $agent->setWorkerClient($sourceLink);
                $agents->addAgent($agentId, $agent, 1, false);
            }
            if ($stopAsked) {
                $this->master->worker->stopHere(RtSyncStreamTransportTestAgent::AGENT_TYPE);
            }
            if ($askedBeforeReport) {
                $this->master->worker->startHere(RtSyncStreamTransportTestAgent::AGENT_TYPE);
                $this->assertTrue($this->master->worker->isAgentAwaitingStopReport(RtSyncStreamTransportTestAgent::AGENT_TYPE));
            }
            $this->reader->received = [];

            $this->streamTwoPartWrite($source, $sourceLink);
            $source->send(new WorkerAgentStoppedDTO(RtSyncStreamTransportTestAgent::AGENT_TYPE));
            $sourceLink->read();
            $this->assertSame([$stopAsked, !$stopAsked], [
                $sourceLink->awaitsStopReport(RtSyncStreamTransportTestAgent::AGENT_TYPE),
                $agents->hasAgent(RtSyncStreamTransportTestAgent::AGENT_TYPE),
            ], 'The report waits behind the last part the master has not read: a stop asked for is still awaited, and an'
                . ' agent that stopped unasked is still on the roster');
            $this->drain($sourceLink->rtSyncStream());
            $sourceLink->read();

            $this->assertSame([false, false], [
                $sourceLink->awaitsStopReport(RtSyncStreamTransportTestAgent::AGENT_TYPE),
                $agents->hasAgent(RtSyncStreamTransportTestAgent::AGENT_TYPE),
            ], 'The master read the report');
            $this->assertTrue(Hilos::$sr->hasQueuedSignals(), 'with the write still in its router');
            $agents->afterStreamedWrites($notify, $origin);
            unset($origin);
            $this->assertNull($originReference->get(), 'A deferred lifecycle notification cannot retain the startup operation');
            $this->assertSame([], $notifications, 'The notification waits behind the real streamed write');

            if (!$askedBeforeReport) {
                $this->master->worker->startHere(RtSyncStreamTransportTestAgent::AGENT_TYPE);
            }
            $this->assertTrue($this->master->worker->isAgentAwaitingStopReport(RtSyncStreamTransportTestAgent::AGENT_TYPE),
                'The start waits for the write read before the report');

            $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignalsWithinBudget');
            for ($pass = 0; $pass < 64 && count($this->reader->received) < 3; $pass++) {
                $this->master->worker->advanceStartsAwaitingStopReport();
                $dispatch->invoke($this->master);
                $sourceLink->read();
                $this->deliverToReader();
            }

            $this->assertSame([WorkerRtSyncStreamDTO::class, WorkerRtSyncStreamDTO::class, AgentStartDTO::class],
                $this->reader->received, 'The start goes behind the last part of the write read before the report');
            $this->assertSame(2, $agents->getAgentWorkerInfo(RtSyncStreamTransportTestAgent::AGENT_TYPE)?->workerIndex);
            $this->assertSame([null], $notifications, 'The deferred fact cannot borrow the write that freed its barrier');
        } finally {
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($this->master);
            $loop->close();
        }
    }

    /**
     * @return iterable<string, array{bool, bool}> Whether the worker reported the stop before its link closed, and whether
     *     the start was asked for before the report
     */
    public static function startsHeldOnALinkThatLeaves(): iterable
    {
        yield 'reported, the start asked before the report' => [true, true];
        yield 'reported, the start asked while the write read before the report settles' => [true, false];
        yield 'gone without the report' => [false, true];
    }

    /**
     * An agent stopped on one worker wrote a row too long for one part, and the worker's link closes while that write
     * still waits in the master's router - behind the report of the stop, or with the report never to come: the start
     * held for the report goes to another worker only once the dispatch has handed the write to that worker's stream,
     * as it goes while the link stays, so it reaches the worker behind the write's last part.
     *
     * @param bool $reported Whether the worker reported the stop before its link closed
     * @param bool $askedBeforeReport Whether the start was asked for before the master read the report
     */
    #[DataProvider('startsHeldOnALinkThatLeaves')]
    public function testAStartHeldOnALinkThatLeavesReachesAnotherWorkerOnlyBehindTheLastPartOfTheOldInstancesWrite(
        bool $reported,
        bool $askedBeforeReport,
    ): void {
        $loop = new EventLoop();
        try {
            new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->master, $loop);
            new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->master);
            [$source, $sourceLink] = $this->link(1);
            $agents = $this->master->getAgentManagerDaemon();
            $agent = new RtSyncStreamTransportTestAgent();
            $agent->setWorkerClient($sourceLink);
            $agents->addAgent(RtSyncStreamTransportTestAgent::AGENT_TYPE, $agent, 1, false);
            $this->master->worker->stopHere(RtSyncStreamTransportTestAgent::AGENT_TYPE);
            if ($askedBeforeReport) {
                $this->master->worker->startHere(RtSyncStreamTransportTestAgent::AGENT_TYPE);
            }
            $this->reader->received = [];

            $this->streamTwoPartWrite($source, $sourceLink);
            if ($reported) {
                $source->send(new WorkerAgentStoppedDTO(RtSyncStreamTransportTestAgent::AGENT_TYPE));
            }
            $sourceLink->read();
            $this->drain($sourceLink->rtSyncStream());
            $sourceLink->read();
            $this->assertSame(!$reported, $sourceLink->awaitsStopReport(RtSyncStreamTransportTestAgent::AGENT_TYPE));
            $this->assertTrue(Hilos::$sr->hasQueuedSignals(), 'The write waits in the master\'s router');
            if (!$askedBeforeReport) {
                $this->master->worker->startHere(RtSyncStreamTransportTestAgent::AGENT_TYPE);
            }

            $closed = $sourceLink->getSocket();
            $this->master->worker->dropClient($sourceLink);
            $this->sockets = array_values(array_filter($this->sockets, static fn (Socket $socket): bool => $socket !== $closed));
            $this->master->worker->advanceStartsAwaitingStopReport();
            $this->assertTrue($this->master->worker->isAgentAwaitingStopReport(RtSyncStreamTransportTestAgent::AGENT_TYPE),
                'The start waits for the write read from the link that left');

            $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignalsWithinBudget');
            for ($pass = 0; $pass < 64 && count($this->reader->received) < 3; $pass++) {
                $this->master->worker->advanceStartsAwaitingStopReport();
                $dispatch->invoke($this->master);
                $this->deliverToReader();
            }

            $this->assertSame([WorkerRtSyncStreamDTO::class, WorkerRtSyncStreamDTO::class, AgentStartDTO::class],
                $this->reader->received, 'The start goes behind the last part of the write read from the link that left');
            for ($pass = 0; $pass < 8; $pass++) {
                $this->master->worker->advanceStartsAwaitingStopReport();
                $dispatch->invoke($this->master);
                $this->deliverToReader();
            }
            $this->assertCount(1, array_filter($this->reader->received, static fn (string $frame): bool => $frame === AgentStartDTO::class),
                'The worker is started once: a second start after the first would ask it for the same agent twice');
            $this->assertSame(2, $agents->getAgentWorkerInfo(RtSyncStreamTransportTestAgent::AGENT_TYPE)?->workerIndex);
        } finally {
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($this->master);
            $loop->close();
        }
    }

    /**
     * @return iterable<string, array{?bool, bool, list<class-string<WorkerDTO>>}> Whether the dispatch applies the write
     *     read from the link that left or refuses it, null with no write read; whether the node starts the agent itself,
     *     as one declared for every node, rather than being asked; and what the reading worker gets, in order
     */
    public static function startsOfAnAgentLostWithItsWorker(): iterable
    {
        yield 'a write waits in the router' => [true, false, [WorkerRtSyncStreamDTO::class, WorkerRtSyncStreamDTO::class, AgentStartDTO::class]];
        yield 'no write waits' => [null, false, [AgentStartDTO::class]];
        yield 'the dispatch refuses the write' => [false, false, [WorkerRtStalenessMessageDTO::class, AgentStartDTO::class]];
        yield 'a per-node agent the node starts itself' => [true, true,
            [WorkerRtSyncStreamDTO::class, WorkerRtSyncStreamDTO::class, AgentStartDTO::class]];
    }

    /**
     * An agent running on one worker wrote a row too long for one part, and the worker's link closes while that write
     * still waits in the master's router, with no stop asked for or reported: the next start of the agent lost with
     * the worker goes to another worker only once the dispatch has handed the write on - applied or refused - so it
     * reaches the worker behind the write's last part, as a start held for a stop report does. A per-node agent, which
     * the node starts again by itself as the link is removed, waits the same way. With no write read from the link the
     * start goes at once.
     *
     * @param ?bool $applied Whether the dispatch applies the write or refuses it, null with no write read
     * @param bool $perNode Whether the node starts the agent itself, as one declared for every node
     * @param list<class-string<WorkerDTO>> $frames What the reading worker gets, in order
     */
    #[DataProvider('startsOfAnAgentLostWithItsWorker')]
    public function testAStartOfAnAgentLostWithItsWorkerReachesAnotherWorkerOnlyBehindTheLastPartOfTheOldInstancesWrite(
        ?bool $applied,
        bool $perNode,
        array $frames,
    ): void {
        $previousAppClass = Hilos::appClass();
        $loop = new EventLoop();
        try {
            new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->master, $loop);
            new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->master);
            if ($perNode) {
                new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, RtSyncStreamTransportTestHilos::class);
            }
            [$source, $sourceLink] = $this->link(1);
            $agents = $this->master->getAgentManagerDaemon();
            $agent = new RtSyncStreamTransportTestAgent();
            $agent->setWorkerClient($sourceLink);
            $agents->addAgent(RtSyncStreamTransportTestAgent::AGENT_TYPE, $agent, 1, false);
            $this->reader->received = [];

            if ($applied !== null) {
                $this->streamTwoPartWrite($source, $sourceLink);
                $sourceLink->read();
                $this->drain($sourceLink->rtSyncStream());
                $sourceLink->read();
                $this->assertTrue($this->routerHolds(SignalTypeConstants::RT_SYNC_CREATED), 'The write waits in the master\'s router');
            }

            $closed = $sourceLink->getSocket();
            $this->master->worker->dropClient($sourceLink);
            $this->sockets = array_values(array_filter($this->sockets, static fn (Socket $socket): bool => $socket !== $closed));
            if (!$perNode) {
                $this->assertFalse($agents->hasAgent(RtSyncStreamTransportTestAgent::AGENT_TYPE), 'The roster lets the agent go with its worker');
                $this->master->worker->startHere(RtSyncStreamTransportTestAgent::AGENT_TYPE);
            }
            $this->assertSame($applied !== null, $this->master->worker->isAgentAwaitingStopReport(RtSyncStreamTransportTestAgent::AGENT_TYPE),
                'The start waits for the write read from the link that left, and only for it');
            if ($applied === false) {
                // The dispatch takes the write and answers it refused, as it answers every write it does not apply.
                $refused = Hilos::$sr->getNextQueuedSignal()?->data;
                $this->assertInstanceOf(RtSyncCreatedSignalData::class, $refused);
                RtSyncStreamSettle::settle($refused, false);
            }

            $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignalsWithinBudget');
            for ($pass = 0; $pass < 64 && count($this->reader->received) < count($frames); $pass++) {
                $this->master->worker->advanceStartsAwaitingStopReport();
                $dispatch->invoke($this->master);
                $this->deliverToReader();
            }

            $this->assertSame($frames, $this->reader->received, 'The start goes behind the last part of the write read from the link that left');
            for ($pass = 0; $pass < 8; $pass++) {
                $this->master->worker->advanceStartsAwaitingStopReport();
                $dispatch->invoke($this->master);
                $this->deliverToReader();
            }
            $this->assertCount(1, array_filter($this->reader->received, static fn (string $frame): bool => $frame === AgentStartDTO::class),
                'The worker is started once: a second start after the first would ask it for the same agent twice');
            $this->assertSame(2, $agents->getAgentWorkerInfo(RtSyncStreamTransportTestAgent::AGENT_TYPE)?->workerIndex);
        } finally {
            new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $previousAppClass);
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($this->master);
            $loop->close();
        }
    }

    /**
     * @return iterable<string, array{bool, bool}> Whether the agent ran on a worker when it was revoked, and whether that
     *     worker reported the stop before its link closed
     */
    public static function revokedInstances(): iterable
    {
        yield 'the worker reported the stop' => [true, true];
        yield 'the link closed without the report' => [true, false];
        yield 'nothing ran' => [false, false];
    }

    /**
     * An agent revoked on one worker wrote a row too long for one part, and the master read the report of its stop -
     * or saw its link close without it - while that write still waited in its router: the revoke's caller hears that
     * the stopped instance is gone once, and only after the dispatch has handed the write on, so word of the stop
     * never leaves this node ahead of the old instance's last write. With nothing running it hears so at once.
     *
     * @param bool $ran Whether the agent ran on a worker when it was revoked
     * @param bool $reported Whether the worker reported the stop before its link closed
     */
    #[DataProvider('revokedInstances')]
    public function testARevokedInstanceIsGoneOnlyBehindTheWriteItsWorkerStreamedBeforeTheStop(bool $ran, bool $reported): void
    {
        $loop = new EventLoop();
        try {
            new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->master, $loop);
            new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->master);
            // Each time the caller hears the instance is gone: whether the write still waited in the router.
            $toldWithWriteQueued = [];
            $stopped = function () use (&$toldWithWriteQueued): void {
                $toldWithWriteQueued[] = $this->routerHolds(SignalTypeConstants::RT_SYNC_CREATED);
            };
            if (!$ran) {
                $this->master->worker->revokePlacement(RtSyncStreamTransportTestAgent::AGENT_TYPE, null, $stopped);
                $this->assertSame([false], $toldWithWriteQueued, 'Nothing ran, so nothing is left to wait for');

                return;
            }

            [$source, $sourceLink] = $this->link(1);
            $agent = new RtSyncStreamTransportTestAgent();
            $agent->setWorkerClient($sourceLink);
            $this->master->getAgentManagerDaemon()->addAgent(RtSyncStreamTransportTestAgent::AGENT_TYPE, $agent, 1, false);
            $this->master->worker->revokePlacement(RtSyncStreamTransportTestAgent::AGENT_TYPE, null, $stopped);
            $this->assertTrue($sourceLink->awaitsStopReport(RtSyncStreamTransportTestAgent::AGENT_TYPE));
            $this->assertSame([], $toldWithWriteQueued, 'The stop is sent, and its worker has not reported it');
            $this->reader->received = [];

            $this->streamTwoPartWrite($source, $sourceLink);
            if ($reported) {
                $source->send(new WorkerAgentStoppedDTO(RtSyncStreamTransportTestAgent::AGENT_TYPE));
            }
            $sourceLink->read();
            $this->drain($sourceLink->rtSyncStream());
            $sourceLink->read();
            $this->assertSame(!$reported, $sourceLink->awaitsStopReport(RtSyncStreamTransportTestAgent::AGENT_TYPE));
            $this->assertTrue(Hilos::$sr->hasQueuedSignals(), 'The write waits in the master\'s router');
            if (!$reported) {
                $closed = $sourceLink->getSocket();
                $this->master->worker->dropClient($sourceLink);
                $this->sockets = array_values(array_filter($this->sockets, static fn (Socket $socket): bool => $socket !== $closed));
            }
            $this->assertSame([], $toldWithWriteQueued, 'The report, or the link\'s departure, came ahead of the write it follows');

            $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignalsWithinBudget');
            for ($pass = 0; $pass < 64 && $toldWithWriteQueued === []; $pass++) {
                $dispatch->invoke($this->master);
                $this->deliverToReader();
            }
            for ($pass = 0; $pass < 8; $pass++) {
                $dispatch->invoke($this->master);
                $this->deliverToReader();
            }

            $this->assertSame([false], $toldWithWriteQueued, 'Told once, after the dispatch handed the write on');
            $this->assertSame([WorkerRtSyncStreamDTO::class, WorkerRtSyncStreamDTO::class], $this->reader->received,
                'The write reached the other worker');
        } finally {
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($this->master);
            $loop->close();
        }
    }

    /**
     * Two callers revoked the same agent on its worker, and the first told that the stopped instance is gone raises:
     * the second is told all the same, behind it, and the failure reaches the dispatch that handed the write on.
     */
    public function testACallerTheRevokedInstanceIsGoneToThatRaisesDoesNotKeepTheNextOneUntold(): void
    {
        $loop = new EventLoop();
        try {
            new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->master, $loop);
            new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->master);
            $failure = new EventLoopException('The first caller failed');
            $told = [];
            [$source, $sourceLink] = $this->link(1);
            $agent = new RtSyncStreamTransportTestAgent();
            $agent->setWorkerClient($sourceLink);
            $this->master->getAgentManagerDaemon()->addAgent(RtSyncStreamTransportTestAgent::AGENT_TYPE, $agent, 1, false);
            $this->master->worker->revokePlacement(RtSyncStreamTransportTestAgent::AGENT_TYPE, null,
                static function () use ($failure, &$told): void {
                    $told[] = 'first';
                    throw $failure;
                });
            $this->master->worker->revokePlacement(RtSyncStreamTransportTestAgent::AGENT_TYPE, null,
                static function () use (&$told): void {
                    $told[] = 'second';
                });
            $this->assertSame([], $told, 'Both wait for the report of the stop sent on the agent\'s connection');
            $this->reader->received = [];

            $this->streamTwoPartWrite($source, $sourceLink);
            $source->send(new WorkerAgentStoppedDTO(RtSyncStreamTransportTestAgent::AGENT_TYPE));
            $sourceLink->read();
            $this->drain($sourceLink->rtSyncStream());
            $sourceLink->read();
            $this->assertSame([], $told, 'The report came ahead of the write it follows');

            $raised = null;
            $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignalsWithinBudget');
            for ($pass = 0; $pass < 64 && $told === []; $pass++) {
                try {
                    $dispatch->invoke($this->master);
                } catch (EventLoopException $caught) {
                    $raised = $caught;
                }
                $this->deliverToReader();
            }

            $this->assertSame(['first', 'second'], $told, 'The second caller is told behind the first that raised');
            $this->assertSame($failure, $raised, 'The failure reaches the dispatch that handed the write on');
        } finally {
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($this->master);
            $loop->close();
        }
    }

    /**
     * Two agents revoked on one worker, each with a caller waiting for its stopped instance to be gone, and the worker's
     * link closes with no report and no write left to hand on: the caller of the first agent raises, the caller of the
     * other agent is told all the same, the node still starts again the per-node agent the close took - the last step of
     * the removal - and the failure reaches whoever dropped the link.
     */
    public function testACallerOfOneAgentRevokedOnALinkThatClosesRaisingDoesNotKeepTheNeighbourOrTheRestOfTheRemovalUndone(): void
    {
        $previousAppClass = Hilos::appClass();
        $loop = new EventLoop();
        try {
            new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->master, $loop);
            new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->master);
            new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, RtSyncStreamTransportTestHilos::class);
            $failure = new EventLoopException('The first agent\'s caller failed');
            $told = [];
            [, $sourceLink] = $this->link(1);
            $agents = $this->master->getAgentManagerDaemon();
            foreach ([RtSyncStreamTransportTestAgent::AGENT_TYPE, self::WRITER] as $agentType) {
                $agent = new RtSyncStreamTransportTestAgent();
                $agent->setWorkerClient($sourceLink);
                $agents->addAgent($agentType, $agent, 1, false);
            }
            $this->master->worker->revokePlacement(RtSyncStreamTransportTestAgent::AGENT_TYPE, null,
                static function () use ($failure, &$told): void {
                    $told[] = 'first agent';
                    throw $failure;
                });
            $this->master->worker->revokePlacement(self::WRITER, null,
                static function () use (&$told): void {
                    $told[] = 'second agent';
                });
            $this->assertSame([], $told, 'Both wait for the report of the stop sent on the agents\' connection');
            $this->reader->received = [];

            $closed = $sourceLink->getSocket();
            $raised = null;
            try {
                $this->master->worker->dropClient($sourceLink);
            } catch (EventLoopException $caught) {
                $raised = $caught;
            }
            $this->sockets = array_values(array_filter($this->sockets, static fn (Socket $socket): bool => $socket !== $closed));

            $this->assertSame(['first agent', 'second agent'], $told, 'The other agent\'s caller is told behind the one that raised, once');
            $this->assertSame($failure, $raised, 'The failure reaches whoever dropped the link');
            $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignalsWithinBudget');
            for ($pass = 0; $pass < 8; $pass++) {
                $dispatch->invoke($this->master);
                $this->deliverToReader();
            }
            $this->assertSame([AgentStartDTO::class], $this->reader->received, 'The node started its per-node agent again, once');
            $this->assertSame(2, $agents->getAgentWorkerInfo(RtSyncStreamTransportTestAgent::AGENT_TYPE)?->workerIndex);
        } finally {
            new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $previousAppClass);
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($this->master);
            $loop->close();
        }
    }

    /**
     * The agent that alone owns a collection on this node writes a row, and its worker's link closes while the
     * write the master read from that worker still waits in the master's router: the roster lets the agent go at
     * once, and what it owned stays this node's until the dispatch has handed the write on, so the write still
     * goes out to the node that reads the collection, and the claim goes behind it.
     */
    public function testAWriteTheMasterReadFromAWorkerBeforeItsLinkClosedGoesOutToThePeer(): void
    {
        $loop = new EventLoop();
        try {
            new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->master, $loop);
            new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->master);
            [$here, $there, , , $peer] = $this->peerLinks();
            // Node B reads the collection, over an active link of this node's peer server.
            new ReflectionMethod(AbstractServer::class, 'addClient')->invoke($peer, $here);
            new ReflectionProperty(PeerServer::class, 'activeLinks')->setValue($peer, ['node-b' => $here]);
            new ReflectionProperty(PeerServer::class, 'nodeReaderMap')->getValue($peer)
                ->note('node-b', SourceChange::KIND_RT, [self::COLLECTION]);
            [$source, $sourceLink] = $this->link(1);
            $agents = $this->master->getAgentManagerDaemon();
            $writer = new RtSyncStreamTransportTestAgent();
            $writer->setWorkerClient($sourceLink);
            $agents->addAgent(self::WRITER, $writer, 1, false);
            $agents->handleRtSourceRegistered(new WorkerRtSourceRegisteredDTO(self::WRITER, [self::COLLECTION]));
            $here->write();
            $this->wireDtos($there->getSocket());

            $source->stream->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1', ['owner' => null]));
            $sourceLink->read();
            $this->drain($sourceLink->rtSyncStream());
            $this->assertTrue($this->routerHolds(SignalTypeConstants::RT_SYNC_CREATED), 'The write waits in the master\'s router');

            $closed = $sourceLink->getSocket();
            $this->master->worker->dropClient($sourceLink);
            $this->sockets = array_values(array_filter($this->sockets, static fn (Socket $socket): bool => $socket !== $closed));
            $this->assertFalse($agents->hasAgent(self::WRITER), 'The roster lets the agent go at once');
            $this->assertTrue($agents->rtNodeSourceMap()->owns(self::COLLECTION), 'The claim waits for the write read before the close');

            $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignalsWithinBudget');
            for ($pass = 0; $pass < 8; $pass++) {
                $dispatch->invoke($this->master);
                $this->deliverToReader();
                $here->write();
            }

            $this->assertSame([PeerRtSyncStreamDTO::class], $this->wireFrames($there->getSocket()), 'The write goes out to node B');
            $this->assertFalse($agents->rtNodeSourceMap()->owns(self::COLLECTION), 'The claim goes behind the write');
        } finally {
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($this->master);
            $loop->close();
        }
    }

    /**
     * A worker's link closes while the master has taken the part of a write from it and not read it through: the
     * write is refused, as any write the master does not apply - the dispatch is handed nothing of it, and the
     * workers that read the row are told it is frozen - so what waits behind the writes a link handed over before
     * its close is exactly those writes.
     */
    public function testAPartTakenAndNotReadThroughWhenALinkClosesIsNotHandedToTheDispatch(): void
    {
        $loop = new EventLoop();
        try {
            new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->master, $loop);
            new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->master);
            [$source, $sourceLink] = $this->link(1);
            $this->reader->received = [];

            $source->stream->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1', ['owner' => null]));
            $sourceLink->read();
            $this->assertTrue($sourceLink->rtSyncStream()->isReading(), 'The master took the part and has not read it through');

            $closed = $sourceLink->getSocket();
            $this->master->worker->dropClient($sourceLink);
            $this->sockets = array_values(array_filter($this->sockets, static fn (Socket $socket): bool => $socket !== $closed));
            $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignalsWithinBudget');
            for ($pass = 0; $pass < 8; $pass++) {
                $dispatch->invoke($this->master);
                $this->deliverToReader();
            }

            $this->assertFalse($sourceLink->rtSyncStream()->isReading(), 'The stream is through with the part');
            $this->assertNotContains(SignalTypeConstants::RT_SYNC_CREATED, $this->master->router->queued,
                'Nothing of the part reaches the dispatch');
            $this->assertSame([WorkerRtStalenessMessageDTO::class], $this->reader->received,
                'The reading worker gets no part of it, only the freeze of the row the refused write leaves');
        } finally {
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($this->master);
            $loop->close();
        }
    }

    /**
     * A thaw waits on the reading worker's link behind a two-part write, and one of its rows freezes again
     * before the write's last part: the freeze goes at once, and the thaw that still waits no longer names
     * that row - written after the freeze, it would undo it on the worker's copy. Its other row thaws
     * behind the write.
     */
    public function testAFreezeGoesAtOnceAndTakesItsRowOutOfAThawWaitingBehindAWrite(): void
    {
        $loop = new EventLoop();
        try {
            new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->master, $loop);
            new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->master);
            $this->reader->received = [];
            $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignalsWithinBudget');

            $this->readerLink->rtSyncStream()->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1',
                ['blob' => str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES)]));
            $dispatch->invoke($this->master);
            $thaw = new WorkerRtStalenessMessageDTO(self::COLLECTION, ['row-1', 'row-2'], null);
            $this->readerLink->sendRtStaleness($thaw, $thaw->toJson());
            $freeze = new WorkerRtStalenessMessageDTO(self::COLLECTION, ['row-1'], 1700000000.5);
            $this->readerLink->sendRtStaleness($freeze, $freeze->toJson());
            for ($pass = 0; $pass < 64 && count($this->reader->received) < 4; $pass++) {
                $this->deliverToReader();
                $dispatch->invoke($this->master);
            }

            $this->assertSame([WorkerRtSyncStreamDTO::class, WorkerRtStalenessMessageDTO::class, WorkerRtSyncStreamDTO::class,
                WorkerRtStalenessMessageDTO::class], $this->reader->received, 'The freeze at once, the thaw behind the write');
            $this->assertSame([[['row-1'], 1700000000.5], [['row-2'], null]], array_map(
                static fn (WorkerRtStalenessMessageDTO $frame): array => [$frame->stateIds, $frame->since],
                $this->reader->staleness,
            ), 'The thaw no longer names the row that froze again');
        } finally {
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($this->master);
            $loop->close();
        }
    }

    /**
     * A link lets go of rows its stream held while the master's effect queue still holds work - the apply of the
     * snapshot that gave them a base again: the master lifts their mark at once and leaves the thaw to the queue,
     * behind that work. A hold of one of them that comes before the thaw's turn freezes it again, here and on the
     * reading worker at once, and the thaw, when its turn comes, no longer names it - written after the freeze, it
     * would leave the worker reading as current a row the master holds frozen.
     */
    public function testAThawQueuedForRowsLetGoOfLeavesOutTheRowHeldAgainBeforeItsTurn(): void
    {
        $loop = new EventLoop();
        try {
            new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->master, $loop);
            new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->master);
            $hold = new ReflectionProperty(WorkerServer::class, 'rtSyncStreamHold')->getValue($this->master->worker);
            $this->reader->received = [];
            $busy = 0;
            SignalDispatcherFixture::queue($this->master, static function () use (&$busy): Generator {
                for (; $busy < 256; $busy++) {
                    yield;
                }
            });

            $hold(1, self::COLLECTION, ['row-1', 'row-2'], 1700000000.5);
            $hold(1, self::COLLECTION, ['row-1', 'row-2'], null);
            $this->assertSame([], RtStaleness::staleRows(self::COLLECTION), 'The master lifted its own mark at once');
            $hold(1, self::COLLECTION, ['row-1'], 1700000001.5);
            $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignalsWithinBudget');
            for ($pass = 0; $pass < 64 && count($this->reader->received) < 3; $pass++) {
                $dispatch->invoke($this->master);
                $this->deliverToReader();
            }

            $this->assertSame([[['row-1', 'row-2'], 1700000000.5], [['row-1'], 1700000001.5], [['row-2'], null]], array_map(
                static fn (WorkerRtStalenessMessageDTO $frame): array => [$frame->stateIds, $frame->since],
                $this->reader->staleness,
            ), 'The thaw no longer names the row held again');
            $this->assertSame(1700000001.5, RtStaleness::staleSince(self::COLLECTION, 'row-1'));
        } finally {
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($this->master);
            $loop->close();
        }
    }

    /** A peer's operation crosses the peer factory and the master to a reading worker; a stray origin or generation does nothing. */
    public function testPeerOperationCrossesTheMasterToAWorkerAndAStrayOriginIsRefused(): void
    {
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerRtSyncSink($this->master);
        $peer = new PeerServer('127.0.0.1', 0, NodeIdentity::of('node-a', 'Node A', NodeRole::Master, []),
            [], PeerTestTls::unread(), PeerTestMarkers::shared());
        $peer->setRtSyncStreamWake($this->master->wakeRtSyncStream(...));
        $link = new ReflectionClass(PeerLink::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(PeerLink::class, 'remoteIdentity')->setValue($link, NodeIdentity::of('node-b', 'Node B', NodeRole::Master, []));
        $answers = [];
        $receiving = new RtSyncStreamChannel(
            static function (RtSyncStreamFrame $frame) use (&$answers): void {
                $answers[] = $frame;
            },
            static function (RtSyncStreamOperation $operation, Closure $settled) use ($peer, $link): void {
                $peer->onRtSyncStreamReceived($link, $operation, $settled);
            },
        );
        $wire = [];
        $remote = new RtSyncStreamChannel(
            static function (RtSyncStreamFrame $frame) use (&$wire): void {
                $wire[] = $frame;
            },
            static function (): void {
            },
            static function (): void {
            },
        );
        $row = ['blob' => str_repeat('peer row ', 4000), 'nested' => ['a' => [1, 2.5, true, null]]];
        $remote->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-2', $row, 'accept-2', emitter: 'emitter-n1'), 'node-b', true);

        $parts = 0;
        while (true) {
            $this->drain($remote);
            $this->assertCount(1, $wire, 'One part is out at a time');
            $parsed = PeerDTO::fromWire(new PeerRtSyncStreamDTO(array_shift($wire))->toJson());
            $this->assertInstanceOf(PeerRtSyncStreamDTO::class, $parsed);
            $receiving->receive($parsed->frame);
            ++$parts;
            if ($parsed->frame->final) {
                break;
            }
            $this->assertSame([RtSyncStreamStatus::Next], array_map(static fn (RtSyncStreamFrame $answer) => $answer->status, $answers));
            $this->assertFalse($this->readerLink->hasPendingWrite(), 'No part reaches the worker before the operation is whole');
            $remote->receive(array_shift($answers));
        }
        $this->assertGreaterThanOrEqual(2, $parts);
        $this->assertSame([RtSyncStreamStatus::Applied], array_map(static fn (RtSyncStreamFrame $answer) => $answer->status, $answers));
        $this->assertTrue($this->readerLink->hasPendingWrite(), 'Applied is answered once the worker delivery is queued');
        $remote->receive(array_shift($answers));
        $this->assertTrue($remote->isIdle());

        $this->deliverToReader();
        $this->assertCount(1, $this->reader->delivered);
        $operation = $this->reader->delivered[0];
        $this->assertSame([
            RtSyncStreamOperation::FIELD_ROW => $row,
            'origin' => 'accept-2',
            RtSyncStreamOperation::FIELD_EMITTER => 'emitter-n1',
            'node' => null,
            'partial' => false,
        ], [
            RtSyncStreamOperation::FIELD_ROW => RtRowValues::nativeMap($operation->row),
            'origin' => $operation->origin,
            RtSyncStreamOperation::FIELD_EMITTER => $operation->emitter,
            'node' => $operation->originNodeId,
            'partial' => $operation->partialOwner,
        ]);
        $this->assertSame('emitter-n1', $operation->signalData()->emitter, 'The worker\'s carrier names the process that sent the fact');

        $remote->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-3', ['blob' => 'third']), 'node-c');
        $this->drain($remote);
        $receiving->receive(PeerDTO::fromWire(new PeerRtSyncStreamDTO(array_shift($wire))->toJson())->frame);
        $this->assertSame([RtSyncStreamStatus::Refused], array_map(static fn (RtSyncStreamFrame $answer) => $answer->status, $answers),
            'An operation naming a node other than the linked one is refused');
        $receiving->receive($this->staleFrame());
        $this->assertCount(1, $answers, 'A part of another generation is not answered');
        $this->assertFalse($this->readerLink->hasPendingWrite(), 'Neither reaches the worker');
    }

    /**
     * A worker's write the master refuses holds its row on the master's end of the link, and the
     * master's copy of the row is frozen for every worker that reads it, since their copies come
     * from that one: a reading worker is told the row froze as of the refusal, the way it is told of
     * every other mark of the master's copy. The writing worker is told nothing - its copy is the
     * one the row is written to. The row's recovery tells the readers it is current again.
     */
    public function testAWorkerWriteTheMasterRefusesFreezesTheRowForTheOtherWorkersThatReadIt(): void
    {
        [$source, $sourceLink] = $this->link(1);
        $source->send(new WorkerSourceInterestDTO([self::COLLECTION], [], 1));
        $sourceLink->read();
        $sourceLink->write();
        $source->read();
        $before = microtime(true);
        $source->stream->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-4', ['blob' => 'refused']), 'node-x');
        $this->drain($source->stream);
        $sourceLink->read();
        $sourceLink->write();
        $source->read();
        $this->assertSame([RtSyncStreamStatus::Refused], array_map(static fn (RtSyncStreamFrame $answer) => $answer->status,
            $source->answers), 'A worker\'s write naming a node is refused');
        $this->assertSame(['row-4'], $sourceLink->rtSyncStream()->heldRows(self::COLLECTION));

        $this->readerLink->write();
        $this->reader->read();
        $this->assertCount(1, $this->reader->staleness, 'The reading worker is told the row froze');
        $frozen = $this->reader->staleness[0];
        $this->assertSame([self::COLLECTION, ['row-4']], [$frozen->collectionKey, $frozen->stateIds]);
        $this->assertNotNull($frozen->since);
        $this->assertGreaterThanOrEqual($before, $frozen->since);
        $this->assertSame($frozen->since, RtStaleness::staleSince(self::COLLECTION, 'row-4'), 'As of the master\'s own mark');
        $this->assertSame([], $source->staleness, 'The writing worker is told nothing');

        $sourceLink->rtSyncStream()->recover(self::COLLECTION);
        $this->readerLink->write();
        $this->reader->read();
        $this->assertCount(2, $this->reader->staleness, 'The reading worker is told the row thawed');
        $thawed = $this->reader->staleness[1];
        $this->assertSame([self::COLLECTION, ['row-4'], null], [$thawed->collectionKey, $thawed->stateIds, $thawed->since]);
        $this->assertNull(RtStaleness::staleSince(self::COLLECTION, 'row-4'));
    }

    /**
     * A linked node's write the master refuses holds its row on the master's end of the peer link,
     * and the master's copy of the row is frozen for the workers that read it as well: their copies
     * come from that one. None of them wrote the row, so every reader is told. The row's recovery
     * tells them it is current again. The peer server gets the master's mark as the daemon hands it
     * every server it registers.
     */
    public function testANodesWriteTheMasterRefusesFreezesTheRowForTheWorkersThatReadIt(): void
    {
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerRtSyncSink($this->master);
        $peer = new PeerServer('127.0.0.1', 0, NodeIdentity::of('node-a', 'Node A', NodeRole::Master, []),
            [], PeerTestTls::unread(), PeerTestMarkers::shared());
        $this->master->registerServer($peer);
        $pair = [];
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        array_push($this->sockets, ...$pair);
        $link = new PeerLink($pair[0], $peer, NodeIdentity::of('node-a', 'Node A', NodeRole::Master, []), false,
            new PlainSocketTransport($pair[0]));
        new ReflectionProperty(PeerLink::class, 'remoteIdentity')->setValue($link, NodeIdentity::of('node-b', 'Node B', NodeRole::Master, []));
        $wire = [];
        $remote = new RtSyncStreamChannel(
            static function (RtSyncStreamFrame $frame) use (&$wire): void {
                $wire[] = $frame;
            },
            static function (): void {
            },
        );

        $before = microtime(true);
        $remote->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-5', ['blob' => 'refused']), 'node-c');
        $link->rtSyncStream()->receive(array_shift($wire));
        $this->assertSame(['row-5'], $link->rtSyncStream()->heldRows(self::COLLECTION), 'A node\'s write naming another node is refused');

        $this->readerLink->write();
        $this->reader->read();
        $this->assertCount(1, $this->reader->staleness, 'The reading worker is told the row froze');
        $frozen = $this->reader->staleness[0];
        $this->assertSame([self::COLLECTION, ['row-5']], [$frozen->collectionKey, $frozen->stateIds]);
        $this->assertNotNull($frozen->since);
        $this->assertGreaterThanOrEqual($before, $frozen->since);
        $this->assertSame($frozen->since, RtStaleness::staleSince(self::COLLECTION, 'row-5'), 'As of the master\'s own mark');

        $link->recoverRtSyncStream(self::COLLECTION);
        $this->readerLink->write();
        $this->reader->read();
        $this->assertCount(2, $this->reader->staleness, 'The reading worker is told the row thawed');
        $thawed = $this->reader->staleness[1];
        $this->assertSame([self::COLLECTION, ['row-5'], null], [$thawed->collectionKey, $thawed->stateIds, $thawed->since]);
        $this->assertNull(RtStaleness::staleSince(self::COLLECTION, 'row-5'));
    }

    /**
     * A node's write too long for one part, and the signal an agent there sends right after it to an
     * agent of this node: the other node writes the signal only behind the write's last part, so the
     * master here publishes the write to the agent's worker before it hands the agent the signal.
     */
    public function testATwoPartWriteOfANodeReachesTheAgentsWorkerBeforeTheSignalSentAfterIt(): void
    {
        $loop = new EventLoop();
        try {
            new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->master, $loop);
            new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->master);
            [$here, $there] = $this->peerLinks();
            $this->reader->received = [];

            $there->rtSyncStream()->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1',
                ['blob' => str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES)]), 'node-b');
            $there->sendFrame($this->signalToReader());
            $this->movePeerFrames($here, $there, 2);

            $this->assertSame([WorkerRtSyncStreamDTO::class, WorkerRtSyncStreamDTO::class, DaemonAgentMessageDTO::class],
                $this->reader->received, 'The agent\'s worker gets both parts of the write before the signal sent after it');
        } finally {
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($this->master);
            $loop->close();
        }
    }

    /**
     * A node's write too long for one part, and the access re-decision of pages it fans out right after
     * it: the other node writes the re-decision only behind the write's last part, so the master here
     * publishes the write to its reading worker before it hands that worker the re-decision, which
     * reads who is behind a connection from the copy the write updates.
     */
    public function testATwoPartWriteOfANodeReachesTheWorkersBeforeTheAccessReDecisionSentAfterIt(): void
    {
        $loop = new EventLoop();
        try {
            new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->master, $loop);
            new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->master);
            [$here, $there, , $remote] = $this->peerLinks();
            Hilos::$cluster?->registerClientSignalSink($this->master);
            new ReflectionMethod(AbstractServer::class, 'addClient')->invoke($remote, $there);
            new ReflectionProperty(PeerServer::class, 'activeLinks')->setValue($remote, ['node-a' => $there]);
            $this->reader->received = [];

            $there->rtSyncStream()->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1',
                ['blob' => str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES)]), 'node-b');
            $remote->broadcastPageAccessReassess(new SignalDTO(new SignalSource(SignalSource::DAEMON),
                new SignalType(SignalTypeConstants::PAGE_ACCESS_REASSESS_CONNECTIONS),
                new SignalName(SignalConstants::PAGE_ACCESS_REASSESS_CONNECTIONS),
                new PageAccessReassessConnectionsSignalData(['accept-1'])));
            $this->movePeerFrames($here, $there, 2);

            $this->assertSame([WorkerRtSyncStreamDTO::class, WorkerRtSyncStreamDTO::class, WorkerPageAccessReassessConnectionsMessageDTO::class],
                $this->reader->received, 'The reading worker gets both parts of the write before the re-decision sent after it');
        } finally {
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($this->master);
            $loop->close();
        }
    }

    /**
     * A node's write in one part and the signal sent after it, read here in one read while the
     * master is busy and has not read the part: the part's arrival queued the master's read of it,
     * the signal is queued for the agent behind that read, and the write the read publishes to the
     * agent's worker is written there ahead of the signal - no hold of the link's parsing is needed.
     */
    public function testAOnePartWriteOfANodeTheMasterHasNotReadStaysAheadOfTheSignalInTheSameRead(): void
    {
        $loop = new EventLoop();
        try {
            new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->master, $loop);
            new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->master);
            [$here, $there] = $this->peerLinks();
            $this->reader->received = [];
            $busy = 0;
            SignalDispatcherFixture::queue($this->master, static function () use (&$busy): Generator {
                for (; $busy < 256; $busy++) {
                    yield;
                }
            });
            $this->assertLessThan(256, $busy, 'An older effect is still queued');

            $there->rtSyncStream()->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1', ['owner' => null]), 'node-b');
            $there->sendFrame($this->signalToReader());
            $there->write();
            $here->read();
            $this->assertTrue($here->rtSyncStream()->isReading(), 'The master has not read the part yet');
            $this->movePeerFrames($here, $there, 1);

            $this->assertSame([WorkerRtSyncStreamDTO::class, DaemonAgentMessageDTO::class], $this->reader->received,
                'The agent\'s worker gets the write before the signal sent after it');
        } finally {
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($this->master);
            $loop->close();
        }
    }

    /**
     * Only a signal to an agent waits for the write a node streamed before it: keepalive and
     * consensus frames written while a write waits for its next part's credit go out at once.
     */
    public function testKeepaliveAndHeartbeatDoNotWaitForTheCreditOfAnOpenWrite(): void
    {
        [, $there, $socket] = $this->peerLinks();

        $there->rtSyncStream()->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1',
            ['blob' => str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES)]), 'node-b');
        $there->sendFrame($this->signalToReader());
        $there->sendFrame(new PeerPingDTO());
        $there->sendFrame(new PeerHeartbeatDTO(1, 'node-b', true));
        $there->write();

        $this->assertSame([PeerRtSyncStreamDTO::class, PeerPingDTO::class, PeerHeartbeatDTO::class], $this->wireFrames($socket),
            'The first part, then the keepalive and the heartbeat; the signal waits for the last part');
    }

    /**
     * The status of an agent of a node - here its stop, which the leader places the agent anew on -
     * written while a write of that node waits for its next part's credit: it goes only behind the
     * write's last part, so the node the leader picks next never gets the agent ahead of the old
     * instance's last write.
     */
    public function testAnAgentStatusLeavesANodeOnlyBehindTheLastPartOfItsWrite(): void
    {
        [, $there, $socket] = $this->peerLinks();

        $there->rtSyncStream()->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1',
            ['blob' => str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES)]), 'node-b');
        $there->sendFrame(PeerAgentStatusDTO::stopped(RtSyncStreamTransportTestAgent::AGENT_TYPE, null));
        $there->write();

        $this->assertSame([PeerRtSyncStreamDTO::class], $this->wireFrames($socket), 'The status waits for the write\'s last part');

        $there->rtSyncStream()->receive(RtSyncStreamFrame::ack($there->rtSyncStream()->streamId, 1, 0, RtSyncStreamStatus::Next));
        $there->write();

        $this->assertSame([PeerRtSyncStreamDTO::class, PeerAgentStatusDTO::class], $this->wireFrames($socket),
            'The status goes behind the write\'s last part');
    }

    /**
     * The report of what a node's agents own, broadcast while a write of that node waits for its next
     * part's credit: the leader drops a claim the report no longer carries, and another node's claim to
     * the same rows then stands, so the report goes to each node only behind the write's last part.
     */
    public function testAClaimsReportLeavesANodeOnlyBehindTheLastPartOfItsWrite(): void
    {
        [, $there, $socket, $remote] = $this->peerLinks();
        new ReflectionMethod(AbstractServer::class, 'addClient')->invoke($remote, $there);
        new ReflectionProperty(PeerServer::class, 'activeLinks')->setValue($remote, ['node-a' => $there]);

        $there->rtSyncStream()->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1',
            ['blob' => str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES)]), 'node-b');
        $remote->announceRtClaims([new PeerRtClaimEntry(RtSyncStreamTransportTestAgent::AGENT_TYPE,
            RtSyncStreamTransportTestAgent::AGENT_TYPE, null, [self::COLLECTION])]);
        $there->write();

        $this->assertSame([PeerRtSyncStreamDTO::class], $this->wireFrames($socket), 'The report waits for the write\'s last part');

        $there->rtSyncStream()->receive(RtSyncStreamFrame::ack($there->rtSyncStream()->streamId, 1, 0, RtSyncStreamStatus::Next));
        $there->write();

        $this->assertSame([PeerRtSyncStreamDTO::class, PeerRtClaimsDTO::class], $this->wireFrames($socket),
            'The report goes behind the write\'s last part');
    }

    /**
     * The report of what a node hosts and a command's reply the node forwards, written while a write of
     * that node waits for its next part's credit: the leader places anew an agent the report leaves out,
     * and the command's caller acts on the outcome, so both go only behind the write's last part.
     */
    public function testAPlacementReportAndACommandReplyLeaveANodeOnlyBehindTheLastPartOfItsWrite(): void
    {
        [, $there, $socket] = $this->peerLinks();

        $there->rtSyncStream()->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1',
            ['blob' => str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES)]), 'node-b');
        $there->sendFrame(new PeerPlacementReportDTO([]));
        $there->sendFrame($this->commandReplyToNodeA());
        $there->write();

        $this->assertSame([PeerRtSyncStreamDTO::class], $this->wireFrames($socket),
            'The report and the reply wait for the write\'s last part');

        $there->rtSyncStream()->receive(RtSyncStreamFrame::ack($there->rtSyncStream()->streamId, 1, 0, RtSyncStreamStatus::Next));
        $there->write();

        $this->assertSame([PeerRtSyncStreamDTO::class, PeerPlacementReportDTO::class, PeerCommandReplyDTO::class],
            $this->wireFrames($socket), 'The report and the reply go behind the write\'s last part, in order');
    }

    /**
     * A signal to an agent of the linked node waits behind a two-part write, and this node then restarts
     * and stops that agent: both go behind the signal, so the linked node never stops the agent ahead of a
     * signal sent to it before. The stop of an agent no frame waits for goes at once.
     */
    public function testAStopOrRestartOfAnAgentWhoseSignalWaitsForAWriteLeavesANodeBehindTheSignal(): void
    {
        [, $there, $socket] = $this->peerLinks();

        $there->rtSyncStream()->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1',
            ['blob' => str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES)]), 'node-b');
        $there->sendFrame($this->signalToReader());
        $there->sendFrame(new PeerAgentRestartDTO('cid-1', 'node-b', RtSyncStreamTransportTestAgent::AGENT_TYPE, null, null, false));
        $there->sendFrame(new PeerStopAgentDTO(RtSyncStreamTransportTestAgent::AGENT_TYPE, null));
        $there->sendFrame(new PeerStopAgentDTO('stream_bystander', null));
        $there->write();

        $atOnce = $this->wireDtos($socket);
        $this->assertSame([PeerRtSyncStreamDTO::class, PeerStopAgentDTO::class],
            array_map(static fn (PeerDTO $frame): string => $frame::class, $atOnce), 'The first part, then the stop of the agent no frame waits for');
        $this->assertInstanceOf(PeerStopAgentDTO::class, $atOnce[1]);
        $this->assertSame('stream_bystander', $atOnce[1]->agentType);

        $there->rtSyncStream()->receive(RtSyncStreamFrame::ack($there->rtSyncStream()->streamId, 1, 0, RtSyncStreamStatus::Next));
        $there->write();

        $this->assertSame([PeerRtSyncStreamDTO::class, PeerSignalDTO::class, PeerAgentRestartDTO::class, PeerStopAgentDTO::class],
            $this->wireFrames($socket), 'The signal behind the write\'s last part, the restart and the stop of its agent behind the signal');
    }

    /**
     * An order to place an agent, written while a write of the old instance waits for its next part's
     * credit: the linked node starts the new instance trusting that write, so the order goes only behind
     * the write's last part, and a stop of the same agent written after the order goes behind it.
     */
    public function testAnOrderToPlaceAnAgentLeavesANodeOnlyBehindTheLastPartOfTheOldInstancesWrite(): void
    {
        [, $there, $socket] = $this->peerLinks();

        $there->rtSyncStream()->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1',
            ['blob' => str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES)]), 'node-b');
        $there->sendFrame(new PeerPlaceAgentDTO(RtSyncStreamTransportTestAgent::AGENT_TYPE, null));
        $there->sendFrame(new PeerStopAgentDTO(RtSyncStreamTransportTestAgent::AGENT_TYPE, null));
        $there->write();

        $this->assertSame([PeerRtSyncStreamDTO::class], $this->wireFrames($socket), 'The order and the stop wait for the write\'s last part');

        $there->rtSyncStream()->receive(RtSyncStreamFrame::ack($there->rtSyncStream()->streamId, 1, 0, RtSyncStreamStatus::Next));
        $there->write();

        $this->assertSame([PeerRtSyncStreamDTO::class, PeerPlaceAgentDTO::class, PeerStopAgentDTO::class], $this->wireFrames($socket),
            'The order behind the write\'s last part, the stop behind the order');
    }

    /**
     * A node's write too long for one part confirms a row this node's copy holds frozen: the master lifts
     * its own mark, and tells the worker that reads the row only behind the write's last part, so the
     * worker never reads the row as current ahead of the write.
     */
    public function testAThawReachesTheReadingWorkerOnlyBehindTheLastPartOfTheWriteThatGaveIt(): void
    {
        $loop = new EventLoop();
        try {
            new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->master, $loop);
            new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->master);
            [$here, $there] = $this->peerLinks();
            RtStaleness::mark(self::COLLECTION, ['row-1'], microtime(true));
            $this->reader->received = [];

            $there->rtSyncStream()->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1',
                ['blob' => str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES)]), 'node-b');
            $this->movePeerFrames($here, $there, 2);

            $this->assertNull(RtStaleness::staleSince(self::COLLECTION, 'row-1'), 'The master lifted its own mark');
            $this->assertSame([WorkerRtSyncStreamDTO::class, WorkerRtSyncStreamDTO::class, WorkerRtStalenessMessageDTO::class],
                $this->reader->received, 'The reading worker gets both parts of the write before the thaw');
            $this->assertSame([self::COLLECTION, ['row-1'], null],
                [$this->reader->staleness[0]->collectionKey, $this->reader->staleness[0]->stateIds, $this->reader->staleness[0]->since]);
        } finally {
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($this->master);
            $loop->close();
        }
    }

    /**
     * A command's reply a node forwards behind its write, read here in one read while the master is busy
     * and has not read the write's part: the reply waits in the master's effect queue behind that read,
     * as a signal to an agent does, so the page that asked for the command - here an agent's restart - is
     * answered only behind the write.
     */
    public function testAForwardedCommandReplyIsHandledHereOnlyBehindTheReadOfTheWriteSentBeforeIt(): void
    {
        $loop = new EventLoop();
        try {
            new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->master, $loop);
            new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->master);
            [$here, $there] = $this->peerLinks();
            Hilos::$cluster?->registerClientSignalSink($this->master);
            $restarts = new ReflectionProperty(DaemonManager::class, 'pageAgentRestarts')->getValue($this->master);
            $this->assertInstanceOf(PageAgentRestarts::class, $restarts);
            new ReflectionProperty(PageAgentRestarts::class, 'waiters')->setValue($restarts, ['cid-1' => new PageAgentRestartWaiter(
                new DaemonAgentRestartRequestSignalData(RtSyncStreamTransportTestAgent::AGENT_TYPE, 'node-a', 'accept-1', null,
                    RtSyncStreamTransportTestAgent::AGENT_TYPE, null), INF)]);
            $this->reader->received = [];
            $busy = 0;
            SignalDispatcherFixture::queue($this->master, static function () use (&$busy): Generator {
                for (; $busy < 256; $busy++) {
                    yield;
                }
            });

            $there->rtSyncStream()->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1', ['owner' => null]), 'node-b');
            $there->sendFrame($this->commandReplyToNodeA());
            $there->write();
            $here->read();

            $this->assertTrue($here->rtSyncStream()->isReading(), 'The master has not read the part yet');
            $this->assertTrue($restarts->hasWaiter('cid-1'), 'The reply waits behind the read of the part');

            $this->movePeerFrames($here, $there, 1);

            $this->assertFalse($restarts->hasWaiter('cid-1'), 'The reply is handled');
            $this->assertSame([WorkerRtSyncStreamDTO::class, DaemonAgentMessageDTO::class], $this->reader->received,
                'The page\'s agent gets the write before the answer to its restart');
        } finally {
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($this->master);
            $loop->close();
        }
    }

    /**
     * The actual ready/ack switch restarts a queued or partially emitted write on the new socket;
     * the receiving master and reading worker apply its original data once, before the next write.
     *
     * @param bool $partial Whether the old transport already emitted the first part
     */
    #[TestWith([false])]
    #[TestWith([true])]
    public function testReplacementDeliversQueuedAndPartialWritesOnTheNewSocket(bool $partial): void
    {
        [$a, $b, $old] = $this->replacementServers();
        if (!$partial) {
            $b->setRtSyncStreamWake(static function (): void {
            });
        }
        $row = ['blob' => str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES)];
        $old[1][0]->rtSyncStream()->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1', $row), 'node-b');
        $old[1][0]->rtSyncStream()->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-2', ['owner' => null]), 'node-b');
        $oldParts = $this->relayReplacement($old[1], $old[0]);
        $this->assertCount($partial ? 1 : 0, $oldParts);
        if ($partial) {
            $this->assertFalse($oldParts[0]->frame->final);
        }
        $b->setRtSyncStreamWake(static function (): void {
        });
        $new = $this->replacementPair($a, $b, true);
        $old[1][0]->close();
        $old[0][0]->close();
        $this->assertSame([], $this->reader->applied, 'The old partial write was never applied');
        $history = [];
        for ($pass = 0; $pass < 32 && count($this->reader->applied) < 2; ++$pass) {
            $this->drain($new[1][0]->rtSyncStream());
            array_push($history, ...$this->relayReplacement($new[1], $new[0]));
            $this->drain($new[0][0]->rtSyncStream());
            $this->master->drain();
            $this->deliverToReader();
            $this->relayReplacement($new[0], $new[1]);
        }
        $this->assertSame(['write row-1', 'write row-2'], $this->reader->applied);
        $this->assertSame($row, RtRowValues::nativeMap($this->reader->delivered[0]->row));
        $parts = array_values(array_filter($history, static fn (PeerDTO $frame): bool => $frame instanceof PeerRtSyncStreamDTO));
        $this->assertCount(3, $parts);
        $this->assertSame([1, 0, false], [$parts[0]->frame->sequence, $parts[0]->frame->part, $parts[0]->frame->final]);
        if ($partial) {
            $this->assertNotSame($oldParts[0]->frame->streamId, $parts[0]->frame->streamId);
            $this->assertSame($oldParts[0]->frame->data, $parts[0]->frame->data);
        }
    }

    /**
     * A wholly sent old final W0 - still on its socket, or accepted and partway through decode - applies on the
     * master's own FIFO before the transferred W1, its waiting signal F1 and the new W2, once each: the new link
     * parses nothing until the old one read the peer's drain marker, as on one connection. The worker sees
     * exactly [W0, W1, F1, W2] when W2 arrives after F1 was handled; with W2 in the same read, F1 still follows W1.
     *
     * @param bool $accepted Whether the old final is accepted and partway through decode
     * @param bool $late Whether W2 is sent only once F1 was parsed and handled
     */
    #[TestWith([false, true])]
    #[TestWith([true, true])]
    #[TestWith([false, false])]
    #[TestWith([true, false])]
    public function testAWhollySentOldFinalAppliesBeforeTheTransferredAndNewWrites(bool $accepted, bool $late): void
    {
        $loop = new EventLoop();
        try {
            new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->master, $loop);
            new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->master);
            [$a, $b, $old] = $this->replacementServers();
            $this->reader->received = [];
            $a->setRtSyncStreamWake($this->master->wakeRtSyncStream(...));
            $b->setRtSyncStreamWake(static function (): void {
            });
            $donor = $old[1][0]->rtSyncStream();
            $donor->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1', ['value' => 0]), 'node-b');
            $final = $this->wholeFinal($old[1]);
            $this->assertSame(strlen($final), socket_write($old[0][1], $final));
            if ($accepted) {
                $old[0][0]->read();
                $this->assertTrue($old[0][0]->rtSyncStream()->pump(new RtWorkBudget(4, 4096)));
                $this->assertTrue($old[0][0]->rtSyncStream()->isReading(), 'The old decode still needs another portion');
            }
            $donor->send(new RtSyncUpdatedSignalData(self::COLLECTION, 'row-1', ['value' => 1], previous: ['value' => 0]), 'node-b');
            $old[1][0]->sendFrame($this->signalToReader());
            $new = $this->replacementPair($a, $b, true);
            $later = new RtSyncUpdatedSignalData(self::COLLECTION, 'row-1', ['value' => 2], previous: ['value' => 1]);
            if (!$late) {
                $new[1][0]->rtSyncStream()->send($later, 'node-b');
            }
            $this->drain($new[1][0]->rtSyncStream());
            $this->assertSame(
                $late ? [PeerRtSyncStreamDTO::class, PeerSignalDTO::class]
                    : [PeerRtSyncStreamDTO::class, PeerSignalDTO::class, PeerRtSyncStreamDTO::class],
                array_map(static fn (PeerDTO $frame): string => $frame::class, $this->relayReplacement($new[1], $new[0])),
            );
            $this->dispatchToReader(3);
            $this->assertNotContains(DaemonAgentMessageDTO::class, $this->reader->received,
                'Before the old link read the peer\'s drain marker, the worker got no F1');
            $this->assertSame($accepted ? [0] : [], $this->deliveredValues(), 'and no W1 either');

            $this->relayReplacement($old[1], $old[0]);
            $new[0][0]->read();
            $this->dispatchToReader($late ? 2 : 3);
            if ($late) {
                $this->assertSame([WorkerRtSyncStreamDTO::class, WorkerRtSyncStreamDTO::class, DaemonAgentMessageDTO::class],
                    $this->reader->received, 'F1 was parsed and handled after W1');
                $new[1][0]->rtSyncStream()->send($later, 'node-b');
                $this->drain($new[1][0]->rtSyncStream());
                $this->relayReplacement($new[1], $new[0]);
                $this->dispatchToReader(4);
            }
            $this->assertSame([0, 1, 2], $this->deliveredValues(), 'W0, W1 and W2 apply in the order sent, once each');
            $this->assertSame(['row-1', 'row-1', 'row-1'], array_column($this->reader->delivered, 'stateId'));
            $this->assertCount(4, $this->reader->received, 'Nothing reaches the worker twice');
            $this->assertGreaterThanOrEqual(2, array_search(DaemonAgentMessageDTO::class, $this->reader->received, true),
                'F1 never reaches the worker before W1');
            if ($late) {
                $this->assertSame([
                    WorkerRtSyncStreamDTO::class, WorkerRtSyncStreamDTO::class,
                    DaemonAgentMessageDTO::class, WorkerRtSyncStreamDTO::class,
                ], $this->reader->received, 'The worker gets W0, W1, F1, W2, as on one connection');
            }
        } finally {
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($this->master);
            $loop->close();
        }
    }

    /** An old final already queued for daemon apply keeps its existing source FIFO position after replacement. */
    public function testAnOldFinalAlreadyQueuedForDaemonApplyKeepsItsFifoAfterReplacement(): void
    {
        $loop = new EventLoop();
        try {
            new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->master, $loop);
            new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->master);
            [$a, $b, $old] = $this->replacementServers();
            $a->setRtSyncStreamWake($this->master->wakeRtSyncStream(...));
            $b->setRtSyncStreamWake(static function (): void {
            });
            $busy = 0;
            SignalDispatcherFixture::queue($this->master, static function () use (&$busy): Generator {
                for (; $busy < 256; ++$busy) {
                    yield;
                }
            });
            $donor = $old[1][0]->rtSyncStream();
            $donor->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1', ['value' => 0]), 'node-b');
            $this->drain($donor);
            $this->relayReplacement($old[1], $old[0]);
            $oldIncoming = $old[0][0]->rtSyncStream();
            $this->assertTrue($oldIncoming->isReading());
            $this->assertTrue($oldIncoming->pump(new RtWorkBudget(4, 4096)), 'Decode consumes portions before apply');
            $this->assertTrue($oldIncoming->isReading());
            $this->drain($oldIncoming);
            $this->assertFalse($oldIncoming->isReading(), 'The host returned null, with its apply still queued');
            $this->assertLessThan(256, $busy);
            $this->assertSame([], $this->reader->delivered);

            $donor->send(new RtSyncUpdatedSignalData(self::COLLECTION, 'row-1', ['value' => 1], previous: ['value' => 0]), 'node-b');
            $new = $this->replacementPair($a, $b, true);
            $new[1][0]->rtSyncStream()->send(
                new RtSyncUpdatedSignalData(self::COLLECTION, 'row-1', ['value' => 2], previous: ['value' => 1]), 'node-b');
            $this->drain($new[1][0]->rtSyncStream());
            $this->relayReplacement($new[1], $new[0]);
            $this->relayReplacement($old[1], $old[0]);
            $new[0][0]->read();
            $this->relayReplacement($old[0], $old[1]);
            $new[1][0]->read();
            $this->drain($new[0][0]->rtSyncStream());
            $this->assertSame([], $this->reader->delivered);
            $oldReference = WeakReference::create($old[0][0]);
            new ReflectionMethod(AbstractServer::class, 'dropClient')->invoke($a, $old[0][0]);
            unset($old[0], $oldIncoming);
            gc_collect_cycles();
            $this->assertNull($oldReference->get(), 'Only incoming channel work survives the donor socket');

            for ($pass = 0; $pass < 128 && count($this->reader->delivered) < 3; ++$pass) {
                new ReflectionMethod(DaemonManager::class, 'dispatchSignalsWithinBudget')->invoke($this->master);
                $this->deliverToReader();
            }
            $this->assertSame([0, 1, 2], array_map(
                static fn (RtSyncStreamOperation $operation): int => RtRowValues::nativeMap($operation->row)['value'],
                $this->reader->delivered,
            ));
            $this->relayReplacement($new[0], $new[1]);
            $this->assertTrue($new[1][0]->rtSyncStream()->isIdle(), 'Transferred waits receive their new-epoch answers');
        } finally {
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($this->master);
            $loop->close();
        }
    }

    /**
     * A third link activated while the second still waits behind the first parses nothing until the second read
     * the peer's drain marker, and the second reads it only once the first read its own: row-1 applies as 0, 1, 2
     * once each, and F1, which waited behind W1, reaches the worker after it.
     */
    public function testAReplacementOfAHeldReplacementWaitsForTheWholeChain(): void
    {
        $loop = new EventLoop();
        try {
            new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->master, $loop);
            new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->master);
            [$a, $b, $old] = $this->replacementServers();
            $this->reader->received = [];
            $a->setRtSyncStreamWake($this->master->wakeRtSyncStream(...));
            $b->setRtSyncStreamWake(static function (): void {
            });
            $donor = $old[1][0]->rtSyncStream();
            $donor->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1', ['value' => 0]), 'node-b');
            $final = $this->wholeFinal($old[1]);
            $this->assertSame(strlen($final), socket_write($old[0][1], $final));
            $donor->send(new RtSyncUpdatedSignalData(self::COLLECTION, 'row-1', ['value' => 1], previous: ['value' => 0]), 'node-b');
            $old[1][0]->sendFrame($this->signalToReader());
            $middle = $this->replacementPair($a, $b, true);
            $this->drain($middle[1][0]->rtSyncStream());
            $this->assertSame([PeerRtSyncStreamDTO::class, PeerSignalDTO::class],
                array_map(static fn (PeerDTO $frame): string => $frame::class, $this->relayReplacement($middle[1], $middle[0])));
            $latest = $this->replacementPair($a, $b, true);
            $latest[1][0]->rtSyncStream()->send(
                new RtSyncUpdatedSignalData(self::COLLECTION, 'row-1', ['value' => 2], previous: ['value' => 1]), 'node-b');
            $this->drain($latest[1][0]->rtSyncStream());
            $this->assertSame([PeerRtSyncStreamDTO::class],
                array_map(static fn (PeerDTO $frame): string => $frame::class, $this->relayReplacement($latest[1], $latest[0])));
            $this->dispatchToReader(4);
            $this->assertSame([], $this->reader->received, 'Neither replacement parses before the first link read the marker');

            $this->assertSame([PeerDrainDTO::class],
                array_map(static fn (PeerDTO $frame): string => $frame::class, $this->relayReplacement($middle[1], $middle[0])));
            $latest[0][0]->read();
            $this->dispatchToReader(4);
            $this->assertSame([], $this->reader->received, 'The second link holds its own marker while it waits for the first');

            $this->relayReplacement($old[1], $old[0]);
            $middle[0][0]->read();
            $latest[0][0]->read();
            $this->dispatchToReader(4);
            $this->assertSame([0, 1, 2], $this->deliveredValues(), 'W0, W1 and W2 apply in the order sent, once each');
            $this->assertCount(4, $this->reader->received, 'Nothing reaches the worker twice');
            $this->assertGreaterThanOrEqual(2, array_search(DaemonAgentMessageDTO::class, $this->reader->received, true),
                'F1 never reaches the worker before W1');
        } finally {
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($this->master);
            $loop->close();
        }
    }

    /**
     * The old link breaks without the peer's drain marker - a reset, a timeout: its close lifts the hold, so the
     * transferred W1 and F1 and the new W2 reach the worker and are answered. W0, lost on the broken socket, is
     * neither applied nor sent again.
     */
    public function testAnOldLinkBrokenWithoutItsMarkerReleasesTheReplacementOnClose(): void
    {
        $loop = new EventLoop();
        try {
            new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($this->master, $loop);
            new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($this->master);
            [$a, $b, $old] = $this->replacementServers();
            $this->reader->received = [];
            $a->setRtSyncStreamWake($this->master->wakeRtSyncStream(...));
            $b->setRtSyncStreamWake(static function (): void {
            });
            $donor = $old[1][0]->rtSyncStream();
            $donor->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1', ['value' => 0]), 'node-b');
            $this->wholeFinal($old[1]);
            $donor->send(new RtSyncUpdatedSignalData(self::COLLECTION, 'row-1', ['value' => 1], previous: ['value' => 0]), 'node-b');
            $old[1][0]->sendFrame($this->signalToReader());
            $new = $this->replacementPair($a, $b, true);
            $new[1][0]->rtSyncStream()->send(
                new RtSyncUpdatedSignalData(self::COLLECTION, 'row-1', ['value' => 2], previous: ['value' => 1]), 'node-b');
            $this->drain($new[1][0]->rtSyncStream());
            $sent = $this->relayReplacement($new[1], $new[0]);
            $this->assertSame([PeerRtSyncStreamDTO::class, PeerSignalDTO::class, PeerRtSyncStreamDTO::class],
                array_map(static fn (PeerDTO $frame): string => $frame::class, $sent), 'W0 is not sent again');
            $this->dispatchToReader(3);
            $this->assertSame([], $this->reader->received, 'The new link waits for the old one');

            $a->dropClient($old[0][0]);
            $b->dropClient($old[1][0]);
            $new[0][0]->read();
            $new[1][0]->read();
            $this->dispatchToReader(3);
            $this->assertSame([1, 2], $this->deliveredValues(), 'The lost W0 is neither applied nor repeated');
            $this->assertCount(3, $this->reader->received, 'Nothing reaches the worker twice');
            $this->assertGreaterThanOrEqual(1, array_search(DaemonAgentMessageDTO::class, $this->reader->received, true),
                'F1 never reaches the worker before W1');
            $this->relayReplacement($new[0], $new[1]);
            $this->assertTrue($new[1][0]->rtSyncStream()->isIdle(), 'Nothing hangs: the new link answered every write');
        } finally {
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($this->master);
            $loop->close();
        }
    }

    /** A wholly sent write is not replayed, and only the transferred write holds the mixed answer wait. */
    public function testReplacementEndsFullySentObligationsButKeepsTheMixedWaitUntilTheNewAnswer(): void
    {
        [$a, $b, $old] = $this->replacementServers();
        $donor = $old[1][0]->rtSyncStream();
        $donor->send(new RtSyncCreatedSignalData(self::COLLECTION, 'sent', ['value' => 0]), 'node-b');
        $this->relayReplacement($old[1], $old[0]);
        $this->master->drain();
        $this->deliverToReader();
        $old[0][0]->write();
        $oldAnswers = $this->wireDtos($old[0][1]);
        $this->assertCount(1, $oldAnswers);
        $this->assertSame(RtSyncStreamStatus::Applied, $oldAnswers[0]->frame->status);
        $told = [];
        $old[1][0]->afterRtWritesAnswered(static function () use (&$told): void {
            $told[] = 'sent only';
        });
        $donor->send(new RtSyncCreatedSignalData(self::COLLECTION, 'transferred',
            ['blob' => str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES)]), 'node-b');
        $oldParts = $this->relayReplacement($old[1], $old[0]);
        $this->assertCount(1, $oldParts);
        $this->assertFalse($oldParts[0]->frame->final);
        $old[1][0]->afterRtWritesAnswered(static function () use (&$told): void {
            $told[] = 'mixed';
        });
        $b->setRtSyncStreamWake(static function (): void {
        });
        $new = $this->replacementPair($a, $b, true);
        $this->assertSame(['sent only'], $told, 'Transfer ends only the wholly sent old obligation');
        $old[1][0]->close();
        $old[0][0]->close();
        $new[0][0]->read();
        $new[1][0]->read();
        $donor->receive($oldAnswers[0]->frame);
        $bytes = $oldAnswers[0]->toJson() . "\n";
        $this->assertSame(strlen($bytes), socket_write($new[1][1], $bytes));
        $new[1][0]->read();
        $this->assertSame(['sent only'], $told, 'Neither old close nor an old-epoch answer ends the mixed wait');
        $successor = $new[1][0]->rtSyncStream();
        $successor->send(new RtSyncCreatedSignalData(self::COLLECTION, 'later', ['value' => 3]), 'node-b');
        $this->drain($successor);
        $first = $this->relayReplacement($new[1], $new[0]);
        $this->assertCount(1, $first);
        $this->assertSame([1, 0, false], [$first[0]->frame->sequence, $first[0]->frame->part, $first[0]->frame->final]);
        $this->drain($new[0][0]->rtSyncStream());
        $this->relayReplacement($new[0], $new[1]);
        $this->drain($successor);
        $tail = $this->relayReplacement($new[1], $new[0]);
        $this->assertCount(2, $tail, 'The successor sends the restarted final part and the later write');
        $this->drain($new[0][0]->rtSyncStream());
        $this->master->drain();
        $this->deliverToReader();
        $this->assertSame(['write sent', 'write transferred', 'write later'], $this->reader->applied,
            'The wholly sent write is applied once, without a replay in the successor epoch');
        $new[0][0]->write();
        $answers = $this->wireDtos($new[0][1]);
        $this->assertCount(2, $answers);
        $this->assertSame([1, 2], [$answers[0]->frame->sequence, $answers[1]->frame->sequence]);
        $this->assertSame(['sent only'], $told, 'Sending and applying still leave the transferred answer outstanding');
        $bytes = $answers[0]->toJson() . "\n";
        $this->assertSame(strlen($bytes), socket_write($new[1][1], $bytes));
        $new[1][0]->read();
        $this->assertSame(['sent only', 'mixed'], $told);
        $this->assertFalse($successor->isIdle(), 'The later write has its own unanswered obligation');
        $bytes = $answers[1]->toJson() . "\n";
        $this->assertSame(strlen($bytes), socket_write($new[1][1], $bytes));
        $new[1][0]->read();
        $this->assertTrue($successor->isIdle());
    }

    /**
     * F1 keeps its W1 boundary rather than waiting for W2/W3. Agent counts and callbacks survive
     * the donor's collection; later stop/restart frames follow the signal on the successor.
     *
     * @param bool $packed Whether fan-out supplies the packed frame
     */
    #[TestWith([false])]
    #[TestWith([true])]
    public function testReplacementRoutesWaitingFramesAndAgentFifoWithoutRetainingTheDonor(bool $packed): void
    {
        [$a, $b, $old] = $this->replacementServers();
        $b->setRtSyncStreamWake(static function (): void {
        });
        $donor = $old[1][0];
        $donor->rtSyncStream()->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1', ['owner' => null]), 'node-b');
        $signal = $this->signalToReader();
        if ($packed) {
            $donor->sendPackedFrame($signal, $signal->toJson());
        } else {
            $donor->sendFrame($signal);
        }
        $donor->rtSyncStream()->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-2',
            ['blob' => str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES)]), 'node-b');
        $donor->sendFrame(PeerAgentStatusDTO::stopped(RtSyncStreamTransportTestAgent::AGENT_TYPE, null));
        $secondSignal = new PeerSignalDTO('node-b', 'node-a', RtSyncStreamTransportTestAgent::AGENT_TYPE, 'other', $signal->signal);
        $donor->sendFrame($secondSignal);
        $new = $this->replacementPair($a, $b, true);
        $new[1][0]->rtSyncStream()->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-3', ['owner' => null]), 'node-b');
        $stop = new PeerStopAgentDTO(RtSyncStreamTransportTestAgent::AGENT_TYPE, null);
        $restart = new PeerAgentRestartDTO('cid-1', 'node-b', RtSyncStreamTransportTestAgent::AGENT_TYPE, null, null, false);
        $secondStop = new PeerStopAgentDTO(RtSyncStreamTransportTestAgent::AGENT_TYPE, 'other');
        $donor->sendPackedFrame($stop, $stop->toJson());
        $donor->sendFrame($restart);
        $donor->sendFrame($secondStop);
        $new[1][0]->sendFrame(new PeerPingDTO());
        $new[1][0]->sendFrame(new PeerHeartbeatDTO(1, 'node-b', true));
        $new[1][0]->write();
        $this->assertSame([PeerPingDTO::class, PeerHeartbeatDTO::class], $this->wireFrames($new[1][1]),
            'Control frames bypass the transferred RT barrier');
        $this->assertSame([PeerDrainDTO::class],
            array_map(static fn (PeerDTO $frame): string => $frame::class, $this->relayReplacement($old[1], $old[0])));
        $new[0][0]->read();
        $weak = WeakReference::create($donor);
        $b->dropClient($donor);
        unset($donor, $old[1]);
        gc_collect_cycles();
        $this->assertNull($weak->get(), 'Waiting callbacks retain their route, never the closed donor');
        $new[1][0]->read();

        $this->drain($new[1][0]->rtSyncStream());
        $first = $this->relayReplacement($new[1], $new[0]);
        $this->assertSame([PeerRtSyncStreamDTO::class, PeerSignalDTO::class, PeerRtSyncStreamDTO::class],
            array_map(static fn (PeerDTO $frame): string => $frame::class, $first));
        $this->assertFalse($first[2]->frame->final, 'F1 is already out while W2 still awaits credit');
        $this->assertSame($signal->toJson(), $first[1]->toJson());
        $this->drain($new[0][0]->rtSyncStream());
        $this->master->drain();
        $this->deliverToReader();
        $this->relayReplacement($new[0], $new[1]);
        $this->drain($new[1][0]->rtSyncStream());
        $new[1][0]->write();
        $last = $this->wireDtos($new[1][1]);
        $this->assertSame(
            [PeerRtSyncStreamDTO::class, PeerAgentStatusDTO::class, PeerSignalDTO::class, PeerRtSyncStreamDTO::class,
                PeerStopAgentDTO::class, PeerAgentRestartDTO::class, PeerStopAgentDTO::class],
            array_map(static fn (PeerDTO $frame): string => $frame::class, $last),
        );
        $this->assertSame($secondSignal->toJson(), $last[2]->toJson());
        $this->assertSame($secondStop->toJson(), $last[6]->toJson(), 'Each agent keeps its own waiting count after the switch');
        $this->assertSame([2, 3], [$last[0]->frame->sequence, $last[3]->frame->sequence]);
    }

    /** Two ready/ack replacements restart a partial write, preserving its frame and waits past both collected donors. */
    public function testRepeatedReplacementCarriesTheCurrentRoutePastCollectedDonors(): void
    {
        [$a, $b, $old] = $this->replacementServers();
        $b->setRtSyncStreamWake(static function (): void {
        });
        $row = ['blob' => str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES)];
        $old[1][0]->rtSyncStream()->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1', $row), 'node-b');
        $old[1][0]->sendFrame($this->signalToReader());
        $told = 0;
        $old[1][0]->afterRtWritesAnswered(static function () use (&$told): void {
            ++$told;
        });
        $this->drain($old[1][0]->rtSyncStream());
        $old[1][0]->write();
        $firstPart = $this->wireDtos($old[1][1])[0]->frame;
        $middle = $this->replacementPair($a, $b, true);
        $this->drain($middle[1][0]->rtSyncStream());
        $middle[1][0]->write();
        $secondPart = $this->wireDtos($middle[1][1])[0]->frame;
        $this->assertSame([false, false], [$firstPart->final, $secondPart->final]);
        $this->assertSame($firstPart->data, $secondPart->data);
        $this->assertNotSame($firstPart->streamId, $secondPart->streamId);
        $latest = $this->replacementPair($a, $b, true);
        $late = 0;
        $old[1][0]->afterRtWritesAnswered(static function () use (&$late): void {
            ++$late;
        });
        foreach ([[$old[1][0], $firstPart], [$middle[1][0], $secondPart]] as [$link, $part]) {
            $answer = RtSyncStreamFrame::ack($part->streamId, $part->sequence, $part->part, RtSyncStreamStatus::Next);
            $link->rtSyncStream()->receive($answer);
            $latest[1][0]->rtSyncStream()->receive($answer);
        }
        unset($link, $part);
        $this->assertSame([0, 0], [$told, $late]);
        $this->relayReplacement($old[1], $old[0]);
        $middle[0][0]->read();
        $this->relayReplacement($middle[1], $middle[0]);
        $latest[0][0]->read();
        $firstWeak = WeakReference::create($old[1][0]);
        $secondWeak = WeakReference::create($middle[1][0]);
        $b->dropClient($old[1][0]);
        $b->dropClient($middle[1][0]);
        $this->assertNotContains($old[1][0], $b->getClients());
        $this->assertNotContains($middle[1][0], $b->getClients());
        $this->assertSame([0, 0], [$told, $late], 'Closing both donors cannot complete the current obligation');
        unset($old[1], $middle[1]);
        gc_collect_cycles();
        $this->assertNull($firstWeak->get());
        $this->assertNull($secondWeak->get());
        $latest[1][0]->read();
        $this->drain($latest[1][0]->rtSyncStream());
        $head = $this->relayReplacement($latest[1], $latest[0]);
        $this->assertCount(1, $head);
        $this->assertFalse($head[0]->frame->final);
        $this->assertSame($firstPart->data, $head[0]->frame->data);
        $this->drain($latest[0][0]->rtSyncStream());
        $this->relayReplacement($latest[0], $latest[1]);
        $this->drain($latest[1][0]->rtSyncStream());
        $sent = $this->relayReplacement($latest[1], $latest[0]);
        $this->assertSame([PeerRtSyncStreamDTO::class, PeerSignalDTO::class],
            array_map(static fn (PeerDTO $frame): string => $frame::class, $sent));
        $this->drain($latest[0][0]->rtSyncStream());
        $this->master->drain();
        $this->deliverToReader();
        $this->relayReplacement($latest[0], $latest[1]);
        $this->assertSame(['write row-1'], $this->reader->applied);
        $this->assertSame($row, RtRowValues::nativeMap($this->reader->delivered[0]->row));
        $this->assertSame([1, 1], [$told, $late]);
    }

    /**
     * The current endpoint's real removal ends transferred obligations once, even after the donors were collected.
     *
     * @param bool $raises Whether the first completion raises a non-exception Throwable
     */
    #[TestWith([false])]
    #[TestWith([true])]
    public function testDroppingTheCurrentReplacementEndsAndReleasesItsStronglyHeldCallbacks(bool $raises): void
    {
        [$a, $b, $old] = $this->replacementServers();
        $b->setRtSyncStreamWake(static function (): void {
        });
        $old[1][0]->rtSyncStream()->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1',
            ['blob' => str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES)]), 'node-b');
        $old[1][0]->sendFrame($this->signalToReader());
        $told = [];
        $failure = new Error('The closing callback failed');
        $old[1][0]->afterRtWritesAnswered(static function () use (&$told, $raises, $failure): void {
            $told[] = 'first';
            if ($raises) {
                throw $failure;
            }
        });
        $obligation = static function () use (&$told): void {
            $told[] = 'owned';
        };
        $held = WeakReference::create($obligation);
        $old[1][0]->afterRtWritesAnswered(static function () use ($obligation): void {
            $obligation();
        });
        unset($obligation);
        $middle = $this->replacementPair($a, $b, true);
        $latest = $this->replacementPair($a, $b, true);
        $old[1][0]->afterRtWritesAnswered(static function () use (&$told): void {
            $told[] = 'late';
        });
        $firstWeak = WeakReference::create($old[1][0]);
        $secondWeak = WeakReference::create($middle[1][0]);
        $b->dropClient($old[1][0]);
        $b->dropClient($middle[1][0]);
        $this->assertNotContains($old[1][0], $b->getClients());
        $this->assertNotContains($middle[1][0], $b->getClients());
        unset($old[1], $middle[1]);
        gc_collect_cycles();
        $this->assertNull($firstWeak->get());
        $this->assertNull($secondWeak->get());
        $this->assertNotNull($held->get(), 'The successor holds the callback obligation independently of both donors');
        $this->assertSame([], $told);

        try {
            $b->dropClient($latest[1][0]);
            $this->assertFalse($raises, 'A callback failure must reach the closer');
        } catch (Error $raised) {
            $this->assertTrue($raises);
            $this->assertSame($failure, $raised);
        }
        $this->assertNotContains($latest[1][0], $b->getClients(), 'Removal finishes even when completion raises');
        $this->assertSame(['first', 'owned', 'late'], $told);
        $this->assertNull($held->get(), 'Completing the obligation releases its strong capture');
        $b->dropClient($latest[1][0]);
        $latest[1][0]->afterRtWritesAnswered(static function () use (&$told): void {
            $told[] = 'closed';
        });
        $b->afterRtWritesAnswered(static function () use (&$told): void {
            $told[] = 'server';
        });
        $this->assertSame(['first', 'owned', 'late', 'closed', 'server'], $told);
        $currentWeak = WeakReference::create($latest[1][0]);
        unset($latest[1]);
        gc_collect_cycles();
        $this->assertNull($currentWeak->get(), 'Pending frame routing cannot retain the closed current socket');
        $this->assertSame([], $this->reader->applied, 'A real close cannot turn an unfinished write into delivery');
    }

    /** A ready wait raises during activation; every ready wait, the drain marker and the transferred tail survive. */
    public function testAReadyCallbackFailureDuringReplacementStillFinishesTheDrainAndActivation(): void
    {
        [$a, $b, $old] = $this->replacementServers();
        $b->setRtSyncStreamWake(static function (): void {
        });
        $old[1][0]->rtSyncStream()->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1', ['owner' => null]), 'node-b');
        $this->drain($old[1][0]->rtSyncStream());
        $failure = new EventLoopException('The ready fact failed');
        $told = [];
        $old[1][0]->afterRtWritesAnswered(static function () use ($failure, &$told): void {
            $told[] = 'raises';
            throw $failure;
        });
        $old[1][0]->afterRtWritesAnswered(static function () use (&$told): void {
            $told[] = 'ready';
        });
        $old[1][0]->rtSyncStream()->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-2', ['owner' => null]), 'node-b');
        $old[1][0]->afterRtWritesAnswered(static function () use (&$told): void {
            $told[] = 'transferred';
        });
        $first = $this->replacementEndpoint($a, 'node-a', 'node-b', true);
        $second = $this->replacementEndpoint($b, 'node-b', 'node-a', false);
        $first[0]->startHandshake();
        $this->relayReplacement($first, $second);
        $this->relayReplacement($second, $first);
        try {
            $this->relayReplacement($first, $second);
            $this->fail('The ready callback failure reaches activation');
        } catch (EventLoopException $raised) {
            $this->assertSame($failure, $raised);
        }
        $this->assertSame(['raises', 'ready'], $told);
        $ack = $this->relayReplacement($second, $first);
        $this->assertEquals([new PeerReadyAckDTO(true), new PeerSourceInterestDTO('node-b', [], [], 0)], $ack);
        $this->relayReplacement($first, $second);
        $old[1][0]->write();
        $this->assertSame([PeerRtSyncStreamDTO::class, PeerDrainDTO::class], $this->wireFrames($old[1][1]));
        $b->dropClient($old[1][0]);
        $this->assertSame(['raises', 'ready'], $told, 'Old close does not end the transferred tail');
        $a->dropClient($old[0][0]);
        $first[0]->read();
        $second[0]->read();
        $this->drain($second[0]->rtSyncStream());
        $this->relayReplacement($second, $first);
        $this->drain($first[0]->rtSyncStream());
        $this->master->drain();
        $this->deliverToReader();
        $this->relayReplacement($first, $second);
        $this->assertSame(['raises', 'ready', 'transferred'], $told);
        $this->assertSame(['write row-2'], $this->reader->applied, 'The fully sent old row was not replayed');
        $second[0]->close();
        $old[1][0]->afterRtWritesAnswered(static function () use (&$told): void {
            $told[] = 'closed';
        });
        $this->assertSame(['raises', 'ready', 'transferred', 'closed'], $told);
    }

    /** No sending direction is opened just to replace an idle link or answer an empty barrier. */
    public function testAnEmptyReplacementDoesNotOpenAnRtStream(): void
    {
        [$a, $b, $old] = $this->replacementServers();
        $new = $this->replacementPair($a, $b, true);
        $told = 0;
        $old[1][0]->afterRtWritesAnswered(static function () use (&$told): void {
            ++$told;
        });
        $stream = new ReflectionProperty(PeerLink::class, 'rtSyncStream');
        $this->assertSame(1, $told);
        $this->assertNull($stream->getValue($old[1][0]));
        $this->assertNull($stream->getValue($new[1][0]));
        $this->relayReplacement($old[0], $old[1]);
        $new[1][0]->read();
        $new[0][0]->rtSyncStream()->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1', ['owner' => null]), 'node-a');
        $this->relayReplacement($new[0], $new[1]);
        $this->assertNotNull($stream->getValue($new[1][0]), 'Receiving a write opened only the incoming direction');
        $third = $this->replacementPair($a, $b, true);
        $this->assertNull($stream->getValue($third[0][0]), 'A fully sent operation does not need a new outgoing epoch');
        $this->assertNull($stream->getValue($third[1][0]), 'An incoming-only channel does not open its successor');
    }

    /** A final drain without a successor still releases waiting frames before its marker and ends answer waits. */
    public function testAFinalDrainReleasesWaitingFramesAndEndsItsAnswers(): void
    {
        [, $there, $socket] = $this->peerLinks();
        $there->rtSyncStream()->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1',
            ['blob' => str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES)]), 'node-b');
        $there->sendFrame($this->signalToReader());
        $there->sendFrame(PeerAgentStatusDTO::stopped(RtSyncStreamTransportTestAgent::AGENT_TYPE, null));
        $told = 0;
        $there->afterRtWritesAnswered(static function () use (&$told): void {
            ++$told;
        });
        $there->beginDrain();
        $there->write();
        $this->assertSame(1, $told);
        $this->assertSame([PeerRtSyncStreamDTO::class, PeerSignalDTO::class, PeerAgentStatusDTO::class, PeerDrainDTO::class],
            $this->wireFrames($socket));
    }

    /**
     * A node waiting for its RT writes to be answered on every link to a peer - here three, one of
     * whose answers is held - is told only once the held link answered too, and once.
     */
    public function testAWaitForRtAnswersEndsOnlyOnceTheLastOfThreeLinksAnswered(): void
    {
        [$remote, $links] = $this->threePeerLinks();
        $answered = 0;
        $remote->afterRtWritesAnswered(static function () use (&$answered): void {
            ++$answered;
        });

        $this->assertSame(0, $answered, 'Every link carries a write not answered yet');
        $this->answerRtWrite($links['node-a']);
        $this->answerRtWrite($links['node-c']);
        $this->assertSame(0, $answered, 'The held link has not answered yet');

        $this->answerRtWrite($links['node-d']);
        $this->assertSame(1, $answered, 'The last link answered, and the wait is told once');
    }

    /** A link that closes brings no more answers, so the wait for its writes ends with it. */
    public function testAClosedLinkEndsTheWaitForItsRtAnswers(): void
    {
        [$remote, $links] = $this->threePeerLinks();
        $answered = 0;
        $remote->afterRtWritesAnswered(static function () use (&$answered): void {
            ++$answered;
        });
        $this->answerRtWrite($links['node-a']);
        $this->answerRtWrite($links['node-c']);

        $links['node-d']->close();
        $this->assertSame(1, $answered, 'The closed link no longer holds the wait');
    }

    /**
     * A wait on a closing link raises - the fact behind it failed: the link still leaves the server's active links,
     * the link's other waits are told all the same, and the failure reaches the one who closed it.
     */
    public function testAWaitThatRaisesAsItsLinkClosesLeavesTheLinkGoneFromTheServer(): void
    {
        [$remote, $links] = $this->threePeerLinks();
        $failure = new EventLoopException('The fact behind the wait failed');
        $told = [];
        $links['node-d']->afterRtWritesAnswered(static function () use ($failure, &$told): void {
            $told[] = 'raises';
            throw $failure;
        });
        $remote->afterRtWritesAnswered(static function () use (&$told): void {
            $told[] = 'every link';
        });
        $this->answerRtWrite($links['node-a']);
        $this->answerRtWrite($links['node-c']);

        try {
            $links['node-d']->close();
            $this->fail('The failure of the wait is not silenced');
        } catch (EventLoopException $raised) {
            $this->assertSame($failure, $raised, 'The failure reaches the one who closed the link');
        }
        $this->assertSame(['raises', 'every link'], $told, 'The closed link\'s other wait is told all the same');

        $remote->afterRtWritesAnswered(static function () use (&$told): void {
            $told[] = 'after the close';
        });
        $this->assertSame(['raises', 'every link', 'after the close'], $told,
            'The closed link is gone from the active links, so a later wait does not hold on its write never answered');
    }

    /** The aggregate and the real placement stopped consumer wait through replacement, but not for W2. */
    public function testReplacementKeepsTheAggregateAndStoppedReportBehindTheTransferredAnswer(): void
    {
        [$b, $links] = $this->threePeerLinks();
        $active = new ReflectionProperty(PeerServer::class, 'activeLinks');
        $active->setValue($b, array_diff_key($links, ['node-a' => true]));
        $a = $this->replacementServer('node-a');
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerRtSyncSink($this->master);
        $b->setRtSyncStreamWake(static function (): void {
        });
        $old = $this->replacementPair($a, $b, false);
        $donor = $old[1][0]->rtSyncStream();
        $donor->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1', ['owner' => null]), 'node-b');
        $answered = 0;
        $b->afterRtWritesAnswered(static function () use (&$answered): void {
            ++$answered;
        });
        $placement = new ClusterPlacement('node-b', $b, $this->master->worker);
        new ReflectionProperty(ClusterPlacement::class, 'hosted')->setValue($placement, [RtSyncStreamTransportTestAgent::AGENT_TYPE => 2]);
        new ReflectionProperty(ClusterPlacement::class, 'placingLeaderId')->setValue($placement, 'node-a');
        $placement->noteAgentStopped(RtSyncStreamTransportTestAgent::AGENT_TYPE, null);
        $this->answerRtWrite($links['node-c']);
        $this->answerRtWrite($links['node-d']);
        $new = $this->replacementPair($a, $b, true);
        $late = 0;
        $old[1][0]->afterRtWritesAnswered(static function () use (&$late): void {
            ++$late;
        });
        $old[1][0]->close();
        $old[0][0]->close();
        $new[0][0]->read();
        $new[1][0]->read();
        $donor->receive(RtSyncStreamFrame::ack($donor->streamId, 1, 0, RtSyncStreamStatus::Applied));
        $this->assertSame([0, 0], [$answered, $late], 'Neither old close nor late old ack ends the transferred wait');
        $this->assertSame($new[1][0], $active->getValue($b)['node-a']);
        $new[1][0]->rtSyncStream()->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-2', ['owner' => null]), 'node-b');
        $this->drain($new[1][0]->rtSyncStream());
        $sent = $this->relayReplacement($new[1], $new[0]);
        $this->assertSame([PeerRtSyncStreamDTO::class, PeerRtSyncStreamDTO::class],
            array_map(static fn (PeerDTO $frame): string => $frame::class, $sent), 'Stopped is still held');
        $this->drain($new[0][0]->rtSyncStream());
        $this->master->drain();
        $new[0][0]->write();
        $answers = $this->wireDtos($new[0][1]);
        $this->assertCount(2, $answers);
        $this->assertSame([1, 2], [$answers[0]->frame->sequence, $answers[1]->frame->sequence]);
        $bytes = $answers[0]->toJson() . "\n";
        $this->assertSame(strlen($bytes), socket_write($new[1][1], $bytes));
        $new[1][0]->read();
        $this->assertSame([1, 1], [$answered, $late], 'The later unacknowledged write does not extend the old waits');
        $reports = $this->relayReplacement($new[1], $new[0]);
        $this->assertEquals([PeerAgentStatusDTO::stopped(RtSyncStreamTransportTestAgent::AGENT_TYPE, null)], $reports,
            'The actual placement stop consumer reports only after every transferred answer');
    }

    /**
     * A wait on a link beginning to drain raises - the fact behind it failed: the drain still goes on, so the
     * linked node is told the route ends, the link's other waits are told all the same, and the failure reaches
     * the one who began the drain.
     */
    public function testAWaitThatRaisesAsItsLinkBeginsToDrainStillTellsTheLinkedNode(): void
    {
        [$remote, $links] = $this->threePeerLinks();
        $linkedNodeEnd = $this->sockets[array_key_last($this->sockets)];
        $failure = new EventLoopException('The fact behind the wait failed');
        $told = [];
        $links['node-d']->afterRtWritesAnswered(static function () use ($failure, &$told): void {
            $told[] = 'raises';
            throw $failure;
        });
        $remote->afterRtWritesAnswered(static function () use (&$told): void {
            $told[] = 'every link';
        });
        $this->answerRtWrite($links['node-a']);
        $this->answerRtWrite($links['node-c']);

        try {
            $links['node-d']->beginDrain();
            $this->fail('The failure of the wait is not silenced');
        } catch (EventLoopException $raised) {
            $this->assertSame($failure, $raised, 'The failure reaches the one who began the drain');
        }
        $this->assertSame(['raises', 'every link'], $told, 'The draining link\'s other wait is told all the same');

        $links['node-d']->write();
        $this->assertSame([PeerRtSyncStreamDTO::class, PeerDrainDTO::class], $this->wireFrames($linkedNodeEnd),
            'The linked node is told the route ends all the same, behind the write already out');
    }

    /** @return array{PeerServer, PeerServer, array{array{PeerLink, Socket}, array{PeerLink, Socket}}} Real initial handshake */
    private function replacementServers(): array
    {
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerRtSyncSink($this->master);
        Hilos::$cluster->registerAgentSignalSink($this->master);
        $a = $this->replacementServer('node-a');
        $b = $this->replacementServer('node-b');
        $this->hostOnReader();

        return [$a, $b, $this->replacementPair($a, $b, false)];
    }

    /**
     * @param string $nodeId Certified identity
     * @return PeerServer Real owner, without timers or another process
     */
    private function replacementServer(string $nodeId): PeerServer
    {
        return new PeerServer('127.0.0.1', 0, NodeIdentity::of($nodeId, $nodeId, NodeRole::Master, []),
            [], PeerTestTls::unread(), PeerTestMarkers::shared());
    }

    /**
     * @param PeerServer $a First owner
     * @param PeerServer $b Second owner
     * @param bool $replacement Whether A dials the preferred replacement
     * @return array{array{PeerLink, Socket}, array{PeerLink, Socket}} Endpoints with relay sockets
     */
    private function replacementPair(PeerServer $a, PeerServer $b, bool $replacement): array
    {
        $first = $this->replacementEndpoint($a, 'node-a', 'node-b', $replacement);
        $second = $this->replacementEndpoint($b, 'node-b', 'node-a', !$replacement);
        [$dialer, $acceptor] = $replacement ? [$first, $second] : [$second, $first];
        $dialer[0]->startHandshake();
        $this->assertInstanceOf(PeerHelloDTO::class, $this->relayReplacement($dialer, $acceptor)[0]);
        $this->assertInstanceOf(PeerWelcomeDTO::class, $this->relayReplacement($acceptor, $dialer)[0]);
        $this->assertEquals([new PeerReadyDTO($replacement)], $this->relayReplacement($dialer, $acceptor));
        $ack = $this->relayReplacement($acceptor, $dialer);
        $this->assertEquals(new PeerReadyAckDTO(true), $ack[0]);
        if ($replacement) {
            $this->assertEquals([new PeerReadyAckDTO(true), new PeerSourceInterestDTO('node-b', [], [], 0)], $ack);
            $this->assertEquals([new PeerSourceInterestDTO('node-a', [], [], 0)], $this->relayReplacement($dialer, $acceptor));
        } else {
            $this->relayReplacement($dialer, $acceptor);
        }

        return [$first, $second];
    }

    /**
     * @param PeerServer $server Real owner
     * @param string $local Local identity
     * @param string $remote Certified peer
     * @param bool $dialer Direction of the handshake
     * @return array{PeerLink, Socket} Endpoint and byte relay
     */
    private function replacementEndpoint(PeerServer $server, string $local, string $remote, bool $dialer): array
    {
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        foreach ($pair as $socket) {
            $this->assertTrue(socket_set_nonblock($socket));
        }
        $this->sockets[] = $pair[1];
        $link = new PeerLink($pair[0], $server, NodeIdentity::of($local, $local, NodeRole::Master, []), $dialer,
            new NamedPeerTestTransport($pair[0], $remote));
        new ReflectionMethod(PeerServer::class, 'addClient')->invoke($server, $link);
        new ReflectionMethod(PeerServer::class, 'attachClient')->invoke($server, $link);

        return [$link, $pair[1]];
    }

    /**
     * @param array{PeerLink, Socket} $from Sending endpoint
     * @param array{PeerLink, Socket} $to Actual receiving parser
     * @return list<PeerDTO> Frames delivered byte for byte
     */
    private function relayReplacement(array $from, array $to): array
    {
        $from[0]->write();
        $bytes = '';
        while (($chunk = socket_read($from[1], 65536)) !== false && $chunk !== '') {
            $bytes .= $chunk;
        }
        if ($bytes !== '') {
            $this->assertSame(strlen($bytes), socket_write($to[1], $bytes));
        }
        $to[0]->read();

        return array_map(PeerDTO::fromWire(...),
            array_values(array_filter(explode("\n", $bytes), static fn (string $line): bool => $line !== '')));
    }

    /**
     * Sends the endpoint's queued write whole, in one final part, and takes it off the wire before anyone reads it.
     *
     * @param array{PeerLink, Socket} $from Sending endpoint, its stream holding one write
     * @return string Wire bytes of the final part, for the case to deliver or to lose
     */
    private function wholeFinal(array $from): string
    {
        $this->drain($from[0]->rtSyncStream());
        $from[0]->write();
        $final = $this->wireDtos($from[1]);
        $this->assertCount(1, $final);
        $this->assertTrue($final[0]->frame->final);

        return $final[0]->toJson() . "\n";
    }

    /**
     * This node's master end of a peer link to node B, and node B's end of it, over a real socket
     * pair; the worker that reads the stream's collection hosts the agent the node signals.
     *
     * @return array{PeerLink, PeerLink, Socket, PeerServer, PeerServer} This node's link, node B's link, this node's
     *         socket, node B's peer server and this node's
     */
    private function peerLinks(): array
    {
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerRtSyncSink($this->master);
        Hilos::$cluster->registerAgentSignalSink($this->master);
        $peer = new PeerServer('127.0.0.1', 0, NodeIdentity::of('node-a', 'Node A', NodeRole::Master, []),
            [], PeerTestTls::unread(), PeerTestMarkers::shared());
        $this->master->registerServer($peer);
        $remote = new PeerServer('127.0.0.1', 0, NodeIdentity::of('node-b', 'Node B', NodeRole::Master, []),
            [], PeerTestTls::unread(), PeerTestMarkers::shared());
        $pair = [];
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        foreach ($pair as $socket) {
            $this->assertTrue(socket_set_nonblock($socket));
            $this->sockets[] = $socket;
        }
        $here = new PeerLink($pair[0], $peer, NodeIdentity::of('node-a', 'Node A', NodeRole::Master, []), false,
            new PlainSocketTransport($pair[0]));
        new ReflectionProperty(PeerLink::class, 'remoteIdentity')->setValue($here, NodeIdentity::of('node-b', 'Node B', NodeRole::Master, []));
        $there = new PeerLink($pair[1], $remote, NodeIdentity::of('node-b', 'Node B', NodeRole::Master, []), true,
            new PlainSocketTransport($pair[1]));
        new ReflectionProperty(PeerLink::class, 'remoteIdentity')->setValue($there, NodeIdentity::of('node-a', 'Node A', NodeRole::Master, []));

        $this->hostOnReader();

        return [$here, $there, $pair[0], $remote, $peer];
    }

    /**
     * Node B's peer server with an active link to each of three nodes over real socket pairs, and a
     * write of node B on each link's stream, out whole and waiting for the answer the case gives by
     * hand. A link's own end of its pair is the link's to close: a case may close it.
     *
     * @return array{PeerServer, array<string, PeerLink>} Node B's peer server and its links, by the node each reaches
     */
    private function threePeerLinks(): array
    {
        $remote = new PeerServer('127.0.0.1', 0, NodeIdentity::of('node-b', 'Node B', NodeRole::Master, []),
            [], PeerTestTls::unread(), PeerTestMarkers::shared());
        $links = [];
        foreach (['node-a' => 'Node A', 'node-c' => 'Node C', 'node-d' => 'Node D'] as $nodeId => $nodeName) {
            $pair = [];
            $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
            foreach ($pair as $socket) {
                $this->assertTrue(socket_set_nonblock($socket));
            }
            $this->sockets[] = $pair[0];
            $link = new PeerLink($pair[1], $remote, NodeIdentity::of('node-b', 'Node B', NodeRole::Master, []), true,
                new PlainSocketTransport($pair[1]));
            new ReflectionProperty(PeerLink::class, 'remoteIdentity')->setValue($link, NodeIdentity::of($nodeId, $nodeName, NodeRole::Master, []));
            $link->rtSyncStream()->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1', ['owner' => null]), 'node-b');
            $links[$nodeId] = $link;
        }
        new ReflectionProperty(PeerServer::class, 'activeLinks')->setValue($remote, $links);

        return [$remote, $links];
    }

    /** @param PeerLink $link Link whose linked node answers the write on its stream applied */
    private function answerRtWrite(PeerLink $link): void
    {
        $link->rtSyncStream()->receive(RtSyncStreamFrame::ack($link->rtSyncStream()->streamId, 1, 0, RtSyncStreamStatus::Applied));
    }

    /** Starts the probe agent on the reading worker, in the roster of the worker server the master built. */
    private function hostOnReader(): void
    {
        $agents = $this->master->getAgentManagerDaemon();
        // What the worker server is built with, as the master builds it.
        new ReflectionProperty(WorkerServer::class, 'agentManager')->setValue($this->master->worker, $agents);
        $agent = new RtSyncStreamTransportTestAgent();
        $agent->setWorkerClient($this->readerLink);
        $agents->addAgent(RtSyncStreamTransportTestAgent::AGENT_TYPE, $agent, 2, false);
        $agents->handleAgentStarted(new WorkerAgentStartedDTO(RtSyncStreamTransportTestAgent::AGENT_TYPE,
            RtSyncStreamTransportTestAgent::AGENT_TYPE));
    }

    /** @return PeerCommandReplyDTO Node B's refusal of the command `cid-1`, forwarded to node A */
    private function commandReplyToNodeA(): PeerCommandReplyDTO
    {
        return new PeerCommandReplyDTO('node-b', 'node-a', new SignalDTO(
            new SignalSource(SignalSource::AGENT, RtSyncStreamTransportTestAgent::AGENT_TYPE),
            new SignalType(SignalTypeConstants::COMMAND_REPLY), new SignalName('cid-1'), CommandReplyDTO::error('cid-1', 'refused'),
        ));
    }

    /** @return PeerSignalDTO A signal from node B to the agent the reading worker hosts */
    private function signalToReader(): PeerSignalDTO
    {
        return new PeerSignalDTO('node-b', 'node-a', RtSyncStreamTransportTestAgent::AGENT_TYPE, null, new SignalDTO(
            new SignalSource(SignalSource::DAEMON), new SignalType(SignalTypeConstants::SYSTEM), new SignalName('after-the-write'),
            new SignalData(),
        ));
    }

    /**
     * Moves frames both ways between the two nodes, through this node's master passes, until the
     * reading worker took the write and the signal.
     *
     * @param PeerLink $here This node's link
     * @param PeerLink $there Node B's link
     * @param int $parts Parts the reading worker gets the write in
     */
    private function movePeerFrames(PeerLink $here, PeerLink $there, int $parts): void
    {
        $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignalsWithinBudget');
        for ($pass = 0; $pass < 256 && count($this->reader->received) < $parts + 1; $pass++) {
            $there->write();
            $here->read();
            $dispatch->invoke($this->master);
            $here->write();
            $there->read();
            $this->deliverToReader();
        }
    }

    /**
     * @param Socket $socket One end of the pair, this node's unless a case reads what this node wrote
     * @return list<class-string<PeerDTO>> Frames the other end wrote so far, in order
     */
    private function wireFrames(Socket $socket): array
    {
        return array_map(static fn (PeerDTO $frame): string => $frame::class, $this->wireDtos($socket));
    }

    /**
     * @param Socket $socket One end of the pair, this node's unless a case reads what this node wrote
     * @return list<PeerDTO> Frames the other end wrote so far, in order
     */
    private function wireDtos(Socket $socket): array
    {
        $bytes = '';
        while (($chunk = socket_read($socket, 65536)) !== false && $chunk !== '') {
            $bytes .= $chunk;
        }

        return array_map(static fn (string $line): PeerDTO => PeerDTO::fromWire($line),
            array_values(array_filter(explode("\n", $bytes), static fn (string $line): bool => $line !== '')));
    }

    /**
     * A master-side worker link over a real socket pair, registered by its own frame.
     *
     * @param int $workerIndex Index the worker registers under
     * @return array{RtSyncStreamTransportTestWorker, WorkerClient} Worker end and the master's link
     */
    private function link(int $workerIndex): array
    {
        $pair = [];
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        foreach ($pair as $socket) {
            $this->assertTrue(socket_set_nonblock($socket));
            $this->sockets[] = $socket;
        }
        $worker = new RtSyncStreamTransportTestWorker($pair[0]);
        $link = new WorkerClient($pair[1], $this->master->getAgentManagerDaemon(), $this->master->worker);
        $link->setRtSyncStreamWake($this->master->wakeRtSyncStream(...));
        // What the worker server hands a link it accepts, as it was handed it by the master.
        $link->setRtSyncStreamHold(new ReflectionProperty(WorkerServer::class, 'rtSyncStreamHold')->getValue($this->master->worker));
        $this->master->worker->adopt($link);
        $worker->send(new WorkerRegisterDTO($workerIndex, false));
        $link->read();
        $this->assertTrue($link->isRegistered());
        $link->write();
        $worker->read();

        return [$worker, $link];
    }

    /**
     * The worker streams a row too long for one part; the master's end of the stream answers on its own pump, which
     * the loop runs ahead of the dispatch, until the worker has written the last part.
     *
     * @param RtSyncStreamTransportTestWorker $source Worker end of the link
     * @param WorkerClient $sourceLink The master's end of it
     */
    private function streamTwoPartWrite(RtSyncStreamTransportTestWorker $source, WorkerClient $sourceLink): void
    {
        $source->stream->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-1',
            ['blob' => str_repeat('x', RtSyncStreamFrame::MAX_PART_BYTES)]));
        for ($pass = 0; $pass < 64 && !end($source->written)->final; $pass++) {
            $sourceLink->read();
            $this->drain($sourceLink->rtSyncStream());
            $sourceLink->write();
            $source->read();
        }
    }

    /**
     * @param string $name Name of the answer
     * @param string $acceptKey Socket it is addressed to
     * @param SignalDataInterface $data What it says
     * @return WorkerAgentMessageDTO An agent's answer to one browser, as the worker writes it to its master
     */
    private function answerSocket(string $name, string $acceptKey, SignalDataInterface $data): WorkerAgentMessageDTO
    {
        return new WorkerAgentMessageDTO(RtSyncStreamTransportTestAgent::AGENT_TYPE, new SignalDTO(
            new SignalSource(SignalSource::AGENT, RtSyncStreamTransportTestAgent::AGENT_TYPE),
            new SignalType(SignalTypeConstants::WS_USER), new SignalName($name), new WebSocketSignalData($data, $acceptKey),
        ));
    }

    /**
     * @param string $signalType One of the SignalTypeConstants values
     * @return bool Whether the master's router holds a signal of the type it has not dispatched yet
     */
    private function routerHolds(string $signalType): bool
    {
        foreach (new ReflectionProperty(SignalRouter::class, 'queuedSignals')->getValue(Hilos::$sr) as $entry) {
            if ($entry instanceof SignalDTO && $entry->signalType->getType() === $signalType) {
                return true;
            }
        }

        return false;
    }

    /** Moves parts to the reading worker and its answers back until it has taken what was queued. */
    private function deliverToReader(): void
    {
        for ($round = 0; $round < 64 && $this->readerLink->hasPendingWrite(); $round++) {
            $this->readerLink->write();
            $this->reader->read();
            $this->readerLink->read();
        }
    }

    /**
     * Runs this node's master passes over its own FIFO and moves what they queued to the reading worker, until the
     * worker took the given number of frames or the passes run out.
     *
     * @param int $frames Frames the reading worker is to have taken
     */
    private function dispatchToReader(int $frames): void
    {
        $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignalsWithinBudget');
        for ($pass = 0; $pass < 128 && count($this->reader->received) < $frames; ++$pass) {
            $dispatch->invoke($this->master);
            $this->deliverToReader();
        }
    }

    /** @return list<int> Values of row-1 in the order the reading worker took its operations */
    private function deliveredValues(): array
    {
        return array_map(static fn (RtSyncStreamOperation $operation): int => RtRowValues::nativeMap($operation->row)['value'],
            $this->reader->delivered);
    }

    /**
     * @param RtSyncStreamChannel $channel End to advance until only credit or input moves it
     */
    private function drain(RtSyncStreamChannel $channel): void
    {
        while ($channel->pump(new RtWorkBudget())) {
        }
    }

    /** @return RtSyncStreamFrame A whole first part of a stream nobody on these links opened */
    private function staleFrame(): RtSyncStreamFrame
    {
        $frames = [];
        $other = new RtSyncStreamChannel(
            static function (RtSyncStreamFrame $frame) use (&$frames): void {
                $frames[] = $frame;
            },
            static function (): void {
            },
        );
        $other->send(new RtSyncCreatedSignalData(self::COLLECTION, 'row-9', ['blob' => 'stale']));

        return $frames[0];
    }
}

/**
 * The worker end of one link: its own stream automaton over the real socket and the worker factory.
 */
final class RtSyncStreamTransportTestWorker
{
    public readonly RtSyncStreamChannel $stream;
    /** @var list<RtSyncStreamFrame> Parts this end wrote */
    public array $written = [];
    /** @var list<RtSyncStreamFrame> Answers this end read */
    public array $answers = [];
    /** @var list<RtSyncStreamOperation> Operations this end applied */
    public array $delivered = [];
    /** @var list<string> What this end applied, in order: a write by its row, a snapshot by its collection */
    public array $applied = [];
    /** @var list<class-string<WorkerDTO>> Parts and other frames this end read, in order; answers are not counted */
    public array $received = [];
    /** @var list<WorkerRtStalenessMessageDTO> Freshness frames this end read, in order */
    public array $staleness = [];
    /** @var list<string> Agents whose stop this end read, in order */
    public array $stopped = [];
    private string $buffer = '';

    /**
     * @param Socket $socket Worker end of the pair
     */
    public function __construct(private readonly Socket $socket)
    {
        $this->stream = new RtSyncStreamChannel(
            function (RtSyncStreamFrame $frame): void {
                if ($frame->isPart()) {
                    $this->written[] = $frame;
                }
                $this->writeRaw(new WorkerRtSyncStreamDTO($frame));
            },
            function (RtSyncStreamOperation|RtSyncStreamSnapshot $operation, Closure $settled): ?Generator {
                if ($operation instanceof RtSyncStreamSnapshot) {
                    return $this->take($operation, $settled);
                }
                $this->delivered[] = $operation;
                $this->applied[] = 'write ' . $operation->stateId;
                $settled(true);

                return null;
            },
        );
    }

    /**
     * Takes a snapshot's rows as they are read, and answers it once it is whole.
     *
     * @param RtSyncStreamSnapshot $snapshot Snapshot handed over at its head
     * @param Closure(bool): void $settled Its answer
     * @return Generator<int, RtWork, int, void> The taking, on the stream's allowance
     */
    private function take(RtSyncStreamSnapshot $snapshot, Closure $settled): Generator
    {
        while (!$snapshot->isWhole() || $snapshot->hasRows()) {
            $snapshot->takeRows();
            yield new RtWork();
        }
        $this->applied[] = 'snapshot ' . $snapshot->collectionKey;
        $settled(true);
    }

    /**
     * @param WorkerDTO $message Message the worker sends its master
     */
    public function send(WorkerDTO $message): void
    {
        $this->writeRaw($message);
    }

    /**
     * @param WorkerDTO $message One frame, written whole to the socket
     */
    public function writeRaw(WorkerDTO $message): void
    {
        $bytes = $message->toJson() . "\n";
        TestCase::assertSame(strlen($bytes), socket_write($this->socket, $bytes));
    }

    /** Reads what the master wrote, through the worker factory; stream frames go to this end's automaton. */
    public function read(): void
    {
        while (($bytes = socket_read($this->socket, 65536)) !== false && $bytes !== '') {
            $this->buffer .= $bytes;
        }
        while (($end = strpos($this->buffer, "\n")) !== false) {
            $message = WorkerDTO::factoryWorkerDTO(substr($this->buffer, 0, $end));
            $this->buffer = substr($this->buffer, $end + 1);
            if (!$message instanceof WorkerRtSyncStreamDTO || $message->frame->isPart()) {
                $this->received[] = $message::class;
            }
            if ($message instanceof WorkerRtStalenessMessageDTO) {
                $this->staleness[] = $message;
            }
            if ($message instanceof AgentStopDTO) {
                $this->stopped[] = $message->agentId;
            }
            if ($message instanceof WorkerRtSyncStreamDTO) {
                if (!$message->frame->isPart()) {
                    $this->answers[] = $message->frame;
                }
                $this->stream->receive($message->frame);
            }
        }
    }
}

/**
 * A real master without processes: its router, agent manager, dispatch and worker server.
 */
final class RtSyncStreamTransportTestManager extends DaemonManager
{
    public RtSyncStreamTransportTestWorkerServer $worker;

    /** The master's own router, which writes down what it queues */
    public RtSyncStreamTransportTestRouter $router;

    public function __construct()
    {
        parent::__construct();
        $this->worker = new RtSyncStreamTransportTestWorkerServer($this->getAgentManagerDaemon());
        $this->registerServer($this->worker);
    }

    /** Runs the same dispatch pass the daemon's loop runs. */
    public function drain(): void
    {
        new ReflectionMethod(DaemonManager::class, 'dispatchSignals')->invoke($this);
    }

    /** @return SignalRouter The master's own router */
    protected function createSignalRouter(): SignalRouter
    {
        $this->router = new RtSyncStreamTransportTestRouter();

        return $this->router;
    }

    /** @return AgentManagerDaemon Empty roster without project factories */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new RtSyncStreamTransportTestAgents();
    }
}

/**
 * The master's router, writing down what it queues.
 */
final class RtSyncStreamTransportTestRouter extends SignalRouter
{
    /** @var list<string> What it queued, in order: an answer to one browser by name and socket, any other signal by type */
    public array $queued = [];

    public function queueSignal(
        SignalSourceInterface $signalSource,
        SignalTypeInterface $signalType,
        SignalNameInterface $signalName,
        SignalDataInterface $signalData,
    ): void {
        $this->queued[] = $signalType->getType() === SignalTypeConstants::WS_USER && $signalData instanceof WebSocketSignalData
            ? $signalName->getName() . ' ' . $signalData->targetAcceptKey
            : $signalType->getType();
        parent::queueSignal($signalSource, $signalType, $signalName, $signalData);
    }
}

final class RtSyncStreamTransportTestAgents extends AgentManagerDaemon
{
    /**
     * @param string $agentType Requested type
     * @param ?string $agentIndex Requested index
     * @return AgentDaemonInterface The probe agent, the one type these transport tests start
     * @throws AgentDaemonCreationFailedException If a test unexpectedly starts another agent
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        if ($agentType === RtSyncStreamTransportTestAgent::AGENT_TYPE && $agentIndex === null) {
            return new RtSyncStreamTransportTestAgent();
        }

        throw new AgentDaemonCreationFailedException('Unexpected test agent start');
    }
}

final class RtSyncStreamTransportTestWorkerServer extends WorkerServer
{
    /**
     * @param AgentManagerDaemon $agents The master's roster, which the worker server starts and stops agents in
     */
    public function __construct(AgentManagerDaemon $agents)
    {
        // What the daemon's constructor hands it, without the process environment the rest of it reads.
        new ReflectionProperty(WorkerServer::class, 'agentManager')->setValue($this, $agents);
    }

    /**
     * @param WorkerClient $client Link the master dispatch walks
     */
    public function adopt(WorkerClient $client): void
    {
        $this->addClient($client);
    }

    /**
     * @param string $agentType Singleton agent the master stops on its worker
     */
    public function stopHere(string $agentType): void
    {
        $this->stopAgent($agentType);
    }

    /**
     * @param string $agentType Singleton agent the master starts, or holds for the report of its earlier stop
     */
    public function startHere(string $agentType): void
    {
        $this->startAgent($agentType);
    }

    /** Runs the pass a fact releases starts held for a stop report to, which the loop runs in the daemon. */
    public function advanceStartsAwaitingStopReport(): void
    {
        new ReflectionMethod(WorkerServer::class, 'advanceAgentsAwaitingStopReport')->invoke($this);
    }

    protected function onStart(): void
    {
    }
}

final class RtSyncStreamTransportTestAgent extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = 'stream_signal_probe';

    /** What it writes down when the master handles its start */
    public const string STARTED = 'started';

    /** What it writes down when the master handles its stop, its link's stream through with every part it took */
    public const string STOPPED = 'stopped';

    /** What it writes down when the master handles its stop while its link's stream still reads a part */
    public const string STOPPED_WHILE_READING = 'stopped while reading';

    /** @var list<string> What the master told it, in order */
    public array $heard = [];

    public function onStart(): void
    {
        $this->heard[] = self::STARTED;
        parent::onStart();
    }

    public function onStop(): void
    {
        $this->heard[] = $this->getWorkerClient()->rtSyncStream()->isReading() ? self::STOPPED_WHILE_READING : self::STOPPED;
        parent::onStop();
    }

    /** @return bool This fixture lives on the reading worker's link without a process of its own */
    public function requiresMonopolisticProcess(): bool
    {
        return false;
    }
}

/**
 * The project facade of the case whose probe agent is declared for every node, so the node starts it again by itself
 * once the worker hosting it is gone.
 */
abstract class RtSyncStreamTransportTestHilos extends Hilos
{
    public const array AGENTS = [
        RtSyncStreamTransportTestAgent::AGENT_TYPE => [AgentRegistryKey::SCOPE => AgentScope::NODE],
    ];
}

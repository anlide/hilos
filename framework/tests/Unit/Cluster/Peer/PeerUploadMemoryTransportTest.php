<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Hilos\Cluster\AgentSignalSink;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerSignalDTO;
use Hilos\Cluster\Peer\DTO\PeerUploadAcceptedDTO;
use Hilos\Cluster\Peer\DTO\PeerUploadGrantedDTO;
use Hilos\Cluster\Peer\DTO\PeerUploadReleaseDTO;
use Hilos\Cluster\Peer\DTO\PeerUploadReserveDTO;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\SignalName;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Environment\EnvAccessor;
use Hilos\Files\Upload\DTO\UploadMemoryReservationData;
use Hilos\Files\Upload\UploadFrame;
use Hilos\Files\Upload\UploadMemoryMaster;
use Hilos\Files\Upload\UploadMemoryPeer;
use Hilos\Files\Upload\UploadMemoryPool;
use Hilos\Files\Upload\UploadMemoryRemoteUpload;
use Hilos\Files\Upload\UploadMemoryTestDelivery;
use Hilos\Hilos;
use Hilos\Socket\Client\AbstractClient;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\Transport\PlainSocketTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Socket;

/** In-process protocol proof over actual PeerLink sockets; real multi-node fault proof belongs to slice 10. */
final class PeerUploadMemoryTransportTest extends TestCase
{
    private ?EnvAccessor $previousEnv;
    private ?ClusterContext $previousCluster;
    private mixed $previousRt;
    private mixed $previousRouter;

    /** @var list<Socket> Test sockets */
    private array $sockets = [];

    /** @var array<string, UploadMemoryMaster> Real node owners */
    private array $masters = [];

    /** @var array<string, PeerServer> Real production send/receive entrypoints */
    private array $servers = [];

    /** @var array<string, PeerLink> Established link ends */
    private array $links = [];

    /** @var array<string, ClusterContext> Per-node delivery sinks */
    private array $contexts = [];

    /** @var array<string, UploadPoolSignalSink> Queued receiver frames */
    private array $sinks = [];

    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        $this->previousCluster = Hilos::$cluster;
        $this->previousRt = Hilos::$rt;
        $this->previousRouter = Hilos::$sr;
        Hilos::$env = new EnvAccessor();
        Hilos::$rt = null;
        Hilos::$sr = null;
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        $this->sockets = $pair;
        foreach (['A', 'B'] as $index => $node) {
            socket_set_nonblock($pair[$index]);
            $remote = $node === 'A' ? 'B' : 'A';
            $master = $this->masters[$node] = new UploadMemoryMaster($node . ':boot');
            $server = $this->servers[$node] = new ReflectionClass(PeerServer::class)->newInstanceWithoutConstructor();
            $identity = NodeIdentity::of($node, NodeRole::Master, []);
            new ReflectionProperty(PeerServer::class, 'localIdentity')->setValue($server, $identity);
            $link = $this->links[$node] = new PeerLink($pair[$index], $server, $identity, false, new PlainSocketTransport($pair[$index]));
            $link->activate(NodeIdentity::of($remote, NodeRole::Master, []));
            new ReflectionProperty(AbstractServer::class, 'clients')->setValue($server, [$link]);
            new ReflectionProperty(PeerServer::class, 'activeLinks')->setValue($server, [$remote => $link]);
            $server->setUploadMemoryMaster($master);
            $context = $this->contexts[$node] = new ReflectionClass(ClusterContext::class)->newInstanceWithoutConstructor();
            $sink = $this->sinks[$node] = new UploadPoolSignalSink();
            $context->registerAgentSignalSink($sink);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        Hilos::$env = $this->previousEnv;
        Hilos::$cluster = $this->previousCluster;
        Hilos::$rt = $this->previousRt;
        Hilos::$sr = $this->previousRouter;
    }

    /** @return iterable<string, array{int}> Increasing completed history on one unchanged link */
    public static function completedUploadCounts(): iterable
    {
        yield 'sixteen' => [16];
        yield 'sixty-four' => [64];
        yield 'two-hundred-fifty-six' => [256];
    }

    /** @param int $uploads Sequential uploads without resetting either peer or pool */
    #[DataProvider('completedUploadCounts')]
    public function testCompletedHistoryIsRetiredOnTheSameLiveLink(int $uploads): void
    {
        $this->masters['A']->setTestLimit(8);
        $this->masters['B']->setTestLimit(8);
        $worker = new ReflectionClass(WorkerClient::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(WorkerClient::class, 'isRegistered')->setValue($worker, true);
        for ($upload = 0; $upload < $uploads; $upload++) {
            $this->enqueue('A', 5);
            $this->move('A');
            $this->move('B');
            $this->masters['A']->afterDispatch();
            $this->move('A');
            self::assertCount(1, $this->sinks['B']->signals);
            $receipt = $this->masters['B']->assignUploadFrame($this->sinks['B']->signals[0]->data, $worker);
            $this->sinks['B']->signals = [];
            $this->masters['B']->onUploadConsumed($worker, [$receipt]);
            $this->move('B');
            $this->masters['A']->afterDispatch();
            $this->masters['B']->afterDispatch();
            foreach (['A', 'B'] as $node) {
                self::assertSame(0, $this->used($node));
                self::assertSame(0, $this->masters[$node]->inspect()['waitingUploads']);
                self::assertSame(0, $this->masters[$node]->inspect()['pendingPeerReservations']);
                self::assertFalse($this->links[$node]->shouldClose());
            }
        }
        $retained = [];
        foreach (['A', 'B'] as $node) {
            $peer = new ReflectionMethod(UploadMemoryMaster::class, 'peer')->invoke($this->masters[$node], $this->links[$node]);
            foreach (['incoming', 'waitingIncoming', 'outgoing', 'received', 'transfers'] as $property) {
                $retained[$node][$property] = count(new ReflectionProperty(UploadMemoryPeer::class, $property)->getValue($peer));
            }
        }
        self::assertSame([
            'A' => ['incoming' => 0, 'waitingIncoming' => 0, 'outgoing' => 0, 'received' => 0, 'transfers' => 0],
            'B' => ['incoming' => 0, 'waitingIncoming' => 0, 'outgoing' => 0, 'received' => 0, 'transfers' => 0],
        ], $retained, 'Retained completed history after ' . $uploads . ' uploads on one live link');
    }

    public function testLargeFileRetiresEveryConsumedWindowBeforeTheFileEnds(): void
    {
        $this->masters['A']->setTestLimit(4);
        $this->masters['B']->setTestLimit(4);
        $pool = new ReflectionProperty(UploadMemoryMaster::class, 'pool')->getValue($this->masters['A']);
        $peer = new ReflectionMethod(UploadMemoryMaster::class, 'peer')->invoke($this->masters['A'], $this->links['A']);
        $remote = new UploadMemoryRemoteUpload($pool, $peer, 'browser', 'file', 1024, true);
        new ReflectionProperty(UploadMemoryMaster::class, 'remoteUploads')->setValue($this->masters['A'], ['browser' => ['file' => $remote]]);
        $input = $this->masters['A']->getUploadInput('browser');
        $worker = new ReflectionClass(WorkerClient::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(WorkerClient::class, 'isRegistered')->setValue($worker, true);
        for ($offset = 0; $offset < 1024; $offset += 4) {
            self::assertNull($remote->reserveNext());
            $this->move('A');
            $this->move('B');
            $window = $remote->reserveNext();
            self::assertNotNull($window);
            self::assertSame($offset + 4, $window->sendUpToBytes);
            $input->allow($window);
            $frame = new WebSocketFrameBinarySignalDTO('browser', "\x04filezzzz");
            $input->observe($frame->payload, strlen($frame->payload));
            $this->masters['A']->onUploadFrameQueued($frame, $input->finishMessage());
            self::assertTrue($this->servers['A']->sendSignalToNode('B', 'probe', '1', new SignalDTO(
                new SignalSource(SignalSource::WEBSOCKET), new SignalType('frame_binary'), new SignalName('frame_binary'), $frame,
            )));
            unset($frame);
            $this->move('A');
            self::assertCount(1, $this->sinks['B']->signals);
            $this->move('B');
            $this->masters['A']->afterDispatch();
            $this->masters['B']->afterDispatch();
            self::assertSame(0, $this->used('A'));
            self::assertSame(4, $this->used('B'), 'Release zero and source acceptance retain receiver work');
            self::assertSame(1, $this->peerMetadataCount('B', 'received'));
            $receipt = $this->masters['B']->assignUploadFrame($this->sinks['B']->signals[0]->data, $worker);
            $this->sinks['B']->signals = [];
            $this->masters['B']->onUploadConsumed($worker, [$receipt, $receipt]);
            $this->masters['B']->afterDispatch();
            self::assertSame(0, $this->used('B'));
            foreach (['A', 'B'] as $node) {
                foreach (['incoming', 'waitingIncoming', 'outgoing', 'received', 'transfers'] as $property) {
                    self::assertSame(0, $this->peerMetadataCount($node, $property), $property . ' at file offset ' . $offset);
                }
                self::assertFalse($this->links[$node]->shouldClose());
            }
            self::assertSame([], new ReflectionProperty(UploadMemoryRemoteUpload::class, 'windows')->getValue($remote));
        }
        self::assertNull($remote->reserveNext());
        self::assertSame(1, count(new ReflectionProperty(UploadMemoryPool::class, 'requests')->getValue($pool)),
            'The upload is still declared while all its completed windows are gone');
        $remote->cancel();
        $remote->cancel();
        $this->masters['A']->cancel('browser', 'file');
        self::assertSame([], new ReflectionProperty(UploadMemoryPool::class, 'requests')->getValue($pool));
    }

    public function testSettledReceiptCannotReplayIntoTheUnusedPartOfALiveWindow(): void
    {
        $this->masters['B']->setTestLimit(8);
        $window = 'A:boot:peer:1:B:window:1';
        $this->control('B', new PeerUploadReserveDTO($window, 8));
        $peer = new ReflectionMethod(UploadMemoryMaster::class, 'peer')->invoke($this->masters['B'], $this->links['B']);
        $pool = new ReflectionProperty(UploadMemoryMaster::class, 'pool')->getValue($this->masters['B']);
        $old = new UploadMemoryReservationData($window, 'A:boot:20', 1);
        $receipt = $peer->accept($old, 1);
        self::assertNotNull($receipt);
        self::assertNull($peer->accept($old, 1), 'An unsettled exact duplicate can still be acknowledged');
        foreach ([new UploadMemoryReservationData($window, $old->receiptId, 2),
            new UploadMemoryReservationData('unknown', $old->receiptId, 1)] as $changed) {
            try {
                $peer->accept($changed, $changed->bytes);
                self::fail('A changed live duplicate must not be acknowledged');
            } catch (PeerTransportException) {
                self::assertSame(8, $this->used('B'));
            }
        }
        $pool->discardPending($receipt->id);
        $this->masters['B']->afterDispatch();
        self::assertSame(0, $this->peerMetadataCount('B', 'received'));
        self::assertSame(1, $this->peerMetadataCount('B', 'incoming'));
        foreach ([$old, new UploadMemoryReservationData($window, 'A:boot:19', 1),
            new UploadMemoryReservationData($window, 'other-boot:21', 1),
            new UploadMemoryReservationData('unknown', 'A:boot:21', 1)] as $stale) {
            try {
                $peer->accept($stale, 1);
                self::fail('Retired, unseen old, foreign or unreserved identity must not receive a new ack');
            } catch (PeerTransportException) {
                self::assertSame(7, $this->used('B'));
                self::assertSame(0, $this->peerMetadataCount('B', 'received'));
            }
        }
        $next = $peer->accept(new UploadMemoryReservationData($window, 'A:boot:23', 1), 1);
        self::assertNotNull($next, 'Unseen pool IDs in the gap are not awaited');
        self::assertSame(7, $this->used('B'));
        $this->control('B', new PeerUploadReleaseDTO($window, 0));
        self::assertSame(1, $this->used('B'));
        $this->control('B', new PeerUploadReserveDTO($window, 999));
        self::assertSame(1, $this->used('B'), 'A retired reserve, even changed, cannot resurrect the window');
        self::assertSame(0, $this->peerMetadataCount('B', 'incoming'));
        $pool->discardPending($next->id);
        $this->masters['B']->afterDispatch();
        self::assertSame(0, $this->peerMetadataCount('B', 'received'));
    }

    public function testRetirementKeepsAnOlderLiveWindowAndUnconsumedDebtAcrossManyNewWindows(): void
    {
        $this->masters['B']->setTestLimit(8);
        $peer = new ReflectionMethod(UploadMemoryMaster::class, 'peer')->invoke($this->masters['B'], $this->links['B']);
        $pool = new ReflectionProperty(UploadMemoryMaster::class, 'pool')->getValue($this->masters['B']);
        $first = 'A:boot:peer:1:B:window:1';
        $this->control('B', new PeerUploadReserveDTO($first, 3));
        $debt = $peer->accept(new UploadMemoryReservationData($first, 'A:boot:10', 1), 1);
        for ($sequence = 2; $sequence <= 128; $sequence++) {
            $window = 'A:boot:peer:1:B:window:' . $sequence;
            $this->control('B', new PeerUploadReserveDTO($window, 5));
            self::assertSame(8, $this->used('B'));
            $receipt = $peer->accept(new UploadMemoryReservationData($window, 'A:boot:' . (10 + $sequence), 1), 1);
            $this->control('B', new PeerUploadReleaseDTO($window, 0));
            $pool->discardPending($receipt->id);
            $this->masters['B']->afterDispatch();
            self::assertSame(3, $this->used('B'));
            self::assertSame(1, $this->peerMetadataCount('B', 'incoming'));
            self::assertSame(1, $this->peerMetadataCount('B', 'received'));
        }
        self::assertNull($peer->accept(new UploadMemoryReservationData($first, 'A:boot:10', 1), 1),
            'An older live receipt is checked before the retirement frontier');
        $this->control('B', new PeerUploadReserveDTO($first, 3));
        self::assertSame(3, $this->used('B'));
        $last = $peer->accept(new UploadMemoryReservationData($first, 'A:boot:200', 1), 1);
        $this->control('B', new PeerUploadReleaseDTO($first, 0));
        self::assertSame(2, $this->used('B'));
        $this->masters['B']->onPeerClosed($this->links['B']);
        $this->masters['B']->onPeerClosed($this->links['B']);
        $this->masters['B']->afterDispatch();
        self::assertSame([], new ReflectionProperty(UploadMemoryMaster::class, 'peers')->getValue($this->masters['B']));
        self::assertSame(2, $this->used('B'), 'Forgetting the peer cannot forget its receiver pool receipts');
        $pool->discardPending($debt->id);
        $pool->discardPending($last->id);
        self::assertSame(0, $this->used('B'));
    }

    public function testCancelBeforeGrantRetiresBothEndsAndLateControlsCannotResurrectIt(): void
    {
        $this->masters['B']->setTestLimit(8);
        $peer = new ReflectionMethod(UploadMemoryMaster::class, 'peer')->invoke($this->masters['A'], $this->links['A']);
        for ($turn = 0; $turn < 128; $turn++) {
            $this->masters['B']->setTestLimit($turn % 2 === 0 ? 0 : 8);
            $grant = $peer->request(3);
            $peer->release($grant, 0);
            $peer->release($grant, 0);
            $this->move('A');
            $this->move('B');
            $this->control('A', new PeerUploadGrantedDTO($grant->id, 3));
            $this->control('B', new PeerUploadReserveDTO($grant->id, 99));
            $this->control('B', new PeerUploadReleaseDTO($grant->id, 0));
            $this->control('A', new PeerUploadAcceptedDTO('A:boot:unknown'));
            foreach (['A', 'B'] as $node) {
                self::assertSame(0, $this->used($node));
                self::assertSame(0, $this->masters[$node]->inspect()['pendingPeerReservations']);
                self::assertSame(0, $this->peerMetadataCount($node, 'incoming'));
                self::assertSame(0, $this->peerMetadataCount($node, 'outgoing'));
                self::assertFalse($this->links[$node]->shouldClose());
            }
        }
        $this->masters['B']->setTestLimit(8);
        $grant = $peer->request(4);
        $this->move('A');
        $this->move('B');
        self::assertSame(4, $this->used('B'));
        foreach ([new PeerUploadReserveDTO($grant->id, 5), new PeerUploadGrantedDTO($grant->id, 5)] as $changed) {
            try {
                $this->control($changed instanceof PeerUploadReserveDTO ? 'B' : 'A', $changed);
                self::fail('Changed live metadata must still be refused');
            } catch (PeerTransportException) {
                self::assertSame(4, $this->used('B'));
            }
        }
        $peer->release($grant, 0);
        $this->move('A');
        self::assertSame(0, $this->used('B'));
    }

    public function testParkedOlderAdmissionGetsANewOrderedReceiptAtActualSend(): void
    {
        $this->masters['A']->setTestLimit(6);
        $this->masters['B']->setTestLimit(6);
        $pool = new ReflectionProperty(UploadMemoryMaster::class, 'pool')->getValue($this->masters['A']);
        $peer = new ReflectionMethod(UploadMemoryMaster::class, 'peer')->invoke($this->masters['A'], $this->links['A']);
        $early = new UploadMemoryRemoteUpload($pool, $peer, 'browser', 'early', 3, true);
        $later = new UploadMemoryRemoteUpload($pool, $peer, 'browser', 'later', 3, true);
        new ReflectionProperty(UploadMemoryMaster::class, 'remoteUploads')->setValue($this->masters['A'],
            ['browser' => ['early' => $early, 'later' => $later]]);
        self::assertNull($early->reserveNext());
        self::assertNull($later->reserveNext());
        $this->move('A');
        $this->move('B');
        $input = $this->masters['A']->getUploadInput('browser');
        $input->allow($early->reserveNext());
        $input->allow($later->reserveNext());
        $first = new WebSocketFrameBinarySignalDTO('browser', "\x05earlyaaa");
        $input->observe($first->payload, strlen($first->payload));
        $oldAdmission = $input->finishMessage();
        $this->masters['A']->onUploadFrameQueued($first, $oldAdmission);
        $second = new WebSocketFrameBinarySignalDTO('browser', "\x05laterbbb");
        $input->observe($second->payload, strlen($second->payload));
        $this->masters['A']->onUploadFrameQueued($second, $input->finishMessage());
        self::assertTrue($this->servers['A']->sendSignalToNode('B', 'probe', '1', new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET), new SignalType('frame_binary'), new SignalName('frame_binary'), $second,
        )));
        $sentSecond = $this->outboundUploadFrames('A')[0];
        unset($second);
        $this->move('A');
        $this->move('B');
        $this->masters['A']->afterDispatch();
        self::assertSame(3, $this->used('A'), 'The older parked frame still owes its raw bytes');
        self::assertTrue($this->servers['A']->sendSignalToNode('B', 'probe', '1', new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET), new SignalType('frame_binary'), new SignalName('frame_binary'), $first,
        )));
        $sentFirst = $this->outboundUploadFrames('A')[0];
        self::assertNotSame($oldAdmission->id, $sentFirst->uploadReservation->receiptId);
        self::assertGreaterThan((int)substr(strrchr($sentSecond->uploadReservation->receiptId, ':'), 1),
            (int)substr(strrchr($sentFirst->uploadReservation->receiptId, ':'), 1));
        unset($first);
        $this->move('A');
        $this->move('B');
        $this->masters['A']->afterDispatch();
        self::assertSame(0, $this->used('A'));
        self::assertSame(6, $this->used('B'));
        self::assertCount(2, $this->sinks['B']->signals);
        self::assertSame('bbb', UploadFrame::parse($this->sinks['B']->signals[0]->data->payload)?->bytes);
        self::assertSame('aaa', UploadFrame::parse($this->sinks['B']->signals[1]->data->payload)?->bytes);
        $this->servers['B']->onSignalReceived($this->links['B'], $sentSecond);
        self::assertCount(2, $this->sinks['B']->signals);
        self::assertStringContainsString($sentSecond->uploadReservation->receiptId, $this->outbound('B'));
        $this->move('B');
        $this->sinks['B']->signals = [];
        $this->masters['B']->afterDispatch();
        $this->masters['B']->afterDispatch();
        self::assertSame(0, $this->used('B'));
        $before = $this->outbound('B');
        $this->servers['B']->onSignalReceived($this->links['B'], $sentSecond);
        self::assertSame($before, $this->outbound('B'), 'A retired portion is refused without inventing an ack');
        self::assertSame([], $this->sinks['B']->signals);
        self::assertSame(0, $this->used('B'));
        $early->cancel();
        $later->cancel();
    }

    public function testReceiverGrantPrecedesDataAndAcceptedWaitsForOriginalRawReferences(): void
    {
        $this->masters['A']->setTestLimit(8);
        $this->masters['B']->setTestLimit(8);
        $this->enqueue('A', 5);
        self::assertSame(5, $this->used('A'));
        self::assertSame(0, $this->used('B'));
        self::assertStringNotContainsString('peer_signal', $this->outbound('A'));
        $this->move('A');
        self::assertSame(5, $this->used('B'));
        self::assertStringContainsString('peer_upload_granted', $this->outbound('B'));
        self::assertSame([], $this->sinks['B']->signals);
        $this->move('B');
        $this->masters['A']->afterDispatch();
        self::assertStringContainsString('uploadReservation', $this->outbound('A'));
        self::assertSame(5, $this->used('A'), 'Serialization is not receiver acceptance');
        $this->move('A');
        self::assertCount(1, $this->sinks['B']->signals);
        self::assertSame(5, $this->used('B'), 'Unused becomes received without another charge');
        self::assertSame('xxxxx', UploadFrame::parse($this->sinks['B']->signals[0]->data->payload)?->bytes);
        self::assertSame(5, $this->used('A'));
        $this->move('B');
        $this->masters['A']->afterDispatch();
        self::assertSame(0, $this->used('A'));
        self::assertSame(5, $this->used('B'), 'Receiver keeps queued raw debt until its worker consumes it');
        $worker = new ReflectionClass(WorkerClient::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(WorkerClient::class, 'isRegistered')->setValue($worker, true);
        $receiptId = $this->masters['B']->assignUploadFrame($this->sinks['B']->signals[0]->data, $worker);
        $foreign = new ReflectionClass(WorkerClient::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(WorkerClient::class, 'isRegistered')->setValue($foreign, true);
        $this->masters['B']->onUploadConsumed($foreign, [$receiptId]);
        self::assertSame(5, $this->used('B'));
        $this->masters['B']->onUploadConsumed($worker, [$receiptId, $receiptId]);
        self::assertSame(0, $this->used('B'));
    }

    public function testOrdinaryUnbudgetedBinaryKeepsItsOriginalDeliveryWithBothPoolsSuspended(): void
    {
        $signal = new SignalDTO(new SignalSource(SignalSource::WEBSOCKET), new SignalType('frame_binary'),
            new SignalName('frame_binary'), new WebSocketFrameBinarySignalDTO('ordinary-browser', 'unsigned binary'));
        self::assertTrue($this->servers['A']->sendSignalToNode('B', 'ordinary-agent', null, $signal));
        self::assertStringNotContainsString('uploadReservation', $this->outbound('A'));
        $this->move('A');
        self::assertSame('unsigned binary', $this->sinks['B']->signals[0]->data->payload);
        self::assertSame(0, $this->used('A'));
        self::assertSame(0, $this->used('B'));
    }

    /** The native buffer and its partial-write cursor are process-private; no second process is needed. */
    public function testBudgetedBacklogAboveNativeCapSurvivesSlowSocketAndSettlesExactlyOnce(): void
    {
        $bytes = 3 * 1024 * 1024;
        $this->masters['A']->setTestLimit(3 * $bytes);
        $this->masters['B']->setTestLimit(3 * $bytes);
        socket_set_option($this->sockets[0], SOL_SOCKET, SO_SNDBUF, 65536);
        for ($portion = 0; $portion < 3; $portion++) {
            $this->enqueue('A', $bytes);
        }
        $this->move('A');
        $this->move('B');
        $this->masters['A']->afterDispatch();
        $end = $this->links['A']->uploadOutboundPosition();
        self::assertGreaterThan(8 * 1024 * 1024, $end);
        self::assertSame(3 * $bytes, $this->used('A'));
        self::assertSame(3 * $bytes, $this->used('B'), 'All receiver grants precede serialization');
        self::assertLessThanOrEqual(8 * 1024 * 1024, strlen($this->outbound('A')));
        $this->links['A']->write();
        self::assertFalse($this->links['A']->shouldClose(), 'Approved raw budget must survive the native encoded-byte cap');
        self::assertTrue($this->links['A']->hasPendingWrite(), 'A slow socket forces a real partial write');
        self::assertFalse($this->links['A']->hasRemovedUploadThrough($end));
        $this->masters['A']->afterDispatch();
        self::assertSame(3 * $bytes, $this->used('A'));
        $this->servers['A']->sendSignalToNode('B', 'control', null, new SignalDTO(
            new SignalSource(SignalSource::DAEMON), new SignalType('agent'), new SignalName('control'),
            new SignalData(['progress' => true]),
        ));
        for ($turn = 0; $turn < 4096 && ($this->links['A']->hasPendingWrite() || count($this->sinks['B']->signals) < 4); $turn++) {
            $this->move('A');
            $this->move('B');
            $this->masters['A']->afterDispatch();
        }
        self::assertFalse($this->links['A']->shouldClose());
        self::assertFalse($this->links['A']->hasPendingWrite());
        self::assertTrue($this->links['A']->hasRemovedUploadThrough($end));
        self::assertCount(4, $this->sinks['B']->signals);
        for ($portion = 0; $portion < 3; $portion++) {
            $signal = $this->sinks['B']->signals[$portion];
            self::assertSame(str_repeat('x', $bytes), UploadFrame::parse($signal->data->payload)?->bytes);
            self::assertSame('frame_binary', $signal->signalType->getType());
            self::assertSame('daemon', $signal->signalSource->getSource());
        }
        self::assertSame('control', $this->sinks['B']->signals[3]->signalName->getName());
        self::assertSame(0, $this->used('A'));
        self::assertSame(3 * $bytes, $this->used('B'));
        $worker = new ReflectionClass(WorkerClient::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(WorkerClient::class, 'isRegistered')->setValue($worker, true);
        for ($portion = 0; $portion < 3; $portion++) {
            $receipt = $this->masters['B']->assignUploadFrame($this->sinks['B']->signals[$portion]->data, $worker);
            $this->masters['B']->onUploadConsumed($worker, [$receipt, $receipt]);
        }
        self::assertSame(0, $this->used('B'));
    }

    public function testSingleFrameLargerThanNativeBufferKeepsDebtUntilStagingAndRawReferencesDisappear(): void
    {
        $bytes = 7 * 1024 * 1024;
        $this->masters['A']->setTestLimit($bytes);
        $this->masters['B']->setTestLimit($bytes);
        socket_set_option($this->sockets[0], SOL_SOCKET, SO_SNDBUF, 65536);
        $held = null;
        $this->masters['A']->enqueueTest('B', 'probe', '1', $bytes,
            function (UploadMemoryTestDelivery $delivery, SignalDTO $signal) use (&$held): void {
                $held = $signal;
                $this->servers['A']->sendSignalToNode('B', $delivery->agentType, $delivery->agentIndex, $signal);
            });
        $this->move('A');
        $this->move('B');
        $this->masters['A']->afterDispatch();
        $end = $this->links['A']->uploadOutboundPosition();
        $peer = new ReflectionMethod(UploadMemoryMaster::class, 'peer')->invoke($this->masters['A'], $this->links['A']);
        $transfers = new ReflectionProperty(UploadMemoryPeer::class, 'transfers')->getValue($peer);
        self::assertCount(1, $transfers);
        $this->control('A', new PeerUploadAcceptedDTO(array_key_first($transfers)));
        $held = null;
        $this->masters['A']->afterDispatch();
        self::assertSame($bytes, $this->used('A'), 'Acceptance and raw deletion cannot release a staged frame');
        $this->links['A']->closeOnceWritten();
        for ($turn = 0; $turn < 4096 && ($this->links['A']->hasPendingWrite() || $this->sinks['B']->signals === []); $turn++) {
            $this->move('A');
        }
        self::assertTrue($this->links['A']->shouldClose(), 'Graceful close waits for the whole serialized frame');
        self::assertTrue($this->links['A']->hasRemovedUploadThrough($end));
        self::assertCount(1, $this->sinks['B']->signals);
        self::assertSame(str_repeat('x', $bytes), UploadFrame::parse($this->sinks['B']->signals[0]->data->payload)?->bytes);
        $this->masters['A']->afterDispatch();
        self::assertSame(0, $this->used('A'));
        self::assertSame($bytes, $this->used('B'));
    }

    public function testOrdinaryOverflowStillDropsThroughTheParentGuardWithBudgetedBytesStaged(): void
    {
        $bytes = 7 * 1024 * 1024;
        $this->masters['A']->setTestLimit($bytes);
        $this->masters['B']->setTestLimit($bytes);
        $this->enqueue('A', $bytes);
        $this->move('A');
        $this->move('B');
        $this->masters['A']->afterDispatch();
        $this->links['A']->sendEncodedFrame(str_repeat(' ', 8 * 1024 * 1024));
        $end = $this->links['A']->uploadOutboundPosition();
        $this->links['A']->write();
        self::assertTrue($this->links['A']->shouldClose());
        self::assertSame('', $this->outbound('A'), 'The real parent guard discards its native buffer');
        self::assertFalse($this->links['A']->hasPendingWrite(), 'Drop deletes all staging before pool release');
        self::assertSame($bytes, $this->used('A'), 'An unaccepted source debt waits for the close notification');
        self::assertTrue($this->links['A']->hasRemovedUploadThrough($end));
        Hilos::$cluster = $this->contexts['A'];
        unset($this->sockets[0]);
        $this->links['A']->close();
        self::assertFalse($this->links['A']->hasPendingWrite());
        self::assertTrue($this->links['A']->hasRemovedUploadThrough($end));
        self::assertSame($bytes, $this->used('A'));
        $this->masters['A']->afterDispatch();
        $this->masters['A']->afterDispatch();
        self::assertSame(0, $this->used('A'));
        self::assertSame([], new ReflectionProperty(UploadMemoryMaster::class, 'peers')->getValue($this->masters['A']));
    }

    public function testOldOrdinaryOverflowStillDropsWithoutAnyUploadBudget(): void
    {
        $this->links['A']->sendEncodedFrame(str_repeat(' ', 8 * 1024 * 1024));
        $this->links['A']->write();
        self::assertTrue($this->links['A']->shouldClose());
        self::assertFalse($this->links['A']->hasPendingWrite());
        self::assertSame('', $this->outbound('A'));
        self::assertSame(0, $this->used('A'));
    }

    public function testCloseDropsStagingBeforeSourceReleaseButKeepsAlreadyReceivedWorkerDebt(): void
    {
        $bytes = 3 * 1024 * 1024;
        $this->masters['A']->setTestLimit(3 * $bytes);
        $this->masters['B']->setTestLimit(3 * $bytes);
        socket_set_option($this->sockets[0], SOL_SOCKET, SO_SNDBUF, 65536);
        for ($portion = 0; $portion < 3; $portion++) {
            $this->enqueue('A', $bytes);
        }
        $this->move('A');
        $this->move('B');
        $this->masters['A']->afterDispatch();
        for ($turn = 0; $turn < 4096 && $this->sinks['B']->signals === []; $turn++) {
            $this->move('A');
        }
        self::assertCount(1, $this->sinks['B']->signals);
        $this->move('B');
        $this->masters['A']->afterDispatch();
        self::assertSame(2 * $bytes, $this->used('A'));
        self::assertTrue($this->links['A']->hasPendingWrite());
        Hilos::$cluster = $this->contexts['A'];
        unset($this->sockets[0]);
        $this->links['A']->close();
        self::assertFalse($this->links['A']->hasPendingWrite());
        self::assertSame('', $this->outbound('A'));
        self::assertSame(2 * $bytes, $this->used('A'));
        $this->masters['A']->afterDispatch();
        self::assertSame(0, $this->used('A'));
        Hilos::$cluster = $this->contexts['B'];
        unset($this->sockets[1]);
        $this->links['B']->close();
        $this->masters['B']->afterDispatch();
        self::assertSame($bytes, $this->used('B'), 'EOF releases unused windows, not the received receipt');
        $worker = new ReflectionClass(WorkerClient::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(WorkerClient::class, 'isRegistered')->setValue($worker, true);
        $receipt = $this->masters['B']->assignUploadFrame($this->sinks['B']->signals[0]->data, $worker);
        $this->masters['B']->onUploadConsumed($worker, [$receipt, $receipt]);
        self::assertSame(0, $this->used('B'));
    }

    public function testActualLinkCloseDeletesBuffersBeforeSourceDebtAndMetadataAreDiscarded(): void
    {
        $this->masters['A']->setTestLimit(8);
        $this->masters['B']->setTestLimit(8);
        $this->enqueue('A', 5);
        $this->move('A');
        $this->move('B');
        $this->masters['A']->afterDispatch();
        self::assertSame(5, $this->used('A'));
        self::assertTrue($this->links['A']->hasPendingWrite());
        Hilos::$cluster = $this->contexts['A'];
        unset($this->sockets[0]);
        $this->links['A']->close();
        self::assertFalse($this->links['A']->hasPendingWrite());
        self::assertSame(5, $this->used('A'), 'Do not infer raw reference deletion inside the close callback');
        $this->masters['A']->afterDispatch();
        self::assertSame(0, $this->used('A'));
        self::assertSame([], new ReflectionProperty(UploadMemoryMaster::class, 'peers')->getValue($this->masters['A']));
        $this->expectException(PeerTransportException::class);
        $this->masters['A']->onPeerControl($this->links['A'], new PeerUploadGrantedDTO('late-old-window', 5));
    }

    public function testMissingLocalSinkDropsReceivedBytesAndStillAcknowledgesResponsibility(): void
    {
        $this->masters['A']->setTestLimit(8);
        $this->masters['B']->setTestLimit(8);
        $this->enqueue('A', 5);
        $this->move('A');
        $this->move('B');
        $this->masters['A']->afterDispatch();
        $this->contexts['B'] = new ReflectionClass(ClusterContext::class)->newInstanceWithoutConstructor();
        $this->move('A');
        $this->masters['B']->afterDispatch();
        $this->move('B');
        $this->masters['A']->afterDispatch();
        self::assertSame(0, $this->used('A'));
        self::assertSame(0, $this->used('B'));
    }

    public function testAcceptedTransferRetainsSourceDebtUntilEveryOriginalReferenceIsGone(): void
    {
        $this->masters['A']->setTestLimit(8);
        $this->masters['B']->setTestLimit(8);
        $held = null;
        $this->masters['A']->enqueueTest('B', 'probe', '1', 5,
            function (UploadMemoryTestDelivery $delivery, SignalDTO $signal) use (&$held): void {
                $held = $signal;
                $this->servers['A']->sendSignalToNode('B', $delivery->agentType, $delivery->agentIndex, $signal);
            });
        $this->move('A');
        $this->move('B');
        $this->masters['A']->afterDispatch();
        $this->move('A');
        $this->move('B');
        $this->masters['A']->afterDispatch();
        self::assertSame(5, $this->used('A'), 'An accepted frame is still charged while the caller holds it');
        $held = null;
        $this->masters['A']->afterDispatch();
        self::assertSame(0, $this->used('A'));
    }

    public function testOneLogicalFrameCrossesReceiverWindowsInByteOrderWithoutChangingMetadata(): void
    {
        $this->masters['A']->setTestLimit(3);
        $this->masters['B']->setTestLimit(3);
        $this->enqueue('A', 5);
        $this->move('A');
        $this->move('B');
        $this->masters['A']->afterDispatch();
        self::assertSame([], $this->sinks['B']->signals, 'Fixture bytes do not exist while a second window is missing');
        $this->masters['A']->setTestLimit(5);
        $this->masters['B']->setTestLimit(5);
        for ($turn = 0; $turn < 4; $turn++) {
            $this->masters['A']->afterDispatch();
            $this->move('A');
            $this->masters['B']->afterDispatch();
            $this->move('B');
        }
        self::assertCount(2, $this->sinks['B']->signals);
        $chunks = array_map(static fn(SignalDTO $signal): ?UploadFrame => UploadFrame::parse($signal->data->payload),
            $this->sinks['B']->signals);
        self::assertSame([3, 2], array_map(static fn(UploadFrame $frame): int => strlen($frame->bytes), $chunks));
        self::assertSame('xxxxx', implode('', array_column($chunks, 'bytes')));
        self::assertSame($chunks[0]->clientUploadId, $chunks[1]->clientUploadId);
        foreach ($this->sinks['B']->signals as $signal) {
            self::assertSame('frame_binary', $signal->signalType->getType());
            self::assertSame('frame_binary', $signal->signalName->getName());
            self::assertSame([], $signal->meta);
            self::assertSame('daemon', $signal->signalSource->getSource());
        }
        self::assertSame(0, $this->used('A'));
        self::assertSame(5, $this->used('B'));
    }

    public function testUnknownAndWrongLinkEnvelopesCannotSpendAnotherGeneration(): void
    {
        $this->masters['B']->setTestLimit(7);
        $this->control('B', new PeerUploadReserveDTO('A:boot:peer:1:B:window:1', 7));
        $pool = new ReflectionProperty(UploadMemoryMaster::class, 'pool')->getValue($this->masters['B']);
        $other = new UploadMemoryPeer($pool, $this->links['B'], 'replacement-generation', 'A');
        try {
            $other->accept(new UploadMemoryReservationData('A:boot:peer:1:B:window:1', 'A:boot:1', 3), 3);
            self::fail('Another incarnation must not spend an old window');
        } catch (PeerTransportException) {
            self::assertSame(7, $this->used('B'));
        }
        $peer = new ReflectionMethod(UploadMemoryMaster::class, 'peer')->invoke($this->masters['B'], $this->links['B']);
        try {
            $peer->accept(new UploadMemoryReservationData('A:boot:peer:1:B:window:1', 'A:boot:1', 3), 4);
            self::fail('Actual raw bytes must match the envelope');
        } catch (PeerTransportException) {
            self::assertSame(7, $this->used('B'));
        }
        $this->control('B', new PeerUploadGrantedDTO('unknown-generation', 7));
        $this->control('B', new PeerUploadAcceptedDTO('unknown-generation'));
        self::assertSame(7, $this->used('B'));
        $this->masters['B']->onPeerClosed($this->links['B']);
        self::assertSame(0, $this->used('B'));
    }

    public function testCrossingRequestsNeverHoldHighWhileWaitingForLowAndBothProgress(): void
    {
        $this->masters['A']->setTestLimit(3);
        $this->masters['B']->setTestLimit(3);
        $this->enqueue('B', 3);
        self::assertSame(0, $this->used('B'), 'High sends metadata before taking its own capacity');
        $this->enqueue('A', 3);
        self::assertSame(3, $this->used('A'));
        $this->move('B');
        self::assertSame(2, $this->masters['A']->inspect()['pendingPeerReservations']);
        self::assertSame(0, $this->used('B'));
        $this->move('A');
        self::assertSame(3, $this->used('B'));
        for ($turn = 0; $turn < 12; $turn++) {
            $this->move('B');
            $this->masters['A']->afterDispatch();
            $this->move('A');
            $this->masters['B']->afterDispatch();
            // Finish received worker work while the reverse acquisition is still waiting.
            $this->sinks['A']->signals = [];
            $this->sinks['B']->signals = [];
            $this->masters['A']->afterDispatch();
            $this->masters['B']->afterDispatch();
        }
        self::assertSame(1, $this->sinks['A']->delivered);
        self::assertSame(1, $this->sinks['B']->delivered);
        self::assertSame(0, $this->used('A'));
        self::assertSame(0, $this->used('B'));
    }

    public function testReceiverDuplicateReleaseAndUnknownOldLinkFactsNeverFreeReceivedDebt(): void
    {
        $master = $this->masters['B'];
        $master->setTestLimit(7);
        $this->control('B', new PeerUploadReserveDTO('A:boot:peer:1:B:window:1', 7));
        $this->control('B', new PeerUploadReserveDTO('A:boot:peer:1:B:window:1', 7));
        self::assertSame(7, $this->used('B'));
        $peer = new ReflectionMethod(UploadMemoryMaster::class, 'peer')->invoke($master, $this->links['B']);
        $envelope = new UploadMemoryReservationData('A:boot:peer:1:B:window:1', 'A:boot:1', 3);
        $receipt = $peer->accept($envelope, 3);
        self::assertNotNull($receipt);
        self::assertNull($peer->accept($envelope, 3));
        $this->control('B', new PeerUploadReleaseDTO('A:boot:peer:1:B:window:1', 2));
        self::assertSame(5, $this->used('B'));
        $this->control('B', new PeerUploadReleaseDTO('A:boot:peer:1:B:window:1', 2));
        $this->control('B', new PeerUploadAcceptedDTO($receipt->id));
        $this->control('B', new PeerUploadReleaseDTO('unknown', 0));
        self::assertSame(5, $this->used('B'));
        $this->control('B', new PeerUploadReleaseDTO('A:boot:peer:1:B:window:1', 0));
        self::assertSame(3, $this->used('B'));
        $master->onPeerClosed($this->links['B']);
        self::assertSame(3, $this->used('B'), 'Link loss cannot free a receiver-owned portion');
        $pool = new ReflectionProperty(UploadMemoryMaster::class, 'pool')->getValue($master);
        $pool->discardPending($receipt->id);
        self::assertSame(0, $this->used('B'));
    }

    public function testSecondGrantTrimsTheFirstBeforePublicationInBothDirections(): void
    {
        foreach ([true, false] as $localFirst) {
            $pool = new UploadMemoryPool($localFirst ? 9 : 4, 'isolated');
            $peer = new UploadMemoryPeer($pool, $this->links['A'], 'isolated-link:' . (int)$localFirst, 'B');
            $upload = new UploadMemoryRemoteUpload($pool, $peer, 'browser', 'file', 9, $localFirst);
            self::assertNull($upload->reserveNext());
            self::assertSame($localFirst ? 9 : 0, $pool->getUsedBytes());
            $wire = $this->outbound('A');
            $frames = array_filter(explode("\n", trim($wire)));
            $request = PeerDTO::fromWire(end($frames));
            self::assertInstanceOf(PeerUploadReserveDTO::class, $request);
            $peer->receive(new PeerUploadGrantedDTO($request->reservationId, $localFirst ? 4 : 9));
            $window = $upload->reserveNext();
            self::assertNotNull($window);
            self::assertSame(4, $window->sendUpToBytes);
            self::assertSame(4, $pool->getUsedBytes());
            if (!$localFirst) {
                self::assertStringContainsString('"remainingBytes":4', $this->outbound('A'));
            }
            $upload->cancel();
            self::assertSame(0, $pool->getUsedBytes());
        }
    }

    private function enqueue(string $node, int $bytes): void
    {
        $remote = $node === 'A' ? 'B' : 'A';
        $server = $this->servers[$node];
        $this->masters[$node]->enqueueTest($remote, 'probe', '1', $bytes,
            static function (UploadMemoryTestDelivery $delivery, SignalDTO $signal) use ($server): void {
                self::assertTrue($server->sendSignalToNode($delivery->targetNodeId, $delivery->agentType, $delivery->agentIndex, $signal));
            });
    }

    private function move(string $from): void
    {
        $to = $from === 'A' ? 'B' : 'A';
        if ($this->links[$from]->hasPendingWrite()) {
            $this->links[$from]->write();
        }
        Hilos::$cluster = $this->contexts[$to];
        $this->links[$to]->read();
    }

    private function used(string $node): int
    {
        return $this->masters[$node]->inspect()['usedBytes'];
    }

    /**
     * @param string $node Exact live socket end
     * @param string $property Existing private transport map
     * @return int Retained entries, without extending the production inspect contract
     */
    private function peerMetadataCount(string $node, string $property): int
    {
        $peer = new ReflectionMethod(UploadMemoryMaster::class, 'peer')->invoke($this->masters[$node], $this->links[$node]);

        return count(new ReflectionProperty(UploadMemoryPeer::class, $property)->getValue($peer));
    }

    private function outbound(string $node): string
    {
        return new ReflectionProperty(AbstractClient::class, 'writeBuffer')->getValue($this->links[$node]);
    }

    /**
     * @param string $node Sender whose already serialized FIFO is inspected
     * @return list<PeerSignalDTO> Upload signals in actual enqueue order
     */
    private function outboundUploadFrames(string $node): array
    {
        $signals = [];
        foreach (explode("\n", trim($this->outbound($node))) as $wire) {
            $frame = PeerDTO::fromWire($wire);
            if ($frame instanceof PeerSignalDTO && $frame->uploadReservation !== null) {
                $signals[] = $frame;
            }
        }

        return $signals;
    }

    private function control(string $node, PeerDTO $frame): void
    {
        new ReflectionMethod(PeerLink::class, 'handleFrame')->invoke($this->links[$node], $frame);
    }
}

final class UploadPoolSignalSink implements AgentSignalSink
{
    /** @var list<SignalDTO> Raw objects parked as a real master would park them */
    public array $signals = [];

    public int $delivered = 0;

    public function deliverSignalToAgent(string $agentType, ?string $agentIndex, SignalDTO $signal): void
    {
        $this->signals[] = $signal;
        $this->delivered++;
    }
}

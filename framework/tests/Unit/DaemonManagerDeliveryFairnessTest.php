<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Generator;
use Hilos\Cluster\AgentSignalSink;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\CommandSignalCancellation;
use Hilos\Core\Daemon\ParkedAgentSignal;
use Hilos\Core\Daemon\SignalDispatch;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\Destination\Destination;
use Hilos\Core\Router\Destination\RemoteAgentDestination;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Files\Upload\UploadMemoryMaster;
use Hilos\Files\Upload\UploadMemoryRemoteUpload;
use Hilos\Hilos;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Transport\TlsSocketTransport;
use Hilos\Tests\Unit\Socket\MutualTlsFixture;
use Hilos\Tests\Unit\Socket\TlsTestExchange;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\Transport\PlainSocketTransport;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\AgentStopDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartedDTO;
use Hilos\Socket\Worker\DTO\WorkerUploadConsumedDTO;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use WeakReference;

/**
 * Scheduler state is process-private; real IPC bytes and receipts observe each guarded outcome.
 * Late producers replenish a 70-entry floor, beyond a whole 64-step portion, and append
 * a new entry every pass. This keeps continuous pressure without oversized per-pass bursts.
 */
final class DaemonManagerDeliveryFairnessTest extends TestCase
{
    /** @return iterable<string, array{bool, string, bool}> Selected releases under ongoing effects or ready cancellations */
    public static function releasedOutcomes(): iterable
    {
        foreach ([false, true] as $preempt) {
            foreach (['complete', 'canceled', 'failed'] as $outcome) {
                foreach ([false, true] as $cancellations) {
                    yield ($preempt ? 'preempt-' : 'oldest-') . $outcome . ($cancellations ? '-cancellations' : '-effects')
                        => [$preempt, $outcome, $cancellations];
                }
            }
        }
    }

    /** @return iterable<string, array{string, string, bool}> Original admissions under later categories and finite stop ownership */
    public static function ordinaryEntrances(): iterable
    {
        foreach (['active', 'input', 'retired', 'identity', 'peer'] as $entrance) {
            foreach (['releases', 'identities', 'peers', 'cancellations', 'all'] as $later) {
                foreach ([false, true] as $stop) {
                    yield $entrance . '-' . $later . ($stop ? '-stop' : '-browser') => [$entrance, $later, $stop];
                }
            }
        }
    }

    /**
     * The stop variant starts at the first-wave-gone barrier. Production prefix accounting
     * and stop delivery drive the actual channel; child process exits remain the cluster scenario's duty.
     *
     * @param string $entrance Original raw's scheduler entrance
     * @param string $contention Later category continuously replenished beside the existing source-effect producer
     * @param bool $stop Whether the production worker barrier must also append its stop after the complete original prefix
     */
    #[DataProvider('ordinaryEntrances')]
    public function testOriginalOrdinaryWorkerReceiptsFinishWhileLaterCategoriesKeepArriving(
        string $entrance, string $contention, bool $stop,
    ): void
    {
        $previousEnv = Hilos::$env;
        $previousRouter = Hilos::$sr;
        $previousCluster = Hilos::$cluster;
        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = null;
        $manager = new DeliveryFairnessManager();
        $roster = new ReflectionProperty(DaemonManager::class, 'agentManagerDaemon')->getValue($manager);
        $memory = new UploadMemoryMaster('category-fairness');
        $memory->setTestLimit(2);
        $workers = new DeliveryFairnessWorkerServer($roster, $memory);
        $manager->registerServer($workers);
        new ReflectionProperty(DaemonManager::class, 'uploadMemory')->setValue($manager, $memory);
        $pair = [];
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        socket_set_nonblock($pair[0]);
        socket_set_nonblock($pair[1]);
        $worker = new WorkerClient($pair[0], $roster, $workers);
        new ReflectionProperty(WorkerClient::class, 'workerIndex')->setValue($worker, 1);
        new ReflectionProperty(WorkerClient::class, 'isRegistered')->setValue($worker, true);
        $worker->setUploadMemoryPoolSink($memory);
        $agent = new DeliveryFairnessAgentDaemon();
        $agent->setWorkerClient($worker);
        $roster->addAgent(DeliveryFairnessAgentDaemon::AGENT_TYPE, $agent, 1, false);
        $roster->handleAgentStarted(new WorkerAgentStartedDTO(DeliveryFairnessAgentDaemon::AGENT_TYPE,
            DeliveryFairnessAgentDaemon::AGENT_TYPE));
        $laterAgent = new DeliveryFairnessAgentDaemon();
        $laterAgent->setWorkerClient($worker);
        $roster->addAgent('later_fairness_probe', $laterAgent, 1, false);
        $roster->handleAgentStarted(new WorkerAgentStartedDTO('later_fairness_probe', 'later_fairness_probe'));
        $loop = new EventLoop();
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($manager, $loop);
        new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($manager);
        $ready = false;
        $worker->attachEventLoop($loop, static function () use (&$ready): void { $ready = true; });
        $holds = new ReflectionMethod(DaemonManager::class, 'agentSignalHolds')->invoke($manager);
        $identities = new ReflectionMethod(DaemonManager::class, 'identitySignals')->invoke($manager);
        $state = new ReflectionMethod(DaemonManager::class, 'signalDispatchState')->invoke($manager);
        $create = new ReflectionMethod(DaemonManager::class, 'createSignalDispatch');
        $raw = [];
        $expected = [];
        $older = null;
        for ($index = 0; $index < 2; $index++) {
            $memory->enqueueTest('local', DeliveryFairnessAgentDaemon::AGENT_TYPE, null, 1,
                static function ($delivery, SignalDTO $signal) use ($index, $entrance, $manager, $holds, $identities,
                    $state, $workers, $create, &$raw, &$expected, &$older): void {
                    $raw[] = WeakReference::create($signal->data);
                    $expected[] = $signal->data->toArray();
                    if ($index === 0) {
                        $older = $holds->add(new ParkedAgentSignal($signal, DeliveryFairnessAgentDaemon::AGENT_TYPE,
                            microtime(true), false, true))->id;
                    } elseif ($entrance === 'identity') {
                        $id = ++$state->identitySequence;
                        $identities->add($id, $signal->data->acceptKey, 'original');
                        $identities->release($id, $id);
                        $state->identities[$id] = $signal;
                    } elseif ($entrance === 'input' || $entrance === 'retired') {
                        Hilos::$sr->queueSignal($signal->signalSource, $signal->signalType, $signal->signalName, $signal->data);
                    } else {
                        $dispatch = $create->invoke($manager, $signal, false, $workers, null, null, null, null);
                        if ($entrance === 'active') {
                            $state->active = $dispatch;
                        } else {
                            $dispatch->peerFanoutId = ++$state->peerSequence;
                            $dispatch->peerOrigin = 'original-peer';
                            $state->peers[$dispatch->peerFanoutId] = $dispatch;
                        }
                    }
                });
        }
        if ($entrance === 'retired') {
            Hilos::$sr = new DeliveryFairnessRouter();
            new ReflectionMethod(DaemonManager::class, 'signalDispatchState')->invoke($manager);
        }
        $holds->release($older);
        if ($entrance === 'active') {
            $hold = $holds->get($older);
            $walk = new ReflectionMethod(DaemonManager::class, 'walkReleasedAgentSignal');
            $state->released = new SignalDispatch($hold->parked->signal,
                static function (SignalDispatch $dispatch) use ($walk, $manager, $workers): Generator {
                    yield;
                    for ($step = 0; $step < 130; $step++) {
                        yield;
                    }
                    yield from $walk->invoke($manager, $dispatch, $workers, null);
                }, $hold->parked);
            $holds->begin($older, $state->released);
            unset($hold);
        }
        $state->browsers = new ReflectionMethod(DaemonManager::class, 'newSignalDispatchPrefix')->invoke($manager);
        if ($stop) {
            new ReflectionMethod(AbstractServer::class, 'addClient')->invoke($workers, $worker);
            $key = WorkerConstants::TYPE_REGULAR . WorkerConstants::KEY_SEPARATOR . '1';
            new ReflectionProperty(WorkerServer::class, 'secondStopWave')->setValue($workers,
                [$key => [DeliveryFairnessAgentDaemon::AGENT_TYPE]]);
            new ReflectionProperty(WorkerServer::class, 'heldStopClients')->setValue($workers, [$key => $worker]);
            new ReflectionProperty(WorkerServer::class, 'secondStopAgents')->setValue($workers,
                [DeliveryFairnessAgentDaemon::AGENT_TYPE => true]);
            new ReflectionProperty(WorkerServer::class, 'secondStopWaveStep')->setValue($workers,
                new ReflectionClass(WorkerServer::class)->getConstant('SECOND_WAVE_FIRST_GONE'));
            new ReflectionProperty(WorkerServer::class, 'stopDispatchGeneration')->setValue($workers, 1);
            new ReflectionMethod(DaemonManager::class, 'captureSignalDispatchPrefixes')->invoke($manager);
            self::assertSame(1, $workers->pendingStopDispatch());
        }
        $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignals');
        $queue = new ReflectionMethod(DaemonManager::class, 'queueSignalEffect');
        $advanceStop = new ReflectionMethod(WorkerServer::class, 'advanceSecondStopWave');
        $wire = '';
        $late = new SignalDTO(new SignalSource(SignalSource::DAEMON), new SignalType(SignalTypeConstants::SYSTEM),
            new SignalName('later-category'), new SignalData());
        try {
            for ($portion = 0; $portion < 128 && (!$state->browsers->complete || ($stop && !$state->stop->complete)); $portion++) {
                $loop->tick();
                $queue->invoke($manager, static function (): Generator {
                    yield;
                    for ($step = 0; $step < 130; $step++) {
                        yield;
                    }
                });
                if ($contention === 'all' || $contention === 'cancellations') {
                    for ($later = 0; $later < 70 && ($later === 0 || count($state->commandCancellations) < 70); $later++) {
                        $id = ++$state->commandCancellationSequence;
                        $cancellation = new CommandSignalCancellation($id, 'later-' . $id, 0);
                        $cancellation->holdsComplete = true;
                        $state->commandCancellations[$id] = $cancellation;
                    }
                }
                if ($contention === 'all' || $contention === 'releases') {
                    $hold = $holds->add(new ParkedAgentSignal($late, 'later_fairness_probe', microtime(true), false, true));
                    $holds->release($hold->id);
                }
                for ($later = 0; $later < 70; $later++) {
                    if (($contention === 'all' || $contention === 'identities')
                        && ($later === 0 || count($state->identities) < 70)) {
                        $id = ++$state->identitySequence;
                        $identities->add($id, 'later-' . $id, 'later');
                        $identities->release($id, $id);
                        $state->identities[$id] = $late;
                    }
                    if (($contention === 'all' || $contention === 'peers')
                        && ($later === 0 || count($state->peers) < 70)) {
                        $peer = new SignalDispatch(null, static function (): Generator { yield; yield; });
                        $peer->peerFanoutId = ++$state->peerSequence;
                        $state->peers[$peer->peerFanoutId] = $peer;
                    }
                    if ($contention === 'all' && ($later === 0 || Hilos::$sr->getQueuedSignalCount() < 70)) {
                        Hilos::$sr->queueSignal($late->signalSource, $late->signalType, $late->signalName, $late->data);
                    }
                }
                unset($cancellation, $hold, $peer);
                if ($contention === 'all' || $contention === 'cancellations') {
                    self::assertGreaterThanOrEqual(70, count($state->commandCancellations));
                }
                if ($contention === 'all' || $contention === 'identities') {
                    self::assertGreaterThanOrEqual(70, count($state->identities));
                }
                if ($contention === 'all' || $contention === 'peers') {
                    self::assertGreaterThanOrEqual(70, count($state->peers));
                }
                if ($contention === 'all') {
                    self::assertGreaterThanOrEqual(70, Hilos::$sr->getQueuedSignalCount());
                }
                $dispatch->invoke($manager);
                if ($stop) {
                    if ($raw[0]->get() !== null || $raw[1]->get() !== null) {
                        self::assertSame(1, $workers->pendingStopDispatch(), 'The actual stop barrier cannot acknowledge a partial raw prefix');
                    }
                    $advanceStop->invoke($workers);
                }
                $worker->write();
                $wire .= new PlainSocketTransport($pair[1])->read(65536);
            }
            self::assertTrue($state->browsers->complete, 'Every original queue must finish while all later categories remain active');
            self::assertFalse($state->browsers->failed);
            if ($stop) {
                self::assertTrue($state->stop->complete);
                self::assertFalse($state->stop->failed);
                self::assertNull($workers->pendingStopDispatch(), 'The actual worker owner acknowledged its captured generation');
                self::assertTrue($worker->awaitsStopReport(DeliveryFairnessAgentDaemon::AGENT_TYPE));
            }
            self::assertNotEmpty($state->effects);
            if ($contention === 'all' || $contention === 'cancellations') {
                self::assertNotEmpty($state->commandCancellations);
            }
            if ($contention === 'all' || $contention === 'releases') {
                self::assertTrue($holds->hasReleased());
            }
            if ($contention === 'all' || $contention === 'identities') {
                self::assertNotEmpty($state->identities);
            }
            if ($contention === 'all' || $contention === 'peers') {
                self::assertNotEmpty($state->peers);
            }
            if ($contention === 'all') {
                self::assertTrue(Hilos::$sr->hasQueuedSignals());
            }
            self::assertNull($raw[0]->get());
            self::assertNull($raw[1]->get());
            $packets = [];
            $receipts = [];
            $stops = 0;
            foreach (explode("\n", trim($wire)) as $line) {
                $packet = WorkerDTO::factoryWorkerDTO($line);
                if ($packet instanceof AgentStopDTO) {
                    self::assertTrue($stop);
                    self::assertSame($expected, $packets, 'The real stop packet follows both complete original raw packets');
                    self::assertSame(DeliveryFairnessAgentDaemon::AGENT_TYPE, $packet->agentId);
                    $stops++;
                    continue;
                }
                self::assertInstanceOf(DaemonAgentMessageDTO::class, $packet);
                if ($packet->signal->data instanceof WebSocketFrameBinarySignalDTO) {
                    self::assertSame(DeliveryFairnessAgentDaemon::AGENT_TYPE, $packet->agentId);
                    $packets[] = $packet->signal->data->toArray();
                    self::assertNotNull($packet->uploadReceiptId);
                    $receipts[] = $packet->uploadReceiptId;
                } else {
                    self::assertSame('later_fairness_probe', $packet->agentId);
                    self::assertSame('later-category', $packet->signal->signalName->getName());
                    self::assertSame([], $packet->signal->data->toArray());
                    self::assertNull($packet->uploadReceiptId);
                }
            }
            self::assertSame($stop ? 1 : 0, $stops, 'A complete original prefix appends exactly one held-agent stop');
            self::assertSame($expected, $packets, 'The ordinary raw never overtakes its original same-agent predecessor or repeats');
            self::assertCount(2, array_unique($receipts));
            self::assertSame(2, $memory->inspect()['usedBytes']);
            $ready = false;
            socket_write($pair[1], new WorkerUploadConsumedDTO($receipts)->toJson() . "\n");
            for ($pass = 0; $pass < 8 && !$ready; $pass++) {
                $loop->tick();
            }
            self::assertTrue($ready);
            $worker->read();
            self::assertSame(0, $memory->inspect()['usedBytes'], 'Actual receipt decoding settles both original accepted obligations');
        } finally {
            new ReflectionMethod(DaemonManager::class, 'discardSignalDispatch')->invoke($manager);
            $worker->close();
            socket_close($pair[1]);
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($manager);
            $loop->close();
            $manager->releaseEventLoop();
            Hilos::$env = $previousEnv;
            Hilos::$sr = $previousRouter;
            Hilos::$cluster = $previousCluster;
        }
    }

    /** @return iterable<string, array{string, bool}> Exact original route and transport under ongoing later scheduler categories */
    public static function peerRouteOutcomes(): iterable
    {
        foreach ([false, true] as $tls) {
            foreach (['delayed', 'replaced', 'reattached', 'canceled', 'failed'] as $outcome) {
                yield ($tls ? 'tls-' : 'plain-') . $outcome => [$outcome, $tls];
            }
        }
        yield 'tls-transport-rebound' => ['transport-rebound', true];
    }

    /**
     * The captured-route window and raw references are private to one pass. Actual peer and worker
     * sockets carry the upload, acceptance and consumption; child processes remain the cluster scenario's duty.
     *
     * @param string $outcome Original route or binding disposition during the retained delivery
     * @param bool $tls Whether the real peer sockets use native mutual TLS
     */
    #[DataProvider('peerRouteOutcomes')]
    public function testNativePeerPrefixAndActualReceiptsSettleUnderContinuousLaterCategories(string $outcome, bool $tls): void
    {
        $previousEnv = Hilos::$env;
        $previousRouter = Hilos::$sr;
        $previousCluster = Hilos::$cluster;
        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = null;
        $manager = new DeliveryFairnessManager();
        $roster = new ReflectionProperty(DaemonManager::class, 'agentManagerDaemon')->getValue($manager);
        $memory = new UploadMemoryMaster('A:peer-fairness');
        $receiverMemory = new UploadMemoryMaster('B:peer-fairness');
        $memory->setTestLimit(2);
        $receiverMemory->setTestLimit(2);
        $workers = new DeliveryFairnessWorkerServer($roster, $memory);
        $manager->registerServer($workers);
        new ReflectionProperty(DaemonManager::class, 'uploadMemory')->setValue($manager, $memory);
        $fixture = null;
        $exchange = null;
        if ($tls) {
            $fixture = new MutualTlsFixture();
            $authority = $fixture->issueAuthority('Peer fairness CA');
            $trust = $fixture->trustFile($authority);
            $exchange = new TlsTestExchange($fixture,
                $fixture->issueNode('A', $authority), $fixture->issueNode('B', $authority), $trust, $trust);
            $exchange->handshake();
            self::assertSame([], $exchange->failures);
            $loop = $exchange->loop;
            $peerPair = [$exchange->sockets['accepting'], $exchange->sockets['dialing']];
        } else {
            $loop = new EventLoop();
            self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $peerPair));
        }
        $sockets = $tls ? [] : $peerPair;
        $links = [];
        $servers = [];
        $contexts = [];
        $ready = ['A' => false, 'B' => false, 'worker' => false, 'replacement' => false];
        foreach (['A', 'B'] as $index => $node) {
            socket_set_nonblock($peerPair[$index]);
            $server = $servers[$node] = new ReflectionClass(PeerServer::class)->newInstanceWithoutConstructor();
            $identity = NodeIdentity::of($node, 'Node ' . $node, NodeRole::Master, []);
            new ReflectionProperty(PeerServer::class, 'localIdentity')->setValue($server, $identity);
            $end = $node === 'A' ? 'accepting' : 'dialing';
            $link = $links[$node] = new PeerLink($peerPair[$index], $server, $identity, false,
                $exchange === null ? new PlainSocketTransport($peerPair[$index]) : $exchange->transports[$end]);
            $remote = $node === 'A' ? 'B' : 'A';
            $link->activate(NodeIdentity::of($remote, 'Node ' . $remote, NodeRole::Master, []));
            new ReflectionMethod(AbstractServer::class, 'addClient')->invoke($server, $link);
            new ReflectionMethod(PeerServer::class, 'attachClient')->invoke($server, $link);
            new ReflectionProperty(PeerServer::class, 'activeLinks')->setValue($server, [$remote => $link]);
            $server->setUploadMemoryMaster($node === 'A' ? $memory : $receiverMemory);
            $contexts[$node] = new ReflectionClass(ClusterContext::class)->newInstanceWithoutConstructor();
            $exchange?->bindClient($end, $link, static function () use ($contexts, $node): void { Hilos::$cluster = $contexts[$node]; });
            $link->attachEventLoop($loop, static function () use (&$ready, $node): void { $ready[$node] = true; });
        }
        $manager->registerServer($servers['A']);
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($manager, $loop);
        new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($manager);
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $workerPair));
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $replacementPair));
        array_push($sockets, ...$workerPair, ...$replacementPair);
        foreach ([...$workerPair, ...$replacementPair] as $socket) {
            socket_set_nonblock($socket);
        }
        $worker = new WorkerClient($workerPair[0], $roster, $workers);
        $replacementWorker = new WorkerClient($replacementPair[0], $roster, $workers);
        foreach (['worker' => $worker, 'replacement' => $replacementWorker] as $key => $channel) {
            new ReflectionProperty(WorkerClient::class, 'workerIndex')->setValue($channel, 1);
            new ReflectionProperty(WorkerClient::class, 'isRegistered')->setValue($channel, true);
            $channel->setUploadMemoryPoolSink($receiverMemory);
            $channel->attachEventLoop($loop, static function () use (&$ready, $key): void { $ready[$key] = true; });
        }
        $laterAgent = new DeliveryFairnessAgentDaemon();
        $laterAgent->setWorkerClient($worker);
        $roster->addAgent('later_peer_probe', $laterAgent, 1, false);
        $roster->handleAgentStarted(new WorkerAgentStartedDTO('later_peer_probe', 'later_peer_probe'));
        $sink = new DeliveryFairnessPeerSink($receiverMemory, $worker);
        $contexts['B']->registerAgentSignalSink($sink);
        $pump = static function () use ($loop, $links, $contexts, &$ready): void {
            $loop->tick();
            foreach ($links as $node => $link) {
                Hilos::$cluster = $contexts[$node];
                if ($ready[$node]) {
                    $ready[$node] = false;
                    $link->read();
                }
                $link->write();
            }
            Hilos::$cluster = null;
        };
        $raw = [];
        $expected = [];
        $state = new ReflectionMethod(DaemonManager::class, 'signalDispatchState')->invoke($manager);
        $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignals');
        $queue = new ReflectionMethod(DaemonManager::class, 'queueSignalEffect');
        $identities = new ReflectionMethod(DaemonManager::class, 'identitySignals')->invoke($manager);
        $holds = new ReflectionMethod(DaemonManager::class, 'agentSignalHolds')->invoke($manager);
        $late = new SignalDTO(new SignalSource(SignalSource::DAEMON), new SignalType(SignalTypeConstants::SYSTEM),
            new SignalName('later-peer-category'), new SignalData());
        $laterEffect = static function (): Generator {
            for ($step = 0; $step < 65; $step++) {
                yield;
            }
        };
        $replacementLink = null;
        $captured = 0;
        $rebound = false;
        $expectedCount = $outcome === 'replaced' ? 0 : (in_array($outcome, ['delayed', 'transport-rebound'], true) ? 2 : 1);
        $deliver = new ReflectionMethod(DaemonManager::class, 'deliverToAgentDestination');
        try {
            $pool = new ReflectionProperty(UploadMemoryMaster::class, 'pool')->getValue($memory);
            $peer = new ReflectionMethod(UploadMemoryMaster::class, 'peer')->invoke($memory, $links['A']);
            $uploads = [];
            for ($index = 0; $index < 2; $index++) {
                $acceptKey = 'peer-browser-' . $index;
                $remote = new UploadMemoryRemoteUpload($pool, $peer, $acceptKey, 'file', 1, true);
                $uploads[$acceptKey]['file'] = $remote;
                new ReflectionProperty(UploadMemoryMaster::class, 'remoteUploads')->setValue($memory, $uploads);
                self::assertNull($remote->reserveNext());
                $window = null;
                if ($exchange === null) {
                    for ($pass = 0; $pass < 64 && $window === null; $pass++) {
                        $pump();
                        $window = $remote->reserveNext();
                    }
                } else {
                    $exchange->runUntil(static function () use ($pump, $remote, &$window): bool {
                        $pump();
                        $window = $remote->reserveNext();
                        return $window !== null;
                    });
                }
                self::assertNotNull($window);
                $input = $memory->getUploadInput($acceptKey);
                $input->allow($window);
                $frame = new WebSocketFrameBinarySignalDTO($acceptKey, "\x04file" . (string)$index);
                $input->observe($frame->payload, strlen($frame->payload));
                $receipt = $input->finishMessage();
                self::assertNotNull($receipt);
                $memory->onUploadFrameQueued($frame, $receipt);
                $raw[] = WeakReference::create($frame);
                $expected[] = $frame->toArray();
                $signal = new SignalDTO(new SignalSource(SignalSource::WEBSOCKET), new SignalType(SignalTypeConstants::FRAME_BINARY),
                    new SignalName(SignalTypeConstants::FRAME_BINARY), $frame);
                $original = new SignalDispatch($signal, static function (SignalDispatch $dispatch) use (&$captured,
                    $manager, $workers, $servers, $deliver, $memory, $outcome): Generator {
                    $signal = $dispatch->getSignal();
                    $physical = $servers['A']->addressedDelivery('B', static function () use (
                        $manager, $workers, $servers, $deliver, $signal,
                    ): void {
                        $deliver->invoke($manager, $workers, $servers['A'],
                            new RemoteAgentDestination('B', DeliveryFairnessAgentDaemon::AGENT_TYPE), $signal);
                    });
                    $captured++;
                    try {
                        for ($step = 0; $step < 130; $step++) {
                            yield;
                        }
                        if ($outcome === 'failed' && $signal->data->acceptKey === 'peer-browser-0') {
                            throw new RuntimeException('Original peer continuation failed');
                        }
                        yield from $physical;
                    } finally {
                        $memory->onUploadConnectionClosed($signal->data->acceptKey);
                    }
                });
                $original->peerFanoutId = ++$state->peerSequence;
                $original->peerOrigin = 'original-peer';
                $state->peers[$original->peerFanoutId] = $original;
            }
            unset($frame, $signal, $original, $remote, $uploads, $peer, $input, $receipt, $window);
            self::assertCount(2, $raw, 'Both original admissions precede the independent browser prefix');
            $state->browsers = new ReflectionMethod(DaemonManager::class, 'newSignalDispatchPrefix')->invoke($manager);
            $changed = false;
            $wire = '';
            $finished = static fn(): bool => ($state->browsers->complete || $state->browsers->failed)
                && $raw[0]->get() === null && $raw[1]->get() === null && $memory->inspect()['usedBytes'] === 0
                && $receiverMemory->inspect()['usedBytes'] === count($sink->packets)
                && count($sink->packets) === $expectedCount;
            $advance = static function () use ($queue, $manager, $laterEffect, $state, $identities, $late, $holds, $dispatch,
                &$changed, &$captured, $raw, $memory, $outcome, $servers, &$sockets, &$replacementLink, $links, $pump,
                $receiverMemory, &$rebound, $exchange, &$ready, $loop, $worker, &$wire, $workerPair): void {
                $queue->invoke($manager, $laterEffect);
                for ($later = 0; $later < 70; $later++) {
                    if ($later === 0 || count($state->commandCancellations) < 70) {
                        $id = ++$state->commandCancellationSequence;
                        $cancellation = new CommandSignalCancellation($id, 'later-peer-' . $id, 0);
                        $cancellation->holdsComplete = true;
                        $state->commandCancellations[$id] = $cancellation;
                    }
                    if ($later === 0 || count($state->identities) < 70) {
                        $id = ++$state->identitySequence;
                        $identities->add($id, 'later-peer-' . $id, 'later');
                        $identities->release($id, $id);
                        $state->identities[$id] = $late;
                    }
                    if ($later === 0 || count($state->peers) < 70) {
                        $peer = new SignalDispatch(null, static function (): Generator { yield; yield; });
                        $peer->peerFanoutId = ++$state->peerSequence;
                        $state->peers[$peer->peerFanoutId] = $peer;
                    }
                    if ($later === 0 || Hilos::$sr->getQueuedSignalCount() < 70) {
                        Hilos::$sr->queueSignal($late->signalSource, $late->signalType, $late->signalName, $late->data);
                    }
                }
                self::assertGreaterThanOrEqual(70, count($state->commandCancellations));
                self::assertGreaterThanOrEqual(70, count($state->identities));
                self::assertGreaterThanOrEqual(70, count($state->peers));
                self::assertGreaterThanOrEqual(70, Hilos::$sr->getQueuedSignalCount());
                $hold = $holds->add(new ParkedAgentSignal($late, 'later_peer_probe', microtime(true), false, true));
                $holds->release($hold->id);
                unset($cancellation, $peer, $hold);
                $dispatch->invoke($manager);
                $queue->invoke($manager, $laterEffect);
                if (!$changed && $captured > 0) {
                    self::assertFalse($state->browsers->complete, 'An original suspended physical attempt is still prefix debt');
                    self::assertNotNull($raw[0]->get());
                    self::assertSame(2, $memory->inspect()['usedBytes'], 'A suspended attempt still owns both original raw bytes');
                    $changed = true;
                    if ($outcome === 'replaced') {
                        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $routePair));
                        array_push($sockets, ...$routePair);
                        foreach ($routePair as $socket) {
                            socket_set_nonblock($socket);
                        }
                        $replacementLink = new PeerLink($routePair[0], $servers['A'],
                            NodeIdentity::of('A', 'Node A', NodeRole::Master, []), false, new PlainSocketTransport($routePair[0]));
                        $replacementLink->activate(NodeIdentity::of('B', 'Node B', NodeRole::Master, []));
                        new ReflectionMethod(AbstractServer::class, 'addClient')->invoke($servers['A'], $replacementLink);
                        new ReflectionMethod(PeerServer::class, 'attachClient')->invoke($servers['A'], $replacementLink);
                        new ReflectionProperty(PeerServer::class, 'activeLinks')->setValue($servers['A'], ['B' => $replacementLink]);
                    } elseif ($outcome === 'reattached') {
                        $servers['A']->removeClient($links['A']);
                        new ReflectionMethod(AbstractServer::class, 'addClient')->invoke($servers['A'], $links['A']);
                        new ReflectionMethod(PeerServer::class, 'attachClient')->invoke($servers['A'], $links['A']);
                    } elseif ($outcome === 'canceled') {
                        $state->active->cancel();
                    }
                }
                $pump();
                // Account accepted I/O before waiting: no further socket event is owed for this bookkeeping.
                $memory->afterDispatch();
                $receiverMemory->afterDispatch();
                if ($outcome === 'transport-rebound' && !$rebound && $raw[0]->get() === null && $links['A']->hasPendingWrite()) {
                    $transport = $exchange->transports['accepting'];
                    $generation = new ReflectionProperty(TlsSocketTransport::class, 'generation')->getValue($transport);
                    $oldNotification = new ReflectionProperty(TlsSocketTransport::class, 'notification')->getValue($transport);
                    self::assertNotNull($oldNotification);
                    $links['A']->detachEventLoop();
                    $ready['A'] = false;
                    $links['A']->attachEventLoop($loop, static function () use (&$ready): void { $ready['A'] = true; });
                    self::assertGreaterThan($generation, new ReflectionProperty(TlsSocketTransport::class, 'generation')->getValue($transport));
                    $oldNotification->markReady();
                    $memory->afterDispatch();
                    self::assertSame(2, $memory->inspect()['usedBytes'], 'Rebinding or a stale cue cannot accept native output for its receiver');
                    $rebound = true;
                    unset($oldNotification, $transport);
                }
                $worker->write();
                $wire .= new PlainSocketTransport($workerPair[1])->read(65536);
            };
            for ($portion = 0; $portion < 256 && !$finished(); $portion++) {
                $advance();
            }
            self::assertTrue($changed);
            self::assertSame($outcome !== 'failed', $state->browsers->complete,
                'Native peer work settles while every later category is still being produced');
            self::assertSame($outcome === 'failed', $state->browsers->failed, 'An original failure cannot acknowledge its browser prefix');
            self::assertNull($raw[0]->get());
            self::assertNull($raw[1]->get());
            // Prefix/raw completion above keeps its fixed portion bound; actual receipts follow native readiness.
            if ($exchange !== null && !$finished()) {
                $exchange->runUntil(static function () use ($advance, $finished): bool {
                    $advance();
                    return $finished();
                });
            }
            self::assertNotEmpty($state->effects);
            self::assertNotEmpty($state->commandCancellations);
            self::assertNotEmpty($state->identities);
            self::assertNotEmpty($state->peers);
            self::assertTrue($holds->hasReleased());
            self::assertTrue(Hilos::$sr->hasQueuedSignals());
            self::assertSame(0, $memory->inspect()['usedBytes'], 'Actual peer acceptance or raw deletion releases the source');
            $delivered = $expectedCount === 2 ? $expected : ($expectedCount === 0 ? [] : [$expected[1]]);
            self::assertSame($delivered, $sink->packets, 'Only successful original attempts reach the receiver in order');
            $packets = [];
            $receipts = [];
            foreach (array_filter(explode("\n", trim($wire))) as $line) {
                $packet = WorkerDTO::factoryWorkerDTO($line);
                self::assertInstanceOf(DaemonAgentMessageDTO::class, $packet);
                if (!$packet->signal->data instanceof WebSocketFrameBinarySignalDTO) {
                    self::assertSame('later_peer_probe', $packet->agentId);
                    self::assertSame('later-peer-category', $packet->signal->signalName->getName());
                    self::assertSame([], $packet->signal->data->toArray());
                    self::assertNull($packet->uploadReceiptId);
                    continue;
                }
                self::assertSame(DeliveryFairnessAgentDaemon::AGENT_TYPE, $packet->agentId);
                $packets[] = $packet->signal->data->toArray();
                self::assertNotNull($packet->uploadReceiptId);
                $receipts[] = $packet->uploadReceiptId;
            }
            self::assertSame($delivered, $packets, 'Peer decode and actual worker bytes retain every original field and order');
            self::assertCount(count($delivered), array_unique($receipts));
            self::assertSame(count($delivered), $receiverMemory->inspect()['usedBytes']);
            if ($receipts !== []) {
                socket_write($replacementPair[1], new WorkerUploadConsumedDTO($receipts)->toJson() . "\n");
                for ($pass = 0; $pass < 8 && !$ready['replacement']; $pass++) {
                    $loop->tick();
                }
                self::assertTrue($ready['replacement']);
                $replacementWorker->read();
                self::assertSame(count($delivered), $receiverMemory->inspect()['usedBytes'], 'The same worker index is a foreign receipt generation');
                socket_write($workerPair[1], new WorkerUploadConsumedDTO([...$receipts, ...$receipts, 'stale-peer-receipt'])->toJson() . "\n");
                for ($pass = 0; $pass < 8 && !$ready['worker']; $pass++) {
                    $loop->tick();
                }
                self::assertTrue($ready['worker']);
                $worker->read();
                self::assertSame(0, $receiverMemory->inspect()['usedBytes'], 'Only the original channel consumes its real accepted receipts once');
            }
            if ($replacementLink !== null) {
                self::assertFalse($replacementLink->hasPendingWrite(), 'No old granted raw or control migrates to a replacement peer');
            }
            self::assertSame($outcome === 'transport-rebound', $rebound, 'The rebind case must cross the actual native output window');
            if ($exchange !== null) {
                self::assertSame([], $exchange->failures);
            }
        } finally {
            new ReflectionMethod(DaemonManager::class, 'discardSignalDispatch')->invoke($manager);
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($manager);
            foreach ([$worker, $replacementWorker, ...array_values($links)] as $client) {
                $client->detachEventLoop();
            }
            if ($exchange !== null) {
                $exchange->close();
                $fixture->close();
            } else {
                $loop->close();
            }
            foreach ($sockets as $socket) {
                socket_close($socket);
            }
            $manager->releaseEventLoop();
            Hilos::$env = $previousEnv;
            Hilos::$sr = $previousRouter;
            Hilos::$cluster = $previousCluster;
        }
    }

    /**
     * @param bool $preempt Whether the newer release was selected before the older hold became ready
     * @param string $outcome Older work's terminal outcome
     * @param bool $cancellations Whether later already-ready cancellation debt also arrives every portion
     */
    #[DataProvider('releasedOutcomes')]
    public function testReleasedWorkerOrderAndRealReceiptsProgressUnderContinuousLaterEffects(
        bool $preempt, string $outcome, bool $cancellations,
    ): void
    {
        $previousEnv = Hilos::$env;
        $previousRouter = Hilos::$sr;
        $previousCluster = Hilos::$cluster;
        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = null;
        $manager = new DeliveryFairnessManager();
        $roster = new ReflectionProperty(DaemonManager::class, 'agentManagerDaemon')->getValue($manager);
        $memory = new UploadMemoryMaster('released-fairness');
        $memory->setTestLimit(2);
        $workers = new DeliveryFairnessWorkerServer($roster, $memory);
        $manager->registerServer($workers);
        new ReflectionProperty(DaemonManager::class, 'uploadMemory')->setValue($manager, $memory);
        $pair = [];
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        socket_set_nonblock($pair[0]);
        socket_set_nonblock($pair[1]);
        $worker = new WorkerClient($pair[0], $roster, $workers);
        new ReflectionProperty(WorkerClient::class, 'workerIndex')->setValue($worker, 1);
        new ReflectionProperty(WorkerClient::class, 'isRegistered')->setValue($worker, true);
        $worker->setUploadMemoryPoolSink($memory);
        $agent = new DeliveryFairnessAgentDaemon();
        $agent->setWorkerClient($worker);
        $roster->addAgent(DeliveryFairnessAgentDaemon::AGENT_TYPE, $agent, 1, false);
        $roster->handleAgentStarted(new WorkerAgentStartedDTO(DeliveryFairnessAgentDaemon::AGENT_TYPE,
            DeliveryFairnessAgentDaemon::AGENT_TYPE));
        $loop = new EventLoop();
        new ReflectionProperty(DaemonManager::class, 'eventLoop')->setValue($manager, $loop);
        new ReflectionMethod(DaemonManager::class, 'attachSignalDispatchEventLoop')->invoke($manager);
        $ready = false;
        $worker->attachEventLoop($loop, static function () use (&$ready): void { $ready = true; });
        $holds = new ReflectionMethod(DaemonManager::class, 'agentSignalHolds')->invoke($manager);
        $ids = [];
        $raw = [];
        $expected = [];
        for ($index = 0; $index < 2; $index++) {
            $memory->enqueueTest('local', DeliveryFairnessAgentDaemon::AGENT_TYPE, null, 1,
                static function ($delivery, SignalDTO $signal) use ($holds, &$ids, &$raw, &$expected): void {
                    $raw[] = WeakReference::create($signal->data);
                    $expected[] = $signal->data->toArray();
                    $ids[] = $holds->add(new ParkedAgentSignal($signal, DeliveryFairnessAgentDaemon::AGENT_TYPE,
                        microtime(true), false, true))->id;
                });
        }
        $state = new ReflectionMethod(DaemonManager::class, 'signalDispatchState')->invoke($manager);
        $selected = $preempt ? $ids[1] : $ids[0];
        $holds->release($selected);
        $state->released = new ReflectionMethod(DaemonManager::class, 'createReleasedAgentDispatch')
            ->invoke($manager, $holds->get($selected), $workers, null);
        $holds->begin($selected, $state->released);
        $holds->release($preempt ? $ids[0] : $ids[1]);
        $state->browsers = new ReflectionMethod(DaemonManager::class, 'newSignalDispatchPrefix')->invoke($manager);
        $failure = new RuntimeException('original worker delivery failed');
        if ($outcome === 'failed') {
            $workers->failure = $failure;
            $workers->failureAcceptKey = $expected[0][WebSocketFrameBinarySignalDTO::ACCEPT_KEY];
        }
        $dispatch = new ReflectionMethod(DaemonManager::class, 'dispatchSignals');
        $queue = new ReflectionMethod(DaemonManager::class, 'queueSignalEffect');
        $failures = [];
        $canceled = false;
        $preempted = false;
        $wire = '';
        try {
            for ($portion = 0; $portion < 128; $portion++) {
                $loop->tick();
                if ($state->released?->heldId === $ids[0]) {
                    $preempted = $preempted || in_array($ids[1], $state->suspendedReleases, true);
                    if ($outcome === 'canceled' && !$canceled) {
                        $holds->settle($ids[0]);
                        $canceled = true;
                    }
                }
                $queue->invoke($manager, static function (): Generator {
                    yield;
                    for ($step = 0; $step < 130; $step++) {
                        yield;
                    }
                });
                if ($cancellations) {
                    for ($later = 0; $later < 70 && ($later === 0 || count($state->commandCancellations) < 70); $later++) {
                        $id = ++$state->commandCancellationSequence;
                        $cancellation = new CommandSignalCancellation($id, 'later-' . $id, 0);
                        $cancellation->holdsComplete = true;
                        $state->commandCancellations[$id] = $cancellation;
                    }
                    unset($cancellation);
                }
                try {
                    $dispatch->invoke($manager);
                } catch (RuntimeException $actual) {
                    self::assertSame($failure, $actual);
                    $failures[] = $actual;
                }
                $worker->write();
                $wire .= new PlainSocketTransport($pair[1])->read(16384);
                if ($raw[0]->get() === null && $raw[1]->get() === null
                    && ($state->browsers->complete || $state->browsers->failed)) {
                    break;
                }
            }
            self::assertLessThan(127, $portion, 'Later effects must not starve either original one-agent continuation');
            self::assertNotEmpty($state->effects, 'The later producer is still active when original work settles');
            if ($cancellations) {
                self::assertNotEmpty($state->commandCancellations, 'Later ready cancellation debt remains at original settlement');
            }
            self::assertSame($outcome === 'failed' ? [$failure] : [], $failures);
            self::assertSame($outcome === 'canceled', $canceled);
            self::assertSame($outcome === 'failed', $state->browsers->failed);
            self::assertSame($outcome !== 'failed', $state->browsers->complete);
            if ($preempt) {
                self::assertTrue($preempted, 'The selected newer cursor must suspend for its older same-agent predecessor');
            }
            self::assertNull($raw[0]->get());
            self::assertNull($raw[1]->get());
            $packets = [];
            $receipts = [];
            foreach (explode("\n", trim($wire)) as $line) {
                $packet = WorkerDTO::factoryWorkerDTO($line);
                self::assertInstanceOf(DaemonAgentMessageDTO::class, $packet);
                self::assertSame(DeliveryFairnessAgentDaemon::AGENT_TYPE, $packet->agentId);
                self::assertNotNull($packet->uploadReceiptId);
                $packets[] = $packet->signal->data->toArray();
                $receipts[] = $packet->uploadReceiptId;
            }
            self::assertSame($outcome === 'complete' ? $expected : [$expected[1]], $packets,
                'The actual worker journal preserves original order, contents and exactly one addressed send');
            self::assertSame(count($receipts), count(array_unique($receipts)));
            self::assertSame(count($receipts), $memory->inspect()['usedBytes'],
                'Physical raw release and successful afterDispatch must not release accepted worker debt');
            $ready = false;
            socket_write($pair[1], new WorkerUploadConsumedDTO($receipts)->toJson() . "\n");
            for ($pass = 0; $pass < 8 && !$ready; $pass++) {
                $loop->tick();
            }
            self::assertTrue($ready, 'The actual receipt bytes wake the native worker channel');
            $worker->read();
            self::assertSame(0, $memory->inspect()['usedBytes'], 'Only the real worker receipt decoder settles its accepted bytes');
        } finally {
            new ReflectionMethod(DaemonManager::class, 'discardSignalDispatch')->invoke($manager);
            $worker->close();
            socket_close($pair[1]);
            new ReflectionMethod(DaemonManager::class, 'detachSignalDispatchEventLoop')->invoke($manager);
            $loop->close();
            $manager->releaseEventLoop();
            Hilos::$env = $previousEnv;
            Hilos::$sr = $previousRouter;
            Hilos::$cluster = $previousCluster;
        }
    }
}

/** Seeds a registered in-process channel; child process lifecycle is exercised by the cluster upload scenario. */
final class DeliveryFairnessWorkerServer extends WorkerServer
{
    public ?RuntimeException $failure = null;

    public ?string $failureAcceptKey = null;

    /**
     * @param AgentManagerDaemon $roster Original agent-to-channel mapping
     * @param UploadMemoryMaster $memory Actual raw-byte receipt owner
     */
    public function __construct(AgentManagerDaemon $roster, private readonly UploadMemoryMaster $memory)
    {
        new ReflectionProperty(WorkerServer::class, 'agentManager')->setValue($this, $roster);
    }

    /**
     * @param string $agentType Original addressed type
     * @param ?string $agentIndex Original instance key
     * @param DaemonAgentMessageDTO $messageDto Original delivery to serialize over the real worker socket
     */
    public function sendSignalToAgent(string $agentType, ?string $agentIndex, DaemonAgentMessageDTO $messageDto): void
    {
        if ($messageDto->signal->data instanceof WebSocketFrameBinarySignalDTO) {
            if ($this->failure !== null && $messageDto->signal->data->acceptKey === $this->failureAcceptKey) {
                throw $this->failure;
            }
            $roster = new ReflectionProperty(WorkerServer::class, 'agentManager')->getValue($this);
            $receipt = $this->memory->assignUploadFrame($messageDto->signal->data, $roster->getAgent($messageDto->agentId)->getWorkerClient());
            $messageDto = new DaemonAgentMessageDTO($messageDto->agentId, $messageDto->signal, $receipt);
        }
        parent::sendSignalToAgent($agentType, $agentIndex, $messageDto);
    }

    /** This fixture uses its seeded channel without starting child processes. */
    protected function onStart(): void
    {
    }
}

final class DeliveryFairnessAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = 'delivery_fairness_probe';

    /** @return bool This fixture shares the seeded worker channel */
    public function requiresMonopolisticProcess(): bool
    {
        return false;
    }
}

final class DeliveryFairnessAgentManager extends AgentManagerDaemon
{
    /**
     * @param string $agentType Unused fixture type
     * @param ?string $agentIndex Unused fixture instance key
     * @return AgentDaemonInterface Fixture daemon for the in-process roster
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        return new DeliveryFairnessAgentDaemon();
    }
}

final class DeliveryFairnessManager extends DaemonManager
{
    /** Releases the already closed fixture base while retained delivery data finishes its lifetime. */
    public function releaseEventLoop(): void
    {
        unset($this->eventLoop);
    }

    /** @return SignalRouter The existing production router with no project topology */
    protected function createSignalRouter(): SignalRouter
    {
        return new DeliveryFairnessRouter();
    }

    /** @return AgentManagerDaemon Fixture roster, with the original master start and hold hooks */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new DeliveryFairnessAgentManager();
    }
}

/** Routes the original raw to the seeded channel without a project topology or child process. */
final class DeliveryFairnessRouter extends SignalRouter
{
    /**
     * @param SignalDTO $signal Original queued envelope
     * @return list<Destination> Only raw test traffic has an agent destination
     */
    protected function additionalDestinations(SignalDTO $signal): array
    {
        return $signal->data instanceof WebSocketFrameBinarySignalDTO ? [new AgentDestination(DeliveryFairnessAgentDaemon::AGENT_TYPE)] : [];
    }
}

/** The receiver uses the actual worker send and receipt owner after production peer decoding. */
final class DeliveryFairnessPeerSink implements AgentSignalSink
{
    /** @var list<array<string, mixed>> Original peer payloads observed before assigning worker receipts */
    public array $packets = [];

    /**
     * @param UploadMemoryMaster $memory Actual receiver ledger
     * @param WorkerClient $worker Original consuming channel
     */
    public function __construct(private readonly UploadMemoryMaster $memory, private readonly WorkerClient $worker)
    {
    }

    /**
     * @param string $agentType Peer-decoded addressee
     * @param ?string $agentIndex Peer-decoded instance
     * @param SignalDTO $signal Peer-decoded raw upload
     */
    public function deliverSignalToAgent(string $agentType, ?string $agentIndex, SignalDTO $signal): void
    {
        TestCase::assertSame(DeliveryFairnessAgentDaemon::AGENT_TYPE, $agentType);
        TestCase::assertNull($agentIndex);
        TestCase::assertInstanceOf(WebSocketFrameBinarySignalDTO::class, $signal->data);
        TestCase::assertSame(SignalSource::WEBSOCKET, $signal->signalSource->getSource());
        TestCase::assertSame(SignalTypeConstants::FRAME_BINARY, $signal->signalType->getType());
        TestCase::assertSame(SignalTypeConstants::FRAME_BINARY, $signal->signalName->getName());
        TestCase::assertSame([], $signal->meta);
        $this->packets[] = $signal->data->toArray();
        $receipt = $this->memory->assignUploadFrame($signal->data, $this->worker);
        $this->worker->send(new DaemonAgentMessageDTO($agentType, $signal, $receipt)->toJson());
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Generator;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Connections\ClusterClientLocation;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Placement\AgentLocation;
use Hilos\Cluster\WorkerPlacement;
use Hilos\Cluster\Peer\DTO\PeerClientSignalDTO;
use Hilos\Cluster\Peer\DTO\PeerSignalDTO;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\SignalDispatch;
use Hilos\Core\Daemon\SignalDispatchPhase;
use Hilos\Core\Daemon\SignalDispatchPrefix;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Group\AbstractGroup;
use Hilos\Core\Group\GroupMembership;
use Hilos\Core\Group\GroupNameMatch;
use Hilos\Core\Group\DTO\GroupJoinSignalData;
use Hilos\Core\Group\DTO\GroupLeaveAllSignalData;
use Hilos\Core\Router\Destination\RemoteClientDestination;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Server\AbstractServer;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Transport\PlainSocketTransport;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupUnsubscribeSignalDTO;
use Hilos\Socket\WebSocket\DTO\WebSocketGroupUpdateSubscriptionSignalDTO;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\DTO\WorkerAgentStartedDTO;
use Hilos\Socket\Worker\DTO\WorkerGroupJoinDTO;
use Hilos\Socket\Worker\DTO\WorkerGroupLeaveAllDTO;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Socket;
use WeakReference;

/**
 * The selection-to-send window is inside one master pass, shorter than a stand tick.
 * Real worker receipt parsing and peer bytes exercise it in one process without a clock wait.
 */
final class DaemonManagerGroupAuthorityTest extends TestCase
{
    private ?EnvAccessor $previousEnv;
    private ?SignalRouter $previousRouter;
    private ?ClusterContext $previousCluster;
    private GroupAuthorityManager $manager;
    private GroupAuthorityWorkers $workers;
    private WorkerClient $worker;
    /** @var list<Socket> Socket pairs owned by the fixture */
    private array $sockets = [];
    private Socket $workerEnd;
    private PeerServer $peers;
    private PeerLink $link;
    private Socket $peerEnd;
    private GroupAuthorityConsumer $consumer;
    private SignalRouter $mirror;

    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        $this->previousRouter = Hilos::$sr;
        $this->previousCluster = Hilos::$cluster;
        Hilos::$env = new EnvAccessor();
        Hilos::$cluster = null;
        $this->manager = new GroupAuthorityManager();
        $master = Hilos::$sr;
        $this->consumer = new GroupAuthorityConsumer();
        $this->mirror = Hilos::$sr;
        Hilos::$sr = $master;
        $roster = new ReflectionProperty(DaemonManager::class, 'agentManagerDaemon')->getValue($this->manager);
        $this->workers = new GroupAuthorityWorkers($roster);
        [$local, $this->workerEnd] = $this->pair();
        $this->worker = new WorkerClient($local, $roster, $this->workers);
        $this->worker->setWorkerIndex(1);
        new ReflectionProperty(WorkerClient::class, 'isRegistered')->setValue($this->worker, true);
        $agent = new GroupAuthorityAgent();
        $agent->setWorkerClient($this->worker);
        $roster->addAgent(GroupAuthorityAgent::AGENT_TYPE, $agent, 1, false);
        $roster->handleAgentStarted(new WorkerAgentStartedDTO(GroupAuthorityAgent::AGENT_TYPE, GroupAuthorityAgent::AGENT_TYPE));
        $this->peers = new ReflectionClass(PeerServer::class)->newInstanceWithoutConstructor();
        new ReflectionProperty(PeerServer::class, 'localIdentity')->setValue(
            $this->peers, NodeIdentity::of('A', 'A', NodeRole::Master, []),
        );
        [$local, $this->peerEnd] = $this->pair();
        $this->link = $this->attachPeer($local);
    }

    protected function tearDown(): void
    {
        new ReflectionMethod(DaemonManager::class, 'discardSignalDispatch')->invoke($this->manager);
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        Hilos::$env = $this->previousEnv;
        Hilos::$sr = $this->previousRouter;
        Hilos::$cluster = $this->previousCluster;
    }

    /** @return iterable<string, array{int, string}> All physical yield boundaries and unchanged control */
    public static function remoteWindows(): iterable
    {
        foreach ([0, 1, 2] as $step) {
            foreach (['leave', 'rejoin', 'keep'] as $change) {
                yield $step . '-' . $change => [$step, $change];
            }
        }
    }

    /**
     * @param int $step Advances after the destination's effect-free yield, through both addressed-delivery yields
     * @param string $change Worker person-change outcome
     */
    #[DataProvider('remoteWindows')]
    public function testRemoteGroupForwardKeepsOriginalMembershipAtEveryPhysicalYield(int $step, string $change): void
    {
        $this->join('g', ['person' => 'old']);
        $location = new ClusterClientLocation('A');
        $location->applySnapshot('B', ['C']);
        Hilos::$cluster = new ReflectionClass(ClusterContext::class)->newInstanceWithoutConstructor();
        Hilos::$cluster->registerClientLocation($location);
        $old = $this->groupSignal('old');
        $work = $this->remoteWork($old);
        $work->current();
        for ($advance = 0; $advance < $step; $advance++) {
            $work->next();
        }
        self::assertTrue($work->valid());
        self::assertFalse($this->link->hasPendingWrite(), 'The interleaving precedes any encoded peer frame');
        if ($change !== 'keep') {
            $this->receive(new WorkerGroupLeaveAllDTO(new GroupLeaveAllSignalData(['C'])));
            if ($change === 'rejoin') {
                // Equal names and params still denote a different admission.
                $this->join('g', ['person' => 'old']);
            }
        }
        while ($work->valid()) {
            $work->next();
        }
        $this->link->write();
        if ($change === 'keep') {
            self::assertSame($this->peerBytes($old), $this->readBytes($this->peerEnd));
        } else {
            self::assertSame('', $this->readBytes($this->peerEnd));
        }
        self::assertFalse($this->link->hasPendingWrite());
        $this->join('g', ['person' => 'new']);
        $fresh = $this->groupSignal('fresh');
        $this->finish($this->remoteWork($fresh));
        $this->link->write();
        self::assertSame($this->peerBytes($fresh), $this->readBytes($this->peerEnd));
        self::assertFalse($this->link->hasPendingWrite());
    }

    public function testPlainAddressedRemoteSendSurvivesGroupDepartureButNeverBorrowsAReplacedPeer(): void
    {
        $signal = $this->groupSignal('addressed');
        $work = $this->remoteWork($signal, new RemoteClientDestination('B', 'C'));
        $work->current();
        $work->next();
        $work->next();
        $this->receive(new WorkerGroupLeaveAllDTO(new GroupLeaveAllSignalData(['C'])));
        $this->finish($work);
        $this->link->write();
        self::assertSame($this->peerBytes($signal), $this->readBytes($this->peerEnd));
        $work = $this->remoteWork($signal, new RemoteClientDestination('B', 'C'));
        $work->current();
        $work->next();
        $work->next();
        [$local, $remote] = $this->pair();
        $replacement = $this->attachPeer($local);
        $this->finish($work);
        self::assertFalse($replacement->hasPendingWrite());
        self::assertFalse($this->link->hasPendingWrite());
        self::assertSame('', $this->readBytes($remote));
        self::assertSame('', $this->readBytes($this->peerEnd));
        $this->finish($this->remoteWork($signal, new RemoteClientDestination('B', 'C')));
        $replacement->write();
        self::assertSame($this->peerBytes($signal), $this->readBytes($remote));
    }

    /** @return iterable<string, array{bool, bool, string}> Exact and server-addressed names, rejoined or originally absent */
    public static function nameWindows(): iterable
    {
        foreach ([true, false] as $update) {
            foreach ([true, false] as $present) {
                foreach (['g', 'g:7'] as $fullName) {
                    yield ($update ? 'update' : 'unsubscribe') . ($present ? '-rejoin-' : '-future-join-') . $fullName
                        => [$update, $present, $fullName];
                }
            }
        }
    }

    /**
     * @param bool $update Update params rather than unsubscribe
     * @param bool $present Whether the original exact name was joined before lookup
     * @param string $fullName Singleton name or server-addressed membership resolved from its head
     */
    #[DataProvider('nameWindows')]
    public function testOldNameLookupCannotMutateOrForwardIntoANewJoin(bool $update, bool $present, string $fullName): void
    {
        if ($present) {
            $this->join($fullName, ['seat' => 'old']);
        } else {
            for ($index = 0; $index < 70; $index++) {
                $this->join('other:' . $index);
            }
        }
        $old = $this->operation($update, ['seat' => 'stale']);
        $dispatch = $this->operationWork($old);
        // Admission, preparation boundary, then the original name cursor's first effect-free yield.
        $this->portion($dispatch, 3);
        if (!$present) {
            $this->portion($dispatch, 64);
            self::assertTrue($dispatch->hasWork(), 'A whole portion cannot complete the original nonmatching name scan');
        }
        self::assertSame(SignalDispatchPhase::Preparation, $dispatch->phase);
        self::assertFalse($this->worker->hasPendingWrite());
        if ($present) {
            $this->receive(new WorkerGroupLeaveAllDTO(new GroupLeaveAllSignalData(['C'])));
        }
        $this->join($fullName, ['seat' => 'fresh']);
        $this->drain($dispatch);
        $this->worker->write();
        self::assertSame([['seat' => 'fresh'], ''], [$this->params($fullName), $this->readBytes($this->workerEnd)],
            'The new registry incarnation survives and no stale operation crosses to the worker');
        self::assertFalse($this->worker->hasPendingWrite());
        $fresh = $this->operation($update, ['seat' => 'updated']);
        $this->drain($this->operationWork($fresh));
        self::assertSame($update ? ['seat' => 'updated'] : null, $this->params($fullName));
        $this->worker->write();
        self::assertSame((new DaemonAgentMessageDTO(GroupAuthorityAgent::AGENT_TYPE, $fresh))->toJson() . "\n",
            $this->readBytes($this->workerEnd));
    }

    /** @param bool $update Update params rather than unsubscribe */
    #[DataProvider('ordinaryOperations')]
    public function testAbsentAndServerAddressedOperationsKeepTheirOrdinaryForward(bool $update): void
    {
        $absent = $this->operation($update, ['seat' => 'absent']);
        $this->drain($this->operationWork($absent));
        self::assertNull($this->params('g'));
        $this->worker->write();
        self::assertSame((new DaemonAgentMessageDTO(GroupAuthorityAgent::AGENT_TYPE, $absent))->toJson() . "\n",
            $this->readBytes($this->workerEnd));
        $this->join('g:7', ['seat' => 'original']);
        $held = $this->operation($update, ['seat' => 'updated']);
        $this->drain($this->operationWork($held));
        self::assertSame($update ? ['seat' => 'updated'] : null, $this->params('g:7'));
        $this->worker->write();
        self::assertSame((new DaemonAgentMessageDTO(GroupAuthorityAgent::AGENT_TYPE, $held))->toJson() . "\n",
            $this->readBytes($this->workerEnd));
    }

    /** @return iterable<string, array{bool}> Both ordinary group operations */
    public static function ordinaryOperations(): iterable
    {
        yield 'update' => [true];
        yield 'unsubscribe' => [false];
    }

    /** @return iterable<string, array{bool, bool, string, int, string}> Original membership, request and physical boundary */
    public static function preparedWindows(): iterable
    {
        foreach (self::nameWindows() as $name => [$update, $present, $fullName]) {
            foreach (array_unique(['g', $fullName]) as $requested) {
                foreach ([0, 1, 2] as $step) {
                    yield $name . '-physical-' . $step . '-request-' . $requested => [$update, $present, $fullName, $step, $requested];
                }
            }
        }
    }

    /**
     * @param bool $update Update rather than unsubscribe
     * @param bool $present Original membership was present
     * @param string $fullName Admitted name
     * @param int $step Physical yields after successful preparation
     * @param string $requested Exact full name or server-addressed head
     */
    #[DataProvider('preparedWindows')]
    public function testPreparedOperationNeverReachesANewMembership(
        bool $update, bool $present, string $fullName, int $step, string $requested,
    ): void {
        if ($present) {
            $this->join($fullName, ['seat' => 'old']);
        }
        $old = $this->operation($update, ['seat' => 'stale'], $requested);
        $dispatch = $this->operationWork($old);
        for ($portion = 0; $portion < 20 && $dispatch->phase !== SignalDispatchPhase::Destinations; $portion++) {
            $this->portion($dispatch, 1);
        }
        self::assertSame(SignalDispatchPhase::Destinations, $dispatch->phase);
        self::assertSame($present && $update ? ['seat' => 'stale'] : null, $this->params($fullName));
        $this->portion($dispatch, $step);
        self::assertFalse($this->worker->hasPendingWrite(), 'Preparation and physical selection still precede the worker frame');
        $this->receive(new WorkerGroupLeaveAllDTO(new GroupLeaveAllSignalData(['C'])));
        $params = $step === 1 ? ['seat' => 'old'] : ['seat' => 'fresh'];
        $this->join($fullName, $params);
        if ($step === 2) {
            $this->finishPhysicalPortion($dispatch);
        } else {
            $this->drain($dispatch);
        }
        $bytes = $this->consumeWorker();
        self::assertSame([$params, $params, []], [$this->params($fullName), $this->mirrorParams($fullName), $this->consumer->agent->operations],
            'The actual worker consumer must leave both mirrors on the newly admitted membership');
        self::assertSame('', $bytes, 'An old prepared operation must emit no worker frame');
        self::assertSame(SignalDispatchPhase::Canceled, $dispatch->phase);
        $fresh = $this->operation($update, ['seat' => 'updated'], $requested);
        $this->drain($this->operationWork($fresh));
        self::assertSame((new DaemonAgentMessageDTO(GroupAuthorityAgent::AGENT_TYPE, $fresh))->toJson() . "\n",
            $this->consumeWorker());
        $expected = $update ? ['seat' => 'updated'] : null;
        self::assertSame([$expected, $expected], [$this->params($fullName), $this->mirrorParams($fullName)]);
        self::assertSame([$update ? SignalTypeConstants::GROUP_UPDATE_SUBSCRIPTION : SignalTypeConstants::GROUP_UNSUBSCRIBE],
            $this->consumer->agent->operations);
    }

    /**
     * @param bool $update Update rather than unsubscribe
     * @param bool $present Original membership was present
     * @param string $fullName Admitted name
     * @param int $step Physical yields after successful preparation
     * @param string $requested Exact full name or server-addressed head
     */
    #[DataProvider('preparedWindows')]
    public function testPreparedRemoteOperationKeepsAuthorityThroughAddressedDelivery(
        bool $update, bool $present, string $fullName, int $step, string $requested,
    ): void {
        if ($present) {
            $this->join($fullName, ['seat' => 'old']);
        }
        Hilos::$cluster = new ClusterContext();
        Hilos::$cluster->registerWorkerPlacement(new class implements WorkerPlacement {
            /**
             * @param string $agentType Fixture group owner
             * @param ?string $agentIndex Fixture index
             * @return AgentLocation Real peer route to the fixture node
             */
            public function locate(string $agentType, ?string $agentIndex): AgentLocation
            {
                return AgentLocation::onNode('B');
            }
        });
        $old = $this->operation($update, ['seat' => 'stale'], $requested);
        $dispatch = $this->prepared($old, $this->peers);
        // Destination, agent physical boundary, then the two real peer addressedDelivery yields.
        $this->portion($dispatch, $step + 2);
        self::assertTrue($dispatch->hasWork());
        self::assertFalse($this->link->hasPendingWrite());
        $this->receive(new WorkerGroupLeaveAllDTO(new GroupLeaveAllSignalData(['C'])));
        $this->join($fullName, ['seat' => 'new']);
        if ($step === 2) {
            $this->finishPhysicalPortion($dispatch);
        } else {
            $this->drain($dispatch);
        }
        $this->link->write();
        self::assertSame('', $this->readBytes($this->peerEnd));
        self::assertSame(SignalDispatchPhase::Canceled, $dispatch->phase);
        self::assertSame([['seat' => 'new'], ['seat' => 'new'], []],
            [$this->params($fullName), $this->mirrorParams($fullName), $this->consumer->agent->operations]);
        $fresh = $this->operation($update, ['seat' => 'updated'], $requested);
        $this->drain($this->operationWork($fresh, $this->peers));
        $this->link->write();
        $bytes = $this->readBytes($this->peerEnd);
        self::assertSame((new PeerSignalDTO('A', 'B', GroupAuthorityAgent::AGENT_TYPE, null, $fresh))->toJson() . "\n", $bytes);
        $this->consumeSignal(PeerSignalDTO::fromArray(json_decode(trim($bytes), true, flags: JSON_THROW_ON_ERROR))->signal);
        $expected = $update ? ['seat' => 'updated'] : null;
        self::assertSame([$expected, $expected], [$this->params($fullName), $this->mirrorParams($fullName)]);
    }

    /** @param bool $update Update rather than unsubscribe */
    #[DataProvider('ordinaryOperations')]
    public function testUnrelatedMutationsKeepThePreparedEffectAndBothMirrors(bool $update): void
    {
        $this->join('g:7', ['seat' => 'old']);
        $operation = $this->operation($update, ['seat' => 'updated']);
        $dispatch = $this->prepared($operation);
        $this->join('other', ['other' => 'C']);
        $this->receive(new WorkerGroupJoinDTO(new GroupJoinSignalData('g:7', 'D', ['other' => 'D'])));
        $this->drain($dispatch);
        self::assertSame((new DaemonAgentMessageDTO(GroupAuthorityAgent::AGENT_TYPE, $operation))->toJson() . "\n", $this->consumeWorker());
        $expected = $update ? ['seat' => 'updated'] : null;
        self::assertSame([$expected, $expected, ['other' => 'C']],
            [$this->params('g:7'), $this->mirrorParams('g:7'), $this->params('other')]);
        self::assertSame(SignalDispatchPhase::Complete, $dispatch->phase);
    }

    /** @param bool $update Update rather than unsubscribe */
    #[DataProvider('ordinaryOperations')]
    public function testUnchangedAbsenceStillRunsTheActualWorkerCallbackAndRefusal(bool $update): void
    {
        $operation = $this->operation($update, ['seat' => 'absent']);
        $this->drain($this->operationWork($operation));
        self::assertSame((new DaemonAgentMessageDTO(GroupAuthorityAgent::AGENT_TYPE, $operation))->toJson() . "\n", $this->consumeWorker());
        self::assertSame([$update ? SignalTypeConstants::GROUP_UPDATE_SUBSCRIPTION : SignalTypeConstants::GROUP_UNSUBSCRIBE],
            $this->consumer->agent->operations);
        self::assertNull($this->mirrorParams('g'));
        $answers = $this->consumeMirrorSignals();
        self::assertCount($update ? 1 : 0, $answers);
        if ($update) {
            self::assertSame(SignalTypeConstants::WS_USER, $answers[0]->signalType->getType());
        }
    }

    /** @return iterable<string, array{bool, bool}> Both held operations, with and without replacement */
    public static function heldOperations(): iterable
    {
        foreach ([true, false] as $update) {
            foreach ([true, false] as $replace) {
                yield ($update ? 'update-' : 'unsubscribe-') . ($replace ? 'replace' : 'keep') => [$update, $replace];
            }
        }
    }

    /**
     * @param bool $update Update rather than unsubscribe
     * @param bool $replace Replace the original membership while the real hold is parked
     */
    #[DataProvider('heldOperations')]
    public function testRealParkAndReleaseNeverRefreshesPreparedAuthority(bool $update, bool $replace): void
    {
        $this->join('g:7', ['seat' => 'old']);
        $roster = new ReflectionProperty(DaemonManager::class, 'agentManagerDaemon')->getValue($this->manager);
        new ReflectionProperty(AgentManagerDaemon::class, 'startedAgentIds')->setValue($roster, []);
        $operation = $this->operation($update, ['seat' => 'updated']);
        $dispatch = $this->prepared($operation);
        $authority = WeakReference::create($dispatch->groupOperation);
        $this->drain($dispatch);
        $holds = new ReflectionMethod(DaemonManager::class, 'agentSignalHolds')->invoke($this->manager);
        self::assertSame(1, $holds->getParkedCount());
        self::assertNotNull($authority->get(), 'The original authority belongs to the parked obligation');
        self::assertSame('', $this->consumeWorker());
        if ($replace) {
            $this->receive(new WorkerGroupLeaveAllDTO(new GroupLeaveAllSignalData(['C'])));
            $this->join('g:7', ['seat' => 'new']);
        }
        $roster->handleAgentStarted(new WorkerAgentStartedDTO(GroupAuthorityAgent::AGENT_TYPE, GroupAuthorityAgent::AGENT_TYPE));
        $id = $holds->candidates()->current();
        $holds->release($id);
        $released = new ReflectionMethod(DaemonManager::class, 'createReleasedAgentDispatch')->invoke(
            $this->manager, $holds->get($id), $this->workers, null,
        );
        self::assertSame($authority->get(), $released->groupOperation);
        $holds->begin($id, $released);
        $this->drain($released);
        $holds->settle($id);
        $bytes = $this->consumeWorker();
        if ($replace) {
            self::assertSame('', $bytes);
            self::assertSame([['seat' => 'new'], ['seat' => 'new'], []],
                [$this->params('g:7'), $this->mirrorParams('g:7'), $this->consumer->agent->operations]);
        } else {
            self::assertSame((new DaemonAgentMessageDTO(GroupAuthorityAgent::AGENT_TYPE, $operation))->toJson() . "\n", $bytes);
            $expected = $update ? ['seat' => 'updated'] : null;
            self::assertSame([$expected, $expected], [$this->params('g:7'), $this->mirrorParams('g:7')]);
        }
        self::assertNull($authority->get());
        self::assertSame(0, $holds->getParkedCount());
        self::assertNull($holds->get($id));
    }

    /** A real parked delivery leaves ordinary preparation free to remove the connection's last other membership. */
    public function testLastUnrelatedRemovalKeepsParkedUnsubscribeThroughTheRealScheduler(): void
    {
        $this->manager->registerServer($this->workers);
        $this->join('g:7', ['seat' => 'first']);
        $this->join('g:8', ['seat' => 'last']);
        $roster = new ReflectionProperty(DaemonManager::class, 'agentManagerDaemon')->getValue($this->manager);
        new ReflectionProperty(AgentManagerDaemon::class, 'startedAgentIds')->setValue($roster, []);
        $first = $this->operation(false, [], 'g');
        Hilos::$sr->queueSignal($first->signalSource, $first->signalType, $first->signalName, $first->data);
        $this->drainScheduler();
        $holds = new ReflectionMethod(DaemonManager::class, 'agentSignalHolds')->invoke($this->manager);
        self::assertSame(1, $holds->getParkedCount());
        $firstId = $holds->candidates()->current();
        $authority = WeakReference::create($holds->get($firstId)->parked->groupOperation);
        self::assertSame([null, ['seat' => 'first']], [$this->params('g:7'), $this->mirrorParams('g:7')]);
        self::assertSame('', $this->consumeWorker(), 'The first prepared removal is parked before any worker frame');
        $last = $this->operation(false, [], 'g:8');
        Hilos::$sr->queueSignal($last->signalSource, $last->signalType, $last->signalName, $last->data);
        $state = new ReflectionMethod(DaemonManager::class, 'signalDispatchState')->invoke($this->manager);
        $prefix = new ReflectionMethod(DaemonManager::class, 'newSignalDispatchPrefix')->invoke($this->manager);
        $state->browsers = $prefix;
        $this->drainScheduler();
        self::assertSame(2, $holds->getParkedCount(), 'A parked predecessor permits the next ordinary preparation');
        self::assertSame([null, null], [$this->params('g:7'), $this->params('g:8')]);
        self::assertSame([['seat' => 'first'], ['seat' => 'last']], [$this->mirrorParams('g:7'), $this->mirrorParams('g:8')]);
        self::assertSame('', $this->consumeWorker());
        $currentAfterLastRemoval = $authority->get()?->isCurrent();
        $this->receive(new WorkerAgentStartedDTO(GroupAuthorityAgent::AGENT_TYPE, GroupAuthorityAgent::AGENT_TYPE));
        $this->drainScheduler();
        self::assertSame(
            (new DaemonAgentMessageDTO(GroupAuthorityAgent::AGENT_TYPE, $first))->toJson() . "\n"
                . (new DaemonAgentMessageDTO(GroupAuthorityAgent::AGENT_TYPE, $last))->toJson() . "\n",
            $this->consumeWorker(),
            'Both actual worker frames must arrive once, in same-agent predecessor order',
        );
        self::assertSame([null, null, null, null],
            [$this->params('g:7'), $this->params('g:8'), $this->mirrorParams('g:7'), $this->mirrorParams('g:8')]);
        self::assertSame([SignalTypeConstants::GROUP_UNSUBSCRIBE, SignalTypeConstants::GROUP_UNSUBSCRIBE],
            $this->consumer->agent->operations);
        self::assertTrue($currentAfterLastRemoval);
        self::assertFalse($prefix->failed, 'Structural cleanup cannot fail the captured completion prefix');
        self::assertTrue($prefix->complete);
        self::assertSame(0, $holds->getParkedCount());
        self::assertNull($holds->get($firstId));
        self::assertNull($authority->get());
        $this->drainScheduler();
        self::assertSame('', $this->consumeWorker(), 'Settled releases cannot run twice');
    }

    public function testCanceledPreparedEffectCannotPayItsCapturedCompletionPrefixes(): void
    {
        $this->join('g', ['seat' => 'old']);
        $dispatch = $this->prepared($this->operation(false, []));
        $state = new ReflectionMethod(DaemonManager::class, 'signalDispatchState')->invoke($this->manager);
        $state->stop = new SignalDispatchPrefix(1, $state->intake, [], 0, 0, 0);
        $state->browsers = new SignalDispatchPrefix(2, $state->intake, [], 0, 0, 0);
        $dispatch->stopPrefixId = 1;
        $dispatch->browserPrefixId = 2;
        $state->stop->captureActive($dispatch);
        $state->browsers->captureActive($dispatch);
        $this->receive(new WorkerGroupLeaveAllDTO(new GroupLeaveAllSignalData(['C'])));
        $this->join('g', ['seat' => 'new']);
        $this->drain($dispatch);
        $state->stop->settle($dispatch);
        $state->browsers->settle($dispatch);
        self::assertTrue($state->stop->failed);
        self::assertTrue($state->browsers->failed);
        self::assertFalse($state->stop->isReadyForAccounting());
        self::assertFalse($state->browsers->isReadyForAccounting());
        self::assertSame('', $this->consumeWorker());
    }

    public function testReplacedRouterCancelsThePreparedCursorWithoutPinningItsSource(): void
    {
        $this->join('g', ['seat' => 'old']);
        $dispatch = $this->prepared($this->operation(false, []));
        $router = WeakReference::create(Hilos::$sr);
        $registry = WeakReference::create(new ReflectionProperty(SignalRouter::class, 'subscriptions')->getValue(Hilos::$sr));
        Hilos::$sr = new GroupAuthorityRouter();
        $this->join('g', ['seat' => 'new']);
        $this->drain($dispatch);
        self::assertSame(SignalDispatchPhase::Canceled, $dispatch->phase);
        // The existing intake still owns preparation cleanup until the manager's final retirement.
        new ReflectionMethod(DaemonManager::class, 'discardSignalDispatch')->invoke($this->manager);
        self::assertNull($router->get());
        self::assertNull($registry->get());
        self::assertSame('', $this->consumeWorker());
        self::assertSame([['seat' => 'new'], ['seat' => 'new']], [$this->params('g'), $this->mirrorParams('g')]);
    }

    public function testIndependentReentrantHoldDoesNotInheritTheOriginalGroupQualification(): void
    {
        $this->join('g', ['seat' => 'old']);
        $dispatch = $this->prepared($this->operation(false, []));
        $roster = new ReflectionProperty(DaemonManager::class, 'agentManagerDaemon')->getValue($this->manager);
        new ReflectionProperty(AgentManagerDaemon::class, 'startedAgentIds')->setValue($roster, []);
        $independent = new SignalDTO(new SignalSource(SignalSource::DAEMON), new SignalType(SignalTypeConstants::GROUP_UNSUBSCRIBE),
            new SignalName('other'), new WebSocketGroupUnsubscribeSignalDTO('D', 'other'));
        new ReflectionProperty(DaemonManager::class, 'currentSignalDispatch')->setValue($this->manager, $dispatch);
        try {
            new ReflectionMethod(DaemonManager::class, 'deliverToAgentDestination')->invoke(
                $this->manager, $this->workers, null, new AgentDestination(GroupAuthorityAgent::AGENT_TYPE), $independent,
            );
        } finally {
            new ReflectionProperty(DaemonManager::class, 'currentSignalDispatch')->setValue($this->manager, null);
        }
        $holds = new ReflectionMethod(DaemonManager::class, 'agentSignalHolds')->invoke($this->manager);
        self::assertSame(1, $holds->getParkedCount());
        $id = $holds->candidates()->current();
        $this->receive(new WorkerGroupLeaveAllDTO(new GroupLeaveAllSignalData(['C'])));
        $this->join('g', ['seat' => 'new']);
        $this->drain($dispatch);
        self::assertSame(SignalDispatchPhase::Canceled, $dispatch->phase);
        $roster->handleAgentStarted(new WorkerAgentStartedDTO(GroupAuthorityAgent::AGENT_TYPE, GroupAuthorityAgent::AGENT_TYPE));
        $holds->release($id);
        $released = new ReflectionMethod(DaemonManager::class, 'createReleasedAgentDispatch')->invoke(
            $this->manager, $holds->get($id), $this->workers, null,
        );
        $holds->begin($id, $released);
        $this->drain($released);
        $holds->settle($id);
        self::assertSame((new DaemonAgentMessageDTO(GroupAuthorityAgent::AGENT_TYPE, $independent))->toJson() . "\n", $this->consumeWorker());
        self::assertSame([['seat' => 'new'], ['seat' => 'new']], [$this->params('g'), $this->mirrorParams('g')]);
    }

    /**
     * @param SignalDTO $signal Operation to prepare
     * @param ?PeerServer $peers Optional real peer transport
     * @return SignalDispatch Operation stopped exactly at its successful preparation boundary
     */
    private function prepared(SignalDTO $signal, ?PeerServer $peers = null): SignalDispatch
    {
        $dispatch = $this->operationWork($signal, $peers);
        for ($portion = 0; $portion < 20 && $dispatch->phase !== SignalDispatchPhase::Destinations; $portion++) {
            $this->portion($dispatch, 1);
        }
        self::assertSame(SignalDispatchPhase::Destinations, $dispatch->phase);
        return $dispatch;
    }

    /**
     * Isolates the final physical door after guarded preparation and selection. Keeping the
     * dispatch context while bypassing only the resume guard makes its last callback observable.
     *
     * @param SignalDispatch $dispatch Production continuation stopped at its final physical yield
     */
    private function finishPhysicalPortion(SignalDispatch $dispatch): void
    {
        new ReflectionProperty(DaemonManager::class, 'currentSignalDispatch')->setValue($this->manager, $dispatch);
        try {
            $dispatch->runPortion(64);
        } finally {
            new ReflectionProperty(DaemonManager::class, 'currentSignalDispatch')->setValue($this->manager, null);
        }
        self::assertFalse($dispatch->hasWork());
    }

    /** @return array{Socket, Socket} Nonblocking connected endpoints */
    private function pair(): array
    {
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        foreach ($pair as $socket) {
            socket_set_nonblock($socket);
            $this->sockets[] = $socket;
        }
        return $pair;
    }

    /**
     * @param Socket $socket Local endpoint
     * @return PeerLink Registered active route to B
     */
    private function attachPeer(Socket $socket): PeerLink
    {
        $link = new PeerLink($socket, $this->peers, NodeIdentity::of('A', 'A', NodeRole::Master, []), false,
            new PlainSocketTransport($socket));
        $link->activate(NodeIdentity::of('B', 'B', NodeRole::Master, []));
        new ReflectionMethod(AbstractServer::class, 'addClient')->invoke($this->peers, $link);
        new ReflectionMethod(PeerServer::class, 'attachClient')->invoke($this->peers, $link);
        new ReflectionProperty(PeerServer::class, 'activeLinks')->setValue($this->peers, ['B' => $link]);
        return $link;
    }

    /** @param WorkerDTO $frame Actual worker receipt to parse */
    private function receive(WorkerDTO $frame): void
    {
        if ($frame instanceof WorkerGroupLeaveAllDTO) {
            $master = Hilos::$sr;
            Hilos::$sr = $this->mirror;
            try {
                foreach ($frame->data->acceptKeys as $acceptKey) {
                    GroupMembership::leaveAll($acceptKey);
                }
                $this->consumeMirrorSignals();
            } finally {
                Hilos::$sr = $master;
            }
        }
        $bytes = $frame->toJson() . "\n";
        self::assertSame(strlen($bytes), socket_write($this->workerEnd, $bytes));
        $this->worker->read();
    }

    /**
     * @param string $group Admitted full name
     * @param array<string, mixed> $params Admitted params
     */
    private function join(string $group, array $params = []): void
    {
        $master = Hilos::$sr;
        Hilos::$sr = $this->mirror;
        try {
            (new GroupAuthorityGroup($this->consumer->agent))->onSubscribe($group, 'C', $params);
            $this->consumeMirrorSignals();
        } finally {
            Hilos::$sr = $master;
        }
        $this->receive(new WorkerGroupJoinDTO(new GroupJoinSignalData($group, 'C', $params)));
    }

    /** @return string Complete bytes delivered through the actual worker consumer */
    private function consumeWorker(): string
    {
        $this->worker->write();
        $bytes = $this->readBytes($this->workerEnd);
        foreach (explode("\n", trim($bytes)) as $line) {
            if ($line !== '') {
                $frame = WorkerDTO::factoryWorkerDTO($line);
                self::assertInstanceOf(DaemonAgentMessageDTO::class, $frame);
                $this->consumeSignal($frame->signal);
            }
        }
        return $bytes;
    }

    /** @return list<SignalDTO> Actual worker output drained in FIFO order */
    private function consumeMirrorSignals(): array
    {
        $signals = [];
        while (($signal = $this->mirror->getNextQueuedSignal()) !== null) {
            $signals[] = $signal;
        }
        return $signals;
    }

    /** @param SignalDTO $signal Decoded worker or peer operation */
    private function consumeSignal(SignalDTO $signal): void
    {
        $master = Hilos::$sr;
        Hilos::$sr = $this->mirror;
        try {
            new ReflectionMethod(WorkerManager::class, 'handleAgentMessage')->invoke(
                $this->consumer, new DaemonAgentMessageDTO(GroupAuthorityAgent::AGENT_TYPE, $signal),
            );
        } finally {
            Hilos::$sr = $master;
        }
    }

    /**
     * @param string $group Exact full group name
     * @return ?array<string, mixed> Params on the real worker mirror
     */
    private function mirrorParams(string $group): ?array
    {
        $registry = new ReflectionProperty(SignalRouter::class, 'subscriptions')->getValue($this->mirror);
        return $registry->groupSubscriptionCandidates($group)->current()?->params;
    }

    /**
     * @param string $marker Payload marker
     * @return SignalDTO Actual group data
     */
    private function groupSignal(string $marker): SignalDTO
    {
        return new SignalDTO(new SignalSource(SignalSource::AGENT), new SignalType(SignalTypeConstants::WS_GROUP),
            new SignalName('group_data'), new WebSocketSignalData(new SignalData(['marker' => $marker]), targetGroup: 'g'));
    }

    /**
     * @param SignalDTO $signal Original envelope
     * @return string Full expected physical bytes
     */
    private function peerBytes(SignalDTO $signal): string
    {
        return (new PeerClientSignalDTO('A', 'B', 'C', $signal))->toJson() . "\n";
    }

    /**
     * @param SignalDTO $signal Actual group signal
     * @param ?RemoteClientDestination $address Explicit address, otherwise production lazy placement
     * @return Generator<int, void> Production physical delivery
     */
    private function remoteWork(SignalDTO $signal, ?RemoteClientDestination $address = null): Generator
    {
        $candidate = $address ?? Hilos::$sr->destinationCandidates($signal)->current();
        self::assertInstanceOf(RemoteClientDestination::class, $candidate);
        $dispatch = new SignalDispatch($signal, static function (): Generator { yield; });
        return new ReflectionMethod(DaemonManager::class, 'walkSignalDestination')->invoke(
            $this->manager, $dispatch, $candidate, $this->workers, null, null, null, $this->peers,
        );
    }

    /**
     * @param bool $update Update rather than unsubscribe
     * @param array<string, mixed> $params Params
     * @param string $requested Original client name
     * @return SignalDTO Operation
     */
    private function operation(bool $update, array $params, string $requested = 'g'): SignalDTO
    {
        return new SignalDTO(new SignalSource(SignalSource::WEBSOCKET),
            new SignalType($update ? SignalTypeConstants::GROUP_UPDATE_SUBSCRIPTION : SignalTypeConstants::GROUP_UNSUBSCRIBE),
            new SignalName($requested), $update ? new WebSocketGroupUpdateSubscriptionSignalDTO('C', $requested, $params)
                : new WebSocketGroupUnsubscribeSignalDTO('C', $requested));
    }

    /**
     * @param SignalDTO $signal Original operation
     * @param ?PeerServer $peers Optional real peer transport
     * @return SignalDispatch Production preparation and routing consumer
     */
    private function operationWork(SignalDTO $signal, ?PeerServer $peers = null): SignalDispatch
    {
        return new SignalDispatch($signal, fn(SignalDispatch $dispatch): Generator =>
            new ReflectionMethod(DaemonManager::class, 'walkSignal')->invoke(
                $this->manager, $dispatch, true, $this->workers, null, null, null, $peers,
            ));
    }

    /**
     * @param SignalDispatch $dispatch Original operation
     * @param int $steps Bounded step budget
     */
    private function portion(SignalDispatch $dispatch, int $steps): void
    {
        new ReflectionProperty(DaemonManager::class, 'currentSignalDispatch')->setValue($this->manager, $dispatch);
        try {
            new ReflectionMethod(DaemonManager::class, 'runSignalPortion')->invoke($this->manager, $dispatch, $steps);
        } finally {
            new ReflectionProperty(DaemonManager::class, 'currentSignalDispatch')->setValue($this->manager, null);
        }
    }

    /** @param SignalDispatch $dispatch Original operation */
    private function drain(SignalDispatch $dispatch): void
    {
        for ($portion = 0; $portion < 20 && $dispatch->hasWork(); $portion++) {
            $this->portion($dispatch, 64);
        }
        self::assertFalse($dispatch->hasWork());
    }

    /** Runs only the production scheduler's runnable portions; parked occupancy never drives this loop. */
    private function drainScheduler(): void
    {
        $runnable = new ReflectionMethod(DaemonManager::class, 'hasRunnableSignalDispatch');
        for ($portion = 0; $portion < 100 && $runnable->invoke($this->manager); $portion++) {
            new ReflectionMethod(DaemonManager::class, 'dispatchSignals')->invoke($this->manager);
        }
        self::assertFalse($runnable->invoke($this->manager));
    }

    /** @param Generator<int, void> $work Retained physical cursor */
    private function finish(Generator $work): void
    {
        while ($work->valid()) {
            $work->next();
        }
    }

    /**
     * @param string $group Exact full name
     * @return ?array<string, mixed> Current params or absent membership
     */
    private function params(string $group): ?array
    {
        $registry = new ReflectionProperty(SignalRouter::class, 'subscriptions')->getValue(Hilos::$sr);
        return $registry->groupSubscriptionCandidates($group)->current()?->params;
    }

    /**
     * @param Socket $socket Remote endpoint
     * @return string All currently available bytes, with no frame filtering
     */
    private function readBytes(Socket $socket): string
    {
        $bytes = '';
        while (true) {
            $length = socket_recv($socket, $part, 65536, MSG_DONTWAIT);
            if ($length === false) {
                self::assertContains(socket_last_error($socket), [SOCKET_EAGAIN, SOCKET_EWOULDBLOCK]);
                socket_clear_error($socket);
                return $bytes;
            }
            if ($length === 0) {
                return $bytes;
            }
            $bytes .= $part;
        }
    }
}

final class GroupAuthorityAgent extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = 'group_authority';

    /** @return bool This fixture shares its seeded worker */
    public function requiresMonopolisticProcess(): bool
    {
        return false;
    }
}

final class GroupAuthorityRoster extends AgentManagerDaemon
{
    /**
     * @param string $agentType Fixture type
     * @param ?string $agentIndex Fixture index
     * @return AgentDaemonInterface Fixture agent
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        return new GroupAuthorityAgent();
    }
}

final class GroupAuthorityRouter extends SignalRouter
{
    /** @return ?string The seeded worker owns all group operations in this fixture */
    protected function getDefaultGroupSubscriptionAgentType(): ?string
    {
        return GroupAuthorityAgent::AGENT_TYPE;
    }
}

final class GroupAuthorityManager extends DaemonManager
{
    /** @return SignalRouter Production lazy router with a fixture group owner */
    protected function createSignalRouter(): SignalRouter
    {
        return new GroupAuthorityRouter();
    }

    /** @return AgentManagerDaemon In-process agent roster */
    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new GroupAuthorityRoster();
    }
}

final class GroupAuthorityWorkers extends WorkerServer
{
    /** @param AgentManagerDaemon $roster Real agent-to-worker mapping */
    public function __construct(AgentManagerDaemon $roster)
    {
        new ReflectionProperty(WorkerServer::class, 'agentManager')->setValue($this, $roster);
    }

    /** The fixture seeds a worker channel without starting a child process. */
    protected function onStart(): void
    {
    }
}

final class GroupAuthorityConsumer extends WorkerManager
{
    public readonly GroupAuthorityWorkerAgent $agent;

    public function __construct()
    {
        parent::__construct(1);
        $this->agent = new GroupAuthorityWorkerAgent();
        $this->agentManager->addAgent(GroupAuthorityAgent::AGENT_TYPE, $this->agent);
    }

    /** @return SignalRouter Worker mirror with the real group dispatcher */
    protected function createSignalRouter(): SignalRouter
    {
        return new GroupAuthorityMirrorRouter();
    }

    /** @return AgentManager Worker-local agents, without starting another process */
    protected function createAgentManager(): AgentManager
    {
        return new GroupAuthorityWorkerRoster();
    }
}

final class GroupAuthorityWorkerRoster extends AgentManager
{
    /**
     * @param string $agentType Fixture agent type
     * @param ?string $agentIndex Fixture index
     * @return AgentInterface Fixture worker agent
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return new GroupAuthorityWorkerAgent();
    }
}

final class GroupAuthorityMirrorRouter extends SignalRouter
{
    /**
     * @param string $group Original singleton or addressed full name
     * @return ?GroupNameMatch Real group class for the worker update consumer
     */
    public function resolveGroupName(string $group): ?GroupNameMatch
    {
        return GroupNameMatch::resolve($group, ['g' => GroupAuthorityGroup::class]);
    }
}

final class GroupAuthorityWorkerAgent extends AbstractAgent
{
    public const string AGENT_TYPE = GroupAuthorityAgent::AGENT_TYPE;

    /** @var list<string> Group callbacks actually reached through WorkerManager */
    public array $operations = [];

    /**
     * @param WebSocketGroupUnsubscribeSignalDTO $data Original worker operation
     * @param string $source Original source
     * @param string $name Requested group
     */
    public function onSignalGroupUnsubscribe(WebSocketGroupUnsubscribeSignalDTO $data, string $source, string $name): void
    {
        $this->operations[] = SignalTypeConstants::GROUP_UNSUBSCRIBE;
    }

    /**
     * @param WebSocketGroupUpdateSubscriptionSignalDTO $data Original worker operation
     * @param string $source Original source
     * @param string $name Requested group
     */
    public function onSignalGroupUpdateSubscription(WebSocketGroupUpdateSubscriptionSignalDTO $data, string $source, string $name): void
    {
        $this->operations[] = SignalTypeConstants::GROUP_UPDATE_SUBSCRIPTION;
    }

    /** The fixture has no external resources. */
    public function onStop(): void
    {
    }
}

final class GroupAuthorityGroup extends AbstractGroup
{
    public const string GROUP = 'g';

    /**
     * @param string $acceptKey Admitted connection
     * @param array<string, string> $params Admitted params
     */
    protected function assertSubscribable(string $acceptKey, array $params): void
    {
    }
}

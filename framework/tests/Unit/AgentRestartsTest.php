<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Leadership;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\DTO\PeerAgentRestartDTO;
use Hilos\Cluster\Peer\DTO\PeerAgentStatusDTO;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerStopAgentDTO;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Cluster\Placement\PlacementExecutor;
use Hilos\Cluster\Placement\PlacementMesh;
use Hilos\Cluster\Placement\PlacementRecord;
use Hilos\Cluster\Placement\PlacementRestartSink;
use Hilos\Cluster\Placement\PlacementState;
use Hilos\Cluster\Placement\ResourceProfile;
use Hilos\Constants\CommandConstants;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\AgentRestarts;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Command\CommandReplyOrigins;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Socket\Server\WorkerServer;
use Closure;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/** The local restart keeps its mark until a start fact, beyond every caller's wait. */
final class AgentRestartsTest extends TestCase
{
    /** @var class-string<Hilos> */
    private string $previousAppClass;
    private ?SignalRouter $previousRouter;
    private ?RtContext $previousRt;
    private ?ClusterContext $previousCluster;
    private ?EnvAccessor $previousEnv;
    /** @var array<string, string|false> */
    private array $previousClusterEnv = [];
    private AgentRestartsTestAgents $agents;
    private AgentRestartsTestWorker $worker;
    private AgentRestarts $restarts;
    /** @var list<array{string, PeerAgentRestartDTO}> */
    private array $forwarded = [];

    protected function setUp(): void
    {
        $this->previousAppClass = Hilos::appClass();
        $this->previousRouter = Hilos::$sr;
        $this->previousRt = Hilos::$rt;
        $this->previousCluster = Hilos::$cluster;
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        foreach (['CLUSTER_ENABLED', 'CLUSTER_NODE_ID', 'CLUSTER_NODE_ROLE'] as $key) {
            $this->previousClusterEnv[$key] = getenv($key);
        }
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, AgentRestartsTestHilos::class);
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = null;
        Hilos::$cluster = null;
        $this->agents = new AgentRestartsTestAgents();
        $this->worker = new AgentRestartsTestWorker();
        $this->restarts = new AgentRestarts(
            $this->agents,
            fn (): WorkerServer => $this->worker,
            new CommandReplyOrigins(),
        );
    }

    protected function tearDown(): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->previousAppClass);
        Hilos::$sr = $this->previousRouter;
        Hilos::$rt = $this->previousRt;
        Hilos::$cluster = $this->previousCluster;
        Hilos::$env = $this->previousEnv;
        foreach ($this->previousClusterEnv as $key => $value) {
            putenv($value === false ? $key : "{$key}={$value}");
        }
        parent::tearDown();
    }

    public function testEveryLocalKindStopsAndStartsOnItsFormerWorker(): void
    {
        foreach (['replica', 'singleton', 'policy', 'indexed:7', 'lazy:8', 'monopoly'] as $id) {
            $this->agents->started[$id] = true;
            $this->worker->linked[$id] = true;
            $before = $this->restarts->revision();

            $this->restarts->request($this->request($id), 100.0);

            self::assertSame($before + 1, $this->restarts->revision());
            self::assertSame([$id], array_column($this->restarts->restartingAgents(), 'id'));
            self::assertSame('standalone', $this->restarts->restartingAgents()[0]->nodeId);
            self::assertSame($id, $this->worker->restarted[array_key_last($this->worker->restarted)][0]);
            $this->restarts->onAgentStarted($id);
            $reply = $this->nextReply();
            self::assertSame(CommandConstants::STATUS_OK, $reply->status);
            self::assertSame($id, $reply->payload[CommandConstants::FIELD_AGENT_ID]);
            self::assertSame('standalone', $reply->payload[CommandConstants::FIELD_NODE_ID]);
            self::assertSame($before + 2, $this->restarts->revision());
            self::assertSame([], $this->restarts->restartingAgents());
        }
        self::assertSame(true, $this->worker->restarted[2][1], 'POLICY carries the placement sanction');
        self::assertSame(false, $this->worker->restarted[0][1], 'A node replica needs no sanction');
    }

    public function testASecondCallerJoinsOneOpenRestartAndGetsItsOwnReply(): void
    {
        $this->agents->started['replica'] = true;
        $this->worker->linked['replica'] = true;
        $this->restarts->request($this->request('replica', 'c1'), 100.0);
        $this->restarts->request($this->request('replica', 'c2'), 105.0);

        self::assertCount(1, $this->worker->restarted);
        self::assertSame(1, $this->restarts->revision());
        $this->restarts->onAgentStarted('replica');
        self::assertSame('c1', $this->nextReply()->correlationId);
        self::assertSame('c2', $this->nextReply()->correlationId);
        self::assertSame(2, $this->restarts->revision());
    }

    public function testOpenMarksAreSortedById(): void
    {
        foreach (['singleton', 'replica'] as $id) {
            $this->agents->started[$id] = true;
            $this->worker->linked[$id] = true;
            $this->restarts->request($this->request($id, $id), 100.0);
        }

        self::assertSame(['replica', 'singleton'], array_column($this->restarts->restartingAgents(), 'id'));
        self::assertSame(2, $this->restarts->revision());
    }

    public function testThirteenSecondWindowAnswersButKeepsTheOpenMark(): void
    {
        $this->agents->started['replica'] = true;
        $this->worker->linked['replica'] = true;
        $this->restarts->request($this->request('replica'), 100.0);

        $this->restarts->tick(112.9);
        self::assertNull(Hilos::$sr?->getNextQueuedSignal());
        $this->restarts->tick(113.0);
        self::assertSame('Agent replica is not up yet after 13s; the restart goes on', $this->nextReply()->payload[CommandConstants::FIELD_MESSAGE]);
        self::assertSame(1, $this->restarts->revision());
        self::assertCount(1, $this->restarts->restartingAgents());
        $this->restarts->onAgentStarted('replica');
        self::assertNull(Hilos::$sr?->getNextQueuedSignal());
        self::assertSame(2, $this->restarts->revision());
    }

    public function testAnAbandonedCallerLeavesNoReplyWhileTheRestartContinues(): void
    {
        $this->agents->started['replica'] = true;
        $this->worker->linked['replica'] = true;
        $this->restarts->request($this->request('replica'), 100.0);
        $this->restarts->onCommandAbandoned('c1');
        $this->restarts->onAgentStarted('replica');

        self::assertNull(Hilos::$sr?->getNextQueuedSignal());
        self::assertSame([], $this->restarts->restartingAgents());
    }

    public function testValidationAndLocalRefusalsNeverStopTheAgent(): void
    {
        $cases = [
            [$this->request('absent'), 'No agent type absent in this installation'],
            [$this->request('indexed'), 'Agent type indexed needs an index: indexed:<index>'],
            [$this->request('replica:1'), 'Agent type replica takes no index'],
            [$this->request('replica', nodeId: 'missing'), 'No node missing in this installation'],
            [$this->request('replica'), 'Agent replica is not running on node standalone'],
        ];
        foreach ($cases as [$request, $message]) {
            $this->restarts->request($request);
            self::assertSame($message, $this->nextReply()->payload[CommandConstants::FIELD_MESSAGE]);
        }
        self::assertSame([], $this->worker->restarted);
        self::assertSame(0, $this->restarts->revision());
    }

    public function testFreezePendingStartAndLostWorkerLinkRefuseBeforeStop(): void
    {
        $this->agents->started['replica'] = true;
        $this->worker->linked['replica'] = true;
        $runtime = new AgentRestartsFreezeRt();
        $runtime->hilosProtectedModeRuntime->phase = ProtectedModeRuntime::PHASE_ACTIVE;
        Hilos::$rt = $runtime;
        $this->restarts->request($this->request('replica'));
        self::assertSame(
            'Node standalone is frozen by protected mode; replica was not stopped',
            $this->nextReply()->payload[CommandConstants::FIELD_MESSAGE],
        );

        Hilos::$rt = null;
        $this->agents->started['replica'] = false;
        $this->restarts->request($this->request('replica'));
        self::assertSame('Agent replica is not running on node standalone', $this->nextReply()->payload[CommandConstants::FIELD_MESSAGE]);

        $this->agents->started['replica'] = true;
        unset($this->worker->linked['replica']);
        $this->restarts->request($this->request('replica'));
        self::assertSame(
            'Agent replica could not be stopped: its worker link is gone',
            $this->nextReply()->payload[CommandConstants::FIELD_MESSAGE],
        );
        self::assertSame([], $this->worker->restarted);
    }

    public function testStopAndStartFailuresCloseTheMarkAndAnswer(): void
    {
        $this->agents->started['replica'] = true;
        $this->worker->linked['replica'] = true;
        $this->worker->failure = new LogicException('old link went away');
        $this->restarts->request($this->request('replica'));
        self::assertSame(
            'Agent replica could not be stopped: its worker link is gone',
            $this->nextReply()->payload[CommandConstants::FIELD_MESSAGE],
        );
        self::assertSame([], $this->restarts->restartingAgents());

        $this->worker->failure = new RuntimeException('no worker room');
        $this->worker->afterRestart = function (): void {
            $this->agents->started['replica'] = false;
        };
        $this->restarts->request($this->request('replica'));
        self::assertSame(
            'Agent replica did not start again: no worker room',
            $this->nextReply()->payload[CommandConstants::FIELD_MESSAGE],
        );
        self::assertSame([], $this->restarts->restartingAgents());

        $this->worker->failure = null;
        $this->worker->afterRestart = null;
        $this->agents->started['replica'] = true;
        $this->restarts->request($this->request('replica'));
        $this->restarts->onAgentStartFailed('replica', 'onStart refused');
        self::assertSame(
            'Agent replica did not start again: onStart refused',
            $this->nextReply()->payload[CommandConstants::FIELD_MESSAGE],
        );
    }

    public function testQuietStartGateAndWorkerLossEndTheOpenMark(): void
    {
        $this->agents->started['replica'] = true;
        $this->worker->linked['replica'] = true;
        $this->worker->afterRestart = function (): void {
            unset($this->agents->started['replica']);
        };
        $this->restarts->request($this->request('replica'));
        self::assertSame(
            'Agent replica did not start again: the local start gate refused it',
            $this->nextReply()->payload[CommandConstants::FIELD_MESSAGE],
        );
        self::assertSame([], $this->restarts->restartingAgents());

        $this->worker->afterRestart = null;
        $this->agents->started['replica'] = true;
        $this->restarts->request($this->request('replica'));
        $this->restarts->onAgentsLost(['replica']);
        self::assertSame(
            'Agent replica did not start again: its worker was lost',
            $this->nextReply()->payload[CommandConstants::FIELD_MESSAGE],
        );
        self::assertSame([], $this->restarts->restartingAgents());
    }

    public function testNoWorkerServerRefusesWithoutOpeningAMark(): void
    {
        $restarts = new AgentRestarts($this->agents, static fn (): ?WorkerServer => null, new CommandReplyOrigins());
        $restarts->request($this->request('replica'));

        self::assertSame('This daemon restarts no agents', $this->nextReply()->payload[CommandConstants::FIELD_MESSAGE]);
        self::assertSame(0, $restarts->revision());
    }

    public function testDepartedAskerIsRemovedAndLeadershipLossClosesOnlyLeaderWork(): void
    {
        $this->agents->started['replica'] = true;
        $this->worker->linked['replica'] = true;
        $foreign = new CommandRequestDTO('c1', 'agent:restart', [
            CommandConstants::FIELD_AGENT_TYPE => 'replica',
            CommandConstants::FIELD_AGENT_INDEX => null,
            CommandConstants::FIELD_NODE_ID => null,
        ], 'n2');
        $this->restarts->request($foreign, 100.0);
        $this->restarts->onNodeLeft('n2');
        $this->restarts->onAgentStarted('replica');
        self::assertNull(Hilos::$sr?->getNextQueuedSignal());

        $this->agents->started['singleton'] = true;
        $this->worker->linked['singleton'] = true;
        $this->restarts->executeHere($this->request('singleton'), 'singleton', null, 'singleton', 'standalone', true, 200.0);
        $this->restarts->onLostLeadership();
        self::assertSame(
            'Leadership changed during the restart of singleton',
            $this->nextReply()->payload[CommandConstants::FIELD_MESSAGE],
        );
        self::assertSame([], $this->restarts->restartingAgents());
    }

    public function testForeignCallerIsRememberedOnlyWhenItsReplyIsQueued(): void
    {
        $origins = new CommandReplyOrigins();
        $restarts = new AgentRestarts($this->agents, fn (): WorkerServer => $this->worker, $origins);
        $this->agents->started['replica'] = true;
        $this->worker->linked['replica'] = true;
        $foreign = new CommandRequestDTO('c-foreign', 'agent:restart', [
            CommandConstants::FIELD_AGENT_TYPE => 'replica',
            CommandConstants::FIELD_AGENT_INDEX => null,
            CommandConstants::FIELD_NODE_ID => null,
        ], 'n2');

        $restarts->request($foreign, 100.0);
        self::assertNull($origins->take('c-foreign', microtime(true)));
        $restarts->onAgentStarted('replica');

        self::assertSame('n2', $origins->take('c-foreign', microtime(true)));
        self::assertSame('c-foreign', $this->nextReply()->correlationId);
    }

    public function testPeerReplicaRequestStopsAtItsReceivingNodeAndKeepsTheOrigin(): void
    {
        $origins = new CommandReplyOrigins();
        $restarts = new AgentRestarts($this->agents, fn (): WorkerServer => $this->worker, $origins);
        $this->agents->started['replica'] = true;
        $this->worker->linked['replica'] = true;

        $restarts->fromPeer(new PeerAgentRestartDTO('c-peer', 'asking-node', 'replica', null, 'standalone', false));
        self::assertSame('replica', $restarts->restartingAgents()[0]->id);
        $restarts->onAgentStarted('replica');

        self::assertSame('asking-node', $origins->take('c-peer', microtime(true)));
        self::assertSame('standalone', $this->nextReply()->payload[CommandConstants::FIELD_NODE_ID]);
    }

    public function testRemoteReplicaAndLeaderRequestsCarryOneFinalPeerAddress(): void
    {
        $this->installCluster(false, ['self' => ['slots=10'], 'remote' => ['slots=10'], 'leader' => ['slots=10']]);

        $this->restarts->request($this->request('replica', 'replica-request', 'remote'));
        $this->restarts->request($this->request('singleton', 'leader-request'));

        self::assertCount(2, $this->forwarded);
        self::assertSame('remote', $this->forwarded[0][0]);
        self::assertSame('replica-request', $this->forwarded[0][1]->correlationId);
        self::assertSame('self', $this->forwarded[0][1]->originNodeId);
        self::assertSame('remote', $this->forwarded[0][1]->nodeId);
        self::assertFalse($this->forwarded[0][1]->inPlace);
        self::assertSame('leader', $this->forwarded[1][0]);
        self::assertSame('leader-request', $this->forwarded[1][1]->correlationId);
        self::assertNull($this->forwarded[1][1]->nodeId);
        self::assertFalse($this->forwarded[1][1]->inPlace);
        self::assertNull(Hilos::$sr?->getNextQueuedSignal());
        self::assertSame([], $this->worker->restarted);
    }

    public function testKnownRemoteNodesWithRefusingLinksGiveDestinationSpecificErrors(): void
    {
        $this->installCluster(false, ['self' => ['slots=10'], 'remote' => ['slots=10'], 'leader' => ['slots=10']]);
        $peer = new ReflectionClass(PeerServer::class)->newInstanceWithoutConstructor();
        $this->restarts = new AgentRestarts(
            $this->agents,
            fn (): WorkerServer => $this->worker,
            new CommandReplyOrigins(),
            static fn (): PeerServer => $peer,
        );

        $this->restarts->request($this->request('replica', 'replica-request', 'remote'));
        self::assertSame('Node remote is silent', $this->nextReply()->payload[CommandConstants::FIELD_MESSAGE]);
        $this->restarts->request($this->request('singleton', 'leader-request'));
        self::assertSame('The leader leader is unreachable', $this->nextReply()->payload[CommandConstants::FIELD_MESSAGE]);
        self::assertFalse($peer->sendToNode('leader', new PeerAgentRestartDTO('probe', 'self', 'singleton', null, null, false)));
        self::assertSame([], $this->worker->restarted);
    }

    public function testLeaderRefusesPlacingAbsentAndWrongNodePolicyRecordsPrecisely(): void
    {
        [$placement] = $this->installCluster(true, ['self' => ['slots=10'], 'remote' => ['slots=10']]);

        $placement->registry()->put(new PlacementRecord('policy', null, 'remote', PlacementState::Placing));
        $this->restarts->request($this->request('policy', nodeId: 'self'));
        self::assertSame('Agent policy is still being placed', $this->nextReply()->payload[CommandConstants::FIELD_MESSAGE]);

        $placement->registry()->forget('policy');
        $this->restarts->request($this->request('policy'));
        self::assertSame('Agent policy is not running anywhere', $this->nextReply()->payload[CommandConstants::FIELD_MESSAGE]);

        $placement->registry()->put(new PlacementRecord('policy', null, 'remote', PlacementState::Started));
        $this->restarts->request($this->request('policy', nodeId: 'self'));
        self::assertSame('Agent policy runs on node remote, not on self', $this->nextReply()->payload[CommandConstants::FIELD_MESSAGE]);
        self::assertSame([], $this->worker->restarted);
        self::assertSame([], $this->forwarded);
    }

    public function testLeaderKeepsPolicyOnRemoteHostWhenItIsTheBestNode(): void
    {
        [$placement] = $this->installCluster(true, ['self' => [], 'remote' => ['slots=10']]);
        $placement->registry()->put(new PlacementRecord('policy', null, 'remote', PlacementState::Started));

        $this->restarts->request($this->request('policy'));

        self::assertSame('remote', $this->forwarded[0][0]);
        self::assertSame('remote', $this->forwarded[0][1]->nodeId);
        self::assertTrue($this->forwarded[0][1]->inPlace);
        self::assertNull(Hilos::$sr?->getNextQueuedSignal());
        self::assertSame([], $this->worker->restarted);
    }

    public function testLeaderMovesPolicyOnlyAfterOldHostReportsStopped(): void
    {
        [$placement, $mesh] = $this->installCluster(true, ['self' => [], 'remote' => [], 'target' => ['slots=10']]);
        $placement->registry()->put(new PlacementRecord('policy', null, 'remote', PlacementState::Started));

        $this->restarts->request($this->request('policy'));
        self::assertSame('remote', $mesh->sent[0][0]);
        self::assertInstanceOf(PeerStopAgentDTO::class, $mesh->sent[0][1]);
        self::assertSame('remote', $placement->registry()->get('policy')?->nodeId);
        self::assertSame(['policy'], array_column($this->restarts->restartingAgents(), 'id'));
        self::assertNull(Hilos::$sr?->getNextQueuedSignal());

        $placement->onAgentStatus('remote', PeerAgentStatusDTO::stopped('policy', null));
        self::assertSame('target', $placement->registry()->get('policy')?->nodeId);
        $placement->onAgentStatus('target', PeerAgentStatusDTO::started('policy', null, 1));
        $reply = $this->nextReply();
        self::assertSame(CommandConstants::STATUS_OK, $reply->status);
        self::assertSame('target', $reply->payload[CommandConstants::FIELD_NODE_ID]);
        self::assertSame([], $this->restarts->restartingAgents());
    }

    /**
     * @param bool $leader Whether this node holds leadership
     * @param array<string, list<string>> $capabilities Capabilities of online nodes
     * @return array{ClusterPlacement, AgentRestartsTestMesh} Cluster placement and its peer port
     */
    private function installCluster(bool $leader, array $capabilities): array
    {
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=self');
        putenv('CLUSTER_NODE_ROLE=master');
        Hilos::$env = new EnvAccessor();
        $context = new ClusterContext();
        $context->registerLeadership(new AgentRestartsTestLeadership($leader, $leader ? 'self' : 'leader'));
        Hilos::$cluster = $context;
        foreach ($capabilities as $nodeId => $tags) {
            if ($nodeId !== 'self') {
                $context->registry()->recordPeer(NodeIdentity::of($nodeId, NodeRole::Master, $tags), 100.0);
            }
        }
        $mesh = new AgentRestartsTestMesh($capabilities);
        $sink = new AgentRestartsTestRestartSink();
        $placement = new ClusterPlacement('self', $mesh, new AgentRestartsTestExecutor(), restartSink: $sink);
        $context->registerPlacement($placement);
        $this->restarts = new AgentRestarts(
            $this->agents,
            fn (): WorkerServer => $this->worker,
            new CommandReplyOrigins(),
            sendRestartFrame: function (string $nodeId, PeerAgentRestartDTO $frame): bool {
                $this->forwarded[] = [$nodeId, $frame];
                return true;
            },
        );
        $sink->restarts = $this->restarts;

        return [$placement, $mesh];
    }

    /**
     * @param string $id Agent id
     * @param string $correlationId Command id
     * @param ?string $nodeId Requested node
     * @return CommandRequestDTO Parsed request
     */
    private function request(string $id, string $correlationId = 'c1', ?string $nodeId = null): CommandRequestDTO
    {
        [$type, $index] = array_pad(explode(':', $id, 2), 2, null);
        return new CommandRequestDTO($correlationId, 'agent:restart', [
            CommandConstants::FIELD_AGENT_TYPE => $type,
            CommandConstants::FIELD_AGENT_INDEX => $index,
            CommandConstants::FIELD_NODE_ID => $nodeId,
        ]);
    }

    /** @return CommandReplyDTO Next queued command outcome */
    private function nextReply(): CommandReplyDTO
    {
        $reply = Hilos::$sr?->getNextQueuedSignal()?->data;
        self::assertInstanceOf(CommandReplyDTO::class, $reply);
        return $reply;
    }
}

/** Synthetic roster, enough to distinguish running and pending agents. */
final class AgentRestartsTestAgents extends AgentManagerDaemon
{
    /** @var array<string, bool> */
    public array $started = [];

    /** @param string $agentId Agent id */
    public function hasAgent(string $agentId): bool
    {
        return array_key_exists($agentId, $this->started);
    }

    /** @param string $agentId Agent id */
    public function isAgentStarted(string $agentId): bool
    {
        return $this->started[$agentId] ?? false;
    }

    /**
     * @param string $agentType Unused agent type
     * @param ?string $agentIndex Unused index
     * @return AgentDaemonInterface Never returned
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new LogicException('No agent is created by this test');
    }
}

/** Captures the worker request without running a worker process. */
final class AgentRestartsTestWorker extends WorkerServer
{
    /** @var array<string, true> */
    public array $linked = [];

    /** @var list<array{0: string, 1: bool}> */
    public array $restarted = [];

    public ?Throwable $failure = null;

    public ?Closure $afterRestart = null;

    public function __construct()
    {
    }

    /**
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index
     * @return ?int One linked worker, or null
     */
    public function placedWorkerId(string $agentType, ?string $agentIndex): ?int
    {
        $id = $agentIndex === null ? $agentType : $agentType . ':' . $agentIndex;
        return isset($this->linked[$id]) ? 1 : null;
    }

    /**
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index
     * @param bool $placedByLeader Placement sanction
     */
    public function restartAgentHere(string $agentType, ?string $agentIndex, bool $placedByLeader): void
    {
        ($this->afterRestart)?->__invoke();
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $id = $agentIndex === null ? $agentType : $agentType . ':' . $agentIndex;
        $this->restarted[] = [$id, $placedByLeader];
    }

    protected function onStart(): void
    {
    }
}

/** Mounted freeze row for the pre-stop guard. */
final class AgentRestartsFreezeRt extends RtContext
{
    public object $hilosProtectedModeRuntime;

    public function __construct()
    {
        $this->hilosProtectedModeRuntime = (object)['phase' => ProtectedModeRuntime::PHASE_INACTIVE];
    }

    public function configure(): void
    {
    }
}

/** Leadership verdict for a master receiving a restart request. */
final class AgentRestartsTestLeadership implements Leadership
{
    public function __construct(private readonly bool $leader, private readonly string $leaderId)
    {
    }

    public function amLeader(): bool
    {
        return $this->leader;
    }

    public function leaderId(): ?string
    {
        return $this->leaderId;
    }

    public function leaderWebSocketOpen(): bool
    {
        return true;
    }

    public function hasQuorum(): bool
    {
        return true;
    }
}

/** Placement peer facts kept separate from restart forwarding. */
final class AgentRestartsTestMesh implements PlacementMesh
{
    /** @var list<array{string, PeerDTO}> */
    public array $sent = [];

    /** @param array<string, list<string>> $capabilities Capabilities by online node */
    public function __construct(private readonly array $capabilities)
    {
    }

    public function sendToNode(string $nodeId, PeerDTO $frame): bool
    {
        $this->sent[] = [$nodeId, $frame];
        return true;
    }

    public function broadcastToNodes(PeerDTO $frame): void
    {
    }

    public function nodeCapabilities(string $nodeId): ?array
    {
        return $this->capabilities[$nodeId] ?? null;
    }

    public function onlineNodeIds(): array
    {
        return array_keys($this->capabilities);
    }
}

/** Policy cost and worker lookup for restart placement. */
final class AgentRestartsTestExecutor implements PlacementExecutor
{
    public function requiredCapabilities(string $agentType, ?string $agentIndex): array
    {
        return [];
    }

    public function placementProfile(string $agentType, ?string $agentIndex): ResourceProfile
    {
        return ResourceProfile::none();
    }

    public function executePlacement(string $agentType, ?string $agentIndex): ?int
    {
        return 1;
    }

    public function placedWorkerId(string $agentType, ?string $agentIndex): ?int
    {
        return 1;
    }

    public function revokePlacement(string $agentType, ?string $agentIndex): void
    {
    }
}

/** Returns the placement completion to the master waiting for the command. */
final class AgentRestartsTestRestartSink implements PlacementRestartSink
{
    public ?AgentRestarts $restarts = null;

    public function onRestartPlaced(string $agentType, ?string $agentIndex, string $nodeId): void
    {
        $this->restarts?->onRestartPlaced($agentType, $agentIndex, $nodeId);
    }

    public function onRestartNotPlaced(string $agentType, ?string $agentIndex, string $reason): void
    {
        $this->restarts?->onRestartNotPlaced($agentType, $agentIndex, $reason);
    }
}

/** Registry with each local lifecycle shape the restart command accepts. */
abstract class AgentRestartsTestHilos extends Hilos
{
    public const array AGENTS = [
        'replica' => [AgentRegistryKey::SCOPE => AgentScope::NODE],
        'singleton' => [],
        'policy' => [AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY],
        'indexed' => [AgentRegistryKey::INDEXED => true],
        'lazy' => [AgentRegistryKey::INDEXED => true, AgentRegistryKey::IDLE_TIMEOUT => 240],
        'monopoly' => [],
    ];
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\ClusterNode;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\ConnectionPolicy;
use Hilos\Cluster\Peer\FullMeshConnectionPolicy;
use Hilos\Cluster\Peer\PeerAddress;
use Hilos\Cluster\Peer\PeerDial;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Cluster\Placement\BestFitPlacementPolicy;
use Hilos\Cluster\Placement\PlacementExecutor;
use Hilos\Cluster\Placement\PlacementPolicy;
use Hilos\Cluster\Placement\ResourceProfile;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\DaemonContext;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Daemon\Module\PeerModule;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Server\ServerInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Unit tests that a project's cluster policies reach the places that decide (HIL-724).
 *
 * The two policy seams were reachable only from a constructor argument no production caller
 * filled, so a project could not swap either one. These cases follow both the whole way: the
 * daemon asks the project at boot and hands the answer to the facade, the peer transport takes
 * it from there, and the policy is the one actually consulted where a dial target and a hosting
 * node are chosen.
 */
final class ClusterPolicySeamTest extends TestCase
{
    /** @var ?EnvAccessor Previous env accessor to restore after the test */
    private ?EnvAccessor $previousEnv = null;

    /** @var ?ClusterContext Previous cluster context to restore after the test */
    private ?ClusterContext $previousCluster = null;

    /** @var ?RtContext Previous runtime overlay to restore after the test */
    private ?RtContext $previousRt = null;

    protected function setUp(): void
    {
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        $this->previousCluster = Hilos::$cluster;
        $this->previousRt = Hilos::$rt;

        // boot() carries a tail this seam has no part in: with a runtime overlay present it
        // registers daemon truth sources and reads the protected-mode freeze off disk. Pinning
        // both the overlay and the backup flag keeps these cases about the policies alone,
        // whatever a neighbouring test left behind.
        Hilos::$rt = null;

        Hilos::$env = new EnvAccessor();
        putenv('BACKUP_ENABLED=false');
        putenv('SOCKET_READ_BUFFER_SIZE=65536');
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=node-a');
        putenv('CLUSTER_NODE_ROLE=slave');
        putenv('CLUSTER_PEER_HOST=127.0.0.1');
        putenv('CLUSTER_PEER_PORT=0');
        putenv('CLUSTER_SEEDS=');
        putenv('CLUSTER_FAILOVER_GRACE_MS=8000');
        putenv('CLUSTER_SLAVE_WORK_GRACE_MS=4000');

        Hilos::$cluster = new ClusterContext();
    }

    protected function tearDown(): void
    {
        Hilos::$env = $this->previousEnv;
        Hilos::$cluster = $this->previousCluster;
        Hilos::$rt = $this->previousRt;
        // Every manager built here installed itself as the global signal router.
        Hilos::$sr = null;
        foreach ([
            'BACKUP_ENABLED',
            'SOCKET_READ_BUFFER_SIZE',
            'CLUSTER_ENABLED',
            'CLUSTER_NODE_ID',
            'CLUSTER_NODE_ROLE',
            'CLUSTER_PEER_HOST',
            'CLUSTER_PEER_PORT',
            'CLUSTER_SEEDS',
            'CLUSTER_FAILOVER_GRACE_MS',
            'CLUSTER_SLAVE_WORK_GRACE_MS',
        ] as $key) {
            putenv($key);
        }
    }

    public function testBootCarriesThePoliciesTheProjectDeclaredToTheFacade(): void
    {
        $placementPolicy = new SpyPlacementPolicy(null);
        $connectionPolicy = new SpyConnectionPolicy(false);

        new PolicySeamTestManager($placementPolicy, $connectionPolicy)->boot($this->context());

        $this->assertSame($placementPolicy, Hilos::$cluster->placementPolicy());
        $this->assertSame($connectionPolicy, Hilos::$cluster->connectionPolicy());
    }

    public function testBootLeavesTheFrameworkDefaultsWhenTheProjectDeclaresNothing(): void
    {
        new PolicySeamTestManager()->boot($this->context());

        $this->assertInstanceOf(BestFitPlacementPolicy::class, Hilos::$cluster->placementPolicy());
        $this->assertInstanceOf(FullMeshConnectionPolicy::class, Hilos::$cluster->connectionPolicy());
    }

    public function testAConnectionPolicyRefusingAPeerLeavesItUndialed(): void
    {
        $policy = new SpyConnectionPolicy(false);
        $server = $this->peerServerBuiltWith(connectionPolicy: $policy);
        $this->learnPeer('node-b');

        new ReflectionMethod($server, 'reconcilePeerDials')->invoke($server);

        $this->assertSame(['node-b'], $policy->weighed, 'the policy is the one asked about the peer');
        $this->assertSame([], $this->peerDials($server), 'a peer the policy refused gets no dial');
    }

    public function testAConnectionPolicyAcceptingAPeerOpensTheDial(): void
    {
        $policy = new SpyConnectionPolicy(true);
        $server = $this->peerServerBuiltWith(connectionPolicy: $policy);
        $this->learnPeer('node-b');

        new ReflectionMethod($server, 'reconcilePeerDials')->invoke($server);

        $this->assertSame(['node-b'], $policy->weighed);
        $this->assertArrayHasKey('node-b', $this->peerDials($server), 'an accepted peer gets its dial');
    }

    public function testThePlacementPolicyIsTheOneAskedWhichNodeHostsAnAgent(): void
    {
        $policy = new SpyPlacementPolicy(null);
        $server = $this->peerServerBuiltWith(placementPolicy: $policy);
        Hilos::$cluster->registerPlacementExecutor(new FakeSeamPlacementExecutor());

        new ReflectionMethod($server, 'onStart')->invoke($server);
        $placement = Hilos::$cluster->placement();

        $this->assertNotNull($placement, 'the transport builds the coordinator once an executor is registered');
        $this->assertNull($placement->placeAgentOnBestNode('render', null));
        $this->assertSame(1, $policy->asked, 'the project policy is what ranked the candidate nodes');
    }

    /**
     * Builds the peer server the way the daemon does: policies onto the facade first, exactly as
     * boot() puts them there, then the module that constructs the server against them.
     *
     * @param ?PlacementPolicy $placementPolicy Policy the project declares for placement, or null for the default
     * @param ?ConnectionPolicy $connectionPolicy Policy the project declares for dialing, or null for the default
     * @return PeerServer Server the module built, captured instead of registered
     */
    private function peerServerBuiltWith(
        ?PlacementPolicy $placementPolicy = null,
        ?ConnectionPolicy $connectionPolicy = null,
    ): PeerServer {
        if ($placementPolicy !== null) {
            Hilos::$cluster->registerPlacementPolicy($placementPolicy);
        }

        if ($connectionPolicy !== null) {
            Hilos::$cluster->registerConnectionPolicy($connectionPolicy);
        }

        $daemon = new PolicySeamTestManager();
        new PeerModule()->register($daemon, $this->context());

        $server = $daemon->captured;
        $this->assertInstanceOf(PeerServer::class, $server);

        return $server;
    }

    /**
     * Lets the local node learn a reachable peer, as a handshake would.
     *
     * @param string $nodeId Node id the peer announces itself under
     */
    private function learnPeer(string $nodeId): void
    {
        Hilos::$cluster->registry()->merge(
            NodeIdentity::of($nodeId, NodeRole::Master, [], new PeerAddress('10.0.0.1', 1111)),
            true,
            microtime(true),
        );
    }

    /**
     * Reads the server's private dial-on-learn map.
     *
     * @param PeerServer $server Server under test
     * @return array<string, PeerDial> Dial-on-learn state keyed by node id
     */
    private function peerDials(PeerServer $server): array
    {
        return new ReflectionProperty($server, 'peerDials')->getValue($server);
    }

    /**
     * @return DaemonContext Path context for a daemon that binds nothing
     */
    private function context(): DaemonContext
    {
        return new DaemonContext(__DIR__, __DIR__);
    }
}

/**
 * Project daemon that declares the policies under test and keeps the servers it is handed.
 *
 * Overriding {@see DaemonManager::registerServer()} is what keeps this a unit test: the base
 * method wires the server into failure reporting and the command channel, and the point here is
 * only what the module constructed.
 */
final class PolicySeamTestManager extends DaemonManager
{
    /** @var ?ServerInterface Last server the daemon was handed, kept instead of registered */
    public ?ServerInterface $captured = null;

    /** @var ?PlacementPolicy Policy this project declares for placement, or null to keep the default */
    private ?PlacementPolicy $placementPolicy;

    /** @var ?ConnectionPolicy Policy this project declares for dialing, or null to keep the default */
    private ?ConnectionPolicy $connectionPolicy;

    /**
     * @param ?PlacementPolicy $placementPolicy Policy this project declares for placement, or null for none
     * @param ?ConnectionPolicy $connectionPolicy Policy this project declares for dialing, or null for none
     */
    public function __construct(?PlacementPolicy $placementPolicy = null, ?ConnectionPolicy $connectionPolicy = null)
    {
        parent::__construct();

        $this->placementPolicy = $placementPolicy;
        $this->connectionPolicy = $connectionPolicy;
    }

    public function registerServer(ServerInterface $server): void
    {
        $this->captured = $server;
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new PolicySeamTestAgentManagerDaemon();
    }

    protected function createPlacementPolicy(): ?PlacementPolicy
    {
        return $this->placementPolicy;
    }

    protected function createConnectionPolicy(): ?ConnectionPolicy
    {
        return $this->connectionPolicy;
    }
}

/**
 * Agent manager that builds nothing: these cases never start an agent.
 */
final class PolicySeamTestAgentManagerDaemon extends AgentManagerDaemon
{
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('not used in test');
    }
}

/**
 * Connection policy that answers the same way about every peer and records who it was asked about.
 */
final class SpyConnectionPolicy implements ConnectionPolicy
{
    /** @var list<string> Node ids this policy was asked to weigh */
    public array $weighed = [];

    /** @var bool The answer this policy gives about every candidate */
    private bool $answer;

    /**
     * @param bool $answer The answer this policy gives about every candidate
     */
    public function __construct(bool $answer)
    {
        $this->answer = $answer;
    }

    public function shouldDial(NodeIdentity $local, ClusterNode $candidate): bool
    {
        $this->weighed[] = $candidate->nodeId;

        return $this->answer;
    }
}

/**
 * Placement policy that names a fixed node and counts how often it was consulted.
 */
final class SpyPlacementPolicy implements PlacementPolicy
{
    /** @var int How many times this policy was asked to rank candidates */
    public int $asked = 0;

    /** @var ?string Node id this policy names, or null when it fits nowhere */
    private ?string $nodeId;

    /**
     * @param ?string $nodeId Node id this policy names, or null when it fits nowhere
     */
    public function __construct(?string $nodeId)
    {
        $this->nodeId = $nodeId;
    }

    public function selectNode(
        array $requiredTags,
        ResourceProfile $profile,
        array $candidates,
        array $hosted = [],
    ): ?string {
        $this->asked++;

        return $this->nodeId;
    }
}

/**
 * Executor that reports an agent nothing constrains, so the choice is the policy's alone.
 */
final class FakeSeamPlacementExecutor implements PlacementExecutor
{
    public function requiredCapabilities(string $agentType, ?string $agentIndex): array
    {
        return [];
    }

    public function placementProfile(string $agentType, ?string $agentIndex): ResourceProfile
    {
        return ResourceProfile::none();
    }

    public function executePlacement(string $agentType, ?string $agentIndex): int
    {
        return 1;
    }

    public function revokePlacement(string $agentType, ?string $agentIndex): void
    {
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\BaseDTO;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\NodeRole;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\Destination\AgentDestination;
use Hilos\Core\Router\Destination\RemoteAgentDestination;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Database\Context\DbContext;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos as HilosFacade;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Tests node-addressed agent signal routing through AGENT_SIGNALS NODE_FIELD config (HIL-757).
 *
 * A node-scoped agent runs a replica on every node, and the placement lookup answers "here"
 * for all of them - so a sender that knows WHICH node it wants had no way to say so. The
 * declaration lets the signal carry a node id, and this is where the router honours it: a
 * foreign id becomes a {@see RemoteAgentDestination} the daemon forwards over the peer
 * channel, and everything else stays the {@see AgentDestination} it is today.
 *
 * The silence on an empty id is a case of its own, because it is where the key parts ways with
 * {@see AgentSignalConfigKey::INDEX_FIELD} ({@see SignalRouterIndexedAgentSignalTest}): an
 * absent index is a sender that forgot, an absent node id is a sender that means "here", and
 * off a cluster that is the only id there is.
 */
final class SignalRouterNodeFieldRoutingTest extends TestCase
{
    /** @var string Id this node answers to while a test runs it as part of a cluster */
    private const string SELF = 'node-A';

    /** @var string Id of the node on the other end of the cluster */
    private const string PEER = 'node-B';

    /** @var ?EnvAccessor Environment accessor in place before the test replaced it */
    private ?EnvAccessor $previousEnv = null;

    /** @var ?ClusterContext Cluster context in place before the test replaced it */
    private ?ClusterContext $previousCluster = null;

    /** @var string Temporary main log file the silence assertion reads back */
    private string $logFile = '';

    protected function setUp(): void
    {
        $this->previousEnv = isset(HilosFacade::$env) ? HilosFacade::$env : null;
        $this->previousCluster = HilosFacade::$cluster;
        HilosFacade::$env = new EnvAccessor();

        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-node-field-routing-log');
        Logger::setLogFile($this->logFile);
    }

    protected function tearDown(): void
    {
        Logger::resetLogFile();
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }

        HilosFacade::$env = $this->previousEnv;
        HilosFacade::$cluster = $this->previousCluster;
        putenv('CLUSTER_ENABLED');
        putenv('CLUSTER_NODE_ID');
        putenv('CLUSTER_NODE_ROLE');
        putenv('CLUSTER_PEER_ADVERTISE');

        parent::tearDown();
    }

    public function testSignalWithoutTheDeclarationRoutesExactlyAsBefore(): void
    {
        $this->enableCluster();

        $destinations = new NodeFieldTestRouter()->getDestinations(
            $this->agentSignal(NodeFieldTestAgent::PLAIN_SIGNAL, ['nodeId' => self::PEER]),
        );

        $this->assertEquals(
            [new AgentDestination(NodeFieldTestAgent::AGENT_TYPE)],
            $destinations,
            'An id in the payload addresses nothing until the receiving agent declares the field',
        );
    }

    public function testEmptyNodeIdAddressesThisNodeAndSaysNothingAboutIt(): void
    {
        $this->enableCluster();

        $destinations = new NodeFieldTestRouter()->getDestinations(
            $this->agentSignal(NodeFieldTestAgent::NODE_SIGNAL, ['nodeId' => '']),
        );

        $this->assertEquals([new AgentDestination(NodeFieldTestAgent::AGENT_TYPE)], $destinations);
        $this->assertSame(
            '',
            $this->logged(),
            'An empty node id is the local node, not a sender that forgot - nothing is written about it',
        );
    }

    public function testAbsentNodeIdAddressesThisNodeAndSaysNothingAboutIt(): void
    {
        $this->enableCluster();

        $destinations = new NodeFieldTestRouter()->getDestinations(
            $this->agentSignal(NodeFieldTestAgent::NODE_SIGNAL, []),
        );

        $this->assertEquals([new AgentDestination(NodeFieldTestAgent::AGENT_TYPE)], $destinations);
        $this->assertSame('', $this->logged());
    }

    public function testOwnNodeIdAddressesTheLocalReplica(): void
    {
        $this->enableCluster();

        $destinations = new NodeFieldTestRouter()->getDestinations(
            $this->agentSignal(NodeFieldTestAgent::NODE_SIGNAL, ['nodeId' => self::SELF]),
        );

        $this->assertEquals([new AgentDestination(NodeFieldTestAgent::AGENT_TYPE)], $destinations);
    }

    public function testForeignNodeIdBecomesARemoteDestinationNamingThatNode(): void
    {
        $this->enableCluster();

        $destinations = new NodeFieldTestRouter()->getDestinations(
            $this->agentSignal(NodeFieldTestAgent::NODE_SIGNAL, ['nodeId' => self::PEER]),
        );

        $this->assertEquals(
            [new RemoteAgentDestination(self::PEER, NodeFieldTestAgent::AGENT_TYPE)],
            $destinations,
        );
    }

    /**
     * The two declarations compose: naming a node does not cost the sender the ability to name
     * an instance, and the index rides along to the node that hosts it.
     */
    public function testForeignNodeIdCarriesTheAgentIndexAlong(): void
    {
        $this->enableCluster();

        $destinations = new NodeFieldTestRouter()->getDestinations(
            $this->agentSignal(NodeFieldTestAgent::INDEXED_NODE_SIGNAL, ['nodeId' => self::PEER, 'entityId' => 7]),
        );

        $this->assertEquals(
            [new RemoteAgentDestination(self::PEER, NodeFieldTestAgent::AGENT_TYPE, '7')],
            $destinations,
        );
    }

    /**
     * Off a cluster the local node has no id to compare against, and asking for one throws
     * ({@see ClusterContext::identity()}). Getting a destination back rather than an exception is
     * the proof the gate short-circuits: a named node is simply not this one, and the daemon is
     * left to report the unreachable peer it already knows how to report.
     */
    public function testForeignNodeIdOffClusterIsStillRemoteAndNeverAsksForALocalIdentity(): void
    {
        HilosFacade::$cluster = new ClusterContext();

        $destinations = new NodeFieldTestRouter()->getDestinations(
            $this->agentSignal(NodeFieldTestAgent::NODE_SIGNAL, ['nodeId' => self::PEER]),
        );

        $this->assertEquals(
            [new RemoteAgentDestination(self::PEER, NodeFieldTestAgent::AGENT_TYPE)],
            $destinations,
        );
    }

    public function testEmptyNodeIdOffClusterStaysLocal(): void
    {
        HilosFacade::$cluster = new ClusterContext();

        $destinations = new NodeFieldTestRouter()->getDestinations(
            $this->agentSignal(NodeFieldTestAgent::NODE_SIGNAL, ['nodeId' => '']),
        );

        $this->assertEquals(
            [new AgentDestination(NodeFieldTestAgent::AGENT_TYPE)],
            $destinations,
            'The single node publishes itself under an empty id, so a reader of it must stay home',
        );
    }

    public function testNodeIdIsIgnoredWithNoClusterContextAtAll(): void
    {
        HilosFacade::$cluster = null;

        $destinations = new NodeFieldTestRouter()->getDestinations(
            $this->agentSignal(NodeFieldTestAgent::NODE_SIGNAL, ['nodeId' => self::PEER]),
        );

        $this->assertEquals(
            [new RemoteAgentDestination(self::PEER, NodeFieldTestAgent::AGENT_TYPE)],
            $destinations,
        );
    }

    /**
     * Puts the environment of a node started as part of a cluster in place.
     */
    private function enableCluster(): void
    {
        putenv('CLUSTER_ENABLED=true');
        putenv('CLUSTER_NODE_ID=' . self::SELF);
        putenv('CLUSTER_NODE_ROLE=' . NodeRole::Master->value);
        putenv('CLUSTER_PEER_ADVERTISE=10.0.0.1:7000');
        HilosFacade::$cluster = new ClusterContext();
    }

    /**
     * Builds an AGENT_SIGNAL DTO with an in-memory inner payload.
     *
     * @param string $signalName Signal name
     * @param array<string, mixed> $payloadData Inner payload data array
     * @return SignalDTO Signal the router is asked to resolve
     */
    private function agentSignal(string $signalName, array $payloadData): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType(SignalTypeConstants::AGENT_SIGNAL),
            new SignalName($signalName),
            new AgentSignalData(new NodeFieldTestPayload($payloadData)),
        );
    }

    /**
     * @return string Everything written to the main log while the test ran
     */
    private function logged(): string
    {
        return (string)file_get_contents($this->logFile);
    }
}

final class NodeFieldTestRouter extends SignalRouter
{
    /**
     * Returns fixture facade for topology reads.
     *
     * @return class-string<HilosFacade> Fixture facade class
     */
    protected function hilosClass(): string
    {
        return NodeFieldTestHilos::class;
    }
}

final class NodeFieldTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'node_field_test_agent';

    public const string PLAIN_SIGNAL = 'node_field_plain_test_signal';

    public const string NODE_SIGNAL = 'node_field_addressed_test_signal';

    public const string INDEXED_NODE_SIGNAL = 'node_field_indexed_test_signal';

    public const array AGENT_SIGNALS = [
        self::PLAIN_SIGNAL,
        self::NODE_SIGNAL => [
            AgentSignalConfigKey::NODE_FIELD => 'nodeId',
        ],
        self::INDEXED_NODE_SIGNAL => [
            AgentSignalConfigKey::INDEX_FIELD => 'entityId',
            AgentSignalConfigKey::NODE_FIELD => 'nodeId',
        ],
    ];

    /**
     * No-op stop hook for node-addressed agent signal tests.
     */
    public function onStop(): void
    {
    }
}

final class NodeFieldTestAgentDaemon extends TopologyTestAgentDaemon
{
    public const string AGENT_TYPE = 'node_field_test_agent';
}

final class NodeFieldTestDbContext extends DbContext
{
    /**
     * No-op DB configuration for node-addressed agent signal tests.
     */
    public function configure(): void
    {
    }
}

final class NodeFieldTestHilos extends HilosFacade
{
    public const array AGENTS = [
        NodeFieldTestAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => NodeFieldTestAgent::class,
            AgentRegistryKey::DAEMON => NodeFieldTestAgentDaemon::class,
        ],
    ];

    /**
     * Creates a no-op DB context for tests.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new NodeFieldTestDbContext();
    }
}

/**
 * Minimal inner payload DTO whose toArray() returns the given data array.
 */
final class NodeFieldTestPayload extends BaseDTO implements SignalDataInterface
{
    /**
     * @param array<string, mixed> $data Payload data
     */
    public function __construct(private readonly array $data)
    {
    }

    /**
     * @return array<string, mixed> Wire payload
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * @param array<string, mixed> $data Wire payload
     * @return static Restored payload
     */
    public static function fromArray(array $data): static
    {
        return new static($data);
    }
}

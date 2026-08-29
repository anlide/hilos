<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Log;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\NodeRole;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Router\AgentSignalData;
use Hilos\Core\Router\SignalRouter;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Log\ClusterLogNodeView;
use Hilos\Log\DTO\NodeLogIndexSignalData;
use Hilos\Log\LogAggregatorAgent;
use Hilos\Log\LogKeySummary;
use Hilos\Log\LogStoreAgent;
use Hilos\Runtime\State\Item\HilosClusterNode as StateHilosClusterNode;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Two nodes reporting their log stores to one aggregator, over the wire they really use (HIL-755).
 *
 * The frames are taken off the signal router's queue and put through
 * {@see NodeLogIndexSignalData::toArray()} and back before the aggregator sees them, rather than
 * being handed to {@see LogAggregatorAgent::applyNodeIndex()} directly. Calling the receiver
 * straight would test the slot mechanics, which are HIL-754's and already covered; what this leaf
 * adds is the transport, and only a payload that has been round-tripped proves it.
 *
 * Each node is a real {@see LogStoreAgent} over a temp directory of its own, started under its own
 * cluster identity - which is how they end up with different node ids, since an agent learns which
 * node it is from the environment at its start.
 */
final class LogIndexPushIntegrationTest extends TestCase
{
    private const string NODE_ONE = 'node-1';

    private const string NODE_TWO = 'node-2';

    /** @var array<string, string> Node id → its fixture log directory */
    private array $dirs = [];

    private string $logFile = '';

    private ?EnvAccessor $previousEnv = null;

    private ?ClusterContext $previousCluster = null;

    protected function setUp(): void
    {
        // Outside the fixtures on purpose: both agents log into the very directories they measure.
        $this->logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-logindex-cluster-journal');
        Logger::setLogFile($this->logFile);

        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        $this->previousCluster = Hilos::$cluster;
        Hilos::$env = new EnvAccessor();
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new ClusterLogRtContext();
        Hilos::$rt->configure();
        Hilos::$rt->mountFeatureRuntime([]);
        // The register is the master's to write, and these cases play the master when they say
        // what the cluster currently sees.
        RtTruthSourceRegistry::registerDaemon(StateHilosClusterNode::RT_COLLECTION);

        foreach ([self::NODE_ONE, self::NODE_TWO] as $nodeId) {
            $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hilos-logindex-' . $nodeId . '-' . uniqid('', true);
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                $this->fail("Could not create fixture directory: {$dir}");
            }
            $this->dirs[$nodeId] = $dir;
        }
    }

    protected function tearDown(): void
    {
        foreach ([
            EnvConstants::DAEMON_LOG_FILE,
            EnvConstants::CLUSTER_ENABLED,
            EnvConstants::CLUSTER_NODE_ID,
            EnvConstants::CLUSTER_NODE_ROLE,
            EnvConstants::CLUSTER_PEER_ADVERTISE,
        ] as $key) {
            putenv($key->name);
        }
        if ($this->previousEnv !== null) {
            Hilos::$env = $this->previousEnv;
        }
        RtTruthSourceRegistry::unregisterDaemon(StateHilosClusterNode::RT_COLLECTION);
        Hilos::$cluster = $this->previousCluster;
        Hilos::$rt = null;
        Hilos::$sr = null;
        Logger::resetLogFile();
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
        foreach ($this->dirs as $dir) {
            $this->removeTree($dir);
        }

        parent::tearDown();
    }

    public function testANodeThatReportsGetsASlotOfItsOwn(): void
    {
        $this->write(self::NODE_ONE, 'agent-a.log', 100);
        $aggregator = $this->startedAggregator();

        $this->nodeAgent(self::NODE_ONE);
        $this->deliver($aggregator);

        $slot = $aggregator->clusterIndex()->node(self::NODE_ONE);
        $this->assertNotNull($slot);
        $this->assertSame(self::NODE_ONE, $slot->nodeId);
        $this->assertSame(['agent-a.log'], array_column($slot->index->keys, 'key'));
    }

    /**
     * The same basename on two machines is two files, rotated and evicted apart. Folding them by
     * name would understate both the count and the weight of what is on the disks.
     */
    public function testTwoNodesKeepTheirOwnSlotsAndTheSameKeyCountsTwice(): void
    {
        $this->write(self::NODE_ONE, 'worker-0.log', 100);
        $this->write(self::NODE_TWO, 'worker-0.log', 300);
        $aggregator = $this->startedAggregator();

        $this->nodeAgent(self::NODE_ONE);
        $this->nodeAgent(self::NODE_TWO);
        $this->deliver($aggregator);

        $totals = $aggregator->clusterIndex()->totals();
        $this->assertSame(2, $totals->nodeCount);
        $this->assertSame([LogKeySummary::CLASS_WORKER => 2], $totals->streamCountByClass);
        $this->assertSame([LogKeySummary::CLASS_WORKER => 400], $totals->bytesByClass);
    }

    /**
     * The frame is the whole index, so filing it is an exchange: a key the node no longer has is
     * gone from the cluster picture too, without anybody having to say it was removed.
     */
    public function testASecondFrameFromANodeReplacesItsSlotWhole(): void
    {
        $this->write(self::NODE_ONE, 'agent-a.log', 100);
        $this->write(self::NODE_ONE, 'agent-gone.log', 700);
        $aggregator = $this->startedAggregator();
        $agent = $this->nodeAgent(self::NODE_ONE);
        $this->deliver($aggregator);

        unlink($this->dirs[self::NODE_ONE] . DIRECTORY_SEPARATOR . 'agent-gone.log');
        $this->write(self::NODE_ONE, 'agent-a.log', 250);
        $agent->walkStore(time());
        $agent->pushIndexIfDue(microtime(true) + 61.0);
        $this->deliver($aggregator);

        $slot = $aggregator->clusterIndex()->node(self::NODE_ONE);
        $this->assertSame(['agent-a.log'], array_column($slot?->index->keys ?? [], 'key'));
        $this->assertSame([LogKeySummary::CLASS_AGENT => 250], $aggregator->clusterIndex()->totals()->bytesByClass);
    }

    /**
     * A machine that was rebuilt while it was away comes back with a different directory, and the
     * picture takes it whole rather than merging it into what it remembered.
     */
    public function testANodeThatCameBackWithADifferentStoreReplacesWhatWasRemembered(): void
    {
        $this->write(self::NODE_ONE, 'agent-old.log', 100);
        $aggregator = $this->startedAggregator();
        $this->nodeAgent(self::NODE_ONE);
        $this->deliver($aggregator);

        unlink($this->dirs[self::NODE_ONE] . DIRECTORY_SEPARATOR . 'agent-old.log');
        $this->write(self::NODE_ONE, 'agent-new.log', 400);
        // Started over, the way a node that was down and came back is.
        $this->nodeAgent(self::NODE_ONE);
        $this->deliver($aggregator);

        $slot = $aggregator->clusterIndex()->node(self::NODE_ONE);
        $this->assertSame(['agent-new.log'], array_column($slot?->index->keys ?? [], 'key'));
        $this->assertSame(1, $aggregator->clusterIndex()->totals()->nodeCount);
    }

    /**
     * A node that cannot read its own directory reports exactly that. It has to stand in the
     * picture as a node with no data, because the overview draws "no data" and an absent node
     * draws nothing at all.
     */
    public function testANodeThatCannotReadItsStoreStandsInThePictureAsUnavailable(): void
    {
        $aggregator = $this->startedAggregator();

        $this->nodeAgent(self::NODE_ONE, logDirectory: false);
        $this->deliver($aggregator);

        $slot = $aggregator->clusterIndex()->node(self::NODE_ONE);
        $this->assertNotNull($slot);
        $this->assertFalse($slot->index->available);
        $this->assertSame(1, $aggregator->clusterIndex()->totals()->unavailableNodeCount);
    }

    /**
     * The files on the disk of a machine that fell over still exist, so its slot stays and its
     * figures stay in the sums; what changes is that the view says the cluster no longer sees it.
     */
    public function testANodeTheClusterLostIsMarkedOfflineAndKeepsItsFigures(): void
    {
        $this->write(self::NODE_ONE, 'agent-a.log', 100);
        $this->write(self::NODE_TWO, 'agent-b.log', 300);
        $aggregator = $this->startedAggregator();
        $this->nodeAgent(self::NODE_ONE);
        $this->nodeAgent(self::NODE_TWO);
        $this->deliver($aggregator);

        $this->publishMembership(self::NODE_ONE, online: true);
        $this->publishMembership(self::NODE_TWO, online: false);

        $this->assertTrue($this->view($aggregator, self::NODE_ONE)->online);
        $this->assertFalse($this->view($aggregator, self::NODE_TWO)->online);
        $this->assertSame(
            [LogKeySummary::CLASS_AGENT => 400],
            $aggregator->clusterIndex()->totals()->bytesByClass,
            'A node that fell over keeps its files, and its files keep their weight',
        );
    }

    /**
     * A frame really arrived from this node, so it exists; the register is simply a publication
     * behind. Reading the missing row as "gone" would blink every new node offline for a tick.
     */
    public function testANodeMissingFromTheRegisterCountsAsOnline(): void
    {
        $this->write(self::NODE_ONE, 'agent-a.log', 100);
        $aggregator = $this->startedAggregator();
        $this->nodeAgent(self::NODE_ONE);
        $this->deliver($aggregator);

        $this->assertTrue($this->view($aggregator, self::NODE_ONE)->online);
    }

    /**
     * @return LogAggregatorAgent Aggregator past its start hook, holding an empty picture
     */
    private function startedAggregator(): LogAggregatorAgent
    {
        $agent = new LogAggregatorAgent();
        $agent->onStart();

        return $agent;
    }

    /**
     * Starts the owner of one node's log directory, which queues its first frame at once.
     *
     * @param string $nodeId Node the agent runs on
     * @param bool $logDirectory Whether the environment names a readable log directory
     * @return LogStoreAgent Started agent
     */
    private function nodeAgent(string $nodeId, bool $logDirectory = true): LogStoreAgent
    {
        if ($logDirectory) {
            putenv(EnvConstants::DAEMON_LOG_FILE->name . '=' . $this->dirs[$nodeId] . '/daemon.log');
        } else {
            putenv(EnvConstants::DAEMON_LOG_FILE->name);
        }
        putenv(EnvConstants::CLUSTER_ENABLED->name . '=true');
        putenv(EnvConstants::CLUSTER_NODE_ID->name . '=' . $nodeId);
        putenv(EnvConstants::CLUSTER_NODE_ROLE->name . '=' . NodeRole::Master->value);
        putenv(EnvConstants::CLUSTER_PEER_ADVERTISE->name . '=10.0.0.1:7000');
        // A context of its own per node: it remembers the identity it resolved, and these two
        // nodes are two identities living in one process.
        Hilos::$cluster = new ClusterContext();

        $agent = new LogStoreAgent();
        $agent->onStart();

        return $agent;
    }

    /**
     * Carries every queued frame to the aggregator through its wire form.
     *
     * @param LogAggregatorAgent $aggregator Receiver to hand the frames to
     */
    private function deliver(LogAggregatorAgent $aggregator): void
    {
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $this->assertSame(HilosSignalConstants::LOGS_NODE_INDEX_REPORT, $signal->signalName->getName());
            $this->assertInstanceOf(AgentSignalData::class, $signal->data);
            $this->assertInstanceOf(NodeLogIndexSignalData::class, $signal->data->data);

            $aggregator->onSignalAgent(
                new AgentSignalData(data: NodeLogIndexSignalData::fromArray($signal->data->data->toArray())),
                'test',
                HilosSignalConstants::LOGS_NODE_INDEX_REPORT,
            );
        }
    }

    /**
     * Writes one node's row into the cluster register, the way its master publishes it.
     *
     * @param string $nodeId Node the row describes
     * @param bool $online Whether the master still sees it connected
     */
    private function publishMembership(string $nodeId, bool $online): void
    {
        Hilos::$rt?->hilosClusterNodes->actions->publish(
            $nodeId,
            NodeRole::Master->value,
            [],
            null,
            $online,
            microtime(true),
        );
    }

    /**
     * @param LogAggregatorAgent $aggregator Aggregator to read
     * @param string $nodeId Node to find among its views
     * @return ClusterLogNodeView View of that node
     */
    private function view(LogAggregatorAgent $aggregator, string $nodeId): ClusterLogNodeView
    {
        foreach ($aggregator->nodeViews() as $view) {
            if ($view->nodeId === $nodeId) {
                return $view;
            }
        }

        $this->fail("No view for node {$nodeId}");
    }

    /**
     * Writes one live log file of the given size into one node's store.
     *
     * @param string $nodeId Node whose store to write into
     * @param string $name Basename to write
     * @param int $bytes Size in bytes
     */
    private function write(string $nodeId, string $name, int $bytes): void
    {
        file_put_contents($this->dirs[$nodeId] . DIRECTORY_SEPARATOR . $name, str_repeat('x', $bytes));
    }

    /**
     * Recursively removes a directory tree.
     *
     * @param string $path Directory or file to remove
     */
    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                unlink($path);
            }

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path . DIRECTORY_SEPARATOR . $entry);
        }
        rmdir($path);
    }
}

/**
 * Runtime context carrying the framework's own mounts, of which one is the cluster register.
 */
final class ClusterLogRtContext extends RtContext
{
    public function configure(): void
    {
    }
}

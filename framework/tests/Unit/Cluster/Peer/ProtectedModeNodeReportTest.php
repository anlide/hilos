<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Peer;

use Closure;
use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Consensus\ConsensusInspection;
use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\Leadership;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerHelloDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeNodeReportDTO;
use Hilos\Cluster\Peer\DTO\PeerReadyDTO;
use Hilos\Cluster\Peer\PeerLink;
use Hilos\Cluster\Peer\PeerProtocol;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Cluster\PendingLeadership;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Sync\DTO\RtSyncSignalDataInterface;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\ProtectedMode\DTO\ProtectedModeQuiesceData;
use Hilos\ProtectedMode\ProtectedModeCoordinator;
use Hilos\ProtectedMode\ProtectedModeQuiesceRoster;
use Hilos\Runtime\State\Item\ProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\TestCase;
use Socket;

/** Exercises the real typed peer path over socket pairs, without agents, DB or files. */
final class ProtectedModeNodeReportTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;
    private ?ClusterContext $previousCluster = null;
    private ?RtContext $previousRt = null;
    private ?SignalRouter $previousRouter = null;
    private array $previousVariables = [];
    private array $sockets = [];
    private ?string $leader = 'node-b';
    private int $term = 7;

    /** Mounts only the existing framework runtime and a read-only consensus test port. */
    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        $this->previousCluster = Hilos::$cluster;
        $this->previousRt = Hilos::$rt;
        $this->previousRouter = Hilos::$sr;
        Hilos::$env = new EnvAccessor();
        Hilos::$sr = null;
        foreach ([
            'SOCKET_READ_BUFFER_SIZE' => '65536',
            'CLUSTER_ENABLED' => 'true',
            'CLUSTER_NODE_ID' => 'node-a',
            'CLUSTER_NODE_ROLE' => 'master',
            'CLUSTER_LINK_KEEPALIVE_INTERVAL_MS' => '60000',
            'CLUSTER_LINK_TIMEOUT_MS' => '60000',
        ] as $key => $value) {
            $this->previousVariables[$key] = getenv($key);
            putenv($key . '=' . $value);
        }
        Hilos::$cluster = new ClusterContext();
        $leadership = $this->createMockForIntersectionOfInterfaces([Leadership::class, ConsensusInspection::class]);
        $leadership->method('leaderId')->willReturnCallback(fn(): ?string => $this->leader);
        $leadership->method('amLeader')->willReturnCallback(fn(): bool => $this->leader === 'node-a');
        $leadership->method('term')->willReturnCallback(fn(): int => $this->term);
        Hilos::$cluster->registerLeadership($leadership);
        Hilos::$rt = new NodeReportTestRtContext();
        Hilos::$rt->mountFeatureRuntime([]);
        RtTruthSourceRegistry::registerDaemon(ProtectedModeRuntime::RT_ITEM);
    }

    /** Releases every socket and process-global test binding. */
    protected function tearDown(): void
    {
        RtTruthSourceRegistry::unregisterDaemon(ProtectedModeRuntime::RT_ITEM);
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        Hilos::$env = $this->previousEnv;
        Hilos::$cluster = $this->previousCluster;
        Hilos::$rt = $this->previousRt;
        Hilos::$sr = $this->previousRouter;
        foreach ($this->previousVariables as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
        parent::tearDown();
    }

    public function testNullableSnapshotRoundTripsWithoutAnIdentityOrPrivateData(): void
    {
        foreach ([
            new PeerProtectedModeNodeReportDTO(null, null, null, null, null),
            $this->leaderReport(),
        ] as $frame) {
            $parsed = PeerDTO::fromWire($frame->toJson());
            $this->assertInstanceOf(PeerProtectedModeNodeReportDTO::class, $parsed);
            $this->assertSame($frame->toArray(), $parsed->toArray());
            $this->assertSame(['type', 'phase', 'term', 'quiesceNodeIds', 'pendingNodeIds', 'initiatorNodeId'], array_keys($parsed->toArray()));
        }
    }

    public function testParserRequiresEveryNullableKey(): void
    {
        foreach (['phase', 'term', 'quiesceNodeIds', 'pendingNodeIds', 'initiatorNodeId'] as $field) {
            $data = $this->leaderReport()->toArray();
            unset($data[$field]);
            try {
                PeerProtectedModeNodeReportDTO::fromArray($data);
                $this->fail('Missing nullable key was accepted: ' . $field);
            } catch (PeerTransportException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testParserRejectsMalformedPhaseTermAndParticipantLists(): void
    {
        foreach ([
            ['phase' => 'invented'], ['phase' => 3], ['term' => '7'], ['term' => false],
            ['quiesceNodeIds' => ['x' => 'node-a']], ['quiesceNodeIds' => ['node-a', 'node-a']],
            ['quiesceNodeIds' => [null]], ['pendingNodeIds' => ['']], ['pendingNodeIds' => [7]],
            ['pendingNodeIds' => ['node-a', 'node-a']], ['pendingNodeIds' => ['other']],
            ['quiesceNodeIds' => null, 'pendingNodeIds' => []], ['initiatorNodeId' => 3],
        ] as $patch) {
            try {
                PeerProtectedModeNodeReportDTO::fromArray([...$this->leaderReport()->toArray(), ...$patch]);
                $this->fail('Malformed node report was accepted: ' . json_encode($patch));
            } catch (PeerTransportException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testInitialSelfAndTypedRemoteReportsPublishTheExistingRuntime(): void
    {
        $server = $this->server();
        $recorder = new NodeReportSyncRecorder();
        Hilos::$sr = $recorder;
        $this->assertNull(Hilos::$rt->hilosProtectedModeRuntime->maintenanceNodes);
        $this->tick($server, 100.0);
        $this->assertSame([
            ['nodeId' => 'node-a', 'phase' => 'inactive', 'quiesced' => null,
                'isLeader' => false, 'isInitiator' => false, 'reportState' => 'current'],
        ], Hilos::$rt->hilosProtectedModeRuntime->maintenanceNodes);
        $this->assertCount(1, $recorder->updates);
        $this->tick($server, 100.1);
        $this->assertCount(1, $recorder->updates);

        [$link, $far] = $this->link($server, 'node-b');
        $this->feed($link, $far, $this->leaderReport());
        $this->tick($server, 101.0);
        $this->assertSame([
            ['nodeId' => 'node-a', 'phase' => 'inactive', 'quiesced' => false,
                'isLeader' => false, 'isInitiator' => true, 'reportState' => 'current'],
            ['nodeId' => 'node-b', 'phase' => 'verifying', 'quiesced' => true,
                'isLeader' => true, 'isInitiator' => false, 'reportState' => 'current'],
        ], Hilos::$rt->hilosProtectedModeRuntime->maintenanceNodes);
        $this->assertCount(2, $recorder->updates);
        $this->feed($link, $far, $this->leaderReport());
        $this->tick($server, 102.0);
        $this->assertCount(2, $recorder->updates);
    }

    public function testReplacementDiscardsOldReportAndDrainingCloseCannotEraseTheNewOne(): void
    {
        $server = $this->server();
        [$old, $oldFar] = $this->link($server, 'node-b');
        $this->feed($old, $oldFar, $this->leaderReport());
        $this->tick($server, 100.0);
        [$new, $newFar] = $this->link($server, 'node-b');
        $this->tick($server, 101.0);
        $this->assertSame([null, null], array_column($this->rows(), 'quiesced'));
        $this->assertSame('missing', $this->rows()[1]['reportState']);
        $this->feed($old, $oldFar, $this->leaderReport());
        $this->tick($server, 102.0);
        $this->assertNull($this->rows()[1]['phase']);

        $this->feed($new, $newFar, new PeerProtectedModeNodeReportDTO('active', 7, ['node-b'], [], 'node-b'));
        $server->onLinkClosed($old);
        $server->removeClient($old);
        $this->tick($server, 103.0);
        $this->assertSame('active', $this->rows()[1]['phase']);
        $this->assertTrue($this->rows()[1]['quiesced']);
        $this->assertTrue($this->rows()[1]['isInitiator']);
        $server->onLinkClosed($new);
        $server->removeClient($new);
        $this->tick($server, 104.0);
        $this->assertSame('silent', $this->rows()[1]['reportState']);
        $this->assertNull($this->rows()[1]['phase']);
        $this->assertSame([null, null], array_column($this->rows(), 'quiesced'));
    }

    public function testTermAndLeadershipChangesCannotReviveCachedAcknowledgements(): void
    {
        $server = $this->server();
        [$link, $far] = $this->link($server, 'node-b');
        $this->feed($link, $far, $this->leaderReport());
        $this->tick($server, 100.0);
        $this->term = 8;
        $this->tick($server, 101.0);
        $this->assertSame([null, null], array_column($this->rows(), 'quiesced'));
        $this->feed($link, $far, $this->leaderReport());
        $this->tick($server, 102.0);
        $this->assertSame([false, false], array_column($this->rows(), 'isInitiator'));

        $fresh = new PeerProtectedModeNodeReportDTO('active', 8, ['node-a', 'node-b'], [], 'node-a');
        $this->feed($link, $far, $fresh);
        $this->tick($server, 103.0);
        $this->assertSame([true, true], array_column($this->rows(), 'quiesced'));
        $this->leader = null;
        $this->tick($server, 104.0);
        $this->assertSame([false, false], array_column($this->rows(), 'isLeader'));
        $this->assertSame([null, null], array_column($this->rows(), 'quiesced'));
        $this->leader = 'node-b';
        $this->tick($server, 105.0);
        $this->assertSame([null, null], array_column($this->rows(), 'quiesced'));
        $this->assertSame('active', $this->rows()[1]['phase']);
        $this->feed($link, $far, $fresh);
        $this->tick($server, 106.0);
        $this->assertSame([true, true], array_column($this->rows(), 'quiesced'));
    }

    public function testSlaveOfflineNullAndMissingReportsStayInTheMembershipProjection(): void
    {
        $server = $this->server();
        Hilos::$cluster->registry()->mergeMembership(NodeIdentity::of('offline', NodeRole::Slave, []), 0.0);
        [$slave, $slaveFar] = $this->link($server, 'slave', role: NodeRole::Slave);
        $this->link($server, 'missing');
        $this->feed($slave, $slaveFar, new PeerProtectedModeNodeReportDTO(null, null, null, null, null));
        $this->tick($server, 100.0);
        $this->assertSame(['missing', 'node-a', 'offline', 'slave'], array_column($this->rows(), 'nodeId'));
        $this->assertSame(['missing', 'current', 'silent', 'missing'], array_column($this->rows(), 'reportState'));
        $this->assertSame([null, 'inactive', null, null], array_column($this->rows(), 'phase'));
        $this->feed($slave, $slaveFar, new PeerProtectedModeNodeReportDTO('inactive', null, null, null, null));
        $this->tick($server, 101.0);
        $this->assertSame('current', $this->rows()[3]['reportState']);
    }

    public function testCandidatesAndRejectedForeignHandshakesCannotCreateRowsOrReports(): void
    {
        $server = $this->server();
        [$candidate] = $this->link($server, 'candidate', ready: false);
        $server->onProtectedModeNodeReportReceived($candidate, $this->leaderReport());
        [$foreign, $far] = $this->link($server, 'foreign', ready: false, certifiedName: 'another-authority');
        $server->onProtectedModeNodeReportReceived($foreign, $this->leaderReport());
        $this->tick($server, 100.0);
        $this->assertNull($foreign->remoteIdentity());
        $this->assertSame(['node-a'], array_column($this->rows(), 'nodeId'));
    }

    public function testChangedReportsQuietTicksPeriodicRepairAndNewMasterLinks(): void
    {
        $server = $this->server();
        [$master, $masterFar] = $this->link($server, 'node-b');
        [$slave, $slaveFar] = $this->link($server, 'slave', role: NodeRole::Slave);
        $this->tick($server, 100.0);
        $first = $this->sentReports($master, $masterFar);
        $this->assertCount(1, $first);
        $this->assertSame('inactive', $first[0]->phase);
        $this->assertSame([], $this->sentReports($slave, $slaveFar));
        $this->tick($server, 101.0);
        $this->assertSame([], $this->sentReports($master, $masterFar));
        Hilos::$rt->hilosProtectedModeRuntime->actions->enterVerifying();
        $this->tick($server, 102.0);
        $this->assertSame('verifying', $this->sentReports($master, $masterFar)[0]->phase);
        $this->tick($server, 106.9);
        $this->assertSame([], $this->sentReports($master, $masterFar));
        $this->tick($server, 107.0);
        $this->assertCount(1, $this->sentReports($master, $masterFar));
        [$newMaster, $newFar] = $this->link($server, 'new-master');
        $this->tick($server, 108.0);
        $this->assertCount(1, $this->sentReports($newMaster, $newFar));
        $this->assertCount(1, $this->sentReports($master, $masterFar));
    }

    public function testLeaderSelfReportUsesTheCurrentRosterWithoutChangingTheFreeze(): void
    {
        $this->leader = 'node-a';
        $server = $this->server();
        $pending = ['node-b'];
        $port = $this->createMockForIntersectionOfInterfaces([ProtectedModeCoordinator::class, ProtectedModeQuiesceRoster::class]);
        $port->method('quiesceNodeIds')->willReturn(['node-b']);
        $port->method('pendingNodeIds')->willReturnCallback(static function () use (&$pending): array { return $pending; });
        $server->registerProtectedMode($port);
        Hilos::$rt->hilosProtectedModeRuntime->actions->enterVerificationWindow(
            new ProtectedModeQuiesceData('manual_maintenance', 'index', null, 'node-b', null), null,
        );
        [$link, $far] = $this->link($server, 'node-b');
        $this->tick($server, 100.0);
        $this->assertSame([null, false], array_column($this->rows(), 'quiesced'));
        $this->assertSame([false, true], array_column($this->rows(), 'isInitiator'));
        $this->assertSame(['node-b'], $this->sentReports($link, $far)[0]->pendingNodeIds);
        $pending = [];
        $this->tick($server, 101.0);
        $this->assertSame([null, true], array_column($this->rows(), 'quiesced'));
        $this->assertSame([], $this->sentReports($link, $far)[0]->pendingNodeIds);
        $pending = ['node-b'];
        $this->tick($server, 102.0);
        $this->assertSame([null, false], array_column($this->rows(), 'quiesced'));
    }

    public function testSlaveSendsEvenWithoutRuntimeAndNeverPublishesAMasterProjection(): void
    {
        $server = $this->server(NodeRole::Slave);
        Hilos::$cluster->registerLeadership(new PendingLeadership());
        [$link, $far] = $this->link($server, 'node-b');
        Hilos::$rt = null;
        $this->tick($server, 100.0);
        $report = $this->sentReports($link, $far)[0];
        $this->assertNull($report->phase);
        $this->assertNull($report->term);
        $this->assertNull(Hilos::$rt);
        Hilos::$rt = new NodeReportTestRtContext();
        Hilos::$rt->mountFeatureRuntime([]);
        $this->tick($server, 101.0);
        $this->assertSame('inactive', $this->sentReports($link, $far)[0]->phase);
        $this->assertNull(Hilos::$rt->hilosProtectedModeRuntime->maintenanceNodes);
    }

    /** @return PeerProtectedModeNodeReportDTO A follower-initiated direct window with one pending node */
    private function leaderReport(): PeerProtectedModeNodeReportDTO
    {
        return new PeerProtectedModeNodeReportDTO('verifying', 7, ['node-a', 'node-b'], ['node-a'], 'node-a');
    }

    /**
     * @param NodeRole $role Local role
     * @return PeerServer Real server without a listener or dial seeds
     */
    private function server(NodeRole $role = NodeRole::Master): PeerServer
    {
        return new PeerServer('127.0.0.1', 0, NodeIdentity::of('node-a', $role, []), [], PeerTestTls::unread(), PeerTestMarkers::shared());
    }

    /**
     * @param PeerServer $server Owning server
     * @param string $nodeId Remote hello identity
     * @param NodeRole $role Remote role
     * @param bool $ready Whether to complete the ready handshake
     * @param ?string $certifiedName Test transport's authenticated certificate identity
     * @return array{PeerLink, Socket} Link and remote socket
     */
    private function link(
        PeerServer $server,
        string $nodeId,
        NodeRole $role = NodeRole::Master,
        bool $ready = true,
        ?string $certifiedName = null,
    ): array {
        $pair = [];
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        [$near, $far] = $pair;
        socket_set_nonblock($near);
        socket_set_nonblock($far);
        $this->sockets = [...$this->sockets, $near, $far];
        $link = new PeerLink($near, $server, NodeIdentity::of('node-a', NodeRole::Master, []), false,
            new NamedPeerTestTransport($near, $certifiedName ?? $nodeId));
        Closure::bind(static function () use ($server, $link): void { $server->clients[] = $link; }, null, PeerServer::class)();
        $this->feed($link, $far, new PeerHelloDTO(PeerProtocol::VERSION, $nodeId, $role, [], PeerTestMarkers::onWire(), null));
        if ($ready) {
            $this->feed($link, $far, new PeerReadyDTO(false));
            $this->assertSame($nodeId, $link->remoteIdentity()?->nodeId);
        }
        return [$link, $far];
    }

    /**
     * @param PeerLink $link Receiving link
     * @param Socket $far Remote socket
     * @param PeerDTO $frame Frame to parse and dispatch for real
     */
    private function feed(PeerLink $link, Socket $far, PeerDTO $frame): void
    {
        socket_write($far, $frame->toJson() . "\n");
        $link->read();
    }

    /**
     * @param PeerServer $server Real server whose post-consensus diagnostic pass is driven
     * @param float $now Deterministic tick time without sleeps
     */
    private function tick(PeerServer $server, float $now): void
    {
        Closure::bind(static function () use ($server, $now): void { $server->tickProtectedModeNodeReports($now); }, null, PeerServer::class)();
    }

    /**
     * @param PeerLink $link Sending link
     * @param Socket $far Remote socket
     * @return list<PeerProtectedModeNodeReportDTO> Diagnostic frames queued since the last observation
     */
    private function sentReports(PeerLink $link, Socket $far): array
    {
        $link->write();
        $bytes = socket_read($far, 131072);
        if ($bytes === false || $bytes === '') {
            return [];
        }
        $reports = [];
        foreach (explode("\n", trim($bytes)) as $json) {
            $frame = PeerDTO::fromWire($json);
            if ($frame instanceof PeerProtectedModeNodeReportDTO) {
                $reports[] = $frame;
            }
        }
        return $reports;
    }

    /** @return list<array<string, string|bool|null>> Published local RT rows */
    private function rows(): array
    {
        $rows = Hilos::$rt->hilosProtectedModeRuntime->maintenanceNodes;
        $this->assertNotNull($rows);
        return $rows;
    }
}

final class NodeReportTestRtContext extends RtContext
{
    /** No project state is needed beside the framework's mounted singleton. */
    public function configure(): void
    {
    }
}

final class NodeReportSyncRecorder extends SignalRouter
{
    /** @var list<RtSyncUpdatedSignalData> Updates sent to the existing local runtime transport */
    public array $updates = [];

    /**
     * @param string $signalName Existing runtime signal name
     * @param RtSyncSignalDataInterface $signalData Runtime update to observe
     */
    public function queueRtSyncSignal(string $signalName, RtSyncSignalDataInterface $signalData): void
    {
        if ($signalData instanceof RtSyncUpdatedSignalData) {
            $this->updates[] = $signalData;
        }
    }
}

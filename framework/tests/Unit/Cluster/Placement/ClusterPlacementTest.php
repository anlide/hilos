<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Placement;

use Hilos\Cluster\Exception\PlacementCapabilityException;
use Hilos\Cluster\Peer\DTO\PeerAgentStatusDTO;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerPlaceAgentDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacedAgentEntry;
use Hilos\Cluster\Peer\DTO\PeerPlacementQueryDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementReportDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementViewDTO;
use Hilos\Cluster\Peer\DTO\PeerStopAgentDTO;
use Hilos\Cluster\Placement\AgentLocationKind;
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Cluster\Placement\PlacementExecutor;
use Hilos\Cluster\Placement\PlacementMesh;
use Hilos\Cluster\Placement\PlacementObserver;
use Hilos\Cluster\Placement\PlacementRecord;
use Hilos\Cluster\Placement\PlacementState;
use Hilos\Cluster\Placement\ResourceProfile;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Exception\NoSuitableWorkerException;
use Hilos\Hilos;
use Hilos\Utils\Logger;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Throwable;

/**
 * Unit tests for the agent-placement coordinator (HIL-179).
 *
 * The coordinator is driven against a fake mesh and executor, so the leader placement
 * side and the node execution side are exercised without a peer socket or a worker pool.
 * The self node is 'leader'; 'gpu-node' is a data-plane node advertising a capability.
 *
 * Both agent types are declared policy-placed, because that is the one cell whose location is
 * answered from what this coordinator tracks (HIL-670): an every-node replica is always here
 * and a leader-hosted singleton is wherever leadership sits, neither of which passes through
 * the placement registry at all.
 */
final class ClusterPlacementTest extends TestCase
{
    private const string SELF = 'leader';

    /** @var string Capacity tag every node that must stay a candidate declares (HIL-448) */
    private const string SLOTS = 'slots=10';

    /** @var class-string<Hilos> App class bound before this test touched it */
    private string $boundAppClass;

    protected function setUp(): void
    {
        $this->boundAppClass = Hilos::appClass();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, PlacementTestHilos::class);
    }

    protected function tearDown(): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->boundAppClass);
        Logger::resetLogFile();

        parent::tearDown();
    }

    public function testLocalPlacementRunsTheLocalStartPathAndTracksStarted(): void
    {
        $mesh = new FakePlacementMesh([self::SELF => [self::SLOTS]]);
        $executor = new FakePlacementExecutor();
        $placement = new ClusterPlacement(self::SELF, $mesh, $executor);

        $placement->placeAgentOnNode('chat', '1', self::SELF);

        $this->assertSame([['chat', '1']], $executor->executed, 'A local placement runs the local start path');
        $this->assertSame([], $mesh->sent, 'A local placement sends no frame');
        $record = $placement->registry()->get('chat:1');
        $this->assertNotNull($record);
        $this->assertSame(self::SELF, $record->nodeId);
        $this->assertSame(PlacementState::Started, $record->state);
    }

    public function testLocalPlacementFailureTracksFailedAndRethrows(): void
    {
        $mesh = new FakePlacementMesh([self::SELF => [self::SLOTS]]);
        $executor = new FakePlacementExecutor();
        $executor->failWith = new NoSuitableWorkerException('regular', false);
        $placement = new ClusterPlacement(self::SELF, $mesh, $executor);

        try {
            $placement->placeAgentOnNode('chat', null, self::SELF);
            $this->fail('A failing local placement must surface the executor error');
        } catch (NoSuitableWorkerException) {
            // expected
        }

        $this->assertSame(PlacementState::Failed, $placement->registry()->get('chat')?->state);
    }

    public function testRemotePlacementSendsPlaceFrameAndTracksPending(): void
    {
        $mesh = new FakePlacementMesh(['gpu-node' => ['gpu', self::SLOTS]], linked: ['gpu-node']);
        $executor = new FakePlacementExecutor(['gpu']);
        $placement = new ClusterPlacement(self::SELF, $mesh, $executor);

        $placement->placeAgentOnNode('render', '9', 'gpu-node');

        $this->assertSame([], $executor->executed, 'A remote placement does not run locally');
        $this->assertCount(1, $mesh->sent);
        [$nodeId, $frame] = $mesh->sent[0];
        $this->assertSame('gpu-node', $nodeId);
        $this->assertInstanceOf(PeerPlaceAgentDTO::class, $frame);
        $this->assertSame(PlacementState::Placing, $placement->registry()->get('render:9')?->state);
    }

    public function testPlacementRejectsWhenTheNodeLacksARequiredCapability(): void
    {
        $mesh = new FakePlacementMesh(['plain-node' => [self::SLOTS]], linked: ['plain-node']);
        $executor = new FakePlacementExecutor(['gpu']);
        $placement = new ClusterPlacement(self::SELF, $mesh, $executor);

        try {
            $placement->placeAgentOnNode('render', null, 'plain-node');
            $this->fail('A placement onto a node missing a required capability must be rejected');
        } catch (PlacementCapabilityException) {
            // expected
        }

        $this->assertSame([], $mesh->sent, 'Nothing is sent when the capability check fails');
        $this->assertSame(0, $placement->registry()->count());
    }

    public function testNodeExecutesPlaceFrameAndRepliesStarted(): void
    {
        $mesh = new FakePlacementMesh([], linked: ['leader']);
        $executor = new FakePlacementExecutor(workerId: 5);
        $placement = new ClusterPlacement('gpu-node', $mesh, $executor);

        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9'));

        $this->assertSame([['render', '9']], $executor->executed);
        [$nodeId, $frame] = $mesh->sent[0];
        $this->assertSame('leader', $nodeId, 'The status reply goes back to the requesting leader');
        $this->assertInstanceOf(PeerAgentStatusDTO::class, $frame);
        $this->assertSame(PlacementState::Started, $frame->state);
        $this->assertSame(5, $frame->workerId);
    }

    /**
     * A placement accepted while a monopolistic worker is raised for the agent is answered once the
     * agent is seated, with the worker it landed on, and only then (HIL-998). The start fact is
     * what answers it: a tick does not.
     */
    public function testNodeDefersTheStartedReplyUntilTheWaitingAgentIsSeated(): void
    {
        $mesh = new FakePlacementMesh([], linked: ['leader']);
        $executor = new FakePlacementExecutor();
        $executor->waitsForWorker = true;
        $placement = new ClusterPlacement('gpu-node', $mesh, $executor);

        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', null));
        $placement->tick(microtime(true));

        $this->assertSame([], $mesh->sent, 'Nothing is answered while the agent waits for its worker');

        $executor->seatedWorkerId = -7;
        $placement->noteAgentStarted('render', null);
        $placement->noteAgentStarted('render', null);

        $this->assertCount(1, $mesh->sent);
        [$nodeId, $frame] = $mesh->sent[0];
        $this->assertSame('leader', $nodeId);
        $this->assertInstanceOf(PeerAgentStatusDTO::class, $frame);
        $this->assertSame(PlacementState::Started, $frame->state);
        $this->assertSame(-7, $frame->workerId);
    }

    /**
     * A deferred placement whose start does not finish is answered failed with that reason, and
     * a clock elapsing first does not answer it (HIL-1041).
     */
    public function testADeferredPlacementIsAnsweredFailedWhenTheStartFails(): void
    {
        $mesh = new FakePlacementMesh([], linked: ['leader']);
        $executor = new FakePlacementExecutor();
        $executor->waitsForWorker = true;
        $placement = new ClusterPlacement('gpu-node', $mesh, $executor);

        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', null));
        $placement->tick(microtime(true) + 60.0);

        $this->assertSame([], $mesh->sent, 'A deferred placement is not answered by a clock');

        $placement->noteAgentStartFailed('render', null, 'the monopolistic worker never came up');

        $this->assertCount(1, $mesh->sent);
        $frame = $mesh->sent[0][1];
        $this->assertInstanceOf(PeerAgentStatusDTO::class, $frame);
        $this->assertSame(PlacementState::Failed, $frame->state);
        $this->assertSame('the monopolistic worker never came up', $frame->error);
    }

    public function testAStopAnswersADeferredPlacementAndNothingFollowsIt(): void
    {
        $mesh = new FakePlacementMesh([], linked: ['leader']);
        $executor = new FakePlacementExecutor();
        $executor->waitsForWorker = true;
        $placement = new ClusterPlacement('gpu-node', $mesh, $executor);

        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', null));
        $placement->onStopAgent('leader', new PeerStopAgentDTO('render', null));
        $placement->noteAgentStartFailed('render', null, 'it was stopped while waiting for a monopolistic worker');

        $this->assertCount(1, $mesh->sent);
        $frame = $mesh->sent[0][1];
        $this->assertInstanceOf(PeerAgentStatusDTO::class, $frame);
        $this->assertSame(PlacementState::Stopped, $frame->state);
    }

    public function testALocalPlacementWaitingForAWorkerIsPlacingUntilSeated(): void
    {
        $mesh = new FakePlacementMesh([self::SELF => [self::SLOTS]]);
        $executor = new FakePlacementExecutor();
        $executor->waitsForWorker = true;
        $placement = new ClusterPlacement(self::SELF, $mesh, $executor);

        $placement->placeAgentOnNode('chat', null, self::SELF);

        $this->assertSame(PlacementState::Placing, $placement->registry()->get('chat')?->state);

        $executor->seatedWorkerId = -3;
        $placement->noteAgentStarted('chat', null);

        $this->assertSame(PlacementState::Started, $placement->registry()->get('chat')?->state);
        $this->assertSame([], $mesh->sent, 'A local placement sends no frame');
    }

    /**
     * A local placement whose start does not finish is failed with that reason, and a clock
     * elapsing first leaves it placing (HIL-1041).
     */
    public function testALocalPlacementIsFailedWhenTheStartFails(): void
    {
        $mesh = new FakePlacementMesh([self::SELF => [self::SLOTS]]);
        $executor = new FakePlacementExecutor();
        $executor->waitsForWorker = true;
        $placement = new ClusterPlacement(self::SELF, $mesh, $executor);

        $placement->placeAgentOnNode('chat', null, self::SELF);
        $placement->tick(microtime(true) + 60.0);

        $this->assertSame(PlacementState::Placing, $placement->registry()->get('chat')?->state);

        $placement->noteAgentStartFailed('chat', null, 'the monopolistic worker never came up');

        $this->assertSame(PlacementState::Failed, $placement->registry()->get('chat')?->state);
    }

    /**
     * Revoking a local placement still waiting for its worker drops the deferred answer, so a
     * later start-failed fact cannot rewrite a Refused record to Failed (HIL-1041).
     */
    public function testRevokingALocalPlacementDropsItsDeferredAnswer(): void
    {
        $mesh = new FakePlacementMesh([self::SELF => [self::SLOTS]]);
        $executor = new FakePlacementExecutor();
        $executor->waitsForWorker = true;
        $placement = new ClusterPlacement(self::SELF, $mesh, $executor);
        $placement->onBecameLeader();
        $placement->placeAgentOnNode('chat', null, self::SELF);

        $placement->refusePlacement('chat', null, self::SELF);
        $placement->noteAgentStartFailed('chat', null, 'it was stopped while waiting for a monopolistic worker');

        $this->assertSame(
            PlacementState::Refused,
            $placement->registry()->get('chat')?->state,
            'A refused placement is not rewritten by a deferred start-failed fact',
        );
    }

    public function testNodeRepliesFailedWhenExecutionThrows(): void
    {
        $mesh = new FakePlacementMesh([], linked: ['leader']);
        $executor = new FakePlacementExecutor();
        $executor->failWith = new NoSuitableWorkerException('regular', false);
        $placement = new ClusterPlacement('gpu-node', $mesh, $executor);

        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', null));

        $frame = $mesh->sent[0][1];
        $this->assertInstanceOf(PeerAgentStatusDTO::class, $frame);
        $this->assertSame(PlacementState::Failed, $frame->state);
        $this->assertNotNull($frame->error);
    }

    public function testLeaderTracksAStartedStatusAgainstTheReportingNode(): void
    {
        $placement = new ClusterPlacement(self::SELF, new FakePlacementMesh([]), new FakePlacementExecutor());

        $placement->onAgentStatus('gpu-node', PeerAgentStatusDTO::started('render', '9', 5));

        $record = $placement->registry()->get('render:9');
        $this->assertSame('gpu-node', $record?->nodeId);
        $this->assertSame(PlacementState::Started, $record?->state);
    }

    public function testLeaderForgetsAPlacementOnAStoppedStatus(): void
    {
        $placement = new ClusterPlacement(self::SELF, new FakePlacementMesh([]), new FakePlacementExecutor());
        $placement->onAgentStatus('gpu-node', PeerAgentStatusDTO::started('render', '9', 5));

        $placement->onAgentStatus('gpu-node', PeerAgentStatusDTO::stopped('render', '9'));

        $this->assertNull($placement->registry()->get('render:9'));
    }

    public function testStopOnARemoteNodeSendsAStopFrameAndForgets(): void
    {
        $mesh = new FakePlacementMesh(['gpu-node' => ['gpu', self::SLOTS]], linked: ['gpu-node']);
        $executor = new FakePlacementExecutor(['gpu']);
        $placement = new ClusterPlacement(self::SELF, $mesh, $executor);
        $placement->placeAgentOnNode('render', '9', 'gpu-node');
        $mesh->sent = [];

        $placement->stopAgentOnNode('render', '9', 'gpu-node');

        $this->assertInstanceOf(PeerStopAgentDTO::class, $mesh->sent[0][1]);
        $this->assertNull($placement->registry()->get('render:9'));
    }

    public function testNodeAnswersAQueryWithItsHostedSet(): void
    {
        $mesh = new FakePlacementMesh([], linked: ['leader']);
        $placement = new ClusterPlacement('gpu-node', $mesh, new FakePlacementExecutor(workerId: 5));
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9'));
        $mesh->sent = [];

        $placement->onPlacementQuery('leader');

        $report = $mesh->sent[0][1];
        $this->assertInstanceOf(PeerPlacementReportDTO::class, $report);
        $this->assertCount(1, $report->agents);
        $this->assertSame('render', $report->agents[0]->agentType);
    }

    public function testBecomingLeaderBroadcastsAQueryAndRebuildsFromReports(): void
    {
        $mesh = new FakePlacementMesh([]);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor());
        // A stale entry from a previous term must be cleared on the fresh rebuild.
        $placement->onAgentStatus('old-node', PeerAgentStatusDTO::started('stale', null, 1));

        $placement->onBecameLeader();

        $this->assertSame(0, $placement->registry()->count(), 'The view is cleared before the rebuild');
        $this->assertInstanceOf(PeerPlacementQueryDTO::class, $mesh->broadcast[0]);

        $placement->onPlacementReport('gpu-node', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')]));

        $record = $placement->registry()->get('render:9');
        $this->assertSame('gpu-node', $record?->nodeId);
        $this->assertSame(PlacementState::Started, $record?->state);
    }

    public function testLocateReportsTheHostingNodeOfARemotelyPlacedAgent(): void
    {
        $placement = new ClusterPlacement(self::SELF, new FakePlacementMesh([]), new FakePlacementExecutor());
        $placement->onAgentStatus('gpu-node', PeerAgentStatusDTO::started('render', '9', 5));

        $this->assertSame('gpu-node', $placement->locate('render', '9')->nodeId);
    }

    public function testLocateAnswersHereForALocallyPlacedAgent(): void
    {
        $mesh = new FakePlacementMesh([self::SELF => [self::SLOTS]]);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor());
        $placement->placeAgentOnNode('chat', '1', self::SELF);

        // The self short-circuit lives in the lookup, so a local agent routes locally.
        $this->assertSame(AgentLocationKind::Here, $placement->locate('chat', '1')->kind);
    }

    /**
     * An agent nobody placed is not the same as an agent placed here, and the lookup says so:
     * answering "here" would deliver its signals into workers that are not running it.
     */
    public function testLocateAnswersUnknownForAnAgentNobodyPlaced(): void
    {
        $placement = new ClusterPlacement(self::SELF, new FakePlacementMesh([]), new FakePlacementExecutor());

        $this->assertSame(AgentLocationKind::Unknown, $placement->locate('never_placed', null)->kind);
    }

    public function testLeaderReplacesADeadNodesAgentOnlyAfterTheFailoverGrace(): void
    {
        // 'leader' lacks the gpu tag, so the only capable target is the spare 'node-c'.
        $mesh = new FakePlacementMesh(
            capabilities: ['node-b' => ['gpu', self::SLOTS], 'node-c' => ['gpu', self::SLOTS]],
            linked: ['node-b', 'node-c'],
            online: [self::SELF, 'node-b', 'node-c'],
        );
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['gpu']), null, failoverGraceMs: 500, slaveWorkGraceMs: 250);
        $placement->onBecameLeader();
        $placement->onPlacementReport('node-b', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')]));
        $mesh->sent = [];

        // node-b dies; the spare is the only survivor able to host the agent.
        $mesh->online = [self::SELF, 'node-c'];
        $placement->noteNodeOffline('node-b', 1000.0);

        $placement->tick(1000.4);
        $this->assertSame([], $mesh->sent, 'Nothing is re-placed before the failover grace elapses');
        $this->assertSame('node-b', $placement->registry()->get('render:9')?->nodeId);

        $placement->tick(1000.6);
        [$nodeId, $frame] = $mesh->sent[0];
        $this->assertSame('node-c', $nodeId, 'The agent is re-placed onto the surviving capable node');
        $this->assertInstanceOf(PeerPlaceAgentDTO::class, $frame);
        $this->assertSame('node-c', $placement->registry()->get('render:9')?->nodeId);
    }

    public function testAFlappedNodeBackBeforeGraceCancelsItsFailover(): void
    {
        $logFile = $this->captureLog();
        $mesh = new FakePlacementMesh(
            capabilities: ['node-b' => ['gpu', self::SLOTS], 'node-c' => ['gpu', self::SLOTS]],
            linked: ['node-b', 'node-c'],
            online: [self::SELF, 'node-b', 'node-c'],
        );
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['gpu']), null, failoverGraceMs: 500);
        $placement->onBecameLeader();
        $placement->onPlacementReport('node-b', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')]));
        $mesh->sent = [];

        $placement->noteNodeOffline('node-b', 1000.0);
        $placement->noteNodeOnline('node-b', 1000.2);
        $placement->tick(1000.9);

        $this->assertSame([], $mesh->sent, 'A node back before its grace keeps its agents; no re-placement');
        $this->assertSame('node-b', $placement->registry()->get('render:9')?->nodeId);

        $log = (string)file_get_contents($logFile);
        unlink($logFile);
        $this->assertStringContainsString("Failover of 1 agent(s) on 'node-b' called off: the node is back before the grace elapsed", $log);
    }

    public function testAFailoverDeadlineSparesAnAgentAlreadyMovedOffTheLostNode(): void
    {
        $mesh = new FakePlacementMesh(
            capabilities: ['node-b' => ['gpu', self::SLOTS], 'node-c' => ['gpu', self::SLOTS]],
            linked: ['node-b', 'node-c'],
            online: [self::SELF, 'node-b', 'node-c'],
        );
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['gpu']), null, failoverGraceMs: 500);
        $placement->onBecameLeader();
        $placement->onPlacementReport('node-b', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')]));

        // node-b is recreated: it leaves, the fleet's own supervisor restarts the agent on
        // node-c while the grace runs, and node-b comes back with nothing left to cancel —
        // the record the deadline was armed for no longer names it.
        $mesh->online = [self::SELF, 'node-c'];
        $placement->noteNodeOffline('node-b', 1000.0);
        $placement->placeAgentOnNode('render', '9', 'node-c');
        $mesh->online = [self::SELF, 'node-b', 'node-c'];
        $placement->noteNodeOnline('node-b', 1000.3);
        $mesh->sent = [];

        $placement->tick(1000.6);

        $this->assertSame([], $mesh->sent, 'Re-placing onto node-b would start the second copy HIL-696 refuses for good');
        $this->assertSame('node-c', $placement->registry()->get('render:9')?->nodeId);
    }

    public function testFailoverDegradesToUnplacedThenRetriesWhenACapableNodeJoins(): void
    {
        // No capable node besides the dead one: failover has nowhere to go.
        $mesh = new FakePlacementMesh(
            capabilities: ['node-b' => ['gpu', self::SLOTS]],
            linked: ['node-b'],
            online: [self::SELF, 'node-b'],
        );
        $observer = new FakePlacementObserver();
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['gpu']), $observer, failoverGraceMs: 500);
        $placement->onBecameLeader();
        $placement->onPlacementReport('node-b', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')]));

        $mesh->online = [self::SELF];
        $placement->noteNodeOffline('node-b', 1000.0);
        $placement->tick(1000.6);

        $this->assertSame(PlacementState::Unplaced, $placement->registry()->get('render:9')?->state);
        $this->assertSame([['render', '9']], $observer->degraded, 'The degradation is reported to the observer');
        $this->assertSame(
            AgentLocationKind::Unknown,
            $placement->locate('render', '9')->kind,
            'An unplaced agent routes nowhere',
        );

        // A capable node joins: the leader retries the unplaced agent onto it.
        $mesh->capabilities['node-c'] = ['gpu', self::SLOTS];
        $mesh->linked[] = 'node-c';
        $mesh->online = [self::SELF, 'node-c'];
        $placement->noteNodeOnline('node-c', 2000.0);

        $record = $placement->registry()->get('render:9');
        $this->assertSame('node-c', $record?->nodeId, 'The previously-unplaced agent is placed on the newcomer');
        $this->assertNotSame(PlacementState::Unplaced, $record?->state);
    }

    public function testSlaveSelfFencesItsHostedAgentsWhenIsolatedFromThePlacingLeader(): void
    {
        $mesh = new FakePlacementMesh([], linked: ['leader']);
        $executor = new FakePlacementExecutor(workerId: 5);
        $placement = new ClusterPlacement('slave', $mesh, $executor, null, failoverGraceMs: 1000, slaveWorkGraceMs: 500);
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9'));

        $placement->noteNodeOffline('leader', 1000.0);
        $placement->tick(1000.4);
        $this->assertSame([], $executor->revoked, 'The slave keeps working through the grace window');

        $placement->tick(1000.6);
        $this->assertSame([['render', '9']], $executor->revoked, 'The slave stops its placed agents once isolated past the grace');
    }

    /**
     * A placement accepted while the agent waits for a worker is not in the hosted set, and used
     * to leave the node unfenced if the placing leader vanished in those seconds (HIL-1041).
     */
    public function testADeferredPlacementArmsTheSelfFenceWhenThePlacingLeaderIsLost(): void
    {
        $mesh = new FakePlacementMesh([], linked: ['leader']);
        $executor = new FakePlacementExecutor();
        $executor->waitsForWorker = true;
        $placement = new ClusterPlacement('slave', $mesh, $executor, null, failoverGraceMs: 1000, slaveWorkGraceMs: 500);

        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', null));
        $placement->noteNodeOffline('leader', 1000.0);
        $placement->tick(1000.4);
        $this->assertSame([], $executor->revoked, 'The slave keeps the waiting placement through the grace window');

        $placement->tick(1000.6);
        $this->assertSame(
            [['render', null]],
            $executor->revoked,
            'The slave stops a deferred placement once isolated past the grace',
        );

        $mesh->sent = [];
        $executor->seatedWorkerId = -7;
        $placement->noteAgentStarted('render', null);
        $this->assertSame([], $mesh->sent, 'A fenced deferred placement does not answer the lost leader');
    }

    public function testSlaveCancelsSelfFenceWhenThePlacingLeaderReturnsInTime(): void
    {
        $mesh = new FakePlacementMesh([], linked: ['leader']);
        $executor = new FakePlacementExecutor(workerId: 5);
        $placement = new ClusterPlacement('slave', $mesh, $executor, null, slaveWorkGraceMs: 500);
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9'));

        $placement->noteNodeOffline('leader', 1000.0);
        $placement->noteNodeOnline('leader', 1000.2);
        $placement->tick(1000.9);

        $this->assertSame([], $executor->revoked, 'A leader back before the grace leaves the slave running');
    }

    public function testArmingTheSelfFenceIsLoggedWithTheGraceAndTheAgentCount(): void
    {
        $logFile = $this->captureLog();
        $mesh = new FakePlacementMesh([], linked: ['leader']);
        $placement = new ClusterPlacement('slave', $mesh, new FakePlacementExecutor(workerId: 5), null, slaveWorkGraceMs: 500);
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9'));

        $placement->noteNodeOffline('leader', 1000.0);
        $placement->noteNodeOffline('leader', 1000.1);

        $this->assertSame(
            ["Self-fence armed: placing leader 'leader' went offline, 1 placed agent(s) stop in 0.5s unless it returns"],
            $this->selfFenceLines($logFile),
            'The fence is armed once, however often the loss is reported',
        );
    }

    public function testDisarmingTheSelfFenceIsLoggedOnlyWhenItWasArmed(): void
    {
        $logFile = $this->captureLog();
        $mesh = new FakePlacementMesh([], linked: ['leader']);
        $placement = new ClusterPlacement('slave', $mesh, new FakePlacementExecutor(workerId: 5), null, slaveWorkGraceMs: 500);
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9'));

        $placement->noteNodeOnline('leader', 999.0);
        $placement->noteNodeOffline('leader', 1000.0);
        $placement->noteNodeOnline('leader', 1000.2);
        $placement->noteNodeOnline('leader', 1000.3);

        $this->assertSame([
            "Self-fence armed: placing leader 'leader' went offline, 1 placed agent(s) stop in 0.5s unless it returns",
            "Self-fence disarmed: placing leader 'leader' is back before the grace elapsed",
        ], $this->selfFenceLines($logFile), 'A return with no fence armed announces no disarming');
    }

    public function testLeaderReconcilesARejoinReportByStoppingAMovedAgent(): void
    {
        $mesh = new FakePlacementMesh([], linked: ['node-b', 'node-c']);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor());
        $placement->onBecameLeader();
        // The leader already re-placed render:9 onto node-c while node-b was gone.
        $placement->onPlacementReport('node-c', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')]));
        $mesh->sent = [];

        // node-b rejoins reporting it still hosts the moved agent.
        $placement->onPlacementReport('node-b', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')]));

        [$nodeId, $frame] = $mesh->sent[0];
        $this->assertSame('node-b', $nodeId);
        $this->assertInstanceOf(PeerStopAgentDTO::class, $frame, 'The returning node is told to stop the moved agent');
        $this->assertSame('node-c', $placement->registry()->get('render:9')?->nodeId, 'The leader-owned placement is unchanged');
    }

    public function testRejoinReportSendsHostedAgentsAndANonLeaderIgnoresReports(): void
    {
        $mesh = new FakePlacementMesh([], linked: ['leader']);
        $placement = new ClusterPlacement('slave', $mesh, new FakePlacementExecutor(workerId: 5));
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9'));
        $mesh->sent = [];

        // On rejoin the node reports what it still hosts...
        $placement->onPeerHandshaked('leader');
        [$nodeId, $report] = $mesh->sent[0];
        $this->assertSame('leader', $nodeId);
        $this->assertInstanceOf(PeerPlacementReportDTO::class, $report);
        $this->assertSame('render', $report->agents[0]->agentType);

        // ...but a non-leader that receives a report folds nothing into its inert view.
        $placement->onPlacementReport('other', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('ghost', null)]));
        $this->assertNull($placement->registry()->get('ghost'));
    }

    public function testAReturningNodeReportsAnEmptyHostedSetToo(): void
    {
        // A container recreated inside the failover grace comes back hosting nothing at all.
        $mesh = new FakePlacementMesh([], linked: ['leader']);
        $placement = new ClusterPlacement('slave', $mesh, new FakePlacementExecutor(workerId: 5));

        $placement->onPeerHandshaked('leader');

        [$nodeId, $report] = $mesh->sent[0];
        $this->assertSame('leader', $nodeId);
        $this->assertInstanceOf(PeerPlacementReportDTO::class, $report);
        $this->assertSame([], $report->agents, 'An empty hosted set is reported rather than kept quiet');
    }

    public function testTheLeaderRePlacesAnAgentTheReturningNodeNoLongerHosts(): void
    {
        // 'leader' lacks the gpu tag, so the agent can only land back on a data-plane node.
        $mesh = new FakePlacementMesh(
            capabilities: ['node-b' => ['gpu', self::SLOTS], 'node-c' => ['gpu', self::SLOTS]],
            linked: ['node-b', 'node-c'],
            online: [self::SELF, 'node-b', 'node-c'],
        );
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['gpu']));
        $placement->onBecameLeader();
        $placement->onPlacementReport('node-b', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')]));
        $mesh->sent = [];

        // node-b returns as a fresh process and says it hosts nothing.
        $placement->onPlacementReport('node-b', new PeerPlacementReportDTO([]));

        [, $frame] = $mesh->sent[0];
        $this->assertInstanceOf(PeerPlaceAgentDTO::class, $frame, 'The agent nobody hosts is placed again at once');
        $this->assertSame(
            PlacementState::Placing,
            $placement->registry()->get('render:9')?->state,
            'The leader stops calling started an agent the node does not host',
        );
    }

    public function testTheEmptiedNodeGetsItsOwnAgentsBack(): void
    {
        $mesh = new FakePlacementMesh(
            capabilities: ['node-b' => ['gpu', self::SLOTS], 'node-c' => ['gpu', self::SLOTS]],
            linked: ['node-b', 'node-c'],
            online: [self::SELF, 'node-b', 'node-c'],
        );
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['gpu']));
        $placement->onBecameLeader();
        $placement->onPlacementReport('node-b', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')]));
        $placement->onPlacementReport('node-c', new PeerPlacementReportDTO([
            new PeerPlacedAgentEntry('chat', '1'),
            new PeerPlacedAgentEntry('chat', '2'),
        ]));

        $placement->onPlacementReport('node-b', new PeerPlacementReportDTO([]));

        $this->assertSame(
            'node-b',
            $placement->registry()->get('render:9')?->nodeId,
            'The emptied node is the least loaded of the two, so the fleet comes home instead of piling on a neighbour',
        );
    }

    public function testAPlacingRecordSurvivesAReportThatDoesNotNameIt(): void
    {
        $mesh = new FakePlacementMesh(['node-b' => [self::SLOTS]], linked: ['node-b'], online: [self::SELF, 'node-b']);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor());
        $placement->onBecameLeader();
        $placement->placeAgentOnNode('render', '9', 'node-b');
        $mesh->sent = [];

        // The report may well have crossed the place frame on the wire.
        $placement->onPlacementReport('node-b', new PeerPlacementReportDTO([]));

        $record = $placement->registry()->get('render:9');
        $this->assertSame(PlacementState::Placing, $record?->state, 'A placement still in flight is not taken back');
        $this->assertSame('node-b', $record?->nodeId);
        $this->assertSame([], $mesh->sent, 'Nothing is re-placed on account of a frame that has not landed yet');
    }

    public function testARefusedRecordSurvivesAReportThatDoesNotNameIt(): void
    {
        $mesh = new FakePlacementMesh(['node-b' => [self::SLOTS]], linked: ['node-b'], online: [self::SELF, 'node-b']);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor());
        $placement->onBecameLeader();
        $placement->refusePlacement('render', '9', 'node-b');
        $mesh->sent = [];

        $placement->onPlacementReport('node-b', new PeerPlacementReportDTO([]));

        $this->assertSame(
            PlacementState::Refused,
            $placement->registry()->get('render:9')?->state,
            'A refusal is a decision to keep the agent down, not a placement to redo (HIL-696)',
        );
        $this->assertSame([], $mesh->sent);
    }

    public function testAnAgentTheReportStillNamesIsLeftWhereItIs(): void
    {
        $mesh = new FakePlacementMesh(['node-b' => [self::SLOTS]], linked: ['node-b'], online: [self::SELF, 'node-b']);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor());
        $placement->onBecameLeader();
        $placement->onPlacementReport('node-b', new PeerPlacementReportDTO([
            new PeerPlacedAgentEntry('render', '9'),
            new PeerPlacedAgentEntry('chat', '1'),
        ]));
        $mesh->sent = [];

        // A flap that changed nothing: the node comes back hosting exactly what it hosted.
        $placement->onPlacementReport('node-b', new PeerPlacementReportDTO([
            new PeerPlacedAgentEntry('render', '9'),
            new PeerPlacedAgentEntry('chat', '1'),
        ]));

        $this->assertSame([], $mesh->sent, 'A node that still hosts its agents is left alone');
        $this->assertSame('node-b', $placement->registry()->get('render:9')?->nodeId);
        $this->assertSame(PlacementState::Started, $placement->registry()->get('chat:1')?->state);
    }

    public function testPlaceAgentOnBestNodePicksTheStrongestCapableNodeAndPlaces(): void
    {
        // Both data-plane nodes are capable; the stronger one (more cpu) should win the pick.
        $mesh = new FakePlacementMesh(
            capabilities: ['node-b' => ['worker', 'cpu=2'], 'node-c' => ['worker', 'cpu=8']],
            linked: ['node-b', 'node-c'],
            online: [self::SELF, 'node-b', 'node-c'],
        );
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['worker']));

        $target = $placement->placeAgentOnBestNode('render', null);

        $this->assertSame('node-c', $target, 'Best-fit places on the strongest capable node');
        [$nodeId, $frame] = $mesh->sent[0];
        $this->assertSame('node-c', $nodeId);
        $this->assertInstanceOf(PeerPlaceAgentDTO::class, $frame);
        $this->assertSame('node-c', $placement->registry()->get('render')?->nodeId);
    }

    public function testAFleetOfEqualAgentsSpreadsOverTheCapableNodes(): void
    {
        // Equally capable nodes: each new member must go where fewer members already run,
        // otherwise a fleet piles onto whichever node won the first pick.
        $mesh = new FakePlacementMesh(
            capabilities: ['node-b' => ['worker', 'cpu=4'], 'node-c' => ['worker', 'cpu=4']],
            linked: ['node-b', 'node-c'],
            online: [self::SELF, 'node-b', 'node-c'],
        );
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['worker']));

        $targets = [];
        for ($index = 0; $index < 4; $index++) {
            $targets[] = $placement->placeAgentOnBestNode('render', (string)$index);
        }

        $this->assertSame(2, count(array_filter($targets, static fn(?string $n): bool => $n === 'node-b')));
        $this->assertSame(2, count(array_filter($targets, static fn(?string $n): bool => $n === 'node-c')));
    }

    public function testPlaceAgentOnBestNodeReturnsNullWhenNoNodeIsAFit(): void
    {
        $mesh = new FakePlacementMesh(
            capabilities: ['node-b' => ['worker', self::SLOTS]],
            linked: ['node-b'],
            online: [self::SELF, 'node-b'],
        );
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['gpu']));

        $this->assertNull($placement->placeAgentOnBestNode('render', null), 'No gpu node is online, so nothing is placed');
        $this->assertSame([], $mesh->sent, 'Nothing is sent when no node clears the hard gate');
        $this->assertSame(0, $placement->registry()->count());
    }

    public function testHeldCapacitySendsTheNextPlacementElsewhereUntilNoNodeHasRoom(): void
    {
        $mesh = new FakePlacementMesh(
            capabilities: ['node-b' => ['worker', 'ram=4'], 'node-c' => ['worker', 'ram=4']],
            linked: ['node-b', 'node-c'],
            online: [self::SELF, 'node-b', 'node-c'],
        );
        $cost = ResourceProfile::costs(['ram' => 3.0]);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['worker'], profile: $cost));

        $this->assertSame('node-b', $placement->placeAgentOnBestNode('render', '1'));
        $this->assertSame('node-c', $placement->placeAgentOnBestNode('render', '2'), 'node-b has 1 of 4 free, the cost is 3');
        $this->assertNull($placement->placeAgentOnBestNode('render', '3'), 'Both nodes are held full: no candidate');
    }

    public function testStoppingAnAgentFreesItsCapacity(): void
    {
        $mesh = new FakePlacementMesh(['node-b' => ['ram=3']], linked: ['node-b'], online: [self::SELF, 'node-b']);
        $cost = ResourceProfile::costs(['ram' => 3.0]);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(profile: $cost));
        $placement->placeAgentOnNode('render', '1', 'node-b');
        $this->assertNull($placement->placeAgentOnBestNode('render', '2'));

        $placement->stopAgentOnNode('render', '1', 'node-b');

        $this->assertSame('node-b', $placement->placeAgentOnBestNode('render', '2'), 'The stop released the reservation');
    }

    public function testAnAgentDegradedToUnplacedHoldsNothing(): void
    {
        $mesh = new FakePlacementMesh(['node-b' => ['ram=3']], linked: ['node-b'], online: [self::SELF, 'node-b']);
        $cost = ResourceProfile::costs(['ram' => 3.0]);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(profile: $cost), null, failoverGraceMs: 500);
        $placement->onBecameLeader();
        $placement->onPlacementReport('node-b', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '1')]));

        $mesh->online = [self::SELF];
        $placement->noteNodeOffline('node-b', 1000.0);
        $placement->tick(1000.6);
        $this->assertSame(PlacementState::Unplaced, $placement->registry()->get('render:1')?->state);

        // node-b is back in the online set without the join that would retry render:1 onto it.
        $mesh->online = [self::SELF, 'node-b'];

        $this->assertSame('node-b', $placement->placeAgentOnBestNode('chat', '1'), 'The unplaced record still names node-b but holds nothing');
    }

    public function testAnAgentsOwnRecordDoesNotBlockItsRePlacement(): void
    {
        $mesh = new FakePlacementMesh(['node-b' => ['ram=3']], linked: ['node-b'], online: [self::SELF, 'node-b']);
        $cost = ResourceProfile::costs(['ram' => 3.0]);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(profile: $cost));
        $placement->placeAgentOnNode('render', '1', 'node-b');
        $mesh->sent = [];

        $placement->placeAgentOnNode('render', '1', 'node-b');

        $this->assertCount(1, $mesh->sent, 'The agent\'s old reservation does not count against itself');
    }

    public function testANamedPlacementOntoANodeWithoutDeclaredCapacityIsRefused(): void
    {
        $mesh = new FakePlacementMesh(['bare' => ['worker']], linked: ['bare'], online: [self::SELF, 'bare']);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['worker']));

        try {
            $placement->placeAgentOnNode('render', '1', 'bare');
            $this->fail('A node that declares no capacity takes no placed work, even work that costs nothing');
        } catch (PlacementCapabilityException $e) {
            $this->assertSame(
                "Cannot place agent 'render:1' on node 'bare': the node declares no capacity, so it accepts no placed work",
                $e->getMessage(),
            );
        }

        $this->assertSame([], $mesh->sent);
        $this->assertSame(0, $placement->registry()->count());
    }

    public function testANamedPlacementBeyondTheFreeCapacityIsRefused(): void
    {
        $mesh = new FakePlacementMesh(['node-b' => ['ram=5']], linked: ['node-b'], online: [self::SELF, 'node-b']);
        $cost = ResourceProfile::costs(['ram' => 3.0]);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(profile: $cost));
        $placement->placeAgentOnNode('render', '1', 'node-b');
        $mesh->sent = [];

        try {
            $placement->placeAgentOnNode('render', '2', 'node-b');
            $this->fail('A placement by name must not overfill the node past the accounting');
        } catch (PlacementCapabilityException $e) {
            $this->assertSame(
                "Cannot place agent 'render:2' on node 'node-b': insufficient free capacity [ram: needs 3, free 2]",
                $e->getMessage(),
            );
        }

        $this->assertSame([], $mesh->sent);
        $this->assertNull($placement->registry()->get('render:2'));
    }

    public function testANewLeaderCountsTheCostOfReportedAgents(): void
    {
        // A head count would pick node-b (2 agents against 3); the reported costs leave it full.
        $mesh = new FakePlacementMesh(
            capabilities: ['node-b' => ['ram=4'], 'node-c' => ['ram=10']],
            linked: ['node-b', 'node-c'],
            online: [self::SELF, 'node-b', 'node-c'],
        );
        $cost = ResourceProfile::costs(['ram' => 2.0]);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(profile: $cost));
        $placement->onBecameLeader();
        $placement->onPlacementReport('node-b', new PeerPlacementReportDTO([
            new PeerPlacedAgentEntry('render', '1'),
            new PeerPlacedAgentEntry('render', '2'),
        ]));
        $placement->onPlacementReport('node-c', new PeerPlacementReportDTO([
            new PeerPlacedAgentEntry('render', '3'),
            new PeerPlacedAgentEntry('render', '4'),
            new PeerPlacedAgentEntry('render', '5'),
        ]));

        $this->assertSame('node-c', $placement->placeAgentOnBestNode('render', '6'));
    }

    public function testFailoverReplacesOntoTheStrongestSurvivingCapableNode(): void
    {
        $mesh = new FakePlacementMesh(
            capabilities: ['node-b' => ['worker', 'cpu=1'], 'node-c' => ['worker', 'cpu=2'], 'node-d' => ['worker', 'cpu=9']],
            linked: ['node-b', 'node-c', 'node-d'],
            online: [self::SELF, 'node-b', 'node-c', 'node-d'],
        );
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['worker']), null, failoverGraceMs: 500);
        $placement->onBecameLeader();
        $placement->onPlacementReport('node-b', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')]));
        $mesh->sent = [];

        // node-b dies; two survivors are capable, so best-fit takes the stronger of them.
        $mesh->online = [self::SELF, 'node-c', 'node-d'];
        $placement->noteNodeOffline('node-b', 1000.0);
        $placement->tick(1000.6);

        $this->assertSame('node-d', $mesh->sent[0][0], 'Failover re-places onto the strongest surviving capable node');
        $this->assertSame('node-d', $placement->registry()->get('render:9')?->nodeId);
    }

    /**
     * A claim refused because another node already owns the collection takes the agent down over
     * the ordinary stop frame, and leaves a record behind saying it must not come back (HIL-696).
     */
    public function testARefusedClaimStopsTheAgentAndKeepsTheRecord(): void
    {
        $mesh = new FakePlacementMesh([self::SELF => [self::SLOTS], 'gpu-node' => [self::SLOTS]], ['gpu-node'], [self::SELF, 'gpu-node']);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor());
        $placement->onBecameLeader();
        $placement->placeAgentOnNode('chat', '1', 'gpu-node');
        $mesh->sent = [];

        $placement->refusePlacement('chat', '1', 'gpu-node');

        $this->assertInstanceOf(PeerStopAgentDTO::class, $mesh->sent[0][1], 'The stop is the frame that already exists');
        $this->assertSame(
            PlacementState::Refused,
            $placement->registry()->get('chat:1')?->state,
            'A forgotten placement would be put back by the next reconciliation pass',
        );
    }

    public function testFailoverDoesNotResurrectARefusedAgent(): void
    {
        $mesh = new FakePlacementMesh([self::SELF => [self::SLOTS], 'gpu-node' => [self::SLOTS]], ['gpu-node'], [self::SELF, 'gpu-node']);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor());
        $placement->onBecameLeader();
        $placement->placeAgentOnNode('chat', '1', 'gpu-node');
        $placement->refusePlacement('chat', '1', 'gpu-node');
        $mesh->sent = [];

        $placement->noteNodeOffline('gpu-node', 1000.0);
        $placement->tick(2000.0);

        $this->assertSame([], $mesh->sent, 'Re-placing the loser elsewhere would only move the split');
        $this->assertSame(PlacementState::Refused, $placement->registry()->get('chat:1')?->state);
    }

    public function testTheStoppedStatusConfirmingTheRefusalDoesNotUndoIt(): void
    {
        $mesh = new FakePlacementMesh([self::SELF => [self::SLOTS], 'gpu-node' => [self::SLOTS]], ['gpu-node'], [self::SELF, 'gpu-node']);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor());
        $placement->onBecameLeader();
        $placement->placeAgentOnNode('chat', '1', 'gpu-node');
        $placement->refusePlacement('chat', '1', 'gpu-node');

        $placement->onAgentStatus('gpu-node', PeerAgentStatusDTO::stopped('chat', '1'));

        $this->assertSame(
            PlacementState::Refused,
            $placement->registry()->get('chat:1')?->state,
            'The frame carrying the refusal out must not be the frame that undoes it',
        );
    }

    public function testANodeStillHostingARefusedAgentIsToldToStopItAgain(): void
    {
        $mesh = new FakePlacementMesh([self::SELF => [self::SLOTS], 'gpu-node' => [self::SLOTS]], ['gpu-node'], [self::SELF, 'gpu-node']);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor());
        $placement->onBecameLeader();
        $placement->placeAgentOnNode('chat', '1', 'gpu-node');
        $placement->refusePlacement('chat', '1', 'gpu-node');
        $mesh->sent = [];

        $placement->onPlacementReport('gpu-node', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('chat', '1')]));

        $this->assertInstanceOf(PeerStopAgentDTO::class, $mesh->sent[0][1] ?? null);
        $this->assertSame(
            PlacementState::Refused,
            $placement->registry()->get('chat:1')?->state,
            'Re-adopting the report would bring the split back with the agent',
        );
    }

    public function testARefusedAgentIsAddressedNowhereAndPublishedNowhere(): void
    {
        $mesh = new FakePlacementMesh([self::SELF => [self::SLOTS], 'gpu-node' => [self::SLOTS]], ['gpu-node'], [self::SELF, 'gpu-node']);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor());
        $placement->onBecameLeader();
        $placement->placeAgentOnNode('chat', '1', 'gpu-node');
        $placement->refusePlacement('chat', '1', 'gpu-node');
        $mesh->broadcast = [];

        $placement->tick(1000.0);

        $this->assertSame(
            AgentLocationKind::Unknown,
            $placement->locate('chat', '1')->kind,
            'An agent nothing hosts has no node to forward to',
        );
        $view = $mesh->broadcast[0] ?? null;
        $this->assertInstanceOf(PeerPlacementViewDTO::class, $view);
        $this->assertSame([], $view->agents, 'A refused agent is left out of the published picture');
    }

    /**
     * A node that took the agent and failed is not a host. Failed used to read as an address
     * because it is neither unplaced nor refused, so a frame went to the node that had just
     * said it could not start it (HIL-1041).
     */
    public function testAFailedAgentIsAddressedNowhereAndPublishedNowhere(): void
    {
        $mesh = new FakePlacementMesh(
            [self::SELF => [self::SLOTS], 'gpu-node' => [self::SLOTS]],
            ['gpu-node'],
            [self::SELF, 'gpu-node'],
        );
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor());
        $placement->onBecameLeader();
        $placement->onAgentStatus('gpu-node', PeerAgentStatusDTO::started('chat', '1', 1));
        $placement->tick(1000.0);
        $mesh->broadcast = [];

        $placement->registry()->put(new PlacementRecord('chat', '1', 'gpu-node', PlacementState::Failed));
        $placement->tick(1001.0);

        $this->assertSame(
            AgentLocationKind::Unknown,
            $placement->locate('chat', '1')->kind,
            'A node that took the agent and failed is not a host to forward to',
        );
        $view = $mesh->broadcast[0] ?? null;
        $this->assertInstanceOf(PeerPlacementViewDTO::class, $view);
        $this->assertSame([], $view->agents, 'A failed placement is left out of the published picture');
    }

    /**
     * A placement whose acknowledgement never came asks the node what it hosts, rather than
     * re-placing the agent on a guess (HIL-930). Both data-plane nodes are capable and the
     * leader is not, so a re-placement WOULD have somewhere to go — the absence of one is a
     * decision, not a dead end.
     */
    public function testAnExpiredPlacementAskAsksTheNodeInsteadOfRePlacing(): void
    {
        $mesh = new FakePlacementMesh(
            capabilities: ['node-b' => ['gpu', self::SLOTS], 'node-c' => ['gpu', self::SLOTS]],
            linked: ['node-b', 'node-c'],
            online: [self::SELF, 'node-b', 'node-c'],
        );
        $placement = new ClusterPlacement(
            self::SELF,
            $mesh,
            new FakePlacementExecutor(['gpu']),
            placementAckTimeoutMs: 500,
        );
        $placement->onBecameLeader();
        $placement->placeAgentOnNode('render', '9', 'node-b');
        $placement->tick(1000.0);
        $mesh->sent = [];

        $placement->tick(1000.6);

        $this->assertCount(1, $mesh->sent, 'One question per record, and nothing else');
        [$askedNode, $frame] = $mesh->sent[0];
        $this->assertSame('node-b', $askedNode, 'The node the record names is the one that knows');
        $this->assertInstanceOf(PeerPlacementQueryDTO::class, $frame, 'The timeout fires a question, not an action');
        $record = $placement->registry()->get('render:9');
        $this->assertSame(PlacementState::Placing, $record?->state, 'Asking does not decide anything by itself');
        $this->assertSame('node-b', $record?->nodeId);

        $mesh->sent = [];
        $placement->tick(1001.2);

        $this->assertSame([], $mesh->sent, 'A node that answers nothing has a dead link, and that is failover, not a retry');
    }

    public function testAReportNamingTheAgentEndsTheWaitAsStarted(): void
    {
        $mesh = new FakePlacementMesh(
            capabilities: ['node-b' => ['gpu', self::SLOTS], 'node-c' => ['gpu', self::SLOTS]],
            linked: ['node-b', 'node-c'],
            online: [self::SELF, 'node-b', 'node-c'],
        );
        $placement = new ClusterPlacement(
            self::SELF,
            $mesh,
            new FakePlacementExecutor(['gpu']),
            placementAckTimeoutMs: 500,
        );
        $placement->onBecameLeader();
        $placement->placeAgentOnNode('render', '9', 'node-b');
        $placement->tick(1000.0);
        $placement->tick(1000.6);
        $mesh->sent = [];

        $placement->onPlacementReport('node-b', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')]));

        $record = $placement->registry()->get('render:9');
        $this->assertSame(PlacementState::Started, $record?->state, 'The node holds it, so the status frame was merely lost');
        $this->assertSame('node-b', $record?->nodeId);
        $this->assertSame([], $mesh->sent, 'An agent found where it was put is left where it is');
    }

    public function testAReportWithoutTheAgentRePlacesTheTimedOutPlacing(): void
    {
        $mesh = new FakePlacementMesh(
            capabilities: ['node-b' => ['gpu', self::SLOTS], 'node-c' => ['gpu', self::SLOTS]],
            linked: ['node-b', 'node-c'],
            online: [self::SELF, 'node-b', 'node-c'],
        );
        $placement = new ClusterPlacement(
            self::SELF,
            $mesh,
            new FakePlacementExecutor(['gpu']),
            placementAckTimeoutMs: 500,
        );
        $placement->onBecameLeader();
        $placement->placeAgentOnNode('render', '9', 'node-b');
        $placement->tick(1000.0);
        $placement->tick(1000.6);
        $mesh->sent = [];

        // The answer to the question: the node hosts nothing, so the place frame is not in
        // flight, it is lost.
        $placement->onPlacementReport('node-b', new PeerPlacementReportDTO([]));

        $this->assertInstanceOf(PeerPlaceAgentDTO::class, $mesh->sent[0][1] ?? null, 'An asked Placing is judged like a Started one');
        $this->assertSame(PlacementState::Placing, $placement->registry()->get('render:9')?->state);
    }

    public function testALateStartedFromANodeTheRecordLeftIsStoppedAndIgnored(): void
    {
        $mesh = new FakePlacementMesh(
            capabilities: ['node-b' => ['gpu', self::SLOTS], 'node-c' => ['gpu', self::SLOTS]],
            linked: ['node-b', 'node-c'],
            online: [self::SELF, 'node-b', 'node-c'],
        );
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['gpu']));
        $placement->onBecameLeader();
        $placement->placeAgentOnNode('render', '9', 'node-c');
        $mesh->sent = [];

        // node-b answers for a placement the leader has since moved to node-c.
        $placement->onAgentStatus('node-b', PeerAgentStatusDTO::started('render', '9', 5));

        $this->assertSame(['node-b'], array_column($mesh->sent, 0));
        $this->assertInstanceOf(PeerStopAgentDTO::class, $mesh->sent[0][1] ?? null, 'The second copy is taken down, as a report would');
        $record = $placement->registry()->get('render:9');
        $this->assertSame('node-c', $record?->nodeId, 'Writing the record by sender would point it back at the node it left');
        $this->assertSame(PlacementState::Placing, $record?->state);
    }

    public function testALateStoppedFromANodeTheRecordLeftDoesNotForgetIt(): void
    {
        $mesh = new FakePlacementMesh(
            capabilities: ['node-b' => ['gpu', self::SLOTS], 'node-c' => ['gpu', self::SLOTS]],
            linked: ['node-b', 'node-c'],
            online: [self::SELF, 'node-b', 'node-c'],
        );
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['gpu']));
        $placement->onBecameLeader();
        $placement->placeAgentOnNode('render', '9', 'node-c');
        $mesh->sent = [];

        $placement->onAgentStatus('node-b', PeerAgentStatusDTO::stopped('render', '9'));

        $record = $placement->registry()->get('render:9');
        $this->assertSame('node-c', $record?->nodeId, 'A stop from the node it left must not erase a record naming another');
        $this->assertSame(PlacementState::Placing, $record?->state);
        $this->assertSame([], $mesh->sent, 'onStopAgent() answers stopped unconditionally, so a stop back at one loops the pair');
    }

    /**
     * Points the main log at a fresh temporary file; tearDown points it back.
     *
     * @return string Path of the temporary log file
     */
    private function captureLog(): string
    {
        $logFile = (string)tempnam(sys_get_temp_dir(), 'hilos-self-fence-log');
        Logger::setLogFile($logFile);

        return $logFile;
    }

    /**
     * Reads the self-fence lines back from a captured log, without their timestamps, and removes the file.
     *
     * @param string $logFile Path returned by {@see captureLog()}
     * @return list<string> Messages of the self-fence lines, in the order they were written
     */
    private function selfFenceLines(string $logFile): array
    {
        $lines = file($logFile, FILE_IGNORE_NEW_LINES);
        unlink($logFile);
        $this->assertIsArray($lines, 'The captured log is readable');

        $messages = [];
        foreach ($lines as $line) {
            $message = (string)preg_replace('/^\[[^\]]+\] /', '', $line);
            if (str_starts_with($message, 'Self-fence')) {
                $messages[] = $message;
            }
        }

        return $messages;
    }
}

/**
 * Fake mesh that records sent and broadcast frames and answers capability and online-set
 * lookups from mutable maps, so the coordinator runs without a peer socket. The maps are
 * public so a failover test can flip a node online/offline or add a capability mid-run.
 */
final class FakePlacementMesh implements PlacementMesh
{
    /** @var list<array{0: string, 1: PeerDTO}> Node-addressed frames, as [nodeId, frame] */
    public array $sent = [];

    /** @var list<PeerDTO> Broadcast frames */
    public array $broadcast = [];

    /** @var array<string, list<string>> Advertised capabilities keyed by node id */
    public array $capabilities;

    /** @var list<string> Node ids a link exists to (sendToNode succeeds) */
    public array $linked;

    /** @var list<string> Currently-online node ids */
    public array $online;

    /**
     * @param array<string, list<string>> $capabilities Advertised capabilities keyed by node id
     * @param list<string> $linked Node ids a link exists to (sendToNode succeeds)
     * @param list<string> $online Currently-online node ids
     */
    public function __construct(array $capabilities, array $linked = [], array $online = [])
    {
        $this->capabilities = $capabilities;
        $this->linked = $linked;
        $this->online = $online;
    }

    public function sendToNode(string $nodeId, PeerDTO $frame): bool
    {
        $this->sent[] = [$nodeId, $frame];

        return in_array($nodeId, $this->linked, true);
    }

    public function broadcastToNodes(PeerDTO $frame): void
    {
        $this->broadcast[] = $frame;
    }

    public function nodeCapabilities(string $nodeId): ?array
    {
        return $this->capabilities[$nodeId] ?? null;
    }

    public function onlineNodeIds(): array
    {
        return $this->online;
    }
}

/**
 * Fake placement observer that records the agents failover degraded to unplaced.
 */
final class FakePlacementObserver implements PlacementObserver
{
    /** @var list<array{0: string, 1: ?string}> Degraded agents, as [type, index] */
    public array $degraded = [];

    public function onPlacementDegraded(string $agentType, ?string $agentIndex): void
    {
        $this->degraded[] = [$agentType, $agentIndex];
    }
}

/**
 * Fake executor that records placements and revokes, and can be told to fail or to report
 * a fixed required-capability set and worker id.
 */
final class FakePlacementExecutor implements PlacementExecutor
{
    /** @var list<array{0: string, 1: ?string}> Executed placements, as [type, index] */
    public array $executed = [];

    /** @var list<array{0: string, 1: ?string}> Revoked placements, as [type, index] */
    public array $revoked = [];

    /** @var ?Throwable Exception the next executePlacement() should throw, or null to succeed */
    public ?Throwable $failWith = null;

    /** @var bool Whether a placement is accepted while a worker is raised for the agent (HIL-998) */
    public bool $waitsForWorker = false;

    /** @var ?int Worker a waiting agent was seated on, or null while it still waits */
    public ?int $seatedWorkerId = null;

    /** @var ResourceProfile Resource profile every agent type reports */
    private readonly ResourceProfile $profile;

    /**
     * @param list<string> $required Required capabilities every agent type reports
     * @param int $workerId Worker id a successful placement lands on
     * @param ?ResourceProfile $profile Resource profile every agent type reports; empty when null
     */
    public function __construct(
        private readonly array $required = [],
        private readonly int $workerId = 1,
        ?ResourceProfile $profile = null,
    ) {
        $this->profile = $profile ?? ResourceProfile::none();
    }

    public function requiredCapabilities(string $agentType, ?string $agentIndex): array
    {
        return $this->required;
    }

    public function placementProfile(string $agentType, ?string $agentIndex): ResourceProfile
    {
        return $this->profile;
    }

    public function executePlacement(string $agentType, ?string $agentIndex): ?int
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        $this->executed[] = [$agentType, $agentIndex];

        return $this->waitsForWorker ? null : $this->workerId;
    }

    public function placedWorkerId(string $agentType, ?string $agentIndex): ?int
    {
        return $this->waitsForWorker ? $this->seatedWorkerId : $this->workerId;
    }

    public function revokePlacement(string $agentType, ?string $agentIndex): void
    {
        $this->revoked[] = [$agentType, $agentIndex];
    }
}

/**
 * Project facade declaring the two agent types these cases place as policy-placed.
 *
 * Abstract because only its registry constant is read: nothing here builds a database.
 */
abstract class PlacementTestHilos extends Hilos
{
    public const array AGENTS = [
        'render' => [
            AgentRegistryKey::INDEXED => true,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        'chat' => [
            AgentRegistryKey::INDEXED => true,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
    ];
}

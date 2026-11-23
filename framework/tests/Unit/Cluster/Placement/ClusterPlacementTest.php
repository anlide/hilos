<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Placement;

use Hilos\Cluster\Placement\PlacementId;
use Hilos\Core\Agent\Daemon\AgentStartSink;
use Closure;
use Hilos\Constants\TimeConstants;
use Hilos\Cluster\Exception\PlacementCapabilityException;
use Hilos\Cluster\Peer\DTO\PeerAgentStatusDTO;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerPlaceAgentDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacedAgentEntry;
use Hilos\Cluster\Peer\DTO\PeerPlacementQueryDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementReleaseDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementReportDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementRequestDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementVerdictDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementViewDTO;
use Hilos\Cluster\Peer\DTO\PeerStopAgentDTO;
use Hilos\Cluster\Placement\AgentLocationKind;
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Cluster\Placement\PlacementExecutor;
use Hilos\Cluster\Placement\PlacementMesh;
use Hilos\Cluster\Placement\PlacementObserver;
use Hilos\Cluster\Placement\PlacementPolicy;
use Hilos\Cluster\Placement\PlacementRecord;
use Hilos\Cluster\Placement\PlacementSnapshot;
use Hilos\Cluster\Placement\PlacementRefusal;
use Hilos\Cluster\Placement\PlacementRefusalCode;
use Hilos\Cluster\Placement\PlacementState;
use Hilos\Cluster\Placement\ResourceProfile;
use Hilos\Constants\AgentConstants;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Exception\NoSuitableWorkerException;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\Exception\EventLoopException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Hilos;
use Hilos\Utils\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @var string Capacity tag every node that must stay a candidate declares (HIL-448), and
     *     every node that receives a place frame declares for its own gate (HIL-447): the slot
     *     each placed agent takes
     */
    private const string SLOTS = 'slots=10';

    /** Mirrors WorkerServer's private limit on the wait for a worker to connect (HIL-1671). */
    private const float WORKER_CONNECT_WAIT_SECONDS = 10.0;

    /** A regular worker's id: positive, where a monopolistic one is negative. */
    private const int REGULAR_WORKER_ID = 4;

    /** @var class-string<Hilos> App class bound before this test touched it */
    private string $boundAppClass;

    /** @return iterable<string, array{PlacementState, ?string}> No-host records with and without node history */
    public static function recordsWithoutHosts(): iterable
    {
        foreach ([PlacementState::Failed, PlacementState::Unplaced, PlacementState::Stopped] as $state) {
            yield $state->value . ' without node' => [$state, null];
            yield $state->value . ' with previous node' => [$state, 'gpu-node'];
        }
    }

    /**
     * @return iterable<string, array{int}> Worker a waiting agent is seated on, by the type it waited for
     *     (negative = monopolistic, positive = regular, HIL-1671)
     */
    public static function awaitedWorkers(): iterable
    {
        yield 'monopolistic worker raised for the agent' => [-7];
        yield 'regular worker launched and not yet connected' => [self::REGULAR_WORKER_ID];
    }

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
        $this->assertSame(1, $placement->registry()->count());
        $this->assertSame(PlacementState::Unplaced, $placement->registry()->get('render')?->state);
        $this->assertNull($placement->registry()->get('render')?->nodeId);
    }

    public function testNodeExecutesPlaceFrameAndRepliesStarted(): void
    {
        $mesh = new FakePlacementMesh(['gpu-node' => [self::SLOTS]], linked: ['leader']);
        $executor = new FakePlacementExecutor(workerId: 5);
        $placement = new ClusterPlacement('gpu-node', $mesh, $executor);

        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9', new PlacementId('leader', 0, 1)));

        $this->assertSame([['render', '9']], $executor->executed);
        [$nodeId, $frame] = $mesh->sent[0];
        $this->assertSame('leader', $nodeId, 'The status reply goes back to the requesting leader');
        $this->assertInstanceOf(PeerAgentStatusDTO::class, $frame);
        $this->assertSame(PlacementState::Started, $frame->state);
        $this->assertSame(5, $frame->workerId);
    }

    /**
     * A placement accepted while the agent waits for a worker of its type to connect is answered
     * once the agent is seated, with the worker it landed on, and only then (HIL-998, HIL-1671).
     * The start fact is what answers it: a tick does not, and the acceptance is not a start.
     *
     * @param int $seatedWorkerId Worker the agent is seated on
     */
    #[DataProvider('awaitedWorkers')]
    public function testNodeDefersTheStartedReplyUntilTheWaitingAgentIsSeated(int $seatedWorkerId): void
    {
        $mesh = new FakePlacementMesh(['gpu-node' => [self::SLOTS]], linked: ['leader']);
        $executor = new FakePlacementExecutor();
        $executor->waitsForWorker = true;
        $placement = new ClusterPlacement('gpu-node', $mesh, $executor);

        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', null, new PlacementId('leader', 0, 1)));
        $placement->tick((hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND));

        $this->assertSame([], $mesh->sent, 'Nothing is answered while the agent waits for its worker');
        $placement->onPlacementQuery('leader', new PeerPlacementQueryDTO('query', 0));
        $report = $mesh->sent[0][1];
        $this->assertInstanceOf(PeerPlacementReportDTO::class, $report);
        $this->assertCount(1, $report->agents);
        $this->assertSame(PlacementState::Placing, $report->agents[0]->state);
        $this->assertFalse($report->agents[0]->workerStarted);
        $this->assertEquals($placement->localPlacementId('render'), $report->agents[0]->placementId);
        $mesh->sent = [];

        $executor->seatedWorkerId = $seatedWorkerId;
        $placement->noteAgentStarted('render', null, placementId: $placement->localPlacementId('render'));
        $placement->noteAgentStarted('render', null, placementId: $placement->localPlacementId('render'));

        $this->assertCount(1, $mesh->sent);
        [$nodeId, $frame] = $mesh->sent[0];
        $this->assertSame('leader', $nodeId);
        $this->assertInstanceOf(PeerAgentStatusDTO::class, $frame);
        $this->assertSame(PlacementState::Started, $frame->state);
        $this->assertSame($seatedWorkerId, $frame->workerId);
    }

    /**
     * A deferred placement whose start does not finish is answered failed with that reason, and
     * a clock elapsing first does not answer it (HIL-1041), whichever worker type it waited for.
     * The failure is its one answer: a start reported after it adds none.
     *
     * @param int $seatedWorkerId Worker a late start would report the agent seated on
     */
    #[DataProvider('awaitedWorkers')]
    public function testADeferredPlacementIsAnsweredFailedWhenTheStartFails(int $seatedWorkerId): void
    {
        $mesh = new FakePlacementMesh(['gpu-node' => [self::SLOTS]], linked: ['leader']);
        $executor = new FakePlacementExecutor();
        $executor->waitsForWorker = true;
        $placement = new ClusterPlacement('gpu-node', $mesh, $executor);

        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', null, new PlacementId('leader', 0, 1)));
        $placement->tick((hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND) + 60.0);

        $this->assertSame([], $mesh->sent, 'A deferred placement is not answered by a clock');

        $placement->noteAgentStartFailed('render', null, 'its worker never came up', placementId: $placement->localPlacementId('render'));
        $executor->seatedWorkerId = $seatedWorkerId;
        $placement->noteAgentStarted('render', null, placementId: $placement->localPlacementId('render'));

        $this->assertCount(1, $mesh->sent);
        $frame = $mesh->sent[0][1];
        $this->assertInstanceOf(PeerAgentStatusDTO::class, $frame);
        $this->assertSame(PlacementState::Failed, $frame->state);
        $this->assertSame('its worker never came up', $frame->error);
    }

    /**
     * @param int $seatedWorkerId Worker a late start would report the agent seated on
     */
    #[DataProvider('awaitedWorkers')]
    public function testAStopAnswersADeferredPlacementAndNothingFollowsIt(int $seatedWorkerId): void
    {
        $mesh = new FakePlacementMesh(['gpu-node' => [self::SLOTS]], linked: ['leader']);
        $executor = new FakePlacementExecutor();
        $executor->waitsForWorker = true;
        $placement = new ClusterPlacement('gpu-node', $mesh, $executor);

        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', null, new PlacementId('leader', 0, 1)));
        $placement->onStopAgent('leader', new PeerStopAgentDTO('render', null, 0, 'stop', $placement->localPlacementId('render')));
        $placement->noteAgentStartFailed(
            'render', null, 'it was stopped while waiting for its worker to connect', placementId: $placement->localPlacementId('render'),
        );
        $executor->seatedWorkerId = $seatedWorkerId;
        $placement->noteAgentStarted('render', null, placementId: $placement->localPlacementId('render'));

        $this->assertCount(1, $mesh->sent);
        $frame = $mesh->sent[0][1];
        $this->assertInstanceOf(PeerAgentStatusDTO::class, $frame);
        $this->assertSame(PlacementState::Stopped, $frame->state);
    }

    /**
     * @param int $seatedWorkerId Worker the agent is seated on
     */
    #[DataProvider('awaitedWorkers')]
    public function testALocalPlacementWaitingForAWorkerIsPlacingUntilSeated(int $seatedWorkerId): void
    {
        $mesh = new FakePlacementMesh([self::SELF => [self::SLOTS]]);
        $executor = new FakePlacementExecutor();
        $executor->waitsForWorker = true;
        $placement = new ClusterPlacement(self::SELF, $mesh, $executor);
        $placement->onBecameLeader(1000.0);

        $placement->placeAgentOnNode('chat', null, self::SELF);

        $this->assertSame(PlacementState::Placing, $placement->registry()->get('chat')?->state);

        $executor->seatedWorkerId = $seatedWorkerId;
        $placement->noteAgentStarted('chat', null, placementId: $placement->localPlacementId('chat'));

        $this->assertSame(PlacementState::Started, $placement->registry()->get('chat')?->state);
        $this->assertSame([], $mesh->sent, 'A local placement sends no frame');
    }

    /**
     * A local placement whose start does not finish is failed with that reason, and a clock
     * elapsing first leaves it placing (HIL-1041), whichever worker type it waited for. A start
     * reported after the failure does not turn it Started.
     *
     * @param int $seatedWorkerId Worker a late start would report the agent seated on
     */
    #[DataProvider('awaitedWorkers')]
    public function testALocalPlacementIsFailedWhenTheStartFails(int $seatedWorkerId): void
    {
        $mesh = new FakePlacementMesh([self::SELF => [self::SLOTS]]);
        $executor = new FakePlacementExecutor();
        $executor->waitsForWorker = true;
        $placement = new ClusterPlacement(self::SELF, $mesh, $executor);
        $placement->onBecameLeader(1000.0);

        $placement->placeAgentOnNode('chat', null, self::SELF);
        $placement->tick((hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND) + 60.0);

        $this->assertSame(PlacementState::Placing, $placement->registry()->get('chat')?->state);

        $placement->noteAgentStartFailed('chat', null, 'its worker never came up', placementId: $placement->localPlacementId('chat'));
        $executor->seatedWorkerId = $seatedWorkerId;
        $placement->noteAgentStarted('chat', null, placementId: $placement->localPlacementId('chat'));

        $this->assertSame(PlacementState::Failed, $placement->registry()->get('chat')?->state);
    }

    /**
     * Revoking a local placement still waiting for its worker drops the deferred answer, so a
     * later start-failed fact cannot rewrite a Refused record to Failed (HIL-1041), nor a start
     * reported late rewrite it to Started.
     *
     * @param int $seatedWorkerId Worker a late start would report the agent seated on
     */
    #[DataProvider('awaitedWorkers')]
    public function testRevokingALocalPlacementDropsItsDeferredAnswer(int $seatedWorkerId): void
    {
        $mesh = new FakePlacementMesh([self::SELF => [self::SLOTS]]);
        $executor = new FakePlacementExecutor();
        $executor->waitsForWorker = true;
        $placement = new ClusterPlacement(self::SELF, $mesh, $executor);
        $placement->onBecameLeader(1000.0);
        $placement->placeAgentOnNode('chat', null, self::SELF);

        $placement->refusePlacement('chat', null, self::SELF);
        $placement->noteAgentStartFailed(
            'chat', null, 'it was stopped while waiting for its worker to connect', placementId: $placement->localPlacementId('chat'),
        );

        $this->assertSame(
            PlacementState::Refused,
            $placement->registry()->get('chat')?->state,
            'A refused placement is not rewritten by a deferred start-failed fact',
        );

        $executor->seatedWorkerId = $seatedWorkerId;
        $placement->noteAgentStarted('chat', null, placementId: $placement->localPlacementId('chat'));

        $this->assertSame(
            PlacementState::Refused,
            $placement->registry()->get('chat')?->state,
            'A refused placement is not rewritten by a late start either',
        );
    }

    /**
     * The node end of the gate (HIL-447): a place frame the node has no slot for is answered
     * failed with the gate's text, and nothing starts or waits on this node.
     */
    public function testANodeWithoutSlotsRefusesAPlaceFrameBeforeItStartsAnything(): void
    {
        $mesh = new FakePlacementMesh(['slave' => ['worker', 'ram=10']], linked: ['leader']);
        $executor = new FakePlacementExecutor(['worker']);
        $executor->waitsForWorker = true;
        $placement = new ClusterPlacement('slave', $mesh, $executor);

        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9', new PlacementId('leader', 0, 1)));

        $this->assertSame([], $executor->executed, 'Nothing is started on a node with no room');
        $this->assertCount(1, $mesh->sent);
        [$nodeId, $frame] = $mesh->sent[0];
        $this->assertSame('leader', $nodeId);
        $this->assertInstanceOf(PeerAgentStatusDTO::class, $frame);
        $this->assertSame(PlacementState::Failed, $frame->state);
        $this->assertSame(
            "Cannot place agent 'render:9' on node 'slave': insufficient free capacity [slots: needs 1, free 0]",
            $frame->error,
        );

        $placement->noteAgentStarted('render', '9', placementId: $placement->localPlacementId('render:9'));
        $this->assertCount(1, $mesh->sent, 'Nothing was deferred, so no later start answers the refused frame');
    }

    public function testTheNodeGateRefusesAMissingTagAndAMissingDeclaration(): void
    {
        $mesh = new FakePlacementMesh(['slave' => [self::SLOTS]], linked: ['leader']);
        $executor = new FakePlacementExecutor(['gpu']);
        $placement = new ClusterPlacement('slave', $mesh, $executor);

        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '1', new PlacementId('leader', 0, 1)));
        $mesh->capabilities['slave'] = ['gpu'];
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '2', new PlacementId('leader', 0, 2)));

        $this->assertSame([], $executor->executed);
        $this->assertSame(
            [
                "Cannot place agent 'render:1' on node 'slave': missing required capabilities [gpu]",
                "Cannot place agent 'render:2' on node 'slave': the node declares no capacity, so it accepts no placed work",
            ],
            array_map(static fn(array $sent): ?string => $sent[1] instanceof PeerAgentStatusDTO ? $sent[1]->error : null, $mesh->sent),
        );
    }

    public function testTheNodeJudgesByTheTagsItAdvertisesNow(): void
    {
        $mesh = new FakePlacementMesh(['slave' => ['slots=1']], linked: ['leader']);
        $executor = new FakePlacementExecutor(workerId: 5);
        $placement = new ClusterPlacement('slave', $mesh, $executor);
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '1', new PlacementId('leader', 0, 1)));

        $mesh->capabilities['slave'] = ['slots=2'];
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '2', new PlacementId('leader', 0, 2)));

        $this->assertSame([['render', '1'], ['render', '2']], $executor->executed);
    }

    /**
     * A start waiting for its worker holds its slot until it is seated or let go (HIL-447), as a
     * Placing record does on the leader; a failed start gives it back.
     */
    public function testADeferredStartHoldsItsSlotUntilItFails(): void
    {
        $mesh = new FakePlacementMesh(['slave' => ['slots=1']], linked: ['leader']);
        $executor = new FakePlacementExecutor();
        $executor->waitsForWorker = true;
        $placement = new ClusterPlacement('slave', $mesh, $executor);
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '1', new PlacementId('leader', 0, 1)));

        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '2', new PlacementId('leader', 0, 2)));

        $this->assertSame([['render', '1']], $executor->executed, 'The waiting start holds the only slot');
        $this->assertSame(PlacementState::Failed, $mesh->sent[0][1]->state);

        $placement->noteAgentStartFailed(
            'render', '1', 'the monopolistic worker never came up', placementId: $placement->localPlacementId('render:1'),
        );
        $mesh->sent = [];
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '2', new PlacementId('leader', 0, 3)));

        $this->assertSame([['render', '1'], ['render', '2']], $executor->executed, 'The failed start gave its slot back');
        $this->assertSame([], $mesh->sent, 'render:2 now waits for its worker in its turn');
    }

    public function testAStopGivesBackTheSlotsOfHostedAndWaitingAgents(): void
    {
        $mesh = new FakePlacementMesh(['slave' => ['slots=2']], linked: ['leader']);
        $executor = new FakePlacementExecutor(workerId: 5);
        $placement = new ClusterPlacement('slave', $mesh, $executor);
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '1', new PlacementId('leader', 0, 1)));
        $executor->waitsForWorker = true;
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '2', new PlacementId('leader', 0, 2)));
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '3', new PlacementId('leader', 0, 3)));
        $this->assertCount(2, $executor->executed, 'One hosted and one waiting agent fill slots=2');

        $placement->onStopAgent('leader', new PeerStopAgentDTO('render', '1', 0, 'stop-1', new PlacementId('leader', 0, 1)));
        $placement->onStopAgent('leader', new PeerStopAgentDTO('render', '2', 0, 'stop-2', new PlacementId('leader', 0, 2)));
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '3', new PlacementId('leader', 0, 4)));
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '4', new PlacementId('leader', 0, 5)));

        $this->assertSame([['render', '1'], ['render', '2'], ['render', '3'], ['render', '4']], $executor->executed);
    }

    /**
     * The agent a frame places is left out of what the node holds, and an agent both hosted and
     * waiting again is counted once: occupancy is per agent, not per entry.
     */
    public function testTheNodeCountsEachAgentOnceAndNeverAgainstItself(): void
    {
        $mesh = new FakePlacementMesh(['slave' => ['slots=2']], linked: ['leader']);
        $executor = new FakePlacementExecutor(workerId: 5);
        $placement = new ClusterPlacement('slave', $mesh, $executor);
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '1', new PlacementId('leader', 0, 1)));
        $executor->waitsForWorker = true;
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '1', new PlacementId('leader', 0, 2)));

        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '2', new PlacementId('leader', 0, 3)));
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '3', new PlacementId('leader', 0, 4)));

        $this->assertSame([['render', '1'], ['render', '1'], ['render', '2']], $executor->executed);
        $failed = array_values(array_filter(
            $mesh->sent,
            static fn(array $sent): bool => $sent[1] instanceof PeerAgentStatusDTO && $sent[1]->state === PlacementState::Failed,
        ));
        $this->assertCount(1, $failed, 'Only render:3 finds both slots held');
        $this->assertSame('render', $failed[0][1]->agentType);
        $this->assertSame('3', $failed[0][1]->agentIndex);
    }

    public function testNodeRepliesFailedWhenExecutionThrows(): void
    {
        $mesh = new FakePlacementMesh(['gpu-node' => [self::SLOTS]], linked: ['leader']);
        $executor = new FakePlacementExecutor();
        $executor->failWith = new NoSuitableWorkerException('regular', false);
        $placement = new ClusterPlacement('gpu-node', $mesh, $executor);

        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', null, new PlacementId('leader', 0, 1)));

        $frame = $mesh->sent[0][1];
        $this->assertInstanceOf(PeerAgentStatusDTO::class, $frame);
        $this->assertSame(PlacementState::Failed, $frame->state);
        $this->assertNotNull($frame->error);
    }

    public function testLeaderTracksAStartedStatusAgainstTheReportingNode(): void
    {
        $placement = new ClusterPlacement(self::SELF, new FakePlacementMesh([]), new FakePlacementExecutor());
        $placement->onBecameLeader(1000.0);

        FakePlacementMesh::status($placement, 'gpu-node', PeerAgentStatusDTO::started('render', '9', 5));

        $record = $placement->registry()->get('render:9');
        $this->assertSame('gpu-node', $record?->nodeId);
        $this->assertSame(PlacementState::Started, $record?->state);
    }

    public function testLeaderForgetsAPlacementOnAStoppedStatus(): void
    {
        $placement = new ClusterPlacement(self::SELF, new FakePlacementMesh([]), new FakePlacementExecutor());
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::status($placement, 'gpu-node', PeerAgentStatusDTO::started('render', '9', 5));

        FakePlacementMesh::status($placement, 'gpu-node', PeerAgentStatusDTO::stopped('render', '9'));

        $this->assertNull($placement->registry()->get('render:9'));
    }

    /** @return iterable<string, array{bool}> Return before and after the lost host's grace */
    public static function gracefulHostReturns(): iterable
    {
        yield 'fresh process returns before failover' => [false];
        yield 'failover expires without any capacity' => [true];
    }

    /**
     * own-env announces node_leaving before the workers' agent_stopped reports (HIL-446).
     * Neither extra instance has a supervisor: only the retained placement can bring it back.
     * The fresh host's regular worker has not connected when the place frames land: the host
     * answers nothing until its agents are seated, then one Started each (HIL-1671).
     *
     * @param bool $afterGrace Whether failover runs before the fresh host returns
     */
    #[DataProvider('gracefulHostReturns')]
    public function testGracefulHostStopsPreserveExtraWorkUntilReconciliation(bool $afterGrace): void
    {
        $mesh = new FakePlacementMesh(
            ['node-b' => ['slots=2']], linked: ['node-b'], online: [self::SELF, 'node-b'],
        );
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(), failoverGraceMs: 500);
        $placement->onBecameLeader(1000.0);
        foreach (['2001', '2003', '2011'] as $index) {
            $placement->onPlacementRequest('caller', new PeerPlacementRequestDTO('render', $index, 0, 'ask'));
        }
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([]));
        $placement->tick(1000.0);

        $hostMesh = new FakePlacementMesh(['node-b' => ['slots=2']], linked: [self::SELF]);
        $host = new ClusterPlacement('node-b', $hostMesh, new FakePlacementExecutor());
        foreach ($mesh->sent as [, $frame]) {
            if ($frame instanceof PeerPlaceAgentDTO) {
                $host->onPlaceAgent(self::SELF, $frame);
            }
        }
        foreach ($hostMesh->sent as [, $frame]) {
            $this->assertInstanceOf(PeerAgentStatusDTO::class, $frame);
            FakePlacementMesh::status($placement, 'node-b', $frame);
        }
        foreach (['2001', '2003'] as $index) {
            $this->assertSame(PlacementState::Started, $placement->registry()->get('render:' . $index)?->state);
        }
        $this->assertSame(PlacementState::Unplaced, $placement->registry()->get('render:2011')?->state);
        $revision = $placement->registry()->revision();
        $mesh->sent = [];
        $hostMesh->sent = [];

        // PeerServer handles the leave before the same link carries the workers' stops.
        $mesh->online = [self::SELF];
        $placement->noteNodeOffline('node-b', 1001.0);
        foreach (['2001', '2003'] as $index) {
            $host->noteAgentStopped('render', $index);
        }
        $this->assertCount(2, $hostMesh->sent);
        foreach ($hostMesh->sent as [, $frame]) {
            $this->assertInstanceOf(PeerAgentStatusDTO::class, $frame);
            $this->assertSame(PlacementState::Stopped, $frame->state);
            FakePlacementMesh::status($placement, 'node-b', $frame);
        }
        $this->assertSame($revision, $placement->registry()->revision(), 'Host departure must not cancel either extra intent');
        $placement->tick(1001.499);
        $this->assertSame([], $mesh->sent, 'The existing host grace still prevents premature placement');

        if ($afterGrace) {
            $placement->tick(1001.6);
            foreach (['2001', '2003', '2011'] as $index) {
                $this->assertSame(PlacementState::Unplaced, $placement->registry()->get('render:' . $index)?->state);
            }
        }

        // The recreated host has more slots and no hosted agents; nobody asks for work again.
        $mesh->online = [self::SELF, 'node-b'];
        $mesh->capabilities['node-b'] = ['slots=3'];
        $placement->noteNodeOnline('node-b', 1001.7);
        $placement->onPeerHandshaked('node-b');
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([]));
        foreach ([1001.7, 1001.71, 1001.72] as $now) {
            $placement->tick($now);
        }
        // The fresh process launched its regular worker in the same millisecond, and the worker has
        // not registered yet: the agents wait for it on the node rather than fail (HIL-1671).
        $freshMesh = new FakePlacementMesh(['node-b' => ['slots=3']], linked: [self::SELF]);
        $executor = new FakePlacementExecutor();
        $executor->waitsForWorker = true;
        $fresh = new ClusterPlacement('node-b', $freshMesh, $executor);
        foreach ($mesh->sent as [$nodeId, $frame]) {
            if ($frame instanceof PeerPlaceAgentDTO) {
                $this->assertSame('node-b', $nodeId);
                $fresh->onPlaceAgent(self::SELF, $frame);
            }
        }
        $mesh->sent = [];
        $this->assertSame([], $freshMesh->sent, 'A host whose regular worker is still connecting answers nothing, failed least of all');
        $this->assertCount(3, $placement->registry()->all(), 'Every intent is retained while the host waits');
        foreach (['2001', '2003', '2011'] as $index) {
            $this->assertSame(PlacementState::Placing, $placement->registry()->get('render:' . $index)?->state);
        }
        // The leader waits out the worker's connection and its data on the ack timer: no repeat, no query.
        $placement->tick(1001.72 + self::WORKER_CONNECT_WAIT_SECONDS + AgentConstants::START_DEADLINE_SECONDS);
        $this->assertSame([], array_filter(
            $mesh->sent,
            static fn(array $sent): bool => $sent[1] instanceof PeerPlaceAgentDTO || $sent[1] instanceof PeerPlacementQueryDTO,
        ));

        $executor->seatedWorkerId = self::REGULAR_WORKER_ID;
        foreach (['2001', '2003', '2011'] as $index) {
            $fresh->noteAgentStarted('render', $index, placementId: $fresh->localPlacementId('render:' . $index));
            $fresh->noteAgentStarted('render', $index, placementId: $fresh->localPlacementId('render:' . $index));
        }
        $this->assertCount(3, $freshMesh->sent, 'One answer per seated agent');
        foreach ($freshMesh->sent as [, $frame]) {
            $this->assertInstanceOf(PeerAgentStatusDTO::class, $frame);
            $this->assertSame(PlacementState::Started, $frame->state);
            $this->assertSame(self::REGULAR_WORKER_ID, $frame->workerId);
            FakePlacementMesh::status($placement, 'node-b', $frame);
        }
        $this->assertCount(3, $executor->executed, 'Every original intent starts once without a new ask');
        $freshMesh->sent = [];
        $fresh->onPlacementQuery(self::SELF, new PeerPlacementQueryDTO('query', 0));
        $this->assertInstanceOf(PeerPlacementReportDTO::class, $freshMesh->sent[0][1]);
        $this->assertCount(3, $freshMesh->sent[0][1]->agents);
        $this->assertCount(3, $placement->registry()->all());
        foreach (['2001', '2003', '2011'] as $index) {
            $record = $placement->registry()->get('render:' . $index);
            $this->assertSame(PlacementState::Started, $record?->state);
            $this->assertSame('node-b', $record?->nodeId);
        }

        // An ordinary idle stop after the return still forgets the instance permanently.
        $freshMesh->sent = [];
        $fresh->noteAgentStopped('render', '2001');
        foreach ($freshMesh->sent as [, $frame]) {
            $this->assertInstanceOf(PeerAgentStatusDTO::class, $frame);
            FakePlacementMesh::status($placement, 'node-b', $frame);
        }
        $placement->tick(1010.0);
        $this->assertNull($placement->registry()->get('render:2001'));
        $this->assertSame(PlacementState::Started, $placement->registry()->get('render:2011')?->state);
    }

    public function testStopOnARemoteNodeSendsAStopFrameAndKeepsCancellation(): void
    {
        $mesh = new FakePlacementMesh(['gpu-node' => ['gpu', self::SLOTS]], linked: ['gpu-node']);
        $executor = new FakePlacementExecutor(['gpu']);
        $placement = new ClusterPlacement(self::SELF, $mesh, $executor);
        $placement->placeAgentOnNode('render', '9', 'gpu-node');
        $order = $placement->registry()->get('render:9')->placementId;
        $mesh->sent = [];

        $placement->stopAgentOnNode('render', '9', 'gpu-node');

        $this->assertInstanceOf(PeerStopAgentDTO::class, $mesh->sent[0][1]);
        $this->assertEquals(
            new PlacementRecord('render', '9', 'gpu-node', PlacementState::Stopped, placementId: $order),
            $placement->registry()->get('render:9'),
        );
    }

    public function testNodeAnswersAQueryWithItsHostedSet(): void
    {
        $mesh = new FakePlacementMesh(['gpu-node' => [self::SLOTS]], linked: ['leader']);
        $placement = new ClusterPlacement('gpu-node', $mesh, new FakePlacementExecutor(workerId: 5));
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9', new PlacementId('leader', 0, 1)));
        $mesh->sent = [];

        $placement->onPlacementQuery('leader', new PeerPlacementQueryDTO('query', 0));

        $report = $mesh->sent[0][1];
        $this->assertInstanceOf(PeerPlacementReportDTO::class, $report);
        $this->assertCount(1, $report->agents);
        $this->assertSame('render', $report->agents[0]->agentType);
    }

    public function testBecomingLeaderBroadcastsAQueryAndRebuildsFromReports(): void
    {
        $mesh = new FakePlacementMesh([]);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor());
        $placement->onBecameLeader(900.0);
        $mesh->broadcast = [];
        // A stale entry from a previous term must be cleared on the fresh rebuild.
        FakePlacementMesh::status($placement, 'old-node', PeerAgentStatusDTO::started('stale', null, 1));

        $placement->onBecameLeader(1000.0);

        $this->assertSame(0, $placement->registry()->count(), 'The view is cleared before the rebuild');
        $this->assertInstanceOf(PeerPlacementQueryDTO::class, $mesh->broadcast[0]);

        FakePlacementMesh::reply($placement, 'gpu-node', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')]));

        $record = $placement->registry()->get('render:9');
        $this->assertSame('gpu-node', $record?->nodeId);
        $this->assertSame(PlacementState::Started, $record?->state);
    }

    public function testLocateReportsTheHostingNodeOfARemotelyPlacedAgent(): void
    {
        $placement = new ClusterPlacement(self::SELF, new FakePlacementMesh([]), new FakePlacementExecutor());
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::status($placement, 'gpu-node', PeerAgentStatusDTO::started('render', '9', 5));

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
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')]));
        FakePlacementMesh::reply($placement, 'node-c', new PeerPlacementReportDTO([]));
        $placement->tick(1000.0);
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
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')]));
        FakePlacementMesh::reply($placement, 'node-c', new PeerPlacementReportDTO([]));
        $placement->tick(1000.0);
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
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')]));
        FakePlacementMesh::reply($placement, 'node-c', new PeerPlacementReportDTO([]));
        $placement->tick(1000.0);

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

    /**
     * A recreated slave retains its placements through shutdown stops (HIL-446), so its return
     * calls off their failover before they are placed afresh (HIL-447). A stale deadline must
     * not degrade the replacement or make the leader undercount the node's occupied slots.
     */
    public function testAPlacementBackOnTheLostNodeCallsOffItsFailover(): void
    {
        $logFile = $this->captureLog();
        // 'leader' lacks the gpu tag, so node-b is the only node that can host the agent.
        $mesh = new FakePlacementMesh(
            capabilities: ['node-b' => ['gpu', self::SLOTS]],
            linked: ['node-b'],
            online: [self::SELF, 'node-b'],
        );
        $observer = new FakePlacementObserver();
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['gpu']), $observer, failoverGraceMs: 500);
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')]));
        $placement->tick(1000.0);

        $mesh->online = [self::SELF];
        $placement->noteNodeOffline('node-b', 1000.0);
        FakePlacementMesh::status($placement, 'node-b', PeerAgentStatusDTO::stopped('render', '9'));
        $mesh->online = [self::SELF, 'node-b'];
        $placement->noteNodeOnline('node-b', 1000.2);
        $placement->placeAgentOnNode('render', '9', 'node-b');
        FakePlacementMesh::status($placement, 'node-b', PeerAgentStatusDTO::started('render', '9', 5));
        $mesh->sent = [];

        $placement->tick(1000.6);

        $this->assertSame([], $observer->degraded, 'An agent running where it was placed afresh is not degraded');
        $record = $placement->registry()->get('render:9');
        $this->assertSame('node-b', $record?->nodeId);
        $this->assertSame(PlacementState::Started, $record?->state, 'The leader still counts the agent against node-b');
        $this->assertSame([], $mesh->sent, 'Nothing is re-placed or stopped');

        $log = (string)file_get_contents($logFile);
        unlink($logFile);
        $this->assertStringContainsString("Failover of 1 agent(s) on 'node-b' called off: the node is back before the grace elapsed", $log);
    }

    /**
     * A placement onto another node calls the old failover off too, so the loss of the node the
     * agent went to arms one of its own (HIL-447). The old deadline, still standing, kept that
     * loss from arming anything, then fired, found the record on another node and dropped itself
     * as stale: the agent stayed written down on a dead node for the rest of the term.
     */
    public function testAPlacementElsewhereLeavesTheNextLossItsOwnFailover(): void
    {
        $mesh = new FakePlacementMesh(
            capabilities: ['node-b' => ['gpu', self::SLOTS], 'node-c' => ['gpu', self::SLOTS], 'node-d' => ['gpu', self::SLOTS]],
            linked: ['node-b', 'node-c', 'node-d'],
            online: [self::SELF, 'node-b', 'node-c', 'node-d'],
        );
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['gpu']), null, failoverGraceMs: 500);
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')]));
        FakePlacementMesh::reply($placement, 'node-c', new PeerPlacementReportDTO([]));
        FakePlacementMesh::reply($placement, 'node-d', new PeerPlacementReportDTO([]));
        $placement->tick(1000.0);

        $mesh->online = [self::SELF, 'node-c', 'node-d'];
        $placement->noteNodeOffline('node-b', 1000.0);
        FakePlacementMesh::status($placement, 'node-b', PeerAgentStatusDTO::stopped('render', '9'));
        $placement->placeAgentOnNode('render', '9', 'node-c');
        $mesh->online = [self::SELF, 'node-d'];
        $placement->noteNodeOffline('node-c', 1000.2);
        $mesh->sent = [];

        $placement->tick(1000.6);
        $this->assertSame([], $mesh->sent, 'The loss of node-c is waited out for a grace of its own');

        $placement->tick(1000.8);
        $this->assertCount(1, $mesh->sent, 'One place frame, for the agent lost with node-c');
        [$nodeId, $frame] = $mesh->sent[0];
        $this->assertSame('node-d', $nodeId, 'The agent lost with node-c is re-placed onto the surviving node');
        $this->assertInstanceOf(PeerPlaceAgentDTO::class, $frame);
        $this->assertSame('node-d', $placement->registry()->get('render:9')?->nodeId);
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
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')]));
        $placement->tick(1000.0);

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
        $placement->tick(2000.0);

        $record = $placement->registry()->get('render:9');
        $this->assertSame('node-c', $record?->nodeId, 'The previously-unplaced agent is placed on the newcomer');
        $this->assertNotSame(PlacementState::Unplaced, $record?->state);
    }

    public function testSlaveSelfFencesItsHostedAgentsWhenIsolatedFromThePlacingLeader(): void
    {
        $mesh = new FakePlacementMesh(['slave' => [self::SLOTS]], linked: ['leader']);
        $executor = new FakePlacementExecutor(workerId: 5);
        $placement = new ClusterPlacement('slave', $mesh, $executor, null, failoverGraceMs: 1000, slaveWorkGraceMs: 500);
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9', new PlacementId('leader', 0, 1)));

        $placement->noteNodeOffline('leader', 1000.0);
        $placement->tick(1000.4);
        $this->assertSame([], $executor->revoked, 'The slave keeps working through the grace window');

        $placement->tick(1000.6);
        $this->assertSame([['render', '9']], $executor->revoked, 'The slave stops its placed agents once isolated past the grace');
    }

    /** @return iterable<string, array{bool, bool}> Which wait a stop is overtaken in: the instance's, or the peers' */
    public static function stopWaits(): iterable
    {
        yield 'the stopped instance is still going' => [true, false];
        yield 'the peers are still answering' => [false, true];
    }

    /**
     * A leader's stop is confirmed only once the stopped instance is gone and then every active
     * link to a peer answered the RT writes handed it before (HIL-2108). With nothing held, as on
     * a node without peers, the answer leaves on the stop's own stack.
     */
    public function testAStopIsConfirmedOnlyBehindTheGoneInstanceAndThePeersAnswers(): void
    {
        $mesh = new FakePlacementMesh(['slave' => [self::SLOTS]], linked: ['leader']);
        $executor = new FakePlacementExecutor(workerId: 5);
        $placement = new ClusterPlacement('slave', $mesh, $executor);
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9', new PlacementId('leader', 0, 1)));
        $executor->holdStopped = true;
        $mesh->holdRtWritesAnswered = true;

        $stop = new PeerStopAgentDTO('render', '9', 0, 'stop', new PlacementId('leader', 0, 1));
        $placement->onStopAgent('leader', $stop);
        $this->assertSame([['render', '9']], $executor->revoked);
        $this->assertSame([], $mesh->heldRtWritesAnswered, 'The peers are waited for only once the instance is gone');
        $this->assertSame([], $this->stoppedStatuses($mesh));
        $placement->onPlacementQuery('leader', new PeerPlacementQueryDTO('stop-ack', 0));
        $placement->onStopAgent('leader', $stop);
        self::assertSame([], $this->placementReports($mesh));

        $executor->releaseStopped();
        $this->assertCount(1, $mesh->heldRtWritesAnswered);
        $this->assertSame([], $this->stoppedStatuses($mesh), 'A gone instance alone does not confirm the stop');
        $placement->onPlacementQuery('leader', new PeerPlacementQueryDTO('rejoin', 0));
        self::assertSame([], $this->placementReports($mesh));

        $mesh->releaseRtWritesAnswered();
        $this->assertSame([['leader', '9']], $this->stoppedStatuses($mesh));
        self::assertEquals($stop->stoppedStatus(), $mesh->sent[array_key_last($mesh->sent)][1]);
        $placement->tick(1000.0);
        self::assertEquals(new PeerPlacementReportDTO([], queryId: 'stop-ack'), $mesh->sent[array_key_last($mesh->sent)][1]);
        $placement->tick(1000.0);
        self::assertEquals([
            $stop->stoppedStatus(),
            new PeerPlacementReportDTO([], queryId: 'stop-ack'),
            new PeerPlacementReportDTO([], queryId: 'rejoin'),
        ], array_slice(array_column($mesh->sent, 1), -3));
        $placement->onStopAgent('leader', $stop);
        self::assertSame([['leader', '9']], $this->stoppedStatuses($mesh));

        $executor->holdStopped = false;
        $mesh->holdRtWritesAnswered = false;
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9', new PlacementId('leader', 0, 2)));
        $placement->onStopAgent('leader', new PeerStopAgentDTO('render', '9', 0, 'next-stop', new PlacementId('leader', 0, 2)));
        $this->assertSame([['leader', '9'], ['leader', '9']], $this->stoppedStatuses($mesh), 'Nothing held: answered on the same stack');
    }

    public function testLateStopAndRejoinEchoCannotConsumeTheNextOrderOnTheSameHost(): void
    {
        $leaderMesh = new FakePlacementMesh(['host' => [self::SLOTS]], linked: ['host']);
        $leader = new ClusterPlacement(self::SELF, $leaderMesh, new FakePlacementExecutor());
        $followerMesh = new FakePlacementMesh(['host' => [self::SLOTS]], linked: [self::SELF]);
        $executor = new FakePlacementExecutor(workerId: 5);
        $follower = new ClusterPlacement('host', $followerMesh, $executor);
        $leader->onBecameLeader(1000.0);
        $leader->placeAgentOnNode('render', '9', 'host');
        $first = $leaderMesh->sent[0][1];
        $follower->onPlaceAgent(self::SELF, $first);
        $leader->onAgentStatus('host', $followerMesh->sent[0][1]);

        $leader->stopAgentOnNode('render', '9', 'host');
        $stop = $leaderMesh->sent[1][1];
        $executor->holdStopped = true;
        $followerMesh->holdRtWritesAnswered = true;
        $follower->onStopAgent(self::SELF, $stop);
        $leader->placeAgentOnNode('render', '9', 'host');
        $leader->onPeerHandshaked('host');
        $rejoin = $leaderMesh->takeFixtureQuery('host');
        $followerMesh->sent = [];
        $follower->onPlacementQuery(self::SELF, $rejoin);
        $executor->releaseStopped();
        self::assertSame([], $followerMesh->sent);
        $followerMesh->releaseRtWritesAnswered();
        self::assertEquals([[self::SELF, $stop->stoppedStatus()]], $followerMesh->sent);
        $follower->tick(1000.0);
        self::assertCount(2, $followerMesh->sent);

        $leader->onAgentStatus('host', $followerMesh->sent[0][1]);
        $second = $leader->registry()->get('render:9')->placementId;
        self::assertTrue($first->placementId->precedes($second));
        $follower->onStopAgent(self::SELF, $stop);
        self::assertCount(2, $followerMesh->sent, 'A second direct Stopped for the old order cannot enter the turn');
        $leader->onPlacementReport('host', $followerMesh->sent[1][1]);
        self::assertSame(PlacementState::Placing, $leader->registry()->get('render:9')->state);
        $follower->onPlaceAgent(self::SELF, new PeerPlaceAgentDTO('render', '9', $second));
        $leader->onAgentStatus('host', $followerMesh->sent[2][1]);
        self::assertSame(PlacementState::Started, $leader->registry()->get('render:9')->state);
        self::assertSame($second, $leader->registry()->get('render:9')->placementId);
    }

    public function testARepeatedStopAfterRejectedWorkerStartCannotRetireTheNextOrder(): void
    {
        $leaderMesh = new FakePlacementMesh(['host' => [self::SLOTS]], linked: ['host']);
        $leader = new ClusterPlacement(self::SELF, $leaderMesh, new FakePlacementExecutor());
        $hostMesh = new FakePlacementMesh(['host' => [self::SLOTS]], linked: [self::SELF]);
        $executor = new FakePlacementExecutor(workerId: 5);
        $host = new ClusterPlacement('host', $hostMesh, $executor);
        $leader->onBecameLeader(1000.0);
        $leader->placeAgentOnNode('render', '9', 'host');
        $place = $leaderMesh->sent[0][1];
        $host->onPlaceAgent(self::SELF, $place);
        $leader->onAgentStatus('host', $hostMesh->sent[0][1]);
        $leader->stopAgentOnNode('render', '9', 'host');
        $stop = $leaderMesh->sent[1][1];
        $leader->onAgentStatus('host', $hostMesh->sent[0][1]);
        $repeat = $leaderMesh->sent[2][1];
        $leader->placeAgentOnNode('render', '9', 'host');

        $executor->holdStopped = true;
        $hostMesh->holdRtWritesAnswered = true;
        $host->stopRejectedLocalStart('render', '9', $place->placementId);
        self::assertNull($host->localPlacementId('render:9'));
        $host->onStopAgent(self::SELF, $stop);
        self::assertSame([], $this->stoppedStatuses($hostMesh));
        $executor->releaseStopped();
        self::assertSame([], $this->stoppedStatuses($hostMesh));
        $hostMesh->releaseRtWritesAnswered();
        $leader->onAgentStatus('host', $hostMesh->sent[1][1]);
        $next = $leader->registry()->get('render:9');
        self::assertTrue($place->placementId->precedes($next->placementId));
        $executor->holdStopped = false;
        $hostMesh->holdRtWritesAnswered = false;
        $host->onStopAgent(self::SELF, $repeat);
        foreach (array_slice($hostMesh->sent, 2) as [, $reply]) {
            $leader->onAgentStatus('host', $reply);
        }
        self::assertSame($next, $leader->registry()->get('render:9'));
    }

    public function testAStopWithoutHostedStillWaitsForTheWorkerPortAndPeerAnswers(): void
    {
        $mesh = new FakePlacementMesh(['slave' => [self::SLOTS]], linked: ['leader']);
        $executor = new FakePlacementExecutor(workerId: 5);
        $host = new ClusterPlacement('slave', $mesh, $executor);
        $order = new PlacementId('leader', 7, 1);
        $host->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9', $order));
        $host->discardLocalStart('render:9', $order);
        $executor->holdStopped = $mesh->holdRtWritesAnswered = true;
        $stop = new PeerStopAgentDTO('render', '9', 7, 'stop', $order);

        $host->onStopAgent('leader', $stop);
        self::assertSame([], $this->stoppedStatuses($mesh));
        $executor->releaseStopped();
        self::assertSame([], $this->stoppedStatuses($mesh));
        $mesh->releaseRtWritesAnswered();
        self::assertEquals($stop->stoppedStatus(), $mesh->sent[1][1]);
    }

    public function testARejoinEchoBeforeAnOrderCannotCompleteItsLaterStop(): void
    {
        $mesh = new FakePlacementMesh(['host' => [self::SLOTS], 'next' => [self::SLOTS]], linked: ['host', 'next']);
        $leader = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor());
        $leader->onBecameLeader(1000.0);
        $leader->onPeerHandshaked('host');
        $early = $mesh->takeFixtureQuery('host');

        $leader->placeAgentOnNode('render', '9', 'host');
        $order = $leader->registry()->get('render:9');
        $leader->placeAgentOnNode('render', '9', 'next');
        $leader->onPlacementReport('host', new PeerPlacementReportDTO([], queryId: $early->queryId));
        self::assertSame($order, $leader->registry()->get('render:9'));

        $leader->onPeerHandshaked('host');
        $late = $mesh->takeFixtureQuery('host');
        $leader->onPlacementReport('host', new PeerPlacementReportDTO([], queryId: $late->queryId));
        self::assertSame('next', $leader->registry()->get('render:9')?->nodeId);
    }

    public function testAnUndeliveredStopCannotLendItsAuthorityToAnEarlierQuery(): void
    {
        $mesh = new FakePlacementMesh(['host' => [self::SLOTS], 'next' => [self::SLOTS]], linked: ['host', 'next']);
        $leader = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor());
        $leader->onBecameLeader(1000.0, 8);
        $leader->placeAgentOnNode('render', '9', 'host');
        $order = $leader->registry()->get('render:9');
        $mesh->linked = ['next'];
        $leader->placeAgentOnNode('render', '9', 'next');
        $leader->tick(1001.0);
        $leader->tick(1017.0);

        $mesh->linked = ['host', 'next'];
        $mesh->sent = [];
        $leader->onPeerHandshaked('host');
        $query = $mesh->takeFixtureQuery('host');
        self::assertNotNull($query);
        [$before, $stop] = $query->orderedPrefix;
        $leader->onPlacementReport('host', new PeerPlacementReportDTO([], queryId: $before->queryId));
        self::assertSame($order, $leader->registry()->get('render:9'));
        $leader->onAgentStatus('host', $stop->stoppedStatus());
        self::assertSame('next', $leader->registry()->get('render:9')?->nodeId);
    }

    public function testOnlyTheExactStopReceiptReleasesAManagedOrder(): void
    {
        $mesh = new FakePlacementMesh(['host' => [self::SLOTS], 'next' => [self::SLOTS]], linked: ['host', 'next']);
        $leader = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor());
        $leader->onBecameLeader(1000.0, 8);
        $leader->placeAgentOnNode('render', '9', 'host');
        $old = $leader->registry()->get('render:9')->placementId;
        $leader->placeAgentOnNode('render', '9', 'next');
        $leader->placeAgentOnNode('render', '9', 'host');
        $stop = $mesh->sent[1][1];
        $receipt = $stop->stoppedStatus();

        $leader->onAgentStatus('other', $receipt);
        $leader->onAgentStatus('host', PeerAgentStatusDTO::stopped('render', '9', $old, 7, $stop->stopId));
        $leader->onAgentStatus('host', PeerAgentStatusDTO::stopped('render', '9', $old, 8, 'another-stop'));
        $leader->onAgentStatus('host', PeerAgentStatusDTO::stopped('render', '9', $old, 8));
        $leader->onAgentStatus('host', PeerAgentStatusDTO::stopped('render', '9'));
        self::assertSame($old, $leader->registry()->get('render:9')->placementId);

        $leader->onAgentStatus('host', $receipt);
        $next = $leader->registry()->get('render:9')->placementId;
        self::assertTrue($old->precedes($next));
        $leader->onAgentStatus('host', $receipt);
        $leader->onAgentStatus('host', PeerAgentStatusDTO::stopped('render', '9'));
        self::assertSame($next, $leader->registry()->get('render:9')->placementId);
        $leader->onAgentStatus('host', PeerAgentStatusDTO::started('render', '9', 5, $next, 8, true));
        $leader->onAgentStatus('host', PeerAgentStatusDTO::stopped('render', '9', $next, 8));
        self::assertNull($leader->registry()->get('render:9'));

        $leader->onPlacementReport('host', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('ordinary', null)]));
        self::assertSame('host', $leader->registry()->get('ordinary')?->nodeId);
        $leader->onAgentStatus('host', PeerAgentStatusDTO::stopped('ordinary', null));
        self::assertNull($leader->registry()->get('ordinary'));
    }

    public function testNamedDemandForTheOldHostWaitsForTheSameStop(): void
    {
        $mesh = new FakePlacementMesh(['host' => [self::SLOTS], 'next' => [self::SLOTS]], linked: ['host', 'next']);
        $leader = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor());
        $leader->onBecameLeader(1000.0);
        $leader->placeAgentOnNode('render', '9', 'host');
        $order = $leader->registry()->get('render:9');

        $leader->placeAgentOnNode('render', '9', 'next');
        $leader->placeAgentOnNode('render', '9', 'host');
        self::assertSame($order, $leader->registry()->get('render:9'));
        self::assertSame([['host', 'render', '9']], $this->placeFrames($mesh));

        FakePlacementMesh::status($leader, 'host', PeerAgentStatusDTO::stopped('render', '9'));
        $next = $leader->registry()->get('render:9');
        self::assertSame('host', $next?->nodeId);
        self::assertTrue($order->placementId->precedes($next?->placementId));
    }

    /** @param bool $returns Whether the old host rejoins before its isolation deadline */
    #[DataProvider('directMoveIsolation')]
    public function testNamedDemandFromAnOfflineHostWaitsForStopOrIsolation(bool $returns): void
    {
        $mesh = new FakePlacementMesh(
            ['host' => [self::SLOTS], 'next' => [self::SLOTS]],
            linked: ['host', 'next'],
            online: [self::SELF, 'host', 'next'],
        );
        $leader = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(), failoverGraceMs: 500);
        $leader->onBecameLeader(1000.0);
        FakePlacementMesh::reply($leader, 'host', new PeerPlacementReportDTO([]));
        FakePlacementMesh::reply($leader, 'next', new PeerPlacementReportDTO([]));
        $leader->tick(1000.0);
        $leader->placeAgentOnNode('render', '9', 'host');
        $order = $leader->registry()->get('render:9');
        $mesh->linked = $mesh->online = ['next'];
        $leader->noteNodeOffline('host', 1001.0);
        $leader->placeAgentOnNode('render', '9', 'next');
        $leader->tick(1001.4);
        self::assertSame($order, $leader->registry()->get('render:9'));

        if ($returns) {
            $mesh->linked = $mesh->online = ['host', 'next'];
            $leader->noteNodeOnline('host', 1001.45);
            $leader->onPeerHandshaked('host');
            $leader->tick(1001.6);
            self::assertSame($order, $leader->registry()->get('render:9'));
            FakePlacementMesh::status($leader, 'host', PeerAgentStatusDTO::stopped('render', '9'));
        } else {
            $leader->tick(1001.6);
        }
        self::assertSame('next', $leader->registry()->get('render:9')?->nodeId);
    }

    public static function directMoveIsolation(): iterable
    {
        yield 'host returns' => [true];
        yield 'isolation elapses' => [false];
    }

    /**
     * A stop overtaken by a new placement of the same agent while it waits owes the leader no answer.
     *
     * @param bool $holdStopped Whether the stopped instance is still going when the placement comes
     * @param bool $holdAnswers Whether the peers are still answering when the placement comes
     */
    #[DataProvider('stopWaits')]
    public function testAStopOvertakenByANewPlacementWhileItWaitsSendsNoAnswer(bool $holdStopped, bool $holdAnswers): void
    {
        $mesh = new FakePlacementMesh(['slave' => [self::SLOTS]], linked: ['leader']);
        $executor = new FakePlacementExecutor(workerId: 5);
        $placement = new ClusterPlacement('slave', $mesh, $executor);
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9', new PlacementId('leader', 0, 1)));
        $executor->holdStopped = $holdStopped;
        $mesh->holdRtWritesAnswered = $holdAnswers;

        $placement->onStopAgent('leader', new PeerStopAgentDTO('render', '9', 0, 'stop', new PlacementId('leader', 0, 1)));
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9', new PlacementId('leader', 0, 2)));
        $executor->releaseStopped();
        $mesh->releaseRtWritesAnswered();

        $this->assertSame([], $this->stoppedStatuses($mesh));
        [$nodeId, $frame] = $mesh->sent[array_key_last($mesh->sent)];
        $this->assertSame('leader', $nodeId);
        $this->assertInstanceOf(PeerAgentStatusDTO::class, $frame);
        $this->assertSame(PlacementState::Started, $frame->state, 'The newer placement is answered');
    }

    /**
     * A node's note that its agent stopped reaches the leader only behind the peers' RT answers,
     * and the agent placed here again meanwhile silences the note.
     */
    public function testAStoppedNoteWaitsForThePeersAndAnAgentTakenBackSilencesIt(): void
    {
        $mesh = new FakePlacementMesh(['slave' => [self::SLOTS]], linked: ['leader']);
        $placement = new ClusterPlacement('slave', $mesh, new FakePlacementExecutor(workerId: 5));
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9', new PlacementId('leader', 0, 1)));
        $mesh->holdRtWritesAnswered = true;

        $placement->noteAgentStopped('render', '9');
        $this->assertSame([], $this->stoppedStatuses($mesh));
        $placement->onPlacementQuery('leader', new PeerPlacementQueryDTO('rejoin', 0));
        self::assertSame([], $this->placementReports($mesh));
        $mesh->releaseRtWritesAnswered();
        self::assertInstanceOf(PeerAgentStatusDTO::class, $mesh->sent[array_key_last($mesh->sent)][1]);
        $placement->tick(1000.0);
        self::assertEquals(new PeerPlacementReportDTO([], queryId: 'rejoin'), $mesh->sent[array_key_last($mesh->sent)][1]);
        $this->assertSame([['leader', '9']], $this->stoppedStatuses($mesh));

        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9', new PlacementId('leader', 0, 2)));
        $placement->noteAgentStopped('render', '9');
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9', new PlacementId('leader', 0, 3)));
        $mesh->releaseRtWritesAnswered();
        $this->assertSame([['leader', '9']], $this->stoppedStatuses($mesh), 'The agent taken back silences the earlier note');
    }

    /** A leader hosting its own placement forgets the record of a stopped agent only once the peers answered. */
    public function testALeaderForgetsItsOwnStoppedAgentOnlyOnceThePeersAnswered(): void
    {
        $mesh = new FakePlacementMesh([self::SELF => [self::SLOTS]]);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor());
        $placement->onBecameLeader(1000.0);
        $placement->placeAgentOnNode('render', '9', self::SELF);
        $mesh->holdRtWritesAnswered = true;

        $placement->noteAgentStopped('render', '9');
        $this->assertSame(PlacementState::Started, $placement->registry()->get('render:9')?->state);

        $mesh->releaseRtWritesAnswered();
        $this->assertNull($placement->registry()->get('render:9'));
    }

    public function testOwnLateIdleStopCannotForgetARefusalOrARebuiltRemoteOrder(): void
    {
        $mesh = new FakePlacementMesh([self::SELF => [self::SLOTS], 'next' => [self::SLOTS]], linked: ['next']);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor());
        $placement->onBecameLeader(1000.0);
        $placement->placeAgentOnNode('render', '9', self::SELF);
        $mesh->holdRtWritesAnswered = true;
        $placement->noteAgentStopped('render', '9');
        $placement->refusePlacement('render', '9', self::SELF);
        $mesh->releaseRtWritesAnswered();
        self::assertSame(PlacementState::Refused, $placement->registry()->get('render:9')?->state);

        $placement->placeAgentOnNode('render', '10', self::SELF);
        $placement->noteAgentStopped('render', '10');
        $placement->onLostLeadership();
        $mesh->online = ['next'];
        $placement->onBecameLeader(1001.0, 2);
        $new = new PlacementId(self::SELF, 2, 1);
        FakePlacementMesh::reply($placement, 'next', new PeerPlacementReportDTO([
            new PeerPlacedAgentEntry('render', '10', $new, PlacementState::Started, true),
        ]));
        $placement->tick(1001.0);
        self::assertTrue($new->equals($placement->registry()->get('render:10')?->placementId));
        $mesh->releaseRtWritesAnswered();
        $placement->tick(1001.0);
        self::assertSame('next', $placement->registry()->get('render:10')?->nodeId);
    }

    /** @param bool $targetLost Whether the proposed target leaves before the old writes settle */
    #[DataProvider('managedMoveTargets')]
    public function testManagedMoveUsesTheLocalStopAndPeerWriteCompletion(bool $targetLost): void
    {
        $mesh = new FakePlacementMesh(
            [self::SELF => ['gpu', self::SLOTS], 'next' => ['gpu', self::SLOTS]],
            linked: ['next'], online: [self::SELF],
        );
        $executor = new FakePlacementExecutor(['gpu']);
        $placement = new ClusterPlacement(self::SELF, $mesh, $executor);
        $placement->onBecameLeader(1000.0);
        $placement->placeAgentOnNode('render', '9', self::SELF);
        $initial = $placement->registry()->get('render:9');
        $executor->holdStopped = true;
        $mesh->holdRtWritesAnswered = true;
        $placement->placeAgentOnNode('render', '9', 'next');
        self::assertSame([['render', '9']], $executor->revoked);
        self::assertSame($initial, $placement->registry()->get('render:9'));
        self::assertSame([], $mesh->sent);
        $executor->releaseStopped();
        self::assertSame([], $mesh->sent);
        if ($targetLost) {
            $mesh->linked = [];
            unset($mesh->capabilities['next']);
        }
        $mesh->releaseRtWritesAnswered();
        if ($targetLost) {
            self::assertSame([], $mesh->sent);
            self::assertSame(PlacementState::Unplaced, $placement->registry()->get('render:9')->state);
            $executor->holdStopped = false;
            $placement->tick(hrtime(true) / 1e9 + 10);
            self::assertSame(self::SELF, $placement->registry()->get('render:9')->nodeId);
        } else {
            self::assertCount(1, $mesh->sent);
            self::assertSame('next', $mesh->sent[0][0]);
            self::assertInstanceOf(PeerPlaceAgentDTO::class, $mesh->sent[0][1]);
            self::assertTrue($initial->placementId->precedes($mesh->sent[0][1]->placementId));
        }
    }

    public static function managedMoveTargets(): iterable
    {
        yield 'target survives stop' => [false];
        yield 'target lost during stop' => [true];
    }

    public function testSelfFenceBlocksItsOwnRebuildUntilTheLastPeerAnswer(): void
    {
        $mesh = new FakePlacementMesh(
            [self::SELF => [self::SLOTS], 'next' => [self::SLOTS]], linked: ['next'], online: ['next'],
        );
        $executor = new FakePlacementExecutor();
        $placement = new ClusterPlacement(self::SELF, $mesh, $executor, slaveWorkGraceMs: 500);
        $order = new PlacementId('previous', 1, 1);
        $placement->onPlaceAgent('previous', new PeerPlaceAgentDTO('render', '9', $order));
        $executor->holdStopped = true;
        $mesh->holdRtWritesAnswered = true;
        $placement->onPlacementRelease('previous', 1000.0);
        $placement->tick(1000.6);
        $placement->onPlacementQuery('next', new PeerPlacementQueryDTO('first-rebuild', 2));
        self::assertSame([], array_filter(array_column($mesh->sent, 1),
            static fn(PeerDTO $frame): bool => $frame instanceof PeerPlacementReportDTO));

        $placement->onBecameLeader(1001.0, 3);
        FakePlacementMesh::reply($placement, 'next', new PeerPlacementReportDTO([], new PlacementSnapshot('previous', 1, 1, [
            new PlacementRecord('render', '9', self::SELF, PlacementState::Started, placementId: $order, workerStarted: true),
        ])));
        $placement->placeAgentOnNode('render', '9', 'next');
        $placement->tick(1001.0);
        self::assertSame([], $this->placeFrames($mesh));
        $executor->releaseStopped();
        $placement->tick(1001.0);
        self::assertSame([], $this->placeFrames($mesh));
        $mesh->releaseRtWritesAnswered();
        $placement->tick(1001.0);
        self::assertSame([['next', 'render', '9']], $this->placeFrames($mesh));
        self::assertSame([], array_filter(array_column($mesh->sent, 1),
            static fn(PeerDTO $frame): bool => $frame instanceof PeerPlacementReportDTO));
    }

    /**
     * A self-fenced node reports its emptied set only once every revoked instance is gone and then
     * every active link to a peer answered the RT writes handed it before (HIL-2108).
     */
    public function testASelfFenceReportsTheEmptiedSetOnlyBehindEveryStopAndThePeersAnswers(): void
    {
        $mesh = new FakePlacementMesh(['slave' => [self::SLOTS]], linked: ['leader']);
        $executor = new FakePlacementExecutor(workerId: 5);
        $placement = new ClusterPlacement('slave', $mesh, $executor, null, failoverGraceMs: 1000, slaveWorkGraceMs: 500);
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9', new PlacementId('leader', 0, 1)));
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '10', new PlacementId('leader', 0, 2)));
        $executor->holdStopped = true;
        $mesh->holdRtWritesAnswered = true;

        $placement->noteNodeOffline('leader', 1000.0);
        $placement->tick(1000.6);
        $this->assertSame([['render', '9'], ['render', '10']], $executor->revoked);
        $this->assertSame([], $this->placementReports($mesh));

        $first = array_shift($executor->heldStopped);
        $this->assertNotNull($first);
        $first();
        $this->assertCount(1, $mesh->heldRtWritesAnswered, 'The first stop can settle its own writes');
        $mesh->releaseRtWritesAnswered();
        $this->assertSame([], $this->placementReports($mesh), 'The second worker still owes its stop');

        $executor->releaseStopped();
        $this->assertCount(1, $mesh->heldRtWritesAnswered);
        $this->assertSame([], $this->placementReports($mesh), 'Gone instances alone do not release the report');

        $mesh->releaseRtWritesAnswered();
        $reports = $this->placementReports($mesh);
        $this->assertCount(1, $reports);
        $this->assertSame([], $reports[0]->agents);
    }

    public function testSelfFenceContinuesAfterARevokeRaisesWithAWorkerStopStillPending(): void
    {
        $mesh = new FakePlacementMesh(['slave' => [self::SLOTS]], linked: ['next']);
        $executor = new FakePlacementExecutor();
        $placement = new ClusterPlacement('slave', $mesh, $executor, slaveWorkGraceMs: 500);
        $placement->onPlaceAgent('previous', new PeerPlaceAgentDTO('render', '9', new PlacementId('previous', 1, 1)));
        $placement->onPlaceAgent('previous', new PeerPlaceAgentDTO('render', '10', new PlacementId('previous', 1, 2)));
        $executor->holdStopped = true;
        $executor->raiseAfterRevoke = new InvalidFormatException('stop sink raised after the worker was asked');
        $mesh->holdRtWritesAnswered = true;
        $placement->onPlacementRelease('previous', hrtime(true) / 1e9 - 2.0);
        $loop = new EventLoop();
        $placement->attachEventLoop($loop);
        try {
            $loop->tick();
            $placement->dispatchReady();
            $query = new PeerPlacementQueryDTO('after-fence', 2);
            $placement->onPlacementQuery('next', $query);
            self::assertSame([['render', '9'], ['render', '10']], $executor->revoked);
            self::assertCount(2, $executor->heldStopped);
            $mesh->sent = [];
            $first = array_shift($executor->heldStopped);
            $first();
            $mesh->releaseRtWritesAnswered();
            $loop->tick();
            $placement->dispatchReady();
            self::assertSame([], $mesh->sent, 'The second worker still owes its stop');
            $executor->releaseStopped();
            $loop->tick();
            $placement->dispatchReady();
            self::assertSame([], $mesh->sent, 'The second stop still owes its RT answer');
            $mesh->releaseRtWritesAnswered();
            self::assertSame([], $mesh->sent, 'The full reply waits for the ready turn');
            $loop->tick();
            $placement->dispatchReady();
            self::assertEquals([['next', new PeerPlacementReportDTO([], queryId: $query->queryId)]], $mesh->sent);
        } finally {
            $placement->detachEventLoop();
            $loop->close();
        }
    }

    public function testDeferredPlacementReportsUseReadyTurnsAfterTheExactStopReceiptEvenWhenOneFails(): void
    {
        $mesh = new FakePlacementMesh(['slave' => [self::SLOTS]], linked: ['leader']);
        $executor = new FakePlacementExecutor();
        $placement = new ClusterPlacement('slave', $mesh, $executor);
        $loop = new EventLoop();
        $placement->attachEventLoop($loop);
        try {
            $order = new PlacementId('leader', 1, 1);
            $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9', $order));
            $executor->holdStopped = true;
            $mesh->holdRtWritesAnswered = true;
            $stop = new PeerStopAgentDTO('render', '9', 1, 'stop', $order);
            $placement->onStopAgent('leader', $stop);
            foreach (['first', 'failed', 'last'] as $queryId) {
                $placement->onPlacementQuery('leader', new PeerPlacementQueryDTO($queryId, 1));
            }
            $mesh->sent = [];
            $mesh->refusedReport = 'failed';
            $loop->tick();
            $placement->dispatchReady();
            self::assertSame([], $mesh->sent);
            $executor->releaseStopped();
            self::assertSame([], $mesh->sent);
            $mesh->releaseRtWritesAnswered();
            self::assertEquals([['leader', $stop->stoppedStatus()]], $mesh->sent);

            $loop->tick();
            $placement->dispatchReady();
            self::assertCount(2, $mesh->sent);
            self::assertSame('first', $mesh->sent[1][1]->queryId);

            $betweenRan = false;
            $between = $loop->createReadyWork(static function () use ($mesh, &$betweenRan): void {
                self::assertCount(2, $mesh->sent);
                $betweenRan = true;
            });
            $between->markReady();
            $loop->tick();
            $placement->dispatchReady();
            self::assertTrue($betweenRan);
            self::assertNull($mesh->refusedReport);
            self::assertCount(2, $mesh->sent);
            self::assertTrue($placement->hasReadyWork());

            $loop->tick();
            $placement->dispatchReady();
            self::assertCount(3, $mesh->sent);
            self::assertSame('last', $mesh->sent[2][1]->queryId);
            self::assertFalse($placement->hasReadyWork());
        } finally {
            $placement->detachEventLoop();
            $loop->close();
        }
    }

    /**
     * On this node as the host, a restart elsewhere and an explicit stop are confirmed only once
     * the stopped instance is gone and then every active link to a peer answered (HIL-2108).
     */
    public function testARestartElsewhereAndAStopHereConfirmOnlyBehindTheGoneInstanceAndThePeersAnswers(): void
    {
        $mesh = new FakePlacementMesh(
            [self::SELF => [self::SLOTS], 'node-b' => [self::SLOTS]], linked: ['node-b'], online: [self::SELF, 'node-b'],
        );
        $executor = new FakePlacementExecutor();
        $placement = new ClusterPlacement(self::SELF, $mesh, $executor);
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([]));
        $placement->tick(1000.0);
        $placement->placeAgentOnNode('render', '9', self::SELF);
        $placement->placeAgentOnNode('render', '10', self::SELF);
        $order = $placement->registry()->get('render:10')->placementId;
        $executor->holdStopped = true;
        $mesh->holdRtWritesAnswered = true;

        $placement->restartElsewhere('render', '9', self::SELF, 'node-b');
        $placement->stopAgentOnNode('render', '10', self::SELF);
        $executor->releaseStopped();
        $this->assertSame([], $this->placeFrames($mesh), 'A gone instance alone does not let the restart move on');
        $this->assertSame(self::SELF, $placement->registry()->get('render:9')?->nodeId);
        $this->assertEquals(
            new PlacementRecord('render', '10', self::SELF, PlacementState::Stopped, placementId: $order),
            $placement->registry()->get('render:10'),
            'A gone instance alone does not confirm the stop',
        );

        $mesh->releaseRtWritesAnswered();
        $this->assertSame([['node-b', 'render', '9']], $this->placeFrames($mesh));
        $this->assertEquals(
            new PlacementRecord('render', '10', null, PlacementState::Stopped, placementId: $order),
            $placement->registry()->get('render:10'),
        );
    }

    /**
     * A rebuild query transfers the placement picture and cancels isolation from the old leader.
     */
    public function testARebuildQueryFromANewLeaderCallsOffTheFenceArmedAgainstTheOldOne(): void
    {
        $logFile = $this->captureLog();
        $mesh = new FakePlacementMesh(['slave' => [self::SLOTS]], linked: ['leader', 'leader-b']);
        $executor = new FakePlacementExecutor(workerId: 5);
        $placement = new ClusterPlacement('slave', $mesh, $executor, null, slaveWorkGraceMs: 500);
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9', new PlacementId('leader', 0, 1)));

        $placement->noteNodeOffline('leader', 1000.0);
        $placement->onPlacementQuery('leader-b', new PeerPlacementQueryDTO('query', 1));
        $placement->tick(1000.6);

        $this->assertSame([], $executor->revoked);
        $this->assertSame([
            "Self-fence armed: placing leader 'leader' went offline, 1 placed agent(s) stop in 0.5s unless it returns",
            "Self-fence called off: leader 'leader-b' took over this node's placements from 'leader'",
        ], $this->selfFenceLines($logFile));
    }

    /**
     * After a rebuild, only losing the new owner of the placement picture arms the fence.
     */
    public function testANodeTakenOverByANewLeaderFencesAgainstItAndNotTheOldOne(): void
    {
        $mesh = new FakePlacementMesh(['slave' => [self::SLOTS]], linked: ['leader', 'leader-b']);
        $executor = new FakePlacementExecutor(workerId: 5);
        $placement = new ClusterPlacement('slave', $mesh, $executor, null, slaveWorkGraceMs: 500);
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9', new PlacementId('leader', 0, 1)));
        $placement->onPlacementQuery('leader-b', new PeerPlacementQueryDTO('query', 1));

        $placement->noteNodeOffline('leader', 1000.0);
        $placement->tick(1000.6);
        $this->assertSame([], $executor->revoked, 'The old leader no longer owns this node');

        $placement->noteNodeOffline('leader-b', 1001.0);
        $placement->tick(1001.6);
        $this->assertSame([['render', '9']], $executor->revoked, 'The new owner is the fence target');
    }

    /**
     * A placement from a new leader transfers ownership and cancels the old leader's deadline.
     */
    public function testANewLeaderPlacingOntoTheNodeCallsOffTheFenceArmedAgainstTheOldOne(): void
    {
        $mesh = new FakePlacementMesh(['slave' => [self::SLOTS]], linked: ['leader', 'leader-b']);
        $executor = new FakePlacementExecutor(workerId: 5);
        $placement = new ClusterPlacement('slave', $mesh, $executor, null, slaveWorkGraceMs: 500);
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9', new PlacementId('leader', 0, 1)));

        $placement->noteNodeOffline('leader', 1000.0);
        $placement->onPlaceAgent('leader-b', new PeerPlaceAgentDTO('render', '7', new PlacementId('leader-b', 1, 2)));
        $placement->tick(1000.6);

        $this->assertSame([], $executor->revoked);
    }

    /**
     * Promotion cancels a follower fence and prevents the leader from fencing against any peer.
     */
    public function testANodeThatBecomesLeaderFencesAgainstNobody(): void
    {
        $logFile = $this->captureLog();
        $mesh = new FakePlacementMesh(['slave' => [self::SLOTS]], linked: ['leader']);
        $executor = new FakePlacementExecutor(workerId: 5);
        $placement = new ClusterPlacement('slave', $mesh, $executor, null, slaveWorkGraceMs: 500);
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9', new PlacementId('leader', 0, 1)));

        $placement->noteNodeOffline('leader', 1000.0);
        $placement->onBecameLeader(1000.0);
        $placement->tick(1000.6);
        $placement->noteNodeOffline('leader', 1001.0);

        $this->assertSame([], $executor->revoked);
        $this->assertSame([
            "Self-fence armed: placing leader 'leader' went offline, 1 placed agent(s) stop in 0.5s unless it returns",
            'Self-fence called off: this node leads now',
        ], $this->selfFenceLines($logFile));
    }

    /**
     * A leader answers a rival's rebuild query without accepting that rival as its own leader.
     */
    public function testALeadingNodeTakesNoLeaderFromARivalsQuery(): void
    {
        $logFile = $this->captureLog();
        $mesh = new FakePlacementMesh([], linked: ['rival']);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor());
        $placement->onBecameLeader(1000.0);

        $placement->onPlacementQuery('rival', new PeerPlacementQueryDTO('query', 0));
        $placement->noteNodeOffline('rival', 1000.0);

        [$nodeId, $frame] = $mesh->sent[array_key_last($mesh->sent)];
        $this->assertSame('rival', $nodeId);
        $this->assertInstanceOf(PeerPlacementReportDTO::class, $frame);
        $this->assertSame([], $this->selfFenceLines($logFile));
    }

    /**
     * A placement accepted while the agent waits for a worker is not in the hosted set, and used
     * to leave the node unfenced if the placing leader vanished in those seconds (HIL-1041).
     */
    public function testADeferredPlacementArmsTheSelfFenceWhenThePlacingLeaderIsLost(): void
    {
        $mesh = new FakePlacementMesh(['slave' => [self::SLOTS]], linked: ['leader']);
        $executor = new FakePlacementExecutor();
        $executor->waitsForWorker = true;
        $placement = new ClusterPlacement('slave', $mesh, $executor, null, failoverGraceMs: 1000, slaveWorkGraceMs: 500);

        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', null, new PlacementId('leader', 0, 1)));
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
        $placement->noteAgentStarted('render', null, placementId: $placement->localPlacementId('render'));
        $this->assertSame([], $mesh->sent, 'A fenced deferred placement does not answer the lost leader');
    }

    public function testSlaveCancelsSelfFenceWhenThePlacingLeaderReturnsInTime(): void
    {
        $mesh = new FakePlacementMesh(['slave' => [self::SLOTS]], linked: ['leader']);
        $executor = new FakePlacementExecutor(workerId: 5);
        $placement = new ClusterPlacement('slave', $mesh, $executor, null, slaveWorkGraceMs: 500);
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9', new PlacementId('leader', 0, 1)));

        $placement->noteNodeOffline('leader', 1000.0);
        $placement->noteNodeOnline('leader', 1000.2);
        $placement->tick(1000.9);

        $this->assertSame([], $executor->revoked, 'A leader back before the grace leaves the slave running');
    }

    public function testArmingTheSelfFenceIsLoggedWithTheGraceAndTheAgentCount(): void
    {
        $logFile = $this->captureLog();
        $mesh = new FakePlacementMesh(['slave' => [self::SLOTS]], linked: ['leader']);
        $placement = new ClusterPlacement('slave', $mesh, new FakePlacementExecutor(workerId: 5), null, slaveWorkGraceMs: 500);
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9', new PlacementId('leader', 0, 1)));

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
        $mesh = new FakePlacementMesh(['slave' => [self::SLOTS]], linked: ['leader']);
        $placement = new ClusterPlacement('slave', $mesh, new FakePlacementExecutor(workerId: 5), null, slaveWorkGraceMs: 500);
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9', new PlacementId('leader', 0, 1)));

        $placement->noteNodeOnline('leader', 999.0);
        $placement->noteNodeOffline('leader', 1000.0);
        $placement->noteNodeOnline('leader', 1000.2);
        $placement->noteNodeOnline('leader', 1000.3);

        $this->assertSame([
            "Self-fence armed: placing leader 'leader' went offline, 1 placed agent(s) stop in 0.5s unless it returns",
            "Self-fence disarmed: placing leader 'leader' is back before the grace elapsed",
        ], $this->selfFenceLines($logFile), 'A return with no fence armed announces no disarming');
    }

    /**
     * A leader cut off from the majority carries what it was placed with as a follower and kept on
     * winning, and nobody in a minority can take it over (HIL-1217). The coordinator fires the
     * quorum transition before its leader tick steps down, so the fence is armed while this node
     * still leads, and the step-down after it must not disarm it.
     */
    public function testALeaderThatLosesItsQuorumFencesTheWorkItHosts(): void
    {
        $logFile = $this->captureLog();
        $mesh = new FakePlacementMesh([self::SELF => [self::SLOTS]], online: [self::SELF]);
        $executor = new FakePlacementExecutor();
        $placement = new ClusterPlacement(self::SELF, $mesh, $executor, null, failoverGraceMs: 1000, slaveWorkGraceMs: 500);
        $placement->onBecameLeader(1000.0);
        $placement->placeAgentOnNode('render', '9', self::SELF);

        $placement->noteQuorumLost(1000.0);
        $placement->onLostLeadership();
        $placement->tick(1000.4);
        $this->assertSame([], $executor->revoked, 'The work runs on through the grace');

        $placement->tick(1000.6);
        $this->assertSame([['render', '9']], $executor->revoked, 'The cut-off leader stops what it hosts');
        $this->assertStringContainsString('Self-fence: quorum lost, stopping 1 placed agent(s)', (string)file_get_contents($logFile));
        $this->assertSame(
            ['Self-fence armed: quorum lost, 1 placed agent(s) stop in 0.5s unless a leader takes them over'],
            $this->selfFenceLines($logFile),
        );
    }

    /**
     * A master that does not lead fences on a lost quorum too, whichever leader placed its work: in
     * a minority that leader has no quorum either, so its return says nothing.
     */
    public function testAMasterThatLosesItsQuorumFencesWhateverItAnsweredTo(): void
    {
        $mesh = new FakePlacementMesh(['master' => [self::SLOTS]], linked: ['leader']);
        $executor = new FakePlacementExecutor(workerId: 5);
        $placement = new ClusterPlacement('master', $mesh, $executor, null, failoverGraceMs: 1000, slaveWorkGraceMs: 500);
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9', new PlacementId('leader', 0, 1)));

        $placement->noteQuorumLost(1000.0);
        $placement->noteNodeOffline('leader', 1000.1);
        $placement->noteNodeOnline('leader', 1000.2);
        $placement->tick(1000.6);

        $this->assertSame([['render', '9']], $executor->revoked, 'The leader it answered to coming back does not call the fence off');
    }

    public function testALeaderTakingOverCallsOffTheQuorumFence(): void
    {
        $logFile = $this->captureLog();
        $mesh = new FakePlacementMesh(['master' => [self::SLOTS]], linked: ['leader-a', 'leader-b']);
        $executor = new FakePlacementExecutor(workerId: 5);
        $placement = new ClusterPlacement('master', $mesh, $executor, null, failoverGraceMs: 1000, slaveWorkGraceMs: 500);
        $placement->onPlaceAgent('leader-a', new PeerPlaceAgentDTO('render', '9', new PlacementId('leader-a', 0, 1)));

        $placement->noteQuorumLost(1000.0);
        $placement->onPlacementQuery('leader-b', new PeerPlacementQueryDTO('query', 1));
        $placement->tick(1000.6);

        $this->assertSame([], $executor->revoked, 'A leader that took the placements over holds the work again');
        $this->assertSame([
            'Self-fence armed: quorum lost, 1 placed agent(s) stop in 0.5s unless a leader takes them over',
            "Self-fence called off: leader 'leader-b' took over this node's placements",
        ], $this->selfFenceLines($logFile));
    }

    public function testWinningTheTermCallsOffTheQuorumFence(): void
    {
        $logFile = $this->captureLog();
        $mesh = new FakePlacementMesh(['master' => [self::SLOTS]], linked: ['leader']);
        $executor = new FakePlacementExecutor(workerId: 5);
        $placement = new ClusterPlacement('master', $mesh, $executor, null, failoverGraceMs: 1000, slaveWorkGraceMs: 500);
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9', new PlacementId('leader', 0, 1)));

        $placement->noteQuorumLost(1000.0);
        $placement->onBecameLeader(1000.2);
        $placement->tick(1000.6);

        $this->assertSame([], $executor->revoked, 'A node that leads again keeps its work');
        $this->assertSame([
            'Self-fence armed: quorum lost, 1 placed agent(s) stop in 0.5s unless a leader takes them over',
            'Self-fence called off: this node leads now',
        ], $this->selfFenceLines($logFile));
    }

    public function testNothingHostedArmsNothingOnQuorumLoss(): void
    {
        $logFile = $this->captureLog();
        $executor = new FakePlacementExecutor();
        $placement = new ClusterPlacement('master', new FakePlacementMesh([]), $executor, null, slaveWorkGraceMs: 500);

        $placement->noteQuorumLost(1000.0);
        $placement->tick(1000.6);

        $this->assertSame([], $executor->revoked);
        $this->assertSame([], $this->selfFenceLines($logFile), 'A node with nothing placed has nothing to fence');
    }

    /**
     * A quorum fence is not called off by the return of the leader the node answered to, and
     * that leader may have adopted the work from the report the returning link carried. The
     * fence that fires therefore says what is left - nothing - to whoever is linked, so no leader
     * keeps calling the stopped work started (HIL-1217).
     */
    public function testAFiredFenceReportsTheEmptiedNodeToEveryLinkedNode(): void
    {
        $mesh = new FakePlacementMesh(['master' => [self::SLOTS]], linked: ['leader']);
        $placement = new ClusterPlacement('master', $mesh, new FakePlacementExecutor(workerId: 5), null, slaveWorkGraceMs: 500);
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9', new PlacementId('leader', 0, 1)));

        $placement->noteQuorumLost(1000.0);
        $placement->noteNodeOnline('leader', 1000.2);
        $placement->tick(1000.6);

        $report = $mesh->broadcast[array_key_last($mesh->broadcast)] ?? null;
        $this->assertInstanceOf(PeerPlacementReportDTO::class, $report);
        $this->assertSame([], $report->agents, 'The fenced node runs nothing, and says so');
    }

    /**
     * A leader that stops leading says so to every linked node: a slave cut off with it from the
     * majority knows nothing of a quorum, and would otherwise run its work beside the copy the
     * majority's leader starts (HIL-1287).
     */
    public function testALeaderThatStopsLeadingReleasesEveryLinkedNode(): void
    {
        $logFile = $this->captureLog();
        $mesh = new FakePlacementMesh([], linked: ['slave']);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor());
        $placement->onBecameLeader(1000.0);

        $placement->onLostLeadership();

        $this->assertInstanceOf(PeerPlacementReleaseDTO::class, $mesh->broadcast[array_key_last($mesh->broadcast)] ?? null);
        $this->assertStringContainsString(
            'Placement release: this node no longer leads, telling every linked node',
            (string)file_get_contents($logFile),
        );
        unlink($logFile);
    }

    public function testANodeThatDidNotLeadReleasesNobody(): void
    {
        $mesh = new FakePlacementMesh([], linked: ['leader']);
        $placement = new ClusterPlacement('master', $mesh, new FakePlacementExecutor());

        $placement->onLostLeadership();

        $this->assertSame(
            [],
            array_filter($mesh->broadcast, static fn(PeerDTO $frame): bool => $frame instanceof PeerPlacementReleaseDTO),
            'Only a node that led has anything to release',
        );
    }

    public function testASlaveReleasedByItsLeaderFencesItsWork(): void
    {
        $logFile = $this->captureLog();
        $mesh = new FakePlacementMesh(['slave' => [self::SLOTS]], linked: ['leader']);
        $executor = new FakePlacementExecutor(workerId: 5);
        $placement = new ClusterPlacement('slave', $mesh, $executor, null, failoverGraceMs: 1000, slaveWorkGraceMs: 500);
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9', new PlacementId('leader', 0, 1)));

        $placement->onPlacementRelease('leader', 1000.0);
        $placement->tick(1000.4);
        $this->assertSame([], $executor->revoked, 'The slave keeps working through the grace window');

        $placement->tick(1000.6);
        $this->assertSame([['render', '9']], $executor->revoked, 'The released slave stops its placed agents past the grace');
        $this->assertStringContainsString(
            "Self-fence: placing leader 'leader' stopped leading, stopping 1 placed agent(s)",
            (string)file_get_contents($logFile),
        );
        $this->assertSame(
            ["Self-fence armed: placing leader 'leader' stopped leading, 1 placed agent(s) stop in 0.5s unless a leader takes them over"],
            $this->selfFenceLines($logFile),
        );
    }

    public function testAReleaseFromALeaderTheNodeDoesNotAnswerToChangesNothing(): void
    {
        $logFile = $this->captureLog();
        $mesh = new FakePlacementMesh(['slave' => [self::SLOTS]], linked: ['leader', 'leader-b']);
        $executor = new FakePlacementExecutor(workerId: 5);
        $placement = new ClusterPlacement('slave', $mesh, $executor, null, failoverGraceMs: 1000, slaveWorkGraceMs: 500);
        $placement->onPlaceAgent('leader-b', new PeerPlaceAgentDTO('render', '9', new PlacementId('leader-b', 1, 1)));

        $placement->onPlacementRelease('leader', 1000.0);
        $placement->tick(1000.6);
        $this->assertSame([], $executor->revoked, 'A node this one does not answer to releases nothing here');

        $placement->noteNodeOffline('leader-b', 1001.0);
        $placement->tick(1001.6);
        $this->assertSame([['render', '9']], $executor->revoked, 'The leader it answers to is still the fence target');
        $this->assertSame(
            ["Self-fence armed: placing leader 'leader-b' went offline, 1 placed agent(s) stop in 0.5s unless it returns"],
            $this->selfFenceLines($logFile),
        );
    }

    public function testANewLeaderTakingOverCallsOffTheReleaseFence(): void
    {
        $logFile = $this->captureLog();
        $mesh = new FakePlacementMesh(['slave' => [self::SLOTS]], linked: ['leader', 'leader-b']);
        $executor = new FakePlacementExecutor(workerId: 5);
        $placement = new ClusterPlacement('slave', $mesh, $executor, null, failoverGraceMs: 1000, slaveWorkGraceMs: 500);
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9', new PlacementId('leader', 0, 1)));

        $placement->onPlacementRelease('leader', 1000.0);
        $placement->onPlacementQuery('leader-b', new PeerPlacementQueryDTO('query', 1));
        $placement->tick(1000.6);

        $this->assertSame([], $executor->revoked, 'A leader that took the placements over holds the work again');
        $this->assertSame([
            "Self-fence armed: placing leader 'leader' stopped leading, 1 placed agent(s) stop in 0.5s unless a leader takes them over",
            "Self-fence called off: leader 'leader-b' took over this node's placements",
        ], $this->selfFenceLines($logFile));
    }

    public function testTheReleasingLeaderWinningAgainCallsOffItsFence(): void
    {
        $logFile = $this->captureLog();
        $mesh = new FakePlacementMesh(['slave' => [self::SLOTS]], linked: ['leader']);
        $executor = new FakePlacementExecutor(workerId: 5);
        $placement = new ClusterPlacement('slave', $mesh, $executor, null, failoverGraceMs: 1000, slaveWorkGraceMs: 500);
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9', new PlacementId('leader', 0, 1)));

        $placement->onPlacementRelease('leader', 1000.0);
        $placement->onPlacementQuery('leader', new PeerPlacementQueryDTO('query', 0));
        $this->assertFalse($placement->acceptsLeaderCommand('leader', 0));
        $placement->onPlacementQuery('leader', new PeerPlacementQueryDTO('new-term', 1));
        $this->assertTrue($placement->acceptsLeaderCommand('leader', 1));
        $placement->tick(1000.6);

        $this->assertSame([], $executor->revoked, 'The former leader leading again takes the placements back');
        $this->assertSame([
            "Self-fence armed: placing leader 'leader' stopped leading, 1 placed agent(s) stop in 0.5s unless a leader takes them over",
            "Self-fence called off: leader 'leader' took over this node's placements",
        ], $this->selfFenceLines($logFile));
    }

    /**
     * The link to a released node's former leader coming back says nothing: that leader leads no
     * more, and it was forgotten with the release.
     */
    public function testTheReleasingLeadersReturnCallsNothingOff(): void
    {
        $logFile = $this->captureLog();
        $mesh = new FakePlacementMesh(['slave' => [self::SLOTS]], linked: ['leader']);
        $executor = new FakePlacementExecutor(workerId: 5);
        $placement = new ClusterPlacement('slave', $mesh, $executor, null, failoverGraceMs: 1000, slaveWorkGraceMs: 500);
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9', new PlacementId('leader', 0, 1)));

        $placement->onPlacementRelease('leader', 1000.0);
        $placement->noteNodeOffline('leader', 1000.1);
        $placement->noteNodeOnline('leader', 1000.2);
        $placement->tick(1000.6);

        $this->assertSame([['render', '9']], $executor->revoked, 'The former leader coming back does not call the fence off');
        $this->assertSame(
            ["Self-fence armed: placing leader 'leader' stopped leading, 1 placed agent(s) stop in 0.5s unless a leader takes them over"],
            $this->selfFenceLines($logFile),
        );
    }

    public function testAReleasedNodeHostingNothingArmsNothing(): void
    {
        $logFile = $this->captureLog();
        $mesh = new FakePlacementMesh([], linked: ['leader']);
        $placement = new ClusterPlacement('slave', $mesh, new FakePlacementExecutor(), null, slaveWorkGraceMs: 500);
        $placement->onPlacementQuery('leader', new PeerPlacementQueryDTO('query', 0));
        $mesh->sent = [];

        $placement->onPlacementRelease('leader', 1000.0);
        $placement->tick(1000.6);

        $this->assertSame([], $this->selfFenceLines($logFile), 'A node with nothing placed has nothing to fence');
        $this->assertSame([], $mesh->sent);
    }

    /**
     * A master in a minority has fenced on its lost quorum already and forgotten its leader, so the
     * release that leader sends next moves neither the deadline nor what the firing line names.
     */
    public function testAReleaseKeepsTheQuorumFenceItsDeadlineAndCause(): void
    {
        $logFile = $this->captureLog();
        $mesh = new FakePlacementMesh(['master' => [self::SLOTS]], linked: ['leader']);
        $executor = new FakePlacementExecutor(workerId: 5);
        $placement = new ClusterPlacement('master', $mesh, $executor, null, failoverGraceMs: 1000, slaveWorkGraceMs: 500);
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9', new PlacementId('leader', 0, 1)));

        $placement->noteQuorumLost(1000.0);
        $placement->onPlacementRelease('leader', 1000.3);
        $placement->tick(1000.6);

        $this->assertSame([['render', '9']], $executor->revoked, 'The quorum fence fires on its own deadline');
        $this->assertStringContainsString('Self-fence: quorum lost, stopping 1 placed agent(s)', (string)file_get_contents($logFile));
        $this->assertSame(
            ['Self-fence armed: quorum lost, 1 placed agent(s) stop in 0.5s unless a leader takes them over'],
            $this->selfFenceLines($logFile),
        );
    }

    public function testADeferredPlacementIsFencedOnRelease(): void
    {
        $mesh = new FakePlacementMesh(['slave' => [self::SLOTS]], linked: ['leader']);
        $executor = new FakePlacementExecutor();
        $executor->waitsForWorker = true;
        $placement = new ClusterPlacement('slave', $mesh, $executor, null, failoverGraceMs: 1000, slaveWorkGraceMs: 500);
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', null, new PlacementId('leader', 0, 1)));

        $placement->onPlacementRelease('leader', 1000.0);
        $placement->tick(1000.6);

        $this->assertSame([['render', null]], $executor->revoked, 'A placement still waiting for a worker is fenced too');
    }

    /**
     * A fresh leader knows only what it hosts itself until the others report. An agent it has no
     * record of may still run on one of them, so it is not placed until every node online has
     * said what it runs (HIL-1217).
     */
    public function testAFreshLeaderPlacesNothingUnrecordedUntilEveryOnlineNodeReports(): void
    {
        $logFile = $this->captureLog();
        $mesh = new FakePlacementMesh(
            capabilities: ['node-a' => [self::SLOTS], 'node-b' => [self::SLOTS]],
            linked: ['node-a', 'node-b'],
            online: [self::SELF, 'node-a', 'node-b'],
        );
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor());
        $placement->onBecameLeader(1000.0);

        FakePlacementMesh::reply($placement, 'node-a', new PeerPlacementReportDTO([]));
        $this->assertNull($placement->placeAgentOnBestNode('render', '9'), 'node-b may still run it');
        $this->assertSame(0, $placement->registry()->count());

        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([]));
        $placement->tick(1000.1);
        $this->assertNotNull($placement->placeAgentOnBestNode('render', '9'), 'Every node has reported');

        $lines = (string)file_get_contents($logFile);
        unlink($logFile);
        $this->assertStringContainsString(
            'Placement rebuild: 2 node(s) to account for their agents before new work is placed: node-a, node-b',
            $lines,
        );
        $this->assertStringContainsString('Placement rebuild settled: every node accounted for its agents', $lines);
        $this->assertStringNotContainsString("No capable node to place agent 'render:9'", $lines, 'A held placement is not a missing node');
    }

    /**
     * A node that went away moments before the term may still run its work until its own fence
     * fires, so the rebuild waits out its failover grace from the moment it left.
     */
    public function testANodeThatWentAwayRecentlyIsWaitedForAFailoverGrace(): void
    {
        $mesh = new FakePlacementMesh(
            capabilities: ['node-b' => [self::SLOTS]],
            linked: ['node-b'],
            online: [self::SELF, 'node-b'],
        );
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(), null, failoverGraceMs: 1000);
        $placement->noteNodeOffline('old', 1000.0);
        $placement->onBecameLeader(1000.2);
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([]));

        $placement->tick(1000.9);
        $this->assertNull($placement->placeAgentOnBestNode('render', '9'), 'The node that left may still run it');

        $placement->tick(1001.0);
        $this->assertSame('node-b', $placement->placeAgentOnBestNode('render', '9'));
    }

    /**
     * An addressed agent is held rather than refused: a not-placed verdict would fail the frame
     * that addressed it, while the agent may simply be running on a node that has not reported.
     */
    public function testAnAddressedAgentWaitsForTheRebuildAndIsAnsweredAfter(): void
    {
        $mesh = new FakePlacementMesh(
            capabilities: ['node-b' => [self::SLOTS]],
            linked: ['node-b', 'node-c'],
            online: [self::SELF, 'node-b'],
        );
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor());
        $placement->onBecameLeader(1000.0);
        $mesh->sent = [];

        $placement->onPlacementRequest('node-c', new PeerPlacementRequestDTO('render', '9', 0, 'ask'));
        $this->assertSame([], $mesh->sent, 'Neither a placement nor a verdict while the rebuild holds');

        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([]));
        $placement->tick(1000.1);
        [$nodeId, $frame] = $mesh->sent[0];
        $this->assertSame('node-b', $nodeId);
        $this->assertInstanceOf(PeerPlaceAgentDTO::class, $frame);

        FakePlacementMesh::status($placement, 'node-b', PeerAgentStatusDTO::failed('render', '9', 'no worker'));
        $verdict = $mesh->sent[array_key_last($mesh->sent)];
        $this->assertSame('node-c', $verdict[0], 'The node that asked is answered from the placement made after the rebuild');
        $this->assertInstanceOf(PeerPlacementVerdictDTO::class, $verdict[1]);
    }

    /**
     * A known record still waits for the complete donor import before reconciliation retries it.
     */
    public function testReconcileWaitsForTheInheritanceBarrier(): void
    {
        $mesh = new FakePlacementMesh(
            capabilities: ['node-a' => [self::SLOTS], 'node-b' => [self::SLOTS]],
            linked: ['node-a', 'node-b'],
            online: [self::SELF, 'node-a', 'node-b'],
        );
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor());
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 'node-a', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')]));
        $placement->onPeerHandshaked('node-a');
        $mesh->sent = [];

        FakePlacementMesh::reply($placement, 'node-a', new PeerPlacementReportDTO([]));

        $this->assertSame([], $mesh->sent, 'node-b may still carry a newer cancellation');
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([]));
        $this->assertSame([], $mesh->sent, 'The last report is not the import barrier');
        $placement->tick(1000.0);
        $this->assertCount(1, $mesh->sent, 'The missing agent is placed after the complete import');
        $this->assertInstanceOf(PeerPlaceAgentDTO::class, $mesh->sent[0][1]);
    }

    public function testFailoverWaitsForTheInheritanceBarrier(): void
    {
        $mesh = new FakePlacementMesh(
            capabilities: ['node-a' => [self::SLOTS], 'node-b' => [self::SLOTS]],
            linked: ['node-a', 'node-b'],
            online: [self::SELF, 'node-a', 'node-b'],
        );
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(), null, failoverGraceMs: 500);
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 'node-a', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')]));
        $mesh->sent = [];

        $mesh->online = [self::SELF, 'node-b'];
        $placement->noteNodeOffline('node-a', 1000.0);
        $placement->tick(1000.6);

        $this->assertSame([], $mesh->sent, 'A known host cannot bypass an unfinished donor import');
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([]));
        $placement->tick(1000.6);
        $this->assertSame('node-b', $mesh->sent[0][0] ?? null, 'Failover moves the known agent after inheritance');
        $this->assertInstanceOf(PeerPlaceAgentDTO::class, $mesh->sent[0][1]);
    }

    public function testLeaderReconcilesARejoinReportByStoppingAMovedAgent(): void
    {
        $mesh = new FakePlacementMesh([], linked: ['node-b', 'node-c']);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor());
        $placement->onBecameLeader(1000.0);
        // The leader already re-placed render:9 onto node-c while node-b was gone.
        FakePlacementMesh::reply($placement, 'node-c', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')]));
        $mesh->sent = [];

        // node-b rejoins reporting it still hosts the moved agent.
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')]));

        [$nodeId, $frame] = $mesh->sent[0];
        $this->assertSame('node-b', $nodeId);
        $this->assertInstanceOf(PeerStopAgentDTO::class, $frame, 'The returning node is told to stop the moved agent');
        $this->assertSame('node-c', $placement->registry()->get('render:9')?->nodeId, 'The leader-owned placement is unchanged');
    }

    public function testRejoinReportSendsHostedAgentsAndANonLeaderIgnoresReports(): void
    {
        $mesh = new FakePlacementMesh(['slave' => [self::SLOTS]], linked: ['leader']);
        $placement = new ClusterPlacement('slave', $mesh, new FakePlacementExecutor(workerId: 5));
        $placement->onPlaceAgent('leader', new PeerPlaceAgentDTO('render', '9', new PlacementId('leader', 0, 1)));
        $mesh->sent = [];

        // On rejoin the node reports what it still hosts...
        $placement->onPeerHandshaked('leader');
        [$nodeId, $report] = $mesh->sent[0];
        $this->assertSame('leader', $nodeId);
        $this->assertInstanceOf(PeerPlacementReportDTO::class, $report);
        $this->assertSame('render', $report->agents[0]->agentType);

        // ...but a non-leader that receives a report folds nothing into its inert view.
        FakePlacementMesh::reply($placement, 'other', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('ghost', null)]));
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
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')]));
        FakePlacementMesh::reply($placement, 'node-c', new PeerPlacementReportDTO([]));
        $placement->tick(1000.0);
        $mesh->sent = [];

        // node-b returns as a fresh process and says it hosts nothing.
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([]));

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
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')]));
        FakePlacementMesh::reply($placement, 'node-c', new PeerPlacementReportDTO([
            new PeerPlacedAgentEntry('chat', '1'),
            new PeerPlacedAgentEntry('chat', '2'),
        ]));

        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([]));

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
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([]));
        $placement->tick(1000.0);
        $placement->placeAgentOnNode('render', '9', 'node-b');
        $mesh->sent = [];

        // The report may well have crossed the place frame on the wire.
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([]));

        $record = $placement->registry()->get('render:9');
        $this->assertSame(PlacementState::Placing, $record?->state, 'A placement still in flight is not taken back');
        $this->assertSame('node-b', $record?->nodeId);
        $this->assertSame([], $mesh->sent, 'Nothing is re-placed on account of a frame that has not landed yet');
    }

    public function testARefusedRecordSurvivesAReportThatDoesNotNameIt(): void
    {
        $mesh = new FakePlacementMesh(['node-b' => [self::SLOTS]], linked: ['node-b'], online: [self::SELF, 'node-b']);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor());
        $placement->onBecameLeader(1000.0);
        $placement->refusePlacement('render', '9', 'node-b');
        $mesh->sent = [];

        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([]));

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
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([
            new PeerPlacedAgentEntry('render', '9'),
            new PeerPlacedAgentEntry('chat', '1'),
        ]));
        $mesh->sent = [];

        // A flap that changed nothing: the node comes back hosting exactly what it hosted.
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([
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
            capabilities: ['node-b' => ['worker', 'cpu=2', self::SLOTS], 'node-c' => ['worker', 'cpu=8', self::SLOTS]],
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
            capabilities: ['node-b' => ['worker', 'cpu=4', self::SLOTS], 'node-c' => ['worker', 'cpu=4', self::SLOTS]],
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
        $this->assertSame(1, $placement->registry()->count());
        $this->assertSame(PlacementState::Unplaced, $placement->registry()->get('render')?->state);
        $this->assertNull($placement->registry()->get('render')?->nodeId);
    }

    public function testHeldCapacitySendsTheNextPlacementElsewhereUntilNoNodeHasRoom(): void
    {
        $mesh = new FakePlacementMesh(
            capabilities: ['node-b' => ['worker', 'ram=4', self::SLOTS], 'node-c' => ['worker', 'ram=4', self::SLOTS]],
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
        $mesh = new FakePlacementMesh(['node-b' => ['ram=3', self::SLOTS]], linked: ['node-b'], online: [self::SELF, 'node-b']);
        $cost = ResourceProfile::costs(['ram' => 3.0]);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(profile: $cost));
        $placement->placeAgentOnNode('render', '1', 'node-b');
        $this->assertNull($placement->placeAgentOnBestNode('render', '2'));

        $placement->stopAgentOnNode('render', '1', 'node-b');

        $this->assertSame('node-b', $placement->placeAgentOnBestNode('render', '2'), 'The stop released the reservation');
    }

    public function testAnAgentDegradedToUnplacedHoldsNothing(): void
    {
        $mesh = new FakePlacementMesh(['node-b' => ['ram=3', self::SLOTS]], linked: ['node-b'], online: [self::SELF, 'node-b']);
        $cost = ResourceProfile::costs(['ram' => 3.0]);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(profile: $cost), null, failoverGraceMs: 500);
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '1')]));
        $placement->tick(1000.0);

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
        $mesh = new FakePlacementMesh(['node-b' => ['ram=3', self::SLOTS]], linked: ['node-b'], online: [self::SELF, 'node-b']);
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
        $this->assertSame(1, $placement->registry()->count());
        $this->assertSame(PlacementState::Unplaced, $placement->registry()->get('render:1')?->state);
        $this->assertNull($placement->registry()->get('render:1')?->nodeId);
    }

    public function testANamedPlacementBeyondTheFreeCapacityIsRefused(): void
    {
        $mesh = new FakePlacementMesh(['node-b' => ['ram=5', self::SLOTS]], linked: ['node-b'], online: [self::SELF, 'node-b']);
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
        $this->assertSame(PlacementState::Unplaced, $placement->registry()->get('render:2')?->state);
        $this->assertNull($placement->registry()->get('render:2')?->nodeId);
    }

    public function testANewLeaderCountsTheCostOfReportedAgents(): void
    {
        // A head count would pick node-b (2 agents against 3); the reported costs leave it full.
        $mesh = new FakePlacementMesh(
            capabilities: ['node-b' => ['ram=4', self::SLOTS], 'node-c' => ['ram=10', self::SLOTS]],
            linked: ['node-b', 'node-c'],
            online: [self::SELF, 'node-b', 'node-c'],
        );
        $cost = ResourceProfile::costs(['ram' => 2.0]);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(profile: $cost));
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([
            new PeerPlacedAgentEntry('render', '1'),
            new PeerPlacedAgentEntry('render', '2'),
        ]));
        FakePlacementMesh::reply($placement, 'node-c', new PeerPlacementReportDTO([
            new PeerPlacedAgentEntry('render', '3'),
            new PeerPlacedAgentEntry('render', '4'),
            new PeerPlacedAgentEntry('render', '5'),
        ]));
        $placement->tick(1000.0);

        $this->assertSame('node-c', $placement->placeAgentOnBestNode('render', '6'));
    }

    public function testFailoverReplacesOntoTheStrongestSurvivingCapableNode(): void
    {
        $mesh = new FakePlacementMesh(
            capabilities: [
                'node-b' => ['worker', 'cpu=1', self::SLOTS],
                'node-c' => ['worker', 'cpu=2', self::SLOTS],
                'node-d' => ['worker', 'cpu=9', self::SLOTS],
            ],
            linked: ['node-b', 'node-c', 'node-d'],
            online: [self::SELF, 'node-b', 'node-c', 'node-d'],
        );
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['worker']), null, failoverGraceMs: 500);
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')]));
        FakePlacementMesh::reply($placement, 'node-c', new PeerPlacementReportDTO([]));
        FakePlacementMesh::reply($placement, 'node-d', new PeerPlacementReportDTO([]));
        $placement->tick(1000.0);
        $mesh->sent = [];

        // node-b dies; two survivors are capable, so best-fit takes the stronger of them.
        $mesh->online = [self::SELF, 'node-c', 'node-d'];
        $placement->noteNodeOffline('node-b', 1000.0);
        $placement->tick(1000.6);

        $this->assertSame('node-d', $mesh->sent[0][0], 'Failover re-places onto the strongest surviving capable node');
        $this->assertSame('node-d', $placement->registry()->get('render:9')?->nodeId);
    }

    public function testANamedPlacementOntoANodeWithoutSlotsIsRefusedBeforeAnythingIsSent(): void
    {
        $mesh = new FakePlacementMesh(['node-b' => ['worker', 'ram=10']], linked: ['node-b'], online: [self::SELF, 'node-b']);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['worker']));

        try {
            $placement->placeAgentOnNode('render', '1', 'node-b');
            $this->fail('An agent with no project cost still needs a slot');
        } catch (PlacementCapabilityException $e) {
            $this->assertSame(
                "Cannot place agent 'render:1' on node 'node-b': insufficient free capacity [slots: needs 1, free 0]",
                $e->getMessage(),
            );
        }

        $this->assertSame([], $mesh->sent);
        $this->assertSame(1, $placement->registry()->count());
        $this->assertSame(PlacementState::Unplaced, $placement->registry()->get('render:1')?->state);
        $this->assertNull($placement->registry()->get('render:1')?->nodeId);
        $this->assertNull($placement->placeAgentOnBestNode('render', '1'), 'Best-fit finds no slot either');
    }

    public function testEveryLivePlacementHoldsOneSlotOnTheLeader(): void
    {
        $mesh = new FakePlacementMesh(['node-b' => ['slots=2']], linked: ['node-b'], online: [self::SELF, 'node-b']);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor());
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([]));
        $placement->tick(1000.0);
        $placement->placeAgentOnNode('render', '1', 'node-b');
        FakePlacementMesh::status($placement, 'node-b', PeerAgentStatusDTO::started('render', '1', 1));
        $placement->placeAgentOnNode('render', '2', 'node-b');
        $mesh->sent = [];

        try {
            $placement->placeAgentOnNode('render', '3', 'node-b');
            $this->fail('A Started and a Placing record fill slots=2');
        } catch (PlacementCapabilityException $e) {
            $this->assertSame(
                "Cannot place agent 'render:3' on node 'node-b': insufficient free capacity [slots: needs 1, free 0]",
                $e->getMessage(),
            );
        }

        $this->assertSame([], $mesh->sent);
        $this->assertSame(PlacementState::Unplaced, $placement->registry()->get('render:3')?->state);
        $this->assertNull($placement->registry()->get('render:3')?->nodeId);

        $placement->placeAgentOnNode('render', '2', 'node-b');
        $this->assertCount(1, $mesh->sent, 'The agent\'s own slot does not count against its re-placement');
    }

    public function testAFailedOrStoppedPlacementGivesItsSlotBack(): void
    {
        $mesh = new FakePlacementMesh(['node-b' => ['slots=1']], linked: ['node-b'], online: [self::SELF, 'node-b']);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor());
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([]));
        $placement->tick(1000.0);
        $placement->placeAgentOnNode('render', '1', 'node-b');
        $this->assertNull($placement->placeAgentOnBestNode('render', '2'));

        FakePlacementMesh::status($placement, 'node-b', PeerAgentStatusDTO::failed('render', '1', 'the node refused it'));
        $this->assertSame('node-b', $placement->placeAgentOnBestNode('render', '2'), 'A Failed record holds nothing');

        FakePlacementMesh::status($placement, 'node-b', PeerAgentStatusDTO::started('render', '2', 1));
        $this->assertNull($placement->placeAgentOnBestNode('render', '3'));
        $placement->stopAgentOnNode('render', '2', 'node-b');
        $this->assertSame('node-b', $placement->placeAgentOnBestNode('render', '3'), 'The stop released the slot');
    }

    public function testANewLeaderCountsTheSlotsOfReportedAgents(): void
    {
        $mesh = new FakePlacementMesh(
            capabilities: ['node-b' => ['slots=2'], 'node-c' => ['slots=3']],
            linked: ['node-b', 'node-c'],
            online: [self::SELF, 'node-b', 'node-c'],
        );
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor());
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([
            new PeerPlacedAgentEntry('render', '1'),
            new PeerPlacedAgentEntry('render', '2'),
        ]));
        FakePlacementMesh::reply($placement, 'node-c', new PeerPlacementReportDTO([
            new PeerPlacedAgentEntry('render', '3'),
            new PeerPlacedAgentEntry('render', '4'),
        ]));
        $placement->tick(1000.0);

        $this->assertSame('node-c', $placement->placeAgentOnBestNode('render', '5'), 'Only node-c has a slot left');
        $this->assertNull($placement->placeAgentOnBestNode('render', '6'), 'Every reported agent holds its slot');
    }

    /**
     * A claim refused because another node already owns the collection takes the agent down over
     * the ordinary stop frame, and leaves a record behind saying it must not come back (HIL-696).
     */
    public function testARefusedClaimStopsTheAgentAndKeepsTheRecord(): void
    {
        $mesh = new FakePlacementMesh([self::SELF => [self::SLOTS], 'gpu-node' => [self::SLOTS]], ['gpu-node'], [self::SELF, 'gpu-node']);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor());
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 'gpu-node', new PeerPlacementReportDTO([]));
        $placement->tick(1000.0);
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

    public function testARefusedPolicyClaimWaitsForAnExactRemoteOrderBeforePeerStop(): void
    {
        $mesh = new FakePlacementMesh(['host' => [self::SLOTS]], linked: ['host']);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor());
        $placement->onBecameLeader(1000.0, 8);

        $placement->refusePlacement('chat', '1', 'host');
        self::assertSame(PlacementState::Refused, $placement->registry()->get('chat:1')?->state);
        self::assertSame([], $mesh->sent);

        $id = new PlacementId(self::SELF, 8, 1);
        $placement->onPlacementReport('host', new PeerPlacementReportDTO([
            new PeerPlacedAgentEntry('chat', '1', placementId: $id),
        ]));
        self::assertCount(1, $mesh->sent);
        self::assertInstanceOf(PeerStopAgentDTO::class, $mesh->sent[0][1]);
        self::assertEquals($id, $mesh->sent[0][1]->placementId);
    }

    public function testFailoverDoesNotResurrectARefusedAgent(): void
    {
        $mesh = new FakePlacementMesh([self::SELF => [self::SLOTS], 'gpu-node' => [self::SLOTS]], ['gpu-node'], [self::SELF, 'gpu-node']);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor());
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 'gpu-node', new PeerPlacementReportDTO([]));
        $placement->tick(1000.0);
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
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 'gpu-node', new PeerPlacementReportDTO([]));
        $placement->tick(1000.0);
        $placement->placeAgentOnNode('chat', '1', 'gpu-node');
        $placement->refusePlacement('chat', '1', 'gpu-node');

        FakePlacementMesh::status($placement, 'gpu-node', PeerAgentStatusDTO::stopped('chat', '1'));

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
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 'gpu-node', new PeerPlacementReportDTO([]));
        $placement->tick(1000.0);
        $placement->placeAgentOnNode('chat', '1', 'gpu-node');
        $placement->refusePlacement('chat', '1', 'gpu-node');
        $mesh->sent = [];

        FakePlacementMesh::reply($placement, 'gpu-node', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('chat', '1')]));

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
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 'gpu-node', new PeerPlacementReportDTO([]));
        $placement->tick(1000.0);
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
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 'gpu-node', new PeerPlacementReportDTO([]));
        FakePlacementMesh::status($placement, 'gpu-node', PeerAgentStatusDTO::started('chat', '1', 1));
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
     * @param PlacementState $state State with no current host
     * @param ?string $nodeId No chosen node, or the previous host
     */
    #[DataProvider('recordsWithoutHosts')]
    public function testRecordsWithoutHostsReserveNoCapacity(PlacementState $state, ?string $nodeId): void
    {
        $mesh = new FakePlacementMesh(['gpu-node' => ['slots=1']], ['gpu-node'], ['gpu-node']);
        $executor = new FakePlacementExecutor();
        $placement = new ClusterPlacement(self::SELF, $mesh, $executor);
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 'gpu-node', new PeerPlacementReportDTO([]));
        $placement->tick(1000.0);
        $record = new PlacementRecord('chat', 'old', $nodeId, $state);
        $placement->registry()->put($record);

        $this->assertSame('gpu-node', $placement->bestNodeFor('chat', 'new'));
        $placement->placeAgentOnNode('chat', 'new', 'gpu-node');

        $this->assertSame($record, $placement->registry()->get('chat:old'));
        $this->assertSame(PlacementState::Placing, $placement->registry()->get('chat:new')?->state);
        $this->assertCount(1, $mesh->sent);
        $this->assertSame('gpu-node', $mesh->sent[0][0]);
        $this->assertInstanceOf(PeerPlaceAgentDTO::class, $mesh->sent[0][1]);
        $this->assertSame([], $executor->executed);
    }

    /**
     * A failover deadline armed while the agent ran must not resurrect a later no-host record.
     *
     * @param PlacementState $state State after the host released the agent
     * @param ?string $nodeId No chosen node, or the previous host
     */
    #[DataProvider('recordsWithoutHosts')]
    public function testAStaleFailoverLeavesRecordsWithoutHostsAlone(PlacementState $state, ?string $nodeId): void
    {
        $mesh = new FakePlacementMesh([self::SELF => [self::SLOTS]], [], [self::SELF, 'unreported-peer']);
        $executor = new FakePlacementExecutor();
        $observer = new FakePlacementObserver();
        $placement = new ClusterPlacement(self::SELF, $mesh, $executor, $observer);
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::status($placement, 'gpu-node', PeerAgentStatusDTO::started('chat', '1', 1));
        $placement->noteNodeOffline('gpu-node', 1000.0);
        $record = new PlacementRecord('chat', '1', $nodeId, $state);
        $placement->registry()->put($record);

        $placement->tick(2000.0);

        $this->assertSame($record, $placement->registry()->get('chat:1'));
        $this->assertSame([], $mesh->sent);
        $this->assertSame([], $executor->executed);
        $this->assertSame([], $observer->degraded);
    }

    /**
     * @param PlacementState $state Previous state with no current host
     * @param ?string $nodeId No chosen node, or the previous host
     */
    #[DataProvider('recordsWithoutHosts')]
    public function testAStartedStatusCanAdoptARecordWithoutAHost(PlacementState $state, ?string $nodeId): void
    {
        $mesh = new FakePlacementMesh([]);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor());
        $placement->onBecameLeader(1000.0);
        $placement->registry()->put(new PlacementRecord('chat', '1', $nodeId, $state));

        FakePlacementMesh::status($placement, 'node-b', PeerAgentStatusDTO::started('chat', '1', 1));

        if ($state === PlacementState::Stopped) {
            $this->assertEquals(new PlacementRecord('chat', '1', $nodeId, PlacementState::Stopped), $placement->registry()->get('chat:1'));
            self::assertCount(1, $mesh->sent);
            [[$target, $stop]] = $mesh->sent;
            self::assertSame('node-b', $target);
            self::assertInstanceOf(PeerStopAgentDTO::class, $stop);
            self::assertSame('chat', $stop->agentType);
            self::assertSame('1', $stop->agentIndex);
            self::assertNull($stop->placementId);
            self::assertSame(0, $stop->authorityTerm);
            return;
        }
        $this->assertSame(PlacementState::Started, $placement->registry()->get('chat:1')?->state);
        $this->assertSame('node-b', $placement->registry()->get('chat:1')?->nodeId);
        $this->assertSame([], $mesh->sent, 'A previous attempt is not a conflicting placement');
    }

    /**
     * @param PlacementState $state Previous state with no current host
     * @param ?string $nodeId No chosen node, or the previous host
     */
    #[DataProvider('recordsWithoutHosts')]
    public function testAReportCanAdoptARecordWithoutAHost(PlacementState $state, ?string $nodeId): void
    {
        $mesh = new FakePlacementMesh([]);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor());
        $placement->onBecameLeader(1000.0);
        $placement->registry()->put(new PlacementRecord('chat', '1', $nodeId, $state));

        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('chat', '1')]));

        if ($state === PlacementState::Stopped) {
            $this->assertEquals(new PlacementRecord('chat', '1', $nodeId, PlacementState::Stopped), $placement->registry()->get('chat:1'));
            self::assertCount(1, $mesh->sent);
            [[$target, $stop]] = $mesh->sent;
            self::assertSame('node-b', $target);
            self::assertInstanceOf(PeerStopAgentDTO::class, $stop);
            self::assertSame('chat', $stop->agentType);
            self::assertSame('1', $stop->agentIndex);
            self::assertNull($stop->placementId);
            self::assertSame(0, $stop->authorityTerm);
            return;
        }
        $this->assertSame(PlacementState::Started, $placement->registry()->get('chat:1')?->state);
        $this->assertSame('node-b', $placement->registry()->get('chat:1')?->nodeId);
        $this->assertSame([], $mesh->sent, 'Only a current placement elsewhere can refuse the report');
    }

    /** A join retry refreshes the diagnosis of an existing no-host record without inventing a host. */
    public function testAJoinRetryKeepsTheNullNodeWhenNoNodeFits(): void
    {
        $mesh = new FakePlacementMesh([self::SELF => []], [], [self::SELF]);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor());
        $placement->onBecameLeader(1000.0);
        $placement->registry()->put(new PlacementRecord(
            'chat', '1', null, PlacementState::Unplaced, 'no nodes', [PlacementRefusal::noOnlineNodes()],
        ));

        $placement->noteNodeOnline(self::SELF, 1001.0);
        $placement->tick(1001.0);

        $record = $placement->registry()->get('chat:1');
        $this->assertNotNull($record);
        $this->assertNull($record->nodeId);
        $this->assertSame(PlacementState::Unplaced, $record->state);
        $this->assertSame('no capable and online node fits the agent', $record->reason);
        $this->assertCount(1, $record->refusals);
        $this->assertTrue(PlacementRefusal::noDeclaredCapacity(self::SELF)->equals($record->refusals[0]));
        $this->assertSame([], $mesh->sent);
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
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([]));
        FakePlacementMesh::reply($placement, 'node-c', new PeerPlacementReportDTO([]));
        $placement->tick(1000.0);
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
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([]));
        FakePlacementMesh::reply($placement, 'node-c', new PeerPlacementReportDTO([]));
        $placement->tick(1000.0);
        $placement->placeAgentOnNode('render', '9', 'node-b');
        $placement->tick(1000.0);
        $placement->tick(1000.6);
        $mesh->sent = [];

        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')]));

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
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([]));
        FakePlacementMesh::reply($placement, 'node-c', new PeerPlacementReportDTO([]));
        $placement->tick(1000.0);
        $placement->placeAgentOnNode('render', '9', 'node-b');
        $placement->tick(1000.0);
        $placement->tick(1000.6);
        $mesh->sent = [];

        // The answer to the question: the node hosts nothing, so the place frame is not in
        // flight, it is lost.
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([]));

        $this->assertInstanceOf(PeerPlaceAgentDTO::class, $mesh->sent[0][1] ?? null, 'An asked Placing is judged like a Started one');
        $this->assertSame(PlacementState::Placing, $placement->registry()->get('render:9')?->state);
    }

    /**
     * @param PlacementState $state Whether the interrupted wait ordered a place or a stop
     * @param bool $pending Whether the original question found an accepted but unfinished place
     */
    #[DataProvider('interruptedAcknowledgements')]
    public function testRejoinSettlesAnInterruptedAcknowledgement(PlacementState $state, bool $pending): void
    {
        $mesh = new FakePlacementMesh(
            capabilities: ['node-b' => ['gpu', self::SLOTS]], linked: ['node-b'], online: ['node-b'],
        );
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['gpu']), placementAckTimeoutMs: 500);
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([]));
        $placement->tick(1000.0);
        $placement->onPeerHandshaked('node-b');
        $beforeOrder = $mesh->takeFixtureQuery('node-b');
        $placement->placeAgentOnNode('render', '9', 'node-b');
        $initialOrder = $placement->registry()->get('render:9')->placementId;
        if ($state === PlacementState::Stopped) {
            $placement->stopAgentOnNode('render', '9', 'node-b');
        }
        $placement->tick(1000.0);
        $placement->onPlacementReport('node-b', new PeerPlacementReportDTO([], queryId: $beforeOrder->queryId));
        self::assertSame($state, $placement->registry()->get('render:9')?->state);
        self::assertSame('node-b', $placement->registry()->get('render:9')?->nodeId);

        $placement->tick(1000.6);
        $firstAck = $mesh->takeFixtureQuery('node-b');
        self::assertInstanceOf(PeerPlacementQueryDTO::class, $firstAck);
        if ($pending) {
            $followerMesh = new FakePlacementMesh(['node-b' => ['gpu', self::SLOTS]], linked: [self::SELF]);
            $executor = new FakePlacementExecutor();
            $executor->waitsForWorker = true;
            $follower = new ClusterPlacement('node-b', $followerMesh, $executor);
            $follower->onPlaceAgent(self::SELF, new PeerPlaceAgentDTO('render', '9', $initialOrder));
            $follower->onPlacementQuery(self::SELF, $firstAck);
            $report = $followerMesh->sent[0][1];
            self::assertInstanceOf(PeerPlacementReportDTO::class, $report);
            self::assertSame(PlacementState::Placing, $report->agents[0]->state);
            $placement->onPlacementReport('node-b', $report);
            self::assertTrue($initialOrder->equals($placement->registry()->get('render:9')?->placementId));
        }

        $placement->noteNodeOffline('node-b', 1000.7);
        $placement->noteNodeOnline('node-b', 1000.8);
        $placement->onPeerHandshaked('node-b');
        $rejoin = $mesh->takeFixtureQuery('node-b');
        $mesh->sent = [];
        $placement->onPlacementReport('node-b', new PeerPlacementReportDTO([], queryId: $firstAck->queryId));
        self::assertSame([], $mesh->sent, 'The superseded link no longer decides the wait');
        self::assertSame('node-b', $placement->registry()->get('render:9')?->nodeId);

        $placement->onPlacementReport('node-b', new PeerPlacementReportDTO([], queryId: $rejoin->queryId));
        $current = $placement->registry()->get('render:9');
        self::assertSame($state, $current?->state);
        if ($state === PlacementState::Stopped) {
            self::assertNull($current->nodeId);
            self::assertSame([], $mesh->sent);
        } else {
            self::assertTrue($initialOrder->precedes($current->placementId));
            self::assertInstanceOf(PeerPlaceAgentDTO::class, $mesh->sent[0][1] ?? null);
        }
    }

    /** @return iterable<string, array{PlacementState, bool}> Lost ACK and pending before rejoin */
    public static function interruptedAcknowledgements(): iterable
    {
        yield 'lost place ACK' => [PlacementState::Placing, false];
        yield 'lost stop ACK' => [PlacementState::Stopped, false];
        yield 'accepted place still pending before link loss' => [PlacementState::Placing, true];
    }

    public function testALateStartedFromANodeTheRecordLeftIsStoppedAndIgnored(): void
    {
        $mesh = new FakePlacementMesh(
            capabilities: ['node-b' => ['gpu', self::SLOTS], 'node-c' => ['gpu', self::SLOTS]],
            linked: ['node-b', 'node-c'],
            online: [self::SELF, 'node-b', 'node-c'],
        );
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['gpu']));
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([]));
        FakePlacementMesh::reply($placement, 'node-c', new PeerPlacementReportDTO([]));
        $placement->tick(1000.0);
        $placement->placeAgentOnNode('render', '9', 'node-c');
        $mesh->sent = [];

        // node-b answers for a placement the leader has since moved to node-c.
        FakePlacementMesh::status($placement, 'node-b', PeerAgentStatusDTO::started('render', '9', 5));

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
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([]));
        FakePlacementMesh::reply($placement, 'node-c', new PeerPlacementReportDTO([]));
        $placement->tick(1000.0);
        $placement->placeAgentOnNode('render', '9', 'node-c');
        $mesh->sent = [];

        FakePlacementMesh::status($placement, 'node-b', PeerAgentStatusDTO::stopped('render', '9'));

        $record = $placement->registry()->get('render:9');
        $this->assertSame('node-c', $record?->nodeId, 'A stop from the node it left must not erase a record naming another');
        $this->assertSame(PlacementState::Placing, $record?->state);
        $this->assertSame([], $mesh->sent, 'onStopAgent() answers stopped unconditionally, so a stop back at one loops the pair');
    }

    /**
     * A refusal on the leader keeps its structural diagnosis and one Unplaced record with no
     * host. The exception still carries the gate's structure, built from what the gate saw.
     */
    public function testALeaderGateRefusalKeepsTheAttemptAndAWaitingRecord(): void
    {
        $mesh = new FakePlacementMesh(['plain-node' => [self::SLOTS]], linked: ['plain-node'], online: [self::SELF, 'plain-node']);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['gpu']));
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 'plain-node', new PeerPlacementReportDTO([]));
        $placement->tick(1000.0);

        $refused = null;
        try {
            $placement->placeAgentOnNode('render', '9', 'plain-node');
            $this->fail('A placement onto a node missing a required capability must be rejected');
        } catch (PlacementCapabilityException $e) {
            $refused = $e;
        }

        $this->assertSame('plain-node', $refused->refusal->nodeId);
        $this->assertSame(PlacementRefusalCode::MissingCapabilities, $refused->refusal->reasonCode);
        $this->assertSame(['gpu'], $refused->refusal->missingCapabilities);
        $this->assertSame([], $mesh->sent, 'Refused before anything is sent');
        $this->assertSame(PlacementState::Unplaced, $placement->registry()->get('render:9')?->state);
        $this->assertNull($placement->registry()->get('render:9')?->nodeId);

        $snapshots = $placement->placementRefusals();
        $this->assertCount(1, $snapshots);
        $this->assertSame('render:9', $snapshots[0]->agentId());
        $this->assertSame($refused->getMessage(), $snapshots[0]->reason);
        $this->assertCount(1, $snapshots[0]->refusals);
        $this->assertTrue($refused->refusal->equals($snapshots[0]->refusals[0]));
    }

    /**
     * A move the target refuses leaves the running placement alone (HIL-447): the agent still
     * runs where it ran, and only the attempt is kept.
     */
    public function testARefusedMoveLeavesTheLiveRecordAsItWas(): void
    {
        [$placement] = $this->leaderWithARefusalOnAPlainNode(hostedOnNodeB: true);

        $record = $placement->registry()->get('render:9');
        $this->assertSame('node-b', $record?->nodeId);
        $this->assertSame(PlacementState::Started, $record?->state);
        $this->assertNull($record?->reason);
        $this->assertSame([], $record?->refusals);
        $this->assertCount(1, $placement->placementRefusals(), 'The refused move is still kept for inspection');
    }

    /**
     * No room anywhere gives every online node's own refusal, ordered by node id, one gate per
     * node — and one waiting record with no host (HIL-446).
     */
    public function testNoFittingNodeKeepsEveryNodesRefusalAndOneWaitingRecord(): void
    {
        $mesh = new FakePlacementMesh(
            capabilities: [
                self::SELF => ['worker'],
                's1' => ['worker', 'ram=4', 'slots=1'],
                's2' => ['worker', 'ram=4'],
                'c1' => ['slots=4'],
            ],
            linked: ['s1', 's2', 'c1'],
            online: [self::SELF, 's2', 's1', 'c1'],
        );
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['worker']));
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 's1', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '1')]));
        FakePlacementMesh::reply($placement, 's2', new PeerPlacementReportDTO([]));
        FakePlacementMesh::reply($placement, 'c1', new PeerPlacementReportDTO([]));
        $placement->tick(1000.0);

        $this->assertNull($placement->placeAgentOnBestNode('render', '9'));
        $this->assertSame(PlacementState::Unplaced, $placement->registry()->get('render:9')?->state);
        $this->assertNull($placement->registry()->get('render:9')?->nodeId);

        $snapshots = $placement->placementRefusals();
        $this->assertCount(1, $snapshots);
        $this->assertSame('no capable and online node fits the agent', $snapshots[0]->reason);
        $refusals = $snapshots[0]->refusals;
        $this->assertSame(['c1', self::SELF, 's1', 's2'], array_map(static fn(PlacementRefusal $r): ?string => $r->nodeId, $refusals));
        $this->assertSame(PlacementRefusalCode::MissingCapabilities, $refusals[0]->reasonCode);
        $this->assertSame(['worker'], $refusals[0]->missingCapabilities);
        $this->assertSame(PlacementRefusalCode::NoDeclaredCapacity, $refusals[1]->reasonCode, 'A master without declarations');
        $this->assertSame(PlacementRefusalCode::InsufficientCapacity, $refusals[2]->reasonCode);
        $this->assertSame(['slots' => ['cost' => 1.0, 'free' => 0.0]], $refusals[2]->shortfalls, 'Its one slot is taken');
        $this->assertSame(PlacementRefusalCode::InsufficientCapacity, $refusals[3]->reasonCode);
        $this->assertSame(['slots' => ['cost' => 1.0, 'free' => 0.0]], $refusals[3]->shortfalls, 'ram, but no slots declared');

        $this->assertNull($placement->placeAgentOnBestNode('render', '9'));
        $this->assertCount(1, $placement->placementRefusals(), 'Asking again retains one diagnosis and one waiting record');
        $this->assertSame(PlacementState::Unplaced, $placement->registry()->get('render:9')?->state);
        $this->assertCount(2, $placement->registry()->all(), 'One hosted row and exactly one waiting row');
    }

    public function testNoOnlineNodeIsNotReadAsAShortage(): void
    {
        $mesh = new FakePlacementMesh(capabilities: [], online: []);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['worker']));
        $placement->onBecameLeader(1000.0);

        $this->assertNull($placement->placeAgentOnBestNode('render', '9'));

        $snapshots = $placement->placementRefusals();
        $this->assertCount(1, $snapshots);
        $this->assertSame('no online node to place the agent on', $snapshots[0]->reason);
        $this->assertCount(1, $snapshots[0]->refusals);
        $this->assertSame(PlacementRefusalCode::NoOnlineNodes, $snapshots[0]->refusals[0]->reasonCode);
        $this->assertNull($snapshots[0]->refusals[0]->nodeId);
    }

    /**
     * A project policy may turn down a node that clears the gate; the refusal then says so and
     * invents no capacity code for a node that had room (HIL-447).
     */
    public function testAPolicyThatDeclinesAFittingNodeInventsNoRefusal(): void
    {
        $mesh = new FakePlacementMesh(['node-b' => ['worker', self::SLOTS]], linked: ['node-b'], online: [self::SELF, 'node-b']);
        $declining = new class implements PlacementPolicy {
            public function selectNode(array $requiredTags, ResourceProfile $cost, array $candidates): ?string
            {
                return null;
            }
        };
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['worker']), policy: $declining);
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([]));
        $placement->tick(1000.0);

        $this->assertNull($placement->placeAgentOnBestNode('render', '9'));

        $snapshots = $placement->placementRefusals();
        $this->assertCount(1, $snapshots);
        $this->assertSame('the placement policy declined every node that fits the agent', $snapshots[0]->reason);
        $this->assertSame([], $snapshots[0]->refusals);
    }

    /**
     * The node's own gate answers the leader with its refusal as structure, and the structure
     * survives the wire (HIL-447).
     */
    public function testTheNodeGateRefusalTravelsAsStructure(): void
    {
        $mesh = new FakePlacementMesh([self::SELF => ['worker', 'ram=4']]);
        $executor = new FakePlacementExecutor(['worker']);
        $placement = new ClusterPlacement(self::SELF, $mesh, $executor);

        $placement->onPlaceAgent('boss', new PeerPlaceAgentDTO('render', '9', new PlacementId('boss', 0, 1)));

        $this->assertSame([], $executor->executed, 'Nothing starts past a refusal');
        [$nodeId, $frame] = $mesh->sent[0];
        $this->assertSame('boss', $nodeId);
        $this->assertInstanceOf(PeerAgentStatusDTO::class, $frame);
        $this->assertSame(PlacementState::Failed, $frame->state);
        $this->assertStringContainsString('insufficient free capacity', (string)$frame->error);
        $this->assertCount(1, $frame->refusals);
        $this->assertSame(self::SELF, $frame->refusals[0]->nodeId);
        $this->assertSame(PlacementRefusalCode::InsufficientCapacity, $frame->refusals[0]->reasonCode);
        $this->assertSame(['slots' => ['cost' => 1.0, 'free' => 0.0]], $frame->refusals[0]->shortfalls);

        $parsed = PeerDTO::fromWire($frame->toJson());
        $this->assertInstanceOf(PeerAgentStatusDTO::class, $parsed);
        $this->assertTrue($frame->refusals[0]->equals($parsed->refusals[0]));
    }

    public function testAnUntypedNodeFailureAnswersItsTextAndNoRefusal(): void
    {
        $mesh = new FakePlacementMesh([self::SELF => ['worker', self::SLOTS]]);
        $executor = new FakePlacementExecutor(['worker']);
        $executor->failWith = new NoSuitableWorkerException('regular', false);
        $placement = new ClusterPlacement(self::SELF, $mesh, $executor);

        $placement->onPlaceAgent('boss', new PeerPlaceAgentDTO('render', '9', new PlacementId('boss', 0, 1)));

        [, $frame] = $mesh->sent[0];
        $this->assertInstanceOf(PeerAgentStatusDTO::class, $frame);
        $this->assertSame($executor->failWith->getMessage(), $frame->error);
        $this->assertSame([], $frame->refusals, 'A failure the gate did not raise gets no invented code');
    }

    public function testARemoteFailedStatusIsRecordedAndKeptWithItsRefusal(): void
    {
        [$placement] = $this->leaderPlacingOnNodeB();
        $refusal = PlacementRefusal::insufficientCapacity('node-b', ['slots' => ['cost' => 1.0, 'free' => 0.0]]);

        FakePlacementMesh::status($placement, 'node-b', PeerAgentStatusDTO::failed('render', '9', 'node full', [$refusal]));

        $record = $placement->registry()->get('render:9');
        $this->assertSame(PlacementState::Failed, $record?->state);
        $this->assertSame('node-b', $record?->nodeId);
        $this->assertSame('node full', $record?->reason);
        $this->assertCount(1, $record?->refusals ?? []);
        $this->assertTrue($refusal->equals($record->refusals[0]));

        $snapshots = $placement->placementRefusals();
        $this->assertCount(1, $snapshots);
        $this->assertSame('node full', $snapshots[0]->reason);
        $this->assertTrue($refusal->equals($snapshots[0]->refusals[0]));
    }

    public function testAnUntypedRemoteFailureKeepsItsTextAndNoRefusal(): void
    {
        [$placement] = $this->leaderPlacingOnNodeB();

        FakePlacementMesh::status($placement, 'node-b', PeerAgentStatusDTO::failed('render', '9', 'no worker'));

        $record = $placement->registry()->get('render:9');
        $this->assertSame('no worker', $record?->reason);
        $this->assertSame([], $record?->refusals);
        $this->assertSame('no worker', $placement->placementRefusals()[0]->reason);
        $this->assertSame([], $placement->placementRefusals()[0]->refusals);
    }

    /**
     * A failure from the node the record has left neither rewrites the record naming the new
     * node nor becomes the agent's last refusal (HIL-447).
     */
    public function testALateFailedStatusFromTheNodeTheRecordLeftChangesNothing(): void
    {
        $mesh = new FakePlacementMesh(
            capabilities: ['node-b' => ['gpu', self::SLOTS], 'node-c' => ['gpu', self::SLOTS]],
            linked: ['node-b', 'node-c'],
            online: [self::SELF, 'node-b', 'node-c'],
        );
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['gpu']));
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([]));
        FakePlacementMesh::reply($placement, 'node-c', new PeerPlacementReportDTO([]));
        $placement->tick(1000.0);
        $placement->placeAgentOnNode('render', '9', 'node-c');

        FakePlacementMesh::status($placement, 'node-b', PeerAgentStatusDTO::failed(
            'render',
            '9',
            'old host full',
            [PlacementRefusal::insufficientCapacity('node-b', ['slots' => ['cost' => 1.0, 'free' => 0.0]])],
        ));

        $record = $placement->registry()->get('render:9');
        $this->assertSame('node-c', $record?->nodeId);
        $this->assertSame(PlacementState::Placing, $record?->state);
        $this->assertNull($record?->reason);
        $this->assertSame([], $placement->placementRefusals());
    }

    public function testALocalStartFailureIsRecordedWithItsTextAndNoRefusal(): void
    {
        $mesh = new FakePlacementMesh([self::SELF => [self::SLOTS]], online: [self::SELF]);
        $executor = new FakePlacementExecutor();
        $executor->failWith = new NoSuitableWorkerException('regular', false);
        $placement = new ClusterPlacement(self::SELF, $mesh, $executor);
        $placement->onBecameLeader(1000.0);

        try {
            $placement->placeAgentOnNode('chat', null, self::SELF);
            $this->fail('A failing local placement must surface the executor error');
        } catch (NoSuitableWorkerException) {
            // expected
        }

        $record = $placement->registry()->get('chat');
        $this->assertSame(PlacementState::Failed, $record?->state);
        $this->assertSame($executor->failWith->getMessage(), $record?->reason);
        $this->assertSame([], $record?->refusals);
        $this->assertSame($executor->failWith->getMessage(), $placement->placementRefusals()[0]->reason);
    }

    public function testAnAcceptedAttemptDropsTheLastRefusal(): void
    {
        [$placement] = $this->leaderWithARefusalOnAPlainNode();

        $placement->placeAgentOnNode('render', '9', 'node-b');

        $this->assertSame(PlacementState::Placing, $placement->registry()->get('render:9')?->state);
        $this->assertSame([], $placement->placementRefusals());
    }

    public function testAStartDropsTheLastRefusal(): void
    {
        [$placement] = $this->leaderWithARefusalOnAPlainNode();

        FakePlacementMesh::status($placement, 'node-b', PeerAgentStatusDTO::started('render', '9', 1));

        $this->assertSame(PlacementState::Started, $placement->registry()->get('render:9')?->state);
        $this->assertSame([], $placement->placementRefusals());
    }

    public function testAReportThatAdoptsTheAgentDropsTheLastRefusal(): void
    {
        [$placement] = $this->leaderWithARefusalOnAPlainNode();

        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')]));

        $this->assertSame([], $placement->placementRefusals());
    }

    public function testAnExplicitStopDropsTheLastRefusal(): void
    {
        [$placement] = $this->leaderWithARefusalOnAPlainNode(hostedOnNodeB: true);

        $placement->stopAgentOnNode('render', '9', 'node-b');

        $this->assertSame([], $placement->placementRefusals());
    }

    public function testAStoppedStatusDropsTheLastRefusal(): void
    {
        [$placement] = $this->leaderWithARefusalOnAPlainNode(hostedOnNodeB: true);

        FakePlacementMesh::status($placement, 'node-b', PeerAgentStatusDTO::stopped('render', '9'));

        $this->assertSame([], $placement->placementRefusals());
    }

    /**
     * The refusals are a term's diagnosis: a node that stops leading answers none, and one that
     * wins a term starts with none, its own old notes included (HIL-447, HIL-446 keeps them across).
     */
    public function testRefusalsDoNotOutliveTheTerm(): void
    {
        [$placement] = $this->leaderWithARefusalOnAPlainNode();

        $placement->onLostLeadership();
        $this->assertSame([], $placement->placementRefusals(), 'A node that does not lead answers no refusals');

        $placement->onBecameLeader(2000.0);
        $this->assertSame([], $placement->placementRefusals(), 'A new term starts with none');

        [$again] = $this->leaderWithARefusalOnAPlainNode();
        $again->onBecameLeader(2000.0);
        $this->assertSame([], $again->placementRefusals(), 'Winning a term again drops what the last one kept');
    }

    /**
     * Failover that finds no node carries why into the Unplaced record and the snapshot, and a
     * retry on a newcomer that still fits nothing refreshes both without reporting the degrade
     * again (HIL-447).
     */
    public function testFailoverAndItsRetryCarryWhyNothingTookTheAgent(): void
    {
        $mesh = new FakePlacementMesh(
            capabilities: ['node-b' => ['gpu', self::SLOTS], 'node-c' => ['gpu']],
            linked: ['node-b', 'node-c'],
            online: [self::SELF, 'node-b', 'node-c'],
        );
        $observer = new FakePlacementObserver();
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['gpu']), $observer, failoverGraceMs: 500);
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')]));
        FakePlacementMesh::reply($placement, 'node-c', new PeerPlacementReportDTO([]));
        $placement->tick(1000.0);

        $mesh->online = [self::SELF, 'node-c'];
        $placement->noteNodeOffline('node-b', 1000.0);
        $placement->tick(1000.6);

        $record = $placement->registry()->get('render:9');
        $this->assertSame(PlacementState::Unplaced, $record?->state);
        $this->assertSame('node-b', $record?->nodeId);
        $this->assertSame('no capable and online node fits the agent', $record?->reason);
        $this->assertSame(
            [[self::SELF, PlacementRefusalCode::MissingCapabilities], ['node-c', PlacementRefusalCode::NoDeclaredCapacity]],
            $this->refusalCodes($record?->refusals ?? []),
        );
        $this->assertSame($this->refusalCodes($record->refusals), $this->refusalCodes($placement->placementRefusals()[0]->refusals));

        $mesh->capabilities['node-d'] = ['ram=8'];
        $mesh->linked[] = 'node-d';
        $mesh->online = [self::SELF, 'node-c', 'node-d'];
        $placement->noteNodeOnline('node-d', 2000.0);
        $placement->tick(2000.0);

        $retried = $placement->registry()->get('render:9');
        $this->assertSame(PlacementState::Unplaced, $retried?->state);
        $this->assertSame(
            [
                [self::SELF, PlacementRefusalCode::MissingCapabilities],
                ['node-c', PlacementRefusalCode::NoDeclaredCapacity],
                ['node-d', PlacementRefusalCode::MissingCapabilities],
            ],
            $this->refusalCodes($retried?->refusals ?? []),
            'The retry says why as of now, the newcomer included',
        );
        $this->assertSame([['render', '9']], $observer->degraded, 'The retry does not report the degrade a second time');
    }

    /**
     * A node that no longer hosts its agent, with nothing else to take it, leaves it Unplaced with
     * why — the same as failover (HIL-447).
     */
    public function testReconcileCarriesWhyNothingTookTheAgent(): void
    {
        $mesh = new FakePlacementMesh(['node-b' => ['gpu', 'cpu=4']], linked: ['node-b'], online: [self::SELF, 'node-b']);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['gpu']));
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([new PeerPlacedAgentEntry('render', '9')]));
        $placement->tick(1000.0);

        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([]));

        $record = $placement->registry()->get('render:9');
        $this->assertSame(PlacementState::Unplaced, $record?->state);
        $this->assertSame(
            [[self::SELF, PlacementRefusalCode::MissingCapabilities], ['node-b', PlacementRefusalCode::InsufficientCapacity]],
            $this->refusalCodes($record?->refusals ?? []),
        );
        $this->assertSame('no capable and online node fits the agent', $placement->placementRefusals()[0]->reason);
    }

    /**
     * Builds a leader with a capable node-b, a plain node lacking the gpu tag, and one refused
     * attempt to put 'render:9' on the plain node.
     *
     * @param bool $hostedOnNodeB Whether node-b reports running the agent before the attempt
     * @return array{0: ClusterPlacement, 1: FakePlacementMesh} Leader and its mesh
     */
    private function leaderWithARefusalOnAPlainNode(bool $hostedOnNodeB = false): array
    {
        $mesh = new FakePlacementMesh(
            capabilities: ['node-b' => ['gpu', self::SLOTS], 'plain-node' => [self::SLOTS]],
            linked: ['node-b', 'plain-node'],
            online: [self::SELF, 'node-b', 'plain-node'],
        );
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['gpu']));
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement,
            'node-b',
            new PeerPlacementReportDTO($hostedOnNodeB ? [new PeerPlacedAgentEntry('render', '9')] : []),
        );
        FakePlacementMesh::reply($placement, 'plain-node', new PeerPlacementReportDTO([]));
        $placement->tick(1000.0);

        try {
            $placement->placeAgentOnNode('render', '9', 'plain-node');
            $this->fail('The plain node lacks the gpu tag');
        } catch (PlacementCapabilityException) {
            // expected
        }
        $this->assertCount(1, $placement->placementRefusals());

        return [$placement, $mesh];
    }

    /**
     * Builds a leader that has sent 'render:9' to node-b and waits for its status.
     *
     * @return array{0: ClusterPlacement, 1: FakePlacementMesh} Leader and its mesh
     */
    private function leaderPlacingOnNodeB(): array
    {
        $mesh = new FakePlacementMesh(['node-b' => ['gpu', self::SLOTS]], linked: ['node-b'], online: [self::SELF, 'node-b']);
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['gpu']));
        $placement->onBecameLeader(1000.0);
        FakePlacementMesh::reply($placement, 'node-b', new PeerPlacementReportDTO([]));
        $placement->tick(1000.0);
        $placement->placeAgentOnNode('render', '9', 'node-b');
        $this->assertSame(PlacementState::Placing, $placement->registry()->get('render:9')?->state);

        return [$placement, $mesh];
    }

    /**
     * Reads refusals as node id and code pairs, the part an ordering assertion is about.
     *
     * @param list<PlacementRefusal> $refusals Refusals to read
     * @return list<array{0: ?string, 1: PlacementRefusalCode}> Node id and code of each, in order
     */
    private function refusalCodes(array $refusals): array
    {
        return array_map(static fn(PlacementRefusal $refusal): array => [$refusal->nodeId, $refusal->reasonCode], $refusals);
    }

    /**
     * @param FakePlacementMesh $mesh Mesh the coordinator sent through
     * @return list<array{0: string, 1: ?string}> Stopped statuses sent so far, as [node id, agent index]
     */
    private function stoppedStatuses(FakePlacementMesh $mesh): array
    {
        $statuses = [];
        foreach ($mesh->sent as [$nodeId, $frame]) {
            if ($frame instanceof PeerAgentStatusDTO && $frame->state === PlacementState::Stopped) {
                $statuses[] = [$nodeId, $frame->agentIndex];
            }
        }

        return $statuses;
    }

    /**
     * @param FakePlacementMesh $mesh Mesh the coordinator sent through
     * @return list<array{0: string, 1: string, 2: ?string}> Place frames sent so far, as [node id, type, index]
     */
    private function placeFrames(FakePlacementMesh $mesh): array
    {
        $frames = [];
        foreach ($mesh->sent as [$nodeId, $frame]) {
            if ($frame instanceof PeerPlaceAgentDTO) {
                $frames[] = [$nodeId, $frame->agentType, $frame->agentIndex];
            }
        }

        return $frames;
    }

    /**
     * @param FakePlacementMesh $mesh Mesh the coordinator broadcast through
     * @return list<PeerPlacementReportDTO> Placement reports broadcast so far
     */
    private function placementReports(FakePlacementMesh $mesh): array
    {
        return array_values(array_filter(
            $mesh->broadcast,
            static fn(PeerDTO $frame): bool => $frame instanceof PeerPlacementReportDTO,
        ));
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

    use PlacementQueryFixture;

    /**
     * Responds to the latest question when the fixture simulates a reporting peer.
     *
     * @param ClusterPlacement $placement Leader under test
     * @param string $nodeId Reporting peer
     * @param PeerPlacementReportDTO $report Hosted set supplied by the fixture
     */
    public static function reply(ClusterPlacement $placement, string $nodeId, PeerPlacementReportDTO $report): void
    {
        $mesh = (new ReflectionProperty($placement, 'mesh'))->getValue($placement);
        $query = null;
        if (method_exists($mesh, 'takeFixtureQuery') && $report->queryId === null) {
            $query = $mesh->takeFixtureQuery($nodeId);
            $awaiting = (new ReflectionProperty($placement, 'rebuildAwaited'))->getValue($placement);
            $broadcast = (new ReflectionProperty($placement, 'rebuildQueryId'))->getValue($placement);
            if ($query?->queryId === $broadcast && !array_key_exists($nodeId, $awaiting)) {
                $query = null;
            }
        }
        $entries = [];
        foreach ($report->agents as $entry) {
            $record = $placement->registry()->get($entry->agentIndex === null ? $entry->agentType : "$entry->agentType:$entry->agentIndex");
            $entries[] = $entry->placementId === null && $record?->nodeId === $nodeId && $record->placementId !== null
                ? new PeerPlacedAgentEntry($entry->agentType, $entry->agentIndex, $record->placementId, PlacementState::Started)
                : $entry;
        }
        $placement->onPlacementReport($nodeId, new PeerPlacementReportDTO(
            $entries, $report->placementSnapshot, $report->queryId ?? $query?->queryId,
        ));
    }

    /**
     * Sends the fixture's status as the answer to the node's current order, if any.
     *
     * @param ClusterPlacement $placement Leader under test
     * @param string $nodeId Reporting peer
     * @param PeerAgentStatusDTO $status Simulated worker outcome or stop fact
     */
    public static function status(ClusterPlacement $placement, string $nodeId, PeerAgentStatusDTO $status): void
    {
        $agentId = $status->agentIndex === null ? $status->agentType : "$status->agentType:$status->agentIndex";
        $order = $placement->registry()->get($agentId);
        if ($status->state === PlacementState::Stopped && $status->stopId === null && $status->placementId === null) {
            $stops = new ReflectionProperty($placement, 'peerStops')->getValue($placement);
            $stop = $stops[$agentId] ?? null;
            if ($stop?->nodeId === $nodeId) {
                $status = $stop->order->stoppedStatus();
            } elseif ($order?->nodeId === $nodeId && $order->placementId !== null) {
                $status = PeerAgentStatusDTO::stopped(
                    $status->agentType, $status->agentIndex, $order->placementId, $placement->placementSnapshot()?->term ?? $order->placementId->term,
                );
            }
        }
        if ($status->placementId === null && $order?->nodeId === $nodeId && $order->placementId !== null
            && ($status->state === PlacementState::Started || $status->state === PlacementState::Failed)) {
            $status = new PeerAgentStatusDTO(
                $status->agentType, $status->agentIndex, $status->state, $status->workerId, $status->error, $status->refusals,
                $order->placementId, $placement->placementSnapshot()?->term ?? $order->placementId->term,
            );
        }
        $placement->onAgentStatus($nodeId, $status);
    }

    /** @var list<PeerDTO> Broadcast frames */
    public array $broadcast = [];

    /** @var array<string, list<string>> Advertised capabilities keyed by node id */
    public array $capabilities;

    /** @var list<string> Node ids a link exists to (sendToNode succeeds) */
    public array $linked;

    /** @var list<string> Currently-online node ids */
    public array $online;

    /** Whether a wait for RT answers is held until {@see self::releaseRtWritesAnswered()} instead of ending at once */
    public bool $holdRtWritesAnswered = false;

    /** @var list<Closure(): void> Waits for RT answers held so far, in the order asked */
    public array $heldRtWritesAnswered = [];

    public ?string $refusedReport = null;

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
        if ($frame instanceof PeerPlacementReportDTO && $this->refusedReport !== null && $frame->queryId === $this->refusedReport) {
            $this->refusedReport = null;
            throw new EventLoopException('report queue refused');
        }
        $this->sent[] = [$nodeId, $frame];
        $this->captureFixtureQuery($frame, $nodeId);

        return in_array($nodeId, $this->linked, true);
    }

    public function broadcastToNodes(PeerDTO $frame): void
    {
        $this->broadcast[] = $frame;
        $this->captureFixtureQuery($frame);
    }

    public function nodeCapabilities(string $nodeId): ?array
    {
        return $this->capabilities[$nodeId] ?? null;
    }

    public function onlineNodeIds(): array
    {
        return $this->online;
    }

    public function afterRtWritesAnswered(Closure $then): void
    {
        if (!$this->holdRtWritesAnswered) {
            $then();

            return;
        }
        $this->heldRtWritesAnswered[] = $then;
    }

    /** Ends the waits for RT answers held so far, in the order asked; a wait asked meanwhile stays held */
    public function releaseRtWritesAnswered(): void
    {
        $held = $this->heldRtWritesAnswered;
        $this->heldRtWritesAnswered = [];
        foreach ($held as $then) {
            $then();
        }
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
 * Fake executor that records placements and revokes, and can be told to fail, to report
 * a fixed required-capability set and worker id, or to hold what it tells a revoke's caller.
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

    /** Whether a revoke's "stopped" is held until {@see self::releaseStopped()} instead of told at once */
    public bool $holdStopped = false;

    /** @var list<Closure(): void> Revokes' "stopped" held so far, in the order asked */
    public array $heldStopped = [];

    public ?InvalidFormatException $raiseAfterRevoke = null;

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

    /**
     * @param string $agentType Fixture input
     * @param ?string $agentIndex Fixture input
     * @param ?AgentStartSink $immediateStartSink Synchronous notification port for this call
     * @return ?int Fixture placement result
     */
    public function executePlacement(string $agentType, ?string $agentIndex, ?AgentStartSink $immediateStartSink = null): ?int
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

    /**
     * @param string $agentType Fixture input
     * @param ?string $agentIndex Fixture input
     * @param ?Closure $stopped Fixture input
     * @param ?AgentStartSink $immediateStartSink Synchronous notification port for this call
     */
    public function revokePlacement(
        string $agentType,
        ?string $agentIndex,
        ?Closure $stopped = null,
        ?AgentStartSink $immediateStartSink = null,
    ): void
    {
        $this->revoked[] = [$agentType, $agentIndex];
        if ($stopped === null) {
            return;
        }
        if (!$this->holdStopped) {
            $stopped();

            return;
        }
        $this->heldStopped[] = $stopped;
        if ($this->raiseAfterRevoke !== null) {
            $failure = $this->raiseAfterRevoke;
            $this->raiseAfterRevoke = null;
            throw $failure;
        }
    }

    /** Tells the revokes' "stopped" held so far, in the order asked; one asked meanwhile stays held */
    public function releaseStopped(): void
    {
        $held = $this->heldStopped;
        $this->heldStopped = [];
        foreach ($held as $stopped) {
            $stopped();
        }
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

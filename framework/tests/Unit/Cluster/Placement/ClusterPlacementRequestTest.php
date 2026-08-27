<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Placement;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Peer\DTO\PeerAgentStatusDTO;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerPlaceAgentDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementReportDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementRequestDTO;
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Cluster\Placement\PlacementState;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;

/**
 * Placement driven by an agent being ADDRESSED, and the map kept honest when it stops (HIL-628).
 *
 * An instance agent has no bootstrap that starts it: the first frame to it is its start. On one
 * node that already worked, because the frame reaches the worker server and it starts what it
 * cannot find. On a cluster it does not, and for a reason no log reports: the frame can land on
 * any node, only the leader may decide where an agent runs, and the address lookup answers
 * "unknown" — so the frame is dropped and the next one is dropped the same way, forever.
 *
 * What closes it is one frame travelling the direction no placement frame travelled before, from
 * a node UP to the leader. The cases below are the four things that must hold for it not to
 * become a second placer: the leader acts alone, a follower only asks, asking does not repeat per
 * frame, and an agent already placed is left where it is.
 *
 * The last pair covers the other end of the same life — the agent stopping itself after its
 * silence — because a map that keeps naming a host the agent has left would send every later
 * frame to a node that drops it.
 */
final class ClusterPlacementRequestTest extends TestCase
{
    private const string SELF = 'node-a';

    protected function tearDown(): void
    {
        Hilos::$cluster = null;

        parent::tearDown();
    }

    /**
     * The leader needs nobody's permission: it owns the view, so it runs the ordinary best-fit
     * placement the moment an address comes up empty.
     */
    public function testTheLeaderPlacesAnAddressedAgentItself(): void
    {
        $mesh = $this->mesh();
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['worker']));
        $placement->onBecameLeader();

        $placement->requirePlacement('render', '9');

        [$nodeId, $frame] = $this->lastSent($mesh);
        $this->assertSame('node-b', $nodeId);
        $this->assertInstanceOf(PeerPlaceAgentDTO::class, $frame);
        $this->assertSame('9', $frame->agentIndex);
    }

    /**
     * A node that does not lead asks instead of placing. Placing on its own initiative is how two
     * nodes end up hosting one instance agent, which is the outcome placement exists to prevent.
     */
    public function testANodeThatDoesNotLeadAsksTheLeaderInstead(): void
    {
        $mesh = $this->mesh();
        $placement = $this->follower($mesh, leaderId: 'node-b');

        $placement->requirePlacement('render', '9');

        [$nodeId, $frame] = $this->lastSent($mesh);
        $this->assertSame('node-b', $nodeId, 'The ask goes to the leader, not to a node of its own choosing');
        $this->assertInstanceOf(PeerPlacementRequestDTO::class, $frame);
        $this->assertSame('render', $frame->agentType);
        $this->assertSame('9', $frame->agentIndex);
    }

    /**
     * The address is asked once per FRAME, and a page opening sends several in a row — each of
     * them arriving well before any placement could have landed. Without the memory the leader
     * would be asked once per frame for the same agent.
     */
    public function testTheSameAgentIsNotAskedForTwiceInARow(): void
    {
        $mesh = $this->mesh();
        $placement = $this->follower($mesh, leaderId: 'node-b');

        $placement->requirePlacement('render', '9');
        $placement->requirePlacement('render', '9');
        $placement->requirePlacement('render', '9');

        $this->assertCount(1, $mesh->sent);
    }

    /**
     * The memory is per agent and not a global quiet period: two people opening two instances at
     * the same second must both get one, which is the whole point of an agent per instance.
     */
    public function testAnAskForOneAgentDoesNotSilenceAnother(): void
    {
        $mesh = $this->mesh();
        $placement = $this->follower($mesh, leaderId: 'node-b');

        $placement->requirePlacement('render', '9');
        $placement->requirePlacement('render', '10');

        $this->assertCount(2, $mesh->sent);
    }

    /**
     * A cluster between terms has nobody to ask. The ask is dropped rather than turned into a
     * local placement, because a node placing without a leader is exactly the split the consensus
     * layer exists to prevent.
     */
    public function testANodeWithNoLeaderToAskPlacesNothing(): void
    {
        $mesh = $this->mesh();
        $placement = $this->follower($mesh, leaderId: null);

        $placement->requirePlacement('render', '9');

        $this->assertSame([], $mesh->sent);
    }

    /**
     * The leader answers another node's ask with the same placement it would have run for itself.
     */
    public function testTheLeaderPlacesWhatAnotherNodeAsksFor(): void
    {
        $mesh = $this->mesh();
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['worker']));
        $placement->onBecameLeader();

        $placement->onPlacementRequest('node-c', new PeerPlacementRequestDTO('render', '9'));

        [$nodeId, $frame] = $this->lastSent($mesh);
        $this->assertSame('node-b', $nodeId);
        $this->assertInstanceOf(PeerPlaceAgentDTO::class, $frame);
    }

    /**
     * The asking node's ignorance is not the leader's: a published view lags by a tick, so an
     * agent placed a moment ago is still unknown to a node that has not been handed the new
     * picture. Placing it again would start a SECOND copy of it.
     */
    public function testAnAgentTheLeaderAlreadyPlacesIsNotPlacedAgain(): void
    {
        $mesh = $this->mesh();
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['worker']));
        $placement->onBecameLeader();
        $placement->onAgentStatus('node-b', PeerAgentStatusDTO::started('render', '9', 1));

        $placement->onPlacementRequest('node-c', new PeerPlacementRequestDTO('render', '9'));

        $this->assertSame([], $this->placeFrames($mesh), 'An already-placed agent is left where it is');
    }

    /**
     * A node that does not lead ignores an ask sent to it: the view it holds is a copy, and
     * placing against a copy is how a deposed leader keeps placing.
     */
    public function testANodeThatDoesNotLeadIgnoresAPlacementRequest(): void
    {
        $mesh = $this->mesh();
        $placement = $this->follower($mesh, leaderId: 'node-b');

        $placement->onPlacementRequest('node-c', new PeerPlacementRequestDTO('render', '9'));

        $this->assertSame([], $mesh->sent);
    }

    /**
     * The other end of the life: the agent stopped itself, so the node it ran on drops it from
     * what it hosts and tells the leader with the stopped status a revoke would have sent.
     */
    public function testAStoppedAgentLeavesWhatTheNodeHostsAndIsReported(): void
    {
        $mesh = $this->mesh();
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['worker']));
        $placement->onPlaceAgent('node-b', new PeerPlaceAgentDTO('render', '9'));

        $placement->noteAgentStopped('render', '9');

        [$nodeId, $frame] = $this->lastSent($mesh);
        $this->assertSame('node-b', $nodeId, 'The report goes to the leader that placed this work');
        $this->assertInstanceOf(PeerAgentStatusDTO::class, $frame);
        $this->assertSame(PlacementState::Stopped, $frame->state);

        $placement->onPlacementQuery('node-b');
        [, $report] = $this->lastSent($mesh);
        $this->assertInstanceOf(PeerPlacementReportDTO::class, $report);
        $this->assertSame([], $report->agents, 'A stopped agent is no longer reported as hosted');
    }

    /**
     * On the leader there is nobody to tell: it forgets the record itself, the same write an
     * inbound stopped status would have made.
     */
    public function testTheLeaderForgetsAnAgentItStoppedHostingItself(): void
    {
        // The one case where the leader hosts the agent itself, so this mesh — unlike the others —
        // advertises the local node as capable.
        $mesh = new FakePlacementMesh(
            capabilities: [self::SELF => ['worker', 'cpu=4']],
            linked: [self::SELF],
            online: [self::SELF],
        );
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['worker']));
        $placement->onBecameLeader();
        $placement->placeAgentOnNode('render', '9', self::SELF);

        $placement->noteAgentStopped('render', '9');

        $this->assertNull($placement->registry()->get('render:9'));
        $this->assertSame([], $mesh->sent, 'The leader tells nobody what it already knows');
    }

    /**
     * Every agent stop on the node arrives here, including the replicas and leader-hosted
     * singletons placement never put anywhere. Only what this node was PLACED with is news.
     */
    public function testAnAgentThisNodeWasNeverPlacedWithIsNotReported(): void
    {
        $mesh = $this->mesh();
        $placement = new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['worker']));
        $placement->onPlaceAgent('node-b', new PeerPlaceAgentDTO('render', '9'));
        $mesh->sent = [];

        $placement->noteAgentStopped('presence', null);

        $this->assertSame([], $mesh->sent);
    }

    /**
     * Builds a mesh with one capable data-plane node to place onto.
     *
     * @return FakePlacementMesh Mesh recording what the coordinator sent
     */
    private function mesh(): FakePlacementMesh
    {
        return new FakePlacementMesh(
            capabilities: ['node-b' => ['worker', 'cpu=4']],
            linked: ['node-b'],
            online: [self::SELF, 'node-b'],
        );
    }

    /**
     * Builds a coordinator on a node that has never taken a term, with leadership answering a
     * fixed id.
     *
     * @param FakePlacementMesh $mesh Mesh the coordinator sends through
     * @param ?string $leaderId Node id leadership reports, or null when nobody leads
     * @return ClusterPlacement Coordinator under test
     */
    private function follower(FakePlacementMesh $mesh, ?string $leaderId): ClusterPlacement
    {
        $context = new ClusterContext();
        $context->registerLeadership(new LocateTestLeadership($leaderId, false));
        Hilos::$cluster = $context;

        return new ClusterPlacement(self::SELF, $mesh, new FakePlacementExecutor(['worker']));
    }

    /**
     * @param FakePlacementMesh $mesh Mesh the coordinator sent through
     * @return array{0: string, 1: PeerDTO} Most recently sent frame, with the node it went to
     */
    private function lastSent(FakePlacementMesh $mesh): array
    {
        $this->assertNotEmpty($mesh->sent, 'The coordinator sent nothing at all');

        return $mesh->sent[count($mesh->sent) - 1];
    }

    /**
     * @param FakePlacementMesh $mesh Mesh the coordinator sent through
     * @return list<PeerPlaceAgentDTO> Every place-agent frame sent so far, in order
     */
    private function placeFrames(FakePlacementMesh $mesh): array
    {
        return array_values(array_filter(
            array_map(static fn(array $sent): PeerDTO => $sent[1], $mesh->sent),
            static fn(PeerDTO $frame): bool => $frame instanceof PeerPlaceAgentDTO,
        ));
    }
}

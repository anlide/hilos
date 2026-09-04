<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Placement;

use Hilos\Cluster\Peer\DTO\PeerAgentStatusDTO;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacedAgentEntry;
use Hilos\Cluster\Peer\DTO\PeerPlacementReportDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementViewDTO;
use Hilos\Cluster\Placement\AgentLocationKind;
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Cluster\Placement\PlacementExecutor;
use Hilos\Cluster\Placement\PlacementMesh;
use Hilos\Cluster\Placement\ResourceProfile;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The leader's picture of where every agent runs, handed down to the nodes that do not lead
 * (HIL-668).
 *
 * The defect this closes is quiet and total: placement is leader-owned soft state, so every
 * other node answered "I do not know where that agent is" for the whole cluster — and a signal
 * to an agent, asked that question, was delivered locally, to a worker not running it. A
 * browser attached to a non-leader therefore reached nobody, which no error anywhere reports.
 *
 * Two moments carry the picture, and the pair is the same shape the connection index uses: the
 * whole thing to a node the moment it links, and a fresh copy whenever it changes. The change
 * is found by COMPARING rather than by a call at each write — the registry is written from
 * eight places, and one missed call would leave every other node wrong forever with nothing to
 * correct it.
 */
final class ClusterPlacementViewTest extends TestCase
{
    private const string SELF = 'node-a';

    /** @var class-string<Hilos> App class bound before this test touched it */
    private string $boundAppClass;

    protected function setUp(): void
    {
        // Both types are declared policy-placed, because the published view is what the lookup
        // reads for that cell alone (HIL-670): an every-node replica is always here and a
        // leader-hosted singleton is wherever leadership sits, and no view carries either.
        $this->boundAppClass = Hilos::appClass();
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, PlacementViewTestHilos::class);
    }

    protected function tearDown(): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $this->boundAppClass);

        parent::tearDown();
    }

    public function testANonLeaderAnswersLookupsFromTheViewItWasHanded(): void
    {
        $placement = $this->follower();

        $placement->onPlacementView('leader', $this->view(['node-b' => [['render', '9']]]));

        $this->assertSame('node-b', $placement->locate('render', '9')->nodeId);
    }

    /**
     * An agent the view places HERE is still answered null: null means "deliver locally", and
     * this node is where local is. Answering its own id would send the signal out onto the mesh
     * and back, which the receiving side would then have to recognize as its own.
     */
    public function testAnAgentTheViewPlacesOnThisNodeStaysLocal(): void
    {
        $placement = $this->follower();

        $placement->onPlacementView('leader', $this->view([self::SELF => [['chat', null]]]));

        $this->assertSame(AgentLocationKind::Here, $placement->locate('chat', null)->kind);
    }

    /**
     * A node id that reads as a number arrives as an int, because on the wire it spends a leg
     * as an array KEY and PHP has no string key shaped like a decimal integer. What
     * {@see ClusterPlacement::locate()} answers names a node id or none, so the int has to become
     * a string again before it gets there — a cluster named 1/2/3 is an ordinary cluster.
     */
    public function testAViewNamingNodesWithNumbersAnswersWithTheirIds(): void
    {
        $placement = $this->follower();

        $placement->onPlacementView('leader', $this->view([2 => [['render', '9']]]));

        $this->assertSame('2', $placement->locate('render', '9')->nodeId);
    }

    /**
     * Before any view arrives — a node that has just started, or one that has never seen a
     * leader — every lookup answers "I do not know". It used to answer null, which the router
     * read as local, and the signal went to workers running no such agent (HIL-670).
     */
    public function testANodeWithNoViewKnowsOfNoPlacementAtAll(): void
    {
        $this->assertSame(AgentLocationKind::Unknown, $this->follower()->locate('render', '9')->kind);
    }

    /**
     * A view REPLACES rather than merges: it is the leader's complete answer, so an agent it no
     * longer names is an agent that has stopped or moved. Merging would keep forwarding to the
     * node that used to host it.
     */
    public function testAFreshViewReplacesTheOneBefore(): void
    {
        $placement = $this->follower();
        $placement->onPlacementView('leader', $this->view(['node-b' => [['render', '9']]]));

        $placement->onPlacementView('leader', $this->view(['node-c' => [['chat', null]]]));

        $this->assertSame(AgentLocationKind::Unknown, $placement->locate('render', '9')->kind);
        $this->assertSame('node-c', $placement->locate('chat', null)->nodeId);
    }

    /**
     * A view is believed from the node that stamped it and nobody else. Only the leader
     * publishes one, so a mismatch means the frame was relayed — and a relayed picture is at
     * best one hop older than what its sender already holds.
     */
    public function testAViewRelayedBySomebodyElseIsDropped(): void
    {
        $placement = $this->follower();

        $placement->onPlacementView('node-d', $this->view(['node-b' => [['render', '9']]]));

        $this->assertSame(AgentLocationKind::Unknown, $placement->locate('render', '9')->kind);
    }

    /**
     * The leader ignores a view sent to it: its own registry is the original this frame is a
     * copy of, and taking one back would let a deposed leader's picture overwrite the truth.
     */
    public function testTheLeaderIgnoresAViewSentToIt(): void
    {
        $mesh = new PlacementViewTestMesh();
        $placement = new ClusterPlacement(self::SELF, $mesh, new PlacementViewTestExecutor());
        $placement->onBecameLeader();
        $placement->onAgentStatus('node-b', PeerAgentStatusDTO::started('render', '9', 1));

        $placement->onPlacementView('other', $this->view(['node-c' => [['render', '9']]], 'other'));

        $this->assertSame('node-b', $placement->locate('render', '9')->nodeId);
    }

    public function testTheLeaderPublishesTheViewOnceItChanges(): void
    {
        $mesh = new PlacementViewTestMesh();
        $placement = new ClusterPlacement(self::SELF, $mesh, new PlacementViewTestExecutor());
        $placement->onBecameLeader();
        $placement->onAgentStatus('node-b', PeerAgentStatusDTO::started('render', '9', 1));

        $placement->tick(1.0);

        $view = $this->lastView($mesh);
        $this->assertSame(self::SELF, $view->leaderNodeId);
        $this->assertSame(['node-b'], array_keys($view->agents));
        $this->assertSame('render', $view->agents['node-b'][0]->agentType);
        $this->assertSame('9', $view->agents['node-b'][0]->agentIndex);
    }

    /**
     * And it says nothing on the ticks in between, which is nearly all of them. A frame per tick
     * per node would be this mesh's loudest traffic by far, and it would say nothing new.
     */
    public function testTheLeaderIsSilentWhileNothingMoves(): void
    {
        $mesh = new PlacementViewTestMesh();
        $placement = new ClusterPlacement(self::SELF, $mesh, new PlacementViewTestExecutor());
        $placement->onBecameLeader();
        $placement->onAgentStatus('node-b', PeerAgentStatusDTO::started('render', '9', 1));
        $placement->tick(1.0);
        $published = count($this->views($mesh));

        $placement->tick(2.0);
        $placement->tick(3.0);

        $this->assertCount($published, $this->views($mesh));
    }

    /**
     * A node that stops hosting an agent is news of the same kind as one that starts hosting it:
     * a copy that kept the departed agent would forward to a node that would drop the signal.
     */
    public function testThePublishedViewFollowsAgentsAway(): void
    {
        $mesh = new PlacementViewTestMesh();
        $placement = new ClusterPlacement(self::SELF, $mesh, new PlacementViewTestExecutor());
        $placement->onBecameLeader();
        $placement->onAgentStatus('node-b', PeerAgentStatusDTO::started('render', '9', 1));
        $placement->tick(1.0);

        $placement->onAgentStatus('node-b', PeerAgentStatusDTO::stopped('render', '9'));
        $placement->tick(2.0);

        $this->assertSame([], $this->lastView($mesh)->agents);
    }

    /**
     * A node that has just linked holds no picture at all, and the per-tick comparison only
     * speaks when something CHANGES — so without this hand-over the newcomer would wait for the
     * next placement anywhere in the cluster to learn about all the others.
     */
    public function testAFreshlyLinkedNodeIsHandedTheWholeView(): void
    {
        $mesh = new PlacementViewTestMesh();
        $placement = new ClusterPlacement(self::SELF, $mesh, new PlacementViewTestExecutor());
        $placement->onBecameLeader();
        $placement->onAgentStatus('node-b', PeerAgentStatusDTO::started('render', '9', 1));

        $placement->onPeerHandshaked('node-c');

        $handed = array_values(array_filter(
            $mesh->sent,
            static fn(array $sent): bool => $sent[1] instanceof PeerPlacementViewDTO,
        ));
        $this->assertCount(1, $handed);
        $this->assertSame('node-c', $handed[0][0]);
        $this->assertSame(['node-b'], array_keys($handed[0][1]->agents));
    }

    /**
     * A node that does not lead hands over no VIEW: the picture is not its to give, and a stale
     * copy passed on as fact is how two nodes end up forwarding to different places. What it does
     * hand over is its own placement report (HIL-719) — that one is its to give, being the list
     * of agents it hosts itself.
     */
    public function testANonLeaderHandsOverNoViewOnALink(): void
    {
        $mesh = new PlacementViewTestMesh();
        $placement = new ClusterPlacement(self::SELF, $mesh, new PlacementViewTestExecutor());
        $placement->onPlacementView('leader', $this->view(['node-b' => [['render', '9']]]));

        $placement->onPeerHandshaked('node-c');

        $handed = array_map(static fn(array $sent): PeerDTO => $sent[1], $mesh->sent);
        $this->assertCount(1, $handed);
        $this->assertInstanceOf(PeerPlacementReportDTO::class, $handed[0], 'A node speaks for what it hosts');
        $this->assertSame([], $handed[0]->agents, 'This one hosts nothing, and the copied view is not its own');
    }

    /**
     * Builds a coordinator on a node that has never taken a term.
     *
     * @return ClusterPlacement Placement coordinator with an empty registry
     */
    private function follower(): ClusterPlacement
    {
        return new ClusterPlacement(self::SELF, new PlacementViewTestMesh(), new PlacementViewTestExecutor());
    }

    /**
     * Builds a placement-view frame from a plain node-to-agents map.
     *
     * @param array<string|int, list<array{0: string, 1: ?string}>> $agents Agent type and index per node
     * @param string $leaderNodeId Node that stamped the frame as its own picture
     * @return PeerPlacementViewDTO View frame a leader would publish
     */
    private function view(array $agents, string $leaderNodeId = 'leader'): PeerPlacementViewDTO
    {
        $entries = [];
        foreach ($agents as $nodeId => $placed) {
            $entries[$nodeId] = array_map(
                static fn(array $agent): PeerPlacedAgentEntry => new PeerPlacedAgentEntry($agent[0], $agent[1]),
                $placed,
            );
        }

        return new PeerPlacementViewDTO($leaderNodeId, $entries);
    }

    /**
     * @param PlacementViewTestMesh $mesh Mesh the coordinator broadcast through
     * @return list<PeerPlacementViewDTO> Every view frame broadcast so far, in order
     */
    private function views(PlacementViewTestMesh $mesh): array
    {
        return array_values(array_filter(
            $mesh->broadcast,
            static fn(PeerDTO $frame): bool => $frame instanceof PeerPlacementViewDTO,
        ));
    }

    /**
     * @param PlacementViewTestMesh $mesh Mesh the coordinator broadcast through
     * @return PeerPlacementViewDTO Most recently broadcast view frame
     */
    private function lastView(PlacementViewTestMesh $mesh): PeerPlacementViewDTO
    {
        $views = $this->views($mesh);
        $this->assertNotEmpty($views, 'The leader published no view at all');

        return $views[count($views) - 1];
    }
}

/**
 * A mesh that keeps what it was told instead of sending it.
 */
final class PlacementViewTestMesh implements PlacementMesh
{
    /** @var list<array{0: string, 1: PeerDTO}> Node-addressed frames, as [nodeId, frame] */
    public array $sent = [];

    /** @var list<PeerDTO> Broadcast frames, in order */
    public array $broadcast = [];

    /**
     * @param string $nodeId Node the frame is addressed to
     * @param PeerDTO $frame Frame to deliver
     * @return bool True always; these cases need no unlinked node
     */
    public function sendToNode(string $nodeId, PeerDTO $frame): bool
    {
        $this->sent[] = [$nodeId, $frame];

        return true;
    }

    /**
     * @param PeerDTO $frame Frame to deliver to every node
     */
    public function broadcastToNodes(PeerDTO $frame): void
    {
        $this->broadcast[] = $frame;
    }

    /**
     * @param string $nodeId Node id to look up
     * @return ?list<string> Advertised capability tags; none here
     */
    public function nodeCapabilities(string $nodeId): ?array
    {
        return [];
    }

    /**
     * @return list<string> Currently-online node ids; none here
     */
    public function onlineNodeIds(): array
    {
        return [];
    }
}

/**
 * An executor these cases never reach: the view is built from what nodes report, not from what
 * this one runs.
 */
final class PlacementViewTestExecutor implements PlacementExecutor
{
    /**
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @return list<string> Required capability tags; none here
     */
    public function requiredCapabilities(string $agentType, ?string $agentIndex): array
    {
        return [];
    }

    /**
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @return ResourceProfile Empty profile
     */
    public function placementProfile(string $agentType, ?string $agentIndex): ResourceProfile
    {
        return ResourceProfile::none();
    }

    /**
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     * @return int Worker id a placement would land on
     */
    public function executePlacement(string $agentType, ?string $agentIndex): int
    {
        return 1;
    }

    /**
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index, or null for a singleton agent
     */
    public function revokePlacement(string $agentType, ?string $agentIndex): void
    {
    }
}

/**
 * Project facade declaring the two agent types these cases publish as policy-placed.
 *
 * Abstract because only its registry constant is read: nothing here builds a database.
 */
abstract class PlacementViewTestHilos extends Hilos
{
    public const array AGENTS = [
        'render' => [
            AgentRegistryKey::INDEXED => true,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        'chat' => [AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY],
    ];
}

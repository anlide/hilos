<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Cluster\Placement;

use Hilos\Cluster\ClusterContext;
use Hilos\Cluster\Leadership;
use Hilos\Cluster\Peer\DTO\PeerAgentStatusDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacedAgentEntry;
use Hilos\Cluster\Peer\DTO\PeerPlacementViewDTO;
use Hilos\Cluster\Placement\AgentLocationKind;
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Hilos;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Where an agent runs, answered in three cases instead of two (HIL-670).
 *
 * The defect this closes is that "the agent is here" and "nobody has told me where the agent
 * is" were one answer — null — so a signal to an agent placed elsewhere was delivered into the
 * local workers, which run no such agent. Nothing reports that: the send succeeds and the agent
 * never hears it.
 *
 * Which case applies is read off the agent's own declaration rather than off the presence of a
 * placement record, because the absence of a record means a different thing per cell: an
 * every-node replica never has one, a leader-hosted singleton is not placed through placement at
 * all, and only for a policy-placed one does a missing entry honestly mean "unknown".
 */
final class ClusterPlacementLocateTest extends TestCase
{
    private const string SELF = 'node-a';

    /** @var class-string<Hilos> App class bound before this test touched it */
    private string $boundAppClass;

    protected function setUp(): void
    {
        $this->boundAppClass = Hilos::appClass();
        $this->bindAppClass(LocateTestHilos::class);
    }

    protected function tearDown(): void
    {
        $this->bindAppClass($this->boundAppClass);
        Hilos::$cluster = null;

        parent::tearDown();
    }

    /**
     * An every-node replica runs on every node, so it runs on this one. Nothing places it and no
     * view names it, which under the old lookup was indistinguishable from an unplaced agent.
     */
    public function testAnEveryNodeReplicaIsAlwaysHere(): void
    {
        $placement = $this->follower(leaderId: 'node-b');

        $location = $placement->locate('presence', null);

        $this->assertSame(AgentLocationKind::Here, $location->kind);
        $this->assertNull($location->nodeId);
    }

    /**
     * A leader-hosted singleton runs wherever leadership sits, and leadership is asked directly:
     * such an agent never appears in the placement view, because it does not start through
     * placement.
     */
    public function testALeaderHostedSingletonIsOnTheLeaderNode(): void
    {
        $placement = $this->follower(leaderId: 'node-b');

        $location = $placement->locate('chat', null);

        $this->assertSame(AgentLocationKind::Node, $location->kind);
        $this->assertSame('node-b', $location->nodeId);
    }

    public function testALeaderHostedSingletonIsHereOnTheLeaderItself(): void
    {
        $placement = $this->follower(leaderId: self::SELF);

        $this->assertSame(AgentLocationKind::Here, $placement->locate('chat', null)->kind);
    }

    /**
     * A cluster between terms knows no leader, so it cannot name the node hosting a leader-hosted
     * agent. That is the answer to give: delivering locally would hand the signal to workers that
     * are not running it, and the node cannot know it is not the leader-to-be either.
     */
    public function testALeaderHostedSingletonIsUnknownWhileNobodyLeads(): void
    {
        $placement = $this->follower(leaderId: null);

        $location = $placement->locate('chat', null);

        $this->assertSame(AgentLocationKind::Unknown, $location->kind);
        $this->assertNull($location->nodeId);
    }

    /**
     * A policy-placed singleton is answered from the placement view, which is the only cell where
     * that view is the source at all.
     */
    public function testAPolicyPlacedSingletonComesFromThePlacementView(): void
    {
        $placement = $this->follower(leaderId: 'node-b');
        $placement->onPlacementView('node-b', $this->view(['node-c' => [['library', null]]]));

        $location = $placement->locate('library', null);

        $this->assertSame(AgentLocationKind::Node, $location->kind);
        $this->assertSame('node-c', $location->nodeId);
    }

    public function testAPolicyPlacedSingletonThisNodeHostsIsHere(): void
    {
        $placement = $this->follower(leaderId: 'node-b');
        $placement->onPlacementView('node-b', $this->view([self::SELF => [['library', null]]]));

        $this->assertSame(AgentLocationKind::Here, $placement->locate('library', null)->kind);
    }

    /**
     * The case the whole enum exists for: a follower whose view has not arrived yet, or an agent
     * the policy has not placed anywhere. Before this, both delivered locally.
     */
    public function testAPolicyPlacedSingletonNobodyPlacedIsUnknown(): void
    {
        $placement = $this->follower(leaderId: 'node-b');

        $location = $placement->locate('library', null);

        $this->assertSame(AgentLocationKind::Unknown, $location->kind);
        $this->assertNull($location->nodeId);
    }

    /**
     * An agent type the registry does not declare at all reads with the registry defaults —
     * cluster-wide and leader-hosted — rather than being treated as local. The default has to
     * err the same way the declared cell does, because an undeclared agent is exactly the one
     * nobody thought about.
     */
    public function testAnUndeclaredAgentTypeFollowsTheLeaderDefault(): void
    {
        $placement = $this->follower(leaderId: 'node-b');

        $this->assertSame('node-b', $placement->locate('never-declared', null)->nodeId);
    }

    /**
     * The leader answers a policy placement from the registry it owns, without a view: the view
     * is derived from that registry, and on the leader it is empty.
     */
    public function testTheLeaderAnswersAPolicyPlacementFromItsOwnRegistry(): void
    {
        $placement = $this->follower(leaderId: self::SELF);
        $placement->onBecameLeader();
        $placement->onAgentStatus('node-b', PeerAgentStatusDTO::started('library', null, 1));

        $this->assertSame('node-b', $placement->locate('library', null)->nodeId);
    }

    /**
     * Builds a coordinator on {@see SELF} over fake ports, with leadership answering a fixed id.
     *
     * @param ?string $leaderId Node id leadership reports, or null when nobody leads
     * @return ClusterPlacement Coordinator under test
     */
    private function follower(?string $leaderId): ClusterPlacement
    {
        $context = new ClusterContext();
        $context->registerLeadership(new LocateTestLeadership($leaderId, $leaderId === self::SELF));
        Hilos::$cluster = $context;

        return new ClusterPlacement(
            self::SELF,
            new FakePlacementMesh([]),
            new FakePlacementExecutor(),
        );
    }

    /**
     * Builds a placement view frame stamped by the leader that publishes it.
     *
     * @param array<string, list<array{0: string, 1: ?string}>> $agents Hosted agents per node id
     * @return PeerPlacementViewDTO View frame
     */
    private function view(array $agents): PeerPlacementViewDTO
    {
        $entries = [];
        foreach ($agents as $nodeId => $hosted) {
            $entries[$nodeId] = array_map(
                static fn(array $agent): PeerPlacedAgentEntry => new PeerPlacedAgentEntry($agent[0], $agent[1]),
                $hosted,
            );
        }

        return new PeerPlacementViewDTO('node-b', $entries);
    }

    /**
     * @param class-string<Hilos> $hilosClass App class to bind as the topology source
     */
    private function bindAppClass(string $hilosClass): void
    {
        new ReflectionProperty(Hilos::class, 'appClass')->setValue(null, $hilosClass);
    }
}

/**
 * Leadership seam answering a fixed leader id, so a case can put the leader anywhere — including
 * nowhere, which is what a cluster between terms looks like.
 */
final class LocateTestLeadership implements Leadership
{
    /**
     * @param ?string $leaderId Node id to report as the leader, or null when none is known
     * @param bool $amLeader Whether the local node is that leader
     */
    public function __construct(
        private readonly ?string $leaderId,
        private readonly bool $amLeader,
    ) {
    }

    public function amLeader(): bool
    {
        return $this->amLeader;
    }

    public function leaderId(): ?string
    {
        return $this->leaderId;
    }

    public function hasQuorum(): bool
    {
        return $this->leaderId !== null;
    }
}

/**
 * Project facade declaring one agent per cell the lookup has to tell apart.
 *
 * Abstract because only its registry constant is read: nothing here builds a database.
 */
abstract class LocateTestHilos extends Hilos
{
    public const array AGENTS = [
        'chat' => [],
        'presence' => [AgentRegistryKey::SCOPE => AgentScope::NODE],
        'library' => [AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY],
    ];
}

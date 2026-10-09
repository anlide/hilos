<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\Consensus\ConsensusRole;
use Hilos\Cluster\NodeRole;
use Hilos\DaemonSection\ClusterDaemonNodeSlot;
use Hilos\DaemonSection\ClusterDaemonNodeView;
use Hilos\DaemonSection\ClusterDaemonPicture;
use Hilos\DaemonSection\DaemonConsensusPicture;
use Hilos\DaemonSection\DaemonNodeStanding;
use Hilos\DaemonSection\DaemonNodeState;
use Hilos\DaemonSection\NodeDaemonPicture;
use PHPUnit\Framework\TestCase;

/** One state word from a cluster picture, including transient election views. */
final class DaemonNodeStateTest extends TestCase
{
    /** Missing facts have no word; silence and slave role do. */
    public function testKnownUnknownSilentAndStandaloneNodes(): void
    {
        $picture = ClusterDaemonPicture::empty();
        self::assertNull($picture->stateOf('missing'));
        self::assertNull($picture->leader());

        $picture = $picture->withNode(new ClusterDaemonNodeView('unknown', true, null));
        $picture = $picture->withNode(new ClusterDaemonNodeView('silent', false, null));
        $picture = $picture->withNode($this->node('data', NodeRole::Slave));
        $picture = $picture->withNode($this->node('pending', NodeRole::Master));
        $picture = $picture->withNode($this->node('standalone', NodeRole::Master, new DaemonNodeStanding(0, 0, false, null)));
        $picture = $picture->withNode($this->node('bare-master', NodeRole::Master, new DaemonNodeStanding(0, 0, true, null)));

        self::assertNull($picture->stateOf('unknown'));
        self::assertSame(DaemonNodeState::Silent, $picture->stateOf('silent'));
        self::assertSame(DaemonNodeState::Data, $picture->stateOf('data'));
        self::assertNull($picture->stateOf('pending'));
        self::assertNull($picture->stateOf('standalone'));
        self::assertSame(DaemonNodeState::Standby, $picture->stateOf('bare-master'));
    }

    /** A newer term retires the former leader before the new claimant arrives. */
    public function testHighestTermDecidesLeaderWithoutTwoStars(): void
    {
        $picture = ClusterDaemonPicture::empty()
            ->withNode($this->master('old', ConsensusRole::Leader, 3))
            ->withNode($this->master('new', ConsensusRole::Follower, 4));
        self::assertNull($picture->leader());
        self::assertSame(DaemonNodeState::Standby, $picture->stateOf('old'));

        $picture = $picture->withNode($this->master('new', ConsensusRole::Leader, 4));
        self::assertSame('new', $picture->leader()?->nodeId);
        self::assertSame(DaemonNodeState::Leader, $picture->stateOf('new'));
        self::assertSame(DaemonNodeState::Standby, $picture->stateOf('old'));

        $picture = $picture->withNode($this->master('candidate', ConsensusRole::Candidate, 5));
        self::assertNull($picture->leader());
        self::assertSame(DaemonNodeState::Standby, $picture->stateOf('new'));

        $picture = $picture->withNode($this->master('a', ConsensusRole::Leader, 5))
            ->withNode($this->master('b', ConsensusRole::Leader, 5));
        self::assertSame('a', $picture->leader()?->nodeId);
        self::assertSame(DaemonNodeState::Leader, $picture->stateOf('a'));
        self::assertSame(DaemonNodeState::Standby, $picture->stateOf('b'));

        $picture = $picture->withNode(new ClusterDaemonNodeView('a', false, $picture->node('a')?->slot));
        self::assertSame(DaemonNodeState::Silent, $picture->stateOf('a'));
        self::assertSame('b', $picture->leader()?->nodeId);
    }

    /**
     * @param string $id Node id
     * @param NodeRole $role Reported node role
     * @param ?DaemonNodeStanding $standing Optional master section
     * @return ClusterDaemonNodeView Node with a reported role and optional standing
     */
    private function node(string $id, NodeRole $role, ?DaemonNodeStanding $standing = null): ClusterDaemonNodeView
    {
        return new ClusterDaemonNodeView($id, true, new ClusterDaemonNodeSlot(
            $id, new NodeDaemonPicture($id, $role, 1, standing: $standing), 1,
        ));
    }

    /**
     * @param string $id Node id
     * @param ConsensusRole $role Election role
     * @param int $term Election term
     * @return ClusterDaemonNodeView Clustered master with a consensus view
     */
    private function master(string $id, ConsensusRole $role, int $term): ClusterDaemonNodeView
    {
        return $this->node($id, NodeRole::Master, new DaemonNodeStanding(0, 0, true,
            new DaemonConsensusPicture($role, $term, $role === ConsensusRole::Leader ? $id : null, 2, 3, 2),
        ));
    }
}

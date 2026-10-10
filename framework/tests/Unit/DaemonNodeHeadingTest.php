<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\Consensus\ConsensusRole;
use Hilos\Cluster\NodeRole;
use Hilos\DaemonSection\ClusterDaemonNodeSlot;
use Hilos\DaemonSection\ClusterDaemonNodeView;
use Hilos\DaemonSection\ClusterDaemonPicture;
use Hilos\DaemonSection\DaemonConsensusPicture;
use Hilos\DaemonSection\DaemonNodeHeading;
use Hilos\DaemonSection\DaemonNodeStanding;
use Hilos\DaemonSection\NodeDaemonPicture;
use PHPUnit\Framework\TestCase;

/** Node line keeps only the last arrival time of a silent node. */
final class DaemonNodeHeadingTest extends TestCase
{
    public function testMissingPictureAndUnknownNodeHaveNoStateOrTime(): void
    {
        self::assertSame(['clustered' => false, 'state' => null, 'silentSince' => null],
            DaemonNodeHeading::of(null, 'n1', false)->toArray());
        self::assertSame(['clustered' => true, 'state' => null, 'silentSince' => null],
            DaemonNodeHeading::of(ClusterDaemonPicture::empty(), 'n1', true)->toArray());
    }

    public function testStateWordsComeFromThePicture(): void
    {
        $picture = ClusterDaemonPicture::empty()
            ->withNode($this->node('leader', NodeRole::Master, true, true))
            ->withNode($this->node('standby', NodeRole::Master, true, false))
            ->withNode($this->node('data', NodeRole::Slave));

        self::assertSame('leader', DaemonNodeHeading::of($picture, 'leader', true)->toArray()['state']);
        self::assertSame('standby', DaemonNodeHeading::of($picture, 'standby', true)->toArray()['state']);
        self::assertSame('data', DaemonNodeHeading::of($picture, 'data', true)->toArray()['state']);
        self::assertNull(DaemonNodeHeading::of($picture, 'leader', true)->toArray()['silentSince']);
    }

    public function testSilentNodeUsesLastSlotArrivalAndCanHaveNoSlot(): void
    {
        $picture = ClusterDaemonPicture::empty()
            ->withNode(new ClusterDaemonNodeView('with-slot', false, new ClusterDaemonNodeSlot(
                'with-slot', new NodeDaemonPicture('with-slot', NodeRole::Master, 12), 42,
            )))
            ->withNode(new ClusterDaemonNodeView('without-slot', false, null));

        self::assertSame(['clustered' => true, 'state' => 'silent', 'silentSince' => 42],
            DaemonNodeHeading::of($picture, 'with-slot', true)->toArray());
        self::assertSame(['clustered' => true, 'state' => 'silent', 'silentSince' => null],
            DaemonNodeHeading::of($picture, 'without-slot', true)->toArray());
    }

    public function testStandaloneHasNoWordAndLiveArrivalTimeDoesNotEnterTheLine(): void
    {
        $picture = ClusterDaemonPicture::empty()->withNode($this->node('standalone', NodeRole::Master, false));
        $line = DaemonNodeHeading::of($picture, 'standalone', false)->toArray();

        self::assertSame(['clustered', 'state', 'silentSince'], array_keys($line));
        self::assertSame(['clustered' => false, 'state' => null, 'silentSince' => null], $line);
    }

    /**
     * @param string $id Node id
     * @param NodeRole $role Reported role
     * @param bool $clustered Whether its standing is clustered
     * @param bool $leader Whether this master claims leadership
     * @return ClusterDaemonNodeView Live node with a received slot
     */
    private function node(string $id, NodeRole $role, bool $clustered = true, bool $leader = false): ClusterDaemonNodeView
    {
        $consensus = $leader ? new DaemonConsensusPicture(ConsensusRole::Leader, 1, $id, 2, 3, 2) : null;
        $standing = $role === NodeRole::Master ? new DaemonNodeStanding(0, 0, $clustered, $consensus) : null;

        return new ClusterDaemonNodeView($id, true, new ClusterDaemonNodeSlot(
            $id, new NodeDaemonPicture($id, $role, 1, standing: $standing), 42,
        ));
    }
}

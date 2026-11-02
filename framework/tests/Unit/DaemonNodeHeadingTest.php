<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\Consensus\ConsensusRole;
use Hilos\Cluster\NodeRole;
use Hilos\DaemonSection\ClusterDaemonNodeSlot;
use Hilos\DaemonSection\ClusterDaemonNodeView;
use Hilos\DaemonSection\ClusterDaemonPicture;
use Hilos\DaemonSection\DaemonClockPicture;
use Hilos\DaemonSection\DaemonClockReading;
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
        self::assertSame([
            'clustered' => false, 'state' => null, 'silentSince' => null, 'role' => null,
            'clock' => null,
        ],
            DaemonNodeHeading::of(null, 'n1', false)->toArray());
        self::assertSame([
            'clustered' => true, 'state' => null, 'silentSince' => null, 'role' => null,
            'clock' => [
                'reference' => false, 'offsetMs' => null, 'measuredAt' => null, 'thresholdMs' => null, 'beyond' => false,
            ],
        ],
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
        self::assertSame('master', DaemonNodeHeading::of($picture, 'leader', true)->toArray()['role']);
        self::assertSame('master', DaemonNodeHeading::of($picture, 'standby', true)->toArray()['role']);
        self::assertSame('slave', DaemonNodeHeading::of($picture, 'data', true)->toArray()['role']);
        self::assertNull(DaemonNodeHeading::of($picture, 'leader', true)->toArray()['silentSince']);
    }

    public function testSilentNodeUsesLastSlotArrivalAndCanHaveNoSlot(): void
    {
        $picture = ClusterDaemonPicture::empty()
            ->withNode(new ClusterDaemonNodeView('with-slot', false, new ClusterDaemonNodeSlot(
                'with-slot', new NodeDaemonPicture('with-slot', NodeRole::Master, 12), 42,
            )))
            ->withNode(new ClusterDaemonNodeView('without-slot', false, null));

        self::assertSame([
            'clustered' => true, 'state' => 'silent', 'silentSince' => 42000, 'role' => 'master',
            'clock' => [
                'reference' => false, 'offsetMs' => null, 'measuredAt' => null, 'thresholdMs' => null, 'beyond' => false,
            ],
        ],
            DaemonNodeHeading::of($picture, 'with-slot', true)->toArray());
        self::assertSame([
            'clustered' => true, 'state' => 'silent', 'silentSince' => null, 'role' => null,
            'clock' => [
                'reference' => false, 'offsetMs' => null, 'measuredAt' => null, 'thresholdMs' => null, 'beyond' => false,
            ],
        ],
            DaemonNodeHeading::of($picture, 'without-slot', true)->toArray());
    }

    public function testStandaloneHasNoWordAndLiveArrivalTimeDoesNotEnterTheLine(): void
    {
        $picture = ClusterDaemonPicture::empty()->withNode($this->node('standalone', NodeRole::Master, false));
        $line = DaemonNodeHeading::of($picture, 'standalone', false)->toArray();

        self::assertSame(['clustered', 'state', 'silentSince', 'role', 'clock'], array_keys($line));
        self::assertSame([
            'clustered' => false, 'state' => null, 'silentSince' => null, 'role' => 'master',
            'clock' => null,
        ], $line);
    }

    public function testReferenceAndUnmeasuredNodesUseTheLeaderFrameThreshold(): void
    {
        $picture = ClusterDaemonPicture::empty()
            ->withNode($this->node('leader', NodeRole::Master, leader: true, clock: new DaemonClockPicture(300, [
                new DaemonClockReading('leader', 900, 1000),
            ])))
            ->withNode($this->node('peer', NodeRole::Slave));

        self::assertSame([
            'reference' => true, 'offsetMs' => null, 'measuredAt' => null, 'thresholdMs' => 300, 'beyond' => false,
        ], DaemonNodeHeading::of($picture, 'leader', true)->toArray()['clock']);
        self::assertSame([
            'reference' => false, 'offsetMs' => null, 'measuredAt' => null, 'thresholdMs' => 300, 'beyond' => false,
        ], DaemonNodeHeading::of($picture, 'peer', true)->toArray()['clock']);
        self::assertNull(DaemonNodeHeading::of($picture, 'peer', false)->toArray()['clock']);

        $picture = $picture->withNode($this->node('leader', NodeRole::Master, leader: true));
        self::assertSame([
            'reference' => true, 'offsetMs' => null, 'measuredAt' => null, 'thresholdMs' => null, 'beyond' => false,
        ], DaemonNodeHeading::of($picture, 'leader', true)->toArray()['clock']);
    }

    public function testMeasurementsUseAStrictAbsoluteThresholdAndKeepTheLeaderMoment(): void
    {
        foreach ([[-301, true], [-300, false], [0, false], [300, false], [301, true]] as [$offsetMs, $beyond]) {
            $picture = ClusterDaemonPicture::empty()
                ->withNode($this->node('leader', NodeRole::Master, leader: true, clock: new DaemonClockPicture(300, [
                    new DaemonClockReading('peer', $offsetMs, 1_790_000_000_123),
                ])))
                ->withNode($this->node('peer', NodeRole::Slave));

            self::assertSame([
                'reference' => false, 'offsetMs' => $offsetMs, 'measuredAt' => 1_790_000_000_123,
                'thresholdMs' => 300, 'beyond' => $beyond,
            ], DaemonNodeHeading::of($picture, 'peer', true)->toArray()['clock']);
        }
    }

    public function testOnlyTheCurrentLeadersSlotSuppliesReadingsAndElectionsHaveNoReference(): void
    {
        $picture = ClusterDaemonPicture::empty()
            ->withNode($this->node('old', NodeRole::Master, leader: true, clock: new DaemonClockPicture(300, [
                new DaemonClockReading('peer', 900, 1000),
            ])))
            ->withNode($this->node('peer', NodeRole::Master, clock: new DaemonClockPicture(1, [
                new DaemonClockReading('peer', 999, 9999),
            ])));
        $candidate = new DaemonNodeStanding(0, 0, true,
            new DaemonConsensusPicture(ConsensusRole::Candidate, 2, null, 2, 3, 2), null,
            new DaemonClockPicture(100, [new DaemonClockReading('peer', -55, 2000)]));
        $new = $this->node('new', NodeRole::Master);
        $picture = $picture->withNode(new ClusterDaemonNodeView('new', true, new ClusterDaemonNodeSlot(
            'new', $new->slot->picture->withStanding($candidate), 43,
        )));

        foreach (['old', 'new', 'peer'] as $id) {
            self::assertSame([
                'reference' => false, 'offsetMs' => null, 'measuredAt' => null, 'thresholdMs' => null, 'beyond' => false,
            ], DaemonNodeHeading::of($picture, $id, true)->toArray()['clock']);
        }

        $picture = $picture->withNode($this->node('new', NodeRole::Master, leader: true,
            clock: new DaemonClockPicture(100, [new DaemonClockReading('peer', -55, 2000)]), term: 2));
        self::assertSame([
            'reference' => false, 'offsetMs' => -55, 'measuredAt' => 2000, 'thresholdMs' => 100, 'beyond' => false,
        ], DaemonNodeHeading::of($picture, 'peer', true)->toArray()['clock']);
        self::assertTrue(DaemonNodeHeading::of($picture, 'new', true)->clock->reference);
        self::assertFalse(DaemonNodeHeading::of($picture, 'old', true)->clock->reference);
    }

    public function testSilentNodeRetainsItsLastMeasurementFromTheLeader(): void
    {
        $peer = $this->node('peer', NodeRole::Slave);
        $picture = ClusterDaemonPicture::empty()
            ->withNode($this->node('leader', NodeRole::Master, leader: true, clock: new DaemonClockPicture(300, [
                new DaemonClockReading('peer', -450, 1_790_000_000_123),
            ])))
            ->withNode($peer);
        $clock = DaemonNodeHeading::of($picture, 'peer', true)->toArray()['clock'];
        $silent = $picture->withNode(new ClusterDaemonNodeView('peer', false, $peer->slot));

        self::assertSame([
            'reference' => false, 'offsetMs' => -450, 'measuredAt' => 1_790_000_000_123, 'thresholdMs' => 300, 'beyond' => true,
        ], $clock);
        self::assertSame($clock, DaemonNodeHeading::of($silent, 'peer', true)->toArray()['clock']);
        self::assertSame('silent', DaemonNodeHeading::of($silent, 'peer', true)->toArray()['state']);
    }

    /**
     * @param string $id Node id
     * @param NodeRole $role Reported role
     * @param bool $clustered Whether its standing is clustered
     * @param bool $leader Whether this master claims leadership
     * @param ?DaemonClockPicture $clock This master's reported clock picture
     * @param int $term Reported consensus term
     * @return ClusterDaemonNodeView Live node with a received slot
     */
    private function node(
        string $id,
        NodeRole $role,
        bool $clustered = true,
        bool $leader = false,
        ?DaemonClockPicture $clock = null,
        int $term = 1,
    ): ClusterDaemonNodeView
    {
        $consensus = $leader ? new DaemonConsensusPicture(ConsensusRole::Leader, $term, $id, 2, 3, 2) : null;
        $standing = $role === NodeRole::Master ? new DaemonNodeStanding(0, 0, $clustered, $consensus, null, $clock) : null;

        return new ClusterDaemonNodeView($id, true, new ClusterDaemonNodeSlot(
            $id, new NodeDaemonPicture($id, $role, 1, standing: $standing), 42,
        ));
    }
}

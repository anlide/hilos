<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\Consensus\ConsensusRole;
use Hilos\Cluster\NodeRole;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\DaemonSection\ClusterDaemonNodeSlot;
use Hilos\DaemonSection\ClusterDaemonNodeView;
use Hilos\DaemonSection\DaemonConsensusPicture;
use Hilos\DaemonSection\DaemonNodeStanding;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\DTO\DaemonMasterStandingSignalData;
use Hilos\DaemonSection\DTO\DaemonNodePictureSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use PHPUnit\Framework\TestCase;

/** Strict standing contract shared by the master, node agent, and collector. */
final class DaemonMasterStandingSignalDataTest extends TestCase
{
    /** The same nested shape is preserved by all three process boundaries. */
    public function testStandingSurvivesMasterNodeAndClusterWire(): void
    {
        $standing = new DaemonNodeStanding(2, 3, true, new DaemonConsensusPicture(
            ConsensusRole::Follower, 7, 'n2', 2, 3, 2,
        ));
        $master = new DaemonMasterStandingSignalData('n1', $standing);
        self::assertEquals($master, DaemonMasterStandingSignalData::fromArray($master->toArray()));

        $picture = new NodeDaemonPicture('n1', NodeRole::Master, 10, standing: $standing);
        $node = new DaemonNodePictureSignalData($picture);
        self::assertEquals($picture, DaemonNodePictureSignalData::fromArray($node->toArray())->picture);
        self::assertFalse($picture->sameContent($picture->withStanding(null)));
        self::assertSame($standing, $picture->sampledAt(11)->withProcesses(null)->withCron(null)
            ->withEnvironment(null)->standing);

        $cluster = new DaemonClusterPicturePortionSignalData(true, [
            new ClusterDaemonNodeView('n1', true, new ClusterDaemonNodeSlot('n1', $picture, 11)),
        ]);
        self::assertEquals($cluster, DaemonClusterPicturePortionSignalData::fromArray($cluster->toArray()));
        self::assertSame($node->toArray(), $cluster->toArray()['nodes'][0]['picture']);
    }

    /** Unknown standing stays distinct from an observed empty node. */
    public function testNullAndKnownZeroRemainDifferent(): void
    {
        $unknown = new DaemonNodePictureSignalData(new NodeDaemonPicture('n1', NodeRole::Master, 10));
        self::assertArrayHasKey(DaemonNodePictureSignalData::standing, $unknown->toArray());
        self::assertNull(DaemonNodePictureSignalData::fromArray($unknown->toArray())->picture->standing);

        $zero = new DaemonNodePictureSignalData(new NodeDaemonPicture(
            'n1', NodeRole::Master, 10, standing: new DaemonNodeStanding(0, 0, false, null),
        ));
        self::assertSame(0, $zero->toArray()['standing']['sessions']);
        self::assertEquals($zero, DaemonNodePictureSignalData::fromArray($zero->toArray()));
    }

    /** Every required wire key and cross-field range is checked at parse time. */
    public function testMalformedRequiredFieldsAndRangesAreRefused(): void
    {
        $good = (new DaemonMasterStandingSignalData('n1', new DaemonNodeStanding(
            1, 2, true, new DaemonConsensusPicture(ConsensusRole::Leader, 1, 'n1', 2, 3, 2),
        )))->toArray();
        $bad = [];
        $missing = $good;
        unset($missing['sessions']);
        $bad[] = $missing;
        $missing = $good;
        unset($missing['consensus']['leaderId']);
        $bad[] = $missing;
        $role = $good;
        $role['consensus']['role'] = 'primary';
        $bad[] = $role;
        $standalone = $good;
        $standalone['clustered'] = false;
        $bad[] = $standalone;
        $online = $good;
        $online['consensus']['quorum']['online'] = 4;
        $bad[] = $online;
        $needed = $good;
        $needed['consensus']['quorum']['needed'] = 4;
        $bad[] = $needed;
        $sessions = $good;
        $sessions['sessions'] = 3;
        $bad[] = $sessions;
        $leader = $good;
        $leader['consensus']['leaderId'] = '';
        $bad[] = $leader;
        foreach ($bad as $payload) {
            try {
                DaemonMasterStandingSignalData::fromArray($payload);
                self::fail('Malformed standing was accepted');
            } catch (InvalidFormatException) {
            }
        }

        $node = (new DaemonNodePictureSignalData(new NodeDaemonPicture('n1', NodeRole::Master, 10)))->toArray();
        unset($node['standing']);
        $this->expectException(InvalidFormatException::class);
        DaemonNodePictureSignalData::fromArray($node);
    }
}

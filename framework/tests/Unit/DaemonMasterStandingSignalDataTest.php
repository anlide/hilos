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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Strict standing contract shared by the master, node agent, and collector. */
final class DaemonMasterStandingSignalDataTest extends TestCase
{
    /** The same nested shape is preserved by all three process boundaries. */
    #[DataProvider('commandPorts')]
    public function testStandingSurvivesMasterNodeAndClusterWire(?int $commandPort): void
    {
        $standing = new DaemonNodeStanding(2, 3, true, new DaemonConsensusPicture(
            ConsensusRole::Follower, 7, 'n2', 2, 3, 2,
        ), $commandPort);
        $master = new DaemonMasterStandingSignalData('n1', $standing);
        self::assertArrayHasKey('commandPort', $master->toArray());
        self::assertSame($commandPort, $master->toArray()['commandPort']);
        $master = DaemonMasterStandingSignalData::fromArray($master->toArray());
        self::assertEquals($standing, $master->standing);

        $picture = new NodeDaemonPicture('n1', NodeRole::Master, 10, standing: $master->standing);
        $node = new DaemonNodePictureSignalData($picture);
        self::assertArrayHasKey('commandPort', $node->toArray()['standing']);
        self::assertSame($commandPort, $node->toArray()['standing']['commandPort']);
        $parsedPicture = DaemonNodePictureSignalData::fromArray($node->toArray())->picture;
        self::assertEquals($picture, $parsedPicture);
        self::assertFalse($picture->sameContent($picture->withStanding(null)));
        self::assertFalse($picture->sameContent($picture->withStanding(new DaemonNodeStanding(
            2, 3, true, $standing->consensus, $commandPort === null ? 9000 : null,
        ))));
        self::assertSame($master->standing, $picture->sampledAt(11)->withProcesses(null)->withCron(null)
            ->withEnvironment(null)->standing);

        $cluster = new DaemonClusterPicturePortionSignalData(true, [
            new ClusterDaemonNodeView('n1', true, new ClusterDaemonNodeSlot('n1', $parsedPicture, 11)),
        ]);
        self::assertEquals($cluster, DaemonClusterPicturePortionSignalData::fromArray($cluster->toArray()));
        self::assertSame($node->toArray(), $cluster->toArray()['nodes'][0]['picture']);
        self::assertSame($commandPort, $cluster->toArray()['nodes'][0]['picture']['standing']['commandPort']);
    }

    /** @return iterable<string, array{?int}> Known listener ports and an absent listener */
    public static function commandPorts(): iterable
    {
        yield 'absent' => [null];
        yield 'minimum' => [0];
        yield 'registered' => [9013];
        yield 'maximum' => [65535];
    }

    /** Unknown standing stays distinct from an observed empty node. */
    public function testNullAndKnownZeroRemainDifferent(): void
    {
        $unknown = new DaemonNodePictureSignalData(new NodeDaemonPicture('n1', NodeRole::Master, 10));
        self::assertArrayHasKey(DaemonNodePictureSignalData::standing, $unknown->toArray());
        self::assertNull(DaemonNodePictureSignalData::fromArray($unknown->toArray())->picture->standing);

        $zero = new DaemonNodePictureSignalData(new NodeDaemonPicture(
            'n1', NodeRole::Master, 10, standing: new DaemonNodeStanding(0, 0, false, null, null),
        ));
        self::assertSame(0, $zero->toArray()['standing']['sessions']);
        self::assertEquals($zero, DaemonNodePictureSignalData::fromArray($zero->toArray()));
    }

    /** Every required wire key and cross-field range is checked at parse time. */
    public function testMalformedRequiredFieldsAndRangesAreRefused(): void
    {
        $good = (new DaemonMasterStandingSignalData('n1', new DaemonNodeStanding(
            1, 2, true, new DaemonConsensusPicture(ConsensusRole::Leader, 1, 'n1', 2, 3, 2), null,
        )))->toArray();
        $bad = [];
        $missing = $good;
        unset($missing['sessions']);
        $bad[] = $missing;
        $missing = $good;
        unset($missing['commandPort']);
        $bad[] = $missing;
        foreach (['9013', 9013.0, true, false, -1, 65536] as $commandPort) {
            $port = $good;
            $port['commandPort'] = $commandPort;
            $bad[] = $port;
        }
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

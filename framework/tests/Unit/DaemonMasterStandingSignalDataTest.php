<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\Consensus\ConsensusRole;
use Hilos\Cluster\NodeRole;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\DaemonSection\ClusterDaemonNodeSlot;
use Hilos\DaemonSection\ClusterDaemonNodeView;
use Hilos\DaemonSection\ClusterDaemonPictureMirror;
use Hilos\DaemonSection\DaemonClockPicture;
use Hilos\DaemonSection\DaemonClockReading;
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

    /**
     * @param ?DaemonClockPicture $clock Leader's measurements, or explicit absence
     * @param ?array<string, mixed> $expectedClock Exact clock wire shape
     */
    #[DataProvider('clockPictures')]
    public function testClockSurvivesMasterNodePortionsAndMirror(?DaemonClockPicture $clock, ?array $expectedClock): void
    {
        $standing = new DaemonNodeStanding(0, 0, true, new DaemonConsensusPicture(
            ConsensusRole::Leader, 7, 'n1', 2, 3, 2,
        ), null, $clock);
        $master = new DaemonMasterStandingSignalData('n1', $standing);
        self::assertArrayHasKey('clock', $master->toArray());
        self::assertSame($expectedClock, $master->toArray()['clock']);
        self::assertEquals($master, DaemonMasterStandingSignalData::fromArray($master->toArray()));
        self::assertEquals($master, DaemonMasterStandingSignalData::fromJson($master->toJson()));

        $picture = new NodeDaemonPicture('n1', NodeRole::Master, 10, standing: $standing);
        $node = new DaemonNodePictureSignalData($picture);
        self::assertSame($expectedClock, $node->toArray()['standing']['clock']);
        self::assertEquals($picture, DaemonNodePictureSignalData::fromArray($node->toArray())->picture);

        try {
            foreach ([true, false] as $snapshot) {
                $portion = new DaemonClusterPicturePortionSignalData($snapshot, [
                    new ClusterDaemonNodeView('n1', true, new ClusterDaemonNodeSlot('n1', $picture, 11), 'Control'),
                ]);
                self::assertSame($expectedClock, $portion->toArray()['nodes'][0]['picture']['standing']['clock']);
                $parsed = DaemonClusterPicturePortionSignalData::fromArray($portion->toArray());
                self::assertEquals($portion, $parsed);
                ClusterDaemonPictureMirror::applyPortion($parsed);
                self::assertSame('Control', ClusterDaemonPictureMirror::picture()?->node('n1')?->name);
                self::assertEquals($standing, ClusterDaemonPictureMirror::picture()?->node('n1')?->slot?->picture->standing);
            }
        } finally {
            ClusterDaemonPictureMirror::forgetPicture();
        }
    }

    /** @return iterable<string, array{?DaemonClockPicture, ?array<string, mixed>}> Absent, waiting, and measured clock sections */
    public static function clockPictures(): iterable
    {
        yield 'absent' => [null, null];
        yield 'no completed readings' => [new DaemonClockPicture(300, []), ['thresholdMs' => 300, 'nodes' => []]];
        yield 'signed and zero offsets' => [new DaemonClockPicture(300, [
            new DaemonClockReading('n2', -500, 1_800_000_000_123),
            new DaemonClockReading('n3', 0, 1_800_000_000_456),
            new DaemonClockReading('n4', 120, 1_800_000_000_789),
        ]), ['thresholdMs' => 300, 'nodes' => [
            ['nodeId' => 'n2', 'offsetMs' => -500, 'measuredAt' => 1_800_000_000_123],
            ['nodeId' => 'n3', 'offsetMs' => 0, 'measuredAt' => 1_800_000_000_456],
            ['nodeId' => 'n4', 'offsetMs' => 120, 'measuredAt' => 1_800_000_000_789],
        ]]];
    }

    /** A completed reading is found by id, and every clock change devalues the node picture. */
    public function testClockLookupAndContentChanges(): void
    {
        $reading = new DaemonClockReading('n2', -500, 1_800_000_000_123);
        $clock = new DaemonClockPicture(300, [$reading]);
        self::assertSame($reading, $clock->reading('n2'));
        self::assertNull($clock->reading('n1'), 'The leader has no reading of itself');
        self::assertNull($clock->reading('unknown'));
        self::assertNull(new DaemonClockPicture(300, [])->reading('n2'));

        $picture = new NodeDaemonPicture('n1', NodeRole::Master, 10, standing: new DaemonNodeStanding(0, 0, true, null, null, $clock));
        self::assertTrue($picture->sameContent($picture->sampledAt(11)));
        foreach ([
            null,
            new DaemonClockPicture(300, []),
            new DaemonClockPicture(301, [$reading]),
            new DaemonClockPicture(300, [new DaemonClockReading('n2', -501, $reading->measuredAt)]),
            new DaemonClockPicture(300, [new DaemonClockReading('n2', $reading->offsetMs, $reading->measuredAt + 1)]),
        ] as $changed) {
            self::assertFalse($picture->sameContent($picture->withStanding(new DaemonNodeStanding(0, 0, true, null, null, $changed))));
        }
    }

    /** Clock data cannot be attached to a standalone standing even without consensus. */
    public function testStandaloneCannotCarryClock(): void
    {
        $this->expectException(InvalidFormatException::class);
        new DaemonNodeStanding(0, 0, false, null, null, new DaemonClockPicture(300, []));
    }

    /** Missing clock is refused at every boundary rather than treated as explicit null. */
    public function testClockKeyIsRequiredInMasterNodeAndPortions(): void
    {
        $master = (new DaemonMasterStandingSignalData('n1', new DaemonNodeStanding(0, 0, false, null, null)))->toArray();
        $node = (new DaemonNodePictureSignalData(new NodeDaemonPicture(
            'n1', NodeRole::Master, 10, standing: new DaemonNodeStanding(0, 0, false, null, null),
        )))->toArray();
        unset($master['clock'], $node['standing']['clock']);
        $portion = ['snapshot' => true, 'nodes' => [
            ['nodeId' => 'n1', 'online' => true, 'receivedAt' => 11, 'picture' => $node],
        ]];
        foreach ([
            [DaemonMasterStandingSignalData::class, $master],
            [DaemonNodePictureSignalData::class, $node],
            [DaemonClusterPicturePortionSignalData::class, $portion],
        ] as [$dtoClass, $payload]) {
            try {
                $dtoClass::fromArray($payload);
                self::fail('A standing without the clock key was accepted');
            } catch (InvalidFormatException $failure) {
                self::assertStringContainsString('clock', $failure->getMessage());
            }
        }
    }

    /** @param mixed $clock Malformed clock wire value */
    #[DataProvider('malformedClocks')]
    public function testMalformedClockIsRefused(mixed $clock): void
    {
        $payload = (new DaemonMasterStandingSignalData('n1', new DaemonNodeStanding(0, 0, true, null, null)))->toArray();
        $payload['clock'] = $clock;
        $this->expectException(InvalidFormatException::class);
        DaemonMasterStandingSignalData::fromArray($payload);
    }

    /** @return iterable<string, array{mixed}> Wrong clock shapes, fields, and reading elements */
    public static function malformedClocks(): iterable
    {
        foreach ([false, true, 0, 300, 300.0, 'clock', [], [300, []]] as $index => $clock) {
            yield 'clock type or shape ' . $index => [$clock];
        }
        yield 'missing threshold' => [['nodes' => []]];
        yield 'missing nodes' => [['thresholdMs' => 300]];
        foreach ([null, '300', 300.0, false, 0, -1] as $index => $threshold) {
            yield 'threshold ' . $index => [['thresholdMs' => $threshold, 'nodes' => []]];
        }
        foreach ([null, false, 0, 'nodes', ['n2' => []], [1 => []]] as $index => $nodes) {
            yield 'nodes ' . $index => [['thresholdMs' => 300, 'nodes' => $nodes]];
        }
        $goodReading = ['nodeId' => 'n2', 'offsetMs' => -500, 'measuredAt' => 1_800_000_000_123];
        foreach ([null, false, 0, 'node', [], ['n2', -500, 1_800_000_000_123]] as $index => $reading) {
            yield 'reading type or shape ' . $index => [['thresholdMs' => 300, 'nodes' => [$reading]]];
        }
        foreach (array_keys($goodReading) as $key) {
            $reading = $goodReading;
            unset($reading[$key]);
            yield 'missing ' . $key => [['thresholdMs' => 300, 'nodes' => [$reading]]];
        }
        foreach ([null, false, 2, []] as $index => $nodeId) {
            yield 'nodeId ' . $index => [['thresholdMs' => 300, 'nodes' => [array_replace($goodReading, ['nodeId' => $nodeId])]]];
        }
        yield 'empty nodeId' => [['thresholdMs' => 300, 'nodes' => [array_replace($goodReading, ['nodeId' => ''])]]];
        foreach (['offsetMs', 'measuredAt'] as $key) {
            foreach ([null, false, '123', 123.0, []] as $index => $value) {
                yield $key . ' type ' . $index => [['thresholdMs' => 300, 'nodes' => [array_replace($goodReading, [$key => $value])]]];
            }
        }
        yield 'duplicate nodeId' => [['thresholdMs' => 300, 'nodes' => [$goodReading, $goodReading]]];
        yield 'unsorted nodeIds' => [['thresholdMs' => 300, 'nodes' => [
            array_replace($goodReading, ['nodeId' => 'n3']), $goodReading,
        ]]];
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
        self::assertArrayHasKey('clock', $zero->toArray()['standing']);
        self::assertNull($zero->toArray()['standing']['clock']);
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

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Cluster\NodeRole;
use Hilos\Core\Daemon\DaemonMeasurements;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\DaemonSection\ClusterDaemonNodeSlot;
use Hilos\DaemonSection\ClusterDaemonNodeView;
use Hilos\DaemonSection\DTO\DaemonClusterPicturePortionSignalData;
use Hilos\DaemonSection\DTO\DaemonMasterMeasurementsSignalData;
use Hilos\DaemonSection\DTO\DaemonNodePictureSignalData;
use Hilos\DaemonSection\NodeDaemonPicture;
use PHPUnit\Framework\TestCase;

/** Strict measurement contract across the master, node agent, and collector. */
final class DaemonMasterMeasurementsSignalDataTest extends TestCase
{
    /** The same measurement survives every process boundary. */
    public function testMeasurementSurvivesMasterNodeAndClusterWire(): void
    {
        $measurements = $this->measurements(1048576);
        $master = new DaemonMasterMeasurementsSignalData('n1', $measurements);
        self::assertEquals($master, DaemonMasterMeasurementsSignalData::fromArray($master->toArray()));

        $picture = new NodeDaemonPicture('n1', NodeRole::Master, 10, measurements: $measurements);
        $node = new DaemonNodePictureSignalData($picture);
        self::assertEquals($picture, DaemonNodePictureSignalData::fromArray($node->toArray())->picture);

        $cluster = new DaemonClusterPicturePortionSignalData(true, [
            new ClusterDaemonNodeView('n1', true, new ClusterDaemonNodeSlot('n1', $picture, 11)),
        ]);
        self::assertEquals($cluster, DaemonClusterPicturePortionSignalData::fromArray($cluster->toArray()));
        self::assertSame($node->toArray(), $cluster->toArray()['nodes'][0]['picture']);
    }

    /** No frame is represented by null, distinct from a valid zero measurement. */
    public function testUnknownAndKnownZeroRemainDifferent(): void
    {
        $unknown = new DaemonNodePictureSignalData(new NodeDaemonPicture('n1', NodeRole::Master, 10));
        self::assertArrayHasKey(DaemonNodePictureSignalData::measurements, $unknown->toArray());
        self::assertNull(DaemonNodePictureSignalData::fromArray($unknown->toArray())->picture->measurements);

        $known = new DaemonNodePictureSignalData(new NodeDaemonPicture(
            'n1', NodeRole::Master, 10, measurements: $this->measurements(0),
        ));
        self::assertSame(0, $known->toArray()['measurements']['workersMemory']);
        self::assertEquals($known, DaemonNodePictureSignalData::fromArray($known->toArray()));
    }

    /** Missing, mistyped, and inconsistent fields must be rejected at the boundary. */
    public function testMalformedMeasurementIsRefused(): void
    {
        $good = (new DaemonMasterMeasurementsSignalData('n1', $this->measurements(0)))->toArray();
        $bad = [];
        $missing = $good;
        unset($missing['startedAt']);
        $bad[] = $missing;
        $missing = $good;
        unset($missing['masterMemory']);
        $bad[] = $missing;
        $mistyped = $good;
        $mistyped['daemonCores'] = '0';
        $bad[] = $mistyped;
        $unpaired = $good;
        $unpaired['coresBound'] = null;
        $bad[] = $unpaired;
        $empty = $good;
        $empty['nodeId'] = '';
        $bad[] = $empty;
        foreach ($bad as $payload) {
            try {
                DaemonMasterMeasurementsSignalData::fromArray($payload);
                self::fail('Malformed measurement was accepted');
            } catch (InvalidFormatException) {
            }
        }

        $node = (new DaemonNodePictureSignalData(new NodeDaemonPicture('n1', NodeRole::Master, 10)))->toArray();
        unset($node['measurements']);
        $this->expectException(InvalidFormatException::class);
        DaemonNodePictureSignalData::fromArray($node);
    }

    /**
     * @param int $workerBytes Rounded worker memory
     * @return DaemonMeasurements Complete test measurement
     */
    private function measurements(int $workerBytes): DaemonMeasurements
    {
        return new DaemonMeasurements(100, 2.0, DaemonMeasurements::CORES_CPUS, 0.0, 0.0,
            0, 1048576, $workerBytes, 1, 4194304, DaemonMeasurements::CEILING_CGROUP, null);
    }
}

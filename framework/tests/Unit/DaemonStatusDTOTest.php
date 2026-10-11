<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Constants\DaemonConstants;
use Hilos\Core\CLI\DTO\DaemonStatusDTO;
use Hilos\Core\Daemon\CliMonitorManager;
use Hilos\Core\Daemon\DaemonMeasurements;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The status wire shape is shared by HTTP, the command channel, and future node frames. */
final class DaemonStatusDTOTest extends TestCase
{
    public function testRoundTripKeepsEveryMeasurement(): void
    {
        $measurements = new DaemonMeasurements(
            startedAt: 1785996400,
            cores: 4.0,
            coresBound: DaemonMeasurements::CORES_QUOTA,
            daemonCores: 0.42,
            masterCores: 0.03,
            machineLoad: 37,
            masterMemory: 2097152,
            workersMemory: 419430400,
            workersMeasured: 4,
            memoryCeiling: 8589934592,
            memoryCeilingBound: DaemonMeasurements::CEILING_CGROUP,
            phpMemoryLimit: 268435456,
        );
        $status = new DaemonStatusDTO(3600, 1786000000, $measurements, 4, 1, 8);

        $payload = $status->toArray();
        $restored = DaemonStatusDTO::fromJson($status->toJson());

        self::assertSame($payload, $restored->toArray());
        self::assertEquals($measurements, $restored->measurements);
        self::assertSame(4.0, $restored->measurements->cores);
        self::assertArrayNotHasKey('memory', $payload);
        self::assertArrayNotHasKey('cpu', $payload);
    }

    public function testUnknownMeasurementsSurviveRoundTripAsExplicitNulls(): void
    {
        $status = new DaemonStatusDTO(1, 1786000000, $this->unknownMeasurements());

        self::assertEquals($status, DaemonStatusDTO::fromJson($status->toJson()));
        self::assertArrayHasKey(DaemonStatusDTO::CORES, $status->toArray()[DaemonStatusDTO::MEASUREMENTS]);
        self::assertNull($status->toArray()[DaemonStatusDTO::MEASUREMENTS][DaemonStatusDTO::CORES]);
    }

    /** @return iterable<string, array{array<string, mixed>}> Invalid measurement field replacements */
    public static function invalidMeasurements(): iterable
    {
        yield 'cores without bound' => [[DaemonStatusDTO::CORES => 4.0]];
        yield 'bound without cores' => [[DaemonStatusDTO::CORES_BOUND => DaemonMeasurements::CORES_QUOTA]];
        yield 'master exceeds daemon' => [[DaemonStatusDTO::DAEMON_CORES => 0.02, DaemonStatusDTO::MASTER_CORES => 0.03]];
        yield 'machine above 100' => [[DaemonStatusDTO::MACHINE_LOAD => 101]];
        yield 'worker count without memory' => [[DaemonStatusDTO::WORKERS_MEASURED => 1]];
        yield 'wrong nullable type' => [[DaemonStatusDTO::MASTER_MEMORY => 'unknown']];
        yield 'invalid bound' => [[DaemonStatusDTO::MEMORY_CEILING => 1024, DaemonStatusDTO::MEMORY_CEILING_BOUND => 'host']];
        yield 'invalid start' => [[DaemonStatusDTO::STARTED_AT => 0]];
    }

    #[DataProvider('invalidMeasurements')]
    public function testInvalidMeasurementIsRefused(array $replacement): void
    {
        $data = DaemonStatusDTO::measurementsToArray($this->unknownMeasurements());
        $data = array_replace($data, $replacement);
        $this->expectException(InvalidFormatException::class);

        DaemonStatusDTO::measurementsFromArray($data);
    }

    public function testMissingNullableMeasurementKeyIsRefused(): void
    {
        $data = DaemonStatusDTO::measurementsToArray($this->unknownMeasurements());
        unset($data[DaemonStatusDTO::MASTER_MEMORY]);
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(DaemonStatusDTO::MASTER_MEMORY);

        DaemonStatusDTO::measurementsFromArray($data);
    }

    public function testLegacyStatusWithoutMeasurementsIsRefused(): void
    {
        $data = (new DaemonStatusDTO(1, 1786000000, $this->unknownMeasurements()))->toArray();
        unset($data[DaemonStatusDTO::MEASUREMENTS]);
        $data['memory'] = 2097152;
        $data['cpu'] = 12.5;
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(DaemonStatusDTO::MEASUREMENTS);

        DaemonStatusDTO::fromArray($data);
    }

    public function testTheMonitorReadsALegacyStatusAsNotResponding(): void
    {
        $monitor = new CliMonitorManager();
        $reply = CommandReplyDTO::ok('c0ffee00', ['uptime' => 1, 'memory' => 1, 'timestamp' => 1786000000]);
        $status = Closure::bind(
            static function (CliMonitorManager $monitor, CommandReplyDTO $reply): string {
                $monitor->processReply($reply);
                return $monitor->getStatusValue();
            },
            null,
            CliMonitorManager::class,
        );

        self::assertSame(DaemonConstants::STATUS_NOT_RESPONDING, $status($monitor, $reply));
    }

    public function testTheMonitorKeepsTheNewStatusPayload(): void
    {
        $monitor = new CliMonitorManager();
        $expected = new DaemonStatusDTO(1, 1786000000, $this->unknownMeasurements());
        $reply = CommandReplyDTO::ok('c0ffee00', $expected->toArray());
        $read = Closure::bind(
            static function (CliMonitorManager $monitor, CommandReplyDTO $reply): array {
                $monitor->processReply($reply);
                return [$monitor->getStatusValue(), $monitor->daemonStatus];
            },
            null,
            CliMonitorManager::class,
        );

        [$word, $status] = $read($monitor, $reply);
        self::assertSame(DaemonConstants::STATUS_ONLINE, $word);
        self::assertEquals($expected, $status);
    }

    private function unknownMeasurements(): DaemonMeasurements
    {
        return new DaemonMeasurements(1785996400, null, null, null, null, null, null, null, 0, null, null, null);
    }
}

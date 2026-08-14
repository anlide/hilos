<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\API\DTO\AsyncHttpResponse;
use Hilos\Constants\DaemonConstants;
use Hilos\Constants\HttpConstants;
use Hilos\Core\CLI\DTO\DaemonStatusDTO;
use Hilos\Core\Daemon\CliMonitorManager;
use Hilos\Core\Exception\InvalidFormatException;
use PHPUnit\Framework\TestCase;

/**
 * Tests the daemon status payload the CLI monitor reads over HTTP.
 *
 * The status is a measurement, so a field that did not arrive has no stand-in:
 * a CPU load of 0.0 or the reader's own clock in place of the daemon's would
 * render as a healthy daemon that is in fact answering with half a payload. The
 * monitor already treats a refusal as "no status", which is the honest answer,
 * and this suite pins both ends of that.
 */
final class DaemonStatusDTOTest extends TestCase
{
    public function testRoundTripKeepsEveryMeasurement(): void
    {
        $status = new DaemonStatusDTO(
            uptime: 3600,
            memory: 2097152,
            cpu: 12.5,
            timestamp: 1786000000,
            workersRegular: 4,
            workersMonopolistic: 1,
            workersMaxRegular: 8,
        );

        $restored = DaemonStatusDTO::fromJson($status->toJson());

        $this->assertSame(3600, $restored->uptime);
        $this->assertSame(2097152, $restored->memory);
        $this->assertSame(12.5, $restored->cpu);
        $this->assertSame(1786000000, $restored->timestamp);
        $this->assertSame(4, $restored->workersRegular);
        $this->assertSame(1, $restored->workersMonopolistic);
        $this->assertSame(8, $restored->workersMaxRegular);
    }

    public function testAWholeCpuLoadSurvivesJsonAsAFloat(): void
    {
        $restored = DaemonStatusDTO::fromJson(
            new DaemonStatusDTO(uptime: 1, memory: 1, cpu: 0.0, timestamp: 1786000000)->toJson(),
        );

        $this->assertSame(0.0, $restored->cpu);
    }

    public function testFromJsonRefusesAStatusWithoutTheCpuLoadAndNamesTheKey(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(DaemonStatusDTO::CPU);

        DaemonStatusDTO::fromJson('{"uptime":1,"memory":1,"timestamp":1786000000,'
            . '"workersRegular":0,"workersMonopolistic":0,"workersMaxRegular":0}');
    }

    public function testTheMonitorReadsARefusedStatusAsOffline(): void
    {
        $monitor = new CliMonitorManager();
        $response = new AsyncHttpResponse(
            statusCode: HttpConstants::HTTP_OK,
            headersRaw: '',
            body: '{"uptime":1,"memory":1,"timestamp":1786000000}',
        );

        $status = Closure::bind(
            static function (CliMonitorManager $monitor, AsyncHttpResponse $response): string {
                $monitor->processHttpResult($response);

                return $monitor->getStatusValue();
            },
            null,
            CliMonitorManager::class,
        );

        $this->assertSame(DaemonConstants::STATUS_OFFLINE, $status($monitor, $response));
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\WorkerConstants;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Socket\Worker\DTO\DaemonWorkerSignalDTO;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\TestCase;

/**
 * The wire form of the master's broadcast to every worker of this node (HIL-618).
 *
 * The frame is what makes the door lead somewhere: without a registered type the worker
 * refuses the JSON before anyone can look inside it, so the checks here go through
 * {@see WorkerDTO::factoryWorkerDTO()} rather than calling `fromArray()` directly - the
 * registration is half of what is being asserted.
 */
final class DaemonWorkerSignalDTOTest extends TestCase
{
    private const string SIGNAL_NAME = 'project_master_reaction';

    public function testTheFrameTypeAndItsConcretePayloadClassSurviveTheRoundTrip(): void
    {
        $frame = new DaemonWorkerSignalDTO(
            self::SIGNAL_NAME,
            new DaemonWorkerSignalDTOTestPayload('degraded', 4),
        );

        $restored = WorkerDTO::factoryWorkerDTO($frame->toJson());

        $this->assertInstanceOf(DaemonWorkerSignalDTO::class, $restored);
        $this->assertSame(WorkerConstants::MESSAGE_DAEMON_WORKER_SIGNAL, $restored->getType());
        $this->assertSame(self::SIGNAL_NAME, $restored->signalName);
        $this->assertInstanceOf(DaemonWorkerSignalDTOTestPayload::class, $restored->data);
        $this->assertSame('degraded', $restored->data->reason);
        $this->assertSame(4, $restored->data->nodeCount);
    }

    /**
     * A payload class the receiving side does not have is the ordinary cross-version case, not
     * a broken frame: the name still says what happened, and the body is readable as a map.
     */
    public function testAnUnknownPayloadClassDegradesToSignalDataWithoutLosingTheName(): void
    {
        $restored = WorkerDTO::factoryWorkerDTO((string)json_encode([
            WorkerDTO::TYPE => WorkerConstants::MESSAGE_DAEMON_WORKER_SIGNAL,
            DaemonWorkerSignalDTO::SIGNAL_NAME => self::SIGNAL_NAME,
            'data' => ['reason' => 'degraded'],
            'dataType' => 'Project\\Nowhere\\NoSuchSignalData',
        ]));

        $this->assertInstanceOf(DaemonWorkerSignalDTO::class, $restored);
        $this->assertSame(self::SIGNAL_NAME, $restored->signalName);
        $this->assertInstanceOf(SignalData::class, $restored->data);
        $this->assertSame(['reason' => 'degraded'], $restored->data->toArray());
    }

    /**
     * The payload is optional and the name is not: a frame nobody can name is a frame no hook
     * can act on.
     */
    public function testAFrameCarryingNoPayloadArrivesWithAnEmptyOne(): void
    {
        $restored = WorkerDTO::factoryWorkerDTO((string)json_encode([
            WorkerDTO::TYPE => WorkerConstants::MESSAGE_DAEMON_WORKER_SIGNAL,
            DaemonWorkerSignalDTO::SIGNAL_NAME => self::SIGNAL_NAME,
        ]));

        $this->assertInstanceOf(DaemonWorkerSignalDTO::class, $restored);
        $this->assertSame([], $restored->data->toArray());
    }

    public function testAFrameNamingNoSignalIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(DaemonWorkerSignalDTO::SIGNAL_NAME);

        WorkerDTO::factoryWorkerDTO((string)json_encode([
            WorkerDTO::TYPE => WorkerConstants::MESSAGE_DAEMON_WORKER_SIGNAL,
            'data' => [],
        ]));
    }
}

/**
 * A project payload the framework has never heard of, which is the case the envelope exists
 * for: the concrete class has to come back on the other side.
 */
final class DaemonWorkerSignalDTOTestPayload implements SignalDataInterface
{
    /**
     * @param string $reason What the master is telling its workers about
     * @param int $nodeCount A second field, so a restored payload is more than a lucky string
     */
    public function __construct(
        public readonly string $reason,
        public readonly int $nodeCount,
    ) {
    }

    /**
     * @return array<string, mixed> Signal payload
     */
    public function toArray(): array
    {
        return ['reason' => $this->reason, 'nodeCount' => $this->nodeCount];
    }

    /**
     * @param array<string, mixed> $data Signal payload
     * @return static Restored signal payload
     */
    public static function fromArray(array $data): static
    {
        return new static((string)$data['reason'], (int)$data['nodeCount']);
    }
}

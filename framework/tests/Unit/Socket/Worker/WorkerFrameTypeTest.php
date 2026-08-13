<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket\Worker;

use Hilos\Constants\AgentConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Worker\DTO\AgentStartDTO;
use Hilos\Socket\Worker\DTO\WorkerRegisterDTO;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins how the worker channel refuses a frame that names no type (HIL-549).
 *
 * The guard used to read the raw value through `?? ''` and compare it to the empty
 * string, so a frame whose `type` was an array walked past it, reached the `match`
 * default and was interpolated into the refusal message — an "Array to string
 * conversion" warning inside the constructor of the very exception meant to report
 * the frame. It is refused by the same exception now, for its own reason.
 */
final class WorkerFrameTypeTest extends TestCase
{
    public function testFrameWithNoTypeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WorkerDTO::factoryWorkerDTO('{"payload":{}}');
    }

    public function testFrameWithAnEmptyTypeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WorkerDTO::factoryWorkerDTO('{"type":""}');
    }

    public function testFrameWithANonStringTypeIsRefusedWithoutAConversionWarning(): void
    {
        set_error_handler(static function (int $severity, string $message): bool {
            throw new RuntimeException("PHP raised: {$message}");
        });

        try {
            WorkerDTO::factoryWorkerDTO('{"type":["agent_start"]}');
            $this->fail('A frame whose type is not a name must not be accepted.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Message type is missing', $e->getMessage());
        } finally {
            restore_error_handler();
        }
    }

    public function testFrameNamingAnUnknownTypeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WorkerDTO::factoryWorkerDTO('{"type":"no_such_worker_frame"}');
    }

    public function testFrameOfAKnownTypeMissingItsFieldIsRefusedInsteadOfBuiltWithZeros(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(AgentConstants::FIELD_AGENT_ID);

        WorkerDTO::factoryWorkerDTO('{"type":"' . AgentStartDTO::MESSAGE_TYPE . '"}');
    }

    public function testRegistrationWithALoweredFlagIsAcceptedAsTheAnswerItIs(): void
    {
        $frame = WorkerDTO::factoryWorkerDTO(
            (new WorkerRegisterDTO(workerIndex: 0, monopolistic: false))->toJson(),
        );

        $this->assertInstanceOf(WorkerRegisterDTO::class, $frame);
        $this->assertSame(0, $frame->workerIndex);
        $this->assertFalse($frame->monopolistic);
    }

    public function testRegistrationWithoutItsMonopolisticFlagIsRefused(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage(WorkerRegisterDTO::MONOPOLISTIC);

        WorkerDTO::factoryWorkerDTO(
            '{"type":"' . WorkerRegisterDTO::MESSAGE_TYPE . '","' . WorkerRegisterDTO::WORKER_INDEX . '":1}',
        );
    }
}

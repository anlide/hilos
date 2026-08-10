<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket\Worker;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\TestCase;

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
            throw new \RuntimeException("PHP raised: {$message}");
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
}

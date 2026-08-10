<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket\Worker;

use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Socket\Worker\DTO\WorkerDbReHydrateMessageDTO;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the whole-database re-hydrate frame (HIL-479).
 *
 * The frame is the transport half of a signal that had an applicator and no emitter. It is the
 * only worker frame with an empty body, so what has to hold is that the type alone survives the
 * round trip and that the factory still recognizes it - a frame that decoded into something else
 * would leave a restored node reading a database it has already forgotten.
 */
final class DbReHydrateFrameTest extends TestCase
{
    public function testTheFrameTypeIsTheSignalTypeItself(): void
    {
        $this->assertSame(SignalTypeConstants::DB_REHYDRATE, WorkerConstants::MESSAGE_DB_REHYDRATE);
        $this->assertSame(WorkerConstants::MESSAGE_DB_REHYDRATE, new WorkerDbReHydrateMessageDTO()->getType());
    }

    public function testTheFrameCarriesNothingBesidesItsType(): void
    {
        $this->assertSame(
            [WorkerDTO::TYPE => WorkerConstants::MESSAGE_DB_REHYDRATE],
            new WorkerDbReHydrateMessageDTO()->toArray(),
        );
    }

    public function testTheFactoryRebuildsTheFrameFromItsJson(): void
    {
        $parsed = WorkerDTO::factoryWorkerDTO(new WorkerDbReHydrateMessageDTO()->toJson());

        $this->assertInstanceOf(WorkerDbReHydrateMessageDTO::class, $parsed);
        $this->assertSame(WorkerConstants::MESSAGE_DB_REHYDRATE, $parsed->getType());
    }
}

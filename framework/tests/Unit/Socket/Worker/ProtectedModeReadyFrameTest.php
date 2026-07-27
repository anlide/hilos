<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket\Worker;

use Hilos\Socket\Worker\DTO\ProtectedModeReadyDTO;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\TestCase;

/**
 * Tests the protected-mode ready relay frame (HIL-267 slice 5f).
 *
 * Once the leader learns every node has quiesced, the daemon on the initiator node relays the ready
 * to the worker hosting the initiator agent over this daemon->worker frame. The frame only names the
 * agent — the arrival is the whole message. Here we lock the wire shape and that
 * {@see WorkerDTO::factoryWorkerDTO()} dispatches the type back to its class. The daemon-side
 * resolution to the agent's worker is exercised by the cluster e2e slice.
 */
final class ProtectedModeReadyFrameTest extends TestCase
{
    public function testReadyFrameRoundTripsThroughTheWire(): void
    {
        $frame = new ProtectedModeReadyDTO('backup:0');

        $restored = ProtectedModeReadyDTO::fromJson($frame->toJson());

        $this->assertSame(ProtectedModeReadyDTO::MESSAGE_TYPE, $restored->getType());
        $this->assertSame('backup:0', $restored->agentId);
    }

    public function testFactoryDispatchesReadyFrameToItsClass(): void
    {
        $restored = WorkerDTO::factoryWorkerDTO((new ProtectedModeReadyDTO('backup:0'))->toJson());

        $this->assertInstanceOf(ProtectedModeReadyDTO::class, $restored);
        $this->assertSame('backup:0', $restored->agentId);
    }
}

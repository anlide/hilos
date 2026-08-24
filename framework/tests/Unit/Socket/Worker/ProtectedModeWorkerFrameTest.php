<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket\Worker;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\ProtectedMode\DTO\ProtectedModeDisableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeDisableDTO;
use Hilos\Socket\Worker\DTO\WorkerProtectedModeEnableDTO;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\TestCase;

/**
 * Tests the protected-mode worker->daemon request frames (HIL-267 slice 5d).
 *
 * The initiator agent runs in a worker and cannot emit a peer frame itself, so it asks its own
 * master daemon to start or release the freeze over the worker transport. These frames are thin
 * envelopes: enable wraps the initiator identity, disable is the whole message. Here we lock the
 * wire shape, the malformed-payload error, and that {@see WorkerDTO::factoryWorkerDTO()} dispatches
 * each type back to its class. The daemon-side hand-off to the cluster coordinator is exercised by
 * the cluster e2e slice.
 */
final class ProtectedModeWorkerFrameTest extends TestCase
{
    public function testEnableFrameRoundTripsThroughTheWire(): void
    {
        $frame = new WorkerProtectedModeEnableDTO(new ProtectedModeEnableSignalData(
            operation: 'restore',
            initiatorAcceptKey: 'accept-9',
            initiatorSessionTokenHash: null,
            initiatorAgentType: 'backup',
            initiatorAgentIndex: 0,
            initiatorNodeId: 'node-a',
        ));

        $restored = WorkerProtectedModeEnableDTO::fromJson($frame->toJson());

        $this->assertSame(WorkerProtectedModeEnableDTO::MESSAGE_TYPE, $restored->getType());
        $this->assertSame('restore', $restored->data->operation);
        $this->assertSame('accept-9', $restored->data->initiatorAcceptKey);
        $this->assertSame('backup', $restored->data->initiatorAgentType);
        $this->assertSame(0, $restored->data->initiatorAgentIndex);
        $this->assertSame('node-a', $restored->data->initiatorNodeId);
    }

    public function testEnableFrameKeepsNullAgentIndex(): void
    {
        $frame = new WorkerProtectedModeEnableDTO(new ProtectedModeEnableSignalData(
            operation: 'restore',
            initiatorAcceptKey: 'accept-9',
            initiatorSessionTokenHash: null,
            initiatorAgentType: 'backup',
            initiatorAgentIndex: null,
            initiatorNodeId: 'node-a',
        ));

        $restored = WorkerProtectedModeEnableDTO::fromArray($frame->toArray());

        $this->assertNull($restored->data->initiatorAgentIndex);
    }

    public function testEnableFrameRejectsNonObjectPayload(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WorkerProtectedModeEnableDTO::fromArray([
            WorkerProtectedModeEnableDTO::FIELD_PAYLOAD => 'not-an-object',
        ]);
    }

    public function testDisableFrameRoundTripsThroughTheWire(): void
    {
        $frame = new WorkerProtectedModeDisableDTO(new ProtectedModeDisableSignalData(
            initiatorAgentType: 'backup',
            initiatorAgentIndex: 2,
        ));

        $restored = WorkerProtectedModeDisableDTO::fromJson($frame->toJson());

        $this->assertSame(WorkerProtectedModeDisableDTO::MESSAGE_TYPE, $restored->getType());
        $this->assertSame('backup', $restored->data->initiatorAgentType);
        $this->assertSame(2, $restored->data->initiatorAgentIndex);
    }

    public function testDisableFrameRejectsNonObjectPayload(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WorkerProtectedModeDisableDTO::fromArray([
            WorkerProtectedModeDisableDTO::FIELD_PAYLOAD => 'not-an-object',
        ]);
    }

    public function testFactoryDispatchesEnableFrame(): void
    {
        $frame = new WorkerProtectedModeEnableDTO(new ProtectedModeEnableSignalData(
            operation: 'restore',
            initiatorAcceptKey: 'accept-9',
            initiatorSessionTokenHash: null,
            initiatorAgentType: 'backup',
            initiatorAgentIndex: 2,
            initiatorNodeId: 'node-a',
        ));

        $parsed = WorkerDTO::factoryWorkerDTO($frame->toJson());

        $this->assertInstanceOf(WorkerProtectedModeEnableDTO::class, $parsed);
        $this->assertSame('restore', $parsed->data->operation);
        $this->assertSame(2, $parsed->data->initiatorAgentIndex);
    }

    public function testFactoryDispatchesDisableFrame(): void
    {
        $frame = new WorkerProtectedModeDisableDTO(new ProtectedModeDisableSignalData(
            initiatorAgentType: 'backup',
            initiatorAgentIndex: null,
        ));

        $parsed = WorkerDTO::factoryWorkerDTO($frame->toJson());

        $this->assertInstanceOf(WorkerProtectedModeDisableDTO::class, $parsed);
        $this->assertSame('backup', $parsed->data->initiatorAgentType);
        $this->assertNull($parsed->data->initiatorAgentIndex);
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Socket\Command\DTO\CommandRequestDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the command-channel request wire payload.
 */
final class CommandRequestDTOTest extends TestCase
{
    public function testToArrayUsesProtocolKeys(): void
    {
        $request = new CommandRequestDTO('corr-1', 'ping', ['message' => 'hi']);

        $this->assertSame([
            'correlationId' => 'corr-1',
            'command' => 'ping',
            'payload' => ['message' => 'hi'],
        ], $request->toArray());
    }

    public function testJsonRoundTripPreservesFields(): void
    {
        $request = new CommandRequestDTO('corr-2', 'ping', ['a' => 1, 'b' => ['c' => 2]]);

        $restored = CommandRequestDTO::fromJson($request->toJson());

        $this->assertSame('corr-2', $restored->correlationId);
        $this->assertSame('ping', $restored->command);
        $this->assertSame(['a' => 1, 'b' => ['c' => 2]], $restored->payload);
    }

    public function testFromArrayDefaultsMissingFields(): void
    {
        $restored = CommandRequestDTO::fromArray([]);

        $this->assertSame('', $restored->correlationId);
        $this->assertSame('', $restored->command);
        $this->assertSame([], $restored->payload);
    }
}

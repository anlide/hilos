<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Constants\CommandConstants;
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
            'originNodeId' => null,
        ], $request->toArray());
    }

    public function testJsonRoundTripPreservesFields(): void
    {
        $request = new CommandRequestDTO('corr-2', 'ping', ['a' => 1, 'b' => ['c' => 2]]);

        $restored = CommandRequestDTO::fromJson($request->toJson());

        $this->assertSame('corr-2', $restored->correlationId);
        $this->assertSame('ping', $restored->command);
        $this->assertSame(['a' => 1, 'b' => ['c' => 2]], $restored->payload);
        $this->assertNull($restored->originNodeId);
    }

    public function testOriginNodeRoundTripAndCopyPreserveTheCommand(): void
    {
        $request = new CommandRequestDTO('corr-3', 'admin:grant', ['userId' => 7]);
        $stamped = $request->withOriginNodeId('node-2');
        $restored = CommandRequestDTO::fromArray($stamped->toArray());

        $this->assertNull($request->originNodeId);
        $this->assertSame('node-2', $restored->originNodeId);
        $this->assertSame($request->command, $restored->command);
        $this->assertSame($request->payload, $restored->payload);
        $this->assertArrayHasKey(CommandConstants::FIELD_ORIGIN_NODE_ID, $stamped->toArray());
    }

    public function testFromArrayRefusesAPayloadCarryingNoField(): void
    {
        $this->expectException(InvalidFormatException::class);

        CommandRequestDTO::fromArray([]);
    }

    public function testFromArrayRefusesARequestNamingNoCommand(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('command');

        CommandRequestDTO::fromArray(['correlationId' => 'corr-1', 'payload' => []]);
    }

    public function testFromArrayRefusesARequestWithoutItsArgumentMap(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('payload');

        CommandRequestDTO::fromArray(['correlationId' => 'corr-1', 'command' => 'ping']);
    }

    public function testFromArrayKeepsAnEmptyArgumentMap(): void
    {
        $restored = CommandRequestDTO::fromArray([
            'correlationId' => 'corr-1',
            'command' => 'ping',
            'payload' => [],
        ]);

        $this->assertSame([], $restored->payload);
        $this->assertNull($restored->originNodeId);
    }
}

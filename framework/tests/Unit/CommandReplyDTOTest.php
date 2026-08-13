<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Socket\Command\DTO\CommandReplyDTO;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the command-channel reply wire payload and its factories.
 */
final class CommandReplyDTOTest extends TestCase
{
    public function testOkFactoryBuildsSuccessReply(): void
    {
        $reply = CommandReplyDTO::ok('corr-1', ['message' => 'pong']);

        $this->assertTrue($reply->isOk());
        $this->assertSame('ok', $reply->status);
        $this->assertSame(['message' => 'pong'], $reply->payload);
    }

    public function testErrorFactoryBuildsFailureReply(): void
    {
        $reply = CommandReplyDTO::error('corr-2', 'boom');

        $this->assertFalse($reply->isOk());
        $this->assertSame('error', $reply->status);
        $this->assertSame(['message' => 'boom'], $reply->payload);
    }

    public function testJsonRoundTripPreservesFields(): void
    {
        $reply = CommandReplyDTO::ok('corr-3', ['n' => 7]);

        $restored = CommandReplyDTO::fromJson($reply->toJson());

        $this->assertSame('corr-3', $restored->correlationId);
        $this->assertSame('ok', $restored->status);
        $this->assertSame(['n' => 7], $restored->payload);
        $this->assertTrue($restored->isOk());
    }

    public function testFromArrayRefusesAReplyNamingNoStatus(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('status');

        CommandReplyDTO::fromArray(['correlationId' => 'corr-4', 'payload' => []]);
    }

    public function testFromArrayRefusesAReplyWithoutItsResultMap(): void
    {
        $this->expectException(InvalidFormatException::class);
        $this->expectExceptionMessage('payload');

        CommandReplyDTO::fromArray(['correlationId' => 'corr-5', 'status' => 'ok']);
    }
}

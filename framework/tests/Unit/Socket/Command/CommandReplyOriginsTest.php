<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket\Command;

use Hilos\Constants\CommandChannelWindows;
use Hilos\Socket\Command\CommandReplyOrigins;
use PHPUnit\Framework\TestCase;

final class CommandReplyOriginsTest extends TestCase
{
    public function testAnOriginIsTakenOnlyOnce(): void
    {
        $origins = new CommandReplyOrigins();
        $origins->note('corr-1', 'node-2', 1.0);

        $this->assertNull($origins->take('other', 2.0));
        $this->assertSame('node-2', $origins->take('corr-1', 2.0));
        $this->assertNull($origins->take('corr-1', 2.0));
    }

    public function testADepartedNodesOriginsAreForgotten(): void
    {
        $origins = new CommandReplyOrigins();
        $origins->note('corr-1', 'node-2', 1.0);
        $origins->note('corr-2', 'node-3', 1.0);
        $origins->forgetNode('node-2');

        $this->assertNull($origins->take('corr-1', 2.0));
        $this->assertSame('node-3', $origins->take('corr-2', 2.0));
    }

    public function testAnOriginExpiresWithTheHeldConnection(): void
    {
        $origins = new CommandReplyOrigins();
        $origins->note('corr-1', 'node-2', 1.0);

        $this->assertNull($origins->take('corr-1', 1.0 + CommandChannelWindows::CHANNEL_HELD_SECONDS));
    }
}

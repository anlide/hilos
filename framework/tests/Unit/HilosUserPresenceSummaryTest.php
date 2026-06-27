<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Runtime\View\DTO\HilosUserPresenceSummary;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the framework user presence summary DTO.
 */
final class HilosUserPresenceSummaryTest extends TestCase
{
    public function testOnlineWhenSessionsPresent(): void
    {
        $summary = new HilosUserPresenceSummary(2);

        $this->assertSame(2, $summary->onlineSessionCount);
        $this->assertSame(HilosUserPresenceSummary::PRESENCE_ONLINE, $summary->presence);
    }

    public function testOfflineWhenNoSessions(): void
    {
        $summary = new HilosUserPresenceSummary(0);

        $this->assertSame(0, $summary->onlineSessionCount);
        $this->assertSame(HilosUserPresenceSummary::PRESENCE_OFFLINE, $summary->presence);
    }

    public function testPresenceConstantValues(): void
    {
        $this->assertSame('online', HilosUserPresenceSummary::PRESENCE_ONLINE);
        $this->assertSame('offline', HilosUserPresenceSummary::PRESENCE_OFFLINE);
    }
}

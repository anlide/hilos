<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Runtime\View\DTO\HilosUserPresenceSummary;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testProbablyOfflineWhenSessionsPresentAndAllDoubted(): void
    {
        $summary = new HilosUserPresenceSummary(2, false, true);

        $this->assertSame(2, $summary->onlineSessionCount, 'doubted sessions are still counted');
        $this->assertSame(HilosUserPresenceSummary::PRESENCE_PROBABLY_OFFLINE, $summary->presence);
    }

    public function testOfflineWhenNoSessionsWhateverTheDoubt(): void
    {
        $summary = new HilosUserPresenceSummary(0, false, true);

        $this->assertSame(0, $summary->onlineSessionCount);
        $this->assertSame(HilosUserPresenceSummary::PRESENCE_OFFLINE, $summary->presence);
    }

    /**
     * @return array<string, array{int, bool, bool, string}> Count, freshness and doubt in, the presence out
     */
    public static function freshnessAgainstPresence(): array
    {
        return [
            'online, fresh' => [1, false, false, HilosUserPresenceSummary::PRESENCE_ONLINE],
            'online, frozen' => [1, true, false, HilosUserPresenceSummary::PRESENCE_ONLINE],
            'probably offline, fresh' => [1, false, true, HilosUserPresenceSummary::PRESENCE_PROBABLY_OFFLINE],
            'probably offline, frozen' => [1, true, true, HilosUserPresenceSummary::PRESENCE_PROBABLY_OFFLINE],
            'offline, fresh' => [0, false, false, HilosUserPresenceSummary::PRESENCE_OFFLINE],
            'offline, frozen' => [0, true, false, HilosUserPresenceSummary::PRESENCE_OFFLINE],
        ];
    }

    /**
     * The freshness of the replica qualifies the summary and never moves the presence, and the
     * presence never moves it back.
     */
    #[DataProvider('freshnessAgainstPresence')]
    public function testTheFreshnessIsIndependentOfThePresence(int $count, bool $stale, bool $doubted, string $presence): void
    {
        $summary = new HilosUserPresenceSummary($count, $stale, $doubted);

        $this->assertSame($presence, $summary->presence);
        $this->assertSame($stale, $summary->stale);
        $this->assertSame($count, $summary->onlineSessionCount);
    }

    public function testTheOneAndTwoArgumentFormsKeepTheirMeaning(): void
    {
        $this->assertSame(HilosUserPresenceSummary::PRESENCE_ONLINE, (new HilosUserPresenceSummary(1))->presence);
        $frozen = new HilosUserPresenceSummary(1, true);
        $this->assertSame(HilosUserPresenceSummary::PRESENCE_ONLINE, $frozen->presence);
        $this->assertTrue($frozen->stale);
    }

    public function testPresenceConstantValues(): void
    {
        $this->assertSame('online', HilosUserPresenceSummary::PRESENCE_ONLINE);
        $this->assertSame('probably_offline', HilosUserPresenceSummary::PRESENCE_PROBABLY_OFFLINE);
        $this->assertSame('offline', HilosUserPresenceSummary::PRESENCE_OFFLINE);
    }
}

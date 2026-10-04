<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Analytics;

use Hilos\Core\Analytics\AnalyticsJournalLosses;
use Hilos\Core\Analytics\AnalyticsLossCount;
use Hilos\Core\Analytics\AnalyticsLossReason;
use PHPUnit\Framework\TestCase;

/** Open loss episodes of a node across batches, quiet periods and a process restart. */
final class AnalyticsJournalLossesTest extends TestCase
{
    public function testCountsOfOneReasonMergeAndCloseOnlyAfterAMinuteWithoutLoss(): void
    {
        $losses = new AnalyticsJournalLosses();
        self::assertTrue($losses->add(new AnalyticsLossCount(AnalyticsLossReason::JOURNAL_FULL, 2, 10, 20), 30));
        self::assertFalse($losses->add(new AnalyticsLossCount(AnalyticsLossReason::JOURNAL_FULL, 3, 5, 40), 40));
        self::assertSame([], $losses->closeQuiet(40 + AnalyticsJournalLosses::QUIET_MS - 1));
        self::assertTrue($losses->hasChanges());

        $closed = $losses->closeQuiet(40 + AnalyticsJournalLosses::QUIET_MS);
        self::assertEquals([new AnalyticsLossCount(AnalyticsLossReason::JOURNAL_FULL, 5, 5, 40)], $closed);
        self::assertTrue($losses->isEmpty());
        self::assertTrue($losses->add(new AnalyticsLossCount(AnalyticsLossReason::JOURNAL_FULL, 1, 50, 50), 50));
    }

    public function testSavedEpisodesCloseOnTheNextLife(): void
    {
        $losses = new AnalyticsJournalLosses();
        $losses->add(new AnalyticsLossCount(AnalyticsLossReason::RESTORE, 2, 10, 20), 20);
        $losses->add(new AnalyticsLossCount(AnalyticsLossReason::RECORD_DROPPED, 1, 30, 30), 30);
        $losses->markSaved();
        self::assertFalse($losses->hasChanges());

        $recovered = AnalyticsJournalLosses::fromJson($losses->toJson());
        self::assertFalse($recovered->hasChanges());
        self::assertEquals([
            new AnalyticsLossCount(AnalyticsLossReason::RESTORE, 2, 10, 20),
            new AnalyticsLossCount(AnalyticsLossReason::RECORD_DROPPED, 1, 30, 30),
        ], $recovered->closeAll());
        self::assertTrue($recovered->isEmpty());
    }

    public function testMalformedSavedStateDoesNotOpenAnEpisode(): void
    {
        self::assertTrue(AnalyticsJournalLosses::fromJson('{')->isEmpty());
        self::assertTrue(AnalyticsJournalLosses::fromJson('{"v":1,"episodes":[{"reason":"other"}]}')->isEmpty());
    }
}

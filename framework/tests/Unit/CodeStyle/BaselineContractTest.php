<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\CodeStyle;

use Hilos\Tests\CodeStyle\Baseline;
use Hilos\Tests\CodeStyle\BaselineUpdate;
use PHPUnit\Framework\TestCase;

/**
 * Pins the baseline contract on synthetic records: it may only shrink, and every
 * record must name the leaf that will remove it.
 */
final class BaselineContractTest extends TestCase
{
    public function testMatchingRecordIsSilent(): void
    {
        $baseline = Baseline::fromText('PHPDOC-FQN framework/backend/Mail/EmailContent.php 2 # HIL-521');

        $this->assertSame([], $baseline->reconcile($this->reported('PHPDOC-FQN framework/backend/Mail/EmailContent.php', 2)));
    }

    public function testRecordWithoutTicketIsRejected(): void
    {
        $baseline = Baseline::fromText('PHPDOC-FQN framework/backend/Mail/EmailContent.php 2 # later');

        $this->assertSame(
            ['baseline record "PHPDOC-FQN framework/backend/Mail/EmailContent.php" names no owing leaf: later'],
            $baseline->reconcile([]),
        );
    }

    public function testMalformedRecordIsRejected(): void
    {
        $baseline = Baseline::fromText("# a comment\n\nPHPDOC-FQN framework/backend/Mail/EmailContent.php # HIL-521");

        $this->assertSame(
            ['baseline line 3 is malformed: PHPDOC-FQN framework/backend/Mail/EmailContent.php # HIL-521'],
            $baseline->reconcile([]),
        );
    }

    public function testViolationsAboveTheRecordedCountAreReported(): void
    {
        $baseline = Baseline::fromText('PHPDOC-FQN framework/backend/Mail/EmailContent.php 1 # HIL-521');

        $this->assertSame(
            ['line 2', 'line 3'],
            $baseline->reconcile($this->reported('PHPDOC-FQN framework/backend/Mail/EmailContent.php', 3)),
        );
    }

    public function testUnrecordedFileIsReportedInFull(): void
    {
        $baseline = Baseline::fromText('');

        $this->assertSame(
            ['line 1', 'line 2'],
            $baseline->reconcile($this->reported('RT-STATE-REACH framework/backend/Core/Daemon/DaemonManager.php', 2)),
        );
    }

    public function testShrunkRecordAsksForALowerCount(): void
    {
        $baseline = Baseline::fromText('PHPDOC-FQN framework/backend/Mail/EmailContent.php 3 # HIL-521');

        $this->assertSame(
            ['baseline record "PHPDOC-FQN framework/backend/Mail/EmailContent.php" allows 3, only 1 left — lower the count'],
            $baseline->reconcile($this->reported('PHPDOC-FQN framework/backend/Mail/EmailContent.php', 1)),
        );
    }

    public function testPaidOffRecordAsksToBeDeleted(): void
    {
        $baseline = Baseline::fromText('PHPDOC-FQN framework/backend/Mail/EmailContent.php 1 # HIL-521');

        $this->assertSame(
            ['baseline record "PHPDOC-FQN framework/backend/Mail/EmailContent.php" is paid off — delete the line'],
            $baseline->reconcile([]),
        );
    }

    public function testUpdateWritesThePaidPartOfARecord(): void
    {
        $baseline = Baseline::fromText('PHPDOC-FQN framework/backend/Mail/EmailContent.php 3 # HIL-521');

        $update = $baseline->update($this->reported('PHPDOC-FQN framework/backend/Mail/EmailContent.php', 1));

        $this->assertStringContainsString(
            "PHPDOC-FQN framework/backend/Mail/EmailContent.php 1 # HIL-521\n",
            $this->writtenText($update),
        );
        $this->assertSame(
            'Baseline regenerated from the current tree — review the diff before committing it.',
            $update->message(),
        );
    }

    public function testUpdateDropsARecordPaidOffInFull(): void
    {
        $baseline = Baseline::fromText('PHPDOC-FQN framework/backend/Mail/EmailContent.php 3 # HIL-521');

        $update = $baseline->update([]);

        $this->assertStringNotContainsString('EmailContent.php', $this->writtenText($update));
    }

    public function testUpdateKeepsAGrownRecordAtItsCountAndSaysSo(): void
    {
        $baseline = Baseline::fromText('PHPDOC-FQN framework/backend/Mail/EmailContent.php 1 # HIL-521');

        $update = $baseline->update($this->reported('PHPDOC-FQN framework/backend/Mail/EmailContent.php', 3));

        $this->assertStringContainsString(
            "PHPDOC-FQN framework/backend/Mail/EmailContent.php 1 # HIL-521\n",
            $this->writtenText($update),
        );
        $this->assertStringContainsString(
            'PHPDOC-FQN framework/backend/Mail/EmailContent.php: kept at 1, the tree has 3'
                . " — the update mode never raises a count\n  line 2\n  line 3",
            $update->message(),
        );
    }

    public function testUpdateNeverAddsARecordTheBaselineDoesNotKnow(): void
    {
        $baseline = Baseline::fromText('');

        $update = $baseline->update($this->reported('RT-STATE-REACH framework/backend/Core/Daemon/DaemonManager.php', 2));

        $this->assertStringNotContainsString('DaemonManager.php 2', $this->writtenText($update));
        $this->assertStringContainsString(
            'RT-STATE-REACH framework/backend/Core/Daemon/DaemonManager.php: not written, the tree has 2'
                . " — the update mode never adds a record\n  line 1\n  line 2",
            $update->message(),
        );
    }

    public function testUpdateWritesNothingWhileTheBaselineItselfIsUnreadable(): void
    {
        $baseline = Baseline::fromText('PHPDOC-FQN framework/backend/Mail/EmailContent.php # HIL-521');

        $update = $baseline->update($this->reported('PHPDOC-FQN framework/backend/Mail/EmailContent.php', 1));

        $this->assertNull($update->text());
        $this->assertStringContainsString(
            'baseline line 1 is malformed: PHPDOC-FQN framework/backend/Mail/EmailContent.php # HIL-521',
            $update->message(),
        );
    }

    /**
     * @param BaselineUpdate $update Outcome of pressing the update button
     * @return string Contents the update writes, asserted to exist first
     */
    private function writtenText(BaselineUpdate $update): string
    {
        $text = $update->text();
        $this->assertNotNull($text);

        return $text;
    }

    /**
     * @param string $key Baseline key, "<rule id> <path from repository root>"
     * @param int $count How many violation lines the scan reported for that key
     * @return array<string, array<int, string>> Report shaped the way the guard test builds it
     */
    private function reported(string $key, int $count): array
    {
        $lines = [];
        for ($number = 1; $number <= $count; $number++) {
            $lines[] = 'line ' . $number;
        }

        return [$key => $lines];
    }
}

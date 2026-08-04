<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\CodeStyle;

use Hilos\Tests\CodeStyle\Baseline;
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

    public function testRenderKeepsKnownTicketsAndFlagsUnattributedDebt(): void
    {
        $baseline = Baseline::fromText('PHPDOC-FQN framework/backend/Mail/EmailContent.php 3 # HIL-521');

        $rendered = $baseline->render(
            $this->reported('PHPDOC-FQN framework/backend/Mail/EmailContent.php', 1)
            + $this->reported('RT-STATE-REACH framework/backend/Core/Daemon/DaemonManager.php', 2),
        );

        $this->assertStringContainsString(
            "PHPDOC-FQN framework/backend/Mail/EmailContent.php 1 # HIL-521\n"
                . 'RT-STATE-REACH framework/backend/Core/Daemon/DaemonManager.php 2 # ' . Baseline::UNKNOWN_TICKET,
            $rendered,
        );
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

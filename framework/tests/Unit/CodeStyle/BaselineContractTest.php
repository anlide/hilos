<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\CodeStyle;

use Hilos\Tests\CodeStyle\Baseline;
use Hilos\Tests\CodeStyle\BaselineScope;
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

    /** A root owns both its scanning rule ids and a complete path segment. */
    public function testRootScopeReconcilesOnlyItsRulesBelowItsExactRoot(): void
    {
        $baseline = Baseline::fromText(implode("\n", [
            'PHPDOC-FQN framework/backend/Mail/EmailContent.php 3 # HIL-521',
            'PHPDOC-FQN framework/backend-extra/Other.php 4 # HIL-522',
            'PHPDOC-FQN demo/chat/backend/Other.php 5 # HIL-523',
            'THROWS-PROPAGATION framework/backend/Mail/EmailContent.php 6 # HIL-524',
        ]));
        $reported = $this->reported('PHPDOC-FQN framework/backend/Mail/EmailContent.php', 1)
            + $this->reported('THROWS-PROPAGATION framework/backend/New.php', 2)
            + $this->reported('PHPDOC-FQN framework/backend-extra/New.php', 2);

        $this->assertSame(
            ['baseline record "PHPDOC-FQN framework/backend/Mail/EmailContent.php" allows 3, only 1 left — lower the count'],
            $baseline->reconcile($reported, BaselineScope::forRoot('framework/backend', ['PHPDOC-FQN'])),
        );
    }

    /** Ownership survives a scan with no findings. */
    public function testEmptyRootReportStillChecksItsPaidOffRecords(): void
    {
        $baseline = Baseline::fromText(
            "PHPDOC-FQN framework/backend/Mail/EmailContent.php 1 # HIL-521\n"
                . "PHPDOC-FQN demo/chat/backend/Other.php 1 # HIL-522\n",
        );

        $this->assertSame(
            ['baseline record "PHPDOC-FQN framework/backend/Mail/EmailContent.php" is paid off — delete the line'],
            $baseline->reconcile([], BaselineScope::forRoot('framework/backend', ['PHPDOC-FQN'])),
        );
    }

    /** Unknown rules and paths cannot disappear between the individual pieces' scopes. */
    public function testUnownedRecordsAreStillRejectedByTheParserPiece(): void
    {
        $baseline = Baseline::fromText(implode("\n", [
            'PHPDOC-FQN framework/backend/Owned.php 1 # HIL-521',
            'PHPDOC-FQN framework/backend-extra/Other.php 1 # HIL-522',
            'UNKNOWN framework/backend/Owned.php 1 # HIL-523',
            'THROWS-PROPAGATION demo/chat/backend/Owned.php 1 # HIL-524',
        ]));

        $this->assertSame([
            'baseline record "PHPDOC-FQN framework/backend-extra/Other.php" has no guard owner — delete or correct the line',
            'baseline record "UNKNOWN framework/backend/Owned.php" has no guard owner — delete or correct the line',
        ], $baseline->reconcileUnowned([
            BaselineScope::forRoot('framework/backend', ['PHPDOC-FQN']),
            BaselineScope::forRules(['THROWS-PROPAGATION']),
        ]));
    }

    /** An index rule owns its id across roots, including records with no remaining findings. */
    public function testRuleScopeChecksEveryPathOnlyOfItsOwnRules(): void
    {
        $baseline = Baseline::fromText(implode("\n", [
            'THROWS-PROPAGATION framework/backend/One.php 1 # HIL-521',
            'THROWS-PROPAGATION demo/chat/backend/Two.php 1 # HIL-522',
            'THROWS-ORPHAN framework/backend/One.php 2 # HIL-523',
        ]));
        $reported = $this->reported('THROWS-PROPAGATION framework/backend/One.php', 2)
            + $this->reported('THROWS-ORPHAN demo/chat/backend/New.php', 3);

        $this->assertSame([
            'line 2',
            'baseline record "THROWS-PROPAGATION demo/chat/backend/Two.php" is paid off — delete the line',
        ], $baseline->reconcile($reported, BaselineScope::forRules(['THROWS-PROPAGATION'])));
    }

    /** Foreign records retain their spelling, count, ticket and line ending. */
    public function testRootUpdatePreservesAllForeignLinesVerbatim(): void
    {
        $foreign = "  THROWS-PROPAGATION   framework/backend/One.php  7 # HIL-522  \r\n"
            . "PHPDOC-FQN framework/backend-extra/One.php 08 # HIL-523\r\n"
            . 'PHPDOC-FQN demo/chat/backend/One.php 9 # HIL-524';
        $prefix = "# keep this header\r\n\r\n";
        $baseline = Baseline::fromText($prefix
            . "PHPDOC-FQN framework/backend/One.php 3 # HIL-521\n"
            . "PHPDOC-FQN framework/backend/Paid.php 1 # HIL-525\n"
            . $foreign);

        $update = $baseline->update(
            $this->reported('PHPDOC-FQN framework/backend/One.php', 1),
            BaselineScope::forRoot('framework/backend', ['PHPDOC-FQN']),
        );

        $this->assertSame($prefix . "PHPDOC-FQN framework/backend/One.php 1 # HIL-521\n" . $foreign, $this->writtenText($update));
    }

    /** Deleting the final owned line must not strip a preceding foreign line's newline. */
    public function testEmptyUpdatePreservesTheForeignLineEnding(): void
    {
        $foreign = "PHPDOC-FQN demo/chat/backend/One.php 2 # HIL-522\r\n";
        $baseline = Baseline::fromText($foreign . 'PHPDOC-FQN framework/backend/Paid.php 1 # HIL-521');

        $update = $baseline->update([], BaselineScope::forRoot('framework/backend', ['PHPDOC-FQN']));

        $this->assertSame($foreign, $this->writtenText($update));
    }

    /** Scoped updates keep the same refusal of growth and new keys as whole-file updates. */
    public function testScopedUpdateWithholdsGrowthAndNewRecordsOnlyOfItsOwner(): void
    {
        $foreign = "THROWS-PROPAGATION framework/backend/One.php 7 # HIL-522\n";
        $baseline = Baseline::fromText("PHPDOC-FQN framework/backend/One.php 1 # HIL-521\n" . $foreign);
        $reported = $this->reported('PHPDOC-FQN framework/backend/One.php', 3)
            + $this->reported('PHPDOC-FQN framework/backend/New.php', 2)
            + $this->reported('THROWS-PROPAGATION framework/backend/New.php', 4);

        $update = $baseline->update($reported, BaselineScope::forRoot('framework/backend', ['PHPDOC-FQN']));

        $this->assertSame("PHPDOC-FQN framework/backend/One.php 1 # HIL-521\n" . $foreign, $this->writtenText($update));
        $this->assertStringContainsString('2 record(s)', $update->message());
        $this->assertStringContainsString('PHPDOC-FQN framework/backend/One.php: kept at 1, the tree has 3', $update->message());
        $this->assertStringContainsString('PHPDOC-FQN framework/backend/New.php: not written, the tree has 2', $update->message());
        $this->assertStringContainsString("  line 2\n  line 3", $update->message());
        $this->assertStringNotContainsString('THROWS-PROPAGATION', $update->message());
    }

    /** Integration ownership is also rule-wide; a later piece reads the preceding piece's output. */
    public function testSuccessiveDisjointUpdatesKeepEarlierChanges(): void
    {
        $baseline = Baseline::fromText("# original header\n"
            . "PHPDOC-FQN framework/backend/One.php 3 # HIL-521\n"
            . "INTEGRATION-TEST framework/tests/One.php 2 # HIL-522\n"
            . "INTEGRATION-TEST demo/chat/tests/Two.php 1 # HIL-523\n"
            . "THROWS-ORPHAN framework/backend/One.php 4 # HIL-524\n");
        $first = $baseline->update(
            $this->reported('PHPDOC-FQN framework/backend/One.php', 1),
            BaselineScope::forRoot('framework/backend', ['PHPDOC-FQN']),
        );

        $second = Baseline::fromText($this->writtenText($first))->update(
            $this->reported('INTEGRATION-TEST framework/tests/One.php', 1),
            BaselineScope::forRules(['INTEGRATION-TEST']),
        );

        $this->assertSame("# original header\n"
            . "PHPDOC-FQN framework/backend/One.php 1 # HIL-521\n"
            . "INTEGRATION-TEST framework/tests/One.php 1 # HIL-522\n"
            . "THROWS-ORPHAN framework/backend/One.php 4 # HIL-524\n", $this->writtenText($second));
    }

    /** Only the parser piece diagnoses corruption, but no piece may overwrite it. */
    public function testParseProblemsHaveOneReporterButRefuseEveryScopedUpdate(): void
    {
        $baseline = Baseline::fromText("PHPDOC-FQN framework/backend/One.php # HIL-521\n"
            . 'THROWS-ORPHAN framework/backend/Two.php 1 # later');
        $problems = [
            'baseline line 1 is malformed: PHPDOC-FQN framework/backend/One.php # HIL-521',
            'baseline record "THROWS-ORPHAN framework/backend/Two.php" names no owing leaf: later',
        ];
        $this->assertSame($problems, $baseline->reconcileUnowned([]));
        foreach ([
            BaselineScope::forRoot('framework/backend', ['PHPDOC-FQN']),
            BaselineScope::forRules(['THROWS-ORPHAN']),
            BaselineScope::forRules(['INTEGRATION-TEST']),
        ] as $scope) {
            $this->assertSame([], $baseline->reconcile([], $scope, reportParseProblems: false));
            $update = $baseline->update([], $scope, reportParseProblems: false);
            $this->assertNull($update->text());
            foreach ($problems as $problem) {
                $this->assertStringNotContainsString($problem, $update->message());
            }
        }
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

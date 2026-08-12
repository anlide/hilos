<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/unstable-line.php';

/**
 * The runner's half of the flaky gate: the grammar it reads out of a step's log,
 * and the two shapes it turns the result into.
 *
 * The file under test is a plain script rather than a class, so it is required by
 * path — it is loaded the same way `scripts/run-test-suite.php` loads it, and the
 * runner itself is deliberately not loaded here, because requiring it would run a
 * whole test suite.
 */
final class UnstableLineTest extends TestCase
{
    /** A step's log around whatever the reporter printed, so the pattern has to find it. */
    private const string SURROUNDING_LOG = "Running 42 tests using 1 worker\n%s\n  42 passed (3.1m)\n";

    /**
     * A clean step says nothing about flaky tests, and nothing is what is read
     * back — this is the case every green run takes.
     */
    public function testReadsNothingFromALogWithoutTheLine(): void
    {
        $log = sprintf(self::SURROUNDING_LOG, '  ok 1 [chromium] > chat.spec.ts:12 > sends a message');

        $this->assertSame(['count' => 0, 'tests' => []], readUnstableTests($log));
    }

    /**
     * One line yields its number and its names, in the order the reporter listed
     * them.
     */
    public function testReadsTheCountAndTheNamesFromOneLine(): void
    {
        $log = sprintf(self::SURROUNDING_LOG, 'hilos-unstable: 2 (tests/chat.spec.ts:42, tests/table.spec.ts:7)');

        $this->assertSame(
            ['count' => 2, 'tests' => ['tests/chat.spec.ts:42', 'tests/table.spec.ts:7']],
            readUnstableTests($log),
        );
    }

    /**
     * A step whose command drives Playwright more than once prints the line more
     * than once: the numbers add up and the lists join, rather than the last line
     * winning and the earlier flickers disappearing.
     */
    public function testAddsUpEveryLineTheStepPrinted(): void
    {
        $log = "hilos-unstable: 1 (tests/chat.spec.ts:42)\n"
            . "--- second window\n"
            . "hilos-unstable: 2 (tests/table.spec.ts:7, tests/table.spec.ts:9)\n";

        $this->assertSame(
            [
                'count' => 3,
                'tests' => ['tests/chat.spec.ts:42', 'tests/table.spec.ts:7', 'tests/table.spec.ts:9'],
            ],
            readUnstableTests($log),
        );
    }

    /**
     * The number is authoritative and the parentheses are passed through as they
     * came: an unreadable list must not quietly change how many flickers the run
     * admits to, because the count is what decides that anything is shown at all.
     */
    public function testKeepsTheCountWhenTheNamesAreNotWhatWasExpected(): void
    {
        $log = sprintf(self::SURROUNDING_LOG, 'hilos-unstable: 2 (¯\_(ツ)_/¯)');

        $this->assertSame(['count' => 2, 'tests' => ['¯\_(ツ)_/¯']], readUnstableTests($log));
    }

    /**
     * A line the pattern does not match is not half-read: an unclosed list is not
     * the grammar, so it counts for nothing at all.
     */
    public function testIgnoresALineThatDoesNotCloseItsList(): void
    {
        $log = sprintf(self::SURROUNDING_LOG, 'hilos-unstable: 2 (tests/chat.spec.ts:42');

        $this->assertSame(['count' => 0, 'tests' => []], readUnstableTests($log));
    }

    /** A clean step leaves its ledger entry exactly as every reader already parses it. */
    public function testAddsNoLedgerFieldWhenNothingFlickered(): void
    {
        $this->assertSame('', unstableLedgerField(noUnstableTests()));
    }

    /** A step that flickered carries the count after the entry, not instead of it. */
    public function testAppendsTheCountToTheLedgerEntry(): void
    {
        $unstable = ['count' => 3, 'tests' => ['tests/chat.spec.ts:42']];

        $this->assertSame(
            'chat-e2e rc=0 unstable=3',
            'chat-e2e rc=0' . unstableLedgerField($unstable),
        );
    }

    /** A run where every step was clean prints no section — the point of the gate. */
    public function testPrintsNoSummarySectionWhenEveryStepWasClean(): void
    {
        $byStep = ['framework' => noUnstableTests(), 'chat-e2e' => noUnstableTests()];

        $this->assertSame('', unstableSummarySection($byStep));
    }

    /**
     * The section counts the steps and the tests separately, and names what to go
     * and look at; steps that reported nothing stay out of it.
     */
    public function testCountsStepsAndTestsSeparatelyAndNamesThem(): void
    {
        $byStep = [
            'framework' => noUnstableTests(),
            'chat-e2e' => ['count' => 2, 'tests' => ['tests/chat.spec.ts:42', 'tests/table.spec.ts:7']],
            'simple-poll-e2e' => ['count' => 1, 'tests' => ['tests/poll.spec.ts:3']],
        ];

        $this->assertSame(
            "=== unstable: 2 step(s), 3 retried test(s) ===\n"
                . "  chat-e2e             tests/chat.spec.ts:42, tests/table.spec.ts:7\n"
                . "  simple-poll-e2e      tests/poll.spec.ts:3\n",
            unstableSummarySection($byStep),
        );
    }
}

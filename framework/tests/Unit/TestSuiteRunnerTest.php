<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StepOutcome;

require_once __DIR__ . '/../../../scripts/run-test-suite.php';

/** Real runner functions, imported without starting a graph or another process. */
final class TestSuiteRunnerTest extends TestCase
{
    /** The timestamp is input to reporting, so these assertions need no clock or disk. */
    private const string AT = '2026-10-24T12:00:00+00:00';

    /** A completed producer observation, independent of the command's exit code. */
    private const string FAILED_QUARANTINE_LINE = <<<'JSON'
hilos-quarantine: {"flake":"F-023","status":"failed","file":"cluster/daemon/daemon.spec.ts","line":56,"project":"daemon","titlePath":["cluster daemon: foreign node pages, remote restart, environment mismatch and leader loss"],"reason":""}
JSON;

    /** An absent repeat leaves both the full graph and the old pointed command intact. */
    public function testDefaultAndPointingWithoutRepeat(): void
    {
        $options = parseArguments([]);
        $this->assertNull($options['repeat']);
        $this->assertSame([], $options['tests']);
        $this->assertSame(self::manifest(), narrowToTests(self::manifest(), [], [], $options['repeat']));
        $this->assertSame(array_keys(self::manifest()), planFor(self::manifest(), []));

        $options = parseArguments(['e2e', '--tests= tests/a.spec.ts:71, tests/b.spec.ts:12 ', '--lanes=1']);
        $this->assertNull($options['repeat']);
        $this->assertSame(1, $options['lanes']);
        $pointed = narrowToTests(self::manifest(), $options['targets'], $options['tests'], $options['repeat']);
        $this->assertSame(
            "composer run test:e2e-full -- 'tests/a.spec.ts:71' 'tests/b.spec.ts:12'",
            $pointed['e2e']['command'],
        );
        $this->assertSame(self::manifest()['prepare'], $pointed['prepare']);
    }

    /** @return iterable<string, array{string, int}> Decimal values, including the integer limit. */
    public static function validRepeatCounts(): iterable
    {
        yield 'one is explicit' => ['1', 1];
        yield 'five observations' => ['5', 5];
        yield 'decimal leading zeros' => ['0005', 5];
        yield 'integer maximum' => [(string)PHP_INT_MAX, PHP_INT_MAX];
        yield 'padded integer maximum' => ['000' . PHP_INT_MAX, PHP_INT_MAX];
    }

    /**
     * @param string $value The CLI spelling.
     * @param int $expected The count represented by that spelling.
     */
    #[DataProvider('validRepeatCounts')]
    public function testParsesExplicitRepeat(string $value, int $expected): void
    {
        $options = parseArguments(['e2e', '--tests=tests/a.spec.ts:71', '--repeat=' . $value]);
        $this->assertSame($expected, $options['repeat']);
    }

    /** @return iterable<string, array{string}> Invalid spellings and overflow on either PHP integer width. */
    public static function invalidRepeatCounts(): iterable
    {
        foreach (['', '0', '000', '-1', '+1', '1.5', '1e2', 'text', '5x', ' 5', '5 ', "5\n", '0x10'] as $value) {
            yield 'value ' . var_export($value, true) => [$value];
        }
        yield 'one over integer maximum' => [PHP_INT_SIZE === 8 ? '9223372036854775808' : '2147483648'];
        yield 'much larger than integer maximum' => [(string)PHP_INT_MAX . '0'];
    }

    /** @param string $value An invalid repeat count. */
    #[DataProvider('invalidRepeatCounts')]
    public function testRejectsRepeatBeforePlanning(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--repeat');
        parseArguments(['e2e', '--tests=tests/a.spec.ts:71', '--repeat=' . $value]);
    }

    /** @return iterable<string, array{array<int, string>}> Invalid option and target combinations. */
    public static function invalidRepeatTargets(): iterable
    {
        yield 'missing value' => [['e2e', '--tests=tests/a.spec.ts:71', '--repeat']];
        yield 'no tests even with one' => [['e2e', '--repeat=1']];
        yield 'empty tests before repeat' => [['e2e', '--tests=', '--repeat=1']];
        yield 'empty tests after repeat' => [['e2e', '--repeat=5', '--tests= , ']];
        yield 'no target' => [['--tests=tests/a.spec.ts:71', '--repeat=5']];
        yield 'two targets' => [['e2e', 'independent', '--tests=tests/a.spec.ts:71', '--repeat=5']];
        yield 'same target twice' => [['e2e', 'e2e', '--tests=tests/a.spec.ts:71', '--repeat=5']];
        yield 'tag instead of id' => [['frontend', '--tests=tests/a.spec.ts:71', '--repeat=5']];
        yield 'unknown id' => [['unknown', '--tests=tests/a.spec.ts:71', '--repeat=5']];
        yield 'step without takesTests' => [['prepare', '--tests=tests/a.spec.ts:71', '--repeat=5']];
        yield 'step declining tests' => [['cluster', '--tests=tests/a.spec.ts:71', '--repeat=5']];
    }

    /** @param array<int, string> $arguments A call that cannot repeat selected tests. */
    #[DataProvider('invalidRepeatTargets')]
    public function testRejectsUnusableRepeatTargets(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('--repeat');
        $options = parseArguments($arguments);
        narrowToTests(self::manifest(), $options['targets'], $options['tests'], $options['repeat']);
    }

    /** Repetition belongs only to the selected command, after the same Composer separator. */
    public function testRepeatsTwoTestsWithoutChangingDependencies(): void
    {
        $options = parseArguments(['e2e', '--tests=tests/a.spec.ts:71,tests/b.spec.ts:12', '--repeat=5']);
        $pointed = narrowToTests(self::manifest(), $options['targets'], $options['tests'], $options['repeat']);
        $this->assertSame(
            "composer run test:e2e-full -- 'tests/a.spec.ts:71' 'tests/b.spec.ts:12' --repeat-each=5",
            $pointed['e2e']['command'],
        );
        $this->assertSame(['prepare', 'e2e'], planFor($pointed, $options['targets']));
        foreach (['prepare', 'child', 'independent', 'cluster'] as $id) {
            $this->assertSame(self::manifest()[$id], $pointed[$id]);
        }
        $once = narrowToTests(self::manifest(), ['e2e'], ['tests/a.spec.ts:71'], 1);
        $this->assertSame("composer run test:e2e-full -- 'tests/a.spec.ts:71' --repeat-each=1", $once['e2e']['command']);
    }

    /** Shell metacharacters stay in one selector when repetition is appended. */
    public function testEscapesSelectedTests(): void
    {
        $pointed = narrowToTests(self::manifest(), ['e2e'], ["tests/it's a test.spec.ts:1; echo oops"], 5);
        $this->assertSame(
            "composer run test:e2e-full -- 'tests/it'\\''s a test.spec.ts:1; echo oops' --repeat-each=5",
            $pointed['e2e']['command'],
        );
    }

    /** The unstable result is already red when dependencies and all reports read it. */
    public function testUnstableResultBlocksOnlyItsDescendantsAndReportsRed(): void
    {
        $finished = finishedStep(0, "hilos-unstable: 1 (cluster/scenario-46)\n", self::AT, 3.0);
        $this->assertSame(StepOutcome::Failed, $finished['outcome']);
        $this->assertSame(1, $finished['rc']);
        $done = ['e2e' => $finished];
        $this->assertFalse(dependenciesMet(['e2e'], $done));
        $this->assertSame(['independent'], launchable(self::manifest(), ['child', 'independent'], [], $done, 2));
        $this->assertSame("e2e rc=1 unstable=1\n", stepLedgerLine('e2e', $finished));

        $done['child'] = skipped(self::manifest()['child'], $done);
        $done['independent'] = finishedStep(0, 'clean', self::AT, 2.0);
        $this->assertSame(StepOutcome::Skipped, $done['child']['outcome']);
        $this->assertSame('e2e FAILED', $done['child']['note']);
        $report = suiteSummary(['e2e', 'child', 'independent'], $done, [], 2, 5.0, 'logs', self::AT);
        $this->assertSame(1, $report['rc']);
        $this->assertStringContainsString('  FAILED   e2e', $report['text']);
        $this->assertStringContainsString('=== 1 ok, 1 failed, 1 skipped — logs in logs ===', $report['text']);
        $this->assertStringContainsString("=== unstable: 1 step(s), 1 retried test(s) ===\n", $report['text']);
        $this->assertStringContainsString('cluster/scenario-46', $report['text']);
    }

    /** Native failures keep their code even when retry evidence is also present. */
    public function testPreservesNativeFailureInTheResultAndLedger(): void
    {
        $finished = finishedStep(7, "hilos-unstable: 1 (tests/a.spec.ts:71)\n", self::AT, 1.0);
        $this->assertSame(StepOutcome::Failed, $finished['outcome']);
        $this->assertSame(7, $finished['rc']);
        $this->assertSame("e2e rc=7 unstable=1\n", stepLedgerLine('e2e', $finished));
        $this->assertSame(1, suiteSummary(['e2e'], ['e2e' => $finished], [], 1, 1.0, 'logs', self::AT)['rc']);
    }

    /** A quarantine failure is visible while the real completed step releases its child. */
    public function testQuarantineFailureKeepsTheCompletedStepAndItsDescendantsGreen(): void
    {
        $log = self::FAILED_QUARANTINE_LINE . "\n"
            . str_replace(
                ['"status":"failed"', '"project":"daemon"'],
                ['"status":"passed"', '"project":"mobile"'],
                self::FAILED_QUARANTINE_LINE,
            );
        $finished = finishedStep(0, $log, self::AT, 3.0);
        $this->assertSame(StepOutcome::Ok, $finished['outcome']);
        $this->assertSame(0, $finished['rc']);
        $this->assertSame(2, $finished['quarantine']['count']);
        $this->assertSame(1, $finished['quarantine']['failed']);
        $done = ['e2e' => $finished];
        $this->assertTrue(dependenciesMet(['e2e'], $done));
        $this->assertSame(['child', 'independent'], launchable(self::manifest(), ['child', 'independent'], [], $done, 2));
        $this->assertSame(
            "tasks-e2e rc=0 quarantine=2 quarantine_failed=1 quarantine_not_run=0\n",
            stepLedgerLine('tasks-e2e', $finished),
        );

        $done['child'] = finishedStep(0, 'all passed', self::AT, 2.0);
        $report = suiteSummary(['e2e', 'child'], $done, [], 1, 5.0, 'logs', self::AT);
        $this->assertSame(0, $report['rc']);
        $this->assertStringContainsString('  ok       e2e', $report['text']);
        $this->assertStringContainsString('=== 2 ok, 0 failed, 0 skipped — logs in logs ===', $report['text']);
        $this->assertStringContainsString('=== quarantine: 1 step(s), 2 test(s), 1 failed, 0 not run ===', $report['text']);
        $this->assertStringNotContainsString('=== unstable:', $report['text']);
        $this->assertSame(noQuarantineTests(), readQuarantineTests($report['text']));
    }

    /** Native failures keep their exact code and still block descendants with quarantine present. */
    public function testNativeFailureRemainsAuthoritativeWithQuarantine(): void
    {
        $finished = finishedStep(7, self::FAILED_QUARANTINE_LINE, self::AT, 1.0);
        $this->assertSame(StepOutcome::Failed, $finished['outcome']);
        $this->assertSame(7, $finished['rc']);
        $done = ['e2e' => $finished];
        $this->assertFalse(dependenciesMet(['e2e'], $done));
        $this->assertSame(['independent'], launchable(self::manifest(), ['child', 'independent'], [], $done, 2));
        $this->assertSame(
            "e2e rc=7 quarantine=1 quarantine_failed=1 quarantine_not_run=0\n",
            stepLedgerLine('e2e', $finished),
        );
        $report = suiteSummary(['e2e'], $done, [], 1, 1.0, 'logs', self::AT);
        $this->assertSame(1, $report['rc']);
        $this->assertStringContainsString('=== 0 ok, 1 failed, 0 skipped', $report['text']);
    }

    /** Main retry evidence retains its verdict, ledger field and section before quarantine. */
    public function testMainUnstableKeepsItsGateWhenQuarantineIsAlsoReported(): void
    {
        $finished = finishedStep(
            0,
            "hilos-unstable: 1 (cluster/scenario-46)\n" . self::FAILED_QUARANTINE_LINE,
            self::AT,
            3.0,
        );
        $this->assertSame(StepOutcome::Failed, $finished['outcome']);
        $this->assertSame(1, $finished['rc']);
        $this->assertFalse(dependenciesMet(['e2e'], ['e2e' => $finished]));
        $this->assertSame(
            "e2e rc=1 unstable=1 quarantine=1 quarantine_failed=1 quarantine_not_run=0\n",
            stepLedgerLine('e2e', $finished),
        );
        $report = suiteSummary(['e2e'], ['e2e' => $finished], [], 1, 3.0, 'logs', self::AT);
        $this->assertSame(1, $report['rc']);
        $this->assertStringContainsString('=== 0 ok, 1 failed, 0 skipped', $report['text']);
        $this->assertStringContainsString(
            "=== unstable: 1 step(s), 1 retried test(s) ===\n"
                . "  e2e                  cluster/scenario-46\n"
                . "=== quarantine: 1 step(s), 1 test(s), 1 failed, 0 not run ===\n",
            $report['text'],
        );
    }

    /** No quarantine status or reason contributes to the main failed/skipped totals. */
    public function testEveryQuarantineStatusLeavesTheMainSummaryGreen(): void
    {
        foreach (['passed', 'failed', 'timedOut', 'skipped', 'interrupted', 'notRun'] as $status) {
            $observation = json_decode(substr(self::FAILED_QUARANTINE_LINE, strlen('hilos-quarantine: ')), true, flags: JSON_THROW_ON_ERROR);
            $observation['status'] = $status;
            $observation['reason'] = "host failed\nhilos-unstable: 1 (quarantine must not become main evidence)";
            $finished = finishedStep(
                0,
                'hilos-quarantine: ' . json_encode($observation, JSON_THROW_ON_ERROR),
                self::AT,
                1.0,
            );
            $this->assertSame(StepOutcome::Ok, $finished['outcome']);
            $this->assertSame(0, $finished['rc']);
            $report = suiteSummary(['e2e'], ['e2e' => $finished], [], 1, 1.0, 'logs', self::AT);
            $this->assertSame(0, $report['rc']);
            $this->assertStringContainsString('=== 1 ok, 0 failed, 0 skipped', $report['text']);
            $this->assertStringNotContainsString('=== unstable:', $report['text']);
        }
    }

    /** A clean step keeps the previous ledger and summary text, with no unstable section. */
    public function testCleanResultKeepsTheReportSilent(): void
    {
        $finished = finishedStep(0, 'all passed', self::AT, 3.0);
        $this->assertSame(StepOutcome::Ok, $finished['outcome']);
        $this->assertSame(noQuarantineTests(), $finished['quarantine']);
        $this->assertSame("e2e rc=0\n", stepLedgerLine('e2e', $finished));
        $this->assertTrue(dependenciesMet(['e2e'], ['e2e' => $finished]));
        $report = suiteSummary(['e2e'], ['e2e' => $finished], [], 1, 3.0, 'logs', self::AT);
        $this->assertSame(0, $report['rc']);
        $this->assertSame(
            '=== SUMMARY ' . self::AT . " — 3s at 1 lane(s) ===\n"
                . "  ok       e2e                        3s  logs/e2e.log\n"
                . "=== 1 ok, 0 failed, 0 skipped — logs in logs ===\n",
            $report['text'],
        );
    }

    /** A skipped step alone still makes the overall run nonzero. */
    public function testSkippedResultMakesTheRunRed(): void
    {
        $finished = skipped(self::manifest()['child'], []);
        $this->assertSame(noQuarantineTests(), $finished['quarantine']);
        $report = suiteSummary(['child'], ['child' => $finished], [], 1, 0.0, 'logs', self::AT);
        $this->assertSame(1, $report['rc']);
        $this->assertStringContainsString('=== 0 ok, 0 failed, 1 skipped', $report['text']);
        $this->assertStringNotContainsString('=== unstable:', $report['text']);
        $this->assertStringNotContainsString('=== quarantine:', $report['text']);
    }

    /** A blocked child gets the old SKIPPED ledger rather than an rc or quarantine field. */
    public function testSkippedLedgerKeepsItsPreviousGrammar(): void
    {
        $failed = finishedStep(7, self::FAILED_QUARANTINE_LINE, self::AT, 1.0);
        $finished = skipped(self::manifest()['child'], ['e2e' => $failed]);
        $ledger = fopen('php://memory', 'w+');
        try {
            reportSkip('child', $finished, $ledger);
            rewind($ledger);
            $this->assertSame("child SKIPPED (e2e FAILED)\n", stream_get_contents($ledger));
            $this->assertSame(noQuarantineTests(), $finished['quarantine']);
        } finally {
            fclose($ledger);
        }
    }

    /** @return array<string, array<string, mixed>> A small graph with a dependency and an unrelated branch. */
    private static function manifest(): array
    {
        return [
            'prepare' => ['command' => 'prepare stand', 'deps' => [], 'group' => null, 'tags' => ['backend']],
            'e2e' => [
                'command' => 'composer run test:e2e-full', 'deps' => ['prepare'], 'group' => null,
                'tags' => ['frontend'], 'takesTests' => true,
            ],
            'child' => ['command' => 'after e2e', 'deps' => ['e2e'], 'group' => null, 'tags' => []],
            'independent' => ['command' => 'independent', 'deps' => [], 'group' => null, 'tags' => []],
            'cluster' => ['command' => 'cluster', 'deps' => [], 'group' => null, 'tags' => [], 'takesTests' => false],
        ];
    }
}

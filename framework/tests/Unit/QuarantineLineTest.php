<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../scripts/quarantine-line.php';

/** The real producer protocol, parsed without starting Node or the test graph. */
final class QuarantineLineTest extends TestCase
{
    /** Canonical normalizeResults/quarantineLine output from the approved producer contract. */
    private const string PRODUCER_LINE = <<<'JSON'
hilos-quarantine: {"flake":"F-023","status":"failed","file":"cluster/daemon/daemon.spec.ts","line":56,"project":"daemon","titlePath":["cluster daemon: foreign node pages, remote restart, environment mismatch and leader loss"],"reason":""}
JSON;

    /** Silence, unrelated output and indented summary fixtures are not observations. */
    public function testSilentAndUnrelatedLogsKeepTheOldReportEmpty(): void
    {
        foreach (['', "42 passed\n", '  ' . self::PRODUCER_LINE, 'output: ' . self::PRODUCER_LINE] as $log) {
            $this->assertSame(['count' => 0, 'failed' => 0, 'notRun' => 0, 'tests' => []], readQuarantineTests($log));
        }
        $this->assertSame('', quarantineLedgerField(noQuarantineTests()));
        $this->assertSame('', quarantineSummarySection([]));
        $this->assertSame('', quarantineSummarySection(['framework-unit' => noQuarantineTests()]));
    }

    /** Every canonical field survives the actual producer's JSON representation. */
    public function testReadsTheCanonicalProducerLine(): void
    {
        $this->assertSame(
            ['count' => 1, 'failed' => 1, 'notRun' => 0, 'tests' => [self::observation()]],
            readQuarantineTests("Running tests\n" . self::PRODUCER_LINE . "\r\nfinished\n"),
        );
    }

    /** @return iterable<string, array{string, int, int}> All producer statuses and their counters. */
    public static function statuses(): iterable
    {
        yield 'passed' => ['passed', 0, 0];
        yield 'failed' => ['failed', 1, 0];
        yield 'timed out' => ['timedOut', 1, 0];
        yield 'skipped' => ['skipped', 0, 1];
        yield 'interrupted' => ['interrupted', 0, 1];
        yield 'not run' => ['notRun', 0, 1];
    }

    /**
     * @param string $status A producer status.
     * @param int $failed Its failed contribution.
     * @param int $notRun Its not-run contribution.
     */
    #[DataProvider('statuses')]
    public function testCountsEveryStatusWithoutReclassifyingIt(string $status, int $failed, int $notRun): void
    {
        $observation = array_replace(self::observation(), ['status' => $status]);
        $this->assertSame(
            ['count' => 1, 'failed' => $failed, 'notRun' => $notRun, 'tests' => [$observation]],
            readQuarantineTests('hilos-quarantine: ' . json_encode($observation, JSON_THROW_ON_ERROR)),
        );
    }

    /** Quotes and escaped newlines from the Node unit fixture remain data on one line. */
    public function testPreservesProducerUnicodeQuotesAndNewlines(): void
    {
        $line = <<<'JSON'
hilos-quarantine: {"flake":"F-023","status":"passed","file":"tests/space name.spec.ts","line":12,"project":"desktop","titlePath":["suite [.*] Юникод","admin (a+b)?"],"reason":"a \"quote\"\nand a newline"}
JSON;
        $observation = [
            'flake' => 'F-023', 'status' => 'passed', 'file' => 'tests/space name.spec.ts',
            'line' => 12, 'project' => 'desktop', 'titlePath' => ['suite [.*] Юникод', 'admin (a+b)?'],
            'reason' => "a \"quote\"\nand a newline",
        ];
        $quarantine = readQuarantineTests($line);
        $this->assertSame(['count' => 1, 'failed' => 0, 'notRun' => 0, 'tests' => [$observation]], $quarantine);
        $summary = quarantineSummarySection(['tasks-e2e' => $quarantine]);
        $this->assertStringContainsString('suite [.*] Юникод', $summary);
        $this->assertStringContainsString('"reason":"a \\"quote\\"\\nand a newline"', $summary);
        $this->assertSame(2, substr_count($summary, "\n"));
        $this->assertSame($quarantine, readQuarantineTests($line . "\n" . $summary));
    }

    /** Default unnamed projects and empty reasons are legal producer values. */
    public function testAcceptsTheDefaultProjectAndLongFlakeNumbers(): void
    {
        $observation = array_replace(self::observation(), ['flake' => 'F-1234', 'project' => '', 'titlePath' => ['']]);
        $this->assertSame(
            [$observation],
            readQuarantineTests('hilos-quarantine: ' . json_encode($observation, JSON_THROW_ON_ERROR))['tests'],
        );
    }

    /** One flake can cover distinct titles, projects and files at the same source line. */
    public function testKeepsEveryIdentityForTheSameFlake(): void
    {
        $base = array_replace(self::observation(), ['flake' => 'F-016']);
        $tests = [
            $base,
            array_replace($base, ['titlePath' => ['another test']]),
            array_replace($base, ['project' => 'mobile']),
            array_replace($base, ['file' => 'tests/another.spec.ts']),
        ];
        $log = implode("\n", array_map(
            static fn(array $test): string => 'hilos-quarantine: ' . json_encode($test, JSON_THROW_ON_ERROR),
            $tests,
        ));
        $this->assertSame(['count' => 4, 'failed' => 4, 'notRun' => 0, 'tests' => $tests], readQuarantineTests($log));
    }

    /** @return iterable<string, array{string}> Invalid JSON, missing fields and wrong schema types. */
    public static function invalidLines(): iterable
    {
        foreach (['', '{', '{"status":"passed"', 'null', 'true', '1', '"passed"', '[]', '{}'] as $json) {
            yield 'JSON ' . $json => ['hilos-quarantine: ' . $json];
        }
        foreach (array_keys(self::observation()) as $field) {
            $missing = self::observation();
            unset($missing[$field]);
            yield 'missing ' . $field => ['hilos-quarantine: ' . json_encode($missing, JSON_THROW_ON_ERROR)];
            yield 'null ' . $field => [
                'hilos-quarantine: ' . json_encode(array_replace(self::observation(), [$field => null]), JSON_THROW_ON_ERROR),
            ];
        }
        $invalidFields = [
            'flake' => ['', 'F-23', 'F-023suffix', "F-023\n", '@quarantine:F-023', 23],
            'status' => ['', 'flaky', 'unknown', 'Passed', 0],
            'file' => ['', 1, [], '/absolute.spec.ts', 'C:/absolute.spec.ts', 'C:\\absolute.spec.ts'],
            'line' => [0, -1, 56.5, '56', true],
            'project' => [1, false, []],
            'titlePath' => [[], 'title', [1], ['title', null], (object)['0' => 'title']],
            'reason' => [1, false, []],
        ];
        foreach ($invalidFields as $field => $values) {
            foreach ($values as $index => $value) {
                yield $field . ' value ' . $index => [
                    'hilos-quarantine: ' . json_encode(array_replace(self::observation(), [$field => $value]), JSON_THROW_ON_ERROR),
                ];
            }
        }
        yield 'integer-looking JSON float' => [str_replace('"line":56', '"line":56.0', self::PRODUCER_LINE)];
        yield 'trailing garbage' => [self::PRODUCER_LINE . ' garbage'];
        yield 'wrong prefix case' => [str_replace('hilos-quarantine:', 'Hilos-quarantine:', self::PRODUCER_LINE)];
        yield 'no prefix space' => [str_replace('hilos-quarantine: ', 'hilos-quarantine:', self::PRODUCER_LINE)];
    }

    /** @param string $line A malformed or non-protocol line. */
    #[DataProvider('invalidLines')]
    public function testRejectsMalformedLinesWithoutInventingAPass(string $line): void
    {
        $this->assertSame(noQuarantineTests(), readQuarantineTests($line));
        $this->assertSame(
            ['count' => 1, 'failed' => 1, 'notRun' => 0, 'tests' => [self::observation()]],
            readQuarantineTests($line . "\n" . self::PRODUCER_LINE),
        );
    }

    /** The exact suffix follows the sole rc verdict and names independent counters. */
    public function testLedgerGrammar(): void
    {
        $this->assertSame(
            'tasks-e2e rc=0 quarantine=2 quarantine_failed=1 quarantine_not_run=0',
            'tasks-e2e rc=0' . quarantineLedgerField(['count' => 2, 'failed' => 1, 'notRun' => 0]),
        );
    }

    /** The full summary grammar retains the source address and unprefixed producer JSON. */
    public function testCanonicalSummaryText(): void
    {
        $this->assertSame(
            "=== quarantine: 1 step(s), 1 test(s), 1 failed, 0 not run ===\n"
                . '  tasks-e2e            "cluster/daemon/daemon.spec.ts:56" '
                . substr(self::PRODUCER_LINE, strlen('hilos-quarantine: ')) . "\n",
            quarantineSummarySection(['tasks-e2e' => readQuarantineTests(self::PRODUCER_LINE)]),
        );
    }

    /** Summary counts steps and statuses independently and never repeats the line prefix. */
    public function testSummaryReportsAllObservationsInStepOrderWithoutReplay(): void
    {
        $failed = readQuarantineTests(self::PRODUCER_LINE);
        $notRun = array_replace(self::observation(), ['status' => 'notRun', 'reason' => 'globalSetup failed']);
        $passed = array_replace(self::observation(), ['status' => 'passed', 'project' => '']);
        $other = readQuarantineTests(
            'hilos-quarantine: ' . json_encode($notRun, JSON_THROW_ON_ERROR) . "\n"
                . 'hilos-quarantine: ' . json_encode($passed, JSON_THROW_ON_ERROR),
        );
        $summary = quarantineSummarySection(['tasks-e2e' => $failed, 'clean' => noQuarantineTests(), 'polls-e2e' => $other]);
        $this->assertStringStartsWith('=== quarantine: 2 step(s), 3 test(s), 1 failed, 1 not run ===' . "\n", $summary);
        $this->assertStringContainsString('tasks-e2e            "cluster/daemon/daemon.spec.ts:56" {"flake":"F-023"', $summary);
        $this->assertStringContainsString('"status":"notRun"', $summary);
        $this->assertStringContainsString('"project":""', $summary);
        $this->assertStringContainsString('"reason":"globalSetup failed"', $summary);
        $this->assertStringNotContainsString('clean', $summary);
        $this->assertStringNotContainsString('hilos-quarantine:', $summary);
        $this->assertSame(4, substr_count($summary, "\n"));
        $this->assertSame(noQuarantineTests(), readQuarantineTests($summary));
    }

    /**
     * @return array{flake: string, status: string, file: string, line: int,
     *     project: string, titlePath: list<string>, reason: string} Canonical producer row.
     */
    private static function observation(): array
    {
        return [
            'flake' => 'F-023',
            'status' => 'failed',
            'file' => 'cluster/daemon/daemon.spec.ts',
            'line' => 56,
            'project' => 'daemon',
            'titlePath' => ['cluster daemon: foreign node pages, remote restart, environment mismatch and leader loss'],
            'reason' => '',
        ];
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Testing;

use Hilos\Testing\ProcessEnvironment\ProcessEnvironmentSnapshots;
use PHPUnit\Framework\TestCase;

/**
 * Each case closes the suites it opens. Probe variables and the rest of the
 * process environment, including APP_ENV, match what the case found.
 */
final class ProcessEnvironmentSnapshotsTest extends TestCase
{
    private const string ERASED = 'HILOS_PROCESS_ENVIRONMENT_TEST_ERASED';

    private const string CHANGED = 'HILOS_PROCESS_ENVIRONMENT_TEST_CHANGED';

    private const string ADDED = 'HILOS_PROCESS_ENVIRONMENT_TEST_ADDED';

    private const string SUITE = 'HILOS_PROCESS_ENVIRONMENT_TEST_SUITE';

    private const string OUTER = 'HILOS_PROCESS_ENVIRONMENT_TEST_OUTER';

    private const string INNER = 'HILOS_PROCESS_ENVIRONMENT_TEST_INNER';

    /** closeTest puts an erased name back, reverts a changed one, and removes an added one. */
    public function testCloseTestRestoresErasedChangedAndAddedVariables(): void
    {
        $this->runScenario(function (ProcessEnvironmentSnapshots $snapshots): void {
            $snapshots->openSuite();
            putenv(self::ERASED . '=kept');
            putenv(self::CHANGED . '=kept');
            $snapshots->openTest();
            putenv(self::ERASED);
            putenv(self::CHANGED . '=changed');
            putenv(self::ADDED . '=added');
            $snapshots->closeTest();

            self::assertSame('kept', getenv(self::ERASED));
            self::assertSame('kept', getenv(self::CHANGED));
            self::assertFalse(getenv(self::ADDED));
            $snapshots->closeSuite();
        }, 1);
    }

    /** A name set after the suite opens and before the test opens outlives the test and leaves with the suite. */
    public function testNameSetBetweenSuiteAndTestOutlivesTheTestAndLeavesWithTheSuite(): void
    {
        $this->runScenario(function (ProcessEnvironmentSnapshots $snapshots): void {
            $snapshots->openSuite();
            putenv(self::SUITE . '=suite');
            $snapshots->openTest();
            self::assertSame('suite', getenv(self::SUITE));
            putenv(self::SUITE . '=during-test');
            $snapshots->closeTest();
            self::assertSame('suite', getenv(self::SUITE));
            $snapshots->closeSuite();
            self::assertFalse(getenv(self::SUITE));
        }, 1);
    }

    /** A second openTest restores the snapshot of a test that never closed. */
    public function testNextOpenTestClosesATestThatNeverClosed(): void
    {
        $this->runScenario(function (ProcessEnvironmentSnapshots $snapshots): void {
            $snapshots->openSuite();
            putenv(self::SUITE . '=suite');
            $snapshots->openTest();
            putenv(self::SUITE . '=leaked');
            putenv(self::ADDED . '=added');
            $snapshots->openTest();

            self::assertSame('suite', getenv(self::SUITE));
            self::assertFalse(getenv(self::ADDED));
            $snapshots->closeTest();
            $snapshots->closeSuite();
        }, 1);
    }

    /** closeSuite restores a test that never closed, then the suite. */
    public function testCloseSuiteClosesATestThatNeverClosed(): void
    {
        $this->runScenario(function (ProcessEnvironmentSnapshots $snapshots): void {
            $snapshots->openSuite();
            putenv(self::SUITE . '=suite');
            $snapshots->openTest();
            putenv(self::SUITE . '=leaked');
            putenv(self::ADDED . '=added');
            $snapshots->closeSuite();

            self::assertFalse(getenv(self::SUITE));
            self::assertFalse(getenv(self::ADDED));
        }, 1);
    }

    /** Each closeSuite restores only the newest suite still open. */
    public function testNestedSuitesCloseOneAtATime(): void
    {
        $this->runScenario(function (ProcessEnvironmentSnapshots $snapshots): void {
            $snapshots->openSuite();
            putenv(self::OUTER . '=outer');
            $snapshots->openSuite();
            putenv(self::INNER . '=inner');
            $snapshots->openTest();
            putenv(self::OUTER . '=mutated');
            putenv(self::INNER . '=mutated');
            putenv(self::ADDED . '=added');
            $snapshots->closeSuite();

            self::assertSame('outer', getenv(self::OUTER));
            self::assertFalse(getenv(self::INNER));
            self::assertFalse(getenv(self::ADDED));

            $snapshots->openTest();
            self::assertFalse(getenv(self::INNER));
            $snapshots->closeTest();
            $snapshots->closeSuite();
            self::assertFalse(getenv(self::OUTER));
        }, 2);
    }

    /**
     * Runs one case on a fresh snapshot stack and leaves the process environment as it found it.
     * Every suite the case opens is closed here, including when the case stops early.
     *
     * @param callable(ProcessEnvironmentSnapshots): void $scenario Case body
     * @param int $suitesToClose How many suites the case opens
     */
    private function runScenario(callable $scenario, int $suitesToClose): void
    {
        $before = $this->environment();
        $snapshots = new ProcessEnvironmentSnapshots();
        try {
            $scenario($snapshots);
        } finally {
            for ($index = 0; $index < $suitesToClose; $index++) {
                $snapshots->closeSuite();
            }
        }
        self::assertSame($before, $this->environment());
    }

    /** @return array<string, string> Every variable the process exposes, sorted by name */
    private function environment(): array
    {
        $environment = getenv();
        self::assertIsArray($environment);
        ksort($environment);

        return $environment;
    }
}

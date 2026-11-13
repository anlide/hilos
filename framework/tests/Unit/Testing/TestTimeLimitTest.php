<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Testing;

use Hilos\Testing\TimeLimit\TestTimeLimit;
use Hilos\Testing\TimeLimit\TestTimeLimitExceeded;
use Hilos\Testing\TimeLimit\TestTimeLimitExtension;
use PHPUnit\Framework\TestCase;

final class TestTimeLimitTest extends TestCase
{
    private mixed $originalHandler;
    private bool $originalAsync;
    private int $originalAlarm;

    /** Preserves the enclosing PHPUnit extension's alarm while a case tests its own instance. */
    protected function setUp(): void
    {
        $this->originalHandler = pcntl_signal_get_handler(SIGALRM);
        $this->originalAsync = pcntl_async_signals();
        $this->originalAlarm = pcntl_alarm(0);
        pcntl_alarm($this->originalAlarm);
    }

    /** Restores the enclosing handler, dispatch mode and remaining alarm even after an assertion fails. */
    protected function tearDown(): void
    {
        $this->restoreEnclosingAlarm();
    }

    /** The real XML registration already covers an ordinary test's body. */
    public function testExtensionInstallsTheAlarmHandler(): void
    {
        try {
            self::assertNotSame(SIG_DFL, pcntl_signal_get_handler(SIGALRM));
            self::assertTrue(pcntl_async_signals());
            self::assertGreaterThan(0, pcntl_alarm(0));
        } finally {
            $this->restoreEnclosingAlarm();
        }
    }

    /** A delivered signal interrupts PHP work and writes the exact first-stage diagnostic. */
    public function testAlarmInterruptsThePhpLoopAndWritesTheDiagnostic(): void
    {
        $messages = [];
        $timeLimit = new TestTimeLimit(static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        try {
            $timeLimit->arm('LoopTest::testLoop');
            try {
                while (true) {
                    posix_kill(posix_getpid(), SIGALRM);
                }
            } catch (TestTimeLimitExceeded $exception) {
                self::assertSame(TestTimeLimit::exceededMessage('LoopTest::testLoop'), $exception->getMessage());
                self::assertSame([$exception->getMessage() . PHP_EOL], $messages);
                self::assertSame(TestTimeLimit::GRACE_SECONDS, pcntl_alarm(0));
            }
        } finally {
            $timeLimit->disarm();
            $this->restoreEnclosingAlarm();
        }
    }

    /** A second arm changes attribution and starts another full test interval. */
    public function testRearmingReplacesTheTestName(): void
    {
        $messages = [];
        $timeLimit = new TestTimeLimit(static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        try {
            $timeLimit->arm('FirstTest');
            $timeLimit->arm('ReplacementTest');
            self::assertSame(TestTimeLimit::SECONDS, pcntl_alarm(0));
            try {
                posix_kill(posix_getpid(), SIGALRM);
                self::fail('The alarm did not interrupt the test');
            } catch (TestTimeLimitExceeded $exception) {
                self::assertSame(TestTimeLimit::exceededMessage('ReplacementTest'), $exception->getMessage());
            }
        } finally {
            $timeLimit->disarm();
            $this->restoreEnclosingAlarm();
        }
    }

    /** Disarming cancels the OS alarm and returns both signal settings to their previous values. */
    public function testDisarmingRestoresTheSignalSettings(): void
    {
        pcntl_alarm(0);
        $handler = static function (): void {
        };
        pcntl_signal(SIGALRM, $handler);
        pcntl_async_signals(false);
        $timeLimit = new TestTimeLimit();

        try {
            $timeLimit->arm('SettingsTest');
            self::assertTrue(pcntl_async_signals());
            $timeLimit->arm('SettingsTestAgain');
            $timeLimit->disarm();

            self::assertSame(0, pcntl_alarm(0));
            self::assertSame($handler, pcntl_signal_get_handler(SIGALRM));
            self::assertFalse(pcntl_async_signals());
            $timeLimit->disarm();
            self::assertSame($handler, pcntl_signal_get_handler(SIGALRM));
        } finally {
            $timeLimit->disarm();
            $this->restoreEnclosingAlarm();
        }
    }

    /** An unused alarm must not cancel the extension's enclosing alarm. */
    public function testDisarmingWithoutArmingIsSafe(): void
    {
        try {
            $timeLimit = new TestTimeLimit();
            $timeLimit->disarm();
            self::assertSame($this->originalHandler, pcntl_signal_get_handler(SIGALRM));
            self::assertSame($this->originalAsync, pcntl_async_signals());
            self::assertGreaterThan(0, pcntl_alarm(0));
        } finally {
            $this->restoreEnclosingAlarm();
        }
    }

    /** Both irreversible actions can be observed without exiting the unit-test process. */
    public function testSecondAlarmUsesTheInjectedDiagnosticAndExitActions(): void
    {
        $messages = [];
        $statuses = [];
        $timeLimit = new TestTimeLimit(
            static function (string $message) use (&$messages): void {
                $messages[] = $message;
            },
            static function (int $status) use (&$statuses): void {
                $statuses[] = $status;
            },
        );

        try {
            $timeLimit->arm('InjectedTest');
            try {
                posix_kill(posix_getpid(), SIGALRM);
            } catch (TestTimeLimitExceeded) {
            }
            posix_kill(posix_getpid(), SIGALRM);
            self::assertSame([2], $statuses);
            self::assertStringStartsWith(TestTimeLimit::abortedMessage('InjectedTest') . PHP_EOL, $messages[1]);
            self::assertMatchesRegularExpression('/' . preg_quote(__FILE__, '/') . ':\d+/', $messages[1]);
        } finally {
            $timeLimit->disarm();
            $this->restoreEnclosingAlarm();
        }
    }

    /** Parent-delivered signals prove the real exit status and interrupted child's stack. */
    public function testSecondAlarmAbortsTheChildThatSwallowsTheFirstException(): void
    {
        $result = $this->runFixture(<<<'PHP'
use Hilos\Testing\TimeLimit\TestTimeLimit;
use Hilos\Testing\TimeLimit\TestTimeLimitExceeded;

$timeLimit = new TestTimeLimit();
$timeLimit->arm('StubbornTest');
try {
    fwrite(STDOUT, "\nready\n");
    while (true) {
    }
} catch (TestTimeLimitExceeded) {
    fwrite(STDOUT, "\ncaught\n");
}
while (true) {
}
PHP, ['ready', 'caught']);

        self::assertSame(2, $result['status'], $result['stderr']);
        self::assertStringContainsString(TestTimeLimit::exceededMessage('StubbornTest'), $result['stderr']);
        self::assertStringContainsString(TestTimeLimit::abortedMessage('StubbornTest'), $result['stderr']);
        self::assertMatchesRegularExpression('/TimeLimitFixtureTest\.php:\d+/', $result['stderr']);
    }

    /** The class alarm must already be armed while setUpBeforeClass is still running. */
    public function testClassSetupIsCoveredBeforeItsHookReturns(): void
    {
        $result = $this->runFixture(<<<'PHP'
use PHPUnit\Framework\TestCase;

final class TimeLimitFixtureTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        fwrite(STDOUT, "\nready\n");
        while (true) {
        }
    }

    public function testBody(): void
    {
        self::fail('Class setup did not time out');
    }
}
PHP, ['ready'], true);

        self::assertNotSame(0, $result['status'], $result['stdout']);
        self::assertStringContainsString(TestTimeLimit::exceededMessage('TimeLimitFixtureTest'), $result['stderr']);
        self::assertStringContainsString(TestTimeLimitExceeded::class, $result['stdout']);
    }

    /** A skipped setup must leave a live class alarm over the tearDown hook. */
    public function testSkippedSetupDoesNotLeaveTearDownWithoutAnAlarm(): void
    {
        $result = $this->runFixture(<<<'PHP'
use PHPUnit\Framework\TestCase;

final class TimeLimitFixtureTest extends TestCase
{
    protected function setUp(): void
    {
        self::markTestSkipped('The body is intentionally skipped');
    }

    protected function tearDown(): void
    {
        fwrite(STDOUT, "\nready\n");
        while (true) {
        }
    }

    public function testBody(): void
    {
        self::fail('Skipped body ran');
    }
}
PHP, ['ready'], true);

        self::assertNotSame(0, $result['status'], $result['stdout']);
        self::assertStringContainsString(TestTimeLimit::exceededMessage('TimeLimitFixtureTest'), $result['stderr']);
    }

    /** PHPUnit must reject the timeout even with the broadest expected exception type. */
    public function testExpectedThrowableCannotAcceptTheTimeout(): void
    {
        $result = $this->runFixture(<<<'PHP'
use PHPUnit\Framework\TestCase;

final class TimeLimitFixtureTest extends TestCase
{
    public function testBody(): void
    {
        $this->expectException(Throwable::class);
        fwrite(STDOUT, "\nready\n");
        while (true) {
        }
    }
}
PHP, ['ready'], true);

        self::assertNotSame(0, $result['status'], $result['stdout']);
        self::assertStringContainsString(TestTimeLimitExceeded::class, $result['stdout']);
        self::assertStringContainsString('Errors: 1', $result['stdout']);
        self::assertStringNotContainsString('PHPUnit error:', $result['stdout']);
    }

    /** Catching the exception and passing the test must still produce a PHPUnit error. */
    public function testSwallowedTimeoutCannotLeaveTheRunGreen(): void
    {
        $result = $this->runFixture(<<<'PHP'
use Hilos\Testing\TimeLimit\TestTimeLimitExceeded;
use PHPUnit\Framework\TestCase;

final class TimeLimitFixtureTest extends TestCase
{
    public function testBody(): void
    {
        try {
            fwrite(STDOUT, "\nready\n");
            while (true) {
            }
        } catch (TestTimeLimitExceeded) {
        }
        self::assertTrue(true);
    }
}
PHP, ['ready'], true);

        self::assertNotSame(0, $result['status'], $result['stdout']);
        self::assertStringContainsString('There was 1 PHPUnit error:', $result['stdout']);
        self::assertStringContainsString(TestTimeLimit::exceededMessage('TimeLimitFixtureTest::testBody'), $result['stdout']);
    }

    /** Restores the signal state captured before the current test touched its alarm. */
    private function restoreEnclosingAlarm(): void
    {
        pcntl_alarm(0);
        pcntl_signal(SIGALRM, $this->originalHandler);
        pcntl_async_signals($this->originalAsync);
        pcntl_alarm($this->originalAlarm);
    }

    /**
     * E2e cannot observe PHPUnit's process-local alarm or survive its exit; no external service needs a stand gateway.
     * Signals follow explicit child readiness lines, never elapsed time.
     *
     * @param string $body Child script or test class
     * @param list<string> $signalMarkers Lines after which the parent sends SIGALRM
     * @param bool $phpunit Whether the child runs a real PHPUnit lifecycle
     * @return array{status: int, stdout: string, stderr: string} Child verdict and diagnostics
     */
    private function runFixture(string $body, array $signalMarkers, bool $phpunit = false): array
    {
        $directory = tempnam(sys_get_temp_dir(), 'hilos-time-limit-');
        self::assertNotFalse($directory);
        unlink($directory);
        mkdir($directory);
        $file = $directory . '/TimeLimitFixtureTest.php';
        $autoload = dirname(__DIR__, 4) . '/vendor/autoload.php';
        $process = null;
        $pipes = [];

        try {
            file_put_contents($file, "<?php\ndeclare(strict_types=1);\nrequire " . var_export($autoload, true) . ";\n" . $body);
            $command = $phpunit
                ? [
                    PHP_BINARY,
                    dirname(__DIR__, 4) . '/vendor/bin/phpunit',
                    '--no-configuration',
                    '--extension',
                    TestTimeLimitExtension::class,
                    '--fail-on-phpunit-warning',
                    '--colors=never',
                    $file,
                ]
                : [PHP_BINARY, $file];
            $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            fclose($pipes[0]);
            unset($pipes[0]);
            $stdout = '';
            foreach ($signalMarkers as $marker) {
                do {
                    $line = fgets($pipes[1]);
                    self::assertNotFalse($line, "Child exited before {$marker}");
                    $stdout .= $line;
                } while (trim($line) !== $marker);
                self::assertTrue(proc_terminate($process, SIGALRM));
            }
            $stdout .= stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $pipes = [];
            $status = proc_close($process);
            $process = null;

            return ['status' => $status, 'stdout' => $stdout, 'stderr' => $stderr];
        } finally {
            if (is_resource($process)) {
                proc_terminate($process, SIGKILL);
                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }
                proc_close($process);
            }
            unlink($file);
            rmdir($directory);
        }
    }
}

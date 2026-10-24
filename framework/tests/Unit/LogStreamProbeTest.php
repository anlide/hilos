<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use ErrorException;
use Hilos\Core\Daemon\BaseManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use PHPUnit\Framework\TestCase;

/**
 * A signal can interrupt a warning-suppressed read in the master (HIL-1651).
 * Dispatch the probe's real registered callback under that mask in this process:
 * no timing window, child process, substituted warning or copied handler body.
 */
final class LogStreamProbeTest extends TestCase
{
    /**
     * PHPUnit's warning handler is replaced by the real manager handler for this case.
     *
     * @param string $script Bootstrap whose probe callback is installed
     * @param string $path Missing path identifying this process's warning
     * @param bool $suppressed Whether dispatch interrupts an @ expression
     * @param bool $propagate Whether the manager's ErrorException leaves the callback
     */
    #[WithoutErrorHandler]
    #[DataProvider('warningCases')]
    public function testWarningSurvivesTheInterruptedMaskAndRestoresProcessState(
        string $script,
        string $path,
        bool $suppressed,
        bool $propagate,
    ): void {
        $originalArgv = $_SERVER['argv'];
        $originalReporting = error_reporting();
        $originalAsync = pcntl_async_signals();
        $originalUsr1 = pcntl_signal_get_handler(SIGUSR1);
        $originalUsr2 = pcntl_signal_get_handler(SIGUSR2);
        $manager = new LogStreamProbeTestManager();
        $warnings = [];
        $handler = static function (int $severity, string $message, string $file, int $line) use (
            $manager,
            $propagate,
            &$warnings,
        ): bool {
            $warnings[] = [$severity, $message, $file, error_reporting()];
            try {
                $manager->errorHandler($severity, $message, $file, $line);
            } catch (ErrorException $exception) {
                if ($propagate) {
                    throw $exception;
                }
            }
            // Consume even a masked warning so red-before does not pollute PHPUnit's output.
            return true;
        };
        $originalHandler = set_error_handler($handler);

        try {
            $_SERVER['argv'] = [$script];
            $this->installProbe();
            self::assertTrue(pcntl_async_signals());

            // Queue first, then dispatch exactly inside the chosen expression's mask.
            pcntl_async_signals(false);
            self::assertTrue(posix_kill(posix_getpid(), SIGUSR2));
            $outsideReporting = E_ALL & ~E_NOTICE;
            error_reporting($outsideReporting);
            $entryReporting = null;
            $returnedReporting = null;
            $caught = null;
            $dispatch = static function () use (&$entryReporting, &$returnedReporting, &$caught): void {
                $entryReporting = error_reporting();
                try {
                    pcntl_signal_dispatch();
                } catch (ErrorException $exception) {
                    $caught = $exception;
                } finally {
                    $returnedReporting = error_reporting();
                }
            };

            if ($suppressed) {
                @$dispatch();
            } else {
                $dispatch();
            }

            self::assertSame($entryReporting, $returnedReporting, 'Restore the mask inside the interrupted expression.');
            self::assertSame($outsideReporting, error_reporting(), 'Restore the mask outside the @ expression.');
            self::assertSame($suppressed ? 0 : E_WARNING, $entryReporting & E_WARNING);
            self::assertCount(1, $warnings, 'Raise exactly one real PHP warning.');
            self::assertSame(E_WARNING, $warnings[0][0]);
            self::assertStringStartsWith('fopen(' . $path . '):', $warnings[0][1]);
            self::assertSame(realpath(__DIR__ . '/../../../scripts/log-stream-probe.php'), $warnings[0][2]);
            self::assertSame(E_WARNING, $warnings[0][3] & E_WARNING, 'The intentional probe warning must be active.');
            self::assertSame(1, $manager->errors);
            self::assertCount(1, $manager->messages);
            self::assertMatchesRegularExpression(
                '/^WARNING in log-stream-probe\.php:\d+ - fopen\(' . preg_quote($path, '/') . '\):/',
                $manager->messages[0],
            );
            if ($propagate) {
                self::assertInstanceOf(ErrorException::class, $caught);
                self::assertSame(E_WARNING, $caught->getSeverity());
                self::assertSame($warnings[0][1], $caught->getMessage());
            } else {
                self::assertNull($caught);
            }
            $currentHandler = set_error_handler($handler);
            try {
                self::assertSame($handler, $currentHandler, 'The probe must preserve the PHP handler.');
            } finally {
                restore_error_handler();
            }
        } finally {
            restore_error_handler();
            error_reporting($originalReporting);
            $_SERVER['argv'] = $originalArgv;
            pcntl_signal(SIGUSR1, $originalUsr1);
            pcntl_signal(SIGUSR2, $originalUsr2);
            pcntl_async_signals($originalAsync);
        }

        self::assertSame($originalReporting, error_reporting());
        self::assertSame($originalArgv, $_SERVER['argv']);
        self::assertSame($originalUsr1, pcntl_signal_get_handler(SIGUSR1));
        self::assertSame($originalUsr2, pcntl_signal_get_handler(SIGUSR2));
        self::assertSame($originalAsync, pcntl_async_signals());
        $restoredHandler = set_error_handler($handler);
        try {
            self::assertSame($originalHandler, $restoredHandler);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @return iterable<string, array{string, string, bool, bool}> Process, mask and callback exit cases
     */
    public static function warningCases(): iterable
    {
        foreach (['daemon' => '/nonexistent/hilos-log-stream-probe-warning', 'worker' => '/nonexistent/hilos-log-stream-probe-worker-warning']
            as $process => $path) {
            foreach ([false, true] as $suppressed) {
                foreach ([false, true] as $propagate) {
                    yield $process . ($suppressed ? ' suppressed' : ' active') . ($propagate ? ' throws' : ' returns')
                        => [$process . '.php', $path, $suppressed, $propagate];
                }
            }
        }
    }

    /** Reloading the standalone probe changes its captured bootstrap; its constants stay defined. */
    private function installProbe(): void
    {
        set_error_handler(static function (int $severity, string $message): bool {
            self::assertSame(E_WARNING, $severity);
            self::assertMatchesRegularExpression('/^Constant LOG_STREAM_PROBE_[A-Z_]+ already defined$/', $message);
            return true;
        });
        try {
            require __DIR__ . '/../../../scripts/log-stream-probe.php';
        } finally {
            restore_error_handler();
        }
    }
}

/** The real BaseManager warning conversion, with only its logging and lifecycle sinks replaced. */
final class LogStreamProbeTestManager extends BaseManager
{
    /** @var list<string> Lines received by the actual manager log hook */
    public array $messages = [];

    /** Number of active warnings that reached the lifecycle hook. */
    public int $errors = 0;

    /** @return string Fixture process name */
    protected function getManagerName(): string
    {
        return 'log-stream-probe-test';
    }

    /** @param string $message Line composed by the real error handler */
    protected function logError(string $message): void
    {
        $this->messages[] = $message;
    }

    /** @param string $message Unused exception line */
    protected function logException(string $message): void
    {
        self::unexpectedHook();
    }

    /** @param string $message Unused fatal line */
    protected function logShutdown(string $message): void
    {
        self::unexpectedHook();
    }

    /** Count the manager's active-error notification without ending the test process. */
    protected function onError(): void
    {
        $this->errors++;
    }

    /** No uncaught-exception notification belongs to this warning test. */
    protected function onException(): void
    {
        self::unexpectedHook();
    }

    /** No fatal notification belongs to this warning test. */
    protected function onShutdown(): void
    {
        self::unexpectedHook();
    }

    /** No shutdown signal is sent by this test. */
    protected function onShutdownSignal(): void
    {
        self::unexpectedHook();
    }

    /** No restart signal is sent by this test. */
    protected function onRestartSignal(): void
    {
        self::unexpectedHook();
    }

    private static function unexpectedHook(): never
    {
        TestCase::fail('Only the warning log and onError hooks may run.');
    }
}

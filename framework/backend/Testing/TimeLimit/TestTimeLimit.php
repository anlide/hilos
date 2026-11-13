<?php

declare(strict_types=1);

namespace Hilos\Testing\TimeLimit;

use Closure;
use PHPUnit\Event\Code\Test;
use PHPUnit\Event\Code\Throwable;
use PHPUnit\Event\Facade;

/** Owns the OS alarm of this PHPUnit process; no other process reads its state. */
final class TestTimeLimit
{
    public const int SECONDS = 60;
    public const int GRACE_SECONDS = 10;

    private const int ABORT_EXIT_CODE = 2;

    private ?string $id = null;
    private ?string $exceededId = null;
    private mixed $previousHandler = SIG_DFL;
    private bool $previousAsync = false;
    private readonly Closure $writeStderr;
    private readonly Closure $abort;

    /**
     * @param Closure(string): void|null $writeStderr Diagnostic sink, STDERR by default
     * @param Closure(int): void|null $abort Run termination, exit by default
     */
    public function __construct(?Closure $writeStderr = null, ?Closure $abort = null)
    {
        $this->writeStderr = $writeStderr ?? static function (string $message): void {
            fwrite(STDERR, $message);
        };
        $this->abort = $abort ?? static function (int $status): void {
            exit($status);
        };
    }

    /**
     * @param string $id Test or class that exceeded the limit
     * @return string First-stage diagnostic shared with artifact readers
     */
    public static function exceededMessage(string $id): string
    {
        return sprintf('Test %s exceeded the time limit of %d seconds', $id, self::SECONDS);
    }

    /**
     * @param string $id Test or class that did not stop
     * @return string Run-abort diagnostic shared with artifact readers
     */
    public static function abortedMessage(string $id): string
    {
        return sprintf(
            'Test %s exceeded the time limit of %d seconds and did not stop within %d more seconds; the run is aborted',
            $id,
            self::SECONDS,
            self::GRACE_SECONDS,
        );
    }

    /**
     * @param string $id Test or class now covered by the alarm
     */
    public function arm(string $id): void
    {
        if ($this->id === null) {
            $this->previousHandler = pcntl_signal_get_handler(SIGALRM);
            $this->previousAsync = pcntl_async_signals();
            pcntl_signal(SIGALRM, $this->onAlarm(...));
        }

        $this->id = $id;
        $this->exceededId = null;
        pcntl_alarm(self::SECONDS);
        pcntl_async_signals(true);
    }

    /** Restores signal settings only when this instance owns the alarm. */
    public function disarm(): void
    {
        if ($this->id === null) {
            return;
        }

        pcntl_alarm(0);
        pcntl_signal(SIGALRM, $this->previousHandler);
        pcntl_async_signals($this->previousAsync);
        $this->id = null;
        $this->exceededId = null;
    }

    /**
     * Keeps a swallowed timeout red, then covers cleanup with the class alarm.
     *
     * @param Test $test Test whose outcome was announced
     * @param Throwable|null $error Error outcome, if any
     */
    public function finishTest(Test $test, ?Throwable $error = null): void
    {
        if ($this->exceededId !== null && $error?->className() !== TestTimeLimitExceeded::class) {
            Facade::emitter()->testTriggeredPhpunitError($test, self::exceededMessage($this->exceededId));
        }

        $this->arm($test->isTestMethod() ? $test->className() : $test->id());
    }

    /**
     * @throws TestTimeLimitExceeded When the first alarm interrupts the test
     */
    private function onAlarm(): void
    {
        if ($this->id === null) {
            return;
        }

        if ($this->exceededId !== null) {
            $message = self::abortedMessage($this->exceededId) . PHP_EOL;
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
                if (isset($frame['file'], $frame['line'])) {
                    $message .= $frame['file'] . ':' . $frame['line'] . PHP_EOL;
                }
            }
            ($this->writeStderr)($message);
            ($this->abort)(self::ABORT_EXIT_CODE);
            return;
        }

        $this->exceededId = $this->id;
        pcntl_alarm(self::GRACE_SECONDS);
        ($this->writeStderr)(self::exceededMessage($this->exceededId) . PHP_EOL);
        throw new TestTimeLimitExceeded(self::exceededMessage($this->exceededId));
    }
}

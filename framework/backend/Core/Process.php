<?php

declare(strict_types=1);

namespace Hilos\Core;

use Hilos\Core\Exception\Process\CouldNotStartException;
use Hilos\Core\Exception\Process\FailedToClosePipeException;
use Hilos\Core\Exception\Process\FailedToGetStatusException;
use Hilos\Core\Exception\Process\FailedToReadStdOutException;
use Hilos\Core\Exception\Process\FailedToSetNonBlockingException;
use Hilos\Core\Exception\Process\FailedToSetStdErrException;
use Hilos\Core\Exception\Process\FailedToTerminateProcessException;
use Hilos\Core\Exception\Process\FailedToWriteStdInException;
use Throwable;

/**
 * Wrapper for proc_open to run child processes with non-blocking I/O.
 *
 * Note: non-blocking pipes (stream_set_blocking($pipe, false)) are only honoured
 * on POSIX platforms. PHP's proc_open pipes are always blocking on Windows, so the
 * non-blocking guarantees of this class hold on Linux/macOS only.
 */
class Process
{
    // Process status keys (subset of proc_get_status() keys)
    public const string STATUS_RUNNING = 'running';
    public const string STATUS_STOPPED = 'stopped';
    public const string STATUS_SIGNALED = 'signaled';
    public const string STATUS_EXIT_CODE = 'exitcode';

    // Process descriptor types
    public const string DESCRIPTOR_PIPE = 'pipe';
    public const string DESCRIPTOR_FILE = 'file';
    public const string DESCRIPTOR_PTY = 'pty';
    // Hands the child a descriptor this process already holds, named by its number: [redirect, 1]
    // gives the child our own stdout. The only way into a container log, whose descriptor is an
    // anonymous pipe that no path reopens (HIL-843).
    public const string DESCRIPTOR_REDIRECT = 'redirect';

    // Pipe modes
    public const string PIPE_READ = 'r';
    public const string PIPE_WRITE = 'w';
    public const string PIPE_APPEND = 'a';

    /** @var resource Child process resource */
    private $process;

    /** @var array<int, resource> Open pipe resources for stdin, stdout and stderr */
    private array $pipes = [];

    /** @var list<string|int> Descriptor for stdin (e.g. [pipe, r]) */
    private array $stdinDescriptor;

    /** @var list<string|int> Descriptor for stdout (e.g. [pipe, w], or [redirect, 1]) */
    private array $stdoutDescriptor;

    /** @var list<string|int> Descriptor for stderr (e.g. [pipe, w], or [redirect, 2]) */
    private array $stderrDescriptor;

    /** @var string Unread stdout content */
    private string $unreadStdOut = '';

    /** @var string Unread stderr content */
    private string $unreadStdErr = '';

    /** @var string Buffered stdin content not yet written to the child (non-blocking back-pressure) */
    private string $unwrittenStdIn = '';

    /** @var ?float Time when halt() should be called (microtime) or null if not set */
    private ?float $haltTime = null;

    /** @var bool Whether the process resource has already been closed via proc_close() */
    private bool $closed = false;

    /** @var ?int Captured process exit code, or null if not yet known */
    private ?int $exitCode = null;

    /** @var array<string, mixed> Last observed status; returned after the process is closed */
    private array $lastStatus = [self::STATUS_RUNNING => false];

    /**
     * Create and start child process with non-blocking streams.
     *
     * @param string $command Command to execute (e.g. path to Python script); may contain
     *                        quoted, shell-like arguments which are split into argv tokens
     * @param list<string> $params Parameters passed verbatim as argv entries (no shell, no escaping)
     * @param ?string $cwd Working directory for the process
     * @param list<string|int> $stdIn Standard input descriptor (e.g. [pipe, r])
     * @param list<string|int> $stdOut Standard output descriptor (e.g. [pipe, w], or [redirect, 1])
     * @param list<string|int> $stdErr Standard error descriptor (e.g. [pipe, w], or [redirect, 2])
     *
     * @throws CouldNotStartException If process cannot be started
     * @throws FailedToSetNonBlockingException If non-blocking mode cannot be set
     */
    public function __construct(
        string $command,
        array $params = [],
        ?string $cwd = null,
        array $stdIn = [self::DESCRIPTOR_PIPE, self::PIPE_READ],
        array $stdOut = [self::DESCRIPTOR_PIPE, self::PIPE_WRITE],
        array $stdErr = [self::DESCRIPTOR_PIPE, self::PIPE_WRITE],
    ) {
        $this->stdinDescriptor = $stdIn;
        $this->stdoutDescriptor = $stdOut;
        $this->stderrDescriptor = $stdErr;

        $descriptorSpec = [
            0 => $this->stdinDescriptor,
            1 => $this->stdoutDescriptor,
            2 => $this->stderrDescriptor,
        ];

        // proc_open receives an argv array, so no shell is involved: params are passed
        // verbatim and must not be shell-escaped. Only $command is tokenised, to keep
        // support for shell-like command strings (e.g. "php -d memory_limit=512M script.php").
        $fullCommand = array_merge(
            $this->convertCommandStringToProcOpenArray($command),
            array_values($params),
        );

        $this->process = proc_open($fullCommand, $descriptorSpec, $this->pipes, $cwd);

        if (!is_resource($this->process)) {
            throw new CouldNotStartException('Could not start the process.');
        }

        if (isset($this->pipes[0]) && is_resource($this->pipes[0])) {
            if (!stream_set_blocking($this->pipes[0], false)) {
                throw new FailedToSetNonBlockingException('Failed to set non-blocking mode stdin on streams.');
            }
        }
        if (isset($this->pipes[1]) && is_resource($this->pipes[1])) {
            if (!stream_set_blocking($this->pipes[1], false)) {
                throw new FailedToSetNonBlockingException('Failed to set non-blocking mode stdout on streams.');
            }
        }
        if (isset($this->pipes[2]) && is_resource($this->pipes[2])) {
            if (!stream_set_blocking($this->pipes[2], false)) {
                throw new FailedToSetNonBlockingException('Failed to set non-blocking mode stderr on streams.');
            }
        }
    }

    /**
     * Convert shell-like command string into an argv array suitable for proc_open().
     *
     * Example:
     *   "php -d memory_limit=512M /app/daemon.php 'arg with space'" =>
     *   ['php', '-d', 'memory_limit=512M', '/app/daemon.php', 'arg with space']
     *
     * @param string $command Shell-like command string with quoted args
     * @return list<string> argv array for proc_open
     */
    private function convertCommandStringToProcOpenArray(string $command): array
    {
        $pattern = '/
            "([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"  # double-quoted
            |
            \'([^\'\\\\]*(?:\\\\.[^\'\\\\]*)*)\' # single-quoted
            |
            ([^\\s"\']+)                        # unquoted
        /x';

        $args = [];
        if (preg_match_all($pattern, $command, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                if ($m[1] !== '') {
                    $args[] = stripcslashes($m[1]);
                } elseif ($m[2] !== '') {
                    $args[] = stripcslashes($m[2]);
                } else {
                    $args[] = $m[3];
                }
            }
        }

        return $args;
    }

    /**
     * Check process state, flush buffered stdin and read new data from stdout and stderr.
     *
     * @throws FailedToGetStatusException If process status cannot be retrieved
     * @throws FailedToReadStdOutException If stdout data cannot be read
     * @throws FailedToSetStdErrException If stderr data cannot be read
     * @throws FailedToWriteStdInException If buffered stdin data cannot be written
     * @throws FailedToTerminateProcessException If process cannot be terminated
     * @throws FailedToClosePipeException If pipes cannot be closed
     */
    public function tick(): void
    {
        $status = $this->getStatus();

        $this->flushStdIn();

        if (isset($this->pipes[1]) && is_resource($this->pipes[1])) {
            $stdoutContent = stream_get_contents($this->pipes[1]);
            if ($stdoutContent === false) {
                throw new FailedToReadStdOutException('Failed to read from stdout.');
            }
            $this->unreadStdOut .= $stdoutContent;
        }

        if (isset($this->pipes[2]) && is_resource($this->pipes[2])) {
            $stderrContent = stream_get_contents($this->pipes[2]);
            if ($stderrContent === false) {
                throw new FailedToSetStdErrException('Failed to read from stderr.');
            }
            $this->unreadStdErr .= $stderrContent;
        }

        // Check if halt time reached
        if ($this->haltTime !== null && microtime(true) >= $this->haltTime) {
            $this->halt(); // Force kill - timeout exceeded
            return;
        }

        if (!$status[self::STATUS_RUNNING]) {
            $this->halt(); // Ensure the process is terminated if not running
        }
    }

    /**
     * Get process status.
     *
     * Once the process has been closed, the last observed status is returned with
     * 'running' forced to false, so callers can keep polling safely.
     *
     * @return array<string, mixed> Array with process status, including 'running', 'exitcode', and other keys.
     * @throws FailedToGetStatusException If process status cannot be retrieved
     */
    public function getStatus(): array
    {
        if ($this->closed) {
            return $this->lastStatus;
        }

        /** @var array|false $status */
        $status = proc_get_status($this->process);
        if ($status === false) {
            throw new FailedToGetStatusException('Failed to get process status.');
        }

        // proc_get_status() reports the real exit code only on the first call after the
        // process terminates; subsequent calls return -1. Capture it as soon as we see it.
        if ($this->exitCode === null
            && !$status[self::STATUS_RUNNING]
            && isset($status[self::STATUS_EXIT_CODE])
            && $status[self::STATUS_EXIT_CODE] !== -1
        ) {
            $this->exitCode = $status[self::STATUS_EXIT_CODE];
        }

        $this->lastStatus = $status;
        return $status;
    }

    /**
     * Get the process exit code.
     *
     * @return ?int Exit code, or null if the process has not terminated or it could not be determined
     */
    public function getExitCode(): ?int
    {
        return $this->exitCode;
    }

    /**
     * Stop process safely.
     *
     * @param ?float $shutdownTimeout Timeout in seconds before force kill (null = no timeout, equivalent to old behavior)
     * @throws FailedToGetStatusException If process status cannot be retrieved
     * @throws FailedToTerminateProcessException If process cannot be terminated
     */
    public function stop(?float $shutdownTimeout = null): void
    {
        $status = $this->getStatus();
        if ($status[self::STATUS_RUNNING]) {
            if (!proc_terminate($this->process)) {
                throw new FailedToTerminateProcessException('Failed to terminate the process.');
            }

            // Set halt time if timeout specified
            if ($shutdownTimeout !== null && $shutdownTimeout > 0) {
                $this->haltTime = microtime(true) + $shutdownTimeout;
            }
        }
    }

    /**
     * Force terminate process, drain remaining output and release the process resource.
     *
     * Idempotent: once the process has been closed, subsequent calls are no-ops.
     *
     * @throws FailedToGetStatusException If process status cannot be retrieved
     * @throws FailedToTerminateProcessException If process cannot be forcefully terminated
     * @throws FailedToClosePipeException If pipes cannot be closed
     */
    public function halt(): void
    {
        if (!$this->closed) {
            $status = $this->getStatus();
            if ($status[self::STATUS_RUNNING]) {
                if (!proc_terminate($this->process, 9)) { // Send SIGKILL
                    throw new FailedToTerminateProcessException('Failed to forcefully terminate the process.');
                }
            }

            // Drain any output still buffered in the pipes before closing them, so the
            // final burst a process emits right before exiting is not lost.
            $this->drainPipes();
            $this->closePipes();

            // Release the process handle. proc_close() blocks until the child is reaped and
            // returns its exit status, which is our last chance to learn the exit code for
            // a force-killed process.
            $exitCode = proc_close($this->process);
            if ($this->exitCode === null && $exitCode !== -1) {
                $this->exitCode = $exitCode;
            }

            $this->closed = true;
            $this->lastStatus[self::STATUS_RUNNING] = false;
        }

        // Clear halt time
        $this->haltTime = null;
    }

    /**
     * Queue data to be sent to the child process stdin and attempt to flush it.
     *
     * On non-blocking pipes a single write may accept only part of the data; the
     * remainder is buffered and retried on the next flush (e.g. during tick()).
     *
     * @param string $input Data to send to stdin
     * @throws FailedToWriteStdInException If data cannot be written to stdin
     */
    public function sendInput(string $input): void
    {
        $this->unwrittenStdIn .= $input;
        $this->flushStdIn();
    }

    /**
     * Get unread stdout content.
     *
     * @return string Stdout content
     */
    public function getStdOut(): string
    {
        $unreadStdOut = $this->unreadStdOut;
        $this->unreadStdOut = '';
        return $unreadStdOut;
    }

    /**
     * Get unread stderr content.
     *
     * @return string Stderr content
     */
    public function getStdErr(): string
    {
        $unreadStdErr = $this->unreadStdErr;
        $this->unreadStdErr = '';
        return $unreadStdErr;
    }

    /**
     * Class destructor. Ensures process termination and resource cleanup.
     *
     * Failures during teardown are swallowed: throwing from a destructor would escalate
     * to a fatal error during shutdown.
     */
    public function __destruct()
    {
        try {
            $this->halt();
        } catch (Throwable) {
            // Best-effort cleanup; nothing useful can be done if teardown fails here.
        }
    }

    /**
     * Write as much buffered stdin data as the pipe currently accepts, keeping the rest.
     *
     * @throws FailedToWriteStdInException If data cannot be written to stdin
     */
    private function flushStdIn(): void
    {
        if ($this->unwrittenStdIn === '') {
            return;
        }
        if (!isset($this->pipes[0]) || !is_resource($this->pipes[0])) {
            throw new FailedToWriteStdInException('Stdin pipe is not available.');
        }

        $written = fwrite($this->pipes[0], $this->unwrittenStdIn);
        if ($written === false) {
            throw new FailedToWriteStdInException('Failed to write to stdin.');
        }

        $this->unwrittenStdIn = substr($this->unwrittenStdIn, $written);
    }

    /**
     * Read any remaining buffered output from stdout and stderr (best effort).
     */
    private function drainPipes(): void
    {
        if (isset($this->pipes[1]) && is_resource($this->pipes[1])) {
            $stdoutContent = stream_get_contents($this->pipes[1]);
            if ($stdoutContent !== false) {
                $this->unreadStdOut .= $stdoutContent;
            }
        }
        if (isset($this->pipes[2]) && is_resource($this->pipes[2])) {
            $stderrContent = stream_get_contents($this->pipes[2]);
            if ($stderrContent !== false) {
                $this->unreadStdErr .= $stderrContent;
            }
        }
    }

    /**
     * Close all open stream descriptors.
     *
     * @throws FailedToClosePipeException If any of the streams cannot be closed
     */
    private function closePipes(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe) && fclose($pipe) === false) {
                throw new FailedToClosePipeException('Failed to close pipe.');
            }
        }
    }
}

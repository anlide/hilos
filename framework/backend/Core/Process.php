<?php

declare(strict_types=1);

namespace Hilos\Core;

use Hilos\Core\Exception\Process\CouldNotStartException;
use Hilos\Core\Exception\Process\FailedToClosePipeException;
use Hilos\Core\Exception\Process\FailedToGetStatusException;
use Hilos\Core\Exception\Process\FailedToReadStdOutException;
use Hilos\Core\Exception\Process\FailedToSetNonBlockingException;
use Hilos\Core\Exception\Process\FailedToSetStdErrException;
use Hilos\Core\Exception\Process\FailedToTerminateProcessExceptionException;
use Hilos\Core\Exception\Process\FailedToWriteStdInException;

class Process
{
    // Process status constants
    public const string STATUS_RUNNING = 'running';
    public const string STATUS_STOPPED = 'stopped';
    public const string STATUS_SIGNALED = 'signaled';
    public const string STATUS_EXITED = 'exited';

    // Process descriptor types
    public const string DESCRIPTOR_PIPE = 'pipe';
    public const string DESCRIPTOR_FILE = 'file';
    public const string DESCRIPTOR_PTY = 'pty';

    // Pipe modes
    public const string PIPE_READ = 'r';
    public const string PIPE_WRITE = 'w';
    public const string PIPE_APPEND = 'a';

    /** @var resource Child process resource */
    private $process;

    /** @var array<int, resource|false> Descriptors for stdin, stdout and stderr */
    private array $pipes = [];

    /** @var array<string, string> Descriptor for stdin */
    private array $stdinDescriptor;

    /** @var array<string, string> Descriptor for stdout */
    private array $stdoutDescriptor;

    /** @var array<string, string> Descriptor for stderr */
    private array $stderrDescriptor;

    /** @var string Unread stdout content */
    private string $unreadStdOut = '';

    /** @var string Unread stderr content */
    private string $unreadStdErr = '';

    /** @var ?float Time when halt() should be called (microtime) or null if not set */
    private ?float $haltTime = null;

    /**
     * ProcessManager constructor.
     *
     * @param string $command Command to execute (e.g., path to Python script).
     * @param array<int, string> $params Array of parameters to be passed to the command.
     * - Each element of the `params` array will be escaped using `escapeshellarg` to protect against command injection.
     * - Example: `new Process('python', ['script.py', 'param1', 'param2']);`
     * @param ?string $cwd Working directory for the process
     * @param array $stdIn Standard input descriptor
     * @param array $stdOut Standard output descriptor
     * @param array $stdErr Standard error descriptor
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

        $fullCommand = $this->convertCommandStringToProcOpenArray($command . ' ' . implode(' ', array_map('escapeshellarg', $params)));

        $this->process = proc_open($fullCommand, $descriptorSpec, $this->pipes, $cwd);

        if (!is_resource($this->process)) {
            throw new CouldNotStartException('Could not start the process.');
        }

        if (isset($this->pipes[0]) && ($this->pipes[0] !== null)) {
            if (!stream_set_blocking($this->pipes[0], false)) {
                throw new FailedToSetNonBlockingException('Failed to set non-blocking mode stdin on streams.');
            }
        }
        if (isset($this->pipes[1]) && ($this->pipes[1] !== null)) {
            if (!stream_set_blocking($this->pipes[1], false)) {
                throw new FailedToSetNonBlockingException('Failed to set non-blocking mode stdout on streams.');
            }
        }
        if (isset($this->pipes[2]) && ($this->pipes[2] !== null)) {
            if (!stream_set_blocking($this->pipes[2], false)) {
                throw new FailedToSetNonBlockingException('Failed to set non-blocking mode stderr on streams.');
            }
        }
    }

    /**
     * Convert shell-like command string into an argv array suitable for proc_open()
     *
     * Example:
     *   "php -d memory_limit=512M /app/daemon.php 'arg with space'" =>
     *   ['php', '-d', 'memory_limit=512M', '/app/daemon.php', 'arg with space']
     */
    function convertCommandStringToProcOpenArray(string $command): array
    {
        $pattern = '/
            (?:
                "([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"  # double-quoted
                |
                \'([^\'\\\\]*(?:\\\\.[^\'\\\\]*)*)\' # single-quoted
                |
                ([^\\s"\']+)                        # unquoted
            )
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
     * Check process state and read new data from stdout and stderr.
     *
     * @throws FailedToGetStatusException If process status cannot be retrieved
     * @throws FailedToReadStdOutException If stdout data cannot be read
     * @throws FailedToSetStdErrException If stderr data cannot be read
     * @throws FailedToTerminateProcessExceptionException If process cannot be terminated
     * @throws FailedToClosePipeException If pipes cannot be closed
     */
    public function tick(): void
    {
        $status = $this->getStatus();

        if (isset($this->pipes[1]) && ($this->pipes[1] !== null)) {
            $stdoutContent = stream_get_contents($this->pipes[1]);
            if ($stdoutContent === false) {
                throw new FailedToReadStdOutException('Failed to read from stdout.');
            }
            $this->unreadStdOut .= $stdoutContent;
        }

        if (isset($this->pipes[2]) && ($this->pipes[2] !== null)) {
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
     * @return array<string, mixed> Array with process status, including 'running', 'exitcode', and other keys.
     * @throws FailedToGetStatusException If process status cannot be retrieved
     */
    public function getStatus(): array
    {
        /** @var array|false $status */
        $status = proc_get_status($this->process);
        if ($status === false) {
            throw new FailedToGetStatusException('Failed to get process status.');
        }
        return $status;
    }

    /**
     * Stop process safely.
     *
     * @param ?float $shutdownTimeout Timeout in seconds before force kill (null = no timeout, equivalent to old behavior)
     * @throws FailedToGetStatusException If process status cannot be retrieved
     * @throws FailedToTerminateProcessExceptionException If process cannot be terminated
     */
    public function stop(?float $shutdownTimeout = null): void
    {
        $status = $this->getStatus();
        if ($status[self::STATUS_RUNNING]) {
            if (!proc_terminate($this->process)) {
                throw new FailedToTerminateProcessExceptionException('Failed to terminate the process.');
            }

            // Set halt time if timeout specified
            if ($shutdownTimeout !== null && $shutdownTimeout > 0) {
                $this->haltTime = microtime(true) + $shutdownTimeout;
            }
        }
    }

    /**
     * Force terminate process.
     *
     * @throws FailedToGetStatusException If process status cannot be retrieved
     * @throws FailedToTerminateProcessExceptionException If process cannot be forcefully terminated
     * @throws FailedToClosePipeException If pipes cannot be closed
     */
    public function halt(): void
    {
        $status = $this->getStatus();
        if ($status[self::STATUS_RUNNING]) {
            if (!proc_terminate($this->process, 9)) { // Send SIGKILL
                throw new FailedToTerminateProcessExceptionException('Failed to forcefully terminate the process.');
            }
        }
        $this->closePipes();

        // Clear halt time
        $this->haltTime = null;
    }

    /**
     * Send data to child process stdin.
     *
     * @param string $input Data to send to stdin
     * @throws FailedToWriteStdInException If data cannot be written to stdin
     */
    public function sendInput(string $input): void
    {
        if (fwrite($this->pipes[0], $input) === false) {
            throw new FailedToWriteStdInException('Failed to write to stdin.');
        }
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
     * @throws FailedToGetStatusException If process status cannot be retrieved
     * @throws FailedToTerminateProcessExceptionException If process cannot be terminated
     * @throws FailedToClosePipeException If pipes cannot be closed
     */
    public function __destruct()
    {
        $this->halt();
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

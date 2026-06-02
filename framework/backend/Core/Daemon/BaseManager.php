<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use ErrorException;
use Hilos\Constants\ErrorConstants;
use Hilos\Hilos;
use Hilos\Utils\Logger;
use Hilos\Core\Exception\MissingRequiredParameterException;
use Throwable;

/**
 * Base class for long-running process managers.
 *
 * Provides shared error handling, signal handling, loop timing, required
 * function validation, and logging hooks for concrete managers.
 */
abstract class BaseManager
{
    /** Whether the manager loop should stop. */
    protected bool $shouldExit = false;

    /**
     * Registers PHP error, exception, and shutdown handlers.
     *
     * Concrete managers provide the logging and lifecycle hooks used by those
     * handlers.
     */
    protected function setupErrorHandling(): void
    {
        set_error_handler([$this, 'errorHandler']);
        set_exception_handler([$this, 'exceptionHandler']);
        register_shutdown_function([$this, 'shutdownHandler']);
    }

    /**
     * Registers process signal handlers for graceful stop and restart.
     *
     * SIGTERM and SIGINT request shutdown. SIGHUP reloads environment
     * configuration and requests restart.
     */
    protected function setupSignalHandlers(): void
    {
        pcntl_signal(SIGTERM, [$this, 'handleShutdown']);
        pcntl_signal(SIGINT, [$this, 'handleShutdown']);
        pcntl_signal(SIGHUP, [$this, 'handleRestart']);
    }

    /**
     * Sleeps for the remaining time in a fixed-duration loop iteration.
     *
     * The method subtracts elapsed work time from the target duration and only
     * sleeps when the loop still has time left.
     *
     * @param float $loopStartTime Start time of the loop iteration in seconds
     * @param int $targetLoopTimeMicroseconds Target loop duration in microseconds
     */
    protected function sleepWithPreciseTiming(float $loopStartTime, int $targetLoopTimeMicroseconds = 10000): void
    {
        // Calculate time spent on useful work
        $usefulWorkTime = microtime(true) - $loopStartTime;
        $usefulWorkTimeMicroseconds = (int)($usefulWorkTime * 1000000);

        // Calculate remaining sleep time (target - time spent on work)
        $remainingSleepTime = $targetLoopTimeMicroseconds - $usefulWorkTimeMicroseconds;

        // Sleep only if we have time left
        if ($remainingSleepTime > 0) {
            usleep($remainingSleepTime);
        }
    }

    /**
     * Logs an active PHP error and converts it to ErrorException.
     *
     * Masked error levels are left to normal PHP handling by returning false.
     * Active errors are logged with a compact severity label before the manager
     * error hook is called.
     *
     * @param int $severity Error severity level (E_ERROR, E_WARNING, etc.)
     * @param string $message Error message
     * @param string $file File where error occurred
     * @param int $line Line number where error occurred
     * @return bool False when the error level is masked
     * @throws ErrorException When the active PHP error is converted to an exception
     */
    public function errorHandler(int $severity, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        // Map error levels to readable names
        $severityMap = [
            E_ERROR => 'FATAL',
            E_WARNING => 'WARNING',
            E_PARSE => 'FATAL',
            E_NOTICE => 'NOTICE',
            E_CORE_ERROR => 'FATAL',
            E_CORE_WARNING => 'WARNING',
            E_COMPILE_ERROR => 'FATAL',
            E_COMPILE_WARNING => 'WARNING',
            E_USER_ERROR => 'FATAL',
            E_USER_WARNING => 'WARNING',
            E_USER_NOTICE => 'NOTICE',
            E_RECOVERABLE_ERROR => 'FATAL',
            E_DEPRECATED => 'DEPRECATED',
            E_USER_DEPRECATED => 'DEPRECATED',
        ];

        $severityName = $severityMap[$severity] ?? 'UNKNOWN';

        // Format log message (timestamp will be added by logMessage)
        $logMessage = sprintf(
            "%s in %s:%d - %s",
            $severityName,
            basename($file),
            $line,
            substr($message, 0, ErrorConstants::ERROR_MESSAGE_MAX_LENGTH),
        );

        $this->logError($logMessage);
        $this->onError();

        // Throw exception for try-catch handling
        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    /**
     * Logs an uncaught exception and invokes the manager exception hook.
     *
     * Exception messages and stack traces are truncated to the framework log
     * limits before being passed to the concrete logger.
     *
     * @param Throwable $exception The uncaught exception
     */
    public function exceptionHandler(Throwable $exception): void
    {
        $logMessage = sprintf(
            "UNCAUGHT EXCEPTION: %s in %s:%d - %s\nStack trace:\n%s",
            get_class($exception),
            basename($exception->getFile()),
            $exception->getLine(),
            substr($exception->getMessage(), 0, ErrorConstants::ERROR_MESSAGE_MAX_LENGTH),
            substr($exception->getTraceAsString(), 0, ErrorConstants::ERROR_STACK_TRACE_MAX_LENGTH),
        );

        $this->logException($logMessage);
        $this->onException();
    }

    /**
     * Logs fatal shutdown errors that PHP exposes through error_get_last().
     *
     * Non-fatal shutdowns are ignored. Critical shutdowns invoke the concrete
     * manager shutdown hook after logging.
     */
    public function shutdownHandler(): void
    {
        $error = error_get_last();

        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $logMessage = sprintf(
                "FATAL SHUTDOWN: %s in %s:%d - %s",
                'FATAL',
                basename($error['file']),
                $error['line'],
                substr($error['message'], 0, ErrorConstants::ERROR_MESSAGE_MAX_LENGTH),
            );

            $this->logShutdown($logMessage);
            $this->onShutdown();
        }
    }

    /**
     * Handles SIGTERM and SIGINT by requesting manager shutdown.
     *
     * The concrete manager receives the shutdown-signal hook after the exit flag
     * is set.
     */
    public function handleShutdown(): void
    {
        Logger::info($this->getManagerName() . " received shutdown signal");
        $this->shouldExit = true;
        $this->onShutdownSignal();
    }

    /**
     * Handles SIGHUP by reloading environment configuration and exiting.
     *
     * The concrete manager receives the restart-signal hook after the exit flag
     * is set.
     */
    public function handleRestart(): void
    {
        Logger::info($this->getManagerName() . " received restart signal");

        // Reload environment configuration
        Hilos::reloadEnv();

        $this->shouldExit = true;
        $this->onRestartSignal();
    }

    /**
     * Ensures process-management functions required by daemon managers exist.
     *
     * @throws MissingRequiredParameterException When required functions are unavailable
     */
    protected function checkRequiredFunctions(): void
    {
        $requiredFunctions = [
            'pcntl_signal',
            'pcntl_signal_dispatch',
            'proc_open',
            'proc_get_status',
            'proc_terminate',
        ];

        $missingFunctions = [];
        foreach ($requiredFunctions as $function) {
            if (!function_exists($function)) {
                $missingFunctions[] = $function;
            }
        }

        if (!empty($missingFunctions)) {
            throw new MissingRequiredParameterException(
                'Required functions are not available: ' . implode(', ', $missingFunctions) .
                '. Please install PCNTL extension or run in CLI mode.'
            );
        }
    }

    /**
     * Returns the manager name used in shared log messages.
     *
     * @return string Human-readable manager name
     */
    abstract protected function getManagerName(): string;

    /**
     * Logs an error message for this manager.
     *
     * @param string $message Error message to log
     */
    abstract protected function logError(string $message): void;

    /**
     * Logs an uncaught exception message for this manager.
     *
     * Handles logging of exception messages with stack traces.
     * Implementation can vary between console logging, file logging,
     * or system logging.
     *
     * @param string $message Exception message to log
     */
    abstract protected function logException(string $message): void;

    /**
     * Logs a fatal shutdown message for this manager.
     *
     * Handles logging of shutdown messages for fatal errors.
     * Implementation can vary between console logging, file logging,
     * or system logging.
     *
     * @param string $message Shutdown message to log
     */
    abstract protected function logShutdown(string $message): void;

    /**
     * Handles a PHP error after it has been logged.
     *
     * Concrete managers can request shutdown, cleanup resources, or record
     * manager-specific state.
     */
    abstract protected function onError(): void;

    /**
     * Handles an uncaught exception after it has been logged.
     *
     * Concrete managers can request shutdown, cleanup resources, or record
     * manager-specific state.
     */
    abstract protected function onException(): void;

    /**
     * Handles a fatal shutdown after it has been logged.
     *
     * Concrete managers can release resources that are still safe to touch
     * during PHP shutdown.
     */
    abstract protected function onShutdown(): void;

    /**
     * Handles a process shutdown signal after the exit flag is set.
     *
     * Concrete managers can add signal-specific cleanup while the normal loop
     * shutdown path remains responsible for final resource release.
     */
    abstract protected function onShutdownSignal(): void;

    /**
     * Handles a restart signal after environment reload and exit flag update.
     *
     * Concrete managers can persist restart-specific state or notify peers.
     */
    abstract protected function onRestartSignal(): void;
}

<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

use ErrorException;
use Hilos\Utils\Constants\ErrorConstants;
use Hilos\Utils\Env;
use Hilos\Utils\Helpers\TimeHelper;
use RuntimeException;
use Throwable;

/**
 * BaseManager - Abstract base class for all managers
 *
 * Contains common logic for:
 * - Error and exception handling
 * - Signal management
 * - Precise timing control
 * - Function validation
 * - Logging infrastructure
 *
 * @package Hilos\Core\Daemon
 * @abstract
 */
abstract class BaseManager
{
    /** @var bool Flag for manager termination */
    protected bool $shouldExit = false;

    /**
     * Setup error handling for all managers
     *
     * Registers error, exception and shutdown handlers to ensure
     * proper error logging and graceful shutdown on critical errors.
     */
    protected function setupErrorHandling(): void
    {
        set_error_handler([$this, 'errorHandler']);
        set_exception_handler([$this, 'exceptionHandler']);
        register_shutdown_function([$this, 'shutdownHandler']);
    }

    /**
     * Setup signal handlers for graceful shutdown and restart
     *
     * Registers handlers for:
     * - SIGTERM/SIGINT: Graceful shutdown
     * - SIGHUP: Restart signal with environment reload
     */
    protected function setupSignalHandlers(): void
    {
        pcntl_signal(SIGTERM, [$this, 'handleShutdown']);
        pcntl_signal(SIGINT, [$this, 'handleShutdown']);
        pcntl_signal(SIGHUP, [$this, 'handleRestart']);
    }

    /**
     * Sleep with precise timing based on work duration
     *
     * Ensures consistent loop timing by sleeping for the remaining time
     * after useful work is completed. Target loop duration is configurable.
     *
     * @param float $loopStartTime Start time of the loop iteration in seconds
     * @param int $targetLoopTimeMicroseconds Target loop duration in microseconds (default 10000 = 10ms)
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
     * Error handler with comprehensive logging
     *
     * Handles PHP errors by logging them with detailed information
     * and triggering appropriate cleanup actions. Maps error levels
     * to human-readable names and truncates long messages.
     *
     * @param int $severity Error severity level (E_ERROR, E_WARNING, etc.)
     * @param string $message Error message
     * @param string $file File where error occurred
     * @param int $line Line number where error occurred
     * @return bool Always returns false to continue normal error handling
     * @throws ErrorException Always throws exception for proper error handling
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
     * Uncaught exception handler with detailed logging
     *
     * Handles uncaught exceptions by logging them with full stack trace
     * and triggering appropriate cleanup actions. Truncates long messages
     * and stack traces to prevent log overflow.
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
     * Shutdown handler for catching fatal errors
     *
     * Handles fatal errors that occur during shutdown by logging them
     * and triggering cleanup actions. Only processes critical error types
     * that cause script termination.
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
     * Shutdown signal handler (SIGTERM, SIGINT)
     *
     * Handles graceful shutdown signals by logging the event,
     * setting the exit flag and triggering cleanup actions.
     */
    public function handleShutdown(): void
    {
        $this->logMessage($this->getManagerName() . " received shutdown signal");
        $this->shouldExit = true;
        $this->onShutdownSignal();
    }

    /**
     * Restart signal handler (SIGHUP)
     *
     * Handles restart signals by logging the event,
     * reloading environment configuration,
     * setting the exit flag and triggering restart actions.
     */
    public function handleRestart(): void
    {
        $this->logMessage($this->getManagerName() . " received restart signal");
        
        // Reload environment configuration
        Env::reload();
        
        $this->shouldExit = true;
        $this->onRestartSignal();
    }

    /**
     * Check availability of required functions
     *
     * Validates that all necessary PHP functions are available for
     * process management and signal handling. Throws exception if
     * any required functions are missing.
     *
     * @throws RuntimeException If required functions are not available
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
            throw new RuntimeException(
                'Required functions are not available: ' . implode(', ', $missingFunctions) .
                '. Please install PCNTL extension or run in CLI mode.'
            );
        }
    }


    /**
     * Log message to console (basic implementation)
     *
     * Provides basic console logging with timestamp. Child classes
     * can override this method to implement custom logging behavior.
     *
     * @param string $message Message to log
     */
    protected function logMessage(string $message): void
    {
        echo "[" . TimeHelper::getTimestampWithMs() . "] " . $message . "\n";
    }

    // Abstract methods that must be implemented by child classes

    /**
     * Get manager name for logging purposes
     *
     * Returns a human-readable name for the manager instance,
     * used in log messages and error reporting.
     *
     * @return string Manager name (e.g., "Daemon", "Docker watchdog")
     */
    abstract protected function getManagerName(): string;

    /**
     * Log error message (implementation specific)
     *
     * Handles logging of error messages. Implementation can vary
     * between console logging, file logging, or system logging.
     *
     * @param string $message Error message to log
     */
    abstract protected function logError(string $message): void;

    /**
     * Log exception message (implementation specific)
     *
     * Handles logging of exception messages with stack traces.
     * Implementation can vary between console logging, file logging,
     * or system logging.
     *
     * @param string $message Exception message to log
     */
    abstract protected function logException(string $message): void;

    /**
     * Log shutdown message (implementation specific)
     *
     * Handles logging of shutdown messages for fatal errors.
     * Implementation can vary between console logging, file logging,
     * or system logging.
     *
     * @param string $message Shutdown message to log
     */
    abstract protected function logShutdown(string $message): void;

    /**
     * Handle error event (implementation specific)
     *
     * Called when an error occurs. Child classes should implement
     * specific cleanup or recovery actions for their context.
     */
    abstract protected function onError(): void;

    /**
     * Handle exception event (implementation specific)
     *
     * Called when an uncaught exception occurs. Child classes should
     * implement specific cleanup or recovery actions for their context.
     */
    abstract protected function onException(): void;

    /**
     * Handle shutdown event (implementation specific)
     *
     * Called when a fatal shutdown occurs. Child classes should
     * implement specific cleanup actions for their context.
     */
    abstract protected function onShutdown(): void;

    /**
     * Handle shutdown signal event (implementation specific)
     *
     * Called when a shutdown signal (SIGTERM, SIGINT) is received.
     * Child classes should implement specific cleanup actions.
     */
    abstract protected function onShutdownSignal(): void;

    /**
     * Handle restart signal event (implementation specific)
     *
     * Called when a restart signal (SIGHUP) is received.
     * Child classes should implement specific restart actions.
     */
    abstract protected function onRestartSignal(): void;
}

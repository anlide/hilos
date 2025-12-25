<?php

declare(strict_types=1);

namespace Hilos\Logging\Logger;

use Hilos\Utils\Helpers\TimeHelper;

/**
 * Logger - Static logger for daemon and agent logging
 *
 * Provides simple static interface for logging with optional file logging.
 * Can write to file (if log file is set) or to stdout/stderr.
 */
class Logger
{
    /** @var ?string Log file path for daemon-side logging */
    private static ?string $logFile = null;

    /** @var ?string Error log file path for error-only logging */
    private static ?string $errorLogFile = null;

    /** @var bool Whether to show log level prefix [INFO], [ERROR], [DEBUG] (default: false) */
    private static bool $showLogLevel = false;

    /** @var bool Whether to enable debug logging (default: false) */
    private static bool $debugEnabled = false;

    /** @var string Agent log marker for parsing in daemon */
    private const string AGENT_LOG_MARKER = '[AGENT_LOG]';

    /**
     * Set log file path for daemon-side logging
     *
     * @param string $logFile Log file path
     */
    public static function setLogFile(string $logFile): void
    {
        self::$logFile = $logFile;
    }

    /**
     * Set error log file path for error-only logging
     *
     * @param string $errorLogFile Error log file path
     */
    public static function setErrorLogFile(string $errorLogFile): void
    {
        self::$errorLogFile = $errorLogFile;
    }

    /**
     * Enable or disable log level prefix display
     *
     * @param bool $showLogLevel If true, log messages will include [INFO], [ERROR], [DEBUG] prefix
     */
    public static function setShowLogLevel(bool $showLogLevel): void
    {
        self::$showLogLevel = $showLogLevel;
    }

    /**
     * Enable or disable debug logging
     *
     * @param bool $enabled If true, debug messages will be logged, otherwise they will be ignored
     */
    public static function setDebugEnabled(bool $enabled): void
    {
        self::$debugEnabled = $enabled;
    }

    /**
     * Log info message
     *
     * @param string $message Message to log
     * @param array $context Optional context data
     */
    public static function info(string $message, array $context = []): void
    {
        self::log('INFO', $message, $context);
    }

    /**
     * Log error message
     *
     * @param string $message Error message
     * @param array $context Optional context data
     */
    public static function error(string $message, array $context = []): void
    {
        self::log('ERROR', $message, $context, true);
    }

    /**
     * Log debug message
     *
     * Only logs if debug logging is enabled via setDebugEnabled(true).
     * By default, debug logging is disabled.
     *
     * @param string $message Debug message
     * @param array $context Optional context data
     */
    public static function debug(string $message, array $context = []): void
    {
        if (!self::$debugEnabled) {
            return;
        }
        self::log('DEBUG', $message, $context);
    }

    /**
     * Log message
     *
     * @param string $level Log level (INFO, ERROR, DEBUG)
     * @param string $message Message
     * @param array $context Optional context data
     * @param bool $useStderr If true, write to stderr instead of stdout
     */
    private static function log(string $level, string $message, array $context = [], bool $useStderr = false): void
    {
        $timestamp = TimeHelper::getTimestampWithMs();
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';

        // Determine prefix for main log file:
        // - INFO: no prefix (main log entries)
        // - DEBUG: DEBUG: prefix
        // - ERROR: ERROR: prefix
        $mainLogPrefix = ($level === 'INFO') ? '' : "{$level}:";
        $mainLogLine = "[{$timestamp}]" . ($mainLogPrefix !== '' ? " {$mainLogPrefix}" : '') . " {$message}{$contextStr}";

        // For error log file: no prefix (only errors there)
        $errorLogLine = "[{$timestamp}] {$message}{$contextStr}";

        if (self::$logFile !== null) {
            // Write to main log file
            file_put_contents(self::$logFile, $mainLogLine . "\n", FILE_APPEND | LOCK_EX);

            // If ERROR level, also write to error log file (without prefix)
            if ($level === 'ERROR' && self::$errorLogFile !== null) {
                file_put_contents(self::$errorLogFile, $errorLogLine . "\n", FILE_APPEND | LOCK_EX);
            }
        } else {
            // Fallback to echo/error_log
            // For ERROR level: write to both stdout (with prefix) and stderr (without prefix)
            if ($level === 'ERROR') {
                // Write to stdout (main log file) with ERROR: prefix
                echo $mainLogLine . "\n";
                // Write to stderr (error log file) without prefix
                self::errorLog($errorLogLine);
            } elseif ($useStderr) {
                // For other levels going to stderr, write without prefix
                self::errorLog($errorLogLine);
            } else {
                // For other levels going to stdout, write with appropriate prefix
                echo $mainLogLine . "\n";
            }
        }
    }

    /**
     * Log error message using error_log (replacement for error_log function)
     *
     * This method replaces direct error_log() calls throughout the codebase.
     * If log file is set, writes to file, otherwise uses error_log().
     *
     * @param string $message Error message
     * @param int $messageType Optional message type (default: 0)
     * @param ?string $destination Optional destination file (if specified, uses error_log with 3rd param)
     */
    public static function errorLog(string $message, int $messageType = 0, ?string $destination = null): void
    {
        if ($destination !== null) {
            // Special case: write to specific file (used by DockerManager for error log file)
            error_log($message, 3, $destination);
            return;
        }

        if (self::$logFile !== null) {
            // Write to main log file with ERROR: prefix
            $timestamp = TimeHelper::getTimestampWithMs();
            $mainLogLine = "[{$timestamp}] ERROR: {$message}";
            file_put_contents(self::$logFile, $mainLogLine . "\n", FILE_APPEND | LOCK_EX);

            // Also write to error log file without prefix
            if (self::$errorLogFile !== null) {
                $errorLogLine = "[{$timestamp}] {$message}";
                file_put_contents(self::$errorLogFile, $errorLogLine . "\n", FILE_APPEND | LOCK_EX);
            }
        } else {
            // Fallback to error_log
            error_log($message, $messageType);
        }
    }

    // Agent-specific logging methods (from AgentLogger)

    /**
     * Log agent start event
     *
     * @param string $agentId Agent ID
     * @param string $agentType Agent type
     */
    public static function logAgentStart(string $agentId, string $agentType): void
    {
        self::logAgent($agentId, 'INFO', "Agent started '{$agentType}'");
    }

    /**
     * Log agent stop event
     *
     * @param string $agentId Agent ID
     * @param string $agentType Agent type
     */
    public static function logAgentStop(string $agentId, string $agentType): void
    {
        self::logAgent($agentId, 'INFO', "Agent stopped '{$agentType}'");
    }

    /**
     * Log message from user
     *
     * @param string $agentId Agent ID
     * @param string $userId User ID
     * @param string $message User message (truncated if too long)
     */
    public static function logAgentUserMessage(string $agentId, string $userId, string $message): void
    {
        // Truncate message if too long (e.g., > 200 chars) for readability
        $truncatedMessage = mb_strlen($message) > 200
            ? mb_substr($message, 0, 197) . '...'
            : $message;

        self::logAgent($agentId, 'INFO', "User message [userId={$userId}] [message={$truncatedMessage}]");
    }

    /**
     * Log agent info message
     *
     * @param string $agentId Agent ID
     * @param string $message Message
     */
    public static function logAgentInfo(string $agentId, string $message): void
    {
        self::logAgent($agentId, 'INFO', $message);
    }

    /**
     * Log agent error message
     *
     * @param string $agentId Agent ID
     * @param string $message Error message
     */
    public static function logAgentError(string $agentId, string $message): void
    {
        self::logAgent($agentId, 'ERROR', $message, true);
    }

    /**
     * Log agent debug message
     *
     * @param string $agentId Agent ID
     * @param string $message Debug message
     */
    public static function logAgentDebug(string $agentId, string $message): void
    {
        self::logAgent($agentId, 'DEBUG', $message);
    }

    /**
     * Log message in agent format
     *
     * @param string $agentId Agent ID
     * @param string $level Log level (INFO, ERROR, DEBUG)
     * @param string $message Message
     * @param bool $useStderr If true, write to stderr instead of stdout
     */
    private static function logAgent(string $agentId, string $level, string $message, bool $useStderr = false): void
    {
        $timestamp = TimeHelper::getTimestampWithMs();
        $logLine = self::AGENT_LOG_MARKER . "{$agentId}|{$level}|[{$timestamp}] {$message}";

        if ($useStderr) {
            self::errorLog($logLine);
        } else {
            echo $logLine . "\n";
        }
    }
}

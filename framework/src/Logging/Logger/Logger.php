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

    /** @var bool Whether to show log level prefix [INFO], [ERROR], [DEBUG] (default: false) */
    private static bool $showLogLevel = false;

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
     * Enable or disable log level prefix display
     *
     * @param bool $showLogLevel If true, log messages will include [INFO], [ERROR], [DEBUG] prefix
     */
    public static function setShowLogLevel(bool $showLogLevel): void
    {
        self::$showLogLevel = $showLogLevel;
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
     * @param string $message Debug message
     * @param array $context Optional context data
     */
    public static function debug(string $message, array $context = []): void
    {
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
        $levelPrefix = self::$showLogLevel ? " [{$level}]" : '';
        $logLine = "[{$timestamp}]{$levelPrefix} {$message}{$contextStr}";

        if (self::$logFile !== null) {
            // Write to log file if set
            file_put_contents(self::$logFile, $logLine . "\n", FILE_APPEND | LOCK_EX);
        } else {
            // Fallback to echo/error_log
            if ($useStderr) {
                self::errorLog($logLine);
            } else {
                echo $logLine . "\n";
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
            // Write to log file if set
            $timestamp = TimeHelper::getTimestampWithMs();
            $logLine = "[{$timestamp}] [ERROR] {$message}";
            file_put_contents(self::$logFile, $logLine . "\n", FILE_APPEND | LOCK_EX);
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
        self::logAgent($agentId, 'INFO', "Agent started [type={$agentType}]");
    }

    /**
     * Log agent stop event
     *
     * @param string $agentId Agent ID
     * @param string $agentType Agent type
     */
    public static function logAgentStop(string $agentId, string $agentType): void
    {
        self::logAgent($agentId, 'INFO', "Agent stopped [type={$agentType}]");
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


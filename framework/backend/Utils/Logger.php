<?php

declare(strict_types=1);

namespace Hilos\Utils;

use Hilos\Utils\Helpers\TimeHelper;

/**
 * Logger - Static logger for daemon and agent logging.
 *
 * Provides simple static interface for logging with optional file logging.
 * Can write to file (if log file is set) or to stdout/stderr.
 */
class Logger
{
    /** Log level: informational messages */
    public const string LEVEL_INFO = 'INFO';

    /** Log level: error messages */
    public const string LEVEL_ERROR = 'ERROR';

    /** Log level: warning messages */
    public const string LEVEL_WARNING = 'WARNING';

    /** Log level: debug messages */
    public const string LEVEL_DEBUG = 'DEBUG';

    /** Agent log marker for parsing in daemon */
    public const string AGENT_LOG_MARKER = '[AGENT_LOG]';

    /** Field separator in agent log line (format: agentId|level|message) */
    public const string AGENT_LOG_FIELD_SEPARATOR = '|';

    /** Number of pipe-separated fields in agent log line (agentId, level, message) */
    public const int AGENT_LOG_FIELDS_COUNT = 3;

    /** Maximum length of user message in agent log before truncation */
    private const int AGENT_USER_MESSAGE_MAX_LENGTH = 200;

    /** Suffix appended when agent user message is truncated */
    private const string TRUNCATION_SUFFIX = '...';

    /** @var ?string Log file path for daemon-side logging */
    private static ?string $logFile = null;

    /** @var ?string Error log file path for error-only logging */
    private static ?string $errorLogFile = null;

    /** @var bool Whether to show log level prefix [INFO], [ERROR], [DEBUG] (default: false) */
    private static bool $showLogLevel = false;

    /** @var bool Whether to enable debug logging (default: false) */
    private static bool $debugEnabled = false;

    /**
     * Set log file path for daemon-side logging.
     *
     * @param string $logFile Log file path
     */
    public static function setLogFile(string $logFile): void
    {
        self::$logFile = $logFile;
    }

    /**
     * Set error log file path for error-only logging.
     *
     * The address holds independently of the main log: a process that sets only this one
     * (the docker watchdog) keeps echoing its whole feed to stdout while its errors are
     * also appended here.
     *
     * @param string $errorLogFile Error log file path
     */
    public static function setErrorLogFile(string $errorLogFile): void
    {
        self::$errorLogFile = $errorLogFile;
    }

    /**
     * Enable or disable log level prefix display.
     *
     * @param bool $showLogLevel If true, log messages will include [INFO], [ERROR], [DEBUG] prefix
     */
    public static function setShowLogLevel(bool $showLogLevel): void
    {
        self::$showLogLevel = $showLogLevel;
    }

    /**
     * Enable or disable debug logging.
     *
     * @param bool $enabled If true, debug messages will be logged, otherwise they will be ignored
     */
    public static function setDebugEnabled(bool $enabled): void
    {
        self::$debugEnabled = $enabled;
    }

    /**
     * Log info message.
     *
     * @param string $message Message to log
     * @param array<string, mixed> $context Optional context data
     */
    public static function info(string $message, array $context = []): void
    {
        self::log(self::LEVEL_INFO, $message, $context);
    }

    /**
     * Log error message.
     *
     * @param string $message Error message
     * @param array<string, mixed> $context Optional context data
     */
    public static function error(string $message, array $context = []): void
    {
        self::log(self::LEVEL_ERROR, $message, $context, true);
    }

    /**
     * Log warning message.
     *
     * A recoverable but noteworthy condition. Written to the main log with a
     * WARNING prefix, not to the error log or stderr.
     *
     * @param string $message Warning message
     * @param array<string, mixed> $context Optional context data
     */
    public static function warning(string $message, array $context = []): void
    {
        self::log(self::LEVEL_WARNING, $message, $context);
    }

    /**
     * Log debug message.
     *
     * Only logs if debug logging is enabled via setDebugEnabled(true).
     * By default, debug logging is disabled.
     *
     * @param string $message Debug message
     * @param array<string, mixed> $context Optional context data
     */
    public static function debug(string $message, array $context = []): void
    {
        if (!self::$debugEnabled) {
            return;
        }
        self::log(self::LEVEL_DEBUG, $message, $context);
    }

    /**
     * Log message.
     *
     * With no main log file the line is echoed to stdout; an ERROR is additionally
     * appended to the error log file when one is set, and only falls back to stderr
     * when it is not.
     *
     * @param string $level Log level (INFO, ERROR, WARNING, DEBUG)
     * @param string $message Message
     * @param array<string, mixed> $context Optional context data
     * @param bool $useStderr If true, write to stderr instead of stdout
     */
    private static function log(string $level, string $message, array $context = [], bool $useStderr = false): void
    {
        $timestamp = TimeHelper::getTimestampWithMs();
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';

        // Determine prefix for main log file:
        // - If showLogLevel: [INFO], [ERROR], [DEBUG] format for all levels
        // - If !showLogLevel: INFO has no prefix, DEBUG/ERROR have "LEVEL:" prefix
        if (self::$showLogLevel) {
            $mainLogPrefix = "[{$level}]";
        } else {
            $mainLogPrefix = ($level === self::LEVEL_INFO) ? '' : "{$level}:";
        }
        $mainLogLine = "[{$timestamp}]" . ($mainLogPrefix !== '' ? " {$mainLogPrefix}" : '') . " {$message}{$contextStr}";

        // For error log file: no prefix (only errors there)
        $errorLogLine = "[{$timestamp}] {$message}{$contextStr}";

        if (self::$logFile !== null) {
            // Write to main log file
            file_put_contents(self::$logFile, $mainLogLine . "\n", FILE_APPEND | LOCK_EX);

            // If ERROR level, also write to error log file (without prefix)
            if ($level === self::LEVEL_ERROR && self::$errorLogFile !== null) {
                file_put_contents(self::$errorLogFile, $errorLogLine . "\n", FILE_APPEND | LOCK_EX);
            }
        } else {
            // Fallback to echo/error_log
            // For ERROR level: write to both stdout (with prefix) and the error log file
            // (without prefix), or stderr when no error log file is set
            if ($level === self::LEVEL_ERROR) {
                // Write to stdout (main log file) with ERROR: prefix
                echo $mainLogLine . "\n";
                if (self::$errorLogFile !== null) {
                    // Write straight to the file: the line already carries its
                    // timestamp, and errorLog() would stamp it a second time
                    file_put_contents(self::$errorLogFile, $errorLogLine . "\n", FILE_APPEND | LOCK_EX);
                } else {
                    // Write to stderr without prefix
                    self::errorLog($errorLogLine);
                }
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
     * If the main log file is set, writes there (and to the error log file when that is
     * set too); with no main log file it writes to the error log file alone, stamping the
     * line itself; with neither, it falls back to error_log().
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
            $mainLogLine = "[{$timestamp}] " . self::LEVEL_ERROR . ": {$message}";
            file_put_contents(self::$logFile, $mainLogLine . "\n", FILE_APPEND | LOCK_EX);

            // Also write to error log file without prefix
            if (self::$errorLogFile !== null) {
                $errorLogLine = "[{$timestamp}] {$message}";
                file_put_contents(self::$errorLogFile, $errorLogLine . "\n", FILE_APPEND | LOCK_EX);
            }
        } elseif (self::$errorLogFile !== null) {
            // No main log, but the process declared an error log: write there in the
            // same "[timestamp] message" shape the daemon uses for that file
            $timestamp = TimeHelper::getTimestampWithMs();
            file_put_contents(self::$errorLogFile, "[{$timestamp}] {$message}\n", FILE_APPEND | LOCK_EX);
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
        self::logAgent($agentId, self::LEVEL_INFO, "Agent started '{$agentType}'");
    }

    /**
     * Log agent stop event
     *
     * @param string $agentId Agent ID
     * @param string $agentType Agent type
     */
    public static function logAgentStop(string $agentId, string $agentType): void
    {
        self::logAgent($agentId, self::LEVEL_INFO, "Agent stopped '{$agentType}'");
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
        // Truncate message if too long for readability
        $truncatedMessage = mb_strlen($message) > self::AGENT_USER_MESSAGE_MAX_LENGTH
            ? mb_substr($message, 0, self::AGENT_USER_MESSAGE_MAX_LENGTH - mb_strlen(self::TRUNCATION_SUFFIX)) . self::TRUNCATION_SUFFIX
            : $message;

        self::logAgent($agentId, self::LEVEL_INFO, "User message [userId={$userId}] [message={$truncatedMessage}]");
    }

    /**
     * Log agent info message
     *
     * @param string $agentId Agent ID
     * @param string $message Message
     */
    public static function logAgentInfo(string $agentId, string $message): void
    {
        self::logAgent($agentId, self::LEVEL_INFO, $message);
    }

    /**
     * Log agent error message
     *
     * @param string $agentId Agent ID
     * @param string $message Error message
     */
    public static function logAgentError(string $agentId, string $message): void
    {
        self::logAgent($agentId, self::LEVEL_ERROR, $message, true);
    }

    /**
     * Log agent warning message
     *
     * @param string $agentId Agent ID
     * @param string $message Warning message
     */
    public static function logAgentWarning(string $agentId, string $message): void
    {
        self::logAgent($agentId, self::LEVEL_WARNING, $message);
    }

    /**
     * Log agent debug message
     *
     * Only logs if debug logging is enabled via setDebugEnabled(true).
     * By default, debug logging is disabled.
     *
     * @param string $agentId Agent ID
     * @param string $message Debug message
     */
    public static function logAgentDebug(string $agentId, string $message): void
    {
        if (!self::$debugEnabled) {
            return;
        }
        self::logAgent($agentId, self::LEVEL_DEBUG, $message);
    }

    /**
     * Log message in agent format
     *
     * @param string $agentId Agent ID
     * @param string $level Log level (INFO, ERROR, WARNING, DEBUG)
     * @param string $message Message
     * @param bool $useStderr If true, write to stderr instead of stdout
     */
    private static function logAgent(string $agentId, string $level, string $message, bool $useStderr = false): void
    {
        $timestamp = TimeHelper::getTimestampWithMs();
        $logLine = self::AGENT_LOG_MARKER . "{$agentId}" . self::AGENT_LOG_FIELD_SEPARATOR . "{$level}" . self::AGENT_LOG_FIELD_SEPARATOR . "[{$timestamp}] {$message}";

        if ($useStderr) {
            self::errorLog($logLine);
        } else {
            echo $logLine . "\n";
        }
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Logging\Logger;

/**
 * AgentLogger - Logger for agent-specific events
 *
 * Outputs agent logs to stdout/stderr in special format for daemon parsing.
 * Format: [AGENT_LOG]agentId|level|message
 */
class AgentLogger
{
    /**
     * Agent log marker for parsing in daemon
     */
    private const string MARKER = '[AGENT_LOG]';

    /**
     * Log agent start event
     *
     * @param string $agentId Agent ID
     * @param string $agentType Agent type
     */
    public static function logStart(string $agentId, string $agentType): void
    {
        self::log($agentId, 'INFO', "Agent started [type={$agentType}]");
    }

    /**
     * Log agent stop event
     *
     * @param string $agentId Agent ID
     * @param string $agentType Agent type
     */
    public static function logStop(string $agentId, string $agentType): void
    {
        self::log($agentId, 'INFO', "Agent stopped [type={$agentType}]");
    }

    /**
     * Log message from user
     *
     * @param string $agentId Agent ID
     * @param string $userId User ID
     * @param string $message User message (truncated if too long)
     */
    public static function logUserMessage(string $agentId, string $userId, string $message): void
    {
        // Truncate message if too long (e.g., > 200 chars) for readability
        $truncatedMessage = mb_strlen($message) > 200 
            ? mb_substr($message, 0, 197) . '...' 
            : $message;
        
        self::log($agentId, 'INFO', "User message [userId={$userId}] [message={$truncatedMessage}]");
    }

    /**
     * Log agent info message
     *
     * @param string $agentId Agent ID
     * @param string $message Message
     */
    public static function logInfo(string $agentId, string $message): void
    {
        self::log($agentId, 'INFO', $message);
    }

    /**
     * Log agent error message
     *
     * @param string $agentId Agent ID
     * @param string $message Error message
     */
    public static function logError(string $agentId, string $message): void
    {
        self::log($agentId, 'ERROR', $message, true);
    }

    /**
     * Log agent debug message
     *
     * @param string $agentId Agent ID
     * @param string $message Debug message
     */
    public static function logDebug(string $agentId, string $message): void
    {
        self::log($agentId, 'DEBUG', $message);
    }

    /**
     * Log message in agent format
     *
     * @param string $agentId Agent ID
     * @param string $level Log level (INFO, ERROR, DEBUG)
     * @param string $message Message
     * @param bool $useStderr If true, write to stderr instead of stdout
     */
    private static function log(string $agentId, string $level, string $message, bool $useStderr = false): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logLine = self::MARKER . "{$agentId}|{$level}|[{$timestamp}] {$message}";
        
        if ($useStderr) {
            error_log($logLine);
        } else {
            echo $logLine;
        }
    }
}


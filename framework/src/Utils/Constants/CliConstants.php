<?php

namespace Hilos\Utils\Constants;

/**
 * CLI Constants - constants for CLI interface
 *
 * All configurable values are read from environment variables (.env file)
 * with fallback to default values
 */
class CliConstants
{
    // Error message limits (not configurable)
    public const int ERROR_MESSAGE_MAX_LENGTH = 2000;
    public const int ERROR_STACK_TRACE_MAX_LENGTH = 10000;

    // HTTP Status endpoint path (not configurable)
    public const string HTTP_STATUS_PATH = '/status';

    /**
     * Get daemon host for monitoring
     *
     * @return string Daemon host (IP or hostname)
     */
    public static function getMonitorDaemonHost(): string
    {
        return getenv('HILOS_DAEMON_HOST') ?: '127.0.0.1';
    }

    /**
     * Get HTTP status server host
     *
     * @return string Host to bind HTTP server (0.0.0.0 = all interfaces)
     */
    public static function getHttpStatusHost(): string
    {
        return getenv('HTTP_STATUS_HOST') ?: '0.0.0.0';
    }

    /**
     * Get HTTP status server port
     *
     * @return int Port number
     */
    public static function getHttpStatusPort(): int
    {
        return (int)(getenv('HTTP_STATUS_PORT') ?: 8090);
    }

    /**
     * Get worker communication host
     *
     * @return string Host to bind worker server (0.0.0.0 = all interfaces)
     */
    public static function getWorkerCommHost(): string
    {
        return getenv('WORKER_COMM_HOST') ?: '0.0.0.0';
    }

    /**
     * Get worker communication port
     *
     * @return int Port number
     */
    public static function getWorkerCommPort(): int
    {
        return (int)(getenv('WORKER_COMM_PORT') ?: 8091);
    }

    /**
     * Get daemon log file path
     *
     * @return string Log file path
     */
    public static function getDaemonLogFile(): string
    {
        return getenv('DAEMON_LOG_FILE') ?: '/var/log/hilos/daemon.log';
    }

    /**
     * Get daemon error log file path
     *
     * @return string Error log file path
     */
    public static function getDaemonErrorLogFile(): string
    {
        return getenv('DAEMON_ERROR_LOG_FILE') ?: '/var/log/hilos/daemon-error.log';
    }
}

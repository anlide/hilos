<?php

namespace Hilos\Utils\Constants;

/**
 * CLI Constants - константы для CLI интерфейса
 */
class CliConstants
{
    // Лог файлы
    public const string DAEMON_LOG_FILE = '/var/log/hilos/daemon.log';
    public const string DAEMON_ERROR_LOG_FILE = '/var/log/hilos/daemon-error.log';

    // Error message limits
    public const int ERROR_MESSAGE_MAX_LENGTH = 2000; // Maximum length for error messages
    public const int ERROR_STACK_TRACE_MAX_LENGTH = 10000; // Maximum length for stack traces

    // HTTP Status endpoint
    public const string HTTP_STATUS_HOST = '0.0.0.0';
    public const int HTTP_STATUS_PORT = 8090;
    public const string HTTP_STATUS_PATH = '/status';

    // Worker Communication
    public const string WORKER_COMM_HOST = '0.0.0.0';
    public const int WORKER_COMM_PORT = 8091;

    // Monitor settings
    public const string MONITOR_DAEMON_HOST = 'hilos'; // Docker container name
}

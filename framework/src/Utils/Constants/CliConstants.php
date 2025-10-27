<?php

namespace Hilos\Utils\Constants;

/**
 * CLI Constants - константы для CLI интерфейса
 */
class CliConstants
{
    // PID файл
    public const PID_FILE = '/var/run/hilos.pid';
    
    // Лог файлы
    public const DAEMON_LOG_FILE = '/var/log/hilos/daemon.log';
    public const DAEMON_ERROR_LOG_FILE = '/var/log/hilos/daemon-error.log';
    public const WORKER_LOG_FILE_PATTERN = '/var/log/hilos/worker-%d.log';
    
    // Пути к скриптам
    public const DAEMON_SCRIPT_PATH = __DIR__ . '/../../Bootstrap/daemon.php';
    public const WORKER_SCRIPT_PATH = __DIR__ . '/../../Bootstrap/worker.php';
    
    // Сигналы
    public const SIGTERM_TIMEOUT = 10; // секунды
    public const SIGTERM_CHECK_INTERVAL = 100000; // микросекунды (100ms)
    
    // Команды
    public const COMMAND_DAEMON_START = 'daemon:start';
    public const COMMAND_DAEMON_STOP = 'daemon:stop';
    public const COMMAND_DAEMON_RESTART = 'daemon:restart';
    public const COMMAND_DAEMON_STATUS = 'daemon:status';
    public const COMMAND_DAEMON_MONITOR = 'daemon:monitor';
    
    // Опции
    public const OPTION_FOREGROUND = '--foreground';
    public const OPTION_WORKER_ID = '--worker-id';
    public const OPTION_CONFIG = '--config';
    
    // Сообщения
    public const MSG_DAEMON_ALREADY_RUNNING = 'Daemon is already running (PID: %d)';
    public const MSG_DAEMON_NOT_RUNNING = 'Daemon is not running';
    public const MSG_DAEMON_STARTED = 'Daemon started successfully';
    public const MSG_DAEMON_STOPPED = 'Daemon stopped';
    public const MSG_DAEMON_START_FAILED = 'Failed to start daemon';
    public const MSG_DAEMON_STOP_FAILED = 'Failed to stop daemon';
    public const MSG_CANNOT_GET_PID = 'Cannot get daemon PID';
    public const MSG_UNKNOWN_COMMAND = 'Unknown command: %s';
    
    // Статус
    public const STATUS_RUNNING = 'running';
    public const STATUS_STOPPED = 'stopped';
    public const STATUS_UNKNOWN = 'unknown';
    
    // Error message limits
    public const ERROR_MESSAGE_MAX_LENGTH = 2000; // Maximum length for error messages
    public const ERROR_STACK_TRACE_MAX_LENGTH = 10000; // Maximum length for stack traces
    
    // HTTP Status endpoint
    public const HTTP_STATUS_HOST = '0.0.0.0';
    public const HTTP_STATUS_PORT = 8090;
    public const HTTP_STATUS_PATH = '/status';
    public const HTTP_TIMEOUT = 0.5; // seconds
    
    // Worker Communication
    public const WORKER_COMM_HOST = '0.0.0.0';
    public const WORKER_COMM_PORT = 8091;
    
    // Monitor settings
    public const MONITOR_DAEMON_HOST = 'hilos'; // Docker container name
    public const MONITOR_UPDATE_INTERVAL = 1000000; // microseconds (1 second)
}

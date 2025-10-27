<?php

namespace Hilos\Logging\Logger;

/**
 * FileLogger - простой файловый логгер
 */
class FileLogger
{
    private string $logFile;

    public function __construct(string $logFile)
    {
        $this->logFile = $logFile;
    }

    /**
     * Log info message
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    /**
     * Log error message
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    /**
     * Log debug message
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log('DEBUG', $message, $context);
    }

    /**
     * Log message
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
        $logLine = "[$timestamp] [$level] $message$contextStr\n";
        
        file_put_contents($this->logFile, $logLine, FILE_APPEND | LOCK_EX);
    }
}

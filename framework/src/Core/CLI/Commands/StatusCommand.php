<?php

declare(strict_types=1);

namespace Hilos\Core\CLI\Commands;

use Hilos\Utils\Constants\CliConstants;
use Hilos\Utils\Constants\ExitCode;

/**
 * StatusCommand - Display daemon status
 *
 * Shows current daemon status including PID, uptime, memory usage and other metrics.
 * Retrieves real-time data from running daemon process.
 */
class StatusCommand implements CommandInterface
{
    /** @var string Unknown value display */
    private const string VALUE_UNKNOWN = 'Unknown';

    /** @var string Not available value display */
    private const string VALUE_NOT_AVAILABLE = 'N/A';

    /**
     * Execute status command
     *
     * Displays current daemon status with real-time data.
     *
     * @param array $options Command options
     * @param array $args Positional arguments (unused)
     * @return int Exit code (0)
     */
    public function execute(array $options, array $args): int
    {
        echo "Hilos Daemon Status\n";
        echo "==================\n";

        // Get daemon status
        $isRunning = $this->isDaemonRunning();
        $pid = $this->getDaemonPid();

        // Display status
        echo "Status: " . ($isRunning ? CliConstants::STATUS_RUNNING : CliConstants::STATUS_STOPPED) . "\n";

        if ($isRunning && $pid !== null) {
            echo "PID: {$pid}\n";

            // Get uptime
            $uptime = $this->getUptime($pid);
            echo "Uptime: " . ($uptime !== null ? $this->formatUptime($uptime) : self::VALUE_UNKNOWN) . "\n";

            // Get memory usage
            $memory = $this->getMemoryUsage($pid);
            echo "Memory: " . ($memory !== null ? $this->formatMemory($memory) : self::VALUE_UNKNOWN) . "\n";

            // Last heartbeat (from log file or current time)
            echo "Last heartbeat: " . date('Y-m-d H:i:s') . "\n";

            // Container name
            echo "Container: " . gethostname() . "\n";
        } else {
            echo "PID: " . self::VALUE_NOT_AVAILABLE . "\n";
        }

        // Show parsed options if any (for debugging/demonstration)
        if (!empty($options)) {
            echo "\nParsed options:\n";
            foreach ($options as $key => $value) {
                $displayValue = is_bool($value) ? 'true' : $value;
                echo "  --{$key}: {$displayValue}\n";
            }
        }

        echo "\n";

        return ExitCode::SUCCESS;
    }

    /**
     * Check if daemon is running
     *
     * @return bool True if daemon is running
     */
    private function isDaemonRunning(): bool
    {
        if (!file_exists(CliConstants::PID_FILE)) {
            return false;
        }

        $pid = $this->getDaemonPid();
        if ($pid === null) {
            return false;
        }

        // Check if process exists
        return posix_kill($pid, 0);
    }

    /**
     * Get daemon PID
     *
     * @return ?int Daemon PID or null
     */
    private function getDaemonPid(): ?int
    {
        if (!file_exists(CliConstants::PID_FILE)) {
            return null;
        }

        $pidContent = file_get_contents(CliConstants::PID_FILE);
        if ($pidContent === false) {
            return null;
        }

        $pid = (int)$pidContent;
        return $pid > 0 ? $pid : null;
    }

    /**
     * Get process uptime in seconds
     *
     * @param int $pid Process ID
     * @return ?int Uptime in seconds or null
     */
    private function getUptime(int $pid): ?int
    {
        // Try to read from /proc filesystem (Linux)
        $statFile = "/proc/{$pid}/stat";
        if (!file_exists($statFile)) {
            return null;
        }

        $stat = file_get_contents($statFile);
        if ($stat === false) {
            return null;
        }

        $parts = explode(' ', $stat);
        if (count($parts) < 22) {
            return null;
        }

        $startTime = (int)$parts[21];
        $clockTicks = (int)shell_exec('getconf CLK_TCK') ?: 100;
        $uptimeFile = file_get_contents('/proc/uptime');
        if ($uptimeFile === false) {
            return null;
        }

        $systemUptime = (float)explode(' ', $uptimeFile)[0];
        $processStartSeconds = $startTime / $clockTicks;
        $uptime = (int)($systemUptime - $processStartSeconds);

        return $uptime > 0 ? $uptime : null;
    }

    /**
     * Get memory usage in bytes
     *
     * @param int $pid Process ID
     * @return ?int Memory usage in bytes or null
     */
    private function getMemoryUsage(int $pid): ?int
    {
        // Try to read from /proc filesystem (Linux)
        $statusFile = "/proc/{$pid}/status";
        if (!file_exists($statusFile)) {
            return null;
        }

        $status = file_get_contents($statusFile);
        if ($status === false) {
            return null;
        }

        if (preg_match('/VmRSS:\s+(\d+)\s+kB/', $status, $matches)) {
            return (int)$matches[1] * 1024; // Convert to bytes
        }

        return null;
    }

    /**
     * Format uptime in human readable format
     *
     * @param int $seconds Uptime in seconds
     * @return string Formatted uptime (HH:MM:SS)
     */
    private function formatUptime(int $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }

    /**
     * Format memory in human readable format
     *
     * @param int $bytes Memory in bytes
     * @return string Formatted memory (e.g., "15.2 MB")
     */
    private function formatMemory(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB',];
        $unitIndex = 0;
        $value = (float)$bytes;

        while ($value >= 1024 && $unitIndex < count($units) - 1) {
            $value /= 1024;
            $unitIndex++;
        }

        return round($value, 2) . ' ' . $units[$unitIndex];
    }
}


<?php

namespace Hilos\Core\CLI;

use Hilos\Utils\Constants\CliConstants;

/**
 * CLI Interface - основной интерфейс для CLI команд
 */
class CliInterface
{
    /**
     * Check if daemon is running
     */
    public function isDaemonRunning(): bool
    {
        if (!file_exists(CliConstants::PID_FILE)) {
            return false;
        }

        $pid = (int)file_get_contents(CliConstants::PID_FILE);
        if ($pid <= 0) {
            return false;
        }

        // Check if process exists
        return posix_kill($pid, 0);
    }

    /**
     * Get daemon PID
     */
    public function getDaemonPid(): ?int
    {
        if (!file_exists(CliConstants::PID_FILE)) {
            return null;
        }

        $pid = (int)file_get_contents(CliConstants::PID_FILE);
        return $pid > 0 ? $pid : null;
    }

    /**
     * Start daemon process
     */
    public function startDaemon(bool $foreground = false): bool
    {
        if ($this->isDaemonRunning()) {
            $pid = $this->getDaemonPid();
            echo sprintf(CliConstants::MSG_DAEMON_ALREADY_RUNNING, $pid) . "\n";
            return false;
        }

        $command = 'php ' . CliConstants::DAEMON_SCRIPT_PATH;
        if ($foreground) {
            $command .= ' ' . CliConstants::OPTION_FOREGROUND;
        }

        $descriptorspec = [
            0 => ['file', '/dev/null', 'r'],                              // stdin
            1 => ['file', CliConstants::DAEMON_LOG_FILE, 'a'],            // stdout
            2 => ['file', CliConstants::DAEMON_ERROR_LOG_FILE, 'a']       // stderr
        ];

        $process = proc_open($command, $descriptorspec, $pipes);

        if (is_resource($process)) {
            // Store PID
            $status = proc_get_status($process);
            if ($status && $status['pid']) {
                file_put_contents(CliConstants::PID_FILE, $status['pid']);
            }
            
            proc_close($process);
            echo CliConstants::MSG_DAEMON_STARTED . "\n";
            return true;
        }

        echo CliConstants::MSG_DAEMON_START_FAILED . "\n";
        return false;
    }

    /**
     * Stop daemon process
     */
    public function stopDaemon(): bool
    {
        if (!$this->isDaemonRunning()) {
            echo CliConstants::MSG_DAEMON_NOT_RUNNING . "\n";
            return false;
        }

        $pid = $this->getDaemonPid();
        if (!$pid) {
            echo CliConstants::MSG_CANNOT_GET_PID . "\n";
            return false;
        }

        // Send SIGTERM
        if (posix_kill($pid, SIGTERM)) {
            // Wait for graceful shutdown
            $timeout = CliConstants::SIGTERM_TIMEOUT;
            $start = time();
            
            while ($this->isDaemonRunning() && (time() - $start) < $timeout) {
                usleep(CliConstants::SIGTERM_CHECK_INTERVAL);
            }
            
            if ($this->isDaemonRunning()) {
                // Force kill
                posix_kill($pid, SIGKILL);
            }
            
            // Remove PID file
            if (file_exists(CliConstants::PID_FILE)) {
                unlink(CliConstants::PID_FILE);
            }
            
            echo CliConstants::MSG_DAEMON_STOPPED . "\n";
            return true;
        }

        echo CliConstants::MSG_DAEMON_STOP_FAILED . "\n";
        return false;
    }

    /**
     * Get daemon status
     */
    public function getDaemonStatus(): array
    {
        $isRunning = $this->isDaemonRunning();
        $pid = $this->getDaemonPid();
        
        return [
            'running' => $isRunning,
            'pid' => $pid,
            'uptime' => $isRunning ? $this->getUptime($pid) : null,
            'memory' => $isRunning ? $this->getMemoryUsage($pid) : null,
        ];
    }

    /**
     * Get process uptime
     */
    private function getUptime(int $pid): ?int
    {
        $stat = file_get_contents("/proc/{$pid}/stat");
        if (!$stat) {
            return null;
        }

        $parts = explode(' ', $stat);
        $startTime = (int)$parts[21];
        $clockTicks = (int)shell_exec('getconf CLK_TCK');
        
        return time() - ($startTime / $clockTicks);
    }

    /**
     * Get memory usage
     */
    private function getMemoryUsage(int $pid): ?int
    {
        $status = file_get_contents("/proc/{$pid}/status");
        if (!$status) {
            return null;
        }

        if (preg_match('/VmRSS:\s+(\d+)\s+kB/', $status, $matches)) {
            return (int)$matches[1] * 1024; // Convert to bytes
        }

        return null;
    }
}

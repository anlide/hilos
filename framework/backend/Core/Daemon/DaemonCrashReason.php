<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

final class DaemonCrashReason
{
    /**
     * @param ?int $exitCode Captured process exit code
     * @param ?int $termSignal Captured terminating signal number
     * @param float $uptimeSeconds How long the process survived, in seconds
     * @param string $errorLogTail Tail of the daemon's raw error stream
     * @return string Operator-facing crash reason
     */
    public static function render(
        ?int $exitCode,
        ?int $termSignal,
        float $uptimeSeconds,
        string $errorLogTail,
    ): string {
        if ($termSignal !== null) {
            $processStatus = "killed by signal {$termSignal}";
        } elseif ($exitCode !== null) {
            $processStatus = "exit code {$exitCode}";
        } else {
            $processStatus = 'exit status unknown';
        }

        return $processStatus
            . ', survived ' . number_format($uptimeSeconds, 2) . 's. Last daemon output: '
            . $errorLogTail;
    }
}

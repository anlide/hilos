<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

final class DaemonCrashReason
{
    /**
     * @param ?int $exitCode Captured process exit code
     * @param ?int $termSignal Captured terminating signal number
     * @param float $uptimeSeconds How long the process survived, in seconds
     * @param string $outputTail Tail of what the daemon printed, quoted from all of its streams
     * @return string Operator-facing crash reason
     */
    public static function render(
        ?int $exitCode,
        ?int $termSignal,
        float $uptimeSeconds,
        string $outputTail,
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
            . $outputTail;
    }
}

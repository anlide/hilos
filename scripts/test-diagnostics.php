<?php

declare(strict_types=1);

/**
 * Append a runner observation immediately; a broken diagnostic sink never changes the run.
 *
 * @param string $phase Existing execution boundary
 * @param string $subject Step or command owner, without command arguments
 * @param string $edge Begin, end or an instantaneous observation
 * @param int|null $since Monotonic beginning in nanoseconds, for an end record
 * @param string|null $path Run artifact; null uses the caller's already captured STDERR
 * @param array<string, int|string|bool|null> $details Additional process facts
 * @return int Monotonic timestamp of this observation, in nanoseconds
 */
function testPhase(
    string $phase,
    string $subject,
    string $edge,
    ?int $since = null,
    ?string $path = null,
    array $details = [],
): int {
    $at = hrtime(true);
    try {
        $line = json_encode([
            'phase' => $phase,
            'subject' => $subject,
            'edge' => $edge,
            'pid' => getmypid(),
            'wall' => microtime(true),
            'monotonicNs' => $at,
            'durationNs' => $since === null ? null : $at - $since,
            ...$details,
        ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
        if ($line === false) {
            return $at;
        }
        if ($path === null) {
            // warning-suppressed: an unavailable captured stream only loses this observation
            $written = @fwrite(STDERR, 'hilos-test-phase: ' . $line . PHP_EOL);
        } else {
            // warning-suppressed: an unavailable artifact only loses this observation
            $written = @file_put_contents($path, $line . PHP_EOL, FILE_APPEND);
        }
        if ($written === false) {
            return $at;
        }
    } catch (Throwable) {
        // Diagnostics must not replace a command's result, including during teardown.
    }

    return $at;
}

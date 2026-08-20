<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good;

use RuntimeException;

/**
 * Negative sample: class D of error-suppression.md, the deliberate degrade and the
 * teardown step, stays legal under FS-SEAM. None of the calls below opens a file, and
 * none of their results is turned into an exception — they are dropped unexamined, or
 * they become `null`. The stream primitive at the end is not judged at all: it works
 * over a descriptor rather than a path, which is where class B lives.
 */
final class FsSeamLookAlikes
{
    /**
     * The teardown shape: the suppressed removal is a best-effort step inside a catch,
     * and what travels on is the caught failure, not the result of the removal.
     *
     * @param string $path Path of the half-written file
     * @throws RuntimeException Whatever the write raised, unchanged
     */
    public function dropHalfWritten(string $path): void
    {
        try {
            throw new RuntimeException("Cannot write: {$path}");
        } catch (RuntimeException $failure) {
            // warning-suppressed: the leftover is dropped best-effort, no-op when it resists
            @unlink($path);

            throw $failure;
        }
    }

    /**
     * @return string|null Contents of the kernel file, or null where there is no /proc
     */
    public function loadAverage(): ?string
    {
        // warning-suppressed: /proc is absent off Linux, the caller reads null as "unknown"
        $stat = @file_get_contents('/proc/loadavg');
        if ($stat === false) {
            return null;
        }

        return $stat;
    }

    /**
     * @param string $path Path of the sidecar that may not have been written yet
     * @return int|null Size in bytes, or null while the sidecar is absent
     */
    public function sidecarSize(string $path): ?int
    {
        // warning-suppressed: a sidecar that is not there yet has no size, and the caller says so
        $size = @filesize($path . '.meta');

        return $size === false ? null : $size;
    }

    /**
     * @param resource $socket Non-blocking socket the peer may not have written to yet
     * @return string|null Chunk read, or null when there was nothing to read this tick
     */
    public function readChunk($socket): ?string
    {
        // warning-suppressed: an empty non-blocking read is normal traffic, it becomes null below
        $chunk = @fread($socket, 8192);
        if ($chunk === false || $chunk === '') {
            return null;
        }

        return $chunk;
    }
}

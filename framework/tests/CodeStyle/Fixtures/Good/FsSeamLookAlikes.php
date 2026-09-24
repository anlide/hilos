<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good;

use RuntimeException;

/**
 * Negative sample: class D of error-suppression.md, the deliberate degrade and the
 * teardown step, stays legal under FS-SEAM. None of the suppressed calls below opens a
 * file, and none of their results is turned into an exception — they are dropped
 * unexamined, or they become `null`. The stream primitive among them is not judged at
 * all: it works over a descriptor rather than a path, which is where class B lives.
 *
 * The unsuppressed calls further down are the look-alikes of the third sign: a silent
 * primitive checked, a call nobody tests, a result passed on as data before it is
 * tested, a method and a static member wearing a primitive's name, a primitive returned
 * from an arrow function, and a marked suppression over a kin of the list.
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

    /**
     * The class D shape written with the suppression over the whole assignment. Sign 2
     * now DOES walk this far — it did not before the anchor was normalized — and has to
     * stay silent all the same, because the statement after it is a `return` rather than
     * an `if` that throws.
     *
     * @param string $path Path of the sidecar that may not have been written yet
     * @return int|null Size in bytes, or null while the sidecar is absent
     */
    public function sidecarSizeUnderWholeStatementSuppression(string $path): ?int
    {
        // warning-suppressed: a sidecar that is not there yet has no size, and the caller says so
        @$size = filesize($path . '.meta');

        return $size === false ? null : $size;
    }

    /**
     * A silent primitive: realpath() answers false with no warning, so the check after
     * it is alive and the third sign leaves it alone.
     *
     * @param string $path Path to canonicalize
     * @return string|null Canonical path, or null when it does not resolve
     */
    public function canonical(string $path): ?string
    {
        $real = realpath($path);
        if ($real === false) {
            return null;
        }

        return $real;
    }

    /**
     * The other silent primitive, in the `?:` shape the sign reads everywhere else.
     *
     * @param string $pattern Glob pattern
     * @return list<string> Matches, or nothing for a glob that failed
     */
    public function matching(string $pattern): array
    {
        return glob($pattern) ?: [];
    }

    /**
     * A call nobody tests is not the third sign: its failure ends the process by the
     * policy, which is a different defect.
     *
     * @param string $path File to touch
     */
    public function untested(string $path): void
    {
        touch($path);
    }

    /**
     * A result that travels on as data first is not tested: the first read is an
     * argument, and the check that follows it is not the first read.
     *
     * @param string $path File to measure
     * @return bool Whether the size was known
     */
    public function passedOnAsDataFirst(string $path): bool
    {
        $size = filesize($path);
        $this->record($size);
        if ($size === false) {
            return false;
        }

        return true;
    }

    /**
     * A static member and a method wearing a primitive's name are not calls of it.
     *
     * @param string $dir Directory to make and drop
     * @return bool Whether it is gone
     */
    public function namesakes(string $dir): bool
    {
        if (!self::mkdir($dir)) {
            return false;
        }

        return $this->unlink($dir);
    }

    /**
     * A primitive returned from an arrow function is tested by nobody here.
     *
     * @param string $path File to remove later
     * @return callable(): bool The removal, for whoever runs it
     */
    public function deferred(string $path): callable
    {
        return static fn (): bool => unlink($path);
    }

    /**
     * A marked suppression over a kin of the list is class D as it always was: the
     * result becomes null, and the third sign never looks at a suppressed call.
     *
     * @param string $path File whose modification time is asked for
     * @return int|null Modification time, or null when the file is not there
     */
    public function modifiedAt(string $path): ?int
    {
        // warning-suppressed: a path that is gone has no stat, the caller reads null as "unknown"
        $stat = @stat($path);

        return $stat === false ? null : $stat['mtime'];
    }

    /**
     * @param int|false $size Size, or false when unknown
     */
    private function record(int|false $size): void
    {
    }

    /**
     * @param string $dir Directory to make
     * @return bool Whether it is there
     */
    private static function mkdir(string $dir): bool
    {
        return is_dir($dir);
    }

    /**
     * @param string $path Path to drop
     * @return bool Whether it is gone
     */
    private function unlink(string $path): bool
    {
        return !is_file($path);
    }
}

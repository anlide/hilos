<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad;

use RuntimeException;

/**
 * Deliberately broken sample for the third sign of FS-SEAM: every call below is a path
 * primitive written WITHOUT `@`, so ERROR-SUPPRESSION has nothing to say about it, and
 * each has its false result tested in one of the shapes the sign reads. Inside a Hilos
 * process the warning the primitive raises ends the process before the tested branch
 * runs — the branch is dead, whatever it does.
 *
 * One hit per shape: a negation, `=== false` on either side, `?:`, a ternary condition,
 * a bare `if`, a negation and a bare call inside a chain, a bare `while`, and an
 * assigned result whose first read — two statements later, or in a `return` — is such
 * a test.
 */
final class FsSeamUncheckedSamples
{
    /**
     * @param string $dir Directory the caller insists on creating
     * @throws RuntimeException When the directory cannot be created — a throw no process reaches
     */
    public function negated(string $dir): void
    {
        if (!mkdir($dir, 0700, true)) {
            throw new RuntimeException("Cannot create: {$dir}");
        }
    }

    /**
     * @param string $path File written without the seam
     * @param string $data Payload
     * @return bool Whether the write went through, by a check that never says no
     */
    public function comparedOnTheRight(string $path, string $data): bool
    {
        if (file_put_contents($path, $data) === false) {
            return false;
        }

        return true;
    }

    /**
     * @param string $path File opened without the seam
     * @return bool Whether the file opened, by a check that never says no
     */
    public function comparedOnTheLeft(string $path): bool
    {
        if (false === fopen($path, 'rb')) {
            return false;
        }

        return true;
    }

    /**
     * @param string $dir Directory to list
     * @return list<string> Entries, or what `?: []` promises for a listing that failed
     */
    public function elvis(string $dir): array
    {
        return scandir($dir) ?: [];
    }

    /**
     * @param string $path File to measure
     * @return string Which branch a failed size would take — never the second
     */
    public function ternary(string $path): string
    {
        return filesize($path) ? 'sized' : 'unsized';
    }

    /**
     * @param string $path File the caller removes
     * @return string What a failed removal would report — never 'kept'
     */
    public function bareCondition(string $path): string
    {
        if (unlink($path)) {
            return 'removed';
        }

        return 'kept';
    }

    /**
     * @param string $dir Directory the caller creates when absent
     * @throws RuntimeException When the directory cannot be created — a throw no process reaches
     */
    public function negatedInAChain(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir)) {
            throw new RuntimeException("Cannot create: {$dir}");
        }
    }

    /**
     * @param string $dir Directory the caller creates when absent
     * @return bool Whether the directory is there, by a check that never says no
     */
    public function bareInAChain(string $dir): bool
    {
        if (is_dir($dir) || mkdir($dir)) {
            return true;
        }

        return false;
    }

    /**
     * @param string $path File the caller keeps touching until told to stop
     */
    public function bareWhile(string $path): void
    {
        while (touch($path) && $this->again()) {
            usleep(10);
        }
    }

    /**
     * @param string $path File opened without the seam
     * @return int Bytes read — a check two statements after the open never says no
     * @throws RuntimeException When the file cannot be opened — a throw no process reaches
     */
    public function readTwoStatementsLater(string $path): int
    {
        $handle = fopen($path, 'rb');
        $read = 0;
        if ($handle === false) {
            throw new RuntimeException("Cannot open: {$path}");
        }
        fclose($handle);

        return $read;
    }

    /**
     * @param string $path File to measure
     * @return int|null Size, or the null a failed measure would answer — never
     */
    public function readInAReturn(string $path): ?int
    {
        $size = filesize($path);

        return $size === false ? null : $size;
    }

    /**
     * @return bool Whether to try again
     */
    private function again(): bool
    {
        return false;
    }
}

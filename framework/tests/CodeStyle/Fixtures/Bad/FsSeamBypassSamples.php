<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad;

use RuntimeException;

/**
 * Deliberately broken sample: both calls below carry a correct, non-empty marker and
 * are therefore legal by ERROR-SUPPRESSION, which is the whole point — a marker no
 * longer buys a file primitive the right to owe an exception outside the seam.
 *
 * The first goes down by sign 1 (a file is opened under `@`), the next two by sign 2
 * (a suppressed path primitive whose checked failure becomes a throw) — one of them
 * brace-less; the last two repeat both signs with the `@` over the whole assignment.
 */
final class FsSeamBypassSamples
{
    /**
     * @param string $path Path opened and read without the seam
     * @return string Contents this class was not allowed to fetch this way
     * @throws RuntimeException When the file cannot be opened or read
     */
    public function read(string $path): string
    {
        // warning-suppressed: false becomes RuntimeException on the next line
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open: {$path}");
        }
        fclose($handle);

        // warning-suppressed: false becomes RuntimeException on the next line
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Cannot read: {$path}");
        }

        return $contents;
    }

    /**
     * @param string $path Path of the file the caller insists on removing
     * @throws RuntimeException When the file resists removal
     */
    public function drop(string $path): void
    {
        // warning-suppressed: false becomes RuntimeException on the same line
        if (!@unlink($path)) throw new RuntimeException("Cannot delete: {$path}");
    }

    /**
     * The same open as read(), with the suppression moved one token left. Sign 1 has to
     * fire here too: the shape the guard used to walk past is the reason this leaf exists.
     *
     * @param string $path Path opened without the seam, under a suppression covering the assignment
     */
    public function openUnderWholeStatementSuppression(string $path): void
    {
        // warning-suppressed: the handle is closed on the next line either way
        @$handle = fopen($path, 'rb');
        fclose($handle);
    }

    /**
     * Sign 2 under the same spelling — the half a reader-only fix would have left open,
     * since the walk back to the assignment starts at the call rather than at the `@`.
     *
     * @param string $path Path of the file the caller insists on removing
     * @throws RuntimeException When the file resists removal
     */
    public function dropUnderWholeStatementSuppression(string $path): void
    {
        // warning-suppressed: false becomes RuntimeException on the next line
        @$ok = unlink($path);
        if (!$ok) {
            throw new RuntimeException("Cannot delete: {$path}");
        }
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Bad;

/**
 * Deliberately broken sample: none of the suppressed calls below is covered by a
 * usable marker, so ERROR-SUPPRESSION must report every one of them.
 */
final class ErrorSuppressionSamples
{
    /**
     * @param string $path Path handled without any error contract
     * @param string $target Path the file is moved to
     * @return string Whatever survived the suppressed calls
     */
    public function shuffle(string $path, string $target): string
    {
        @unlink($path . '.lock');

        $contents = @file_get_contents($path);

        // warning-suppressed:
        @rename($path, $target);

        // warning-suppressed: two lines up is not directly above the call

        $size = @filesize($target);

        return $contents === false ? (string)$size : $contents;
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle\Fixtures\Good\Fs;

use RuntimeException;

/**
 * Negative sample carrying the seam's own path, repeated here the way the empty-string
 * fixtures repeat the segments of the real zone: the rule matches the tail `Fs/FsPath.php`,
 * so a fixture spelled like it is judged as the seam and has to stay silent.
 *
 * The body is the very code sign 1 reports one directory up — a suppressed `fopen` under
 * a marker whose next line throws. Inside the seam that is not a bypass but the definition
 * of it: this is the one place allowed to turn a failing primitive into an exception.
 */
final class FsPath
{
    /**
     * @param string $path Absolute file path
     * @return string First line of the file
     * @throws RuntimeException If the file cannot be opened or holds no line
     */
    public static function firstLine(string $path): string
    {
        // warning-suppressed: false becomes RuntimeException on the next line
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open: {$path}");
        }

        $line = fgets($handle);
        fclose($handle);
        if ($line === false) {
            throw new RuntimeException("Cannot read: {$path}");
        }

        return $line;
    }
}

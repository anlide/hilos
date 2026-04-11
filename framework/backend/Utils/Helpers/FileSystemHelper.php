<?php

declare(strict_types=1);

namespace Hilos\Utils\Helpers;

use Hilos\Utils\Helpers\Exception\ScandirWarningException;
use Throwable;

/**
 * FileSystemHelper - small filesystem utilities.
 *
 * @package Hilos\Utils\Helpers
 */
final class FileSystemHelper
{
    /**
     * List directory entries like {@see scandir()} without `@` on failure.
     *
     * E_WARNING / E_USER_WARNING from PHP are turned into {@see ScandirWarningException} inside a
     * temporary handler, then mapped to `false`. Other severities are passed to the previous handler.
     * On success, returns the same list as `scandir()`.
     *
     * @param string $path Absolute directory path
     * @return list<string>|false Directory entries, or false if scandir failed or a warning was raised
     */
    public static function scandirOrFalse(string $path): array|false
    {
        $previous = null;
        $previous = set_error_handler(
            static function (int $severity, string $message, string $errFile, int $errLine) use (&$previous): bool {
                if ($severity === E_WARNING || $severity === E_USER_WARNING) {
                    throw new ScandirWarningException($message, $severity, $errFile, $errLine);
                }
                if ($previous !== null) {
                    return $previous($severity, $message, $errFile, $errLine);
                }
                return false;
            }
        );
        try {
            $result = scandir($path);
            if ($result === false) {
                return false;
            }
            return $result;
        } catch (Throwable) {
            return false;
        } finally {
            set_error_handler($previous);
        }
    }

    /**
     * Normalize a client-provided filename to a lowercase basename.
     *
     * Used for duplicate checks where path segments, null bytes, and surrounding
     * whitespace must not affect collision detection.
     *
     * @param string $name Original filename or path segment from the client
     * @return string Normalized basename
     */
    public static function normalizeBasename(string $name): string
    {
        $base = basename(str_replace(["\0"], '', $name));

        return strtolower(trim($base));
    }
}

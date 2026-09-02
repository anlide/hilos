<?php

declare(strict_types=1);

namespace Hilos\Utils\Helpers;

use Hilos\Log\LogArchivePruner;

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
     * E_WARNING / E_USER_WARNING from PHP are captured by a temporary handler and mapped to `false`.
     * Other severities are passed to the previous handler. On success, returns the same list as `scandir()`.
     *
     * @param string $path Absolute directory path
     * @return list<string>|false Directory entries, or false if scandir failed or a warning was raised
     */
    public static function scandirOrFalse(string $path): array|false
    {
        $entries = self::warningAsFalse(static fn (): array|false => scandir($path));

        return is_array($entries) ? $entries : false;
    }

    /**
     * Delete a file like {@see unlink()} without `@` on failure.
     *
     * The outcome is the return value rather than an exception because the one caller that needs
     * this — the log archive cleanup ({@see LogArchivePruner}) — is best-effort and names every
     * path it could not remove in its own report, which is exactly what a suppression would hide.
     *
     * @param string $path Absolute file path
     * @return bool True when the file is gone, false when it could not be removed
     */
    public static function unlinkOrFalse(string $path): bool
    {
        return self::warningAsFalse(static fn (): bool => unlink($path)) === true;
    }

    /**
     * Remove an empty directory like {@see rmdir()} without `@` on failure.
     *
     * @param string $path Absolute directory path
     * @return bool True when the directory is gone, false when it is not empty or could not be removed
     */
    public static function rmdirOrFalse(string $path): bool
    {
        return self::warningAsFalse(static fn (): bool => rmdir($path)) === true;
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

    /**
     * Run one filesystem builtin with its warning captured instead of suppressed.
     *
     * E_WARNING / E_USER_WARNING raised by the call are caught by a temporary handler and turn the
     * whole call into `false`; every other severity goes on to the handler that was in place. This
     * is what the `*OrFalse` wrappers above have instead of `@`: the failure stays a value the
     * caller reads rather than a message nobody sees.
     *
     * @param callable(): (array<int, string>|bool) $call Builtin invocation to run under the handler
     * @return array<int, string>|bool What the builtin returned, or false when it raised a warning
     */
    private static function warningAsFalse(callable $call): array|bool
    {
        $warningRaised = false;
        $previous = null;
        $previous = set_error_handler(
            static function (int $severity, string $message, string $errFile, int $errLine) use (&$previous, &$warningRaised): bool {
                if ($severity === E_WARNING || $severity === E_USER_WARNING) {
                    $warningRaised = true;

                    return true;
                }
                if ($previous !== null) {
                    return $previous($severity, $message, $errFile, $errLine);
                }

                return false;
            }
        );
        try {
            $result = $call();

            return $warningRaised ? false : $result;
        } finally {
            restore_error_handler();
        }
    }
}

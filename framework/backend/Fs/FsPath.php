<?php

declare(strict_types=1);

namespace Hilos\Fs;

use Hilos\Fs\Context\FsContext;
use Hilos\Fs\Exception\DirectoryCreateException;
use Hilos\Fs\Exception\FileDeleteException;
use Hilos\Fs\Exception\FileMoveException;
use Hilos\Fs\Exception\FileNotFoundException;
use Hilos\Fs\Exception\FilePermissionException;
use Hilos\Fs\Exception\FileReadException;
use Hilos\Fs\Exception\FileWriteException;

/**
 * Context-free file primitives addressed by absolute path.
 *
 * This is the only place in `framework/backend` that turns a failing file
 * primitive into a typed `Fs/Exception/*`: every suppression here carries its
 * marker, and the next line raises the exception. Callers use the primitive and
 * catch the exception — they never suppress a failing write themselves. A
 * deliberate degrade or a teardown step (class D of the error-suppression rule)
 * is not covered by this layer and stays marked at its own call site.
 *
 * Static because a path primitive has no state, and the named-directory registry
 * behind {@see FsContext} cannot address paths that are not registered in
 * it (backup scopes, per-pid work directories).
 */
final class FsPath
{
    /**
     * @param string $path Absolute file path
     * @return string File contents
     *
     * @throws FileNotFoundException If the file does not exist
     * @throws FileReadException If the file exists but cannot be read
     */
    public static function read(string $path): string
    {
        if (!is_file($path)) {
            throw new FileNotFoundException("File not found: {$path}");
        }
        // warning-suppressed: false becomes FileReadException on the next line
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new FileReadException("Cannot read file: {$path}");
        }

        return $content;
    }

    /**
     * Read the file line by line, holding no more than one line at a time.
     *
     * The pair of {@see read()} for a file too large to bring into memory: the
     * handle is opened and closed here, and the caller sees only strings. Each
     * line is yielded exactly as `fgets()` returned it, trailing line ending
     * included, because what the text means belongs to the reader.
     *
     * A generator body does not run until the first iteration, so both exceptions
     * below arrive on the `foreach`, not on the call that creates it — the caller
     * puts the `foreach` inside its `try`, not the assignment.
     *
     * @param string $path Absolute file path
     * @return iterable<int, string> File lines in file order, each with its trailing line ending
     *
     * @throws FileNotFoundException If the file does not exist
     * @throws FileReadException If the file cannot be opened, or the stream breaks before its end
     */
    public static function readLines(string $path): iterable
    {
        if (!is_file($path)) {
            throw new FileNotFoundException("File not found: {$path}");
        }
        // warning-suppressed: false becomes FileReadException on the next line
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new FileReadException("Cannot read file: {$path}");
        }

        try {
            while (($line = fgets($handle)) !== false) {
                yield $line;
            }
            if (!feof($handle)) {
                throw new FileReadException("Cannot read file to its end: {$path}");
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Overwrite the file with the payload, creating it when absent.
     *
     * @param string $path Absolute file path
     * @param string $data Binary payload
     *
     * @throws FileWriteException If the write fails
     */
    public static function write(string $path, string $data): void
    {
        // warning-suppressed: false becomes FileWriteException on the next line
        if (@file_put_contents($path, $data) === false) {
            throw new FileWriteException("Cannot write file: {$path}");
        }
    }

    /**
     * @param string $path Absolute file path
     * @param string $data Binary payload to append
     *
     * @throws FileWriteException If the append fails
     */
    public static function append(string $path, string $data): void
    {
        // warning-suppressed: false becomes FileWriteException on the next line
        if (@file_put_contents($path, $data, FILE_APPEND) === false) {
            throw new FileWriteException("Cannot append to file: {$path}");
        }
    }

    /**
     * @param string $sourcePath Absolute source path
     * @param string $targetPath Absolute target path
     *
     * @throws FileMoveException If the rename fails
     */
    public static function move(string $sourcePath, string $targetPath): void
    {
        // warning-suppressed: false becomes FileMoveException on the next line
        if (!@rename($sourcePath, $targetPath)) {
            throw new FileMoveException("Cannot move {$sourcePath} to {$targetPath}");
        }
    }

    /**
     * Copy a file, overwriting whatever is at the target.
     *
     * The primitive next to {@see move()} for the case a rename cannot serve: a target on another
     * device. Overwriting is deliberate — a caller repeating a copy that was interrupted has to be
     * able to write the whole file again over the part of it that arrived.
     *
     * @param string $sourcePath Absolute source path
     * @param string $targetPath Absolute target path
     *
     * @throws FileWriteException If the copy fails
     */
    public static function copy(string $sourcePath, string $targetPath): void
    {
        // warning-suppressed: false becomes FileWriteException on the next line
        if (!@copy($sourcePath, $targetPath)) {
            throw new FileWriteException("Cannot copy {$sourcePath} to {$targetPath}");
        }
    }

    /**
     * Move a finished temp file into its final place, so readers never see a partial write.
     *
     * The temp file is fsynced first; that part is best-effort, because a failing
     * fsync is a durability question and not a reason to abandon a written file.
     * A failing move is fatal, and the temp file is removed before it travels on.
     *
     * @param string $tmpPath Absolute path of the temp file, on the same filesystem as the target
     * @param string $finalPath Absolute target path
     *
     * @throws FileMoveException If the rename fails
     */
    public static function publish(string $tmpPath, string $finalPath): void
    {
        // warning-suppressed: an unopenable temp file is published without the fsync
        $handle = @fopen($tmpPath, 'r');
        if ($handle !== false) {
            // warning-suppressed: a failed flush leaves the durability best-effort, publication goes on
            @fflush($handle);
            // warning-suppressed: a failed fsync leaves the durability best-effort, publication goes on
            @fsync($handle);
            // warning-suppressed: the handle is dropped either way, nothing reads it after this line
            @fclose($handle);
        }

        try {
            self::move($tmpPath, $finalPath);
        } catch (FileMoveException $move) {
            try {
                self::delete($tmpPath);
            } catch (FileDeleteException) {
                // best-effort cleanup: the move failure is what the caller is told about
            }

            throw $move;
        }
    }

    /**
     * Create an empty temp file in the system temp directory and return its path.
     *
     * The mode is applied before the path is returned, so a caller writing a
     * secret into the file cannot leave it readable for the time between the two
     * calls. A file whose mode could not be set is removed instead of returned.
     *
     * @param string $prefix Basename prefix for the generated file
     * @param int|null $mode Permission mode to apply before returning, or null to keep the default
     * @return string Absolute path of the created file
     *
     * @throws FilePermissionException If the mode cannot be applied
     * @throws FileWriteException If the file cannot be created
     */
    public static function createTempFile(string $prefix, ?int $mode = null): string
    {
        // warning-suppressed: false becomes FileWriteException on the next line
        $path = @tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            throw new FileWriteException("Cannot create temp file with prefix: {$prefix}");
        }
        if ($mode === null) {
            return $path;
        }

        try {
            self::chmod($path, $mode);
        } catch (FilePermissionException $permission) {
            try {
                self::delete($path);
            } catch (FileDeleteException) {
                // best-effort cleanup: the mode failure is what the caller is told about
            }

            throw $permission;
        }

        return $path;
    }

    /**
     * @param string $path Absolute file path
     * @param int $mode Permission mode
     *
     * @throws FilePermissionException If the mode cannot be applied
     */
    public static function chmod(string $path, int $mode): void
    {
        // warning-suppressed: false becomes FilePermissionException on the next line
        if (!@chmod($path, $mode)) {
            throw new FilePermissionException("Cannot change mode of file: {$path}");
        }
    }

    /**
     * Create the directory (recursively) when missing.
     *
     * @param string $path Absolute directory path
     * @param int $mode Permission mode for created directories
     *
     * @throws DirectoryCreateException If the directory does not exist and cannot be created
     */
    public static function ensureDirectory(string $path, int $mode = 0775): void
    {
        if (is_dir($path)) {
            return;
        }
        // warning-suppressed: a lost race is caught by the is_dir() re-check on the same line
        if (!@mkdir($path, $mode, true) && !is_dir($path)) {
            throw new DirectoryCreateException("Cannot create directory: {$path}");
        }
    }

    /**
     * @param string $path Absolute file path
     * @return int Size in bytes
     *
     * @throws FileNotFoundException If the file does not exist
     * @throws FileReadException If the size cannot be read
     */
    public static function size(string $path): int
    {
        if (!is_file($path)) {
            throw new FileNotFoundException("File not found: {$path}");
        }
        // warning-suppressed: false becomes FileReadException on the next line
        $size = @filesize($path);
        if ($size === false) {
            throw new FileReadException("Cannot read size of file: {$path}");
        }

        return $size;
    }

    /**
     * Delete the file (no-op when already absent).
     *
     * @param string $path Absolute file path
     *
     * @throws FileDeleteException If the file exists but cannot be removed
     */
    public static function delete(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        // warning-suppressed: false becomes FileDeleteException on the next line
        if (!@unlink($path)) {
            throw new FileDeleteException("Cannot delete file: {$path}");
        }
    }
}

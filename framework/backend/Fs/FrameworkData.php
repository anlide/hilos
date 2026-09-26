<?php

declare(strict_types=1);

namespace Hilos\Fs;

use Hilos\Fs\Exception\DirectoryNotFoundException;
use Hilos\Fs\Exception\FileNotFoundException;
use Hilos\Fs\Exception\FileReadException;
use Hilos\Fs\Exception\LfsPointerException;

/**
 * Locates framework-owned data and refuses unmaterialized Git LFS pointers (HIL-650).
 */
final class FrameworkData
{
    public const string DIRECTORY = __DIR__ . '/../../data';

    private const string LFS_POINTER_PREFIX = 'version https://git-lfs.github.com/spec/';

    /**
     * @param string $name Framework-authored file name relative to the data directory
     * @return string Absolute path of the materialized file
     * @throws FileNotFoundException When the named file is absent
     * @throws LfsPointerException When Git LFS has not materialized the file
     * @throws FsException When the file cannot be read
     */
    public static function path(string $name): string
    {
        $path = self::DIRECTORY . '/' . $name;
        self::assertFileMaterialized($path);

        return $path;
    }

    /**
     * Checks every file, including data in nested directories, before a process starts.
     *
     * @param string $directory Absolute data directory to check
     * @throws DirectoryNotFoundException When the directory is absent
     * @throws LfsPointerException When any file is still a Git LFS pointer
     * @throws FsException When a directory or file cannot be read
     */
    public static function assertMaterialized(string $directory = self::DIRECTORY): void
    {
        foreach (FsPath::entries($directory) as $name) {
            $path = $directory . '/' . $name;
            if (is_dir($path)) {
                self::assertMaterialized($path);
            } else {
                self::assertFileMaterialized($path);
            }
        }
    }

    /**
     * Reads only the pointer prefix, regardless of the size of the data file.
     *
     * @param string $path Absolute data file path
     * @throws LfsPointerException When the file contains a Git LFS pointer
     * @throws FsException When the file is missing or its head cannot be read
     */
    private static function assertFileMaterialized(string $path): void
    {
        $head = FsPath::readWith($path, static function ($handle) use ($path): string {
            $head = fread($handle, strlen(self::LFS_POINTER_PREFIX));
            if ($head === false) {
                throw new FileReadException("Cannot read file head: {$path}");
            }

            return $head;
        });

        if ($head === self::LFS_POINTER_PREFIX) {
            throw new LfsPointerException(
                "{$path} is a Git LFS pointer, not the file: install git-lfs and run `git lfs pull`"
                . ' (a package archive must include its Git LFS objects)',
            );
        }
    }
}

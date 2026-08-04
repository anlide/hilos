<?php

declare(strict_types=1);

namespace Hilos\Tests\CodeStyle;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Walks one source root and yields the PHP files a code-style rule should read.
 *
 * A missing root is skipped silently: the framework ships as a package without
 * the demos, so their roots are optional rather than a configuration error.
 */
final class SourceScanner
{
    /**
     * Directory names that never hold project sources, pruned in every root.
     * Everything else is excluded by exact path only: a rule that skips whatever
     * happens to carry a given name would silently stop checking the day a suite
     * names a real source directory that way.
     */
    public const array PRUNED_DIRECTORIES = ['vendor', 'node_modules', '.git'];

    private readonly string $root;

    /** @var array<int, string> Absolute paths, normalized, never descended into */
    private readonly array $excludedPaths;

    /**
     * @param string $root Absolute path of the directory to scan
     * @param array<int, string> $excludedPaths Paths relative to the root to leave out
     */
    public function __construct(string $root, array $excludedPaths = [])
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
        $this->excludedPaths = array_map(
            fn(string $path): string => $this->root . '/' . trim(str_replace('\\', '/', $path), '/'),
            $excludedPaths,
        );
    }

    /**
     * @return string Normalized absolute path of the scanned root
     */
    public function root(): string
    {
        return $this->root;
    }

    /**
     * @return iterable<SplFileInfo> Every PHP file under the root, excluded directories pruned
     */
    public function files(): iterable
    {
        if (!is_dir($this->root)) {
            return;
        }

        $filtered = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            fn(SplFileInfo $current): bool => $current->isDir()
                ? $this->isScannable($current)
                : $current->getExtension() === 'php',
        );

        foreach (new RecursiveIteratorIterator($filtered) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                yield $file;
            }
        }
    }

    /**
     * @param SplFileInfo $file File produced by this scanner
     * @return string Path of the file relative to the scanned root
     */
    public function relativePath(SplFileInfo $file): string
    {
        return substr(str_replace('\\', '/', $file->getPathname()), strlen($this->root) + 1);
    }

    /**
     * @param SplFileInfo $directory Directory the walk is about to descend into
     * @return bool True when the directory may hold sources to check
     */
    private function isScannable(SplFileInfo $directory): bool
    {
        return !in_array($directory->getFilename(), self::PRUNED_DIRECTORIES, true)
            && !in_array(str_replace('\\', '/', $directory->getPathname()), $this->excludedPaths, true);
    }
}

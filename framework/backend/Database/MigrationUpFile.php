<?php

declare(strict_types=1);

namespace Hilos\Database;

use Hilos\Fs\FsException;
use Hilos\Fs\FsPath;

/** One file read for execution or for the evidence of a restore invocation. */
final readonly class MigrationUpFile
{
    /**
     * @param int $index Numeric UP prefix
     * @param string $path Exact source path
     * @param string $content Original bytes used to calculate the digest
     * @param string $digest SHA-256 of those bytes
     * @param list<string> $statements Execution units after journal substitution
     */
    private function __construct(
        public int $index,
        public string $path,
        public string $content,
        public string $digest,
        public array $statements,
    ) {
    }

    /**
     * @param int $index Numeric UP prefix
     * @param string $path Exact source path
     * @return self Read-only file facts
     * @throws DatabaseException When the file or journal substitution cannot be read
     */
    public static function read(int $index, string $path): self
    {
        try {
            $content = FsPath::read($path);
        } catch (FsException $failure) {
            throw new DatabaseException("Failed to read migration file: {$path}", 0, $failure);
        }
        return new self($index, $path, $content, hash('sha256', $content), MigrationSqlReader::statements($content));
    }
}

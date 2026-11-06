<?php

declare(strict_types=1);

namespace Hilos\Database;

/** File evidence private to a single restore invocation; no worker or node shares it. */
final readonly class MigrationUpTrack
{
    /**
     * @param string $path Configured track path
     * @param string $name Configured track name for the migrator's diagnostics
     * @param list<string> $fileNames Complete directory listing, including down files
     * @param list<MigrationUpFile> $files UP files in the migrator's numeric order
     */
    public function __construct(public string $path, public string $name, public array $fileNames, public array $files)
    {
    }

    /**
     * @param int $level Proven archive level, never a fallback for absent history
     * @return list<MigrationUpFile> Same UP queue that migrateUp selects above that level
     */
    public function after(int $level): array
    {
        return array_values(array_filter($this->files, static fn (MigrationUpFile $file): bool => $file->index > $level));
    }

    /**
     * @throws DatabaseException When sources, configured track or resolved SQL have changed since this read
     */
    public function assertUnchanged(): void
    {
        if (Migration::readUpTrack() != $this) {
            throw new DatabaseException('The migration track changed after full restore preparation');
        }
    }
}

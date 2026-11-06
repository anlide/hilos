<?php

declare(strict_types=1);

namespace Hilos\Backup\FullRestore;

/** Schema-sized metadata only; table data stays in the extracted file. */
final readonly class Dump
{
    /**
     * @param array<string, DumpObject> $objects Final declarations keyed by exact object name
     * @param MigrationHistory $migrationHistory Explicit migration history, never an inferred level
     */
    public function __construct(public array $objects, public MigrationHistory $migrationHistory)
    {
    }
}

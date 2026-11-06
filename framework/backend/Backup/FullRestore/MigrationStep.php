<?php

declare(strict_types=1);

namespace Hilos\Backup\FullRestore;

use Hilos\Database\MigrationUpFile;

/** Ordered operation with the file and exact migration row needed to establish its provenance. */
final readonly class MigrationStep
{
    /**
     * @param MigrationUpFile $file Exact source and digest
     * @param int $statementIndex Zero-based execution-unit position in that file
     * @param MigrationOperation $operation Admitted operation or explicit uncertainty
     */
    public function __construct(public MigrationUpFile $file, public int $statementIndex, public MigrationOperation $operation)
    {
    }
}

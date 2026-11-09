<?php

declare(strict_types=1);

namespace Hilos\Backup\FullRestore;

final readonly class MigrationTargetEvidence
{
    /**
     * @param MigrationBinding $binding History source and physical execution schema
     * @param MigrationFacts $facts Archive and target migration rows
     * @param list<MigrationStep> $pending Ordered UP effects after the proven archive level, or the whole track when unknown
     */
    public function __construct(public MigrationBinding $binding, public MigrationFacts $facts, public array $pending)
    {
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Database\Migration;

/** A private scan snapshot, including legacy files that only reserve a number. */
final readonly class FrameworkMigrationTrackEntry
{
    /**
     * @param int $index Project migration number
     * @param ?string $upName Up basename, absent for a down-only entry
     * @param ?string $downName Down basename, absent for an up-only entry
     * @param ?string $upSql Canonical up text, absent with its path
     * @param ?string $downSql Canonical down text, absent with its path
     * @param ?FrameworkMigrationOrigin $origin Verified complete provenance, absent for legacy or partial pairs
     */
    public function __construct(
        public int $index,
        public ?string $upName,
        public ?string $downName,
        public ?string $upSql,
        public ?string $downSql,
        public ?FrameworkMigrationOrigin $origin,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Database\Identity;

/** A sign-in method's portable metadata, excluding its authentication secret. */
final readonly class IdentityExportEntry
{
    /**
     * @param string $type Identity method
     * @param ?string $identifier Public handle, absent for a password
     * @param ?string $provider Provider name, when applicable
     * @param bool $verified Whether the handle is confirmed
     * @param string $createdAt SQL creation time, read from the DB-only stamp
     */
    public function __construct(
        public string $type,
        public ?string $identifier,
        public ?string $provider,
        public bool $verified,
        public string $createdAt,
    ) {
    }
}

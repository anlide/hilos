<?php

declare(strict_types=1);

namespace Hilos\Backup;

/**
 * BackupScope - what a backup archive captures.
 *
 * The scope also names the storage subdirectory under the backup root:
 * `<root>/<scope>/...`. Values are stable wire/storage strings.
 */
enum BackupScope: string
{
    /** Full dump: schema, seed reference data, and all rows. */
    case FULL = 'full';

    /** Schema plus seed reference data, without bulk rows. */
    case SCHEMA_SEED = 'schema-seed';

    /** Schema only, no data. */
    case SCHEMA_ONLY = 'schema-only';

    /**
     * Parses a stored scope value, tolerating unknown/empty input.
     *
     * @param ?string $value Raw scope value
     * @return ?self Matched scope or null when unrecognized
     */
    public static function fromString(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::tryFrom($value);
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Database\Migration;

use Hilos\Database\DatabaseException;
use Hilos\Database\MigrationTrackCheck;

/** A complete, canonical source pair whose identity is independent of project migration numbers. */
final readonly class FrameworkMigrationPair
{
    public string $upSql;
    public string $downSql;

    /**
     * @param string $name Filename stem without number or direction
     * @param FrameworkMigrationOrigin $origin Exact source provenance
     * @param string $upBody Up SQL without an origin line
     * @param string $downBody Down SQL without an origin line
     * @throws DatabaseException When the stem, provenance or body hashes are invalid
     */
    public function __construct(
        public string $name,
        public FrameworkMigrationOrigin $origin,
        string $upBody,
        string $downBody,
    ) {
        if (
            preg_match('/\A[a-z][a-zA-Z0-9_-]*\z/D', $name) !== 1
            || preg_match(MigrationTrackCheck::UP_FILE_PATTERN, '1_' . $name . '.sql') !== 1
        ) {
            throw new DatabaseException("Invalid framework migration filename stem {$name}");
        }
        $header = $origin->header() . "\n";
        $this->upSql = $header . FrameworkMigrationOrigin::canonicalSql($upBody);
        $this->downSql = $header . FrameworkMigrationOrigin::canonicalSql($downBody);
        FrameworkMigrationOrigin::parsePair($this->upSql, $this->downSql);
    }
}

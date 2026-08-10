<?php

declare(strict_types=1);

namespace Hilos\Backup;

use Hilos\BaseDTO;

/**
 * BackupConnectionMeta - one database connection captured in a backup.
 *
 * A backup may span several configured connections; each carries the connection
 * index, its database name, and the migration index at capture time so a restore
 * can be matched against the schema version it was taken from.
 */
final class BackupConnectionMeta extends BaseDTO
{
    public const string index = 'index';
    public const string database = 'database';
    public const string migrationIndex = 'migrationIndex';

    /**
     * @param int $index Connection index in the project database configuration
     * @param string $database Database name captured
     * @param ?int $migrationIndex Migration index at capture time; null on a sidecar written
     *     before the field existed - "not recorded", which the restore gate must tell from a
     *     database that genuinely carries no migrations
     */
    public function __construct(
        public readonly int $index,
        public readonly string $database,
        public readonly ?int $migrationIndex,
    ) {
    }

    /**
     * @param array<string, mixed> $data Sidecar connection payload
     * @return static Restored connection metadata
     */
    public static function fromArray(array $data): static
    {
        return new self(
            (int)($data[self::index] ?? 0),
            // external-boundary: the sidecar is read from disk and an older one names no database
            (string)($data[self::database] ?? ''),
            isset($data[self::migrationIndex]) ? (int)$data[self::migrationIndex] : null,
        );
    }

    /**
     * @return array<string, mixed> Sidecar connection payload
     */
    public function toArray(): array
    {
        return [
            self::index => $this->index,
            self::database => $this->database,
            // Written even when null, the way BackupMetadata writes its digest: a reader tells
            // "this connection recorded no level" from "this sidecar predates the field".
            self::migrationIndex => $this->migrationIndex,
        ];
    }
}

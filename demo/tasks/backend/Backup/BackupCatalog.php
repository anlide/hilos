<?php

declare(strict_types=1);

namespace Demo\Tasks\Backup;

use Hilos\Backup\BackupConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Entity\Item\Entity;

/**
 * BackupCatalog - the tasks demo's backup catalog.
 *
 * Activates the framework backup subsystem via Hilos::BACKUP_CATALOG. It is the
 * project-owned container for the per-connection reference-object registry under
 * {@see BackupConstants::CATALOG_REFERENCES}. The tasks demo declares no schedule, so it
 * takes the framework default (one daily full backup at 03:00 on the agent mechanism); a
 * project overrides it by adding {@see BackupConstants::CATALOG_SCHEDULE} entries here.
 *
 * The reference registry lists the reference/seed collections per connection index, and
 * this demo seeds nothing: every row it holds is written by the people using it. So the
 * single connection index 0 names an empty list - schema-seed then captures the schema
 * alone - rather than borrowing a seed table from another demo's data.
 *
 * What a restore into a lesser environment must rewrite is declared on each table's own
 * Entity ({@see Entity::META_PII} and {@see Entity::META_PII_NOT_PERSONAL}). This demo names
 * no {@see BackupConstants::CATALOG_TABLES_WITHOUT_ENTITY} class: all three of its own tables
 * have an Entity.
 */
final class BackupCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, array<string, mixed>> Backup catalog (reference registry)
     */
    public static function getCatalog(): array
    {
        return [
            BackupConstants::CATALOG_REFERENCES => [
                0 => [],
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker\Backup;

use Hilos\Backup\BackupConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Database\Entity\Item\Entity;
use Hilos\Database\ChangeLog\ChangeLogDatabase;
use Hilos\Database\Schema\ChangeLogTablesWithoutEntity;

/**
 * BackupCatalog - the binance-btc-tracker demo's backup catalog.
 *
 * Activates the framework backup subsystem via Hilos::BACKUP_CATALOG. It is the
 * project-owned container for the per-connection reference-object registry under
 * {@see BackupConstants::CATALOG_REFERENCES}. The demo declares no schedule, so it takes
 * the framework default (one daily full backup at 03:00 on the agent mechanism); a project
 * overrides it by adding {@see BackupConstants::CATALOG_SCHEDULE} entries here.
 *
 * The reference registry lists the reference/seed collections per connection index, and
 * this demo seeds nothing and keeps no table of its own: every table in its database is the
 * framework's, written by the people using it. So the single connection index 0 names an
 * empty list - schema-seed then captures the schema alone.
 *
 * What a restore into a lesser environment must rewrite is declared on each table's own
 * Entity ({@see Entity::META_PII} and {@see Entity::META_PII_NOT_PERSONAL}). The six journal
 * tables have no Entity; {@see ChangeLogTablesWithoutEntity} classifies them on connection 1.
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
            BackupConstants::CATALOG_TABLES_WITHOUT_ENTITY => [
                ChangeLogDatabase::CONNECTION_INDEX => ChangeLogTablesWithoutEntity::class,
            ],
        ];
    }
}

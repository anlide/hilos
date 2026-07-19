<?php

declare(strict_types=1);

namespace Demo\Chat\Backup;

use Demo\Chat\Database\Object\Collection\Bots;
use Hilos\Backup\BackupConstants;
use Hilos\Core\Catalog\CatalogProviderInterface;

/**
 * BackupCatalog - the chat demo's backup catalog.
 *
 * Activates the framework backup subsystem via Hilos::BACKUP_CATALOG. It is the
 * project-owned container for the per-connection reference-object registry under
 * {@see BackupConstants::CATALOG_REFERENCES} (this ticket) and, later, the backup
 * schedule ({name, cron, scope} entries, populated in HIL-273).
 *
 * The reference registry lists the reference/seed Entity or Object collection classes
 * per connection index; the framework derives their table names and keeps their rows
 * under the schema-seed scope. The chat demo seeds only the `bot` table
 * (see Migration/Seed/001_truncate_and_seed_bot.sql), so it is the sole reference table
 * on the single connection index 0.
 */
final class BackupCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, array<string, mixed>> Backup catalog (reference registry; schedule lands in HIL-273)
     */
    public static function getCatalog(): array
    {
        return [
            BackupConstants::CATALOG_REFERENCES => [
                0 => [Bots::class],
            ],
        ];
    }
}

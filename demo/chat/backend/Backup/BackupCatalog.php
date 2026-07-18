<?php

declare(strict_types=1);

namespace Demo\Chat\Backup;

use Hilos\Core\Catalog\CatalogProviderInterface;

/**
 * BackupCatalog - the chat demo's backup catalog.
 *
 * Activates the framework backup subsystem via Hilos::BACKUP_CATALOG. It is the
 * project-owned container for the backup schedule ({name, cron, scope} entries,
 * populated in HIL-271) and the per-connection reference-object registry
 * (populated in HIL-273). This foundation registers an empty catalog.
 */
final class BackupCatalog implements CatalogProviderInterface
{
    /**
     * @return array<string, array<string, mixed>> Empty backup catalog (schedule and references land in HIL-271/HIL-273)
     */
    public static function getCatalog(): array
    {
        return [];
    }
}

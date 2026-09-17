<?php

declare(strict_types=1);

namespace Hilos\Pages\Backup\DTO;

use Hilos\Constants\HilosSignalConstants;
use Hilos\Core\Table\DTO\TableBulkActionDTO;

/**
 * BackupBulkDeleteActionDTO - delete the marked backups, or every backup matching a filter.
 *
 * The shape is the framework's bulk request and nothing else: the table and exactly one of the
 * two targets ({@see TableBulkActionDTO}). What a row of it does is the page's and the storage
 * agent's to decide, one row at a time.
 */
final class BackupBulkDeleteActionDTO extends TableBulkActionDTO
{
    /**
     * Gets the action name this DTO represents.
     *
     * @return string Action name
     */
    public function getAction(): string
    {
        return HilosSignalConstants::BACKUP_BULK_DELETE;
    }
}

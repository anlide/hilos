<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Collection;

use Hilos\Runtime\State\Item\BackupHistory;
use OutOfBoundsException;

/**
 * BackupHistories - runtime backup index rows keyed by backup id.
 *
 * Framework-owned state collection; the project registers it and the monopoly
 * backup agent rebuilds it from scanned sidecars.
 *
 * @extends RtStates<BackupHistory>
 */
final class BackupHistories extends RtStates
{
    public const string STATE_CLASS = BackupHistory::class;

    /**
     * @param ?string $id Backup id, or null for a missing optional runtime key
     * @return ?BackupHistory Backup history row, or null when missing
     */
    public function get(?string $id): ?BackupHistory
    {
        /** @var ?BackupHistory $state */
        $state = parent::get($id);

        return $state;
    }

    /**
     * Array access is for required rows; use `get()` when absence is valid.
     *
     * @param mixed $offset Backup id
     * @return BackupHistory Backup history row
     */
    public function offsetGet(mixed $offset): BackupHistory
    {
        if ($offset === null) {
            throw new OutOfBoundsException('Backup history not found: null');
        }

        return $this->get((string)$offset)
            ?? throw new OutOfBoundsException("Backup history not found: {$offset}");
    }
}

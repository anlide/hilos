<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Collection;

use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Collection\RtCollectionActionsClassException;
use Hilos\Runtime\Exception\Collection\RtCollectionPropertyNotFoundException;
use Hilos\Runtime\State\Collection\BackupHistories as StateBackupHistories;
use Hilos\Runtime\State\Item\BackupHistory as StateBackupHistory;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\BackupHistoriesActions;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\BackupHistory;

/**
 * Read-only wrapper around the stored-backup index.
 *
 * Framework-owned, like the state collection it projects; a project only binds the
 * two together in its runtime context ({@see RtContext::setRepresent()}).
 * That binding is what gives the index a write path that syncs — the backup page is
 * served by a different worker than the backup agent, so an index written any other
 * way exists in one process and nowhere else.
 *
 * @extends RtCollection<BackupHistory, BackupHistoriesActions>
 * @property-read BackupHistoriesActions $actions Actions for write operations
 */
final class BackupHistories extends RtCollection
{
    /**
     * @return StateBackupHistories Backing state collection
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     */
    public function getStateCollection(): StateBackupHistories
    {
        /** @var StateBackupHistories */
        return parent::getStateCollection();
    }

    /**
     * @param RtState $state StateBackupHistory instance
     * @return BackupHistory View item for this index row
     */
    protected function createRtItem(RtState $state): BackupHistory
    {
        /** @var StateBackupHistory $state */
        return new BackupHistory($state);
    }

    /**
     * @param mixed $offset Backup id
     * @return ?BackupHistory Item or null
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     */
    public function offsetGet(mixed $offset): ?BackupHistory
    {
        /** @var ?BackupHistory $item */
        $item = parent::offsetGet($offset);

        return $item;
    }

    /**
     * @return BackupHistoriesActions Actions instance
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     */
    protected function getActions(): BackupHistoriesActions
    {
        /** @var BackupHistoriesActions $actions */
        $actions = parent::getActions();

        return $actions;
    }

    /**
     * @param string $name Property name
     * @return BackupHistoriesActions Actions instance
     * @throws RtCollectionPropertyNotFoundException When $name is not a declared property
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): BackupHistoriesActions
    {
        return match ($name) {
            self::actions => $this->getActions(),
            default => parent::__get($name),
        };
    }
}

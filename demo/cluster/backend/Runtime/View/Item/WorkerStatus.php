<?php

declare(strict_types=1);

namespace Demo\Cluster\Runtime\View\Item;

use Demo\Cluster\Runtime\State\Item\WorkerStatus as StateWorkerStatus;
use Demo\Cluster\Runtime\View\Actions\Item\WorkerStatusActions;
use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\View\Item\RtItem;

/**
 * Read-only wrapper over one fleet worker status row.
 *
 * @extends RtItem<StateWorkerStatus>
 *
 * @property-read string $workerIndex Fleet member index
 * @property-read int $jobsDone Synthetic jobs the member has finished
 * @property-read int $rowsSeen Rows of this collection the member itself could see
 * @property-read int $updatedAt Last report unix time
 * @property-read WorkerStatusActions $actions Write operations for this status row
 */
final class WorkerStatus extends RtItem
{
    /**
     * @param StateWorkerStatus $state Backing runtime state
     */
    public function __construct(StateWorkerStatus &$state)
    {
        parent::__construct($state);
    }

    /**
     * @throws RtItemActionsClassException When item actions class is missing or invalid
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     */
    public function __get(string $name): int|string|WorkerStatusActions|null
    {
        return match ($name) {
            StateWorkerStatus::workerIndex => $this->_state->workerIndex,
            StateWorkerStatus::jobsDone => $this->_state->jobsDone,
            StateWorkerStatus::rowsSeen => $this->_state->rowsSeen,
            StateWorkerStatus::updatedAt => $this->_state->updatedAt,
            RtItem::actions => $this->getItemActions(),
            default => parent::__get($name),
        };
    }

    /**
     * @return array<string, mixed> Full state row
     */
    public function toArray(): array
    {
        return $this->_state->toArray();
    }
}

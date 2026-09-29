<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Item;

use Hilos\Runtime\State\Item\HilosProbeFleetStatus as StateHilosProbeFleetStatus;
use Hilos\Runtime\View\Actions\Item\HilosProbeFleetStatusActions;
use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\HilosException;

/**
 * Read-only wrapper over one probe fleet status row.
 *
 * @extends RtItem<StateHilosProbeFleetStatus>
 *
 * @property-read string $workerIndex Fleet member index
 * @property-read int $jobsDone Synthetic jobs the member has finished
 * @property-read int $rowsSeen Rows of this collection the member itself could see
 * @property-read int $updatedAt Last report unix time
 * @property-read HilosProbeFleetStatusActions $actions Write operations for this status row
 */
final class HilosProbeFleetStatus extends RtItem
{
    /**
     * @param StateHilosProbeFleetStatus $state Backing runtime state
     */
    public function __construct(StateHilosProbeFleetStatus $state)
    {
        parent::__construct($state);
    }

    /**
     * @throws RtItemActionsClassException When item actions class is missing or invalid
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     * @throws HilosException When an inherited getter or an implementation's relation read fails
     */
    public function __get(string $name): int|string|HilosProbeFleetStatusActions|null
    {
        return match ($name) {
            StateHilosProbeFleetStatus::workerIndex => $this->_state->workerIndex,
            StateHilosProbeFleetStatus::jobsDone => $this->_state->jobsDone,
            StateHilosProbeFleetStatus::rowsSeen => $this->_state->rowsSeen,
            StateHilosProbeFleetStatus::updatedAt => $this->_state->updatedAt,
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

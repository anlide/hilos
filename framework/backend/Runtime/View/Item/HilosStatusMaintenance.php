<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Item;

use Hilos\Runtime\State\Item\HilosStatusMaintenance as StateHilosStatusMaintenance;
use Hilos\Runtime\View\Actions\Item\HilosStatusMaintenanceActions;
use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\HilosException;

/**
 * Read-only wrapper over one Status maintenance observation row.
 *
 * @extends RtItem<StateHilosStatusMaintenance>
 *
 * @property-read string $id Immutable singleton key
 * @property-read bool $available Whether the unconditional freeze source is known
 * @property-read bool $active Last known occupied fact; unavailable does not imply inactive
 * @property-read bool $announcementKnown Whether announcement classification is proven
 * @property-read ?string $periodId Opaque public period identity
 * @property-read ?string $workPublicId Historical public announcement identity
 * @property-read ?string $title Sanitized title snapshot
 * @property-read list<string> $componentKeys Sanitized component keys
 * @property-read ?int $startsAt Planned inclusive start in epoch milliseconds UTC
 * @property-read ?int $endsAt Planned exclusive end in epoch milliseconds UTC
 * @property-read ?int $startedAt First occupied fact in epoch milliseconds UTC
 * @property-read ?int $lastObservedAt Last occupied fact in epoch milliseconds UTC
 * @property-read HilosStatusMaintenanceActions $actions Write operations
 */
final class HilosStatusMaintenance extends RtItem
{
    /**
     * @param StateHilosStatusMaintenance $state Backing runtime state
     */
    public function __construct(StateHilosStatusMaintenance $state)
    {
        parent::__construct($state);
    }

    /**
     * @param string $name Maintenance field or item actions
     * @return mixed Current maintenance field or actions
     * @throws RtItemActionsClassException When item actions class is missing or invalid
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     * @throws HilosException When an inherited getter or an implementation's relation read fails
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            StateHilosStatusMaintenance::id => $this->_state->id,
            StateHilosStatusMaintenance::available => $this->_state->available,
            StateHilosStatusMaintenance::active => $this->_state->active,
            StateHilosStatusMaintenance::announcementKnown => $this->_state->announcementKnown,
            StateHilosStatusMaintenance::periodId => $this->_state->periodId,
            StateHilosStatusMaintenance::workPublicId => $this->_state->workPublicId,
            StateHilosStatusMaintenance::title => $this->_state->title,
            StateHilosStatusMaintenance::componentKeys => $this->_state->componentKeys,
            StateHilosStatusMaintenance::startsAt => $this->_state->startsAt,
            StateHilosStatusMaintenance::endsAt => $this->_state->endsAt,
            StateHilosStatusMaintenance::startedAt => $this->_state->startedAt,
            StateHilosStatusMaintenance::lastObservedAt => $this->_state->lastObservedAt,
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

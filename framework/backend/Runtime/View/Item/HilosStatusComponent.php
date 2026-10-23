<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Item;

use Hilos\Runtime\State\Item\HilosStatusComponent as StateHilosStatusComponent;
use Hilos\Runtime\View\Actions\Item\HilosStatusComponentActions;
use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\HilosException;

/**
 * Read-only wrapper over one Status component count row.
 *
 * @extends RtItem<StateHilosStatusComponent>
 *
 * @property-read string $componentKey Stable component key
 * @property-read int $expectedInstances Expected slots, at least one
 * @property-read ?int $liveInstances Observed live slots, or null without a complete picture
 * @property-read ?int $observedAt Observation epoch milliseconds UTC, or null
 * @property-read HilosStatusComponentActions $actions Write operations for this status row
 */
final class HilosStatusComponent extends RtItem
{
    /**
     * @param StateHilosStatusComponent $state Backing runtime state
     */
    public function __construct(StateHilosStatusComponent $state)
    {
        parent::__construct($state);
    }

    /**
     * @param string $name Count field or item actions
     * @return int|string|HilosStatusComponentActions|null Current count field or actions
     * @throws RtItemActionsClassException When item actions class is missing or invalid
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     * @throws HilosException When an inherited getter or an implementation's relation read fails
     */
    public function __get(string $name): int|string|HilosStatusComponentActions|null
    {
        return match ($name) {
            StateHilosStatusComponent::componentKey => $this->_state->componentKey,
            StateHilosStatusComponent::expectedInstances => $this->_state->expectedInstances,
            StateHilosStatusComponent::liveInstances => $this->_state->liveInstances,
            StateHilosStatusComponent::observedAt => $this->_state->observedAt,
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

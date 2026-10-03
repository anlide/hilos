<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Item;

use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\State\Item\HilosProfilePhotoCheck as StateHilosProfilePhotoCheck;
use Hilos\Runtime\View\Actions\Item\HilosProfilePhotoCheckActions;
use Hilos\HilosException;

/**
 * @extends RtItem<StateHilosProfilePhotoCheck>
 * @property-read string $acceptKey Connection awaiting the verdict
 * @property-read int $userId Person whose photo is checked
 * @property-read string $clientUploadId Pending upload id
 * @property-read int $startedAt Start moment in epoch milliseconds
 * @property-read HilosProfilePhotoCheckActions $actions
 */
final class HilosProfilePhotoCheck extends RtItem
{
    /** @param StateHilosProfilePhotoCheck $state Backing runtime row */
    public function __construct(StateHilosProfilePhotoCheck $state)
    {
        parent::__construct($state);
    }

    /**
     * @param string $name Property name
     * @return string|int|HilosProfilePhotoCheckActions Property value
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     * @throws RtItemActionsClassException When item actions are unavailable
     * @throws HilosException Whatever an inherited getter raises
     */
    public function __get(string $name): string|int|HilosProfilePhotoCheckActions
    {
        return match ($name) {
            StateHilosProfilePhotoCheck::acceptKey => $this->_state->acceptKey,
            StateHilosProfilePhotoCheck::userId => $this->_state->userId,
            StateHilosProfilePhotoCheck::clientUploadId => $this->_state->clientUploadId,
            StateHilosProfilePhotoCheck::startedAt => $this->_state->startedAt,
            RtItem::actions => $this->getItemActions(),
            default => parent::__get($name),
        };
    }

    /** @return array<string, mixed> Full runtime row */
    public function toArray(): array
    {
        return $this->_state->toArray();
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Item;

use Hilos\HilosException;
use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\State\Item\HilosModerationCheck as StateHilosModerationCheck;
use Hilos\Runtime\View\Actions\Item\HilosModerationCheckActions;

/**
 * Read-only view of one check a connection is waiting on (HIL-1625).
 *
 * @extends RtItem<StateHilosModerationCheck>
 * @property-read string $acceptKey Connection awaiting the verdict
 * @property-read int $userId Person whose content is checked
 * @property-read string $kind Moderation kind key
 * @property-read string $requestId Id of this attempt
 * @property-read string $verdictTo Agent type the verdict is sent to
 * @property-read ?string $text Text to check, or null for a kind that looks at no text
 * @property-read ?string $clientUploadId Complete upload to check, or null for a kind that looks at no image
 * @property-read int $startedAt Opening moment in epoch milliseconds
 * @property-read int $deadlineAt Moment the wait ends, in epoch milliseconds
 * @property-read ?string $sessionTokenHash Session name-change correlation, or null for a connection-bound check
 * @property-read HilosModerationCheckActions $actions
 */
final class HilosModerationCheck extends RtItem
{
    /** @param StateHilosModerationCheck $state Backing runtime row */
    public function __construct(StateHilosModerationCheck $state)
    {
        parent::__construct($state);
    }

    /**
     * @param string $name Property name
     * @return string|int|null|HilosModerationCheckActions Property value
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     * @throws RtItemActionsClassException When item actions are unavailable
     * @throws HilosException Whatever an inherited getter raises
     */
    public function __get(string $name): string|int|null|HilosModerationCheckActions
    {
        return match ($name) {
            StateHilosModerationCheck::acceptKey => $this->_state->acceptKey,
            StateHilosModerationCheck::userId => $this->_state->userId,
            StateHilosModerationCheck::kind => $this->_state->kind,
            StateHilosModerationCheck::requestId => $this->_state->requestId,
            StateHilosModerationCheck::verdictTo => $this->_state->verdictTo,
            StateHilosModerationCheck::text => $this->_state->text,
            StateHilosModerationCheck::clientUploadId => $this->_state->clientUploadId,
            StateHilosModerationCheck::startedAt => $this->_state->startedAt,
            StateHilosModerationCheck::deadlineAt => $this->_state->deadlineAt,
            StateHilosModerationCheck::sessionTokenHash => $this->_state->sessionTokenHash,
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

<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Item;

use Hilos\HilosException;
use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\State\Item\HilosProfileNameChange as StateHilosProfileNameChange;
use Hilos\Runtime\View\Actions\Item\HilosProfileNameChangeActions;

/**
 * Read-only view of one session's current or last name-change attempt.
 *
 * @extends RtItem<StateHilosProfileNameChange>
 * @property-read string $sessionTokenHash Hash of the session cookie token
 * @property-read int $userId Person the attempt belongs to
 * @property-read string $requestId Fresh secure 32-hex attempt identifier
 * @property-read string $originAcceptKey Connection that submitted the attempt
 * @property-read string $name Trimmed requested name
 * @property-read string $phase One of the PHASE_* constants
 * @property-read ?string $refusal Server refusal sentence, or null
 * @property-read int $startedAt Attempt start in epoch milliseconds
 * @property-read ?int $deadlineAt Fixed checking deadline in epoch milliseconds, or null
 * @property-read HilosProfileNameChangeActions $actions Actions for write operations
 */
final class HilosProfileNameChange extends RtItem
{
    /** @param StateHilosProfileNameChange $state Backing runtime row */
    public function __construct(StateHilosProfileNameChange $state)
    {
        parent::__construct($state);
    }

    /**
     * @param string $name Property name
     * @return string|int|HilosProfileNameChangeActions|null Property value
     * @throws RtItemPropertyNotFoundException When the property is unknown
     * @throws RtItemActionsClassException When item actions are missing or invalid
     * @throws HilosException When the inherited getter fails
     */
    public function __get(string $name): string|int|HilosProfileNameChangeActions|null
    {
        return match ($name) {
            StateHilosProfileNameChange::sessionTokenHash => $this->_state->sessionTokenHash,
            StateHilosProfileNameChange::userId => $this->_state->userId,
            StateHilosProfileNameChange::requestId => $this->_state->requestId,
            StateHilosProfileNameChange::originAcceptKey => $this->_state->originAcceptKey,
            StateHilosProfileNameChange::name => $this->_state->name,
            StateHilosProfileNameChange::phase => $this->_state->phase,
            StateHilosProfileNameChange::refusal => $this->_state->refusal,
            StateHilosProfileNameChange::startedAt => $this->_state->startedAt,
            StateHilosProfileNameChange::deadlineAt => $this->_state->deadlineAt,
            self::actions => $this->getItemActions(),
            default => parent::__get($name),
        };
    }

    /** @return array<string, mixed> Full runtime row */
    public function toArray(): array
    {
        return $this->_state->toArray();
    }
}

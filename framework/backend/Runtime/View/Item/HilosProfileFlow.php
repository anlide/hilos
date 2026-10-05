<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Item;

use Hilos\HilosException;
use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\State\Item\HilosProfileFlow as StateHilosProfileFlow;
use Hilos\Runtime\View\Actions\Collection\HilosProfileFlowsActions;

/**
 * Read-only wrapper over one profile window's flow in one session (HIL-1182).
 *
 * What the users library reads when a step has to stand on what an earlier one proved, and what
 * the session holder reads when it tells the tabs where the window is. The row carries no write
 * API of its own: every write is the holder's, and is named by the session and the window
 * ({@see HilosProfileFlowsActions}).
 *
 * @extends RtItem<StateHilosProfileFlow>
 *
 * @property-read string $sessionTokenHash Hash of the session cookie token the flow belongs to
 * @property-read string $operation Operation key of the window
 * @property-read int $userId The person the flow was started for
 * @property-read string $step What has happened in the window - one of the STEP_* constants
 * @property-read string $address The account's address the proof stands on
 * @property-read ?string $target New email on STEP_NEW_SENT, added address or number on STEP_PHONE_SENT or STEP_EMAIL_SENT, null otherwise
 * @property-read int $expiresAt Epoch milliseconds the code of the proof dies at
 */
final class HilosProfileFlow extends RtItem
{
    /**
     * @param StateHilosProfileFlow $state Backing runtime state
     */
    public function __construct(StateHilosProfileFlow $state)
    {
        parent::__construct($state);
    }

    /**
     * @param string $name Property name
     * @return string|int|null Property value
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     * @throws RtItemActionsClassException When the item actions class is missing or invalid
     * @throws HilosException When an inherited getter or an implementation's relation read fails
     */
    public function __get(string $name): string|int|null
    {
        return match ($name) {
            StateHilosProfileFlow::sessionTokenHash => $this->_state->sessionTokenHash,
            StateHilosProfileFlow::operation => $this->_state->operation,
            StateHilosProfileFlow::userId => $this->_state->userId,
            StateHilosProfileFlow::step => $this->_state->step,
            StateHilosProfileFlow::address => $this->_state->address,
            StateHilosProfileFlow::target => $this->_state->target,
            StateHilosProfileFlow::expiresAt => $this->_state->expiresAt,
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

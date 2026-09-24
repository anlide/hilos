<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Item;

use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\State\Item\HilosCodeSendAttempt as StateHilosCodeSendAttempt;
use Hilos\Runtime\View\Actions\Collection\HilosCodeSendAttemptsActions;

/**
 * Read-only wrapper over one session's code send attempt (HIL-826).
 *
 * What the agent reads when it has to put the line on the wire: the state, the channel it is
 * travelling over and, on a refusal, the provider's own sentence. The row carries no write API
 * of its own - a step is judged against the ticket the line holds, which is a question about
 * the whole collection ({@see HilosCodeSendAttemptsActions}) rather than about one row a
 * caller has already found.
 *
 * @extends RtItem<StateHilosCodeSendAttempt>
 *
 * @property-read string $sessionTokenHash Hash of the session cookie token this line is addressed to
 * @property-read string $ticket Send this line is about
 * @property-read string $channel Channel the code is travelling over
 * @property-read string $state One of the five states of the send
 * @property-read ?string $detail Provider's sentence, on a refusal and nowhere else
 * @property-read ?string $reason How the code agent's send ended, on its closing step alone
 * @property-read ?int $resendAt Server moment a send is allowed again, in epoch ms, or null
 * @property-read ?int $expiresAt Server moment the live code dies, in epoch ms, or null
 * @property-read int $updatedAt Epoch milliseconds of the last write
 */
final class HilosCodeSendAttempt extends RtItem
{
    /**
     * @param StateHilosCodeSendAttempt $state Backing runtime state
     */
    public function __construct(StateHilosCodeSendAttempt $state)
    {
        parent::__construct($state);
    }

    /**
     * @param string $name Property name
     * @return string|int|null Property value
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     * @throws RtItemActionsClassException When the item actions class is missing or invalid
     */
    public function __get(string $name): string|int|null
    {
        return match ($name) {
            StateHilosCodeSendAttempt::sessionTokenHash => $this->_state->sessionTokenHash,
            StateHilosCodeSendAttempt::ticket => $this->_state->ticket,
            StateHilosCodeSendAttempt::channel => $this->_state->channel,
            StateHilosCodeSendAttempt::state => $this->_state->state,
            StateHilosCodeSendAttempt::detail => $this->_state->detail,
            StateHilosCodeSendAttempt::reason => $this->_state->reason,
            StateHilosCodeSendAttempt::resendAt => $this->_state->resendAt,
            StateHilosCodeSendAttempt::expiresAt => $this->_state->expiresAt,
            StateHilosCodeSendAttempt::updatedAt => $this->_state->updatedAt,
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

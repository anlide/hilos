<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Item;

use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\State\Item\HilosSessionRotation as StateHilosSessionRotation;
use Hilos\Runtime\View\Actions\Collection\HilosSessionRotationsActions;

/**
 * Read-only wrapper over one pending token rotation (HIL-582).
 *
 * What the master gets when it trades a ticket on the 101: the token to put in the
 * Set-Cookie, and the connections of the old session to drop once it has. The row carries
 * no write API of its own - a rotation is written once and then either burned or swept,
 * both of which are collection-level acts on {@see HilosSessionRotationsActions}.
 *
 * @extends RtItem<StateHilosSessionRotation>
 *
 * @property-read string $ticket One-time ticket naming this rotation
 * @property-read string $sessionToken Session token the ticket's bearer receives
 * @property-read list<string> $acceptKeysToDrop Accept keys of the session's other connections
 * @property-read float $expiresAtMs Unix milliseconds after which the ticket is not honoured
 */
final class HilosSessionRotation extends RtItem
{
    /**
     * @param StateHilosSessionRotation $state Backing runtime state
     */
    public function __construct(StateHilosSessionRotation &$state)
    {
        parent::__construct($state);
    }

    /**
     * @param string $name Property name
     * @return string|float|array<int, string>|null Property value
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     */
    public function __get(string $name): string|float|array|null
    {
        return match ($name) {
            StateHilosSessionRotation::ticket => $this->_state->ticket,
            StateHilosSessionRotation::sessionToken => $this->_state->sessionToken,
            StateHilosSessionRotation::acceptKeysToDrop => $this->_state->acceptKeysToDrop,
            StateHilosSessionRotation::expiresAtMs => $this->_state->expiresAtMs,
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

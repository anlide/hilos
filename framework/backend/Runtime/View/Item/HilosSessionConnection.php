<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Item;

use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\State\Item\HilosSessionConnection as StateHilosSessionConnection;

/**
 * Read-only runtime item for one connection row — the session stage (HIL-509).
 *
 * The view twin of {@see StateHilosSessionConnection}: one field on top of the
 * presence stage, read the same way the two below it are. The stages are mirrored
 * on both layers so that "which stage is this project on" has a single answer.
 *
 * @template TState of StateHilosSessionConnection
 * @extends HilosConnection<TState>
 *
 * @property-read ?string $sessionToken Session cookie token this connection belongs to
 */
abstract class HilosSessionConnection extends HilosConnection
{
    /**
     * Adds the session-stage fields to the base ones of {@see HilosConnection::__get()}.
     *
     * @param string $name Property name (sessionToken, or a presence-stage one)
     * @return mixed Property value or item actions
     * @throws RtItemActionsClassException When the item actions class is missing or invalid
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            StateHilosSessionConnection::sessionToken => $this->_state->sessionToken,
            default => parent::__get($name),
        };
    }
}

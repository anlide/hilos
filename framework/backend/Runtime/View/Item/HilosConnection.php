<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Item;

use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\State\Item\HilosConnection as StateHilosConnection;
use Hilos\Runtime\View\Actions\Item\HilosConnectionActions;

/**
 * Read-only runtime item for one connection row — the presence stage (HIL-509).
 *
 * The view twin of {@see StateHilosConnection}: it reads the base fields off the
 * row and resolves the item's write actions, so a project's connection item is
 * left with nothing but its own fields. A project that carries browser sessions
 * extends {@see HilosSessionConnection} instead, which adds the session token to
 * the same match.
 *
 * {@see __get()} deliberately keeps the widest return type: a project extends
 * this match with fields and virtual links of its own, and a narrowed one would
 * have to be widened again by every subclass.
 *
 * @template TState of StateHilosConnection
 * @extends RtItem<TState>
 *
 * @property-read string $acceptKey WebSocket accept key
 * @property-read ?int $userId Authenticated user id, or null while anonymous
 * @property-read HilosConnectionActions $actions Write operations for this connection
 */
abstract class HilosConnection extends RtItem
{
    /**
     * Delegates the base fields to the backing row and resolves item actions.
     *
     * @param string $name Property name (acceptKey, userId, actions)
     * @return mixed Property value or item actions
     * @throws RtItemActionsClassException When the item actions class is missing or invalid
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            StateHilosConnection::acceptKey => $this->_state->acceptKey,
            StateHilosConnection::userId => $this->_state->userId,
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

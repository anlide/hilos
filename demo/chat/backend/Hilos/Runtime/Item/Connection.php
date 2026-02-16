<?php

declare(strict_types=1);

namespace Demo\Chat\Hilos\Runtime\Item;

use Demo\Chat\Hilos\Database\Hilos;
use Demo\Chat\Hilos\Database\Item\User;
use Demo\Chat\Hilos\Runtime\State\Item\Connection as StateConnection;
use Hilos\Hilos\Runtime\Item\RtItem;

/**
 * Connection Rt item - read-only wrapper around Connection state.
 *
 * Provides high-level access to connection data.
 * Stores only reference to StateConnection for memory efficiency.
 *
 * @extends RtItem<StateConnection>
 *
 * @property-read string $acceptKey WebSocket accept key
 * @property-read int $userId User ID
 * @property-read int $connectedAt Unix timestamp when connected
 * @property-read ?User $user User for this connection (null if user not found)
 */
final class Connection extends RtItem
{
    /**
     * @param StateConnection $state Connection state (reference)
     */
    public function __construct(StateConnection &$state)
    {
        parent::__construct($state);
    }

    /**
     * Property getter.
     *
     * @param string $name Property name
     * @return string|int|User|null
     */
    public function __get(string $name): string|int|User|null
    {
        /** @var StateConnection $state */
        $state = $this->_state;

        return match ($name) {
            StateConnection::acceptKey => $state->getAcceptKey(),
            StateConnection::userId => $state->getUserId(),
            StateConnection::connectedAt => $state->getConnectedAt(),
            Hilos::user => Hilos::$db->users[$state->getUserId()] ?? null,
            default => parent::__get($name),
        };
    }

    /**
     * Convert to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        /** @var StateConnection $state */
        $state = $this->_state;
        return [
            StateConnection::acceptKey => $state->getAcceptKey(),
            StateConnection::userId => $state->getUserId(),
            StateConnection::connectedAt => $state->getConnectedAt(),
        ];
    }
}

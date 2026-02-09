<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\Idea;

use Demo\Chat\Database\Idea;
use Demo\Chat\Database\Idea\User as IdeaUser;
use Demo\Chat\Runtime\State\Connection as StateConnection;
use Hilos\Runtime\Idea\IdeaRtItem;

/**
 * Connection Idea - read-only wrapper around Connection state
 *
 * Provides high-level access to connection data.
 * Stores only reference to StateConnection for memory efficiency.
 *
 * @extends IdeaRtItem<StateConnection>
 *
 * @property-read string $acceptKey WebSocket accept key
 * @property-read int $userId User ID
 * @property-read int $connectedAt Unix timestamp when connected
 * @property-read ?IdeaUser $user IdeaUser for this connection (null if user not found)
 */
final class Connection extends IdeaRtItem
{
    /**
     * Constructor
     *
     * @param StateConnection $state Connection state (reference)
     */
    public function __construct(StateConnection &$state)
    {
        parent::__construct($state);
    }

    /**
     * Property getter
     *
     * @param string $name Property name
     * @return string|int|IdeaUser|null
     */
    public function __get(string $name): string|int|IdeaUser|null
    {
        /** @var StateConnection $state */
        $state = $this->_state;

        return match ($name) {
            StateConnection::acceptKey => $state->getAcceptKey(),
            StateConnection::userId => $state->getUserId(),
            StateConnection::connectedAt => $state->getConnectedAt(),
            'user' => Idea::$db->users[$state->getUserId()] ?? null,
            default => parent::__get($name),
        };
    }

    /**
     * Convert to array
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

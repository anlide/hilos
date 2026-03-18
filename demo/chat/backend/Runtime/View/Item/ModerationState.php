<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Item;

use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\View\Item\User;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\State\Item\ModerationState as StateModerationState;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\View\Item\RtItem;

/**
 * ModerationState - Read-only wrapper around moderation state.
 *
 * @extends RtItem<StateModerationState>
 *
 * @property-read int $userId User ID
 * @property-read string $message Pending moderation message
 * @property-read int $updatedAt Unix timestamp when state was updated
 * @property-read ?User $user User for this moderation state
 */
final class ModerationState extends RtItem
{
    /**
     * Creates moderation state view item.
     *
     * @param StateModerationState $state Moderation state (reference)
     */
    public function __construct(StateModerationState &$state)
    {
        parent::__construct($state);
    }

    /**
     * Property getter.
     *
     * @param string $name Property name
     * @return int|string|User|null Property value
     * @throws RtItemPropertyNotFoundException If property not found
     */
    public function __get(string $name): int|string|User|null
    {
        /** @var StateModerationState $state */
        $state = $this->_state;

        return match ($name) {
            StateModerationState::userId => $state->userId,
            StateModerationState::message => $state->message,
            StateModerationState::updatedAt => $state->updatedAt,
            DbChatContext::user => Hilos::$db->users[$state->userId] ?? null,
            default => parent::__get($name),
        };
    }

    /**
     * Convert to array.
     *
     * @return array<string, int|string> Item as associative array
     */
    public function toArray(): array
    {
        /** @var StateModerationState $state */
        $state = $this->_state;
        return [
            StateModerationState::userId => $state->userId,
            StateModerationState::message => $state->message,
            StateModerationState::updatedAt => $state->updatedAt,
        ];
    }
}

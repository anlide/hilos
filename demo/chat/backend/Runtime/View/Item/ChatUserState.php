<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Item;

use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\View\Item\User;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\State\Item\ChatUserState as StateChatUserState;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\View\Item\RtItem;

/**
 * Read-only {@see RtItem} over {@see StateChatUserState} (mirrors state fields + virtual `user`).
 *
 * @extends RtItem<StateChatUserState>
 *
 * @property-read int $userId User ID
 * @property-read string $moderationMessage Pending text moderation (empty if none)
 * @property-read int $moderationUpdatedAt Last text moderation update unix time
 * @property-read ?User $user User row or null if not found in DB view
 */
final class ChatUserState extends RtItem
{
    /**
     * @param StateChatUserState $state Backing state (by reference, same as parent contract)
     */
    public function __construct(StateChatUserState &$state)
    {
        parent::__construct($state);
    }

    /**
     * Delegates known keys to the backing state; virtual `user` loads from the DB users collection.
     *
     * @throws RtItemPropertyNotFoundException When $name is not a declared virtual property
     */
    public function __get(string $name): int|string|User|null
    {
        /** @var StateChatUserState $state */
        $state = $this->_state;

        return match ($name) {
            StateChatUserState::userId => $state->userId,
            StateChatUserState::moderationMessage => $state->moderationMessage,
            StateChatUserState::moderationUpdatedAt => $state->moderationUpdatedAt,
            DbChatContext::user => Hilos::$db->users[$state->userId] ?? null,
            default => parent::__get($name),
        };
    }

    /**
     * @return array<string, mixed> Full state row (same as {@see StateChatUserState::toArray()})
     */
    public function toArray(): array
    {
        /** @var StateChatUserState $state */
        $state = $this->_state;

        return $state->toArray();
    }
}

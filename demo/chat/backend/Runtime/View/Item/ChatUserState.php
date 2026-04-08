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
 * ChatUserState - Read-only view over per-user chat runtime state.
 *
 * @extends RtItem<StateChatUserState>
 *
 * @property-read int $userId User ID
 * @property-read string $moderationMessage Pending text moderation (empty if none)
 * @property-read int $moderationUpdatedAt Last text moderation update unix time
 * @property-read ?User $user User row for this state
 */
final class ChatUserState extends RtItem
{
    public function __construct(StateChatUserState &$state)
    {
        parent::__construct($state);
    }

    /**
     * @return int|string|User|null
     *
     * @throws RtItemPropertyNotFoundException
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
     * @return array<string, int|string|float|null>
     */
    public function toArray(): array
    {
        /** @var StateChatUserState $state */
        $state = $this->_state;

        return [
            StateChatUserState::userId => $state->userId,
            StateChatUserState::moderationMessage => $state->moderationMessage,
            StateChatUserState::moderationUpdatedAt => $state->moderationUpdatedAt,
        ];
    }
}

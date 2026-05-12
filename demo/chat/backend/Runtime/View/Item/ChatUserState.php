<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\View\Item;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\View\Item\User;
use Demo\Chat\Hilos;
use Demo\Chat\Runtime\State\Item\ChatUserState as StateChatUserState;
use Demo\Chat\Runtime\View\Actions\Item\ChatUserStateActions;
use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\View\Item\RtItem;

/**
 * Read-only runtime item over a user state row plus the virtual `user` link.
 *
 * @extends RtItem<StateChatUserState>
 *
 * @property-read int $userId User ID
 * @property-read float $lastOutboundSubmittedAt Last accepted outbound submit microtime
 * @property-read ?User $user User row or null if not found in DB view
 * @property-read ChatUserStateActions $actions Write operations for this user runtime state
 */
final class ChatUserState extends RtItem
{
    /**
     * Minimum interval in seconds between accepted outbound submissions.
     */
    public const int MESSAGE_RATE_LIMIT_SECONDS = 10;

    /**
     * Allowed client-side timer drift before the visible rate-limit window ends.
     */
    public const int MESSAGE_RATE_LIMIT_TOLERANCE_SECONDS = 1;

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
     * @throws RtItemActionsClassException When item actions class is missing or invalid
     * @throws RtItemPropertyNotFoundException When $name is not a declared virtual property
     */
    public function __get(string $name): int|float|string|User|null|ChatUserStateActions
    {
        return match ($name) {
            StateChatUserState::userId => $this->_state->userId,
            StateChatUserState::lastOutboundSubmittedAt => $this->_state->lastOutboundSubmittedAt,
            ChatDbContext::user => Hilos::$db->users[$this->_state->userId],
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

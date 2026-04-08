<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Collection;

use Demo\Chat\Runtime\State\Item\ChatUserState;
use Hilos\Runtime\State\Collection\RtStates;

/**
 * UserStates - Per-user chat runtime state collection.
 *
 * @extends RtStates<ChatUserState>
 */
final class UserStates extends RtStates
{
    public const string STATE_CLASS = ChatUserState::class;
}

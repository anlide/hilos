<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Collection;

use Demo\Chat\Runtime\State\Item\ModerationState;
use Hilos\Runtime\State\Collection\RtStates;

/**
 * ModerationStates state collection - in-flight moderation state by user.
 *
 * @extends RtStates<ModerationState>
 */
class ModerationStates extends RtStates
{
    public const string STATE_CLASS = ModerationState::class;
}

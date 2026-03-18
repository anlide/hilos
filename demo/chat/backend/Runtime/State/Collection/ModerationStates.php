<?php

declare(strict_types=1);

namespace Demo\Chat\Runtime\State\Collection;

use Demo\Chat\Runtime\State\Item\ModerationState;
use Hilos\Runtime\State\Collection\RtStates;

/**
 * ModerationStates - In-flight moderation state collection by user.
 *
 * @extends RtStates<ModerationState>
 */
final class ModerationStates extends RtStates
{
    public const string STATE_CLASS = ModerationState::class;
}

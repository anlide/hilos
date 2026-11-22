<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation\Testing;

/** Account-operation asks, answers and loser-to-survivor handovers can be held or replayed. */
enum AccountOperationProbeFrameKind: string
{
    case Ask = 'ask';
    case Answer = 'answer';
    case Handover = 'handover';
}

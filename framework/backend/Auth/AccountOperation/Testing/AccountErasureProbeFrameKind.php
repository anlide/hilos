<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation\Testing;

/** Only existing account-operation asks and answers can be held or replayed. */
enum AccountErasureProbeFrameKind: string
{
    case Ask = 'ask';
    case Answer = 'answer';
}

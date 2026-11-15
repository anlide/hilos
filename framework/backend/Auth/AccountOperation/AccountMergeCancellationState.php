<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

/** Internal progress of cancel-before-fact; never a participant wire answer. */
enum AccountMergeCancellationState: string
{
    case WaitingOwners = 'waiting_owners';
    case NotStarted = 'not_started';
    case TooLate = 'too_late';
}

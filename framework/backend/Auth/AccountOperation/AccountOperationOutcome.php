<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

/** Terminal outcomes of a durable account operation. */
enum AccountOperationOutcome: string
{
    case Completed = 'completed';
    case NotStarted = 'not_started';
}

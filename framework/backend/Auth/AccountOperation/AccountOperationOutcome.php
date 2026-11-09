<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

/** SCAFFOLD: terminal outcomes for durable operation storage (HIL-1936). */
enum AccountOperationOutcome: string
{
    case Completed = 'completed';
    case NotStarted = 'not_started';
}

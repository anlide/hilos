<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountOperation;

/** A participant's answer to one assignment, separate from the operation's terminal outcome. */
enum AccountOperationAnswerKind: string
{
    case Done = 'done';
    case Uncaptured = 'uncaptured';
    case Waits = 'waits';
    case FinalRefused = 'final_refused';
    case Refused = 'refused';
    case Failed = 'failed';
}

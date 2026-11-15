<?php

declare(strict_types=1);

namespace Hilos\Auth\AccountDeletion;

/** The stage a force-purge reply names: only a finished erasure says it was carried out (HIL-1953). */
enum AccountDeletionCommandOutcome: string
{
    case Completed = 'completed';
    case Starting = 'starting';
    case Erasing = 'erasing';
}

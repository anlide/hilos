<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Hilos;

/**
 * GuardianRunStatus - Demo lifecycle states for guardian agent runs.
 */
enum GuardianRunStatus: string
{
    case NOT_STARTED = 'not_started';
    case IN_PROGRESS = 'in_progress';
    case DONE = 'done';
    case STOPPED = 'stopped';
    case FAILED = 'failed';
}

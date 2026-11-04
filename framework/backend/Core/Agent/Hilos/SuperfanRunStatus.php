<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Hilos;

/**
 * SuperfanRunStatus - Demo lifecycle states for superfan agent runs.
 */
enum SuperfanRunStatus: string
{
    case NOT_STARTED = 'not_started';
    case IN_PROGRESS = 'in_progress';
    case DONE = 'done';
    case STOPPED = 'stopped';
    case FAILED = 'failed';
}

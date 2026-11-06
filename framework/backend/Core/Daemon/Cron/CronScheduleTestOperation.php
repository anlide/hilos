<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon\Cron;

/** Test-only mutations of one current cron rule's schedule pauses. */
enum CronScheduleTestOperation: string
{
    case Pause = 'pause';
    case CancelPause = 'cancel_pause';
}

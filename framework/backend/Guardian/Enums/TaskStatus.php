<?php

declare(strict_types=1);

namespace Hilos\Guardian\Enums;

/**
 * Guardian task execution status.
 */
enum TaskStatus: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case DONE = 'done';
    case FAILED = 'failed';
}

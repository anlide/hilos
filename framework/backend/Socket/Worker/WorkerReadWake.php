<?php

declare(strict_types=1);

namespace Hilos\Socket\Worker;

/** Local wait outcomes; no bytes or process signals are consumed by the wait. */
enum WorkerReadWake
{
    case READABLE;
    case TIMEOUT;
    case INTERRUPTED;
    case UNAVAILABLE;
}

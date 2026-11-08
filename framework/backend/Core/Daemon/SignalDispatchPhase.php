<?php

declare(strict_types=1);

namespace Hilos\Core\Daemon;

/** Process-local progress of one admitted signal; never a wire or runtime row. */
enum SignalDispatchPhase
{
    case Admission;
    case Preparation;
    case Destinations;
    case Terminal;
    case Complete;
    case Canceled;
    case Failed;
}

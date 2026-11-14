<?php

declare(strict_types=1);

namespace Hilos\Runtime\Snapshot;

/** Consumers sharing one process's RT pass. */
enum RtWorkLane
{
    case Ordinary;
    case Snapshot;
    case Retire;
}

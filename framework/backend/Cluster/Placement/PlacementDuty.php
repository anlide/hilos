<?php

declare(strict_types=1);

namespace Hilos\Cluster\Placement;

/** Independent process-local deadlines owned by the guarded placement phase. */
enum PlacementDuty: int
{
    case Rebuild = 0;
    case Failover = 1;
    case Retry = 2;
    case Acknowledgement = 3;
    case SelfFence = 4;
}

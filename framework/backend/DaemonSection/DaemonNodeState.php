<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

/** State word derived from the current cluster picture. */
enum DaemonNodeState: string
{
    case Leader = 'leader';
    case Standby = 'standby';
    case Data = 'data';
    case Silent = 'silent';
}

<?php

declare(strict_types=1);

namespace Hilos\DaemonSection;

/** Whether a node can answer for a catalog key in this cluster picture. */
enum ClusterEnvironmentCellState: string
{
    case Known = 'known';
    case Unknown = 'unknown';
    case Undeclared = 'undeclared';
}

<?php

declare(strict_types=1);

namespace Hilos\Tables\Daemon;

use Hilos\DaemonSection\ClusterEnvironmentComparison;
use Hilos\DaemonSection\ClusterEnvironmentKeyComparison;

/** Environment keys whose declarations, types or labels differ across nodes. */
final class HilosDaemonEnvDivergedTable extends AbstractHilosDaemonEnvMismatchTable
{
    public const string TABLE = 'hilosDaemonEnvDiverged';

    /** @return list<ClusterEnvironmentKeyComparison> Divergent keys in verdict order */
    protected function keysOf(ClusterEnvironmentComparison $comparison): array
    {
        return $comparison->diverged;
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tables\Daemon;

use Hilos\DaemonSection\ClusterEnvironmentComparison;
use Hilos\DaemonSection\ClusterEnvironmentKeyComparison;

/** Environment keys with equal values reached through different sources. */
final class HilosDaemonEnvSourcesDifferTable extends AbstractHilosDaemonEnvMismatchTable
{
    public const string TABLE = 'hilosDaemonEnvSourcesDiffer';

    /** @return list<ClusterEnvironmentKeyComparison> Keys with differing sources in verdict order */
    protected function keysOf(ClusterEnvironmentComparison $comparison): array
    {
        return $comparison->sourcesDiffer;
    }
}

<?php

declare(strict_types=1);

namespace Demo\Cluster\Database;

use Hilos\Database\Context\HilosDbContext;

/**
 * ClusterDbContext - Database context for the cluster demo.
 *
 * The demo keeps no domain tables — cluster coordination is deliberately out of
 * MySQL — so it adds nothing to the framework settings collection HilosDbContext
 * registers. The concrete subclass exists only to be instantiated by the facade.
 */
final class ClusterDbContext extends HilosDbContext
{
}

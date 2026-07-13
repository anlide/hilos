<?php

declare(strict_types=1);

namespace Hilos\Cluster\Exception;

use Hilos\Cluster\ClusterContext;

/**
 * Thrown when node-identity is requested while cluster mode is disabled.
 *
 * A single-node daemon has no cluster identity; callers must gate identity
 * access behind {@see ClusterContext::isEnabled()}.
 */
class ClusterDisabledException extends ClusterException
{
}

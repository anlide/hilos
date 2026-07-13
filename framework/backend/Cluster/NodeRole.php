<?php

declare(strict_types=1);

namespace Hilos\Cluster;

/**
 * Self-declared role of a cluster node.
 *
 * The role is declared in node configuration, not elected: it is fixed for the
 * lifetime of the process and changing it requires a restart. A master is a
 * quorum member and is eligible to run as the ClusterCoordinator; a slave is a
 * data-plane node that carries workers but takes no part in coordination.
 */
enum NodeRole: string
{
    case Master = 'master';
    case Slave = 'slave';
}

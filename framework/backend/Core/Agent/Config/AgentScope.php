<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Config;

/**
 * AgentScope - how many instances of an agent type exist.
 *
 * The first of the two placement axes declared per agent in Hilos::AGENTS; the second is
 * {@see AgentPlacement}, which answers who picks the node. Scope answers only the count,
 * so a cluster-wide agent stays one instance whether the leader or the placement policy
 * chose its host.
 *
 * The default is {@see self::CLUSTER}: forgetting to declare the axis under-runs an agent
 * (one instance) rather than double-running a truth source.
 */
enum AgentScope
{
    /** Exactly one instance cluster-wide. */
    case CLUSTER;

    /** One replica on every node, started as that node's workers become ready. */
    case NODE;
}

<?php

declare(strict_types=1);

namespace Hilos\Core\Agent\Config;

/**
 * AgentPlacement - who picks the node a cluster-wide agent runs on.
 *
 * The second of the two placement axes declared per agent in Hilos::AGENTS; the first is
 * {@see AgentScope}, which answers how many instances exist. The axis is meaningful only
 * for {@see AgentScope::CLUSTER}: a per-node replica has no node to pick, and declaring
 * placement next to {@see AgentScope::NODE} is a topology error.
 *
 * The default is {@see self::LEADER}, which is what every agent did before the axes
 * arrived.
 */
enum AgentPlacement
{
    /** The leader hosts it: the agent runs wherever leadership currently sits. */
    case LEADER;

    /** The placement policy picks the host node, and the agent stays there across terms. */
    case POLICY;
}

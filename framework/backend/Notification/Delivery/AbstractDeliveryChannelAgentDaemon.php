<?php

declare(strict_types=1);

namespace Hilos\Notification\Delivery;

use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;

/**
 * AbstractDeliveryChannelAgentDaemon - daemon proxy for a delivery channel agent (HIL-196).
 *
 * The daemon-side half a channel leaf pairs with its
 * {@see AbstractDeliveryChannelAgent}. It intentionally leaves the placement to the
 * concrete channel, because the two channel shapes place differently:
 *
 * - a monopolistic singleton channel (e.g. telegram) overrides
 *   {@see requiresMonopolisticProcess()} to return true and takes no index;
 * - a pooled channel (e.g. email) returns false, takes an `$agentIndex` in its
 *   constructor, and is registered with `AgentRegistryKey::INDEXED => true` so the
 *   dispatcher's `shardKey` fans across the pool.
 *
 * A concrete daemon also sets its own `AGENT_TYPE`, matching the channel agent it
 * proxies and the key it is registered under in Hilos::AGENTS.
 */
abstract class AbstractDeliveryChannelAgentDaemon extends AbstractAgentDaemon
{
}

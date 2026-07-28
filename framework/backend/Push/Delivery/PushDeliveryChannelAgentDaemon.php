<?php

declare(strict_types=1);

namespace Hilos\Push\Delivery;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;
use Hilos\Notification\Delivery\AbstractDeliveryChannelAgentDaemon;

/**
 * PushDeliveryChannelAgentDaemon - daemon proxy for the sharded web-push delivery agent (HIL-199).
 *
 * Places {@see PushDeliveryChannelAgent} as one instance of the cluster-global `hilos_push` pool:
 * non-monopolistic and not leader-pinned, so the leader's best-fit placement spreads the pool across
 * nodes (a notification is not pushed once from every node). Registered under
 * {@see HilosAgentType::HILOS_PUSH} with the pool's INDEXED flag; the constructor carries the shard
 * index. Mirrors {@see \Hilos\Mail\Delivery\MailDeliveryChannelAgentDaemon}.
 */
final class PushDeliveryChannelAgentDaemon extends AbstractDeliveryChannelAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_PUSH;

    /**
     * Binds this daemon proxy to its pool shard index.
     *
     * @param string $agentIndex Pool shard index (1..PUSH_WORKER_COUNT)
     * @throws AgentIndexRequiredException When agentIndex is empty
     */
    public function __construct(string $agentIndex)
    {
        if ($agentIndex === '') {
            throw new AgentIndexRequiredException('PushDeliveryChannelAgentDaemon requires a non-empty shard index');
        }
        $this->agentIndex = $agentIndex;
    }

    /**
     * The push pool fans across nodes, so its instances are not monopolistic.
     *
     * @return bool False: the push channel is a distributed pool
     */
    public function requiresMonopolisticProcess(): bool
    {
        return false;
    }

    /**
     * The pool spreads across all nodes rather than pinning to the cluster leader.
     *
     * @return bool False: pool instances run on any node
     */
    public function requiresClusterLeadership(): bool
    {
        return false;
    }
}

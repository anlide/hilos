<?php

declare(strict_types=1);

namespace Hilos\Sms\Delivery;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;
use Hilos\Notification\Delivery\AbstractDeliveryChannelAgentDaemon;

/**
 * SmsDeliveryChannelAgentDaemon - daemon proxy for the sharded SMS delivery agent (HIL-285).
 *
 * Places {@see SmsDeliveryChannelAgent} as one instance of the cluster-global `hilos_sms`
 * pool, mirroring the mail pool's placement: non-monopolistic and not leader-pinned, so the
 * leader's best-fit placement spreads the pool across nodes (a notification is not sent once
 * from every node). Registered under {@see HilosAgentType::HILOS_SMS} with the pool's INDEXED
 * flag; the constructor carries the shard index.
 */
final class SmsDeliveryChannelAgentDaemon extends AbstractDeliveryChannelAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_SMS;

    /**
     * Binds this daemon proxy to its pool shard index.
     *
     * @param string $agentIndex Pool shard index (1..SMS_WORKER_COUNT)
     * @throws AgentIndexRequiredException When agentIndex is empty
     */
    public function __construct(string $agentIndex)
    {
        if ($agentIndex === '') {
            throw new AgentIndexRequiredException('SmsDeliveryChannelAgentDaemon requires a non-empty shard index');
        }
        $this->agentIndex = $agentIndex;
    }

    /**
     * The SMS pool fans across nodes, so its instances are not monopolistic.
     *
     * @return bool False: the SMS channel is a distributed pool
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

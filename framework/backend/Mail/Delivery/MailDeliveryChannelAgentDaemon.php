<?php

declare(strict_types=1);

namespace Hilos\Mail\Delivery;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;
use Hilos\Notification\Delivery\AbstractDeliveryChannelAgentDaemon;

/**
 * MailDeliveryChannelAgentDaemon - daemon proxy for the sharded email delivery agent (HIL-197).
 *
 * Places {@see MailDeliveryChannelAgent} as one instance of the cluster-global
 * `hilos_mail` pool: non-monopolistic and not leader-pinned, so the leader's best-fit
 * placement spreads the pool across nodes (a notification is not mailed once from every
 * node). Registered under {@see HilosAgentType::HILOS_MAIL} with the pool's INDEXED flag;
 * the constructor carries the shard index.
 */
final class MailDeliveryChannelAgentDaemon extends AbstractDeliveryChannelAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_MAIL;

    /**
     * Binds this daemon proxy to its pool shard index.
     *
     * @param string $agentIndex Pool shard index (1..MAIL_WORKER_COUNT)
     * @throws AgentIndexRequiredException When agentIndex is empty
     */
    public function __construct(string $agentIndex)
    {
        if ($agentIndex === '') {
            throw new AgentIndexRequiredException('MailDeliveryChannelAgentDaemon requires a non-empty shard index');
        }
        $this->agentIndex = $agentIndex;
    }

    /**
     * The mail pool fans across nodes, so its instances are not monopolistic.
     *
     * @return bool False: the email channel is a distributed pool
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

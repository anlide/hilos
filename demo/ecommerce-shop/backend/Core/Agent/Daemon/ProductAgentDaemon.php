<?php

declare(strict_types=1);

namespace Demo\EcommerceShop\Core\Agent\Daemon;

use Demo\EcommerceShop\Constants\AgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;

/**
 * Daemon proxy for the agent of one product page address.
 *
 * One daemon per address the storefront is asked for (agentIndex = the address).
 */
final class ProductAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = AgentType::PRODUCT;

    /**
     * Creates the daemon proxy for the agent of one product page address.
     *
     * @param string $agentIndex The page address (non-empty)
     * @throws AgentIndexRequiredException When agentIndex is empty
     */
    public function __construct(string $agentIndex)
    {
        if ($agentIndex === '') {
            throw new AgentIndexRequiredException('ProductAgentDaemon requires non-empty agentIndex (product page address)');
        }
        $this->agentIndex = $agentIndex;
    }

    /**
     * The agent of a product page address writes nothing, so it shares a worker.
     *
     * @return bool False (the agent does not require a monopolistic worker)
     */
    public function requiresMonopolisticProcess(): bool
    {
        return false;
    }
}

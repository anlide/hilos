<?php

declare(strict_types=1);

namespace Hilos\Users\Agent;

use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;
use Hilos\Core\Agent\Exception\InvalidAgentIndexException;

/** Daemon proxy for an indexed person agent. Placement and lifetime come from the project registry. */
abstract class AbstractUserAgentDaemon extends AbstractAgentDaemon
{
    public const string AGENT_TYPE = HilosAgentType::HILOS_USER;

    /**
     * @param string $agentIndex Person id from the agent address
     * @throws AgentIndexRequiredException When the address omits the id
     * @throws InvalidAgentIndexException When the id is not a positive integer
     */
    public function __construct(string $agentIndex)
    {
        if ($agentIndex === '') {
            throw new AgentIndexRequiredException('User agent daemon requires a person id');
        }

        $userId = ctype_digit($agentIndex)
            ? filter_var($agentIndex, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        // The daemon record is keyed by the original address before this proxy is built.
        if ($userId === false || (string)$userId !== $agentIndex) {
            throw new InvalidAgentIndexException('User agent daemon requires a positive integer person id');
        }

        $this->agentIndex = (string)$userId;
    }

    /**
     * @return bool False: each person agent shares an ordinary worker
     */
    public function requiresMonopolisticProcess(): bool
    {
        return false;
    }
}

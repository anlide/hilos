<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Daemon\AbstractAgentDaemon;
use Hilos\Core\Agent\Exception\AgentIndexRequiredException;

/**
 * Helpers and daemon stubs for topology registry unit tests.
 */
final class TopologyAgentsTestSupport
{
    /**
     * @param class-string<AbstractAgent> $workerClass
     * @param class-string<AbstractAgentDaemon> $daemonClass
     * @return array<string, mixed>
     */
    public static function entry(string $workerClass, string $daemonClass, bool $indexed = false): array
    {
        $entry = [
            AgentRegistryKey::WORKER => $workerClass,
            AgentRegistryKey::DAEMON => $daemonClass,
        ];

        if ($indexed) {
            $entry[AgentRegistryKey::INDEXED] = true;
        }

        return $entry;
    }
}

final class TopologyIndexedFactoryAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'indexed_factory_agent';

    /**
     * @param string $agentIndex Indexed factory test agent index
     */
    public function __construct(string $agentIndex)
    {
        $this->agentIndex = $agentIndex;
    }

    /**
     * No-op stop hook for indexed factory tests.
     */
    public function onStop(): void
    {
    }
}

final class TopologyIndexedFactoryAgentDaemon extends TopologyTestAgentDaemon
{
    public const string AGENT_TYPE = 'indexed_factory_agent';

    /**
     * @param string $agentIndex Indexed factory test agent index
     */
    public function __construct(string $agentIndex)
    {
        $this->agentIndex = $agentIndex;
    }
}

final class TopologyValidAgentDaemon extends TopologyTestAgentDaemon
{
    public const string AGENT_TYPE = 'valid_agent';
}

final class TopologyMismatchedAgentDaemon extends TopologyTestAgentDaemon
{
    public const string AGENT_TYPE = 'wrong_agent';
}

final class TopologyNotAgentDaemon extends TopologyTestAgentDaemon
{
    public const string AGENT_TYPE = 'not_agent';
}

final class TopologyInvalidAgentSignalAgentDaemon extends TopologyTestAgentDaemon
{
    public const string AGENT_TYPE = 'invalid_agent_signal_agent';
}

final class TopologyFirstAgentSignalAgentDaemon extends TopologyTestAgentDaemon
{
    public const string AGENT_TYPE = 'first_agent_signal_agent';
}

final class TopologySecondAgentSignalAgentDaemon extends TopologyTestAgentDaemon
{
    public const string AGENT_TYPE = 'second_agent_signal_agent';
}

final class TopologyConflictingAgentDaemon extends TopologyTestAgentDaemon
{
    public const string AGENT_TYPE = 'conflicting_agent';
}

final class TopologyInvalidAgentCommandAgentDaemon extends TopologyTestAgentDaemon
{
    public const string AGENT_TYPE = 'invalid_agent_command_agent';
}

final class TopologyFirstAgentCommandAgentDaemon extends TopologyTestAgentDaemon
{
    public const string AGENT_TYPE = 'first_agent_command_agent';
}

final class TopologySecondAgentCommandAgentDaemon extends TopologyTestAgentDaemon
{
    public const string AGENT_TYPE = 'second_agent_command_agent';
}

final class TopologyBadCommandDtoAgentDaemon extends TopologyTestAgentDaemon
{
    public const string AGENT_TYPE = 'bad_command_dto_agent';
}

final class TopologyIndexedAgentDaemon extends TopologyTestAgentDaemon
{
    public const string AGENT_TYPE = 'indexed_agent';

    /**
     * @param string $agentIndex Agent index for indexed topology test agent
     * @throws AgentIndexRequiredException When agentIndex is empty
     */
    public function __construct(string $agentIndex)
    {
        if ($agentIndex === '') {
            throw new AgentIndexRequiredException('TopologyIndexedAgentDaemon requires non-empty agentIndex');
        }

        $this->agentIndex = $agentIndex;
    }
}

final class TopologyIndexedAgentMissingIndexFieldDaemon extends TopologyTestAgentDaemon
{
    public const string AGENT_TYPE = 'indexed_missing_field_agent';
}

final class TopologyIndexedAgentEmptyIndexFieldDaemon extends TopologyTestAgentDaemon
{
    public const string AGENT_TYPE = 'indexed_empty_field_agent';
}

final class TopologyIndexedAgentUnknownConfigKeyDaemon extends TopologyTestAgentDaemon
{
    public const string AGENT_TYPE = 'indexed_unknown_key_agent';
}

final class TopologyAgentSignalDtoAgentDaemon extends TopologyTestAgentDaemon
{
    public const string AGENT_TYPE = 'agent_signal_dto_agent';
}

final class TopologyInvalidAgentSignalDtoAgentDaemon extends TopologyTestAgentDaemon
{
    public const string AGENT_TYPE = 'invalid_agent_signal_dto_agent';
}

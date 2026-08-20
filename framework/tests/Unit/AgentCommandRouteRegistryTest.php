<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentCommandConfigKey;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Topology\AgentCommandRouteRegistry;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use PHPUnit\Framework\TestCase;

/**
 * Tests AgentCommandRouteRegistry aggregation of AGENT_COMMANDS across the three declaration shapes.
 */
final class AgentCommandRouteRegistryTest extends TestCase
{
    /**
     * @return array<string, array<string, mixed>> Agent registry for the fixtures
     */
    private function agents(): array
    {
        return [
            'list_agent' => [AgentRegistryKey::WORKER => CommandListRouteAgent::class],
            'dto_agent' => [AgentRegistryKey::WORKER => CommandDtoRouteAgent::class],
            'config_agent' => [AgentRegistryKey::WORKER => CommandConfigRouteAgent::class],
        ];
    }

    public function testRoutesAggregateEveryDeclarationShapeToItsAgentType(): void
    {
        $this->assertSame([
            'plain_a' => 'list_agent',
            'plain_b' => 'list_agent',
            'typed' => 'dto_agent',
            'configured' => 'config_agent',
            'configured_test_only' => 'config_agent',
            'configured_not_test_only' => 'config_agent',
        ], AgentCommandRouteRegistry::routes($this->agents()));
    }

    public function testDtoRoutesReturnOnlyCommandsThatDeclareAPayloadDto(): void
    {
        $this->assertSame([
            'typed' => CommandRequestDTO::class,
            'configured' => CommandRequestDTO::class,
        ], AgentCommandRouteRegistry::dtoRoutes($this->agents()));
    }

    public function testTestOnlyCommandsReturnOnlyTheFlaggedCommand(): void
    {
        $this->assertSame(['configured_test_only'], AgentCommandRouteRegistry::testOnlyCommands($this->agents()));
    }

    public function testRoutesSkipNonAgentRegistryEntries(): void
    {
        $this->assertSame([], AgentCommandRouteRegistry::routes([
            'not_agent' => [AgentRegistryKey::WORKER => CommandRequestDTO::class],
            0 => [AgentRegistryKey::WORKER => CommandListRouteAgent::class],
        ]));
    }
}

final class CommandListRouteAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'list_agent';

    public const array AGENT_COMMANDS = ['plain_a', 'plain_b'];

    public function onStop(): void
    {
    }
}

final class CommandDtoRouteAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'dto_agent';

    public const array AGENT_COMMANDS = ['typed' => CommandRequestDTO::class];

    public function onStop(): void
    {
    }
}

final class CommandConfigRouteAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'config_agent';

    public const array AGENT_COMMANDS = [
        'configured' => [AgentCommandConfigKey::DTO => CommandRequestDTO::class],
        'configured_test_only' => [AgentCommandConfigKey::TEST_ONLY => true],
        'configured_not_test_only' => [AgentCommandConfigKey::TEST_ONLY => false],
    ];

    public function onStop(): void
    {
    }
}

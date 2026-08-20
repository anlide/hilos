<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentCommandConfigKey;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Database\Context\DbContext;
use Hilos\Hilos as HilosFacade;
use Hilos\Socket\Command\TestOnlyCommandRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Tests the single registry the socket gate asks (HIL-566).
 *
 * What is worth proving here is the JOIN, not either half: the flag lives in two unrelated
 * places on purpose - an agent declares it beside the route it declares, and the three
 * commands the master answers itself belong to no agent at all - and the point of the class
 * is that the gate never has to know that.
 */
final class TestOnlyCommandRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        // Hand the captured project facade back, or the next test reads this one's agents.
        HilosFacade::initEnv(dirname(__DIR__), copyExample: false);

        parent::tearDown();
    }

    public function testTheMasterOwnThreeAreAlwaysInTheRegistry(): void
    {
        $commands = TestOnlyCommandRegistry::commands();

        $this->assertContains(CommandConstants::COMMAND_CLUSTER_INSPECT, $commands);
        $this->assertContains(CliCommands::PROTECTED_MODE_TEST_INSPECT, $commands);
        $this->assertContains(CommandConstants::COMMAND_CONNECTION_DROP, $commands);
    }

    public function testAProjectsFlaggedAgentCommandsJoinTheMastersOwn(): void
    {
        TestOnlyRegistryHilos::initEnv(dirname(__DIR__), copyExample: false);

        $this->assertSame([
            'registry_flagged_command',
            CommandConstants::COMMAND_CLUSTER_INSPECT,
            CliCommands::PROTECTED_MODE_TEST_INSPECT,
            CommandConstants::COMMAND_CONNECTION_DROP,
        ], TestOnlyCommandRegistry::commands());
    }

    public function testAnUnflaggedAgentCommandIsNotTestOnly(): void
    {
        TestOnlyRegistryHilos::initEnv(dirname(__DIR__), copyExample: false);

        $this->assertTrue(TestOnlyCommandRegistry::isTestOnly('registry_flagged_command'));
        $this->assertFalse(TestOnlyCommandRegistry::isTestOnly('registry_plain_command'));
    }

    public function testTheChannelsOperatorCommandsAreNotTestOnly(): void
    {
        $this->assertFalse(TestOnlyCommandRegistry::isTestOnly(CommandConstants::COMMAND_PING));
        $this->assertFalse(TestOnlyCommandRegistry::isTestOnly(CommandConstants::COMMAND_CLUSTER_NODES));
        $this->assertFalse(TestOnlyCommandRegistry::isTestOnly(CommandConstants::COMMAND_CLUSTER_RELOAD));
    }

    public function testAnUnknownNameIsNotTestOnly(): void
    {
        $this->assertFalse(TestOnlyCommandRegistry::isTestOnly('nothing:declares:this'));
    }
}

final class TestOnlyRegistryAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'test_only_registry_agent';

    public const array AGENT_COMMANDS = [
        'registry_flagged_command' => [AgentCommandConfigKey::TEST_ONLY => true],
        'registry_plain_command',
    ];

    public function onStop(): void
    {
    }
}

final class TestOnlyRegistryHilos extends HilosFacade
{
    public const array AGENTS = [
        TestOnlyRegistryAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => TestOnlyRegistryAgent::class],
    ];

    /**
     * Creates a DB context the fixture never touches.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new TestOnlyRegistryDbContext();
    }
}

final class TestOnlyRegistryDbContext extends DbContext
{
    /**
     * No-op DB configuration for the registry fixture.
     */
    public function configure(): void
    {
    }
}

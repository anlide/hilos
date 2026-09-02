<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Database\Context\DbContext;
use Hilos\Hilos as HilosFacade;
use Hilos\Socket\Command\TestOnlyCommandRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Tests the single registry the socket gate asks (HIL-566, rebuilt on the prefix in HIL-742).
 *
 * What was worth proving here used to be the JOIN of two unrelated halves - a flag an agent
 * declared beside its route, and a list of the names the master answered itself. There is no
 * join any more: the name declares it, and the registry reads
 * {@see CommandConstants::TEST_ONLY_PREFIX}.
 *
 * So what is worth proving now is the property that replaced the join, and it is stronger than
 * anything the flag could offer: the answer does not depend on the installation. Under the flag
 * it did - a name was test-only only where an agent that declared it was registered - which is
 * why the CLI-side latch had to refuse to ask this class at all. The fixture below is here for
 * that one claim: swapping the project facade changes nothing about the verdict.
 */
final class TestOnlyCommandRegistryTest extends TestCase
{
    /** @var string A test-only name no agent of any project declares */
    private const string UNDECLARED_TEST_NAME = 'test:nothing:declares:this';

    protected function tearDown(): void
    {
        // Hand the captured project facade back, or the next test reads this one's agents.
        HilosFacade::initEnv(dirname(__DIR__));

        parent::tearDown();
    }

    public function testAPrefixedNameIsTestOnlyAndAPlainOneIsNot(): void
    {
        $this->assertTrue(TestOnlyCommandRegistry::isTestOnly(CliCommands::CLUSTER_TEST_INSPECT));
        $this->assertTrue(TestOnlyCommandRegistry::isTestOnly(CliCommands::DB_TEST_RESET));
        $this->assertFalse(TestOnlyCommandRegistry::isTestOnly(CommandConstants::COMMAND_PING));
        $this->assertFalse(TestOnlyCommandRegistry::isTestOnly(CliCommands::CLUSTER_NODES));
        $this->assertFalse(TestOnlyCommandRegistry::isTestOnly(CliCommands::CLUSTER_RELOAD));
    }

    public function testANameNoAgentDeclaresIsJudgedAllTheSame(): void
    {
        $this->assertTrue(TestOnlyCommandRegistry::isTestOnly(self::UNDECLARED_TEST_NAME));
        $this->assertFalse(TestOnlyCommandRegistry::isTestOnly('nothing:declares:this'));
    }

    public function testTheVerdictDoesNotDependOnWhatTheProjectRegisters(): void
    {
        $beforeDeclared = TestOnlyCommandRegistry::isTestOnly(TestOnlyRegistryAgent::TEST_COMMAND);
        $beforePlain = TestOnlyCommandRegistry::isTestOnly(TestOnlyRegistryAgent::PLAIN_COMMAND);

        TestOnlyRegistryHilos::initEnv(dirname(__DIR__));

        $this->assertSame($beforeDeclared, TestOnlyCommandRegistry::isTestOnly(TestOnlyRegistryAgent::TEST_COMMAND));
        $this->assertSame($beforePlain, TestOnlyCommandRegistry::isTestOnly(TestOnlyRegistryAgent::PLAIN_COMMAND));
        $this->assertTrue(TestOnlyCommandRegistry::isTestOnly(TestOnlyRegistryAgent::TEST_COMMAND));
        $this->assertFalse(TestOnlyCommandRegistry::isTestOnly(TestOnlyRegistryAgent::PLAIN_COMMAND));
    }
}

final class TestOnlyRegistryAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'test_only_registry_agent';

    /** @var string Agent-owned command whose name declares it test-only */
    public const string TEST_COMMAND = 'test:registry:fixture';

    /** @var string Agent-owned command reachable on any node */
    public const string PLAIN_COMMAND = 'registry:fixture';

    public const array AGENT_COMMANDS = [
        self::TEST_COMMAND,
        self::PLAIN_COMMAND,
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

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\CLI\Exception\TestOnlyCommandOnProductionException;
use Hilos\Core\Router\SignalRouter;
use Hilos\Database\Context\DbContext;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Client\CommandClient;
use Hilos\Socket\Server\CommandServer;
use Hilos\Utils\Helpers\JsonHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Socket;

/**
 * Tests the command socket refusing a test-only command on a production-like node (HIL-566).
 *
 * This is the boundary the feature exists for: the command channel authenticates nobody and
 * binds where the operator points it, so on a production installation the only thing between
 * a reachable port and a database reset, a freeze, or a dropped connection is this refusal.
 * The CLI-side guard cannot stand in for it - it protects the process that SENDS the command,
 * which an attacker is not using.
 *
 * Every case names APP_ENV explicitly rather than leaning on a default, because the verdict
 * under test is precisely what that value decides.
 */
final class CommandClientTestOnlyGateTest extends TestCase
{
    /** @var ?EnvAccessor Previous env accessor to restore after the test */
    private ?EnvAccessor $previousEnv = null;

    /** @var list<Socket> Sockets kept alive for the client under test */
    private array $sockets = [];

    protected function setUp(): void
    {
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        putenv('SOCKET_READ_BUFFER_SIZE=65536');
    }

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            @socket_close($socket);
        }
        $this->sockets = [];

        Hilos::$env = $this->previousEnv;
        Hilos::$sr = null;
        // Hand the captured project facade back, or the next test reads this fixture's agents.
        Hilos::initEnv(dirname(__DIR__));
        putenv('SOCKET_READ_BUFFER_SIZE');
        putenv('APP_ENV=test');

        parent::tearDown();
    }

    /**
     * @return list<array{0: string}> Test-only command names the master answers itself
     */
    public static function masterTestOnlyCommands(): array
    {
        return [
            [CliCommands::CLUSTER_TEST_INSPECT],
            [CliCommands::PROTECTED_MODE_TEST_INSPECT],
            [CliCommands::CONNECTION_TEST_DROP],
        ];
    }

    /**
     * @param string $command Command name under test
     */
    #[DataProvider('masterTestOnlyCommands')]
    public function testATestOnlyCommandIsRefusedOnProduction(string $command): void
    {
        $client = $this->clientOn('prod');

        $client->feed([
            CommandConstants::FIELD_CORRELATION_ID => 'corr-1',
            CommandConstants::FIELD_COMMAND => $command,
            CommandConstants::FIELD_PAYLOAD => [],
        ]);

        $this->assertSame(CommandConstants::STATUS_ERROR, $client->lastReply()[CommandConstants::FIELD_STATUS] ?? null);
        $this->assertSame('corr-1', $client->lastReply()[CommandConstants::FIELD_CORRELATION_ID] ?? null);
    }

    /**
     * @return list<array{0: string}> Environments that admit a test-only command
     */
    public static function admittingEnvironments(): array
    {
        return [['dev'], ['local'], ['test']];
    }

    /**
     * @param string $command Command name under test
     */
    #[DataProvider('masterTestOnlyCommands')]
    public function testATestOnlyCommandIsRefusedOnStaging(string $command): void
    {
        $client = $this->clientOn('staging');

        $client->feed([
            CommandConstants::FIELD_CORRELATION_ID => 'corr-2',
            CommandConstants::FIELD_COMMAND => $command,
            CommandConstants::FIELD_PAYLOAD => [],
        ]);

        $this->assertSame(CommandConstants::STATUS_ERROR, $client->lastReply()[CommandConstants::FIELD_STATUS] ?? null);
    }

    public function testATestOnlyCommandIsRefusedOnAnUnrecognizedEnv(): void
    {
        $client = $this->clientOn('weekend-box');

        $client->feed([
            CommandConstants::FIELD_CORRELATION_ID => 'corr-3',
            CommandConstants::FIELD_COMMAND => CliCommands::CLUSTER_TEST_INSPECT,
            CommandConstants::FIELD_PAYLOAD => [],
        ]);

        $this->assertSame(CommandConstants::STATUS_ERROR, $client->lastReply()[CommandConstants::FIELD_STATUS] ?? null);
    }

    /**
     * The refusal has to read the same whichever transport delivered it: a caller that drives
     * one command both ways must not have to recognize two wordings for one verdict.
     */
    public function testTheRefusalCarriesTheSameSentenceTheCliRaises(): void
    {
        $client = $this->clientOn('prod');

        $client->feed([
            CommandConstants::FIELD_CORRELATION_ID => 'corr-4',
            CommandConstants::FIELD_COMMAND => CliCommands::CONNECTION_TEST_DROP,
            CommandConstants::FIELD_PAYLOAD => [],
        ]);

        $this->assertSame(
            TestOnlyCommandOnProductionException::message(CliCommands::CONNECTION_TEST_DROP),
            $client->lastReply()[CommandConstants::FIELD_PAYLOAD][CommandConstants::FIELD_MESSAGE] ?? null,
        );
    }

    /**
     * The gate judges the command, not the connection: a refused command must not cost the
     * caller its socket, or a CLI would read a transport failure where it was told no.
     */
    public function testARefusedCommandLeavesTheConnectionOpen(): void
    {
        $client = $this->clientOn('prod');

        $client->feed([
            CommandConstants::FIELD_CORRELATION_ID => 'corr-5',
            CommandConstants::FIELD_COMMAND => CliCommands::CLUSTER_TEST_INSPECT,
            CommandConstants::FIELD_PAYLOAD => [],
        ]);

        $this->assertFalse($client->shouldClose());
    }

    /**
     * @param string $appEnv APP_ENV value the node reports
     */
    #[DataProvider('admittingEnvironments')]
    public function testATestOnlyCommandIsAdmittedOnANonProductionNode(string $appEnv): void
    {
        $client = $this->clientOn($appEnv);

        $client->feed([
            CommandConstants::FIELD_CORRELATION_ID => 'corr-6',
            CommandConstants::FIELD_COMMAND => CliCommands::CLUSTER_TEST_INSPECT,
            CommandConstants::FIELD_PAYLOAD => [],
        ]);

        $this->assertSame(CommandConstants::STATUS_OK, $client->lastReply()[CommandConstants::FIELD_STATUS] ?? null);
    }

    /**
     * The half the master does not answer itself: an AGENT-owned test-only command is refused
     * at the same point, before it is ever parked and routed. This is what the two agent-side
     * copies of the verdict used to do (HIL-566 removed them), and it only holds because the
     * gate sits above the split between master branches and parked commands.
     */
    public function testAnAgentOwnedTestOnlyCommandIsRefusedBeforeItIsParked(): void
    {
        GateFixtureHilos::initEnv(dirname(__DIR__));
        $client = $this->clientOn('prod');

        $client->feed([
            CommandConstants::FIELD_CORRELATION_ID => 'corr-10',
            CommandConstants::FIELD_COMMAND => 'test:gate:fixture',
            CommandConstants::FIELD_PAYLOAD => [],
        ]);

        $this->assertSame(
            TestOnlyCommandOnProductionException::message('test:gate:fixture'),
            $client->lastReply()[CommandConstants::FIELD_PAYLOAD][CommandConstants::FIELD_MESSAGE] ?? null,
        );
    }

    public function testAnAgentOwnedTestOnlyCommandIsParkedOnATestNode(): void
    {
        GateFixtureHilos::initEnv(dirname(__DIR__));
        Hilos::$sr = new SignalRouter();
        $client = $this->clientOn('test');

        $client->feed([
            CommandConstants::FIELD_CORRELATION_ID => 'corr-11',
            CommandConstants::FIELD_COMMAND => 'test:gate:fixture',
            CommandConstants::FIELD_PAYLOAD => [],
        ]);

        // Parked, not answered: the reply comes back from the agent later.
        $this->assertSame([], $client->lastReply());
        $this->assertNotNull(Hilos::$sr->getNextQueuedSignal());
    }

    public function testPingStillAnswersOkOnProduction(): void
    {
        $client = $this->clientOn('prod');

        $client->feed([
            CommandConstants::FIELD_CORRELATION_ID => 'corr-7',
            CommandConstants::FIELD_COMMAND => CommandConstants::COMMAND_PING,
            CommandConstants::FIELD_PAYLOAD => [],
        ]);

        $this->assertSame(CommandConstants::STATUS_OK, $client->lastReply()[CommandConstants::FIELD_STATUS] ?? null);
    }

    /**
     * A `ping` and a test-only command down ONE connection get different answers - the gate
     * carries no per-connection memory to poison.
     */
    public function testTheGateJudgesTheCommandAndNotTheConnection(): void
    {
        $client = $this->clientOn('prod');

        $client->feed([
            CommandConstants::FIELD_CORRELATION_ID => 'corr-8',
            CommandConstants::FIELD_COMMAND => CommandConstants::COMMAND_PING,
            CommandConstants::FIELD_PAYLOAD => [],
        ]);
        $this->assertSame(CommandConstants::STATUS_OK, $client->lastReply()[CommandConstants::FIELD_STATUS] ?? null);

        $client->feed([
            CommandConstants::FIELD_CORRELATION_ID => 'corr-9',
            CommandConstants::FIELD_COMMAND => CliCommands::CLUSTER_TEST_INSPECT,
            CommandConstants::FIELD_PAYLOAD => [],
        ]);
        $this->assertSame(CommandConstants::STATUS_ERROR, $client->lastReply()[CommandConstants::FIELD_STATUS] ?? null);
    }

    /**
     * Builds a command client over a socket pair the test owns, on the named environment.
     *
     * @param string $appEnv APP_ENV value the node reports
     * @return CommandClientTestOnlyGateTestClient Client under test
     */
    private function clientOn(string $appEnv): CommandClientTestOnlyGateTestClient
    {
        putenv("APP_ENV={$appEnv}");
        Hilos::$env = new EnvAccessor();

        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        $this->sockets[] = $pair[0];
        $this->sockets[] = $pair[1];

        return new CommandClientTestOnlyGateTestClient($pair[0], new CommandServer('127.0.0.1', 0));
    }
}

/**
 * Command client that takes a request straight from the test instead of the socket.
 */
final class CommandClientTestOnlyGateTestClient extends CommandClient
{
    /**
     * Hands one request to the read-buffer parser as the CLI would send it.
     *
     * @param array<string, mixed> $request Request payload
     */
    public function feed(array $request): void
    {
        $this->readBuffer .= json_encode($request) . "\n";
        $this->processReadBuffer();
    }

    /**
     * Reads back the reply the parser queued for the CLI.
     *
     * @return array<string, mixed> Decoded reply, or an empty array when none was queued
     */
    public function lastReply(): array
    {
        $lines = array_filter(explode("\n", $this->writeBuffer), static fn(string $line): bool => $line !== '');
        $reply = JsonHelper::tryDecode((string) end($lines));

        return is_array($reply) ? $reply : [];
    }
}

final class GateFixtureAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'gate_fixture_agent';

    public const array AGENT_COMMANDS = [
        'test:gate:fixture',
    ];

    public function onStop(): void
    {
    }
}

final class GateFixtureHilos extends Hilos
{
    public const array AGENTS = [
        GateFixtureAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => GateFixtureAgent::class],
    ];

    /**
     * Creates a DB context the fixture never touches.
     *
     * @return DbContext Test DB context
     */
    protected static function createDb(): DbContext
    {
        return new GateFixtureDbContext();
    }
}

final class GateFixtureDbContext extends DbContext
{
    /**
     * No-op DB configuration for the gate fixture.
     */
    public function configure(): void
    {
    }
}

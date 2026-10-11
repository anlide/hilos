<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\CliCommands;
use Hilos\Cluster\Peer\DTO\PeerAgentRestartDTO;
use Hilos\Constants\CommandConstants;
use Hilos\Core\Daemon\AgentRestartDoor;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Client\CommandClient;
use Hilos\Socket\Server\CommandServer;
use Hilos\Socket\Command\DTO\CommandRequestDTO;
use Hilos\Utils\Helpers\JsonHelper;
use PHPUnit\Framework\TestCase;
use Socket;

/** The restart command holds its caller and enters the master without an agent request signal. */
final class CommandClientAgentRestartTest extends TestCase
{
    private ?EnvAccessor $previousEnv;
    private ?SignalRouter $previousRouter;

    /** @var list<Socket> */
    private array $sockets = [];

    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
        $this->previousRouter = Hilos::$sr;
        putenv('SOCKET_READ_BUFFER_SIZE=65536');
        Hilos::$env = new EnvAccessor();
        Hilos::$sr = new SignalRouter();
    }

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        Hilos::$env = $this->previousEnv;
        Hilos::$sr = $this->previousRouter;
        putenv('SOCKET_READ_BUFFER_SIZE');
        parent::tearDown();
    }

    public function testTheMasterDoorReceivesAHeldRequestWithoutAgentRouting(): void
    {
        $server = new AgentRestartTestCommandServer();
        $door = new AgentRestartTestDoor();
        $server->setAgentRestartDoor($door);
        $client = $this->client($server);

        $client->feed($this->request());

        self::assertSame(['c1'], $server->held);
        self::assertCount(1, $door->requests);
        self::assertSame(CliCommands::AGENT_RESTART, $door->requests[0]->command);
        self::assertSame(null, Hilos::$sr?->getNextQueuedSignal());
        self::assertSame([], $client->replies());

        $client->feed($this->request('c2'));
        self::assertCount(1, $door->requests, 'A held connection parses no second command');
    }

    public function testMissingDoorRefusesImmediately(): void
    {
        $server = new AgentRestartTestCommandServer();
        $client = $this->client($server);

        $client->feed($this->request());

        self::assertSame([], $server->held);
        self::assertSame('This daemon restarts no agents', $client->replies()[0][CommandConstants::FIELD_PAYLOAD][CommandConstants::FIELD_MESSAGE]);
        self::assertSame(null, Hilos::$sr?->getNextQueuedSignal());
    }

    /** @param AgentRestartTestCommandServer $server Server holding the test socket */
    private function client(AgentRestartTestCommandServer $server): AgentRestartTestCommandClient
    {
        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        $this->sockets[] = $pair[0];
        $this->sockets[] = $pair[1];
        return new AgentRestartTestCommandClient($pair[0], $server);
    }

    /**
     * @param string $correlationId Request id
     * @return array<string, mixed> Command wire request
     */
    private function request(string $correlationId = 'c1'): array
    {
        return [
            CommandConstants::FIELD_CORRELATION_ID => $correlationId,
            CommandConstants::FIELD_COMMAND => CliCommands::AGENT_RESTART,
            CommandConstants::FIELD_PAYLOAD => [
                CommandConstants::FIELD_AGENT_TYPE => 'replica',
                CommandConstants::FIELD_AGENT_INDEX => null,
                CommandConstants::FIELD_NODE_ID => null,
            ],
        ];
    }
}

/** Captures the command hold without a live listener. */
final class AgentRestartTestCommandServer extends CommandServer
{
    /** @var list<string> */
    public array $held = [];

    public function __construct()
    {
    }

    /**
     * @param string $correlationId Held command id
     * @param CommandClient $client Client held for reply
     */
    public function hold(string $correlationId, CommandClient $client): void
    {
        $this->held[] = $correlationId;
    }
}

/** Captures requests passed to the master's restart seam. */
final class AgentRestartTestDoor implements AgentRestartDoor
{
    /** @var list<CommandRequestDTO> */
    public array $requests = [];

    /** @param CommandRequestDTO $request Held operator request */
    public function requestAgentRestart(CommandRequestDTO $request): void
    {
        $this->requests[] = $request;
    }

    /** @param PeerAgentRestartDTO $frame Peer restart request */
    public function requestPeerAgentRestart(PeerAgentRestartDTO $frame): void
    {
    }
}

/** Exposes only the command parser and its queued reply lines. */
final class AgentRestartTestCommandClient extends CommandClient
{
    /** @param array<string, mixed> $request Wire request */
    public function feed(array $request): void
    {
        $this->readBuffer .= json_encode($request) . "\n";
        $this->processReadBuffer();
    }

    /** @return list<array<string, mixed>> Replies queued on this socket */
    public function replies(): array
    {
        $replies = [];
        foreach (array_filter(explode("\n", $this->writeBuffer)) as $line) {
            $decoded = JsonHelper::tryDecode($line);
            if (is_array($decoded)) {
                $replies[] = $decoded;
            }
        }
        return $replies;
    }
}

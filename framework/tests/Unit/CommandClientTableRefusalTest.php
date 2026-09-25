<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\TableRefusalRuntime as StateTableRefusalRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Client\CommandClient;
use Hilos\Socket\Server\CommandServer;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\JsonHelper;
use PHPUnit\Framework\TestCase;
use Socket;

/**
 * Tests the master answering test:table:refuse itself (HIL-1131).
 *
 * The master is the only writer of the refusal row, and the workers that build the windows read it
 * only through RT sync, so what is pinned here is the write and what the caller is told about it:
 * a valid request lands on the row and goes on the sync wire, the reply reads the row back, a
 * request without a key takes the refusal off, and a key that is not a string leaves the row
 * exactly as it was.
 */
final class CommandClientTableRefusalTest extends TestCase
{
    private ?EnvAccessor $previousEnv = null;

    private ?RtContext $previousRt = null;

    private ?SignalRouter $previousSignalRouter = null;

    /** @var list<Socket> Sockets kept alive for the client under test */
    private array $sockets = [];

    protected function setUp(): void
    {
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;
        $this->previousRt = Hilos::$rt;
        $this->previousSignalRouter = Hilos::$sr;
        putenv('SOCKET_READ_BUFFER_SIZE=65536');
        putenv('APP_ENV=test');
        Hilos::$env = new EnvAccessor();
        Hilos::$sr = new SignalRouter();
        Hilos::$rt = new TableRefusalTestRtContext();
        Hilos::$rt->mountFeatureRuntime([]);
        // The master registers itself the same way at daemon start; without it the write would be
        // refused as a write from nowhere.
        RtTruthSourceRegistry::registerDaemon(StateTableRefusalRuntime::RT_ITEM);
    }

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        $this->sockets = [];

        RtTruthSourceRegistry::unregisterDaemon(StateTableRefusalRuntime::RT_ITEM);
        Hilos::$rt = $this->previousRt;
        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$env = $this->previousEnv;
        putenv('SOCKET_READ_BUFFER_SIZE');

        parent::tearDown();
    }

    public function testTheReplyCarriesTheTableTheRowNowHolds(): void
    {
        $reply = $this->ask([CommandConstants::FIELD_TABLE_KEY => 'hilosSecurityStepUp']);

        $this->assertSame(CommandConstants::STATUS_OK, $reply[CommandConstants::FIELD_STATUS] ?? null);
        $this->assertSame(
            [CommandConstants::FIELD_TABLE_KEY => 'hilosSecurityStepUp'],
            $reply[CommandConstants::FIELD_PAYLOAD] ?? null,
        );
        $this->assertSame('hilosSecurityStepUp', Hilos::$rt?->hilosTableRefusalRuntime?->tableKey);
    }

    public function testTheWriteGoesOnTheSyncWireForTheWorkers(): void
    {
        $this->ask([CommandConstants::FIELD_TABLE_KEY => 'settings']);

        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertInstanceOf(RtSyncUpdatedSignalData::class, $signal->data);
        $this->assertSame(StateTableRefusalRuntime::RT_ITEM, $signal->data->collectionKey);
        $this->assertSame([StateTableRefusalRuntime::tableKey => 'settings'], $signal->data->row);
    }

    public function testARequestWithoutAKeyTakesTheRefusalOff(): void
    {
        $this->ask([CommandConstants::FIELD_TABLE_KEY => 'settings']);

        $reply = $this->ask([]);

        $this->assertSame(CommandConstants::STATUS_OK, $reply[CommandConstants::FIELD_STATUS] ?? null);
        $this->assertSame([CommandConstants::FIELD_TABLE_KEY => ''], $reply[CommandConstants::FIELD_PAYLOAD] ?? null);
        $this->assertSame('', Hilos::$rt?->hilosTableRefusalRuntime?->tableKey);
    }

    public function testAKeyThatIsNotAStringIsRefusedAndTheRowIsLeftAlone(): void
    {
        $this->ask([CommandConstants::FIELD_TABLE_KEY => 'settings']);

        $reply = $this->ask([CommandConstants::FIELD_TABLE_KEY => 7]);

        $this->assertSame(CommandConstants::STATUS_ERROR, $reply[CommandConstants::FIELD_STATUS] ?? null);
        $this->assertSame(
            'tableKey must be a string',
            $reply[CommandConstants::FIELD_PAYLOAD][CommandConstants::FIELD_MESSAGE] ?? null,
        );
        $this->assertSame('settings', Hilos::$rt?->hilosTableRefusalRuntime?->tableKey);
    }

    public function testANodeWithoutRuntimeStateRefusesInsteadOfThrowing(): void
    {
        Hilos::$rt = null;

        $reply = $this->ask([CommandConstants::FIELD_TABLE_KEY => 'settings']);

        $this->assertSame(CommandConstants::STATUS_ERROR, $reply[CommandConstants::FIELD_STATUS] ?? null);
        $this->assertSame(
            'This node holds no runtime state for a table refusal',
            $reply[CommandConstants::FIELD_PAYLOAD][CommandConstants::FIELD_MESSAGE] ?? null,
        );
    }

    /**
     * Sends one test:table:refuse request to a fresh command client and reads its reply.
     *
     * @param array<string, mixed> $payload Request payload
     * @return array<string, mixed> Decoded reply
     */
    private function ask(array $payload): array
    {
        $pair = [];
        socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair);
        $this->sockets[] = $pair[0];
        $this->sockets[] = $pair[1];

        $client = new TableRefusalTestCommandClient($pair[0], new CommandServer('127.0.0.1', 0));
        $client->feed([
            CommandConstants::FIELD_CORRELATION_ID => 'corr-refusal',
            CommandConstants::FIELD_COMMAND => CliCommands::TABLE_TEST_REFUSE,
            CommandConstants::FIELD_PAYLOAD => $payload,
        ]);

        return $client->lastReply();
    }
}

/**
 * Command client that takes a request straight from the test instead of the socket.
 */
final class TableRefusalTestCommandClient extends CommandClient
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

/**
 * Runtime context of a project that mounts nothing of its own.
 *
 * The refusal row is framework-owned and mounted for every project, so the master finds it here
 * exactly as it does in a real daemon.
 */
final class TableRefusalTestRtContext extends RtContext
{
    public function configure(): void
    {
    }
}

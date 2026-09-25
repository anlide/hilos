<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\TableLagRuntime as StateTableLagRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Client\CommandClient;
use Hilos\Socket\Server\CommandServer;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\JsonHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Socket;

/**
 * Tests the master answering test:table:lag itself (HIL-1020).
 *
 * The master is the only writer of the lag row, and the workers that serve the tables read it
 * only through RT sync, so what is pinned here is the write and what the caller is told about
 * it: a valid request lands on the row and goes on the sync wire, the reply reads the row back,
 * a lag the request leaves out is written as zero, and a value the queue could not read leaves
 * the row exactly as it was.
 */
final class CommandClientTableLagTest extends TestCase
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
        Hilos::$rt = new TableLagTestRtContext();
        Hilos::$rt->mountFeatureRuntime([]);
        // The master registers itself the same way at daemon start; without it the write would be
        // refused as a write from nowhere.
        RtTruthSourceRegistry::registerDaemon(StateTableLagRuntime::RT_ITEM);
    }

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        $this->sockets = [];

        RtTruthSourceRegistry::unregisterDaemon(StateTableLagRuntime::RT_ITEM);
        Hilos::$rt = $this->previousRt;
        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$env = $this->previousEnv;
        putenv('SOCKET_READ_BUFFER_SIZE');

        parent::tearDown();
    }

    public function testTheReplyCarriesTheLagTheRowNowHolds(): void
    {
        $reply = $this->ask([
            CommandConstants::FIELD_WINDOW_MS => 60000,
            CommandConstants::FIELD_FACETS_MS => 1500,
        ]);

        $this->assertSame(CommandConstants::STATUS_OK, $reply[CommandConstants::FIELD_STATUS] ?? null);
        $this->assertSame([
            CommandConstants::FIELD_WINDOW_MS => 60000,
            CommandConstants::FIELD_FACETS_MS => 1500,
        ], $reply[CommandConstants::FIELD_PAYLOAD] ?? null);
        $this->assertSame(60000, Hilos::$rt?->hilosTableLagRuntime?->windowMs);
        $this->assertSame(1500, Hilos::$rt?->hilosTableLagRuntime?->facetsMs);
    }

    public function testTheWriteGoesOnTheSyncWireForTheWorkers(): void
    {
        $this->ask([CommandConstants::FIELD_WINDOW_MS => 60000]);

        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertInstanceOf(RtSyncUpdatedSignalData::class, $signal->data);
        $this->assertSame(StateTableLagRuntime::RT_ITEM, $signal->data->collectionKey);
        $this->assertSame([StateTableLagRuntime::windowMs => 60000], $signal->data->row);
    }

    public function testALagTheRequestLeavesOutIsWrittenAsZero(): void
    {
        $this->ask([
            CommandConstants::FIELD_WINDOW_MS => 60000,
            CommandConstants::FIELD_FACETS_MS => 1500,
        ]);

        $reply = $this->ask([CommandConstants::FIELD_FACETS_MS => 200]);

        $this->assertSame([
            CommandConstants::FIELD_WINDOW_MS => 0,
            CommandConstants::FIELD_FACETS_MS => 200,
        ], $reply[CommandConstants::FIELD_PAYLOAD] ?? null);
        $this->assertSame(0, Hilos::$rt?->hilosTableLagRuntime?->windowMs);
    }

    public function testABareRequestTakesBothLagsOff(): void
    {
        $this->ask([
            CommandConstants::FIELD_WINDOW_MS => 60000,
            CommandConstants::FIELD_FACETS_MS => 1500,
        ]);

        $reply = $this->ask([]);

        $this->assertSame(CommandConstants::STATUS_OK, $reply[CommandConstants::FIELD_STATUS] ?? null);
        $this->assertSame(0, Hilos::$rt?->hilosTableLagRuntime?->windowMs);
        $this->assertSame(0, Hilos::$rt?->hilosTableLagRuntime?->facetsMs);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}> Payload and the field its refusal names
     */
    public static function unreadableLags(): array
    {
        return [
            'negative window' => [[CommandConstants::FIELD_WINDOW_MS => -1], CommandConstants::FIELD_WINDOW_MS],
            'fractional window' => [[CommandConstants::FIELD_WINDOW_MS => 1.5], CommandConstants::FIELD_WINDOW_MS],
            'window as text' => [[CommandConstants::FIELD_WINDOW_MS => '100'], CommandConstants::FIELD_WINDOW_MS],
            'negative facets' => [
                [CommandConstants::FIELD_WINDOW_MS => 100, CommandConstants::FIELD_FACETS_MS => -5],
                CommandConstants::FIELD_FACETS_MS,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload Request payload carrying an unreadable lag
     * @param string $field Payload field the refusal has to name
     */
    #[DataProvider('unreadableLags')]
    public function testAnUnreadableLagIsRefusedAndTheRowIsLeftAlone(array $payload, string $field): void
    {
        $this->ask([
            CommandConstants::FIELD_WINDOW_MS => 700,
            CommandConstants::FIELD_FACETS_MS => 300,
        ]);

        $reply = $this->ask($payload);

        $this->assertSame(CommandConstants::STATUS_ERROR, $reply[CommandConstants::FIELD_STATUS] ?? null);
        $this->assertSame(
            "{$field} must be a whole number of milliseconds, 0 or more",
            $reply[CommandConstants::FIELD_PAYLOAD][CommandConstants::FIELD_MESSAGE] ?? null,
        );
        $this->assertSame(700, Hilos::$rt?->hilosTableLagRuntime?->windowMs);
        $this->assertSame(300, Hilos::$rt?->hilosTableLagRuntime?->facetsMs);
    }

    public function testANodeWithoutRuntimeStateRefusesInsteadOfThrowing(): void
    {
        Hilos::$rt = null;

        $reply = $this->ask([CommandConstants::FIELD_WINDOW_MS => 100]);

        $this->assertSame(CommandConstants::STATUS_ERROR, $reply[CommandConstants::FIELD_STATUS] ?? null);
        $this->assertSame(
            'This node holds no runtime state for a table lag',
            $reply[CommandConstants::FIELD_PAYLOAD][CommandConstants::FIELD_MESSAGE] ?? null,
        );
    }

    /**
     * Sends one test:table:lag request to a fresh command client and reads its reply.
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

        $client = new TableLagTestCommandClient($pair[0], new CommandServer('127.0.0.1', 0));
        $client->feed([
            CommandConstants::FIELD_CORRELATION_ID => 'corr-lag',
            CommandConstants::FIELD_COMMAND => CliCommands::TABLE_TEST_LAG,
            CommandConstants::FIELD_PAYLOAD => $payload,
        ]);

        return $client->lastReply();
    }
}

/**
 * Command client that takes a request straight from the test instead of the socket.
 */
final class TableLagTestCommandClient extends CommandClient
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
 * The lag row is framework-owned and mounted for every project, so the master finds it here
 * exactly as it does in a real daemon.
 */
final class TableLagTestRtContext extends RtContext
{
    public function configure(): void
    {
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Constants\CliCommands;
use Hilos\Constants\CommandConstants;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\AdminViewModeRuntime as StateAdminViewModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Client\CommandClient;
use Hilos\Socket\Server\CommandServer;
use Hilos\TruthSource\RtTruthSourceRegistry;
use Hilos\Utils\Helpers\JsonHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Socket;

/**
 * Tests the master answering test:admin-view-mode itself (HIL-1249).
 *
 * The master is the only writer of the mode row, and the workers that answer the viewers read it
 * only through RT sync, so what is pinned here is the write and what the caller is told about it:
 * a valid request lands on the row and goes on the sync wire, the reply reads the row back, and a
 * request that does not say on or off outright leaves the row exactly as it was.
 */
final class CommandClientAdminViewModeTest extends TestCase
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
        Hilos::$rt = new AdminViewModeTestRtContext();
        Hilos::$rt->mountFeatureRuntime([]);
        // The master registers itself the same way at daemon start; without it the write would be
        // refused as a write from nowhere.
        RtTruthSourceRegistry::registerDaemon(StateAdminViewModeRuntime::RT_ITEM);
    }

    protected function tearDown(): void
    {
        foreach ($this->sockets as $socket) {
            socket_close($socket);
        }
        $this->sockets = [];

        RtTruthSourceRegistry::unregisterDaemon(StateAdminViewModeRuntime::RT_ITEM);
        Hilos::$rt = $this->previousRt;
        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$env = $this->previousEnv;
        putenv('SOCKET_READ_BUFFER_SIZE');

        parent::tearDown();
    }

    public function testTheReplyCarriesTheModeTheRowNowHolds(): void
    {
        $reply = $this->ask([CommandConstants::FIELD_ENABLED => true]);

        $this->assertSame(CommandConstants::STATUS_OK, $reply[CommandConstants::FIELD_STATUS] ?? null);
        $this->assertSame([CommandConstants::FIELD_ENABLED => true], $reply[CommandConstants::FIELD_PAYLOAD] ?? null);
        $this->assertTrue(Hilos::$rt?->hilosAdminViewModeRuntime?->enabled);
    }

    public function testOffTakesTheModeBackOff(): void
    {
        $this->ask([CommandConstants::FIELD_ENABLED => true]);

        $reply = $this->ask([CommandConstants::FIELD_ENABLED => false]);

        $this->assertSame([CommandConstants::FIELD_ENABLED => false], $reply[CommandConstants::FIELD_PAYLOAD] ?? null);
        $this->assertFalse(Hilos::$rt?->hilosAdminViewModeRuntime?->enabled);
    }

    public function testTheWriteGoesOnTheSyncWireForTheWorkers(): void
    {
        $this->ask([CommandConstants::FIELD_ENABLED => true]);

        $signal = Hilos::$sr?->getNextQueuedSignal();
        $this->assertNotNull($signal);
        $this->assertInstanceOf(RtSyncUpdatedSignalData::class, $signal->data);
        $this->assertSame(StateAdminViewModeRuntime::RT_ITEM, $signal->data->collectionKey);
        $this->assertSame([StateAdminViewModeRuntime::enabled => true], $signal->data->row);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}> Payloads that do not say on or off outright
     */
    public static function unreadableModes(): array
    {
        return [
            'no mode at all' => [[]],
            'the mode as text' => [[CommandConstants::FIELD_ENABLED => 'false']],
            'the mode as a number' => [[CommandConstants::FIELD_ENABLED => 0]],
        ];
    }

    /**
     * @param array<string, mixed> $payload Request payload that does not say on or off outright
     */
    #[DataProvider('unreadableModes')]
    public function testAnUnreadableModeIsRefusedAndTheRowIsLeftAlone(array $payload): void
    {
        $this->ask([CommandConstants::FIELD_ENABLED => true]);

        $reply = $this->ask($payload);

        $this->assertSame(CommandConstants::STATUS_ERROR, $reply[CommandConstants::FIELD_STATUS] ?? null);
        $this->assertSame(
            'enabled must be true or false',
            $reply[CommandConstants::FIELD_PAYLOAD][CommandConstants::FIELD_MESSAGE] ?? null,
        );
        $this->assertTrue(Hilos::$rt?->hilosAdminViewModeRuntime?->enabled);
    }

    public function testANodeWithoutRuntimeStateRefusesInsteadOfThrowing(): void
    {
        Hilos::$rt = null;

        $reply = $this->ask([CommandConstants::FIELD_ENABLED => true]);

        $this->assertSame(CommandConstants::STATUS_ERROR, $reply[CommandConstants::FIELD_STATUS] ?? null);
        $this->assertSame(
            'This node holds no runtime state for the admin view mode',
            $reply[CommandConstants::FIELD_PAYLOAD][CommandConstants::FIELD_MESSAGE] ?? null,
        );
    }

    /**
     * Sends one test:admin-view-mode request to a fresh command client and reads its reply.
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

        $client = new AdminViewModeTestCommandClient($pair[0], new CommandServer('127.0.0.1', 0));
        $client->feed([
            CommandConstants::FIELD_CORRELATION_ID => 'corr-view-mode',
            CommandConstants::FIELD_COMMAND => CliCommands::ADMIN_VIEW_MODE_TEST,
            CommandConstants::FIELD_PAYLOAD => $payload,
        ]);

        return $client->lastReply();
    }
}

/**
 * Command client that takes a request straight from the test instead of the socket.
 */
final class AdminViewModeTestCommandClient extends CommandClient
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
 * The mode row is framework-owned and mounted for every project, so the master finds it here
 * exactly as it does in a real daemon.
 */
final class AdminViewModeTestRtContext extends RtContext
{
    public function configure(): void
    {
    }
}

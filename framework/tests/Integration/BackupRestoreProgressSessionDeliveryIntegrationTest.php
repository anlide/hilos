<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\Agent\DTO\BackupRestoreProgressSignalData;
use Hilos\Constants\HilosSignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Socket\Client\Interface\WebSocketClientInterface;
use Hilos\Socket\Server\WebSocketServer;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Tests\Unit\WebSocketClientTestProbe;
use LogicException;
use PHPUnit\Framework\TestCase;
use Socket;

/**
 * Where a restore's progress is delivered: to the browser SESSION that asked, and to nothing else
 * (HIL-655).
 *
 * The address exists because the socket the operation was started from does not survive it. A
 * reload mints a new accept key, a second tab was never named at all, and the registry that would
 * translate either back into a person is written by an agent the freeze has stopped - so under a
 * restore the master's own connection list is the only thing that still knows who is who. What is
 * pinned here is that whole road, end to end: the frame an agent addresses to a session
 * ({@see AbstractAgent::sendToSession()}), the destination the router derives from it, and the
 * master's fan-out matching it against the hash each connection carried in on its 101.
 *
 * The connections are real ones, driven through the real handshake with a real cookie, because the
 * hash under test is the one that handshake computes. A double that simply held a string beside a
 * fake client would prove that two strings can be compared.
 */
final class BackupRestoreProgressSessionDeliveryIntegrationTest extends TestCase
{
    /** Session cookie of the browser that asked for the restore, in the minted token form. */
    private const string INITIATOR_SESSION_TOKEN = '0123456789abcdef0123456789abcdef';

    /** Session cookie of another browser watching the same node, same form and a different value. */
    private const string STRANGER_SESSION_TOKEN = 'fedcba9876543210fedcba9876543210';

    private const string BACKUP_ID = '2026-08-24_11-00-00';

    private ?SignalRouter $previousSignalRouter = null;

    private ?EnvAccessor $previousEnv = null;

    protected function setUp(): void
    {
        $this->previousSignalRouter = Hilos::$sr;
        $this->previousEnv = isset(Hilos::$env) ? Hilos::$env : null;

        Hilos::$sr = new SignalRouter();
        Hilos::$env = new EnvAccessor();
        putenv('HILOS_BUILD_TIMESTAMP=1');
        putenv('HILOS_SESSION_COOKIE_NAME=hilos_session_token');
        putenv('APP_ENV=test');
    }

    protected function tearDown(): void
    {
        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$env = $this->previousEnv;
        Hilos::$rt = null;
        putenv('HILOS_BUILD_TIMESTAMP');
        putenv('HILOS_SESSION_COOKIE_NAME');
        putenv('APP_ENV');

        parent::tearDown();
    }

    /**
     * The scenario the leaf was written from: the admin who started the restore has the tab they
     * started it from AND the one they opened (or reloaded into) while it ran. Both are the same
     * browser, and the second is the one the old address could not reach.
     */
    public function testEveryConnectionOfTheInitiatorSessionIsToldHowTheRestoreIsGoing(): void
    {
        $manager = new RestoreProgressDeliveryTestManager();
        $server = $manager->addWebSocketServer();
        $started = $server->connect(self::INITIATOR_SESSION_TOKEN);
        $reloaded = $server->connect(self::INITIATOR_SESSION_TOKEN);
        $server->forgetHandshakeBytes();

        $this->queueProgress('importing');
        $manager->dispatch();

        foreach ([$started, $reloaded] as $probe) {
            $frame = $this->deliveredFrame($probe);
            $this->assertSame(HilosSignalConstants::BACKUP_RESTORE_PROGRESS, $frame['type'] ?? null);
            $this->assertSame(self::BACKUP_ID, $frame['data']['backupId'] ?? null);
            $this->assertSame('importing', $frame['data']['phase'] ?? null);
        }
    }

    /**
     * The other half, and the one that makes the address worth having: a session is not a
     * broadcast. Another admin looking at the same shuttered node is told nothing about a run that
     * is not theirs - and neither is a visitor whose browser carries no session the freeze could
     * name.
     */
    public function testNoOtherBrowserOnTheNodeHearsAboutIt(): void
    {
        $manager = new RestoreProgressDeliveryTestManager();
        $server = $manager->addWebSocketServer();
        $server->connect(self::INITIATOR_SESSION_TOKEN);
        $stranger = $server->connect(self::STRANGER_SESSION_TOKEN);
        $server->forgetHandshakeBytes();

        $this->queueProgress('importing');
        $manager->dispatch();

        $this->assertSame('', $stranger->outboundBytes());
    }

    /**
     * The outcome rides the same address as the phases before it, which is what lets a tab that
     * only ever saw the shuttered screen say how the run ended rather than going quiet.
     */
    public function testTheOutcomeTravelsToTheSameSession(): void
    {
        $manager = new RestoreProgressDeliveryTestManager();
        $server = $manager->addWebSocketServer();
        $watching = $server->connect(self::INITIATOR_SESSION_TOKEN);
        $server->forgetHandshakeBytes();

        $this->queueProgress('failed', 'the archive checksum did not match');
        $manager->dispatch();

        $frame = $this->deliveredFrame($watching);
        $this->assertSame('error', $frame['data']['outcome'] ?? null);
        $this->assertSame('the archive checksum did not match', $frame['data']['failureReason'] ?? null);
    }

    /**
     * Queues one progress frame the way {@see BackupAgent::reportRestoreProgress()} does: the
     * payload it builds, addressed by the hash of the initiator's session token.
     *
     * @param string $phase Restore phase the frame reports
     * @param ?string $failureReason Why the run failed, or null while it is still going
     * @throws InvalidArgumentException When the queued signal carries an empty name
     */
    private function queueProgress(string $phase, ?string $failureReason = null): void
    {
        $progress = new BackupRestoreProgressSignalData(
            running: $failureReason === null,
            backupId: self::BACKUP_ID,
            scope: 'full',
            phase: $phase,
            phaseStartedAt: null,
            startedAt: null,
            finishedAt: null,
            outcome: $failureReason === null ? null : 'error',
            failureReason: $failureReason,
            estimatedSeconds: null,
            rehydrateComplete: $failureReason === null,
            rehydrateProblems: [],
            databaseTouched: $failureReason !== null,
        );

        Hilos::$sr->queueSignal(
            signalSource: new SignalSource(SignalSource::AGENT),
            signalType: new SignalType(SignalTypeConstants::WS_SESSION),
            signalName: new SignalName(HilosSignalConstants::BACKUP_RESTORE_PROGRESS),
            signalData: new WebSocketSignalData(
                data: $progress,
                targetSessionTokenHash: StateProtectedModeRuntime::hashSessionToken(self::INITIATOR_SESSION_TOKEN),
            ),
        );
    }

    /**
     * Decodes the one frame the dispatch pass wrote to this connection.
     *
     * @param WebSocketClientTestProbe $probe Connection to read the delivery off
     * @return array<string, mixed> Decoded signal frame
     */
    private function deliveredFrame(WebSocketClientTestProbe $probe): array
    {
        $bytes = $probe->outboundBytes();
        $this->assertNotSame('', $bytes, 'Nothing was written to this connection');

        $lengthByte = ord($bytes[1]);
        $headerLength = $lengthByte === 126 ? 4 : 2;
        $payloadLength = $lengthByte === 126 ? unpack('n', substr($bytes, 2, 2))[1] : $lengthByte;

        $decoded = json_decode(substr($bytes, $headerLength, $payloadLength), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}

/**
 * Daemon manager standing on a websocket server and nothing else, able to run one dispatch pass.
 */
final class RestoreProgressDeliveryTestManager extends DaemonManager
{
    /**
     * Registers the stand-in websocket server the fan-out writes to.
     *
     * @return RestoreProgressDeliveryTestServer The registered stand-in, for arranging the case
     */
    public function addWebSocketServer(): RestoreProgressDeliveryTestServer
    {
        // The dispatch pass is the master's, and it does not run without the worker link it
        // normally stands on; the browser server is what this file is about.
        $this->registerServer(new RestoreProgressDeliveryTestWorkerServer());
        $server = new RestoreProgressDeliveryTestServer();
        $this->registerServer($server);

        return $server;
    }

    /**
     * Drains the queue through the real dispatch pass, which is private to the manager.
     */
    public function dispatch(): void
    {
        $dispatch = Closure::bind(
            static function (DaemonManager $manager): void {
                $manager->dispatchSignals();
            },
            null,
            DaemonManager::class,
        );

        $dispatch($this);
    }

    /**
     * Swallows the master's own handling: this file is about what leaves the master.
     *
     * @param SignalDTO $signal Signal being dispatched
     * @param ?string $originNodeId Node the write happened on, or null when it was this one
     */
    protected function handleDaemonSignal(SignalDTO $signal, ?string $originNodeId = null): void
    {
    }

    protected function createSignalRouter(): SignalRouter
    {
        return Hilos::$sr ?? new SignalRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new RestoreProgressDeliveryTestAgentManagerDaemon();
    }
}

final class RestoreProgressDeliveryTestAgentManagerDaemon extends AgentManagerDaemon
{
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('not used in test');
    }
}

/**
 * A worker server with no links: the dispatch pass refuses to run without one registered.
 */
final class RestoreProgressDeliveryTestWorkerServer extends WorkerServer
{
    public function __construct()
    {
    }

    protected function onStart(): void
    {
    }
}

/**
 * A websocket server holding connections that completed a real handshake instead of owning sockets.
 */
final class RestoreProgressDeliveryTestServer extends WebSocketServer
{
    public function __construct()
    {
    }

    /**
     * Opens one browser connection carrying the given session cookie, through the real handshake.
     *
     * The cookie is what the hash is computed from, on the same line of the same method a live 101
     * computes it on - so a connection here answers the fan-out exactly as a browser's would.
     *
     * @param string $sessionToken Session cookie value this browser arrives with
     * @return WebSocketClientTestProbe The connected probe, for reading deliveries off
     */
    public function connect(string $sessionToken): WebSocketClientTestProbe
    {
        $probe = WebSocketClientTestProbe::createSocketless();
        $probe->feed(
            "GET /ws HTTP/1.1\r\n"
            . "Host: localhost:8092\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . 'Cookie: hilos_session_token=' . $sessionToken . "\r\n"
            . 'Sec-WebSocket-Key: ' . base64_encode('0123456789abcdef') . "\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "\r\n",
        );
        $this->clients[] = $probe;

        return $probe;
    }

    /**
     * Drops the 101 and the welcome frame every connection was answered with, so what remains in a
     * buffer afterwards is what the dispatch pass put there.
     */
    public function forgetHandshakeBytes(): void
    {
        foreach ($this->clients as $client) {
            if ($client instanceof WebSocketClientTestProbe) {
                $client->flushOutbound();
            }
        }
    }

    /**
     * @param resource|Socket $socket Accepted socket
     * @return WebSocketClientInterface Never returned: this server accepts nothing
     * @throws LogicException Always: nothing here listens
     */
    protected function onCreateClient($socket): WebSocketClientInterface
    {
        throw new LogicException('This server accepts no sockets');
    }

    protected function onStart(): void
    {
    }
}

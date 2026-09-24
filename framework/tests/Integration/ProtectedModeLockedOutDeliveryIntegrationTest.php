<?php

declare(strict_types=1);

namespace Hilos\Tests\Integration;

use Closure;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\ProtectedMode\DTO\ProtectedModeStateSignalData;
use Hilos\ProtectedMode\DaemonProtectedModeExecutor;
use Hilos\ProtectedMode\ProtectedModeStubCopy;
use Hilos\Runtime\State\Item\ProtectedModeRuntime as StateProtectedModeRuntime;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Client\Interface\WebSocketClientInterface;
use Hilos\Socket\Server\WebSocketServer;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Tests\Unit\WebSocketClientTestProbe;
use Hilos\TruthSource\RtTruthSourceRegistry;
use LogicException;
use PHPUnit\Framework\TestCase;
use Socket;

/**
 * Whom the first-pass announcement reaches: every connection on this node the freeze still locks
 * out, and nobody the verification window has let in (HIL-1082).
 *
 * The announcement is the one frame of the mode whose verdict is meant for the held alone. It says
 * `active` - the stub stays up, now with a code field - and a tab the window has already let in
 * takes a pushed frame as a phase change: its page goes back under the stub, and the shell
 * remounts it with whatever was open in it gone. The initiator was spared by two arguments; the
 * circle, let in live since HIL-912, and every pass holder were not, and the first code threw them
 * back onto the stub. What is pinned here is the selection, end to end: the row's own locksOut()
 * asked on the master about every connection of this node, one frame queued by accept key for
 * each held one, and the dispatch pass writing exactly those.
 *
 * The connections are real ones, driven through the real handshake with a real cookie, because the
 * identity the row is asked about - the accept key minted on the 101 and the hash of the session
 * cookie - is the one that handshake computes. A fake client holding two strings would prove that
 * two strings can be compared.
 */
final class ProtectedModeLockedOutDeliveryIntegrationTest extends TestCase
{
    /** Session cookie of the operator who asked for the operation, in the minted token form. */
    private const string OPERATOR_SESSION_TOKEN = '0123456789abcdef0123456789abcdef';

    /** Session cookie of the circle member photographed at the freeze, same form and another value. */
    private const string MEMBER_SESSION_TOKEN = 'fedcba9876543210fedcba9876543210';

    /** Session cookie of a verifier who already presented a code. */
    private const string PASS_HOLDER_SESSION_TOKEN = '1111222233334444aaaabbbbccccdddd';

    /** Session cookie of a signed-in browser the window has not let in. */
    private const string STRANGER_SESSION_TOKEN = 'ddddccccbbbbaaaa4444333322221111';

    /** The operation the freeze protects, as the row and the stub copy name it. */
    private const string OPERATION = 'restore';

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
        RtTruthSourceRegistry::unregisterDaemon(StateProtectedModeRuntime::RT_ITEM);
        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$env = $this->previousEnv;
        Hilos::$rt = null;
        putenv('HILOS_BUILD_TIMESTAMP');
        putenv('HILOS_SESSION_COOKIE_NAME');
        putenv('APP_ENV');

        parent::tearDown();
    }

    /**
     * Seven sockets on one node inside the verification window, and the operator mints the first
     * code. The socket the operation was asked from and the operator's second tab, the circle
     * member and the verifier who already presented a code are inside and hear nothing; the
     * signed-in stranger and the cookieless visitor are still held, and each gets the frame that
     * turns the waiting sentence into the field. The seventh is a socket still in its 101: it has
     * no key to address yet, and what it is told comes with its own welcome, not from this push.
     */
    public function testOnlyTheBrowsersTheFreezeStillHoldsHearOfTheFirstCode(): void
    {
        $manager = new LockedOutDeliveryTestManager();
        $server = $manager->addWebSocketServer();
        $operatorTab = $server->connect(self::OPERATOR_SESSION_TOKEN);
        $operatorSecondTab = $server->connect(self::OPERATOR_SESSION_TOKEN);
        $member = $server->connect(self::MEMBER_SESSION_TOKEN);
        $passHolder = $server->connect(self::PASS_HOLDER_SESSION_TOKEN);
        $stranger = $server->connect(self::STRANGER_SESSION_TOKEN);
        $cookieless = $server->connect(null);
        $server->forgetHandshakeBytes();
        $handshaking = $server->open();
        $this->mountFreeze($operatorTab->acceptKey);

        $manager->notifyProtectedModeLockedOutState($this->announcement());
        $manager->dispatch();

        foreach ([$stranger, $cookieless] as $held) {
            $frame = $this->deliveredFrame($held);
            $this->assertSame(SignalTypeConstants::PROTECTED_MODE, $frame['type'] ?? null);
            $this->assertTrue($frame['data'][ProtectedModeStateSignalData::active] ?? null);
            $this->assertTrue($frame['data'][ProtectedModeStateSignalData::acceptsPass] ?? null);
            $this->assertTrue($frame['data'][ProtectedModeStateSignalData::passIssued] ?? null);
        }
        foreach ([$operatorTab, $operatorSecondTab, $member, $passHolder, $handshaking] as $silent) {
            $this->assertSame('', $silent->outboundBytes());
        }
    }

    /**
     * With no freeze row on this node there is nobody the freeze holds, so the announcement has no
     * addressee and nothing is written - the inert reading every other branch of the port has.
     */
    public function testWithoutAFreezeRowNobodyIsTold(): void
    {
        $manager = new LockedOutDeliveryTestManager();
        $server = $manager->addWebSocketServer();
        $stranger = $server->connect(self::STRANGER_SESSION_TOKEN);
        $cookieless = $server->connect(null);
        $server->forgetHandshakeBytes();
        Hilos::$rt = new LockedOutDeliveryTestRtContext();

        $manager->notifyProtectedModeLockedOutState($this->announcement());
        $manager->dispatch();

        $this->assertSame('', $stranger->outboundBytes());
        $this->assertSame('', $cookieless->outboundBytes());
    }

    /**
     * Mounts the freeze row of a node inside its verification window - the operator named by both
     * halves of their identity, one circle member photographed, one verifier admitted by a code and
     * that code standing - and registers this process as its truth source the way the daemon does.
     *
     * Mounted after the connections are made and not before, because the operator's socket is
     * recognized by the accept key the 101 minted for it, and that key is read off the probe.
     *
     * @param string $operatorAcceptKey Accept key of the socket the operation was asked from
     * @throws InvalidFormatException When the row lost a field the freeze is judged by
     */
    private function mountFreeze(string $operatorAcceptKey): void
    {
        Hilos::$rt = new LockedOutDeliveryTestRtContext();
        Hilos::$rt->mountFeatureItem(StateProtectedModeRuntime::RT_ITEM, StateProtectedModeRuntime::fromRow([
            StateProtectedModeRuntime::phase => StateProtectedModeRuntime::PHASE_VERIFYING,
            StateProtectedModeRuntime::operation => self::OPERATION,
            StateProtectedModeRuntime::initiatorAcceptKey => $operatorAcceptKey,
            StateProtectedModeRuntime::initiatorSessionTokenHash => self::sessionHash(self::OPERATOR_SESSION_TOKEN),
            StateProtectedModeRuntime::circleSessionTokenHashes => [self::sessionHash(self::MEMBER_SESSION_TOKEN)],
            StateProtectedModeRuntime::circleNamedCount => 1,
            StateProtectedModeRuntime::admittedSessionTokenHashes => [self::sessionHash(self::PASS_HOLDER_SESSION_TOKEN)],
            StateProtectedModeRuntime::passHashes => ['hash-of-a-pass'],
        ]));
        RtTruthSourceRegistry::registerDaemon(StateProtectedModeRuntime::RT_ITEM);
    }

    /**
     * The frame {@see DaemonProtectedModeExecutor::announcePassIssued()} builds at zero-to-one: the
     * verification frame with the second bit raised, worded for the maintenance surface.
     *
     * @return ProtectedModeStateSignalData The first-pass announcement
     */
    private function announcement(): ProtectedModeStateSignalData
    {
        $copy = ProtectedModeStubCopy::forOperation(self::OPERATION);

        return new ProtectedModeStateSignalData(
            active: true,
            operation: self::OPERATION,
            title: $copy->title,
            message: $copy->message,
            acceptsPass: true,
            passIssued: true,
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

    /**
     * @param string $sessionToken Session cookie value in the minted token form
     * @return string The hash the row keeps for that browser, as the 101 computes it
     */
    private static function sessionHash(string $sessionToken): string
    {
        return StateProtectedModeRuntime::hashSessionToken($sessionToken);
    }
}

/**
 * Daemon manager standing on a websocket server and nothing else, able to run one dispatch pass.
 */
final class LockedOutDeliveryTestManager extends DaemonManager
{
    /**
     * Registers the stand-in websocket server the frames are written to.
     *
     * @return LockedOutDeliveryTestServer The registered stand-in, for arranging the case
     */
    public function addWebSocketServer(): LockedOutDeliveryTestServer
    {
        // The dispatch pass is the master's, and it does not run without the worker link it
        // normally stands on; the browser server is what this file is about.
        $this->registerServer(new LockedOutDeliveryTestWorkerServer());
        $server = new LockedOutDeliveryTestServer();
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
        return new LockedOutDeliveryTestAgentManagerDaemon();
    }
}

final class LockedOutDeliveryTestAgentManagerDaemon extends AgentManagerDaemon
{
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('not used in test');
    }
}

/**
 * A worker server with no links: the dispatch pass refuses to run without one registered.
 */
final class LockedOutDeliveryTestWorkerServer extends WorkerServer
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
final class LockedOutDeliveryTestServer extends WebSocketServer
{
    public function __construct()
    {
    }

    /**
     * Opens one browser connection through the real handshake, carrying the given session cookie or
     * none at all.
     *
     * The cookie is what the session hash is computed from, and the accept key is minted on the
     * same 101 - both on the same lines of the same method a live connection runs through, so a
     * connection here is judged by the row exactly as a browser's would be. No cookie header at
     * all for a null token: a cookieless visitor is a browser the row can name by nothing.
     *
     * @param ?string $sessionToken Session cookie value this browser arrives with, or null for none
     * @return WebSocketClientTestProbe The connected probe, for reading deliveries off
     */
    public function connect(?string $sessionToken): WebSocketClientTestProbe
    {
        $probe = WebSocketClientTestProbe::createSocketless();
        $probe->feed(
            "GET /ws HTTP/1.1\r\n"
            . "Host: localhost:8092\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . ($sessionToken === null ? '' : 'Cookie: hilos_session_token=' . $sessionToken . "\r\n")
            . 'Sec-WebSocket-Key: ' . base64_encode('0123456789abcdef') . "\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "\r\n",
        );
        $this->clients[] = $probe;

        return $probe;
    }

    /**
     * Opens one socket that has not sent its handshake yet: accepted, in the list, and without an
     * accept key or a session hash until its 101 runs.
     *
     * @return WebSocketClientTestProbe The accepted probe, for reading deliveries off
     */
    public function open(): WebSocketClientTestProbe
    {
        $probe = WebSocketClientTestProbe::createSocketless();
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

/**
 * Runtime context registering no project state: the case mounts the freeze row itself, or leaves
 * it out.
 */
final class LockedOutDeliveryTestRtContext extends RtContext
{
    /**
     * Registers no project runtime state: the framework mount, when a case makes it, supplies the
     * freeze row.
     */
    public function configure(): void
    {
    }
}

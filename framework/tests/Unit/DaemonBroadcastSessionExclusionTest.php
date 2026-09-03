<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalData;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Router\WebSocketSignalData;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Socket\Client\Interface\WebSocketClientInterface;
use Hilos\Socket\Client\WebSocketClient;
use Hilos\Socket\Server\WebSocketServer;
use PHPUnit\Framework\TestCase;

/**
 * Whom a node-wide broadcast leaves out, now that it can be told about a browser.
 *
 * The exclusion used to name one socket, which is the wrong unit for the one thing it is used
 * for: the operator who starts a destructive operation is a person with tabs open, and a frame
 * that spared only the tab they clicked in reached the rest, leaving that browser holding two
 * answers about one freeze. Since HIL-718 the caller doing the sparing is the verification
 * window rather than the entry, and the unit is the same for the same reason. Both exclusions
 * live here at once, because the accept key is still the only thing a node knows about a
 * connection that carries no cookie.
 */
final class DaemonBroadcastSessionExclusionTest extends TestCase
{
    /** Session cookie of the browser that started the operation, in the minted token form. */
    private const string INITIATOR_TOKEN = '0123456789abcdef0123456789abcdef';

    /** Session cookie of any other browser, same form and a different value. */
    private const string STRANGER_TOKEN = 'fedcba9876543210fedcba9876543210';

    /** Name the broadcast travels under; distinctive enough not to be confused with a welcome. */
    private const string SIGNAL_NAME = 'broadcast_exclusion_marker';

    private ?SignalRouter $previousSignalRouter = null;

    private ?EnvAccessor $previousEnv = null;

    private BroadcastExclusionTestManager $daemon;

    private BroadcastExclusionTestWebSocketServer $server;

    protected function setUp(): void
    {
        $this->previousSignalRouter = Hilos::$sr;
        $this->previousEnv = Hilos::$env;
        Hilos::$env = new EnvAccessor();
        $this->daemon = new BroadcastExclusionTestManager();
        $this->server = new BroadcastExclusionTestWebSocketServer();
        $this->daemon->registerServer($this->server);
    }

    protected function tearDown(): void
    {
        Hilos::$sr = $this->previousSignalRouter;
        Hilos::$env = $this->previousEnv;
        putenv('HILOS_BUILD_TIMESTAMP');

        parent::tearDown();
    }

    public function testASecondTabOfTheExcludedBrowserIsLeftOutToo(): void
    {
        $clicked = $this->connect(self::INITIATOR_TOKEN);
        $otherTab = $this->connect(self::INITIATOR_TOKEN);
        $stranger = $this->connect(self::STRANGER_TOKEN);

        $this->broadcast($clicked->acceptKey, $clicked->sessionTokenHash);

        $this->assertFalse($this->received($clicked));
        $this->assertFalse($this->received($otherTab));
        $this->assertTrue($this->received($stranger));
    }

    public function testExcludingOnlyAnAcceptKeyStillReachesTheRestOfThatBrowser(): void
    {
        // The old behavior, kept for an initiator with no browser behind it: a CLI trigger or a
        // schedule leaves the hash null, and the broadcast has to spare only what it was told.
        $clicked = $this->connect(self::INITIATOR_TOKEN);
        $otherTab = $this->connect(self::INITIATOR_TOKEN);

        $this->broadcast($clicked->acceptKey, null);

        $this->assertFalse($this->received($clicked));
        $this->assertTrue($this->received($otherTab));
    }

    public function testExcludingNobodyReachesEveryConnection(): void
    {
        $first = $this->connect(self::INITIATOR_TOKEN);
        $second = $this->connect(self::STRANGER_TOKEN);

        $this->broadcast(null, null);

        $this->assertTrue($this->received($first));
        $this->assertTrue($this->received($second));
    }

    /**
     * Fans a node-wide broadcast out to the connections this node holds.
     *
     * Driven through the forwarded-fanout door rather than through the writer beneath it: that
     * is a public entry, and it takes the same road a locally raised broadcast takes - router
     * first, then the one pass over the sockets.
     *
     * @param ?string $excludeAcceptKey Accept key to exclude, or null to send to all
     * @param ?string $excludeSessionTokenHash Session hash to exclude, or null to leave no browser out
     */
    private function broadcast(?string $excludeAcceptKey, ?string $excludeSessionTokenHash): void
    {
        $this->daemon->deliverFanoutToClients('node-b', new SignalDTO(
            new SignalSource(SignalSource::AGENT),
            new SignalType(SignalTypeConstants::WS_ALL_CONNECTED),
            new SignalName(self::SIGNAL_NAME),
            new WebSocketSignalData(
                data: new SignalData(),
                excludeAcceptKey: $excludeAcceptKey,
                excludeSessionTokenHash: $excludeSessionTokenHash,
            ),
        ));
    }

    /**
     * Puts a handshaken connection on the server, carrying the cookie of one browser.
     *
     * Driven through the real 101 rather than assembled: the session hash is derived there and
     * nowhere else, so a connection built by hand would be answering a question the master never
     * asked it.
     *
     * @param string $sessionToken Session cookie the browser presents
     * @return WebSocketClientTestProbe Connection with a completed handshake
     */
    private function connect(string $sessionToken): WebSocketClientTestProbe
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
        $this->assertTrue($probe->handshakeDone());
        $this->server->attach($probe);

        return $probe;
    }

    /**
     * @param WebSocketClientTestProbe $probe Connection to inspect
     * @return bool Whether the broadcast payload reached this connection
     */
    private function received(WebSocketClientTestProbe $probe): bool
    {
        return str_contains($probe->outboundBytes(), self::SIGNAL_NAME);
    }
}

/**
 * A WebSocket server whose client list the test fills directly.
 */
final class BroadcastExclusionTestWebSocketServer extends WebSocketServer
{
    public function __construct()
    {
        parent::__construct('127.0.0.1', 0);
    }

    /**
     * @param WebSocketClient $client Connection the broadcast will walk over
     */
    public function attach(WebSocketClient $client): void
    {
        $this->clients[] = $client;
    }

    /**
     * @return string Server name the failure card names
     */
    public function getServerName(): string
    {
        return 'broadcast-exclusion-test';
    }

    protected function onStart(): void
    {
    }

    /**
     * @param resource $socket Client socket
     * @return WebSocketClientInterface Never returned; this server accepts nothing
     * @throws AgentDaemonCreationFailedException Always
     */
    protected function onCreateClient($socket): WebSocketClientInterface
    {
        throw new AgentDaemonCreationFailedException('the broadcast exclusion test accepts no connection');
    }
}

/**
 * A daemon whose only registered server is the WebSocket one the fan-out writes to.
 */
final class BroadcastExclusionTestManager extends DaemonManager
{
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new BroadcastExclusionTestAgentManagerDaemon();
    }
}

final class BroadcastExclusionTestAgentManagerDaemon extends AgentManagerDaemon
{
    /**
     * @param string $agentType Agent type that was asked for
     * @param ?string $agentIndex Agent index that was asked for
     * @return AgentDaemonInterface Never returned; these cases start no agent
     * @throws AgentDaemonCreationFailedException Always
     */
    protected function createAgentDaemon(string $agentType, ?string $agentIndex): AgentDaemonInterface
    {
        throw new AgentDaemonCreationFailedException('not used in test');
    }
}

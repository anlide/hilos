<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit\Socket\WebSocket;

use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerSignalDTO;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\AgentInterface;
use Hilos\Core\Agent\AgentManager;
use Hilos\Core\Daemon\WorkerManager;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Execution\ExecutionContext;
use Hilos\Core\Http\RequestQueryParams;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Core\TruthSource\TruthSourceKeys;
use Hilos\Environment\EnvAccessor;
use Hilos\Hilos;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Collection\HilosConnections as StateHilosConnections;
use Hilos\Runtime\State\Item\HilosConnection as StateHilosConnection;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\HilosConnectionsActions;
use Hilos\Runtime\View\Actions\Item\HilosConnectionActions;
use Hilos\Runtime\View\Collection\HilosConnections;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Runtime\View\Item\HilosConnection;
use Hilos\Socket\Client\WebSocketClient;
use Hilos\Socket\WebSocket\DTO\WebSocketLivenessSignalDTO;
use Hilos\Socket\WebSocket\Exception\InvalidFrameException;
use Hilos\Socket\Worker\DTO\DaemonAgentMessageDTO;
use Hilos\Socket\Worker\WorkerDTO;
use Hilos\TruthSource\RtTruthSourceRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Socket;

/**
 * The liveness transition of a connection reaches the owner of the connections, and only it writes (HIL-1566).
 *
 * Four questions, kept apart because each fails differently. The payload: the master names the
 * connection and its new state, and both survive every hop a frame takes to an agent on another
 * worker or node. The receipt: the worker writes the doubt flag as the addressed agent, so the
 * row's own claim decides, a repeat costs nothing, and a connection already gone stays gone. The
 * door: a browser that writes the internal type in its own JSON reaches nobody. The repair: a
 * real master socket checks the owner's row once an interval, tells it again only what it
 * missed, writes nothing itself, and keeps that schedule however much the browser says.
 */
final class WebSocketLivenessSignalTest extends TestCase
{
    private const string ACCEPT_KEY = 'ak-liveness';
    private const string GONE_ACCEPT_KEY = 'ak-liveness-gone';
    /** An RFC pong as a browser sends it: masked, with nothing in it */
    private const string PONG = "\x8A\x80mask";
    private const string UPGRADE_REQUEST = "GET /ws HTTP/1.1\r\n"
        . "Host: localhost:8092\r\n"
        . "Upgrade: websocket\r\n"
        . "Connection: Upgrade\r\n"
        . "Sec-WebSocket-Key: MDEyMzQ1Njc4OWFiY2RlZg==\r\n"
        . "Sec-WebSocket-Version: 13\r\n"
        . "\r\n";

    private ?EnvAccessor $previousEnv = null;
    /** @var list<array{WebSocketClient, Socket}> Connections and their browser ends */
    private array $connections = [];

    protected function setUp(): void
    {
        $this->previousEnv = Hilos::$env;
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as [$client, $peer]) {
            $client->close();
            socket_close($peer);
        }
        Hilos::$env = $this->previousEnv;
        Hilos::$rt = null;
        Hilos::$sr = null;
        ExecutionContext::clear();
        RtTruthSourceRegistry::unregisterAgent(LivenessTestWorkerManager::OWNER_ID);

        parent::tearDown();
    }

    /**
     * @return array<string, array{bool}> Both states a connection moves into
     */
    public static function transitions(): array
    {
        return [
            'went silent' => [true],
            'heard from again' => [false],
        ];
    }

    #[DataProvider('transitions')]
    public function testThePayloadReadsBackWhatItCarries(bool $probablyOffline): void
    {
        $dto = new WebSocketLivenessSignalDTO(self::ACCEPT_KEY, $probablyOffline);

        $this->assertSame(
            [
                WebSocketLivenessSignalDTO::ACCEPT_KEY => self::ACCEPT_KEY,
                WebSocketLivenessSignalDTO::PROBABLY_OFFLINE => $probablyOffline,
            ],
            $dto->toArray(),
        );
        $restored = WebSocketLivenessSignalDTO::fromArray($dto->toArray());
        $this->assertSame(self::ACCEPT_KEY, $restored->getAcceptKey());
        $this->assertSame($probablyOffline, $restored->probablyOffline);
    }

    #[DataProvider('transitions')]
    public function testTheTransitionSurvivesWorkerAndPeerTransport(bool $probablyOffline): void
    {
        $signal = $this->livenessSignal(self::ACCEPT_KEY, $probablyOffline);

        $worker = WorkerDTO::factoryWorkerDTO(new DaemonAgentMessageDTO('owner', $signal)->toJson());
        $this->assertInstanceOf(DaemonAgentMessageDTO::class, $worker);
        $peer = PeerDTO::fromWire(new PeerSignalDTO('socket', 'remote', 'owner', null, $worker->signal)->toJson());
        $this->assertInstanceOf(PeerSignalDTO::class, $peer);

        foreach ([$worker->signal, $peer->signal] as $restored) {
            $this->assertSame(SignalSource::WEBSOCKET, $restored->signalSource->getSource());
            $this->assertSame(SignalTypeConstants::CONNECTION_LIVENESS, $restored->signalType->getType());
            $this->assertSame(SignalTypeConstants::CONNECTION_LIVENESS, $restored->signalName->getName());
            $this->assertInstanceOf(WebSocketLivenessSignalDTO::class, $restored->data);
            $this->assertSame($signal->data->toArray(), $restored->data->toArray());
        }
    }

    /**
     * @return array<string, array{array<string, mixed>}> Payloads that do not name a connection and a state
     */
    public static function brokenPayloads(): array
    {
        $named = [WebSocketLivenessSignalDTO::ACCEPT_KEY => self::ACCEPT_KEY];
        $doubted = [WebSocketLivenessSignalDTO::PROBABLY_OFFLINE => true];

        return [
            'no state' => [$named],
            'null state' => [[...$named, WebSocketLivenessSignalDTO::PROBABLY_OFFLINE => null]],
            'integer state' => [[...$named, WebSocketLivenessSignalDTO::PROBABLY_OFFLINE => 1]],
            'string state' => [[...$named, WebSocketLivenessSignalDTO::PROBABLY_OFFLINE => 'true']],
            'no accept key' => [$doubted],
            'integer accept key' => [[WebSocketLivenessSignalDTO::ACCEPT_KEY => 7, ...$doubted]],
            'list accept key' => [[WebSocketLivenessSignalDTO::ACCEPT_KEY => [self::ACCEPT_KEY], ...$doubted]],
            'empty accept key' => [[WebSocketLivenessSignalDTO::ACCEPT_KEY => '', ...$doubted]],
        ];
    }

    /**
     * @param array<string, mixed> $payload Payload missing a field or carrying it as another type
     */
    #[DataProvider('brokenPayloads')]
    public function testAPayloadThatDoesNotNameAConnectionAndAStateIsRefused(array $payload): void
    {
        $this->expectException(InvalidFormatException::class);

        WebSocketLivenessSignalDTO::fromArray($payload);
    }

    public function testAnEmptyAcceptKeyIsRefusedAtConstruction(): void
    {
        $this->expectException(InvalidFormatException::class);

        new WebSocketLivenessSignalDTO('', true);
    }

    #[DataProvider('transitions')]
    public function testTheOwnerWritesTheTransitionAndSyncsItOnce(bool $probablyOffline): void
    {
        $manager = $this->arrangeOwnedConnection(!$probablyOffline);

        $manager->handleDaemonMessage($this->addressed(LivenessTestWorkerManager::OWNER_ID, self::ACCEPT_KEY, $probablyOffline));

        $this->assertSame($probablyOffline, $this->connectionsRegistry()[self::ACCEPT_KEY]->probablyOffline);
        $this->assertSame([[StateHilosConnection::probablyOffline => $probablyOffline]], $this->queuedUpdateDiffs());
    }

    #[DataProvider('transitions')]
    public function testATransitionTheRowAlreadyHoldsSendsNothing(bool $probablyOffline): void
    {
        $manager = $this->arrangeOwnedConnection($probablyOffline);

        $manager->handleDaemonMessage($this->addressed(LivenessTestWorkerManager::OWNER_ID, self::ACCEPT_KEY, $probablyOffline));

        $this->assertSame($probablyOffline, $this->connectionsRegistry()[self::ACCEPT_KEY]->probablyOffline);
        $this->assertSame([], $this->queuedUpdateDiffs());
    }

    public function testAnAgentThatDoesNotOwnTheConnectionsIsRefusedAndChangesNothing(): void
    {
        $manager = $this->arrangeOwnedConnection(false);

        try {
            $manager->handleDaemonMessage($this->addressed(LivenessTestWorkerManager::STRANGER_ID, self::ACCEPT_KEY, true));
            $this->fail('an agent that is not the truth source of the connections must be refused');
        } catch (RtTruthSourceWriteNotAllowedException) {
            // The refusal is the point; what follows checks it left the row alone.
        }

        $this->assertFalse($this->connectionsRegistry()[self::ACCEPT_KEY]->probablyOffline);
        $this->assertSame([], $this->queuedUpdateDiffs());
    }

    public function testALateTransitionForAConnectionAlreadyGoneBringsNoRowBack(): void
    {
        $manager = $this->arrangeOwnedConnection(false);

        $manager->handleDaemonMessage($this->addressed(LivenessTestWorkerManager::OWNER_ID, self::GONE_ACCEPT_KEY, true));

        $this->assertFalse(isset($this->connectionsRegistry()[self::GONE_ACCEPT_KEY]));
        $this->assertCount(1, $this->connectionsRegistry());
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    public function testAProjectThatKeepsNoConnectionsHasNothingToWrite(): void
    {
        $manager = new LivenessTestWorkerManager();
        Hilos::$rt = new LivenessTestBareRtContext();
        Hilos::$rt->configure();

        $manager->handleDaemonMessage($this->addressed(LivenessTestWorkerManager::OWNER_ID, self::ACCEPT_KEY, true));

        $this->assertNull(Hilos::$rt->connectionsRegistry());
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
    }

    /**
     * The internal type written in a browser's own JSON is an unknown frame: it queues no signal,
     * so the connection it names - even one the server holds - is not written.
     */
    public function testABrowserCannotRaiseTheInternalTransitionThroughItsJson(): void
    {
        $this->arrangeOwnedConnection(false);
        Hilos::$env = new EnvAccessor();
        [$client] = $this->browserConnection();

        try {
            $client->acceptJson(json_encode([
                'type' => SignalTypeConstants::CONNECTION_LIVENESS,
                WebSocketLivenessSignalDTO::ACCEPT_KEY => self::ACCEPT_KEY,
                WebSocketLivenessSignalDTO::PROBABLY_OFFLINE => true,
            ], JSON_THROW_ON_ERROR));
            $this->fail('a browser frame naming the internal type must be refused');
        } catch (InvalidFrameException) {
            // The refusal is the point; what follows checks nothing went out and nothing was written.
        }

        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());
        $this->assertFalse($this->connectionsRegistry()[self::ACCEPT_KEY]->probablyOffline);
    }

    /**
     * A row that holds what the socket holds costs nothing: no message on a ping or a check, and
     * the one transition the owner writes once.
     */
    public function testARowInAgreementCostsNoMessageOnAnyPingOrCheck(): void
    {
        [$manager, $client] = $this->socketWithOwnedRow(false);

        foreach ([40000, 50000, 90000, 100000] as $nowMs) {
            $this->tickAt($client, $nowMs);
        }
        $this->assertSame([], $this->toldOwner($client, $manager));

        $this->tickAt($client, 120000);
        $this->assertSame([true], $this->toldOwner($client, $manager));
        $this->assertSame([[StateHilosConnection::probablyOffline => true]], $this->queuedUpdateDiffs());

        foreach ([140000, 150000, 190000, 200000, 240000, 250000] as $nowMs) {
            $this->tickAt($client, $nowMs);
        }
        $this->assertSame([], $this->toldOwner($client, $manager));
    }

    /**
     * The check reads the row and writes nothing: the socket's state goes out once a check, the
     * row stays as it was until the owner writes it, and a browser that keeps talking does not
     * push the next check away.
     */
    public function testADisagreeingRowIsToldTheSocketsStateOnceACheckWhileTheBrowserKeepsTalking(): void
    {
        [$manager, $client] = $this->socketWithOwnedRow(true);

        for ($nowMs = 10000; $nowMs < 50000; $nowMs += 10000) {
            $this->writeFromBrowser($client, self::PONG);
            $this->tickAt($client, $nowMs);
        }
        $this->assertSame([], $this->toldOwner($client, null));

        $this->writeFromBrowser($client, self::PONG);
        $this->tickAt($client, 50000);
        $this->assertSame([false], $this->toldOwner($client, null));
        $this->assertTrue($this->connectionsRegistry()[$client->acceptKey]->probablyOffline);

        for ($nowMs = 60000; $nowMs < 100000; $nowMs += 10000) {
            $this->writeFromBrowser($client, self::PONG);
            $this->tickAt($client, $nowMs);
        }
        $this->assertSame([], $this->toldOwner($client, null));

        $this->writeFromBrowser($client, self::PONG);
        $this->tickAt($client, 100000);
        $this->assertSame([false], $this->toldOwner($client, $manager));
        $this->assertFalse($this->connectionsRegistry()[$client->acceptKey]->probablyOffline);
        $this->assertSame([[StateHilosConnection::probablyOffline => false]], $this->queuedUpdateDiffs());

        $this->writeFromBrowser($client, self::PONG);
        $this->tickAt($client, 150000);
        $this->assertSame([], $this->toldOwner($client, $manager));
    }

    /**
     * A doubt told while the owner was gone, and its copy of the connections with it, is told
     * again on the first check after the copy is back.
     */
    public function testADoubtTheOwnerMissedIsRepairedOnceItsCopyIsBack(): void
    {
        [$manager, $client] = $this->socketWithOwnedRow(false);
        $this->tickAt($client, 50000);
        $this->tickAt($client, 100000);

        $this->tickAt($client, 120000);
        $this->assertSame([true], $this->toldOwner($client, null));

        $copy = Hilos::$rt;
        Hilos::$rt = new LivenessTestBareRtContext();
        Hilos::$rt->configure();
        $this->tickAt($client, 150000);
        $this->assertSame([], $this->toldOwner($client, $manager));

        Hilos::$rt = $copy;
        $this->tickAt($client, 199999);
        $this->assertSame([], $this->toldOwner($client, $manager));
        $this->tickAt($client, 200000);
        $this->assertSame([true], $this->toldOwner($client, $manager));
        $this->assertTrue($this->connectionsRegistry()[$client->acceptKey]->probablyOffline);
        $this->assertSame([[StateHilosConnection::probablyOffline => true]], $this->queuedUpdateDiffs());

        $this->tickAt($client, 250000);
        $this->assertSame([], $this->toldOwner($client, $manager));
    }

    /**
     * The browser coming back while the owner was not listening is repaired by the next check,
     * though the browser has not gone quiet since and the flag has not moved again.
     */
    public function testAReturnTheOwnerMissedIsRepairedByTheNextCheckUnderSteadyTraffic(): void
    {
        [$manager, $client] = $this->socketWithOwnedRow(false);
        $this->tickAt($client, 100000);
        $this->tickAt($client, 120000);
        $this->assertSame([true], $this->toldOwner($client, $manager));
        $this->assertSame([[StateHilosConnection::probablyOffline => true]], $this->queuedUpdateDiffs());

        $this->writeFromBrowser($client, self::PONG);
        $this->tickAt($client, 130000);
        $this->assertSame([false], $this->toldOwner($client, null));

        for ($nowMs = 140000; $nowMs < 150000; $nowMs += 5000) {
            $this->writeFromBrowser($client, self::PONG);
            $this->tickAt($client, $nowMs);
        }
        $this->assertSame([], $this->toldOwner($client, $manager));
        $this->assertTrue($this->connectionsRegistry()[$client->acceptKey]->probablyOffline);

        $this->writeFromBrowser($client, self::PONG);
        $this->tickAt($client, 150000);
        $this->assertSame([false], $this->toldOwner($client, $manager));
        $this->assertFalse($this->connectionsRegistry()[$client->acceptKey]->probablyOffline);
        $this->assertSame([[StateHilosConnection::probablyOffline => false]], $this->queuedUpdateDiffs());

        for ($nowMs = 160000; $nowMs <= 250000; $nowMs += 10000) {
            $this->writeFromBrowser($client, self::PONG);
            $this->tickAt($client, $nowMs);
        }
        $this->assertSame([], $this->toldOwner($client, $manager));
    }

    /**
     * A transition on the very tick a check falls due is told once: the check would repeat the
     * same state to the same owner. The check after it still repairs what was missed.
     */
    public function testATransitionOnACheckTickIsToldOnce(): void
    {
        [, $client] = $this->socketWithOwnedRow(false);
        $this->tickAt($client, 50000);

        $this->tickAt($client, 150000);
        $this->assertSame([true], $this->toldOwner($client, null));

        $this->tickAt($client, 199999);
        $this->assertSame([], $this->toldOwner($client, null));
        $this->tickAt($client, 200000);
        $this->assertSame([true], $this->toldOwner($client, null));
    }

    /**
     * No row for the socket is no repeat and no row made: the transition is told, the owner has
     * nothing to write, and every check after it passes the socket over.
     */
    public function testASocketWithoutARowIsPassedOverByEveryCheck(): void
    {
        $manager = $this->arrangeOwner();
        $client = $this->handshakenSocket();

        $this->tickAt($client, 50000);
        $this->tickAt($client, 100000);
        $this->assertSame([], $this->toldOwner($client, $manager));

        $this->tickAt($client, 120000);
        $this->assertSame([true], $this->toldOwner($client, $manager));

        $this->tickAt($client, 150000);
        $this->tickAt($client, 200000);
        $this->assertSame([], $this->toldOwner($client, $manager));
        $this->assertFalse(isset($this->connectionsRegistry()[$client->acceptKey]));
        $this->assertCount(0, $this->connectionsRegistry());
    }

    /**
     * Once the socket is to close, a row that disagrees with it is not checked and not told.
     */
    public function testASocketToCloseIsCheckedAgainstNoRow(): void
    {
        [, $closing] = $this->socketWithOwnedRow(true);
        $closing->markShouldClose();
        $closing->now = 50000;
        $closing->onTick();
        $this->assertSame([], $this->toldOwner($closing, null));

        $client = $this->handshakenSocket();
        $this->registerOwnedRow($client->acceptKey, true);
        $this->tickAt($client, 600000);
        $this->assertTrue($client->shouldClose());
        $this->assertSame([], $this->toldOwner($client, null));
    }

    /**
     * Builds the transition signal the master socket queues for one of its connections.
     *
     * @param string $acceptKey Connection that moved
     * @param bool $probablyOffline State it moved into
     * @return SignalDTO Internal WebSocket signal named after its own type
     */
    private function livenessSignal(string $acceptKey, bool $probablyOffline): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::WEBSOCKET),
            new SignalType(SignalTypeConstants::CONNECTION_LIVENESS),
            new SignalName(SignalTypeConstants::CONNECTION_LIVENESS),
            new WebSocketLivenessSignalDTO($acceptKey, $probablyOffline),
        );
    }

    /**
     * Wraps the transition as the frame the daemon writes to the worker of one agent.
     *
     * @param string $agentId Agent the frame is addressed to
     * @param string $acceptKey Connection that moved
     * @param bool $probablyOffline State it moved into
     * @return DaemonAgentMessageDTO Daemon-to-worker agent frame
     */
    private function addressed(string $agentId, string $acceptKey, bool $probablyOffline): DaemonAgentMessageDTO
    {
        return new DaemonAgentMessageDTO($agentId, $this->livenessSignal($acceptKey, $probablyOffline));
    }

    /**
     * Mounts one connection owned by the worker's owner agent, holding the given doubt, with an empty queue.
     *
     * @param bool $probablyOffline Doubt the row holds before the case runs
     * @return LivenessTestWorkerManager Worker whose two agents the frames are addressed to
     */
    private function arrangeOwnedConnection(bool $probablyOffline): LivenessTestWorkerManager
    {
        $manager = $this->arrangeOwner();
        $this->registerOwnedRow(self::ACCEPT_KEY, $probablyOffline);

        return $manager;
    }

    /**
     * Mounts the connections with the worker's owner agent as their truth source, and no row yet.
     *
     * @return LivenessTestWorkerManager Worker whose two agents the frames are addressed to
     */
    private function arrangeOwner(): LivenessTestWorkerManager
    {
        $manager = new LivenessTestWorkerManager();
        Hilos::$rt = new LivenessTestRtContext();
        Hilos::$rt->configure();
        RtTruthSourceRegistry::register(LivenessTestRtContext::connections, TruthSourceKeys::all(), LivenessTestWorkerManager::OWNER_ID);

        return $manager;
    }

    /**
     * Registers a row as the owner agent, holding the given doubt, and leaves the queue empty.
     *
     * @param string $acceptKey Connection the row is for
     * @param bool $probablyOffline Doubt the row holds before the case runs
     */
    private function registerOwnedRow(string $acceptKey, bool $probablyOffline): void
    {
        ExecutionContext::setCurrentAgentId(LivenessTestWorkerManager::OWNER_ID);
        $connections = $this->connectionsRegistry();
        $connections->actions->register($acceptKey, 7);
        $connections[$acceptKey]->actions->setProbablyOffline($probablyOffline);
        ExecutionContext::clear();
        while (Hilos::$sr?->getNextQueuedSignal() !== null) {
            // The row's own creation and the arranged doubt are not what these cases are about.
        }
    }

    /**
     * Opens a master socket through its real handshake at moment 0, with the queue left empty.
     *
     * @return LivenessTestClient Socket whose clock the case moves by hand
     */
    private function handshakenSocket(): LivenessTestClient
    {
        Hilos::$env = new EnvAccessor();
        $pair = [];
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        socket_set_nonblock($pair[0]);
        socket_set_nonblock($pair[1]);
        $client = new LivenessTestClient($pair[0]);
        $this->connections[] = [$client, $pair[1]];

        $this->writeFromBrowser($client, self::UPGRADE_REQUEST);
        $client->read();
        $this->assertNotSame('', $client->acceptKey);
        $this->assertSame(SignalTypeConstants::HANDSHAKE, Hilos::$sr?->getNextQueuedSignal()?->signalType->getType());
        $this->assertNull(Hilos::$sr?->getNextQueuedSignal());

        return $client;
    }

    /**
     * Opens a master socket whose connection row the owner holds with the given doubt.
     *
     * @param bool $rowProbablyOffline Doubt the owner's row holds; the socket itself starts heard from
     * @return array{LivenessTestWorkerManager, LivenessTestClient} Worker of the owner, and the socket
     */
    private function socketWithOwnedRow(bool $rowProbablyOffline): array
    {
        $manager = $this->arrangeOwner();
        $client = $this->handshakenSocket();
        $this->registerOwnedRow($client->acceptKey, $rowProbablyOffline);

        return [$manager, $client];
    }

    /**
     * Runs one turn of the server's loop for this socket at the given moment.
     *
     * @param LivenessTestClient $client Socket the turn is for
     * @param int $nowMs Monotonic moment of the turn
     */
    private function tickAt(LivenessTestClient $client, int $nowMs): void
    {
        $client->now = $nowMs;
        $client->read();
        $client->onTick();
        $client->write();
    }

    /**
     * Writes bytes from the browser end of the socket, for its next turn to read.
     *
     * @param LivenessTestClient $client Socket the browser is connected to
     * @param string $bytes Bytes as the browser sends them
     */
    private function writeFromBrowser(LivenessTestClient $client, string $bytes): void
    {
        foreach ($this->connections as [$held, $browser]) {
            if ($held === $client) {
                $this->assertSame(strlen($bytes), socket_write($browser, $bytes));

                return;
            }
        }
        $this->fail('the socket is not one this case opened');
    }

    /**
     * Drains the queue, which must hold nothing but this socket's liveness transitions, and hands them on.
     *
     * Each one goes to the owner as the daemon addresses it, unless the owner is gone: then what
     * the master told is lost on the way, as it is to an owner stopped or cut off from this node.
     *
     * @param LivenessTestClient $client Socket whose transitions the queue holds
     * @param ?LivenessTestWorkerManager $owner Worker of the owner, or null while the owner is gone
     * @return list<bool> State of each transition the socket told, in order
     */
    private function toldOwner(LivenessTestClient $client, ?LivenessTestWorkerManager $owner): array
    {
        $signals = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            $signals[] = $signal;
        }

        $states = [];
        foreach ($signals as $signal) {
            $this->assertSame(SignalSource::WEBSOCKET, $signal->signalSource->getSource());
            $this->assertSame(SignalTypeConstants::CONNECTION_LIVENESS, $signal->signalType->getType());
            $this->assertInstanceOf(WebSocketLivenessSignalDTO::class, $signal->data);
            $this->assertSame($client->acceptKey, $signal->data->acceptKey);
            $states[] = $signal->data->probablyOffline;
            $owner?->handleDaemonMessage(new DaemonAgentMessageDTO(LivenessTestWorkerManager::OWNER_ID, $signal));
        }

        return $states;
    }

    /**
     * @return LivenessTestConnections Connections the context represents
     */
    private function connectionsRegistry(): LivenessTestConnections
    {
        $connections = Hilos::$rt?->connectionsRegistry();
        $this->assertInstanceOf(LivenessTestConnections::class, $connections);

        return $connections;
    }

    /**
     * Drains the queue and keeps the diffs of the RT updates it carried.
     *
     * @return list<array<string, mixed>> Diff of each queued RT update, in order
     */
    private function queuedUpdateDiffs(): array
    {
        $diffs = [];
        while (($signal = Hilos::$sr?->getNextQueuedSignal()) !== null) {
            if (
                $signal->signalSource->getSource() !== SignalSource::RT
                || $signal->signalType->getType() !== SignalConstants::RT_SYNC_UPDATED
            ) {
                continue;
            }
            $this->assertInstanceOf(RtSyncUpdatedSignalData::class, $signal->data);
            $diffs[] = $signal->data->row;
        }

        return $diffs;
    }

    /**
     * @return array{LivenessForgeTestClient, Socket} Real connected client and its browser end
     */
    private function browserConnection(): array
    {
        $pair = [];
        $this->assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        socket_set_nonblock($pair[0]);
        socket_set_nonblock($pair[1]);
        $client = new LivenessForgeTestClient($pair[0]);
        $this->connections[] = [$client, $pair[1]];

        return [$client, $pair[1]];
    }
}

/**
 * Worker holding two agents: the owner of the connections and one that owns none of them.
 */
final class LivenessTestWorkerManager extends WorkerManager
{
    public const string OWNER_ID = 'unit_liveness_owner';
    public const string STRANGER_ID = 'unit_liveness_stranger';

    public function __construct()
    {
        parent::__construct(1);
        $this->agentManager->addAgent(self::OWNER_ID, new LivenessTestAgent());
        $this->agentManager->addAgent(self::STRANGER_ID, new LivenessTestAgent());
    }

    /**
     * @return SignalRouter Plain router, enough to queue the RT sync a write announces
     */
    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    /**
     * @return AgentManager Manager the test fills by hand
     */
    protected function createAgentManager(): AgentManager
    {
        return new LivenessTestAgentManager();
    }
}

final class LivenessTestAgentManager extends AgentManager
{
    /**
     * @param string $agentType Agent type
     * @param ?string $agentIndex Agent index
     * @return AgentInterface Fixture agent
     */
    protected function createAgent(string $agentType, ?string $agentIndex): AgentInterface
    {
        return new LivenessTestAgent();
    }
}

final class LivenessTestAgent extends AbstractAgent
{
    public const string AGENT_TYPE = 'unit_liveness';

    public function onStop(): void
    {
    }
}

/**
 * Presence-stage row with nothing of its own, syncing under the collection the context mounts it as.
 */
final class LivenessTestConnection extends StateHilosConnection
{
    public static function getRtCollectionKey(): string
    {
        return LivenessTestRtContext::connections;
    }

    protected function initOwn(): void
    {
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row (nothing of its own to read)
     */
    protected function hydrateOwn(array $row): void
    {
    }

    /**
     * @return array<string, mixed> Always empty: the row is the framework base
     */
    protected function ownToArray(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $diff Partial update (nothing of its own to apply)
     */
    protected function applyOwnDiff(array $diff): void
    {
    }
}

/**
 * @extends StateHilosConnections<LivenessTestConnection>
 */
final class LivenessTestStates extends StateHilosConnections
{
    public const string STATE_CLASS = LivenessTestConnection::class;
}

/**
 * @extends HilosConnection<LivenessTestConnection>
 */
final class LivenessTestItem extends HilosConnection
{
}

/**
 * @extends HilosConnectionActions<LivenessTestItem>
 */
final class LivenessTestItemActions extends HilosConnectionActions
{
}

/**
 * @extends HilosConnectionsActions<LivenessTestItem, LivenessTestConnections>
 */
final class LivenessTestCollectionActions extends HilosConnectionsActions
{
}

/**
 * @extends HilosConnections<LivenessTestItem, LivenessTestCollectionActions>
 */
final class LivenessTestConnections extends HilosConnections
{
    /**
     * @param RtState $state Backing state row
     * @return LivenessTestItem View item over the row
     */
    protected function createRtItem(RtState $state): LivenessTestItem
    {
        /** @var LivenessTestConnection $state */
        return new LivenessTestItem($state);
    }
}

/**
 * Runtime context of a project that mounts and represents its connections.
 */
final class LivenessTestRtContext extends RtContext
{
    public const string connections = 'livenessTestConnections';

    /**
     * Mounts the connections collection and gives it the write API the doubt goes through.
     */
    public function configure(): void
    {
        $this->_stateCollections[self::connections] = LivenessTestStates::init();
        $this->setRepresent(
            self::connections,
            LivenessTestConnections::class,
            LivenessTestCollectionActions::class,
            LivenessTestItemActions::class,
        );
    }
}

/**
 * Runtime context of a project that keeps no connections at all.
 */
final class LivenessTestBareRtContext extends RtContext
{
    /**
     * Mounts nothing: there is no connection row anywhere to doubt.
     */
    public function configure(): void
    {
    }
}

/**
 * Real browser socket whose text frames the test writes straight into the frame parser.
 */
final class LivenessForgeTestClient extends WebSocketClient
{
    public function __construct(Socket $socket)
    {
        parent::__construct($socket);
        $this->handshakeCompleted = true;
        $this->acceptKey = 'liveness-forger-' . spl_object_id($this);
    }

    public function acceptJson(string $json): void
    {
        $this->onFrame($json);
    }

    protected function onHandshake(
        array $headers,
        string $acceptKey,
        array $cookies,
        ?string $clientIp,
        RequestQueryParams $queryParams,
    ): void {
    }

    protected function onClose(): void
    {
    }
}

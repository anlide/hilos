<?php

declare(strict_types=1);

namespace Hilos\Socket\Server;

use Closure;
use Generator;
use Hilos\Core\Daemon\BrowserDeliveryTarget;
use Hilos\Core\Daemon\ConnectionDropper;
use Hilos\Core\Daemon\ProtectedModeAdmissionRecorder;
use Hilos\Core\Daemon\SignalHoldIndex;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\LocalClientSignalBatch;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalName;
use Hilos\Files\Upload\UploadMemoryPoolSink;
use Hilos\HilosException;
use Hilos\Socket\Client\ClientInterface;
use Hilos\Socket\Client\Interface\WebSocketClientInterface;
use Hilos\Socket\Client\WebSocketClient;
use Hilos\Socket\SocketException;
use Hilos\Socket\WebSocket\Delivery\DeliveryCleanup;
use Hilos\Socket\WebSocket\Delivery\DeliverySettingsMirror;
use WeakReference;

/**
 * WebSocketServer - WebSocket server implementation.
 *
 * Manages WebSocket server socket and accepts incoming connections.
 * Works with epoll in daemon main loop.
 *
 * This is an abstract class - child classes must implement onCreateClient()
 * to create concrete WebSocketClient instances.
 *
 * @extends AbstractServer<WebSocketClientInterface>
 */
abstract class WebSocketServer extends AbstractServer
{
    /** @var ?ConnectionDropper Master seam that force-closes a connection, wired at registration */
    private ?ConnectionDropper $connectionDropper = null;

    /** @var ?ProtectedModeAdmissionRecorder Master seam that records an admitted verifier, wired at registration */
    private ?ProtectedModeAdmissionRecorder $protectedModeAdmissionRecorder = null;

    /** @var ?UploadMemoryPoolSink Admission seam injected by production registerServer */
    private ?UploadMemoryPoolSink $uploadMemoryPoolSink = null;

    /** Live delivery setting shared with every accepted socket. */
    private ?DeliverySettingsMirror $deliverySettingsMirror = null;

    private ?DeliveryCleanup $deliveryCleanup = null;

    /** @var array<string, int> Live accept key to exact roster slot; never shared across processes */
    private array $clientKeys = [];

    /** @var array<int, int> Client object identity to its original roster slot */
    private array $clientSlots = [];

    /** @var array<int, int> Key incarnation per live slot, invalidated even by reusing a previous key */
    private array $clientKeyGenerations = [];

    private int $clientKeySequence = 0;

    /** Local keyed-roster input revision, independent of retained delivery cutoffs. */
    private int $localConnectionRevision = 0;

    /** @var array<string, SignalHoldIndex> Session members ordered by transport admission, not handshake completion */
    private array $sessionClients = [];

    /** @var array<int, string> Indexed immutable handshake identity of each live transport */
    private array $clientSessions = [];

    /** Original handshaking transports remain possible session matches until their own visit. */
    private ?SignalHoldIndex $handshakingClients = null;

    /** Scalar keyed slots, so a queued notification need not retain this server's transports. */
    private ?SignalHoldIndex $keyedClients = null;

    private ?int $clientSlotTail = null;

    /**
     * Called when a new WebSocket client connection is accepted.
     *
     * Must be implemented by child classes to create concrete WebSocketClient.
     *
     * @param resource $socket Client socket
     * @return WebSocketClientInterface Client instance
     * @throws HilosException Whatever the project's client build raises, its read of env among it
     */
    abstract protected function onCreateClient($socket): WebSocketClientInterface;

    /**
     * Wires the seam that force-closes a connection, for the clients this server accepts.
     *
     * Held by the server rather than passed to {@see onCreateClient()} because that method
     * is the project's: a project builds its own client subclass, and none of them should
     * have to carry a daemon seam through their constructor to get framework behavior.
     *
     * @param ConnectionDropper $connectionDropper Master seam that force-closes a connection
     */
    public function setConnectionDropper(ConnectionDropper $connectionDropper): void
    {
        $this->connectionDropper = $connectionDropper;
    }

    /**
     * Wires the seam that records an admitted verifier, for the clients this server accepts.
     *
     * Held here for the same reason as the dropper above: a project builds its own client
     * subclass, and none of them should have to carry a daemon seam through their constructor.
     *
     * @param ProtectedModeAdmissionRecorder $recorder Master seam that records an admitted verifier
     */
    public function setProtectedModeAdmissionRecorder(ProtectedModeAdmissionRecorder $recorder): void
    {
        $this->protectedModeAdmissionRecorder = $recorder;
    }

    /**
     * registerServer shares the master's live mirror with the sockets accepted here.
     *
     * @param DeliverySettingsMirror $mirror This master's effective delivery window
     */
    public function setDeliverySettingsMirror(DeliverySettingsMirror $mirror): void
    {
        $this->deliverySettingsMirror = $mirror;
    }

    /** @param DeliveryCleanup $cleanup Master's guarded owner for retired socket-private arrays */
    public function setDeliveryCleanup(DeliveryCleanup $cleanup): void
    {
        $this->deliveryCleanup = $cleanup;
    }

    /**
     * registerServer mounts the same master ledger on both transport servers.
     * Projects keep their existing client factories and constructor signatures.
     *
     * @param UploadMemoryPoolSink $sink The daemon's sole upload ledger owner
     */
    public function setUploadMemoryPoolSink(UploadMemoryPoolSink $sink): void
    {
        $this->uploadMemoryPoolSink = $sink;
    }

    /**
     * Accepts a connection and hands the new client the connection-drop seam.
     *
     * A client needs it for exactly one thing: dropping the other connections of a session
     * whose token it just rotated (HIL-582). It is given here, at accept, rather than looked
     * up at the moment of use, so the client never reaches back into the daemon.
     *
     * @return ?ClientInterface New client or null when no connection is pending
     * @throws SocketException When socket operations fail
     * @throws HilosException When the concrete client refuses to be constructed
     * @throws LogicException When a constructed client is already bound to another upload ledger
     */
    public function acceptConnection(): ?ClientInterface
    {
        $client = parent::acceptConnection();
        if ($client instanceof WebSocketClient && $this->connectionDropper !== null) {
            $client->setConnectionDropper($this->connectionDropper);
        }
        if ($client instanceof WebSocketClient && $this->protectedModeAdmissionRecorder !== null) {
            $client->setProtectedModeAdmissionRecorder($this->protectedModeAdmissionRecorder);
        }
        if ($client instanceof WebSocketClient && $this->uploadMemoryPoolSink !== null) {
            $client->setUploadMemoryPoolSink($this->uploadMemoryPoolSink);
        }
        if ($client instanceof WebSocketClient && $this->deliverySettingsMirror !== null) {
            $client->setDeliverySettingsMirror($this->deliverySettingsMirror);
        }

        return $client;
    }

    /**
     * @param string $acceptKey Connection identifier on this server
     * @return ?BrowserDeliveryTarget Exact indexed transport incarnation, absent while handshaking or closing
     */
    public function clientTarget(string $acceptKey): ?BrowserDeliveryTarget
    {
        $slot = $this->clientKeys[$acceptKey] ?? null;
        $client = $slot === null ? null : ($this->clients[$slot] ?? null);
        if (!$client instanceof WebSocketClient || !$this->isCurrentClient($slot, $client)) {
            return null;
        }
        return new BrowserDeliveryTarget($this, $slot, $client, $this->clientKeyGenerations[$slot]);
    }

    /** @return int Nonconsuming keyed admission, key-change and exact departure revision; no roster walk */
    public function localConnectionRevision(): int
    {
        return $this->localConnectionRevision;
    }

    /**
     * @param int $slot Original roster slot
     * @param WebSocketClient $client Original connection
     * @param int $generation Original accept-key incarnation
     * @return bool Whether this exact address still names the captured transport
     */
    public function isCurrentClientTarget(int $slot, WebSocketClient $client, int $generation): bool
    {
        return $this->isCurrentClient($slot, $client) && ($this->clientKeyGenerations[$slot] ?? null) === $generation
            && ($this->clientKeys[$client->acceptKey] ?? null) === $slot;
    }

    /**
     * Captures the transport tail now. Identity is still read at each original transport's visit,
     * so a handshake completed between portions can join, while a later transport cannot.
     *
     * @param string $sessionTokenHash Included session
     * @return Generator<int, ?WebSocketClientInterface> Indexed members and unfinished handshakes in connection order
     */
    public function sessionClientCandidates(string $sessionTokenHash): Generator
    {
        return $this->walkClients($this->sessionClientSlots($sessionTokenHash, $this->clientSlotTail));
    }

    /**
     * Admission freezes the existing keyed identities; delivery still rejects departed sockets.
     * A later handshake on an original transport belongs to its welcome, not this notification.
     *
     * @param SignalName $name Shared local notification name
     * @param SignalDataInterface $data Shared immutable payload
     * @param Closure(WebSocketClient): bool $include Admission verdict captured by the caller
     * @param ?string $sessionTokenHash Included session, absent for every local transport
     * @param ?Generator<int, void> $preparation Original admission rule's portioned preparation
     * @return LocalClientSignalBatch Finite scalar slots with a weak reference to their original transport owner
     * @throws InvalidArgumentException When the shared name is empty
     */
    public function localClientSignals(
        SignalName $name,
        SignalDataInterface $data,
        Closure $include,
        ?string $sessionTokenHash = null,
        ?Generator $preparation = null,
    ): LocalClientSignalBatch {
        $slots = $sessionTokenHash === null ? ($this->keyedClients ?? new SignalHoldIndex())->candidates()
            : ($this->sessionClients[$sessionTokenHash] ?? new SignalHoldIndex())->candidates();
        $server = WeakReference::create($this);
        $keyCutoff = $this->clientKeySequence;
        return new LocalClientSignalBatch($name, $data, $slots,
            static function (int $slot) use ($server, $keyCutoff, $include): ?BrowserDeliveryTarget {
                $owner = $server->get();
                $client = $owner?->clients[$slot] ?? null;
                $generation = $owner?->clientKeyGenerations[$slot] ?? null;
                return $client instanceof WebSocketClient && $client->acceptKey !== ''
                    && $generation !== null && $generation <= $keyCutoff && $include($client)
                    ? new BrowserDeliveryTarget($owner, $slot, $client, $generation) : null;
            }, $preparation);
    }

    /** @param ClientInterface $client Exact departing transport; a replacement's key remains indexed */
    public function removeClient(ClientInterface $client): void
    {
        if (!$client instanceof WebSocketClient) {
            parent::removeClient($client);
            return;
        }
        $slot = $this->clientSlots[spl_object_id($client)] ?? null;
        if ($slot === null || ($this->clients[$slot] ?? null) !== $client) {
            return;
        }
        if ($client->acceptKey !== '') {
            ++$this->localConnectionRevision;
        }
        if (($this->clientKeys[$client->acceptKey] ?? null) === $slot) {
            unset($this->clientKeys[$client->acceptKey]);
        }
        if (isset($this->clientSessions[$slot])) {
            $session = $this->clientSessions[$slot];
            $this->sessionClients[$session]->remove($slot);
            if ($this->sessionClients[$session]->isEmpty()) {
                unset($this->sessionClients[$session]);
            }
            unset($this->clientSessions[$slot]);
        }
        $this->handshakingClients?->remove($slot);
        $this->keyedClients?->remove($slot);
        $client->setAcceptKeyChanged(null);
        unset($this->clientSlots[spl_object_id($client)], $this->clientKeyGenerations[$slot]);
        parent::removeClient($client);
    }

    /**
     * Get server name for logging.
     *
     * @return string Server name
     */
    public function getServerName(): string
    {
        return "WebSocket Server";
    }

    /**
     * Prepare server for shutdown.
     *
     * Stops reading browsers on this node while continuing to write to them.
     *
     * The master closes the listening socket separately. Browsers close after workers
     * are gone and their last deliveries are written, via {@see closeClientsOnceWritten()}.
     */
    public function prepareShutdown(): void
    {
        parent::prepareShutdown();

        foreach ($this->clients as $client) {
            $client->stopReading();
        }
    }

    /**
     * Close browsers once their queued deliveries are written.
     *
     * The master calls this after its workers are gone (HIL-1207).
     */
    public function closeClientsOnceWritten(): void
    {
        foreach ($this->clients as $client) {
            $client->closeOnceWritten();
        }
    }

    /**
     * Check if server is ready to shutdown.
     *
     * WebSocket server is ready when all clients have disconnected. Clients leave only
     * after closeClientsOnceWritten(), so shutdown waits for the workers and browser writes.
     *
     * @return bool True if ready to shutdown
     */
    public function isReadyToShutdown(): bool
    {
        // Ready when no clients are connected
        return empty($this->clients);
    }

    /**
     * Stop server.
     *
     * Closes server socket only. Does NOT close client connections.
     * Clients should complete their sessions and disconnect themselves.
     */
    public function stop(): void
    {
        // Close server socket only, don't close client connections
        if ($this->socket !== null) {
            socket_close($this->socket);
            $this->socket = null;
        }

        $this->isRunning = false;
    }

    /**
     * Called when server is started.
     *
     * Must be implemented by child classes to perform actions when server starts.
     */
    abstract protected function onStart(): void;

    /**
     * Registers before the first handshake can name the socket. The callback holds the server
     * weakly: an escaped client must not retain a departed server and its entire roster.
     *
     * @param WebSocketClientInterface $client Newly accepted connection
     */
    protected function addClient(ClientInterface $client): void
    {
        parent::addClient($client);
        if ($client instanceof WebSocketClient) {
            if ($this->deliveryCleanup !== null) {
                $client->setDeliveryCleanup($this->deliveryCleanup);
            }
            $slot = array_key_last($this->clients);
            $this->clientSlots[spl_object_id($client)] = $slot;
            $this->clientSlotTail = $slot;
            $server = WeakReference::create($this);
            $client->setAcceptKeyChanged(static function (WebSocketClient $client, string $previous) use ($server): void {
                $server->get()?->indexClientKey($client, $previous);
            });
            $this->indexClientKey($client, $client->acceptKey);
        }
    }

    /**
     * @param WebSocketClient $client Registered transport whose key changed
     * @param string $previous Previous key, possibly still empty before the handshake
     */
    private function indexClientKey(WebSocketClient $client, string $previous): void
    {
        $slot = $this->clientSlots[spl_object_id($client)] ?? null;
        if ($slot === null || ($this->clients[$slot] ?? null) !== $client) {
            return;
        }
        if ($previous !== $client->acceptKey || ($client->acceptKey !== '' && !isset($this->clientKeyGenerations[$slot]))) {
            ++$this->localConnectionRevision;
        }
        if (($this->clientKeys[$previous] ?? null) === $slot) {
            unset($this->clientKeys[$previous]);
        }
        $this->clientKeyGenerations[$slot] = ++$this->clientKeySequence;
        if ($client->acceptKey !== '') {
            $this->clientKeys[$client->acceptKey] = $slot;
            ($this->keyedClients ??= new SignalHoldIndex())->add($slot);
        } else {
            $this->keyedClients?->remove($slot);
        }
        if ($client->sessionTokenHash !== null && !isset($this->clientSessions[$slot])) {
            $this->clientSessions[$slot] = $client->sessionTokenHash;
            ($this->sessionClients[$client->sessionTokenHash] ??= new SignalHoldIndex())->add($slot);
        }
        if ($client->acceptKey === '' && $client->sessionTokenHash === null) {
            ($this->handshakingClients ??= new SignalHoldIndex())->add($slot);
        } else {
            $this->handshakingClients?->remove($slot);
        }
    }

    /**
     * Merges two scalar indexes at each visit rather than capturing either tree. A handshake may
     * move its old slot between indexes before the cursor reaches it; no roster is scanned.
     *
     * @param string $sessionTokenHash Included session
     * @param ?int $tail Last original transport, absent for an empty cohort
     * @return Generator<int, int> Possible matches through the exact transport cutoff
     */
    private function sessionClientSlots(string $sessionTokenHash, ?int $tail): Generator
    {
        if ($tail === null) {
            return;
        }
        $after = null;
        while (true) {
            $member = ($this->sessionClients[$sessionTokenHash] ?? null)?->successor($after);
            $handshaking = $this->handshakingClients?->successor($after);
            $slot = $member === null ? $handshaking : ($handshaking === null ? $member : min($member, $handshaking));
            if ($slot === null || $slot > $tail) {
                return;
            }
            $after = $slot;
            yield $slot;
        }
    }

}

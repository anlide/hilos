<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\Peer\DTO\PeerAgentStatusDTO;
use Hilos\Cluster\Peer\DTO\PeerAnnounceDTO;
use Hilos\Cluster\Peer\DTO\PeerClientFanoutDTO;
use Hilos\Cluster\Peer\DTO\PeerClientSignalDTO;
use Hilos\Cluster\Peer\DTO\PeerConnectionsDeltaDTO;
use Hilos\Cluster\Peer\DTO\PeerConnectionsSnapshotDTO;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerHandshakeDTO;
use Hilos\Cluster\Peer\DTO\PeerHeartbeatDTO;
use Hilos\Cluster\Peer\DTO\PeerHelloDTO;
use Hilos\Cluster\Peer\DTO\PeerNodeLeavingDTO;
use Hilos\Cluster\Peer\DTO\PeerPingDTO;
use Hilos\Cluster\Peer\DTO\PeerPlaceAgentDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementQueryDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementRequestDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementReportDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementViewDTO;
use Hilos\Cluster\Peer\DTO\PeerPongDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeDisableDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeEnableDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeLiftDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModePassDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeProgressDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeQuiesceDTO;
use Hilos\Cluster\Peer\DTO\PeerDbReHydratedDTO;
use Hilos\Cluster\Peer\DTO\PeerDbReHydrateDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeQuiescedDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeReadyDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeRefreezeDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeVerifyDTO;
use Hilos\Cluster\Peer\DTO\PeerRequestVoteDTO;
use Hilos\Cluster\Peer\DTO\PeerRosterDTO;
use Hilos\Cluster\Peer\DTO\PeerRtSnapshotDTO;
use Hilos\Cluster\Peer\DTO\PeerDbSyncDTO;
use Hilos\Cluster\Peer\DTO\PeerRtSyncDTO;
use Hilos\Cluster\Peer\DTO\PeerSignalDTO;
use Hilos\Cluster\Peer\DTO\PeerSourceInterestDTO;
use Hilos\Cluster\Peer\DTO\PeerStopAgentDTO;
use Hilos\Cluster\Peer\DTO\PeerVoteReplyDTO;
use Hilos\Cluster\Peer\DTO\PeerWelcomeDTO;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Socket\Client\AbstractClient;
use Hilos\Socket\SocketException;
use Hilos\Utils\Logger;

/**
 * One framed connection to a remote cluster node, either dialed or accepted.
 *
 * The dialing side opens the connection and sends {@see PeerHelloDTO} first; the
 * accepting side answers with {@see PeerWelcomeDTO}. Once the handshake sets the
 * remote identity, the two sides exchange membership gossip
 * ({@see PeerRosterDTO}, {@see PeerAnnounceDTO}). This link only parses and
 * frames; the registry updates and the fan-out to other peers are owned by the
 * {@see PeerServer}. A malformed frame or a rejected handshake closes the link
 * rather than propagating out of the daemon loop.
 */
final class PeerLink extends AbstractClient
{
    /** @var int Outbound-buffer backpressure cap: a peer that stops draining is dropped, not buffered to OOM */
    private const int MAX_WRITE_BUFFER_BYTES = 8 * 1024 * 1024;

    /** @var PeerServer Owning peer server, driven for membership fan-out */
    private PeerServer $server;

    /** @var NodeIdentity Local node identity announced to the remote peer */
    private NodeIdentity $localIdentity;

    /** @var bool True when this side dialed out (sends hello); false when it accepted (replies welcome) */
    private bool $dialer;

    /** @var ?NodeIdentity Remote node identity, known once the handshake completes */
    private ?NodeIdentity $remoteIdentity = null;

    /** @var float Seconds of silence before a keepalive ping is sent */
    private float $keepaliveIntervalSec;

    /** @var float Seconds of silence after which the link is closed as dead */
    private float $linkTimeoutSec;

    /** @var float Microtime the last inbound traffic arrived, refreshed on every read */
    private float $lastHeardAt;

    /** @var float Microtime the last keepalive ping was sent, to space pings by the interval */
    private float $lastPingAt = 0.0;

    /**
     * @param resource|object $socket Connected peer socket
     * @param PeerServer $server Owning peer server for membership fan-out
     * @param NodeIdentity $localIdentity Local node identity to announce
     * @param bool $dialer True for the dialing side, false for the accepting side
     * @throws EnvException When the socket read buffer or keepalive env values are missing or invalid
     */
    public function __construct($socket, PeerServer $server, NodeIdentity $localIdentity, bool $dialer)
    {
        parent::__construct($socket);

        $this->server = $server;
        $this->localIdentity = $localIdentity;
        $this->dialer = $dialer;

        $this->keepaliveIntervalSec = Hilos::$env->int(EnvConstants::CLUSTER_LINK_KEEPALIVE_INTERVAL_MS) / TimeConstants::MS_PER_SECOND;
        $this->linkTimeoutSec = Hilos::$env->int(EnvConstants::CLUSTER_LINK_TIMEOUT_MS) / TimeConstants::MS_PER_SECOND;
        $this->maxWriteBufferBytes = self::MAX_WRITE_BUFFER_BYTES;
        $this->lastHeardAt = microtime(true);
    }

    /**
     * Queues the opening hello frame; called by the server after a dial connects.
     */
    public function startHandshake(): void
    {
        $this->sendFrame(new PeerHelloDTO(
            PeerProtocol::VERSION,
            $this->localIdentity->nodeId,
            $this->localIdentity->role,
            $this->localIdentity->capabilities,
            $this->localIdentity->address,
        ));
    }

    /**
     * Queues one peer frame for delivery to this link.
     *
     * @param PeerDTO $frame Frame to send
     */
    public function sendFrame(PeerDTO $frame): void
    {
        $this->writeBuffer .= $frame->toJson() . "\n";
    }

    /**
     * Returns the remote node identity once the handshake has completed.
     *
     * @return ?NodeIdentity Remote identity, or null before the handshake completes
     */
    public function remoteIdentity(): ?NodeIdentity
    {
        return $this->remoteIdentity;
    }

    /**
     * Reports whether this side opened the connection (sent the hello).
     *
     * The duplicate-link tie-break keeps the connection dialed by the smaller node
     * id, so the collapse needs to know each link's direction.
     *
     * @return bool True for the dialing side, false for the accepting side
     */
    public function isDialer(): bool
    {
        return $this->dialer;
    }

    /**
     * Silently drops this link after it lost the duplicate-link tie-break.
     *
     * The peer is still reachable over the surviving link, so this must not look
     * like a departure: the remote identity is cleared first, which makes
     * {@see onClose()} a no-op and keeps the registry entry and the leave gossip
     * untouched. The link is then scheduled to close on the next tick.
     */
    public function discardAsDuplicate(): void
    {
        $this->remoteIdentity = null;
        $this->markShouldClose();
    }

    /**
     * Parses complete peer frames and dispatches them, closing on a bad frame.
     *
     * @throws SocketException If buffer size or JSON depth exceeds limits
     * @throws InvalidArgumentException When the re-hydrate signal cannot be named
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     * @throws HilosException Whatever the project's own leadership duties raise when this node wins a term
     */
    protected function processReadBuffer(): void
    {
        // Any inbound traffic proves the peer is alive; refresh the keepalive clock before
        // parsing so a partial frame split across reads still staves off the timeout.
        $this->lastHeardAt = microtime(true);

        while ($this->readBuffer !== '') {
            // Handling a frame can tear this link down mid-buffer — a completed handshake that
            // loses the duplicate collapse discards its own link. Once it is closing, stop
            // parsing the frames that followed in the same read, or a trailing gossip frame
            // would be mis-flagged as arriving before the (now-undone) handshake.
            if ($this->shouldClose) {
                return;
            }

            $message = $this->extractCompleteJsonMessage($this->readBuffer);
            if ($message === null) {
                // Incomplete frame, wait for more data.
                break;
            }

            try {
                $this->handleFrame(PeerDTO::fromWire($message));
            } catch (PeerTransportException $e) {
                Logger::warning("Peer link dropped: {$e->getMessage()}");
                $this->markShouldClose();
                return;
            }
        }
    }

    /**
     * Runs the per-link keepalive: closes a silent link, otherwise pings a quiet one.
     *
     * A link that has heard nothing for the timeout is closed as dead — a hung-but-connected
     * node whose event loop froze with the socket open, which the ordinary link close then
     * routes through the offline/failover path; the same timeout also bounds a stalled
     * half-open handshake. Before that, a link quiet for the keepalive interval sends a
     * {@see PeerPingDTO} (spaced by the interval) to draw a pong and prove the peer alive; a
     * busy link resets its clock on every inbound frame and never pings. Pinging waits for the
     * handshake, so a half-open link only times out.
     */
    public function onTick(): void
    {
        $now = microtime(true);
        $silentFor = $now - $this->lastHeardAt;

        if ($silentFor >= $this->linkTimeoutSec) {
            Logger::warning("Peer link timed out after {$silentFor}s of silence"
                . ($this->remoteIdentity !== null ? " to {$this->remoteIdentity->nodeId}" : ' (handshake never completed)'));
            $this->markShouldClose();
            return;
        }

        if ($this->remoteIdentity !== null
            && $silentFor >= $this->keepaliveIntervalSec
            && ($now - $this->lastPingAt) >= $this->keepaliveIntervalSec) {
            $this->sendFrame(new PeerPingDTO());
            $this->lastPingAt = $now;
        }
    }

    /**
     * Notifies the server so it can mark the peer offline and announce the leave.
     */
    protected function onClose(): void
    {
        if ($this->remoteIdentity === null) {
            return;
        }

        Logger::info("Peer left: {$this->remoteIdentity->nodeId}");
        $this->server->onLinkClosed($this);
    }

    /**
     * Routes a parsed frame to its handler.
     *
     * @param PeerDTO $frame Parsed peer frame
     * @throws PeerTransportException When the frame is out of sequence or of an unknown type
     */
    private function handleFrame(PeerDTO $frame): void
    {
        match (true) {
            $frame instanceof PeerHelloDTO => $this->onHello($frame),
            $frame instanceof PeerWelcomeDTO => $this->onWelcome($frame),
            $frame instanceof PeerRosterDTO => $this->onRoster($frame),
            $frame instanceof PeerAnnounceDTO => $this->onAnnounce($frame),
            $frame instanceof PeerRequestVoteDTO => $this->onRequestVote($frame),
            $frame instanceof PeerVoteReplyDTO => $this->onVoteReply($frame),
            $frame instanceof PeerHeartbeatDTO => $this->onHeartbeat($frame),
            $frame instanceof PeerNodeLeavingDTO => $this->onNodeLeaving($frame),
            $frame instanceof PeerPlaceAgentDTO => $this->onPlaceAgent($frame),
            $frame instanceof PeerStopAgentDTO => $this->onStopAgent($frame),
            $frame instanceof PeerAgentStatusDTO => $this->onAgentStatus($frame),
            $frame instanceof PeerPlacementQueryDTO => $this->onPlacementQuery($frame),
            $frame instanceof PeerPlacementReportDTO => $this->onPlacementReport($frame),
            $frame instanceof PeerPlacementViewDTO => $this->onPlacementView($frame),
            $frame instanceof PeerPlacementRequestDTO => $this->onPlacementRequest($frame),
            $frame instanceof PeerSignalDTO => $this->onSignal($frame),
            $frame instanceof PeerRtSyncDTO => $this->onRtSync($frame),
            $frame instanceof PeerDbSyncDTO => $this->onDbSync($frame),
            $frame instanceof PeerRtSnapshotDTO => $this->onRtSnapshot($frame),
            $frame instanceof PeerSourceInterestDTO => $this->onSourceInterest($frame),
            $frame instanceof PeerClientSignalDTO => $this->onClientSignal($frame),
            $frame instanceof PeerClientFanoutDTO => $this->onClientFanout($frame),
            $frame instanceof PeerConnectionsSnapshotDTO => $this->onConnectionsSnapshot($frame),
            $frame instanceof PeerConnectionsDeltaDTO => $this->onConnectionsDelta($frame),
            $frame instanceof PeerProtectedModeEnableDTO => $this->onProtectedModeEnable($frame),
            $frame instanceof PeerProtectedModeReadyDTO => $this->onProtectedModeReady($frame),
            $frame instanceof PeerProtectedModeDisableDTO => $this->onProtectedModeDisable($frame),
            $frame instanceof PeerProtectedModeQuiesceDTO => $this->onProtectedModeQuiesce($frame),
            $frame instanceof PeerProtectedModeQuiescedDTO => $this->onProtectedModeQuiesced($frame),
            $frame instanceof PeerDbReHydrateDTO => $this->onDbReHydrate($frame),
            $frame instanceof PeerDbReHydratedDTO => $this->onDbReHydrated($frame),
            $frame instanceof PeerProtectedModeLiftDTO => $this->onProtectedModeLift($frame),
            $frame instanceof PeerProtectedModeVerifyDTO => $this->onProtectedModeVerify($frame),
            $frame instanceof PeerProtectedModeProgressDTO => $this->onProtectedModeProgress($frame),
            $frame instanceof PeerProtectedModePassDTO => $this->onProtectedModePass($frame),
            $frame instanceof PeerProtectedModeRefreezeDTO => $this->onProtectedModeRefreeze($frame),
            $frame instanceof PeerPingDTO => $this->onPing($frame),
            $frame instanceof PeerPongDTO => $this->onPong(),
            default => throw new PeerTransportException('Unexpected peer frame type: ' . $frame->getType()),
        };
    }

    /**
     * Accepting side: records the remote identity and answers with a welcome.
     *
     * @param PeerHelloDTO $hello Incoming hello frame
     * @throws PeerTransportException When a hello arrives on the dialing side or the version is incompatible
     */
    private function onHello(PeerHelloDTO $hello): void
    {
        if ($this->dialer) {
            throw new PeerTransportException('Unexpected hello on the dialing side of a peer link');
        }

        $this->requireCompatible($hello);

        $remote = NodeIdentity::of($hello->nodeId, $hello->role, $hello->capabilities, $hello->address);
        $this->remoteIdentity = $remote;
        $this->sendFrame(new PeerWelcomeDTO(
            PeerProtocol::VERSION,
            $this->localIdentity->nodeId,
            $this->localIdentity->role,
            $this->localIdentity->capabilities,
            $this->localIdentity->address,
        ));
        Logger::info("Peer joined: {$remote->nodeId} role={$remote->role->value}");
        $this->server->onHandshakeComplete($this, $remote);
    }

    /**
     * Dialing side: records the remote identity from the welcome reply.
     *
     * @param PeerWelcomeDTO $welcome Incoming welcome frame
     * @throws PeerTransportException When a welcome arrives on the accepting side or the version is incompatible
     */
    private function onWelcome(PeerWelcomeDTO $welcome): void
    {
        if (!$this->dialer) {
            throw new PeerTransportException('Unexpected welcome on the accepting side of a peer link');
        }

        $this->requireCompatible($welcome);

        $remote = NodeIdentity::of($welcome->nodeId, $welcome->role, $welcome->capabilities, $welcome->address);
        $this->remoteIdentity = $remote;
        Logger::info("Peer handshake complete with {$remote->nodeId} role={$remote->role->value}");
        $this->server->onHandshakeComplete($this, $remote);
    }

    /**
     * Hands a received roster to the server to merge and propagate.
     *
     * @param PeerRosterDTO $roster Incoming roster frame
     * @throws PeerTransportException When the roster arrives before the handshake
     */
    private function onRoster(PeerRosterDTO $roster): void
    {
        $this->requireHandshaked('roster');
        $this->server->onRosterReceived($this, $roster);
    }

    /**
     * Hands a received announcement to the server to merge and propagate.
     *
     * @param PeerAnnounceDTO $announce Incoming announce frame
     * @throws PeerTransportException When the announcement arrives before the handshake
     */
    private function onAnnounce(PeerAnnounceDTO $announce): void
    {
        $this->requireHandshaked('announce');
        $this->server->onAnnounceReceived($this, $announce);
    }

    /**
     * Hands a received request-vote to the server for the coordinator to answer.
     *
     * @param PeerRequestVoteDTO $frame Incoming request-vote frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onRequestVote(PeerRequestVoteDTO $frame): void
    {
        $this->requireHandshaked('request vote');
        $this->server->onRequestVote($frame);
    }

    /**
     * Hands a received vote-reply to the server for the coordinator to count.
     *
     * @param PeerVoteReplyDTO $frame Incoming vote-reply frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onVoteReply(PeerVoteReplyDTO $frame): void
    {
        $this->requireHandshaked('vote reply');
        $this->server->onVoteReply($frame);
    }

    /**
     * Hands a received heartbeat to the server for the coordinator to accept.
     *
     * @param PeerHeartbeatDTO $frame Incoming heartbeat frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onHeartbeat(PeerHeartbeatDTO $frame): void
    {
        $this->requireHandshaked('heartbeat');
        $this->server->onHeartbeat($frame);
    }

    /**
     * Hands a received graceful-leave to the server to update membership and,
     * when this node is the named successor, trigger an immediate election.
     *
     * @param PeerNodeLeavingDTO $frame Incoming graceful-leave frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onNodeLeaving(PeerNodeLeavingDTO $frame): void
    {
        $this->requireHandshaked('node leaving');
        $this->server->onNodeLeaving($frame);
    }

    /**
     * Hands a received place-agent request to the server so this node launches it.
     *
     * @param PeerPlaceAgentDTO $frame Incoming place-agent frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onPlaceAgent(PeerPlaceAgentDTO $frame): void
    {
        $this->requireHandshaked('place agent');
        $this->server->onPlaceAgentReceived($this, $frame);
    }

    /**
     * Hands a received stop-agent request to the server so this node stops it.
     *
     * @param PeerStopAgentDTO $frame Incoming stop-agent frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onStopAgent(PeerStopAgentDTO $frame): void
    {
        $this->requireHandshaked('stop agent');
        $this->server->onStopAgentReceived($this, $frame);
    }

    /**
     * Hands a received agent-status reply to the server for the leader to track.
     *
     * @param PeerAgentStatusDTO $frame Incoming agent-status frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onAgentStatus(PeerAgentStatusDTO $frame): void
    {
        $this->requireHandshaked('agent status');
        $this->server->onAgentStatusReceived($this, $frame);
    }

    /**
     * Hands a received placement query to the server so this node reports what it hosts.
     *
     * @param PeerPlacementQueryDTO $frame Incoming placement-query frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onPlacementQuery(PeerPlacementQueryDTO $frame): void
    {
        $this->requireHandshaked('placement query');
        $this->server->onPlacementQueryReceived($this);
    }

    /**
     * Hands a received placement request to the server so the leader places the wanted agent.
     *
     * @param PeerPlacementRequestDTO $frame Incoming placement-request frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onPlacementRequest(PeerPlacementRequestDTO $frame): void
    {
        $this->requireHandshaked('placement request');
        $this->server->onPlacementRequestReceived($this, $frame);
    }

    /**
     * Hands a received placement report to the server for the leader to rebuild its view.
     *
     * @param PeerPlacementReportDTO $frame Incoming placement-report frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onPlacementReport(PeerPlacementReportDTO $frame): void
    {
        $this->requireHandshaked('placement report');
        $this->server->onPlacementReportReceived($this, $frame);
    }

    /**
     * Hands a received cross-node signal to the server for local delivery.
     *
     * @param PeerSignalDTO $frame Incoming signal-forward frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onSignal(PeerSignalDTO $frame): void
    {
        $this->requireHandshaked('signal');
        $this->server->onSignalReceived($this, $frame);
    }

    /**
     * Hands a received RT replica to the server for this node's copy to be updated.
     *
     * @param PeerRtSyncDTO $frame Incoming RT sync frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onRtSync(PeerRtSyncDTO $frame): void
    {
        $this->requireHandshaked('RT sync');
        $this->server->onRtSyncReceived($this, $frame);
    }

    /**
     * Hands a received DB replica to the server for the rows this node holds to be updated.
     *
     * @param PeerDbSyncDTO $frame Incoming DB sync frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onDbSync(PeerDbSyncDTO $frame): void
    {
        $this->requireHandshaked('DB sync');
        $this->server->onDbSyncReceived($this, $frame);
    }

    /**
     * Hands a received RT collection to the server for this node's copy to be replaced.
     *
     * @param PeerRtSnapshotDTO $frame Incoming RT snapshot frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onRtSnapshot(PeerRtSnapshotDTO $frame): void
    {
        $this->requireHandshaked('RT snapshot');
        $this->server->onRtSnapshotReceived($this, $frame);
    }

    /**
     * Hands a received list of the collections a node reads to the server's map of readers.
     *
     * @param PeerSourceInterestDTO $frame Incoming reader-interest frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onSourceInterest(PeerSourceInterestDTO $frame): void
    {
        $this->requireHandshaked('source interest');
        $this->server->onSourceInterestReceived($this, $frame);
    }

    /**
     * Hands a received placement view to the server for this node to answer lookups from.
     *
     * @param PeerPlacementViewDTO $frame Incoming placement-view frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onPlacementView(PeerPlacementViewDTO $frame): void
    {
        $this->requireHandshaked('placement view');
        $this->server->onPlacementViewReceived($this, $frame);
    }

    /**
     * Hands a received client signal to the server for the browser on this node.
     *
     * @param PeerClientSignalDTO $frame Incoming client signal-forward frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onClientSignal(PeerClientSignalDTO $frame): void
    {
        $this->requireHandshaked('client signal');
        $this->server->onClientSignalReceived($this, $frame);
    }

    /**
     * Hands a received fan-out job to the server for this node's own browsers.
     *
     * @param PeerClientFanoutDTO $frame Incoming client fan-out frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onClientFanout(PeerClientFanoutDTO $frame): void
    {
        $this->requireHandshaked('client fanout');
        $this->server->onClientFanoutReceived($this, $frame);
    }

    /**
     * Hands a received connection set to the server for this node's index to be rebuilt.
     *
     * @param PeerConnectionsSnapshotDTO $frame Incoming connection-snapshot frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onConnectionsSnapshot(PeerConnectionsSnapshotDTO $frame): void
    {
        $this->requireHandshaked('connections snapshot');
        $this->server->onConnectionsSnapshotReceived($this, $frame);
    }

    /**
     * Hands a received connection change to the server for this node's index to be updated.
     *
     * @param PeerConnectionsDeltaDTO $frame Incoming connection-delta frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onConnectionsDelta(PeerConnectionsDeltaDTO $frame): void
    {
        $this->requireHandshaked('connections delta');
        $this->server->onConnectionsDeltaReceived($this, $frame);
    }

    /**
     * Hands a received protected-mode enable request to the server for the leader to act on.
     *
     * @param PeerProtectedModeEnableDTO $frame Incoming protected-mode enable frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onProtectedModeEnable(PeerProtectedModeEnableDTO $frame): void
    {
        $this->requireHandshaked('protected-mode enable');
        $this->server->onProtectedModeEnableReceived($this, $frame);
    }

    /**
     * Hands a received protected-mode ready confirmation to the server for the initiator to act on.
     *
     * @param PeerProtectedModeReadyDTO $frame Incoming protected-mode ready frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onProtectedModeReady(PeerProtectedModeReadyDTO $frame): void
    {
        $this->requireHandshaked('protected-mode ready');
        $this->server->onProtectedModeReadyReceived($this, $frame);
    }

    /**
     * Hands a received protected-mode disable request to the server for the leader to act on.
     *
     * @param PeerProtectedModeDisableDTO $frame Incoming protected-mode disable frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onProtectedModeDisable(PeerProtectedModeDisableDTO $frame): void
    {
        $this->requireHandshaked('protected-mode disable');
        $this->server->onProtectedModeDisableReceived($this, $frame);
    }

    /**
     * Hands a received protected-mode quiesce order to the server for this follower to freeze.
     *
     * @param PeerProtectedModeQuiesceDTO $frame Incoming protected-mode quiesce frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onProtectedModeQuiesce(PeerProtectedModeQuiesceDTO $frame): void
    {
        $this->requireHandshaked('protected-mode quiesce');
        $this->server->onProtectedModeQuiesceReceived($this, $frame);
    }

    /**
     * Hands a received protected-mode quiesced report to the server for the leader to track.
     *
     * @param PeerProtectedModeQuiescedDTO $frame Incoming protected-mode quiesced frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onProtectedModeQuiesced(PeerProtectedModeQuiescedDTO $frame): void
    {
        $this->requireHandshaked('protected-mode quiesced');
        $this->server->onProtectedModeQuiescedReceived($this, $frame);
    }

    /**
     * Hands a received database re-hydrate announcement to the server for this node to re-read.
     *
     * @param PeerDbReHydrateDTO $frame Incoming database re-hydrate frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onDbReHydrate(PeerDbReHydrateDTO $frame): void
    {
        $this->requireHandshaked('db re-hydrate');
        $this->server->onDbReHydrateReceived($this, $frame);
    }

    /**
     * Hands a received database re-hydrated report to the server for the initiator to track.
     *
     * @param PeerDbReHydratedDTO $frame Incoming database re-hydrated frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onDbReHydrated(PeerDbReHydratedDTO $frame): void
    {
        $this->requireHandshaked('db re-hydrated');
        $this->server->onDbReHydratedReceived($this, $frame);
    }

    /**
     * Hands a received protected-mode lift order to the server for this follower to release.
     *
     * @param PeerProtectedModeLiftDTO $frame Incoming protected-mode lift frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onProtectedModeLift(PeerProtectedModeLiftDTO $frame): void
    {
        $this->requireHandshaked('protected-mode lift');
        $this->server->onProtectedModeLiftReceived($this, $frame);
    }

    /**
     * Hands a received protected-mode verify frame to the server for this node to act on.
     *
     * @param PeerProtectedModeVerifyDTO $frame Incoming protected-mode verify frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onProtectedModeVerify(PeerProtectedModeVerifyDTO $frame): void
    {
        $this->requireHandshaked('protected-mode verify');
        $this->server->onProtectedModeVerifyReceived($this, $frame);
    }

    /**
     * Hands a received protected-mode progress frame to the server for the leader to stamp.
     *
     * @param PeerProtectedModeProgressDTO $frame Incoming protected-mode progress frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onProtectedModeProgress(PeerProtectedModeProgressDTO $frame): void
    {
        $this->requireHandshaked('protected-mode progress');
        $this->server->onProtectedModeProgressReceived($this, $frame);
    }

    /**
     * Hands a received protected-mode pass frame to the server for this node to record.
     *
     * @param PeerProtectedModePassDTO $frame Incoming protected-mode pass frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onProtectedModePass(PeerProtectedModePassDTO $frame): void
    {
        $this->requireHandshaked('protected-mode pass');
        $this->server->onProtectedModePassReceived($this, $frame);
    }

    /**
     * Hands a received protected-mode refreeze frame to the server for this node to act on.
     *
     * @param PeerProtectedModeRefreezeDTO $frame Incoming protected-mode refreeze frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onProtectedModeRefreeze(PeerProtectedModeRefreezeDTO $frame): void
    {
        $this->requireHandshaked('protected-mode refreeze');
        $this->server->onProtectedModeRefreezeReceived($this, $frame);
    }

    /**
     * Answers a keepalive ping with a pong echoing its nonce.
     *
     * Liveness frames carry no membership meaning and may arrive at any point in a link's
     * life, so — unlike the gossip frames — they are not gated on the handshake: replying
     * keeps a transient race from tearing the link down. The refresh of the last-heard clock
     * already happened when the bytes were read.
     *
     * @param PeerPingDTO $frame Incoming ping frame
     */
    private function onPing(PeerPingDTO $frame): void
    {
        $this->sendFrame(new PeerPongDTO($frame->nonce));
    }

    /**
     * Accepts a keepalive pong; the proof of life is the inbound frame itself.
     *
     * Reading the frame already refreshed the last-heard clock, so no further work is needed;
     * like {@see onPing()} it is not gated on the handshake.
     */
    private function onPong(): void
    {
    }

    /**
     * Rejects a handshake frame whose protocol version is incompatible.
     *
     * @param PeerHandshakeDTO $frame Handshake frame to check
     * @throws PeerTransportException When the protocol version does not match
     */
    private function requireCompatible(PeerHandshakeDTO $frame): void
    {
        if (!PeerProtocol::isCompatible($frame->protocolVersion)) {
            throw new PeerTransportException(
                "Incompatible peer protocol version {$frame->protocolVersion}, expected " . PeerProtocol::VERSION,
            );
        }
    }

    /**
     * Rejects a gossip frame that arrives before the handshake has completed.
     *
     * @param string $frameKind Frame kind for the error message
     * @throws PeerTransportException When the link has no remote identity yet
     */
    private function requireHandshaked(string $frameKind): void
    {
        if ($this->remoteIdentity === null) {
            throw new PeerTransportException("Received a peer {$frameKind} before the handshake completed");
        }
    }
}

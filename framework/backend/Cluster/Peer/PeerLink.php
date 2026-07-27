<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\Peer\DTO\PeerAgentStatusDTO;
use Hilos\Cluster\Peer\DTO\PeerAnnounceDTO;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerHandshakeDTO;
use Hilos\Cluster\Peer\DTO\PeerHeartbeatDTO;
use Hilos\Cluster\Peer\DTO\PeerHelloDTO;
use Hilos\Cluster\Peer\DTO\PeerNodeLeavingDTO;
use Hilos\Cluster\Peer\DTO\PeerPingDTO;
use Hilos\Cluster\Peer\DTO\PeerPlaceAgentDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementQueryDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementReportDTO;
use Hilos\Cluster\Peer\DTO\PeerPongDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeDisableDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeEnableDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeReadyDTO;
use Hilos\Cluster\Peer\DTO\PeerRequestVoteDTO;
use Hilos\Cluster\Peer\DTO\PeerRosterDTO;
use Hilos\Cluster\Peer\DTO\PeerSignalDTO;
use Hilos\Cluster\Peer\DTO\PeerStopAgentDTO;
use Hilos\Cluster\Peer\DTO\PeerVoteReplyDTO;
use Hilos\Cluster\Peer\DTO\PeerWelcomeDTO;
use Hilos\Constants\EnvConstants;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Socket\Client\AbstractClient;
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

        $this->keepaliveIntervalSec = Hilos::$env->int(EnvConstants::CLUSTER_LINK_KEEPALIVE_INTERVAL_MS) / 1000.0;
        $this->linkTimeoutSec = Hilos::$env->int(EnvConstants::CLUSTER_LINK_TIMEOUT_MS) / 1000.0;
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
            $frame instanceof PeerSignalDTO => $this->onSignal($frame),
            $frame instanceof PeerProtectedModeEnableDTO => $this->onProtectedModeEnable($frame),
            $frame instanceof PeerProtectedModeReadyDTO => $this->onProtectedModeReady($frame),
            $frame instanceof PeerProtectedModeDisableDTO => $this->onProtectedModeDisable($frame),
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

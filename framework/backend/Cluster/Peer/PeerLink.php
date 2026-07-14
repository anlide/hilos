<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\Peer\DTO\PeerAnnounceDTO;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerHandshakeDTO;
use Hilos\Cluster\Peer\DTO\PeerHeartbeatDTO;
use Hilos\Cluster\Peer\DTO\PeerHelloDTO;
use Hilos\Cluster\Peer\DTO\PeerNodeLeavingDTO;
use Hilos\Cluster\Peer\DTO\PeerRequestVoteDTO;
use Hilos\Cluster\Peer\DTO\PeerRosterDTO;
use Hilos\Cluster\Peer\DTO\PeerVoteReplyDTO;
use Hilos\Cluster\Peer\DTO\PeerWelcomeDTO;
use Hilos\Environment\Exception\EnvException;
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
    /** @var PeerServer Owning peer server, driven for membership fan-out */
    private PeerServer $server;

    /** @var NodeIdentity Local node identity announced to the remote peer */
    private NodeIdentity $localIdentity;

    /** @var bool True when this side dialed out (sends hello); false when it accepted (replies welcome) */
    private bool $dialer;

    /** @var ?NodeIdentity Remote node identity, known once the handshake completes */
    private ?NodeIdentity $remoteIdentity = null;

    /**
     * @param resource|object $socket Connected peer socket
     * @param PeerServer $server Owning peer server for membership fan-out
     * @param NodeIdentity $localIdentity Local node identity to announce
     * @param bool $dialer True for the dialing side, false for the accepting side
     * @throws EnvException When the socket read buffer env value is missing or invalid
     */
    public function __construct($socket, PeerServer $server, NodeIdentity $localIdentity, bool $dialer)
    {
        parent::__construct($socket);

        $this->server = $server;
        $this->localIdentity = $localIdentity;
        $this->dialer = $dialer;
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
     * No periodic work: handshake timeout and heartbeat belong to later slices.
     */
    public function onTick(): void
    {
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

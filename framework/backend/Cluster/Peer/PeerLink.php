<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer;

use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerHelloDTO;
use Hilos\Cluster\Peer\DTO\PeerWelcomeDTO;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Socket\Client\AbstractClient;
use Hilos\Utils\Logger;

/**
 * One framed connection to a remote cluster node, either dialed or accepted.
 *
 * The dialing side opens the connection and sends {@see PeerHelloDTO} first; the
 * accepting side answers with {@see PeerWelcomeDTO}. Both then hold the remote
 * node identity learned from that handshake. Frames are newline-delimited JSON.
 * A malformed frame or a rejected handshake closes the link rather than
 * propagating out of the daemon loop. Heartbeat and reconnection are later
 * slices; this link only establishes identity.
 */
final class PeerLink extends AbstractClient
{
    /** @var NodeIdentity Local node identity announced to the remote peer */
    private NodeIdentity $localIdentity;

    /** @var bool True when this side dialed out (sends hello); false when it accepted (replies welcome) */
    private bool $dialer;

    /** @var ?NodeIdentity Remote node identity, known once the handshake completes */
    private ?NodeIdentity $remoteIdentity = null;

    /**
     * @param resource|object $socket Connected peer socket
     * @param NodeIdentity $localIdentity Local node identity to announce
     * @param bool $dialer True for the dialing side, false for the accepting side
     * @throws EnvException When the socket read buffer env value is missing or invalid
     */
    public function __construct($socket, NodeIdentity $localIdentity, bool $dialer)
    {
        parent::__construct($socket);

        $this->localIdentity = $localIdentity;
        $this->dialer = $dialer;
    }

    /**
     * Queues the opening hello frame; called by the server after a dial connects.
     */
    public function startHandshake(): void
    {
        $this->writeBuffer .= $this->handshakeFrame(new PeerHelloDTO(
            PeerProtocol::VERSION,
            $this->localIdentity->nodeId,
            $this->localIdentity->role,
            $this->localIdentity->capabilities,
        ));
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
     * Parses complete peer frames and dispatches them, closing on a bad frame.
     */
    protected function processReadBuffer(): void
    {
        while ($this->readBuffer !== '') {
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
     * Logs the peer leaving when a handshaked link closes.
     */
    protected function onClose(): void
    {
        if ($this->remoteIdentity === null) {
            return;
        }

        try {
            Hilos::$cluster?->registry()->markOffline($this->remoteIdentity->nodeId, microtime(true));
        } catch (\Throwable $e) {
            Logger::warning("Cluster registry update failed on peer leave: {$e->getMessage()}");
        }

        Logger::info("Peer left: {$this->remoteIdentity->nodeId}");
    }

    /**
     * Validates the protocol version and routes the frame to its handler.
     *
     * @param PeerDTO $frame Parsed peer frame
     * @throws PeerTransportException When the protocol version or frame direction is invalid
     */
    private function handleFrame(PeerDTO $frame): void
    {
        if (!PeerProtocol::isCompatible($frame->protocolVersion)) {
            throw new PeerTransportException(
                "Incompatible peer protocol version {$frame->protocolVersion}, expected " . PeerProtocol::VERSION,
            );
        }

        if ($frame instanceof PeerHelloDTO) {
            $this->onHello($frame);
            return;
        }

        if ($frame instanceof PeerWelcomeDTO) {
            $this->onWelcome($frame);
            return;
        }

        throw new PeerTransportException('Unexpected peer frame type: ' . $frame->getType());
    }

    /**
     * Accepting side: records the remote identity and answers with a welcome.
     *
     * @param PeerHelloDTO $hello Incoming hello frame
     * @throws PeerTransportException When a hello arrives on the dialing side
     */
    private function onHello(PeerHelloDTO $hello): void
    {
        if ($this->dialer) {
            throw new PeerTransportException('Unexpected hello on the dialing side of a peer link');
        }

        $remote = NodeIdentity::of($hello->nodeId, $hello->role, $hello->capabilities);
        $this->remoteIdentity = $remote;
        $this->writeBuffer .= $this->handshakeFrame(new PeerWelcomeDTO(
            PeerProtocol::VERSION,
            $this->localIdentity->nodeId,
            $this->localIdentity->role,
            $this->localIdentity->capabilities,
        ));
        $this->registerPeer($remote);
        Logger::info("Peer joined: {$remote->nodeId} role={$remote->role->value}");
    }

    /**
     * Dialing side: records the remote identity from the welcome reply.
     *
     * @param PeerWelcomeDTO $welcome Incoming welcome frame
     * @throws PeerTransportException When a welcome arrives on the accepting side
     */
    private function onWelcome(PeerWelcomeDTO $welcome): void
    {
        if (!$this->dialer) {
            throw new PeerTransportException('Unexpected welcome on the accepting side of a peer link');
        }

        $remote = NodeIdentity::of($welcome->nodeId, $welcome->role, $welcome->capabilities);
        $this->remoteIdentity = $remote;
        $this->registerPeer($remote);
        Logger::info("Peer handshake complete with {$remote->nodeId} role={$remote->role->value}");
    }

    /**
     * Records the handshaked peer into the master cluster registry.
     *
     * A registry hiccup must not tear down the link or the daemon loop, so any
     * failure is logged and swallowed rather than propagated.
     *
     * @param NodeIdentity $remote Remote node identity just learned
     */
    private function registerPeer(NodeIdentity $remote): void
    {
        try {
            Hilos::$cluster?->registry()->recordPeer($remote, microtime(true));
        } catch (\Throwable $e) {
            Logger::warning("Cluster registry update failed on peer join: {$e->getMessage()}");
        }
    }

    /**
     * Renders a handshake DTO as one newline-delimited JSON frame.
     *
     * @param PeerDTO $dto Handshake frame to serialize
     * @return string Newline-terminated JSON frame
     */
    private function handshakeFrame(PeerDTO $dto): string
    {
        return $dto->toJson() . "\n";
    }
}

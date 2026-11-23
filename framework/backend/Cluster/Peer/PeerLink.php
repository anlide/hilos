<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer;

use Hilos\Core\Daemon\SignalDispatch;
use Closure;
use Generator;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\EventLoopTimer;
use Hilos\Core\EventLoop\Exception\EventLoopException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Cluster\Exception\PeerTransportException;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\Peer\DTO\PeerAgentRestartDTO;
use Hilos\Cluster\Peer\DTO\PeerAgentStatusDTO;
use Hilos\Cluster\Peer\DTO\PeerAnnounceDTO;
use Hilos\Cluster\Peer\DTO\PeerClientFanoutDTO;
use Hilos\Cluster\Peer\DTO\PeerClientSignalDTO;
use Hilos\Cluster\Peer\DTO\PeerClockProbeDTO;
use Hilos\Cluster\Peer\DTO\PeerClockReplyDTO;
use Hilos\Cluster\Peer\DTO\PeerCommandReplyDTO;
use Hilos\Cluster\Peer\DTO\PeerConnectionDropDTO;
use Hilos\Cluster\Peer\DTO\PeerConnectionsDeltaDTO;
use Hilos\Cluster\Peer\DTO\PeerConnectionsSnapshotDTO;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerDrainDTO;
use Hilos\Cluster\Peer\DTO\PeerHandshakeDTO;
use Hilos\Cluster\Peer\DTO\PeerGroupLeaveAllDTO;
use Hilos\Cluster\Peer\DTO\PeerHeartbeatDTO;
use Hilos\Cluster\Peer\DTO\PeerHelloDTO;
use Hilos\Cluster\Peer\DTO\PeerHttpReplyDTO;
use Hilos\Cluster\Peer\DTO\PeerNodeLeavingDTO;
use Hilos\Cluster\Peer\DTO\PeerPageAccessReassessDTO;
use Hilos\Cluster\Peer\DTO\PeerPingDTO;
use Hilos\Cluster\Peer\DTO\PeerPlaceAgentDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementQueryDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementReleaseDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementRequestDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementReportDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementVerdictDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementViewDTO;
use Hilos\Cluster\Peer\DTO\PeerPongDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeCircleDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeDisableDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeEnableDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeLiftDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeNodeReportDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModePassDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeAdmitDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeProgressDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeSyncDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeSyncedDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeQuiesceDTO;
use Hilos\Cluster\Peer\DTO\PeerDbReHydratedDTO;
use Hilos\Cluster\Peer\DTO\PeerDbReHydrateDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeQuiescedDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeReadyDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeRefusedDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeRefreezeDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeSettledDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeVerifyDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeWindowDTO;
use Hilos\Cluster\Peer\DTO\PeerRequestVoteDTO;
use Hilos\Cluster\Peer\DTO\PeerReadyAckDTO;
use Hilos\Cluster\Peer\DTO\PeerReadyDTO;
use Hilos\Cluster\Peer\DTO\PeerRosterDTO;
use Hilos\Cluster\Peer\DTO\PeerRtClaimRefusedDTO;
use Hilos\Cluster\Peer\DTO\PeerRtClaimsDTO;
use Hilos\Cluster\Peer\DTO\PeerRtClaimsQueryDTO;
use Hilos\Cluster\Peer\DTO\PeerRtReplicaOfferDTO;
use Hilos\Cluster\Peer\DTO\PeerRtSnapshotQueryDTO;
use Hilos\Cluster\Peer\DTO\PeerRtSnapshotRequestDTO;
use Hilos\Cluster\Peer\DTO\PeerDbSyncDTO;
use Hilos\Cluster\Peer\DTO\PeerRtSyncStreamDTO;
use Hilos\Cluster\Peer\DTO\PeerSignalDTO;
use Hilos\Cluster\Peer\DTO\PeerUploadReserveDTO;
use Hilos\Cluster\Peer\DTO\PeerUploadGrantedDTO;
use Hilos\Cluster\Peer\DTO\PeerUploadAcceptedDTO;
use Hilos\Cluster\Peer\DTO\PeerUploadReleaseDTO;
use Hilos\Cluster\Peer\DTO\PeerSourceInterestAnsweredDTO;
use Hilos\Cluster\Peer\DTO\PeerSourceInterestDTO;
use Hilos\Cluster\Peer\DTO\PeerStopAgentDTO;
use Hilos\Cluster\Peer\DTO\PeerVoteReplyDTO;
use Hilos\Cluster\Peer\DTO\PeerWelcomeDTO;
use Hilos\Constants\AgentConstants;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\RtStaleness;
use Hilos\Runtime\Stream\RtSyncStreamChannel;
use Hilos\Runtime\Stream\RtSyncStreamFrame;
use Hilos\Runtime\Stream\RtSyncStreamOperation;
use Hilos\Runtime\Stream\RtSyncStreamSnapshot;
use Hilos\Socket\Client\AbstractClient;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Exception\SocketTlsHandshakeException;
use Hilos\Socket\SocketException;
use Hilos\Socket\Transport\SocketTransportInterface;
use Hilos\TruthSource\RtReplicaConfirmation;
use Hilos\Utils\Logger;
use LogicException;
use SplQueue;
use Throwable;
use WeakReference;

/**
 * One framed connection to a remote cluster node, either dialed or accepted.
 *
 * The dialing side opens the connection and sends {@see PeerHelloDTO} first; the
 * accepting side answers with {@see PeerWelcomeDTO}. A ready/ack exchange then
 * selects the working route; only after that may the two sides exchange membership gossip
 * ({@see PeerRosterDTO}, {@see PeerAnnounceDTO}). This link only parses and
 * frames; the registry updates and the fan-out to other peers are owned by the
 * {@see PeerServer}. A malformed frame or a rejected handshake closes the link
 * rather than propagating out of the daemon loop.
 *
 * The link speaks over a transport that vouches for the name in the peer's certificate
 * (HIL-1034), and the node id a hello or a welcome introduces must be exactly that name:
 * otherwise the handshake is refused before the link remembers the peer or tells the server
 * about it. What the frames carry after the handshake is not checked against it - a holder of a
 * valid node certificate is a member of the cluster.
 *
 * The markers a hello or a welcome carries are checked at the same place, right after the name
 * (HIL-1206): a peer that reads another database than this node is refused the same way, on both
 * ends of the link, by the rule {@see PeerMarkers::refusalFor()} states.
 *
 * A replacement link parses nothing it reads until the old link's inbound to the same node ends - its drain
 * marker read, or the old link closing - so the node handles the peer's frames in the order they were sent,
 * as on one connection ({@see self::holdIncomingBehind()}).
 */
final class PeerLink extends AbstractClient
{
    /** @var int Outbound-buffer backpressure cap: a peer that stops draining is dropped, not buffered to OOM */
    private const int MAX_WRITE_BUFFER_BYTES = 8 * 1024 * 1024;

    /** @var PeerServer Owning peer server, driven for membership fan-out */
    private PeerServer $server;
    /** The ordinary RT stream of this link instance */
    private ?RtSyncStreamChannel $rtSyncStream = null;
    /**
     * @var array<string, true> RT collections the linked node was asked one more snapshot of, until a snapshot of the
     *      collection reaches the stream's host ({@see self::requestRtSnapshot()}); process-local like the stream, and
     *      ending with this link instance as the stream does
     */
    private array $rtSnapshotsAsked = [];
    /**
     * @var array<string, true> RT collections whose last snapshot from the linked node a live change here canceled,
     *      until one of them is published ({@see self::requestRtSnapshotAgain()}); ending with this link instance
     */
    private array $rtSnapshotsContested = [];
    /**
     * @var array<string, int> Frames naming each agent that wait for their turn behind this link's RT stream, until
     *      written; transferred with their callbacks before the successor stream can wake
     */
    private array $waitingInTurn = [];
    /** Placement announcements that a following Stop must not overtake. */
    private int $waitingPlacementTurns = 0;
    /** Weak endpoint route shared by waiting callbacks across successive replacements */
    private readonly PeerFrameRoute $frameRoute;

    /** @var NodeIdentity Local node identity announced to the remote peer */
    private NodeIdentity $localIdentity;

    /** @var bool True when this side dialed out (sends hello); false when it accepted (replies welcome) */
    private bool $dialer;

    /** @var ?NodeIdentity Remote node identity on an active or draining link */
    private ?NodeIdentity $remoteIdentity = null;

    /** @var ?NodeIdentity Certified identity of a link awaiting the ready verdict */
    private ?NodeIdentity $candidateIdentity = null;

    /** @var bool Whether this dialer has sent ready and awaits its ack */
    private bool $readySent = false;

    /** @var bool Whether this acceptor has processed ready */
    private bool $readyReceived = false;

    /** @var bool Whether this old link may receive the peer's drain marker */
    private bool $peerDrainAllowed = false;

    /** @var bool Whether this old link has queued its drain marker */
    private bool $draining = false;

    /** @var bool Whether the peer's drain marker arrived on this old link */
    private bool $peerDrained = false;

    /** @var bool Whether this link's inbound frames wait unparsed for the end of the old link's inbound to the same node */
    private bool $heldBehindPredecessor = false;

    /** @var ?WeakReference<self> Successor whose inbound frames wait for the end of this link's inbound; held weakly */
    private ?WeakReference $heldSuccessor = null;

    /** @var SocketTransportInterface Transport this link was created with, asked for the peer's certificate name */
    private SocketTransportInterface $transport;

    /**
     * @var bool Whether this dialing link accepted a welcome.
     *
     * Not the same question as {@see $remoteIdentity}: a link that lost the duplicate collapse is
     * cleared of its identity after it was welcomed, and must not read as closed before welcome.
     */
    private bool $welcomed = false;

    /**
     * @var bool Whether this side dropped the link on its own - after a silence timeout, or on a frame it refused.
     *
     * A dialed link this side dropped before a welcome was not closed by the peer, and the peer's
     * log names no refusal for it.
     */
    private bool $droppedHere = false;

    /** @var float Seconds of silence before a keepalive ping is sent */
    private float $keepaliveIntervalSec;

    /** @var float Seconds of silence after which the link is closed as dead */
    private float $linkTimeoutSec;

    /** @var float Monotonic seconds when new inbound application bytes last arrived */
    private float $lastHeardAt;

    /** @var float Monotonic seconds when the last keepalive ping was queued */
    private float $lastPingAt = 0.0;

    /** This connection's silence/quiet deadline on its actual transport loop. */
    private ?EventLoopTimer $livenessTimer = null;

    private int $livenessGeneration = 0;

    /** Only a due notification releases native-bound liveness work. */
    private bool $livenessReady = false;

    private int $uploadQueuedPosition = 0;

    private int $uploadRemovedPosition = 0;

    /** @var SplQueue<PeerOutboundSegment> Serialized FIFO spans, including the native buffer */
    private SplQueue $outboundSegments;

    /** @var SplQueue<PeerOutboundSegment> Spans waiting for native transport room */
    private SplQueue $stagedOutbound;

    private int $ordinaryOutboundBytes = 0;

    private int $nativeAppendedBytes = 0;

    /**
     * @param resource|object $socket Connected peer socket
     * @param PeerServer $server Owning peer server for membership fan-out
     * @param NodeIdentity $localIdentity Local node identity to announce
     * @param bool $dialer True for the dialing side, false for the accepting side
     * @param SocketTransportInterface $transport Transport over the socket; the peer channel has no default, and a
     *                                            transport that vouches for nobody makes every handshake refused
     * @throws EnvException When the socket read buffer or keepalive env values are missing or invalid
     */
    public function __construct($socket, PeerServer $server, NodeIdentity $localIdentity, bool $dialer, SocketTransportInterface $transport)
    {
        parent::__construct($socket, $transport);

        $this->transport = $transport;
        $this->server = $server;
        $this->localIdentity = $localIdentity;
        $this->dialer = $dialer;
        $this->frameRoute = new PeerFrameRoute($this);

        $this->keepaliveIntervalSec = Hilos::$env[EnvConstants::CLUSTER_LINK_KEEPALIVE_INTERVAL_MS]->int() / TimeConstants::MS_PER_SECOND;
        $this->linkTimeoutSec = Hilos::$env[EnvConstants::CLUSTER_LINK_TIMEOUT_MS]->int() / TimeConstants::MS_PER_SECOND;
        $this->maxWriteBufferBytes = self::MAX_WRITE_BUFFER_BYTES;
        $this->lastHeardAt = hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND;
        $this->outboundSegments = new SplQueue();
        $this->stagedOutbound = new SplQueue();
    }

    /**
     * Whether the linked node acts on a frame trusting that the RT writes this node streamed to it
     * before the frame are already applied there - so the frame goes in its turn among the stream's
     * operations ({@see RtSyncStreamChannel::writeInTurn()}). A signal to an agent, but not the
     * budgeted portion of an upload, which keeps its own accounting. The status of an agent of this
     * node and the report of what this node hosts: told an agent stopped here, the leader places it
     * anew. The claims of this node's agents: the leader drops a claim this node no longer reports,
     * and another node's claim to the same rows then stands. A command's reply: its caller acts on
     * the outcome. An access re-decision of pages, which reads who is behind a connection from the
     * RT copy. An order to place an agent: the linked node starts the new instance, which reads and
     * writes the rows the old one wrote when it ran on this node. A placement query: absence can
     * judge an order only when the query follows that Place or Stop in the stream. A placement
     * release follows preceding commands and queries, so they cannot restore the released authority.
     * The answer to the linked node's announcement of what it reads: the linked node takes it as the word that every
     * snapshot owed for that announcement is ahead of it. Every other frame - keepalive,
     * consensus, membership, the drain marker, uploads, snapshots and their queries, DB sync, answers
     * to browsers and HTTP, protected mode - goes at once: none of them is read against a write sent
     * before it. A stop or a restart of an agent goes at once too, unless a frame naming the same agent
     * still waits for its turn ({@see self::queueFrame()}). A Stop also follows pending placement queries and releases.
     *
     * @param PeerDTO $frame Frame to send
     * @return bool Whether it goes in its turn behind the writes streamed before it
     */
    public static function readsBehindRtWrites(PeerDTO $frame): bool
    {
        if ($frame instanceof PeerSignalDTO) {
            return $frame->uploadReservation === null;
        }

        return $frame instanceof PeerAgentStatusDTO || $frame instanceof PeerPlacementReportDTO || $frame instanceof PeerRtClaimsDTO
            || $frame instanceof PeerCommandReplyDTO || $frame instanceof PeerPageAccessReassessDTO
            || $frame instanceof PeerRtClaimRefusedDTO || $frame instanceof PeerPlacementVerdictDTO
            || $frame instanceof PeerPlaceAgentDTO || $frame instanceof PeerPlacementQueryDTO || $frame instanceof PeerPlacementReleaseDTO
            || $frame instanceof PeerSourceInterestAnsweredDTO;
    }

    /**
     * Names the agent a frame of this link is about, where its order behind another frame of the same
     * agent matters: a signal to it, but not the budgeted portion of an upload, which keeps its own count
     * of the link's bytes; its status; an order to place, stop or restart it. A forwarded command reply
     * names none: it answers a console's caller.
     *
     * @param PeerDTO $frame Frame to send
     * @return ?string Id of the agent, or null when the frame names none
     */
    private static function agentIdOf(PeerDTO $frame): ?string
    {
        if (($frame instanceof PeerSignalDTO && $frame->uploadReservation === null) || $frame instanceof PeerAgentStatusDTO
            || $frame instanceof PeerPlaceAgentDTO || $frame instanceof PeerStopAgentDTO || $frame instanceof PeerAgentRestartDTO
            || $frame instanceof PeerRtClaimRefusedDTO || $frame instanceof PeerPlacementVerdictDTO) {
            return $frame->agentIndex !== null ? $frame->agentType . AgentConstants::ID_SEPARATOR . $frame->agentIndex : $frame->agentType;
        }

        return null;
    }

    /**
     * Bind silence before either handshake, retaining the constructor's original age.
     * The timer only cues the existing guarded read/tick/write portion.
     *
     * @param EventLoop $loop Actual transport loop
     * @param Closure(): void $wake Guarded client portion
     * @throws SocketTlsHandshakeException When a live TLS session changes native base
     * @throws EventLoopException When the liveness deadline cannot be armed
     */
    public function attachEventLoop(EventLoop $loop, Closure $wake): void
    {
        parent::attachEventLoop($loop, $wake);
        if ($this->socket === null || $this->shouldClose) {
            return;
        }

        $generation = $this->livenessGeneration;
        $weak = WeakReference::create($this);
        $this->livenessTimer = $loop->createTimer(static function () use ($weak, $generation): void {
            $link = $weak->get();
            if ($link === null || $link->livenessGeneration !== $generation
                || $link->livenessTimer === null || $link->shouldClose || $link->socket === null) {
                return;
            }
            $link->livenessReady = true;
            $link->markReady();
        });
        $this->armLiveness();
    }

    /** Retire silence work before transport detach or close, including an activated old cue. */
    public function detachEventLoop(): void
    {
        ++$this->livenessGeneration;
        $this->livenessTimer?->close();
        $this->livenessTimer = null;
        $this->livenessReady = false;
        parent::detachEventLoop();
    }

    /**
     * Queues the opening hello frame; called by the server after a dial connects.
     */
    public function startHandshake(): void
    {
        $this->sendFrame(new PeerHelloDTO(
            PeerProtocol::VERSION,
            $this->localIdentity->nodeId,
            $this->server->localNodeName(),
            $this->localIdentity->role,
            $this->localIdentity->capabilities,
            $this->server->localMarkers()->values,
            $this->localIdentity->address,
        ));
    }

    /**
     * Queues one peer frame for delivery to this link.
     *
     * A signal to an agent goes in its turn among the operations of this link's RT stream, once the
     * stream is open ({@see RtSyncStreamChannel::writeInTurn()}): an RT write this node streamed to
     * the linked node before it is out whole first, so the linked node takes the signal behind the
     * write's final part. There the part's arrival queues the master's read of it, and the signal is
     * queued for the agent behind that read, in the same order ({@see DaemonManager::deliverSignalToAgent()}).
     * The status of an agent of this node ({@see PeerAgentStatusDTO}) goes the same way: told the
     * agent stopped here or failed to start, the leader places it anew, so the status must not reach
     * the leader ahead of a write of this node still waiting for its part's credit; so do the report of
     * what this node hosts, the claims of its agents, a command's reply and an order to place an agent
     * ({@see self::readsBehindRtWrites()}). A stop or a restart of an agent a waiting frame names goes
     * behind that frame, so the linked node never stops an agent ahead of a signal sent to it before
     * ({@see self::queueFrame()}). A frame packed once by its fan-out chooses the same way
     * ({@see self::sendPackedFrame()}). Every other frame - keepalive, consensus, the drain marker, the
     * budgeted portions of an upload, DB sync - is queued at once: none of them waits for a part's
     * credit. Replacement transfers waiting frames; a final drain releases them.
     *
     * @param PeerDTO $frame Frame to send
     */
    public function sendFrame(PeerDTO $frame): void
    {
        if ($this->draining && !$frame instanceof PeerDrainDTO) {
            if ($frame instanceof PeerUploadReserveDTO || $frame instanceof PeerUploadGrantedDTO
                || $frame instanceof PeerUploadAcceptedDTO || $frame instanceof PeerUploadReleaseDTO
                || ($frame instanceof PeerSignalDTO && $frame->uploadReservation !== null)) {
                return;
            }
            $nodeId = $this->remoteIdentity?->nodeId;
            if ($nodeId !== null) {
                $this->server->sendToNode($nodeId, $frame);
            }
            return;
        }

        $this->queueFrame($frame, self::orderedFrameJson($frame, $frame->toJson()));
    }

    /**
     * Queues one already-packed peer frame for delivery to this link.
     *
     * The door a fan-out uses: one frame goes to every link that passes the filter, so the
     * {@see PeerServer} packs it once and hands each link the ready string. Framing stays
     * here and only the JSON arrives packed — a caller knows what it is sending, not how
     * this channel delimits it, and moving the newline out would put the protocol's
     * boundary in every fan-out that ever gets written.
     *
     * @param string $frameJson JSON of one peer frame, without the newline delimiter
     */
    public function sendEncodedFrame(string $frameJson): void
    {
        $this->queueOutbound($frameJson, false);
    }

    /**
     * Queues one frame its fan-out packed once for every link it reaches, choosing its turn as a frame
     * of this link's own does ({@see self::sendFrame()}): a frame the linked node reads behind the
     * writes before it ({@see self::readsBehindRtWrites()}) goes in its turn among the operations of
     * this link's RT stream, once the stream is open. An access re-decision of pages
     * ({@see PeerPageAccessReassessDTO}): the linked node's workers re-decide against their RT copy -
     * who is behind a connection is a row this node streams there - so a write streamed before the
     * re-decision is out whole first, and the linked node queues the re-decision for its workers behind
     * its read of that write ({@see DaemonManager::deliverPageAccessReassess()}). The claims of this
     * node's agents, which the leader folds behind its read the same way
     * ({@see DaemonManager::applyRemoteRtClaims()}), and the report of what this node hosts, broadcast
     * once its placed work is fenced. Replacement follows the current route; a final drain queues it at once.
     *
     * @param PeerDTO $frame Frame the fan-out sends
     * @param string $frameJson The same frame packed, without the newline delimiter
     */
    public function sendPackedFrame(PeerDTO $frame, string $frameJson): void
    {
        if ($this->frameRoute->successor !== null && !$frame instanceof PeerDrainDTO) {
            $this->frameRoute->current()?->sendPackedFrame($frame, $frameJson);

            return;
        }
        $frameJson = self::orderedFrameJson($frame, $frameJson);
        if ($this->draining) {
            $this->queueOutbound($frameJson, false);

            return;
        }
        $this->queueFrame($frame, $frameJson);
    }

    /**
     * @param PeerDTO $frame Original frame, with any local outbound prefix
     * @param string $frameJson Packed frame, without the newline delimiter
     * @return string Packed frame with any prefix carried in the same transport turn
     */
    private static function orderedFrameJson(PeerDTO $frame, string $frameJson): string
    {
        if (!$frame instanceof PeerPlacementQueryDTO || $frame->orderedPrefix === []) {
            return $frameJson;
        }
        $prefix = implode("\n", array_map(static fn(PeerDTO $item): string => $item->toJson(), $frame->orderedPrefix));

        return $prefix . "\n" . $frameJson;
    }

    /**
     * Queues one frame at once, or in its turn among the operations of this link's RT stream once the
     * stream is open ({@see RtSyncStreamChannel::writeInTurn()}): in its turn when the linked node reads
     * it behind the writes streamed before it ({@see self::readsBehindRtWrites()}), and when it names an
     * agent a frame still waiting for its turn names ({@see self::agentIdOf()}) - then it goes behind that
     * frame, as on a worker's link ({@see WorkerClient::sendInTurn()}). The agent counts as waited for
     * until the frame naming it is written; the stream writes every frame waiting in it in the end,
     * behind an operation dropped or refused as well. Replacement moves their endpoint and agent counts
     * before the stream wakes; answers queued in the old generation are dropped on either kind of drain.
     *
     * @param PeerDTO $frame Frame to send
     * @param string $frameJson The same frame packed, without the newline delimiter
     */
    private function queueFrame(PeerDTO $frame, string $frameJson): void
    {
        $agentId = self::agentIdOf($frame);
        $answers = $frame instanceof PeerSourceInterestAnsweredDTO;
        $placementTurn = $frame instanceof PeerPlacementQueryDTO || $frame instanceof PeerPlacementReleaseDTO;
        $inTurn = self::readsBehindRtWrites($frame) || ($agentId !== null && isset($this->waitingInTurn[$agentId]))
            || ($frame instanceof PeerStopAgentDTO && $this->waitingPlacementTurns > 0);
        if ($this->rtSyncStream === null || !$inTurn) {
            $this->queueOutbound($frameJson, $frame instanceof PeerSignalDTO && $frame->uploadReservation !== null);

            return;
        }
        if ($agentId !== null) {
            $this->waitingInTurn[$agentId] = ($this->waitingInTurn[$agentId] ?? 0) + 1;
        }
        if ($placementTurn) {
            ++$this->waitingPlacementTurns;
        }
        $route = $this->frameRoute;
        $this->rtSyncStream->writeInTurn(static function () use ($route, $frameJson, $agentId, $answers, $placementTurn): void {
            $owner = $route->current();
            if ($owner === null) {
                return;
            }
            if ($agentId !== null && --$owner->waitingInTurn[$agentId] === 0) {
                unset($owner->waitingInTurn[$agentId]);
            }
            if ($placementTurn) {
                --$owner->waitingPlacementTurns;
            }
            if ($answers && $route->dropsAnswers) {
                $owner->oweHandOver();

                return;
            }
            $owner->queueOutbound($frameJson, false);
        });
    }

    /**
     * Tells the server this link loses on its drain what the linked node was to read: the node's repeat
     * of its announcement on the replacement link is answered behind a hand-over of everything it names
     * ({@see PeerServer::noteRtHandOverOwed()}).
     */
    private function oweHandOver(): void
    {
        $nodeId = $this->remoteIdentity?->nodeId;
        if ($nodeId !== null) {
            $this->server->noteRtHandOverOwed($nodeId);
        }
    }

    /** @return int Absolute FIFO end, including staged bytes that have not reached the native buffer */
    public function uploadOutboundPosition(): int
    {
        return $this->uploadQueuedPosition;
    }

    /**
     * @param int $position End of a particular serialized portion
     * @return bool Whether transport no longer retains any bytes of that portion
     */
    public function hasRemovedUploadThrough(int $position): bool
    {
        return $this->uploadRemovedPosition >= $position;
    }

    /**
     * Move staged bytes only as transport room opens; count only actual native removal.
     *
     * @throws SocketException When transport writing fails
     * @throws HilosException When a transport callback cannot complete
     */
    public function write(): void
    {
        $this->fillOutboundBuffer();
        $beforeBytes = strlen($this->writeBuffer);
        $beforeAppendedBytes = $this->nativeAppendedBytes;
        $closeWhenDrained = $this->closeWhenOutputDrained;
        $this->closeWhenOutputDrained = false;
        try {
            parent::write();
        } finally {
            $this->recordOutboundRemoval($beforeBytes + $this->nativeAppendedBytes - $beforeAppendedBytes - strlen($this->writeBuffer));
            if ($this->shouldClose) {
                $this->discardOutbound();
            }
            $this->closeWhenOutputDrained = $closeWhenDrained;
            if ($closeWhenDrained && !$this->hasPendingWrite()) {
                $this->closeWhenOutputDrained = false;
                $this->markShouldClose();
            }
            if (!$this->shouldClose && $this->writeBuffer === '' && !$this->stagedOutbound->isEmpty()) {
                $this->markReady();
            }
        }
    }

    /** @return bool Whether native or staged serialized bytes still need transport */
    public function hasPendingWrite(): bool
    {
        return parent::hasPendingWrite() || !$this->stagedOutbound->isEmpty();
    }

    /** Close after the entire FIFO, including deferred upload spans, has drained. */
    public function closeOnceWritten(): void
    {
        if (!$this->hasPendingWrite()) {
            $this->markShouldClose();
            return;
        }
        $this->closeWhenOutputDrained = true;
        $this->markReady();
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

    /** @return ?NodeIdentity Certified identity awaiting the ready verdict */
    public function candidateIdentity(): ?NodeIdentity
    {
        return $this->candidateIdentity;
    }

    /** @return ?string Name in the verified peer certificate, if TLS has completed */
    public function certifiedPeerName(): ?string
    {
        return $this->transport->verifiedPeerName();
    }

    /** @return bool Whether the old incarnation is draining before replacement */
    public function isDraining(): bool
    {
        return $this->draining;
    }

    /**
     * @param NodeIdentity $remote Certified identity of the new active link
     * @throws EventLoopException When the newly eligible ping deadline cannot be armed
     */
    public function activate(NodeIdentity $remote): void
    {
        $this->remoteIdentity = $remote;
        $this->candidateIdentity = null;
        $this->armLiveness();
    }

    /** Allows the old route to receive a drain before the dialer's ack arrives. */
    public function allowPeerDrain(): void
    {
        $this->peerDrainAllowed = true;
    }

    /** Ends an abandoned replacement attempt without changing the working route. */
    public function cancelPeerDrain(): void
    {
        if (!$this->draining) {
            $this->peerDrainAllowed = false;
            $this->peerDrained = false;
        }
    }

    /**
     * Transfers unfinished ordinary writes, frame routes and answer waits before the old transport's
     * drain marker. Snapshots are canceled and old interest answers dropped; their repeat renews the
     * base through the existing hand-over debt. Ordinary writes retain that debt as insurance.
     * Without a successor, waiting frames are released and answer waits end. A ready callback's
     * failure reaches the caller after the other ready callbacks, marker and liveness cleanup.
     * This does not hold the successor's inbound: the caller does, just before, with {@see self::holdIncomingBehind()},
     * so the successor parses nothing until this link's inbound ends - the peer's marker or this link's close.
     *
     * @param ?self $successor Replacement with an unused sending direction, or null for a final drain
     * @throws EventLoopException When the remaining silence deadline cannot be armed
     * @throws LogicException When the successor is this link or its sending direction is already used
     * @throws InvalidFormatException Never: the stream transfers valid parts and answers
     * @throws InvalidArgumentException Never: the successor's stream allowance is valid
     */
    public function beginDrain(?self $successor = null): void
    {
        if ($successor === $this) {
            throw new LogicException('A peer link cannot replace itself');
        }
        if ($this->draining) {
            return;
        }
        if ($this->rtSyncStream?->isWriting() === true) {
            $this->oweHandOver();
        }
        $this->frameRoute->dropsAnswers = true;
        $this->peerDrainAllowed = true;
        $this->draining = true;
        $failure = null;
        if ($successor !== null) {
            $this->frameRoute->successor = $successor->frameRoute;
            foreach ($this->waitingInTurn as $agentId => $count) {
                $successor->waitingInTurn[$agentId] = ($successor->waitingInTurn[$agentId] ?? 0) + $count;
            }
            $this->waitingInTurn = [];
            $successor->waitingPlacementTurns += $this->waitingPlacementTurns;
            $this->waitingPlacementTurns = 0;
        }
        if ($successor !== null && $this->rtSyncStream?->isWriting() === true) {
            try {
                $this->rtSyncStream->moveUnsentTo($successor->rtSyncStream(), function (RtSyncStreamSnapshot $snapshot): void {
                    $this->oweHandOver();
                });
            } catch (Throwable $raised) {
                $failure = $raised;
            }
        } else {
            // With no unfinished writes, old answer obligations end without opening a successor stream.
            try {
                $this->rtSyncStream?->releaseWaiting();
            } catch (Throwable $raised) {
                $failure = $raised;
            }
            try {
                $this->rtSyncStream?->endAnswerWaits();
            } catch (Throwable $raised) {
                $failure ??= $raised;
            }
        }
        $this->sendEncodedFrame((new PeerDrainDTO())->toJson());
        if ($this->peerDrained) {
            $this->closeOnceWritten();
        }
        try {
            $this->armLiveness();
        } catch (Throwable $raised) {
            $failure ??= $raised;
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * Leaves this replacement's inbound frames unparsed in its buffer until the predecessor's inbound ends: the
     * peer's drain marker read there, or the predecessor closing. Reading the socket and liveness go on as before.
     * A predecessor whose inbound already ended holds nothing back.
     *
     * @param self $predecessor Old link to the same node this link replaces
     * @throws LogicException When the predecessor is this link
     */
    public function holdIncomingBehind(self $predecessor): void
    {
        if ($predecessor === $this) {
            throw new LogicException('A peer link cannot wait for its own inbound');
        }
        if ($predecessor->peerDrained || $predecessor->shouldClose || $predecessor->socket === null) {
            return;
        }
        $this->heldBehindPredecessor = true;
        $predecessor->heldSuccessor = WeakReference::create($this);
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
     * Reports whether the peer closed this dialed link after TLS came up but before it welcomed us.
     *
     * In TLS 1.3 a refusal of the dialer's certificate is known only to the accepting side: the
     * dialer finishes its own handshake and then finds the connection closed. A duplicate or a
     * transport failure may also close before welcome, so the server logs a neutral warning.
     *
     * @return bool True for a dialing link whose TLS handshake completed, that never accepted a
     *              welcome, and that this side did not drop on its own
     */
    public function closedUnwelcomed(): bool
    {
        return $this->dialer && !$this->welcomed && !$this->droppedHere && !$this->transport->needsHandshake();
    }

    /**
     * Silently closes this link after it lost the duplicate-link tie-break.
     *
     * The peer is still reachable over the surviving link, so this must not look
     * like a departure: the remote identity is cleared first, which makes
     * {@see onClose()} unable to report a departure. Already queued handshake control
     * frames are written before the socket closes.
     *
     * @throws EventLoopException When the remaining silence deadline cannot be armed
     */
    public function discardAsDuplicate(): void
    {
        $this->candidateIdentity = null;
        $this->remoteIdentity = null;
        $this->droppedHere = true;
        $this->stopReading();
        $this->closeOnceWritten();
        $this->armLiveness();
    }

    /**
     * Dispatches a bounded portion of whole peer frames, closing on a bad frame. A replacement held behind the old
     * link's inbound parses nothing until that inbound ends ({@see self::holdIncomingBehind()}).
     *
     * @throws SocketException If buffer size or JSON depth exceeds limits
     * @throws PeerTransportException When a peer control frame arrives out of sequence
     * @throws InvalidArgumentException When the re-hydrate signal cannot be named
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     * @throws HilosException Whatever the project's own leadership duties raise when this node wins a term
     */
    protected function processReadBuffer(): void
    {
        for ($processed = 0; $this->readBuffer !== ''; ++$processed) {
            // Handling a frame can tear this link down mid-buffer. Once it is closing, stop
            // parsing the frames that followed in the same read, or a trailing gossip frame
            // would be mis-flagged as arriving before the (now-undone) handshake. A replacement that its
            // own ready or ack has just held stops before the next frame, which stays in the buffer.
            if ($this->shouldClose || $this->heldBehindPredecessor) {
                return;
            }

            $message = $this->extractReadBufferJsonMessage($processed);
            if ($message === null) {
                // Incomplete input waits for bytes; a complete remainder already owes a portion.
                break;
            }

            try {
                $this->handleFrame(PeerDTO::fromWire($message));
            } catch (PeerTransportException $e) {
                Logger::warning("Peer link dropped: {$e->getMessage()}");
                $this->droppedHere = true;
                $this->markShouldClose();
                return;
            }
        }
    }

    /**
     * Actual inbound application bytes renew liveness, including an incomplete frame's new tail.
     *
     * @throws EventLoopException When the renewed liveness deadline cannot be armed
     */
    protected function onReadData(): void
    {
        $this->lastHeardAt = hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND;
        $this->armLiveness();
    }

    /**
     * Runs the per-link keepalive: closes a silent link, otherwise pings a quiet one.
     *
     * A link that has heard nothing for the timeout is closed as dead — a hung-but-connected
     * node whose event loop froze with the socket open, which the ordinary link close then
     * routes through the offline/failover path; the same timeout also bounds a stalled
     * half-open handshake. Before that, a link quiet for the keepalive interval sends a
     * {@see PeerPingDTO} (spaced by the interval) to draw a pong and prove the peer alive; a
     * busy link resets its clock on new inbound application bytes and never pings. Pinging waits for the
     * handshake, so a half-open link only times out.
     * Native-bound links ignore unrelated polling passes until their own timer is due.
     *
     * @throws EventLoopException When the next liveness deadline cannot be armed
     */
    public function onTick(): void
    {
        if ($this->shouldClose || $this->socket === null || ($this->livenessTimer !== null && !$this->livenessReady)) {
            return;
        }
        $this->livenessReady = false;
        $now = hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND;
        $silentFor = $now - $this->lastHeardAt;

        if ($silentFor >= $this->linkTimeoutSec) {
            Logger::warning("Peer link timed out after {$silentFor}s of silence"
                . ($this->remoteIdentity !== null ? " to {$this->remoteIdentity->nodeId}" : ' (handshake never completed)'));
            $this->droppedHere = true;
            $this->markShouldClose();
            $this->armLiveness();
            return;
        }

        if ($this->remoteIdentity !== null && !$this->draining
            && $silentFor >= $this->keepaliveIntervalSec
            && ($now - $this->lastPingAt) >= $this->keepaliveIntervalSec) {
            $this->sendFrame(new PeerPingDTO());
            $this->lastPingAt = $now;
        }
        $this->armLiveness();
    }

    /**
     * Gives up the snapshot the RT stream was still reading - its rest will never come, and the
     * staging of it here cancels what it staged ({@see RtSyncStreamChannel::close()}). Ends the
     * waits for the RT stream's answers - a closed link brings no more
     * ({@see self::afterRtWritesAnswered()}) - and notifies the server so it can mark the peer offline.
     * The waits and server notification finish even when the stream's close or a ready callback raises;
     * the first failure reaches the closer. A stale donor's close leaves its successor and transferred waits intact.
     * First of all the end of this link's inbound releases a successor held behind it, which parses on its next pass.
     *
     * @throws HilosException When unused upload peer capacity cannot be canceled
     */
    protected function onClose(): void
    {
        $this->shouldClose = true;
        $this->readBuffer = '';
        $this->discardOutbound();
        $failure = null;
        try {
            $this->releaseHeldSuccessor();
        } catch (Throwable $raised) {
            $failure = $raised;
        }
        try {
            $this->rtSyncStream?->close();
        } catch (Throwable $raised) {
            $failure ??= $raised;
        }
        try {
            $this->rtSyncStream?->endAnswerWaits();
        } catch (Throwable $raised) {
            $failure ??= $raised;
        }
        try {
            $this->server->onLinkClosed($this);
        } catch (Throwable $raised) {
            $failure ??= $raised;
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    /** @return int All pending ordinary bytes; upload spans remain charged to the existing raw pool */
    protected function outboundBackpressureBytes(): int
    {
        return $this->ordinaryOutboundBytes;
    }

    /**
     * Equal future deadlines retain their native generation; outbound traffic never renews silence.
     *
     * @throws EventLoopException When the nearest action cannot be armed
     */
    private function armLiveness(): void
    {
        if ($this->livenessTimer === null) {
            return;
        }
        if ($this->shouldClose || $this->socket === null) {
            $this->livenessTimer->armAt(null);
            return;
        }

        $at = $this->lastHeardAt + $this->linkTimeoutSec;
        if ($this->remoteIdentity !== null && !$this->draining) {
            $at = min($at, max(
                $this->lastHeardAt + $this->keepaliveIntervalSec,
                $this->lastPingAt + $this->keepaliveIntervalSec,
            ));
        }
        $this->livenessTimer->armAt($at);
    }

    /**
     * Preserve one FIFO for data, releases and drain markers. Its cursors include staging,
     * so a later portion never inherits the end of an earlier native append.
     *
     * @param string $frameJson Packed peer frame
     * @param bool $budgeted Whether both upload grants already cover the raw portion
     */
    private function queueOutbound(string $frameJson, bool $budgeted): void
    {
        $bytes = $frameJson . "\n";
        $this->uploadQueuedPosition += strlen($bytes);
        $segment = new PeerOutboundSegment($bytes, $this->uploadQueuedPosition, $budgeted);
        $this->outboundSegments->enqueue($segment);
        if (!$budgeted) {
            $this->ordinaryOutboundBytes += strlen($bytes);
        }
        if ($this->stagedOutbound->isEmpty() && strlen($this->writeBuffer) + strlen($bytes) <= self::MAX_WRITE_BUFFER_BYTES) {
            $this->queueWrite($bytes);
            $this->nativeAppendedBytes += strlen($bytes);
            $segment->bytes = '';
        } else {
            $this->stagedOutbound->enqueue($segment);
            $this->markReady();
        }
    }

    /** Refill only an empty native buffer, preserving frame bytes across partial writes. */
    private function fillOutboundBuffer(): void
    {
        if ($this->writeBuffer !== '' || $this->shouldClose) {
            return;
        }
        while (!$this->stagedOutbound->isEmpty() && strlen($this->writeBuffer) < self::MAX_WRITE_BUFFER_BYTES) {
            $segment = $this->stagedOutbound->bottom();
            $bytes = substr($segment->bytes, 0, self::MAX_WRITE_BUFFER_BYTES - strlen($this->writeBuffer));
            $this->queueWrite($bytes);
            $this->nativeAppendedBytes += strlen($bytes);
            $segment->bytes = substr($segment->bytes, strlen($bytes));
            if ($segment->bytes === '') {
                $this->stagedOutbound->dequeue();
            }
        }
    }

    /** @param int $bytes Bytes actually removed by the parent writer, including a final native discard */
    private function recordOutboundRemoval(int $bytes): void
    {
        while ($bytes > 0 && !$this->outboundSegments->isEmpty()) {
            $segment = $this->outboundSegments->bottom();
            $removed = min($bytes, $segment->endPosition - $this->uploadRemovedPosition);
            if (!$segment->budgeted) {
                $this->ordinaryOutboundBytes -= $removed;
            }
            $this->uploadRemovedPosition += $removed;
            $bytes -= $removed;
            if ($this->uploadRemovedPosition === $segment->endPosition) {
                $this->outboundSegments->dequeue();
            }
        }
    }

    /** Delete every serialized reference before the link's pool owner may release source debts. */
    private function discardOutbound(): void
    {
        $this->writeBuffer = '';
        $this->stagedOutbound = new SplQueue();
        $this->outboundSegments = new SplQueue();
        $this->ordinaryOutboundBytes = 0;
        $this->uploadRemovedPosition = $this->uploadQueuedPosition;
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
            $frame instanceof PeerReadyDTO => $this->onReady($frame),
            $frame instanceof PeerReadyAckDTO => $this->onReadyAck($frame),
            $frame instanceof PeerDrainDTO => $this->onDrain(),
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
            $frame instanceof PeerPlacementReleaseDTO => $this->onPlacementRelease($frame),
            $frame instanceof PeerPlacementReportDTO => $this->onPlacementReport($frame),
            $frame instanceof PeerPlacementViewDTO => $this->onPlacementView($frame),
            $frame instanceof PeerPlacementRequestDTO => $this->onPlacementRequest($frame),
            $frame instanceof PeerAgentRestartDTO => $this->onAgentRestart($frame),
            $frame instanceof PeerPlacementVerdictDTO => $this->onPlacementVerdict($frame),
            $frame instanceof PeerSignalDTO => $this->onSignal($frame),
            $frame instanceof PeerUploadReserveDTO, $frame instanceof PeerUploadGrantedDTO,
            $frame instanceof PeerUploadAcceptedDTO, $frame instanceof PeerUploadReleaseDTO => $this->onUploadControl($frame),
            $frame instanceof PeerRtSyncStreamDTO => $this->onRtSyncStream($frame),
            $frame instanceof PeerDbSyncDTO => $this->onDbSync($frame),
            $frame instanceof PeerSourceInterestDTO => $this->onSourceInterest($frame),
            $frame instanceof PeerSourceInterestAnsweredDTO => $this->onSourceInterestAnswered($frame),
            $frame instanceof PeerRtClaimsDTO => $this->onRtClaims($frame),
            $frame instanceof PeerRtClaimsQueryDTO => $this->onRtClaimsQuery($frame),
            $frame instanceof PeerRtClaimRefusedDTO => $this->onRtClaimRefused($frame),
            $frame instanceof PeerRtSnapshotQueryDTO => $this->onRtSnapshotQuery($frame),
            $frame instanceof PeerRtSnapshotRequestDTO => $this->onRtSnapshotRequest($frame),
            $frame instanceof PeerRtReplicaOfferDTO => $this->onRtReplicaOffer($frame),
            $frame instanceof PeerClientSignalDTO => $this->onClientSignal($frame),
            $frame instanceof PeerClientFanoutDTO => $this->onClientFanout($frame),
            $frame instanceof PeerConnectionDropDTO => $this->onConnectionDrop($frame),
            $frame instanceof PeerPageAccessReassessDTO => $this->onPageAccessReassess($frame),
            $frame instanceof PeerGroupLeaveAllDTO => $this->onGroupLeaveAll($frame),
            $frame instanceof PeerHttpReplyDTO => $this->onHttpReply($frame),
            $frame instanceof PeerCommandReplyDTO => $this->onCommandReply($frame),
            $frame instanceof PeerConnectionsSnapshotDTO => $this->onConnectionsSnapshot($frame),
            $frame instanceof PeerConnectionsDeltaDTO => $this->onConnectionsDelta($frame),
            $frame instanceof PeerProtectedModeEnableDTO => $this->onProtectedModeEnable($frame),
            $frame instanceof PeerProtectedModeReadyDTO => $this->onProtectedModeReady($frame),
            $frame instanceof PeerProtectedModeRefusedDTO => $this->onProtectedModeRefused($frame),
            $frame instanceof PeerProtectedModeDisableDTO => $this->onProtectedModeDisable($frame),
            $frame instanceof PeerProtectedModeQuiesceDTO => $this->onProtectedModeQuiesce($frame),
            $frame instanceof PeerProtectedModeWindowDTO => $this->onProtectedModeWindow($frame),
            $frame instanceof PeerProtectedModeSyncDTO => $this->onProtectedModeSync($frame),
            $frame instanceof PeerProtectedModeSyncedDTO => $this->onProtectedModeSynced($frame),
            $frame instanceof PeerProtectedModeQuiescedDTO => $this->onProtectedModeQuiesced($frame),
            $frame instanceof PeerProtectedModeSettledDTO => $this->onProtectedModeSettled($frame),
            $frame instanceof PeerProtectedModeNodeReportDTO => $this->onProtectedModeNodeReport($frame),
            $frame instanceof PeerDbReHydrateDTO => $this->onDbReHydrate($frame),
            $frame instanceof PeerDbReHydratedDTO => $this->onDbReHydrated($frame),
            $frame instanceof PeerProtectedModeLiftDTO => $this->onProtectedModeLift($frame),
            $frame instanceof PeerProtectedModeVerifyDTO => $this->onProtectedModeVerify($frame),
            $frame instanceof PeerProtectedModeProgressDTO => $this->onProtectedModeProgress($frame),
            $frame instanceof PeerProtectedModePassDTO => $this->onProtectedModePass($frame),
            $frame instanceof PeerProtectedModeAdmitDTO => $this->onProtectedModeAdmit($frame),
            $frame instanceof PeerProtectedModeCircleDTO => $this->onProtectedModeCircle($frame),
            $frame instanceof PeerProtectedModeRefreezeDTO => $this->onProtectedModeRefreeze($frame),
            $frame instanceof PeerPingDTO => $this->onPing($frame),
            $frame instanceof PeerPongDTO => $this->onPong(),
            $frame instanceof PeerClockProbeDTO => $this->onClockProbe($frame),
            $frame instanceof PeerClockReplyDTO => $this->onClockReply($frame),
            default => throw new PeerTransportException('Unexpected peer frame type: ' . $frame->getType()),
        };
    }

    /**
     * Accepting side: certifies a candidate and answers with a welcome.
     *
     * @param PeerHelloDTO $hello Incoming hello frame
     * @throws PeerTransportException When a hello arrives on the dialing side, the version is incompatible,
     *                                the node id is not the name the peer's certificate carries, or the
     *                                peer's markers differ from this node's
     * @throws EventLoopException When a superseded candidate's silence deadline cannot be armed
     */
    private function onHello(PeerHelloDTO $hello): void
    {
        if ($this->dialer || $this->candidateIdentity !== null || $this->remoteIdentity !== null) {
            throw new PeerTransportException('Unexpected peer hello');
        }

        $this->requireCompatible($hello);
        $this->requireCertifiedAs($hello->nodeId);
        $this->requireSameMarkers($hello);

        $remote = NodeIdentity::of($hello->nodeId, $hello->name, $hello->role, $hello->capabilities, $hello->address);
        $welcome = new PeerWelcomeDTO(
            PeerProtocol::VERSION,
            $this->localIdentity->nodeId,
            $this->server->localNodeName(),
            $this->localIdentity->role,
            $this->localIdentity->capabilities,
            $this->server->localMarkers()->values,
            $this->localIdentity->address,
        );
        try {
            $this->server->registerCandidate($this, $remote);
        } catch (PeerTransportException) {
            // Even a losing dial must learn which certified seed it reached.
            $this->sendFrame($welcome);
            $this->stopReading();
            $this->closeOnceWritten();
            return;
        }
        $this->candidateIdentity = $remote;
        $this->sendFrame($welcome);
    }

    /**
     * Dialing side: certifies the welcome, names the seed, and sends ready.
     *
     * @param PeerWelcomeDTO $welcome Incoming welcome frame
     * @throws PeerTransportException When a welcome arrives on the accepting side, the version is incompatible,
     *                                the node id is not the name the peer's certificate carries, or the
     *                                peer's markers differ from this node's
     * @throws EventLoopException When a superseded candidate's silence deadline cannot be armed
     */
    private function onWelcome(PeerWelcomeDTO $welcome): void
    {
        if (!$this->dialer || $this->welcomed || $this->remoteIdentity !== null) {
            throw new PeerTransportException('Unexpected peer welcome');
        }

        $this->requireCompatible($welcome);
        $this->requireCertifiedAs($welcome->nodeId);
        $this->requireSameMarkers($welcome);
        $this->welcomed = true;

        $remote = NodeIdentity::of($welcome->nodeId, $welcome->name, $welcome->role, $welcome->capabilities, $welcome->address);
        $this->server->stampDialRemote($this, $remote->nodeId);
        $this->server->registerCandidate($this, $remote);
        $this->candidateIdentity = $remote;
        $hasActiveLink = $this->server->hasActiveLinkToNode($remote->nodeId);
        if ($hasActiveLink) {
            $this->server->allowOldLinkDrain($remote->nodeId);
        }
        $this->readySent = true;
        $this->sendFrame(new PeerReadyDTO($hasActiveLink));
    }

    /**
     * @param PeerReadyDTO $ready Dialer's old-route state
     * @throws PeerTransportException When ready arrives on the wrong link or out of sequence
     * @throws HilosException When activation or liveness scheduling fails
     */
    private function onReady(PeerReadyDTO $ready): void
    {
        if ($this->dialer || $this->candidateIdentity === null || $this->readyReceived) {
            throw new PeerTransportException('Unexpected peer ready');
        }

        $this->readyReceived = true;
        $remote = $this->candidateIdentity;
        $keepNew = $this->server->shouldKeepCandidate($this, $remote->nodeId, $ready->hasActiveLink);
        // The ack must precede application frames queued by activation on this socket.
        $this->sendFrame(new PeerReadyAckDTO($keepNew));
        if ($keepNew) {
            $this->server->activateCandidate($this, $remote);
        } else {
            $this->discardAsDuplicate();
        }
    }

    /**
     * @param PeerReadyAckDTO $ack Acceptor's route verdict
     * @throws PeerTransportException When the ack arrives on the wrong link or out of sequence
     * @throws HilosException When activation or liveness scheduling fails
     */
    private function onReadyAck(PeerReadyAckDTO $ack): void
    {
        if (!$this->dialer || !$this->readySent || $this->candidateIdentity === null) {
            throw new PeerTransportException('Unexpected peer ready ack');
        }

        $remote = $this->candidateIdentity;
        $this->readySent = false;
        if ($ack->keepNew) {
            $this->server->activateCandidate($this, $remote);
        } else {
            $this->discardAsDuplicate();
        }
    }

    /**
     * Marks the peer's old stream complete without discarding frames preceding it, and releases the successor
     * held behind this link's inbound; a marker refused as out of place releases nothing.
     *
     * @throws PeerTransportException When drain arrives without a pending replacement
     * @throws EventLoopException When the rejected candidate's silence deadline cannot be armed
     */
    private function onDrain(): void
    {
        if (!$this->peerDrainAllowed || $this->peerDrained) {
            $nodeId = $this->remoteIdentity?->nodeId;
            if ($nodeId !== null && $this->server->hasPendingCandidateFor($nodeId)) {
                $this->server->rejectCandidateFor($nodeId);
                return;
            }
            throw new PeerTransportException('Unexpected peer drain');
        }

        $this->peerDrained = true;
        $this->releaseHeldSuccessor();
        if ($this->draining) {
            $this->closeOnceWritten();
        }
    }

    /** Lets the successor held behind this link's inbound parse again from the frame where it stopped. */
    private function releaseHeldSuccessor(): void
    {
        $successor = $this->heldSuccessor?->get();
        $this->heldSuccessor = null;
        if ($successor === null || !$successor->heldBehindPredecessor) {
            return;
        }
        $successor->heldBehindPredecessor = false;
        // A real release is one new parsing opportunity; the next pass of the loop takes it.
        $successor->continueReadBuffer();
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
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
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
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
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
     * @throws RtActionsStateCollectionNullException When the cluster registry is unavailable
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
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
     * @throws InvalidFormatException When an abandoned start has an invalid stop completion address
     * @throws RtActionsStateCollectionNullException When the cluster registry is unavailable
     * @throws EventLoopException When the existing executor cannot arm a stop deadline
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
     * @throws RtActionsStateCollectionNullException When the cluster registry is unavailable
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
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
        $this->server->onPlacementQueryReceived($this, $frame);
    }

    /**
     * Hands a received placement release to the server so this node forgets a leader that stopped leading.
     *
     * @param PeerPlacementReleaseDTO $frame Incoming placement-release frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onPlacementRelease(PeerPlacementReleaseDTO $frame): void
    {
        $this->requireHandshaked('placement release');
        $this->server->onPlacementReleaseReceived($this);
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
     * @param PeerAgentRestartDTO $frame Incoming restart request
     * @throws PeerTransportException When the frame arrives before the handshake
     * @throws EnvException When this node cannot read its leader id
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
     */
    private function onAgentRestart(PeerAgentRestartDTO $frame): void
    {
        $this->requireHandshaked('agent restart');
        $this->server->onAgentRestartReceived($this, $frame);
    }

    /**
     * Hands a received placement report to the server for the leader to rebuild its view.
     *
     * @param PeerPlacementReportDTO $frame Incoming placement-report frame
     * @throws PeerTransportException When the frame arrives before the handshake
     * @throws RtActionsStateCollectionNullException When the cluster registry is unavailable
     */
    private function onPlacementReport(PeerPlacementReportDTO $frame): void
    {
        $this->requireHandshaked('placement report');
        $this->server->onPlacementReportReceived($this, $frame);
    }

    /**
     * Hands a received claim report to the server for the leader to judge who owns what.
     *
     * @param PeerRtClaimsDTO $frame Incoming RT-claims frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onRtClaims(PeerRtClaimsDTO $frame): void
    {
        $this->requireHandshaked('RT claims');
        $this->server->onRtClaimsReceived($this, $frame);
    }

    /**
     * Hands a received claim query to the server so this node reports what its agents own.
     *
     * @param PeerRtClaimsQueryDTO $frame Incoming RT-claims-query frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onRtClaimsQuery(PeerRtClaimsQueryDTO $frame): void
    {
        $this->requireHandshaked('RT claims query');
        $this->server->onRtClaimsQueryReceived($this);
    }

    /**
     * Hands a received claim refusal to the server for this node to act on the leader's verdict.
     *
     * @param PeerRtClaimRefusedDTO $frame Incoming RT-claim-refused frame
     * @throws PeerTransportException When the frame arrives before the handshake
     * @throws InvalidFormatException When a tracked stop completion address is invalid
     * @throws EventLoopException When the executor cannot arm the stop deadline
     */
    private function onRtClaimRefused(PeerRtClaimRefusedDTO $frame): void
    {
        $this->requireHandshaked('RT claim refusal');
        $this->server->onRtClaimRefusedReceived($this, $frame);
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
     * @param PeerDTO $frame Upload control frame on its original established link
     * @throws PeerTransportException When control arrives before identity establishment or is malformed
     * @throws HilosException When the owning master cannot reconcile its ledger
     */
    private function onUploadControl(PeerDTO $frame): void
    {
        $this->requireHandshaked('upload control');
        $this->server->onUploadControlReceived($this, $frame);
    }

    /**
     * Hands a part or an answer of the ordinary RT stream to this link's stream.
     *
     * @param PeerRtSyncStreamDTO $frame Incoming RT stream frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onRtSyncStream(PeerRtSyncStreamDTO $frame): void
    {
        $this->requireHandshaked('RT sync');
        $this->rtSyncStream()->receive($frame->frame);
    }

    /**
     * The ordinary RT stream of this link instance, both directions: this node's writes out, and
     * the linked node's in, each applied only as that node's own. Opened on first use, so a
     * replaced link is a new epoch.
     *
     * An operation is received with the receipt taken when its final part arrived, and applied
     * by the server even if this link closed in between: a loss of its node then freezes it. A
     * snapshot of a collection is handed to the server at its head, and the server hands its
     * staging back as the stream's work ({@see PeerServer::onRtSyncStreamReceived()}).
     *
     * A row the stream holds after refusing the linked node's write is marked by the master, which
     * tells the workers that read the row, since their copies came from this node's and froze with
     * it; there is no writing worker to leave out. Without a master's mark it is marked in this
     * process alone. Either way the first row held of a collection asks the linked node for a
     * snapshot of it ({@see self::requestRtSnapshot()}).
     *
     * A draining link writes nothing more of its stream. Its positions belong to this instance:
     * handed to the replacement route as other frames are, a part would open the new link's
     * incoming epoch under this stream id, or read there as another generation.
     *
     * @return RtSyncStreamChannel Stream of this link
     */
    public function rtSyncStream(): RtSyncStreamChannel
    {
        if ($this->rtSyncStream === null) {
            $link = WeakReference::create($this);
            $server = $this->server;
            $hold = $this->server->rtSyncStreamHold();
            $this->rtSyncStream = new RtSyncStreamChannel(
                static function (RtSyncStreamFrame $frame) use ($link): void {
                    $owner = $link->get();
                    if ($owner === null || $owner->draining) {
                        return;
                    }
                    $owner->sendFrame(new PeerRtSyncStreamDTO($frame));
                },
                static function (
                    RtSyncStreamOperation|RtSyncStreamSnapshot $operation,
                    Closure $settled,
                    ?RtReplicaConfirmation $receipt = null,
                    ?SignalDispatch $origin = null,
                ) use ($link, $server): ?Generator {
                    $owner = $link->get();
                    if ($owner !== null && $operation instanceof RtSyncStreamSnapshot) {
                        unset($owner->rtSnapshotsAsked[$operation->collectionKey]);
                    }

                    return $server->onRtSyncStreamReceived($owner, $operation, $settled, $receipt, $origin);
                },
                $this->server->rtSyncStreamWake(),
                null,
                static function () use ($link, $server): ?RtReplicaConfirmation {
                    $owner = $link->get();

                    return $owner === null ? null : $server->rtSyncReceipt($owner);
                },
                static function (string $collectionKey, array $stateIds, ?float $since, ?SignalDispatch $origin = null) use ($link, $hold): void {
                    if ($hold !== null) {
                        $hold(null, $collectionKey, $stateIds, $since, $origin);
                    } elseif ($since === null) {
                        RtStaleness::clear($collectionKey, $stateIds);
                    } else {
                        RtStaleness::mark($collectionKey, $stateIds, $since);
                    }
                    if ($since !== null) {
                        $link->get()?->requestRtSnapshot($collectionKey);
                    }
                },
            );
        }

        return $this->rtSyncStream;
    }

    /**
     * Lifts the hold this link's stream keeps on the rows of a collection whose operation it
     * refused, once the linked node's snapshot of the collection was applied here; the snapshot
     * also ends a run of its canceled predecessors ({@see self::requestRtSnapshotAgain()}). A link
     * that never opened its stream holds nothing.
     *
     * @param string $collectionKey Collection the snapshot replaced
     * @param ?SignalDispatch $origin Causal operation, or null for an independent fact
     */
    public function recoverRtSyncStream(string $collectionKey, ?SignalDispatch $origin = null): void
    {
        unset($this->rtSnapshotsContested[$collectionKey]);
        $this->rtSyncStream?->recover($collectionKey, $origin);
    }

    /**
     * Asks the linked node for one more snapshot of an RT collection whose last snapshot was canceled
     * here before its publication ({@see self::requestRtSnapshot()}). A live change of the area that
     * cancels the second snapshot in a row is a writer here every cut of that node misses - a second
     * owner of the area - and asking again would only be canceled again: that one is not asked
     * again, and the next event asks (a hold, a hand-over), as after a refusal. A publication of the
     * collection's snapshot ends the run ({@see self::recoverRtSyncStream()}), and so does a
     * cancellation for another cause.
     *
     * A draining link asks through the link that replaced it, and the run is that link's: the next
     * snapshot comes there ({@see PeerServer::requestRtSnapshotAgain()}). Without one nothing is asked,
     * and nothing is to come: the node is struck out of what the copies wait for once its last link closed.
     *
     * @param string $collectionKey Collection whose snapshot was canceled
     * @param bool $byLiveChange Whether a live change of the snapshot's area here canceled it
     * @return bool Whether another snapshot of the collection is asked for and still to come
     */
    public function requestRtSnapshotAgain(string $collectionKey, bool $byLiveChange): bool
    {
        if ($this->draining) {
            $nodeId = $this->remoteIdentity?->nodeId;

            return $nodeId !== null && $this->server->requestRtSnapshotAgain($nodeId, $collectionKey, $byLiveChange);
        }
        if ($byLiveChange && isset($this->rtSnapshotsContested[$collectionKey])) {
            Logger::warning("A write here canceled the RT snapshot of {$collectionKey} from node '{$this->remoteIdentity?->nodeId}'"
                . ' twice in a row: two owners of its area; another is asked for on the next event only');

            return false;
        }
        if ($byLiveChange) {
            $this->rtSnapshotsContested[$collectionKey] = true;
        } else {
            unset($this->rtSnapshotsContested[$collectionKey]);
        }
        $this->requestRtSnapshot($collectionKey);

        return isset($this->rtSnapshotsAsked[$collectionKey]);
    }

    /**
     * Asks the linked node for one more snapshot of an RT collection, this node's copy of its rows
     * having lost its base: the stream holds a row of it after refusing its operation, or a
     * snapshot of it was canceled before its publication. One request waits per collection, until
     * a snapshot of it reaches the stream's host; a draining or closed link asks nothing - a snapshot
     * canceled on a draining link is asked for on its replacement ({@see self::requestRtSnapshotAgain()}),
     * and a new link after a closed one hands the collection over on its handshake. A linked node that
     * owns nothing of the collection any more answers with no snapshot, and the request goes on waiting
     * until the next hand-over of the collection on this link or the link's replacement.
     *
     * @param string $collectionKey Collection to ask a snapshot of
     */
    public function requestRtSnapshot(string $collectionKey): void
    {
        if (isset($this->rtSnapshotsAsked[$collectionKey]) || $this->draining || $this->shouldClose || $this->remoteIdentity === null) {
            return;
        }
        $this->rtSnapshotsAsked[$collectionKey] = true;
        $this->sendFrame(new PeerRtSnapshotRequestDTO($this->localIdentity->nodeId, $collectionKey));
    }

    /**
     * Waits for the operations accepted before this call, following replacements even through an old
     * link reference. Transferred writes keep their answer boundary; fully sent old writes are not
     * replayed and their old answer obligations end. A real close or drain without a successor ends
     * the wait. An unopened stream answers at once without opening one. Ready callbacks all run
     * before their first failure is rethrown.
     *
     * @param Closure(): void $then Told the writes are answered
     */
    public function afterRtWritesAnswered(Closure $then): void
    {
        if ($this->frameRoute->successor !== null) {
            $owner = $this->frameRoute->current();
            if ($owner !== null) {
                $owner->afterRtWritesAnswered($then);
            } else {
                $then();
            }

            return;
        }
        if ($this->rtSyncStream === null || $this->draining || $this->shouldClose) {
            $then();

            return;
        }
        $this->rtSyncStream->afterAnswered($then);
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
     * Hands a received request for a collection to the server so this node answers with what it holds.
     *
     * @param PeerRtSnapshotQueryDTO $frame Incoming RT-snapshot-query frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onRtSnapshotQuery(PeerRtSnapshotQueryDTO $frame): void
    {
        $this->requireHandshaked('RT snapshot query');
        $this->server->onRtSnapshotQueryReceived($this, $frame);
    }

    /**
     * Hands a received request for one more snapshot of a collection to the server, so this node
     * cuts its areas of the collection into this link's stream.
     *
     * @param PeerRtSnapshotRequestDTO $frame Incoming RT-snapshot-request frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onRtSnapshotRequest(PeerRtSnapshotRequestDTO $frame): void
    {
        $this->requireHandshaked('RT snapshot request');
        $this->server->onRtSnapshotRequestReceived($this, $frame);
    }

    /**
     * Hands a received offer of replica rows to the server for this node's copy to be topped up.
     *
     * @param PeerRtReplicaOfferDTO $frame Incoming RT-replica-offer frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onRtReplicaOffer(PeerRtReplicaOfferDTO $frame): void
    {
        $this->requireHandshaked('RT replica offer');
        $this->server->onRtReplicaOfferReceived($this, $frame);
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
     * Hands a received answer to this node's announcement of what it reads to the server.
     *
     * @param PeerSourceInterestAnsweredDTO $frame Incoming answer to an interest frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onSourceInterestAnswered(PeerSourceInterestAnsweredDTO $frame): void
    {
        $this->requireHandshaked('source interest answer');
        $this->server->onSourceInterestAnsweredReceived($this, $frame);
    }

    /**
     * Hands a received placement view to the server for this node to answer lookups from.
     *
     * @param PeerPlacementViewDTO $frame Incoming placement-view frame
     * @throws PeerTransportException When the frame arrives before the handshake
     * @throws EnvException When placement source confirmation cannot read cluster mode
     */
    private function onPlacementView(PeerPlacementViewDTO $frame): void
    {
        $this->requireHandshaked('placement view');
        $this->server->onPlacementViewReceived($this, $frame);
    }

    /**
     * Hands a received placement verdict to the server for this node to answer a waiting ask.
     *
     * @param PeerPlacementVerdictDTO $frame Incoming placement-verdict frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onPlacementVerdict(PeerPlacementVerdictDTO $frame): void
    {
        $this->requireHandshaked('placement verdict');
        $this->server->onPlacementVerdictReceived($this, $frame);
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
     * Hands a received HTTP reply to the server for the connection this node parked.
     *
     * @param PeerHttpReplyDTO $frame Incoming HTTP reply-forward frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onHttpReply(PeerHttpReplyDTO $frame): void
    {
        $this->requireHandshaked('HTTP reply');
        $this->server->onHttpReplyReceived($this, $frame);
    }

    /**
     * Hands a received command reply to the server for the connection this node parked.
     *
     * @param PeerCommandReplyDTO $frame Incoming command reply-forward frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onCommandReply(PeerCommandReplyDTO $frame): void
    {
        $this->requireHandshaked('command reply');
        $this->server->onCommandReplyReceived($this, $frame);
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
     * Hands a rotated session's sibling-drop request to the local server.
     *
     * @param PeerConnectionDropDTO $frame Incoming connection-drop frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onConnectionDrop(PeerConnectionDropDTO $frame): void
    {
        $this->requireHandshaked('connection drop');
        $this->server->onConnectionDropReceived($this, $frame);
    }

    /**
     * Hands a cross-node page access re-decision to this node's server.
     *
     * @param PeerPageAccessReassessDTO $frame Incoming re-decision frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onPageAccessReassess(PeerPageAccessReassessDTO $frame): void
    {
        $this->requireHandshaked('page access re-decision');
        $this->server->onPageAccessReassessReceived($this, $frame);
    }

    /**
     * Hands a cross-node group leave-all to this node's server (HIL-1284).
     *
     * @param PeerGroupLeaveAllDTO $frame Incoming leave-all frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onGroupLeaveAll(PeerGroupLeaveAllDTO $frame): void
    {
        $this->requireHandshaked('group leave-all');
        $this->server->onGroupLeaveAllReceived($this, $frame);
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
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
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
     * Hands a received protected-mode refused notice to the server for the initiator to act on.
     *
     * @param PeerProtectedModeRefusedDTO $frame Incoming protected-mode refused frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onProtectedModeRefused(PeerProtectedModeRefusedDTO $frame): void
    {
        $this->requireHandshaked('protected-mode refused');
        $this->server->onProtectedModeRefusedReceived($this, $frame);
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
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
     */
    private function onProtectedModeQuiesce(PeerProtectedModeQuiesceDTO $frame): void
    {
        $this->requireHandshaked('protected-mode quiesce');
        $this->server->onProtectedModeQuiesceReceived($this, $frame);
    }

    /**
     * Hands a direct verification-window order to this follower's server.
     *
     * @param PeerProtectedModeWindowDTO $frame Incoming protected-mode window frame
     * @throws PeerTransportException When the frame arrives before the handshake
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     * @throws EnvException When the cluster-enabled flag value is invalid during leader lookup
     */
    private function onProtectedModeWindow(PeerProtectedModeWindowDTO $frame): void
    {
        $this->requireHandshaked('protected-mode window');
        $this->server->onProtectedModeWindowReceived($this, $frame);
    }

    /**
     * @param PeerProtectedModeSyncDTO $frame Slave synchronization frame
     * @throws PeerTransportException When authentication is incomplete or the frame is invalid
     * @throws EnvException When leadership or timeout configuration is invalid
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this master is not the truth source
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
     */
    private function onProtectedModeSync(PeerProtectedModeSyncDTO $frame): void
    {
        $this->requireHandshaked('protected-mode sync');
        $this->server->onProtectedModeSyncReceived($this, $frame);
    }

    /**
     * @param PeerProtectedModeSyncedDTO $frame Slave synchronization frame
     * @throws PeerTransportException When authentication is incomplete or the frame is invalid
     * @throws EnvException When leadership or timeout configuration is invalid
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this master is not the truth source
     */
    private function onProtectedModeSynced(PeerProtectedModeSyncedDTO $frame): void
    {
        $this->requireHandshaked('protected-mode synced');
        $this->server->onProtectedModeSyncedReceived($this, $frame);
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
     * @param PeerProtectedModeNodeReportDTO $frame Diagnostic snapshot with identity supplied by this link
     * @throws PeerTransportException When the frame precedes the authenticated handshake
     * @throws EnvException When the local consensus configuration is invalid
     */
    private function onProtectedModeNodeReport(PeerProtectedModeNodeReportDTO $frame): void
    {
        $this->requireHandshaked('protected-mode node report');
        $this->server->onProtectedModeNodeReportReceived($this, $frame);
    }

    /**
     * Hands the leader's word that every node has quiesced to the server for this follower to settle.
     *
     * @param PeerProtectedModeSettledDTO $frame Incoming protected-mode settled frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onProtectedModeSettled(PeerProtectedModeSettledDTO $frame): void
    {
        $this->requireHandshaked('protected-mode settled');
        $this->server->onProtectedModeSettledReceived($this, $frame);
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
     * @param PeerProtectedModeAdmitDTO $frame Incoming verifier admission
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onProtectedModeAdmit(PeerProtectedModeAdmitDTO $frame): void
    {
        $this->requireHandshaked('protected-mode admit');
        $this->server->onProtectedModeAdmitReceived($this, $frame);
    }

    /**
     * Hands a received protected-mode circle frame to the server for this node to record.
     *
     * @param PeerProtectedModeCircleDTO $frame Incoming protected-mode circle frame
     * @throws PeerTransportException When the frame arrives before the handshake
     */
    private function onProtectedModeCircle(PeerProtectedModeCircleDTO $frame): void
    {
        $this->requireHandshaked('protected-mode circle');
        $this->server->onProtectedModeCircleReceived($this, $frame);
    }

    /**
     * Hands a received protected-mode refreeze frame to the server for this node to act on.
     *
     * @param PeerProtectedModeRefreezeDTO $frame Incoming protected-mode refreeze frame
     * @throws PeerTransportException When the frame arrives before the handshake
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
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
     * @param PeerClockProbeDTO $frame Incoming clock probe
     * @throws PeerTransportException When the probe arrives before the handshake
     */
    private function onClockProbe(PeerClockProbeDTO $frame): void
    {
        $this->requireHandshaked('clock probe');
        $this->server->onClockProbeReceived($this, $frame);
    }

    /**
     * @param PeerClockReplyDTO $frame Incoming clock reply
     * @throws PeerTransportException When the reply arrives before the handshake
     */
    private function onClockReply(PeerClockReplyDTO $frame): void
    {
        $this->requireHandshaked('clock reply');
        $this->server->onClockReplyReceived($this, $frame);
    }

    /**
     * Accepts a keepalive pong; its newly received application bytes already renewed liveness.
     *
     * A buffered parser continuation does not renew the clock again. Like {@see onPing()},
     * this handler is not gated on the handshake.
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
     * Rejects a handshake frame whose node id is not the name the peer's certificate carries.
     *
     * The transport has checked the certificate's chain; this checks that the certificate is the
     * introduced node's own. A transport that vouches for nobody fails it too - the peer channel
     * has no plain mode.
     *
     * @param string $nodeId Node id the handshake frame introduces
     * @throws PeerTransportException When the transport vouches for no name, or for another one
     */
    private function requireCertifiedAs(string $nodeId): void
    {
        $certificateName = $this->transport->verifiedPeerName();
        if ($certificateName === null) {
            throw new PeerTransportException(
                "Peer handshake names node '{$nodeId}' but the connection vouches for no certificate name",
            );
        }

        if ($certificateName !== $nodeId) {
            throw new PeerTransportException(
                "Peer handshake names node '{$nodeId}' but its certificate names '{$certificateName}'",
            );
        }
    }

    /**
     * Rejects a handshake frame whose markers differ from this node's.
     *
     * Judged after the certificate name, so the refusal names a node the transport vouched for.
     * The rule and the words of the refusal are {@see PeerMarkers::refusalFor()}'s.
     *
     * @param PeerHandshakeDTO $frame Handshake frame to check
     * @throws PeerTransportException When a kind either side names is missing on the other or carries another value
     */
    private function requireSameMarkers(PeerHandshakeDTO $frame): void
    {
        $refusal = $this->server->localMarkers()->refusalFor($frame->nodeId, $frame->markers);
        if ($refusal !== null) {
            throw new PeerTransportException($refusal);
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

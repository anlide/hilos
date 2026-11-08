<?php

declare(strict_types=1);

namespace Hilos\Cluster\Peer;

use Hilos\Core\EventLoop\Exception\EventLoopException;
use Closure;
use Generator;
use Hilos\Constants\TimeConstants;
use Hilos\Core\Daemon\ContainedFailure;
use Hilos\Core\Daemon\Master\MasterFailureUnit;
use Hilos\Core\EventLoop\EventLoop;
use Hilos\Core\EventLoop\ReadyWork;
use Hilos\Utils\ClientReadFailureLog;
use Hilos\Core\EventLoop\Exception\NativeEventUnavailableException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Cluster\Exception\PeerTransportException;

use Hilos\Cluster\AgentSignalMesh;
use Hilos\Cluster\ClientMesh;
use Hilos\Cluster\ClusterNode;
use Hilos\Cluster\ClusterRegistry;
use Hilos\Cluster\Clock\ClusterClock;
use Hilos\Cluster\Consensus\ClusterConsensusConfig;
use Hilos\Cluster\Consensus\ClusterCoordinator;
use Hilos\Cluster\Consensus\ConsensusInspection;
use Hilos\Cluster\Consensus\ConsensusMesh;
use Hilos\Cluster\Exception\ClusterConfigurationException;
use Hilos\Cluster\LocalNodeAnnouncer;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
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
use Hilos\Cluster\Peer\DTO\PeerDbSyncDTO;
use Hilos\Cluster\Peer\DTO\PeerDbReHydratedDTO;
use Hilos\Cluster\Peer\DTO\PeerDbReHydrateDTO;
use Hilos\Cluster\Peer\DTO\PeerDTO;
use Hilos\Cluster\Peer\DTO\PeerGroupLeaveAllDTO;
use Hilos\Cluster\Peer\DTO\PeerHeartbeatDTO;
use Hilos\Cluster\Peer\DTO\PeerHttpReplyDTO;
use Hilos\Cluster\Peer\DTO\PeerNodeEntry;
use Hilos\Cluster\Peer\DTO\PeerNodeLeavingDTO;
use Hilos\Cluster\Peer\DTO\PeerPageAccessReassessDTO;
use Hilos\Cluster\Peer\DTO\PeerPlaceAgentDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementReportDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementRequestDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementVerdictDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementViewDTO;
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
use Hilos\Cluster\Peer\DTO\PeerProtectedModeQuiescedDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeReadyDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeRefusedDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeRefreezeDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeSettledDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeVerifyDTO;
use Hilos\Cluster\Peer\DTO\PeerProtectedModeWindowDTO;
use Hilos\Cluster\Peer\DTO\PeerRequestVoteDTO;
use Hilos\Cluster\Peer\DTO\PeerRosterDTO;
use Hilos\Cluster\Peer\DTO\PeerPlacementQueryDTO;
use Hilos\Cluster\Peer\DTO\PeerRtClaimEntry;
use Hilos\Cluster\Peer\DTO\PeerRtClaimRefusedDTO;
use Hilos\Cluster\Peer\DTO\PeerRtClaimsDTO;
use Hilos\Cluster\Peer\DTO\PeerRtClaimsQueryDTO;
use Hilos\Cluster\Peer\DTO\PeerRtReplicaOfferDTO;
use Hilos\Cluster\Peer\DTO\PeerRtSnapshotDTO;
use Hilos\Cluster\Peer\DTO\PeerRtSnapshotQueryDTO;
use Hilos\Cluster\Peer\DTO\PeerRtSyncDTO;
use Hilos\Cluster\Peer\DTO\PeerSignalDTO;
use Hilos\Cluster\Peer\DTO\PeerSourceInterestDTO;
use Hilos\Cluster\Peer\DTO\PeerStopAgentDTO;
use Hilos\Cluster\Peer\DTO\PeerVoteReplyDTO;
use Hilos\Cluster\PendingLeadership;
use Hilos\Cluster\Placement\AgentLocation;
use Hilos\Cluster\Placement\ClusterPlacement;
use Hilos\Cluster\Placement\NodeCapacities;
use Hilos\Cluster\Placement\PlacementMesh;
use Hilos\Cluster\DbSyncMesh;
use Hilos\Cluster\DbSyncSink;
use Hilos\Cluster\RtClaimMesh;
use Hilos\Cluster\RtSyncMesh;
use Hilos\Cluster\SourceInterestMesh;
use Hilos\Cluster\Tls\ClusterTlsConfig;
use Hilos\Constants\EnvConstants;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Daemon\AgentRestartDoor;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Source\Interest\SourceReaderMap;
use Hilos\Core\Source\SourceChange;
use Hilos\Core\Sync\DTO\DbReHydrateSignalData;
use Hilos\Core\Sync\DTO\SyncSignalDataInterface;
use Hilos\Database\ReHydrateBarrierSink;
use Hilos\Database\ReHydrateRound;
use Hilos\Environment\Exception\EnvException;
use Hilos\Hilos;
use Hilos\Files\Upload\UploadMemoryMaster;
use Hilos\Socket\WebSocket\DTO\WebSocketFrameBinarySignalDTO;
use Hilos\HilosException;
use Hilos\ProtectedMode\ClusterProtectedMode;
use Hilos\ProtectedMode\DaemonProtectedModeExecutor;
use Hilos\ProtectedMode\DTO\ProtectedModeEnableSignalData;
use Hilos\ProtectedMode\DTO\ProtectedModeQuiesceData;
use Hilos\ProtectedMode\ProtectedModeCoordinator;
use Hilos\ProtectedMode\ProtectedModeMesh;
use Hilos\ProtectedMode\ProtectedModeNodesCollector;
use Hilos\ProtectedMode\ProtectedModeQuiesceRoster;
use Hilos\ProtectedMode\ProtectedModeSyncScheduler;
use Hilos\ProtectedMode\VerifierCircleSnapshot;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Socket\Client\ClientInterface;
use Hilos\Socket\Server\AbstractTlsServer;
use Hilos\Socket\SocketException;
use Hilos\Socket\Exception\SocketTlsHandshakeException;
use Hilos\Socket\Transport\TlsSocketTransport;
use Hilos\Socket\Transport\TlsContext;
use Hilos\Utils\Logger;
use SplQueue;
use WeakReference;
use Random\RandomException;
use Throwable;
use WeakMap;

/**
 * Inter-daemon peer transport: accepts peer links and dials out to form the mesh.
 *
 * Runs beside the worker and command servers on the daemon master loop. It
 * accepts inbound peer links from other nodes and dials on readiness and each
 * target's own deadline to form direct links: the configured seeds to join an
 * existing cluster, and — driven by a {@see ConnectionPolicy}, full mesh by
 * default — every peer learned through gossip, so two nodes that only know each
 * other transitively still raise a direct link. Both directions become framed
 * {@see PeerLink} connections that exchange hello/welcome, then ready/ack before
 * gossip membership. A replaced link drains its queued frames before closing.
 * Every socket operation here is non-blocking, so the master
 * loop is never stalled. The server owns the membership side effects: it merges
 * peers into the master registry and fans out roster/announce gossip so every
 * node's registry converges. Gossip carries membership only - which nodes exist,
 * their role, capabilities and address; whether a node is online is taken from
 * this node's own link to it and from nothing else (HIL-1059). Knowing a peer
 * (registry + gossip) stays separate from dialing one (the policy): a later
 * partial-mesh is a policy swap alone.
 *
 * On a clustered master the server also hosts the {@see ClusterCoordinator}: it
 * builds it at start, drives its tick each onTick, routes the consensus frames
 * (request-vote, vote-reply, heartbeat) into it, and serves as its
 * {@see ConsensusMesh} — the registry's liveness, which is this node's own links,
 * as the view of who is up, and the live links as an outbound channel. A slave
 * keeps no coordinator.
 *
 * Every link, accepted or dialed, is mutual TLS verified against the cluster's authorities, and
 * the name in the peer's certificate binds the hello/welcome handshake (HIL-1034).
 *
 * @extends AbstractTlsServer<PeerLink>
 */
final class PeerServer extends AbstractTlsServer implements
    LocalNodeAnnouncer,
    ConsensusMesh,
    PlacementMesh,
    ProtectedModeMesh,
    RtClaimMesh,
    RtSyncMesh,
    DbSyncMesh,
    SourceInterestMesh,
    ClientMesh,
    AgentSignalMesh
{
    /** @var float Seconds to wait before retrying a failed or dropped seed dial */
    private const float DIAL_RETRY_INTERVAL_SEC = 5.0;

    /** @var float Seconds a non-blocking connect may stay pending before it is abandoned */
    private const float CONNECT_TIMEOUT_SEC = 5.0;

    /** @var float Repair interval for full diagnostic reports, independent of browser subscriptions */
    private const float PROTECTED_MODE_NODE_REPORT_INTERVAL_SEC = 5.0;

    /** @var NodeIdentity Local node identity announced to peers */
    private NodeIdentity $localIdentity;

    /** @var list<PeerAddress> Seed peers to dial on join */
    private array $seeds;

    /** @var ClusterTlsConfig This node's certificate and the authorities it trusts, for both sides of a link */
    private ClusterTlsConfig $tls;

    private TlsContext $dialingContext;

    /** @var PeerMarkers What this node reads that every node must share, named on every handshake */
    private PeerMarkers $localMarkers;

    /** @var ConnectionPolicy Decides which known peers to dial a direct link to */
    private ConnectionPolicy $connectionPolicy;

    /** @var array<int, PeerDial> Per-seed dial state, indexed by seed list position */
    private array $seedDials = [];

    /** @var array<string, PeerDial> Dial-on-learn state for gossip-learned peers, keyed by node id */
    private array $peerDials = [];

    /** The master's existing base; dial handles and deadlines are private to this process. */
    private ?EventLoop $dialLoop = null;

    private ?ReadyWork $dialReconcileReady = null;

    /** @var array<string, ClusterNode> Initial or reload facts awaiting one bounded policy decision each */
    private array $pendingDialNodes = [];

    /** @var array<string, PeerLink> One application-frame route per remote node */
    private array $activeLinks = [];

    /** @var ?WeakMap<PeerLink, int> Exact accepted/dialed roster slots, private to this server */
    private ?WeakMap $peerSlots = null;

    /** @var ?SplQueue<Generator<int, void>> Broadcasts captured only during one synchronous announcement hook */
    private ?SplQueue $capturedBroadcasts = null;

    /** @var ?Closure(Generator<int, void>): void Existing master FIFO, absent for standalone servers */
    private ?Closure $sourceInterestDelivery = null;

    private ?string $deliveryNodeId = null;

    private ?PeerLink $deliveryLink = null;

    private ?int $deliverySlot = null;

    /** @var array<string, PeerLink> Certified links awaiting a ready verdict, one per remote node */
    private array $candidateLinks = [];

    /** @var ?ClusterCoordinator Consensus coordinator, built at start for a master node; null for a slave */
    private ?ClusterCoordinator $coordinator = null;

    /** Exact scheduler owner; the facade leadership slot may be independently replaced. */
    private ?ClusterCoordinator $boundCoordinator = null;

    /** @var ?ClusterPlacement Agent-placement coordinator, built at start on any clustered node; null when no worker executor is registered */
    private ?ClusterPlacement $placement = null;

    /** Exact native owner, independent of the replaceable facade placement slot. */
    private ?ClusterPlacement $boundPlacement = null;

    /** @var ?ClusterClock Master-local clock exchanges, registered at peer startup */
    private ?ClusterClock $clock = null;

    /** @var ?AgentRestartDoor Master receiving peer restart requests */
    private ?AgentRestartDoor $agentRestartDoor = null;

    /** @var ?UploadMemoryMaster The sole master ledger, injected alongside worker/command transports */
    private ?UploadMemoryMaster $uploadMemory = null;

    /** @var ?ProtectedModeCoordinator Protected-mode freeze handler, registered by the leader-orchestration slice; null until then */
    private ?ProtectedModeCoordinator $protectedMode = null;

    /** @var array<string, PeerProtectedModeNodeReportDTO> Master-private inbox, discarded with each working link incarnation */
    private array $protectedModeNodeReports = [];

    /** @var array<string, PeerLink> Working link generations at the last diagnostic send, owned only by this server */
    private array $protectedModeReportLinks = [];

    /** @var ?PeerProtectedModeNodeReportDTO Last local transport snapshot; dies with this peer server */
    private ?PeerProtectedModeNodeReportDTO $lastProtectedModeNodeReport = null;

    /** @var ?float Last diagnostic send time in seconds, null before the first pass */
    private ?float $lastProtectedModeNodeReportAt = null;

    /** @var ?string Last recognized leader, used only to invalidate cached round authority */
    private ?string $protectedModeReportLeaderId = null;

    /** @var ?int Last inspected consensus term, used only to invalidate cached round authority */
    private ?int $protectedModeReportTerm = null;

    /** @var array<string, PeerLink> Connection on which each outstanding slave snapshot was requested */
    private array $protectedModeSyncLinks = [];

    /** @var array<string, string> Request ids paired with the connection above */
    private array $protectedModeSyncIds = [];

    /** @var ?PeerLink Current leader connection that supplied the slave's latest snapshot */
    private ?PeerLink $protectedModeSyncLeaderLink = null;

    /** @var ?string Latest incoming request id, tied to the leader connection */
    private ?string $protectedModeIncomingSyncId = null;

    /** @var ?ReHydrateBarrierSink Where node answers to a database re-hydrate announcement are credited */
    private ?ReHydrateBarrierSink $reHydrateBarrier = null;

    /**
     * @var SourceReaderMap What RT collections each other node reads, as that node reported it.
     *
     * The node-level twin of the map the daemon master keeps for its workers, and the same class
     * because the two levels of one fan-out ask the same question. This is the half of it that
     * lives out here rather than in the daemon: the answer arrives on a link, and what it decides
     * is whether a frame is worth a hop ({@see broadcastToNodesHolding()}).
     */
    private SourceReaderMap $nodeReaderMap;

    /**
     * @var list<string> RT collections this node last told the mesh it reads.
     *
     * Kept so a peer that handshakes later hears it too. The announcement is a broadcast, so it
     * only ever reaches nodes already linked; a node that joins afterwards would otherwise know
     * nothing of this one's interest and would filter every frame away from it, and nothing would
     * ask again until the interest happened to change.
     */
    private array $announcedRtInterest = [];

    /**
     * @var list<string> DB collections this node last told the mesh it reads.
     *
     * Kept beside the runtime list above and for the same reason: a peer that handshakes later
     * would otherwise filter every database frame away from this node.
     */
    private array $announcedDbInterest = [];

    /**
     * @param string $host Host to bind the peer listener
     * @param int $port Port to bind the peer listener
     * @param NodeIdentity $localIdentity Local node identity to announce to peers
     * @param list<PeerAddress> $seeds Seed peers to dial on join (empty for a bootstrap node)
     * @param ClusterTlsConfig $tls This node's certificate and the authorities a peer certificate must be signed by
     * @param PeerMarkers $localMarkers This node's markers, named on every handshake and judged against the peer's
     * @param ?ConnectionPolicy $connectionPolicy Policy choosing which known peers to dial; full mesh when null
     * @param ?AgentRestartDoor $agentRestartDoor Master receiving peer restart requests
     */
    public function __construct(
        string $host,
        int $port,
        NodeIdentity $localIdentity,
        array $seeds,
        ClusterTlsConfig $tls,
        PeerMarkers $localMarkers,
        ?ConnectionPolicy $connectionPolicy = null,
        ?AgentRestartDoor $agentRestartDoor = null,
    ) {
        parent::__construct($host, $port, $tls->certificateFile, $tls->trustFile);

        $this->localIdentity = $localIdentity;
        $this->seeds = $seeds;
        $this->tls = $tls;
        $this->localMarkers = $localMarkers;
        $this->connectionPolicy = $connectionPolicy ?? new FullMeshConnectionPolicy();
        $this->agentRestartDoor = $agentRestartDoor;
        $this->nodeReaderMap = new SourceReaderMap();
    }

    /**
     * Preloads both TLS roles before this server starts accepting or dialing.
     *
     * @throws EnvException When the TLS input budget is invalid
     * @throws NativeEventUnavailableException When the required native TLS API is unavailable
     */
    public function prepareTlsContexts(): void
    {
        parent::prepareTlsContexts();
        $this->dialingContext ??= TlsContext::dialing($this->tls->certificateFile, $this->tls->trustFile);
    }

    /**
     * Registers as the local-node announcer and, on a master, starts consensus.
     *
     * A clustered master builds its {@see ClusterCoordinator} here and installs it as
     * the leadership seam, so election and quorum results begin flowing the moment
     * the listener is open; a slave takes no part in consensus and keeps none.
     *
     * @throws ClusterConfigurationException When the master consensus config is missing or invalid
     * @throws EnvException When a consensus or failover env value cannot be read
     * @throws EventLoopException When a native placement deadline cannot be armed
     */
    protected function onStart(): void
    {
        Hilos::$cluster?->registerLocalAnnouncer($this);

        if ($this->localIdentity->role === NodeRole::Master && Hilos::$cluster !== null) {
            $this->coordinator = new ClusterCoordinator(
                ClusterConsensusConfig::fromEnv($this->localIdentity),
                $this,
                Hilos::$cluster->leadershipObserver(),
            );
            Hilos::$cluster->registerLeadership($this->coordinator);
            $this->bindConsensusEventLoop();
        }

        // Agent placement runs on every clustered node: a master issues placements and
        // tracks them, a data-plane node executes the ones it is handed. The worker server
        // registers itself as the executor before the loop, so it is available here.
        $executor = Hilos::$cluster?->placementExecutor();
        if ($executor !== null) {
            $this->placement = new ClusterPlacement(
                $this->localIdentity->nodeId,
                $this,
                $executor,
                Hilos::$cluster->placementObserver(),
                Hilos::$env[EnvConstants::CLUSTER_FAILOVER_GRACE_MS]->int(),
                Hilos::$env[EnvConstants::CLUSTER_SLAVE_WORK_GRACE_MS]->int(),
                Hilos::$env[EnvConstants::CLUSTER_PLACEMENT_ACK_TIMEOUT_MS]->int(),
                policy: Hilos::$cluster->placementPolicy(),
                restartSink: Hilos::$cluster->placementRestartSink(),
            );
            Hilos::$cluster->registerPlacement($this->placement);
            $this->reconcilePlacementEventLoop();
            // The placement coordinator doubles as the router's read-only placement lookup, so
            // cross-node signal routing (HIL-180) can ask where a placed agent lives.
            Hilos::$cluster->registerWorkerPlacement($this->placement);
        }

        // Every clustered node builds one protected-mode coordinator: the leader orchestrates the
        // two-phase freeze, a follower reacts to it, and this server is its outbound peer port. It
        // routes the freeze frames here and drives its leader-side role from the daemon's leadership
        // hooks; a node that never mounted the runtime item simply writes nothing.
        if (Hilos::$cluster !== null) {
            $protectedMode = new ClusterProtectedMode(
                $this->localIdentity->nodeId,
                $this,
                new DaemonProtectedModeExecutor(),
            );
            $this->registerProtectedMode($protectedMode);
            // The same object fills both facade slots: it is what an initiator asks for a freeze
            // and what the daemon drives through its leadership transitions.
            Hilos::$cluster->registerProtectedMode($protectedMode);
            Hilos::$cluster->registerProtectedModeLeadership($protectedMode);

            $this->clock = new ClusterClock(function (): array {
                $links = [];
                foreach ($this->linkedNodeIds() as $nodeId) {
                    $link = $this->activeLinkToNode($nodeId);
                    if ($link !== null) {
                        $links[$nodeId] = $link;
                    }
                }
                return $links;
            });
            Hilos::$cluster->registerClock($this->clock);
        }

        Logger::info("Peer server listening as node {$this->localIdentity->nodeId}");
        $this->logDeclaredCapacity();
    }

    /**
     * Writes what capacity this node declares for placed work, or that it declares none and so
     * takes none (HIL-445, HIL-448) — the one place an operator sees which nodes carry work.
     */
    private function logDeclaredCapacity(): void
    {
        $capacities = NodeCapacities::fromTags($this->localIdentity->capabilities)->capacities();
        if ($capacities === []) {
            Logger::info("Node {$this->localIdentity->nodeId} declares no capacity: it accepts no placed work"
                . ' (CLUSTER_NODE_CAPABILITIES has no key=value)');
            return;
        }

        $declared = [];
        foreach ($capacities as $key => $capacity) {
            $declared[] = $key . '=' . (string)$capacity;
        }

        Logger::info("Node {$this->localIdentity->nodeId} declares capacity [" . implode(', ', $declared) . '] and accepts placed work');
    }

    /**
     * Creates the accepting side of an inbound peer link, over TLS that demands the peer's certificate.
     *
     * @param resource $socket Accepted peer socket
     * @return ClientInterface Peer link awaiting a hello
     * @throws EnvException When the socket read buffer env value is missing or invalid
     * @throws SocketTlsHandshakeException When the TLS server has not prepared its certificates
     */
    protected function onCreateClient($socket): ClientInterface
    {
        return new PeerLink($socket, $this, $this->localIdentity, dialer: false, transport: $this->createTransport($socket));
    }

    /**
     * Records membership before a handshake can make the link addressable. Roster slots
     * are never renumbered, and a removed slot cannot authorize a replacement connection.
     *
     * @param ClientInterface $client Newly appended accepted or dialed peer
     */
    protected function attachClient(ClientInterface $client): void
    {
        $slot = array_key_last($this->clients);
        if ($client instanceof PeerLink && $slot !== null && $this->clients[$slot] === $client) {
            $this->peerSlots ??= new WeakMap();
            $this->peerSlots[$client] = $slot;
        }
        parent::attachClient($client);
    }

    /**
     * Get server name for logging.
     *
     * @return string Server name
     */
    public function getServerName(): string
    {
        return 'Peer Server';
    }

    /**
     * Names this node's markers to the links it owns, which send them and judge the peer's by them.
     *
     * @return PeerMarkers Markers read once at the start of the daemon
     */
    public function localMarkers(): PeerMarkers
    {
        return $this->localMarkers;
    }

    /**
     * Reads the accepted name when a handshake is formed, including on links predating a reload.
     * Without a cluster registry, the server keeps the identity supplied at construction.
     *
     * @return string Current accepted local name, or the supplied name when the registry is unavailable
     */
    public function localNodeName(): string
    {
        $registry = $this->registry();
        if ($registry === null) {
            return $this->localIdentity->name;
        }

        return $this->onlineNodeById($registry, $this->localIdentity->nodeId)?->name ?? $this->localIdentity->name;
    }

    /**
     * Services legacy link timers, then only ready consensus work; dials have their own cues.
     *
     * The coordinator dispatches after the links are serviced so any request-vote,
     * vote-reply, or heartbeat that arrived this iteration is already folded in
     * before its ready role duty runs. Registry mutations have already updated quorum.
     *
     * @throws RandomException When the secure random source refuses a handshake secret
     * @throws HilosException When native consensus arming or the project's leadership/quorum duties fail
     */
    public function onTick(): void
    {
        parent::onTick();

        $this->bindConsensusEventLoop();
        if ($this->coordinator !== null && $this->registry() === null) {
            $this->coordinator->noteMembershipChanged();
        }
        try {
            $this->coordinator?->dispatchReady();
        } catch (Throwable $failure) {
            $this->coordinator?->detachEventLoop();
            throw $failure;
        }
        try {
            $this->reconcilePlacementEventLoop();
            $this->placement?->dispatchReady();
        } catch (Throwable $failure) {
            $this->placement?->detachEventLoop();
            throw $failure;
        }
        $now = microtime(true);
        if ($this->protectedMode instanceof ProtectedModeSyncScheduler) {
            $this->protectedMode->dispatchSlaveSyncReady();
        }
        $this->tickProtectedModeNodeReports($now);
    }

    /**
     * Announces a planned graceful-leave before the transport begins shutting down.
     *
     * Broadcasts a {@see PeerNodeLeavingDTO} so peers can tell an orderly departure
     * (announced) from a crash (silence) and skip the failover panic. A leaving leader
     * names its most-recently-heard follower as the successor, which campaigns at once
     * on receipt (raft TimeoutNow-style); the frame is fire-and-forget and the ordinary
     * HIL-339 election remains the fallback. Then defers to the base to stop accepting.
     */
    public function prepareShutdown(): void
    {
        $this->broadcastGracefulLeave();

        parent::prepareShutdown();
        $this->closePendingDials();
    }

    /**
     * Wait until everything handed to peers has been written before leaving.
     *
     * Browser closes reach remote agents as CONNECTION_CLOSE and a connection-index delta
     * after the links' write pass. Links remain open until master exit; a peer that stops
     * draining is bounded by the outbound cap or shutdown timeout (HIL-1207).
     *
     * @return bool True when no peer link has queued output
     */
    public function isReadyToShutdown(): bool
    {
        return !array_any($this->clients, static fn(PeerLink $link): bool => $link->hasPendingWrite());
    }

    /**
     * Broadcasts the graceful-leave frame to every handshaked peer.
     *
     * A leaving leader designates a successor so leadership transfers without an
     * election-timeout wait; a leaving non-leader names none and peers simply update
     * membership.
     */
    private function broadcastGracefulLeave(): void
    {
        $wasLeader = $this->coordinator?->amLeader() ?? false;
        $successor = $wasLeader ? $this->pickDesignatedSuccessor() : null;

        $this->broadcastToAllPeers(new PeerNodeLeavingDTO($this->localIdentity->nodeId, $wasLeader, $successor));

        Logger::info(
            "Announced graceful leave for node {$this->localIdentity->nodeId}"
            . ($successor !== null ? " with designated successor {$successor}" : ''),
        );
    }

    /**
     * Picks the successor a leaving leader designates: the most-recently-heard online
     * master peer, excluding self.
     *
     * Naming the freshest peer makes it the most likely to be reachable and current.
     * Returns null when no other online master is known, leaving the ordinary election
     * as the sole path to a new leader.
     *
     * @return ?string Successor node id, or null when no online master peer is known
     */
    private function pickDesignatedSuccessor(): ?string
    {
        $registry = $this->registry();
        if ($registry === null) {
            return null;
        }

        $best = null;
        foreach ($registry->snapshot() as $node) {
            if (!$node->online || $node->role !== NodeRole::Master || $node->nodeId === $this->localIdentity->nodeId) {
                continue;
            }

            if ($best === null || $node->lastSeen > $best->lastSeen) {
                $best = $node;
            }
        }

        return $best?->nodeId;
    }

    /**
     * Closes in-flight connecting sockets, then stops the server.
     *
     * @throws SocketException When the server socket close fails
     * @throws HilosException When a peer fails to announce its close
     */
    public function stop(): void
    {
        $this->closePendingDials();
        $this->detachEventLoop();
        parent::stop();
    }

    /** Terminal entrance retirement also cancels dial watches before closing their raw sockets. */
    public function closeListener(): void
    {
        $this->preparingShutdown = true;
        $this->closePendingDials();
        parent::closeListener();
    }

    /**
     * Binds dial work to the same base as accepted and promoted peer links.
     * Initial membership and seeds are reconciled once, never from the master tick.
     *
     * @param EventLoop $loop Master's existing event loop
     * @throws EventLoopException When a native placement deadline cannot be armed
     * @throws EnvException When early placement-view authority configuration cannot be read
     */
    public function attachEventLoop(EventLoop $loop): void
    {
        if ($this->preparingShutdown) {
            return;
        }
        if ($this->dialLoop === $loop) {
            $this->bindConsensusEventLoop();
            $this->reconcilePlacementEventLoop();
            if ($this->protectedMode instanceof ProtectedModeSyncScheduler) {
                $this->protectedMode->attachSlaveSyncEventLoop($loop);
            }
            return;
        }

        $this->detachEventLoop();
        $this->dialLoop = $loop;
        $this->bindConsensusEventLoop();
        $this->reconcilePlacementEventLoop();
        if ($this->protectedMode instanceof ProtectedModeSyncScheduler) {
            $this->protectedMode->attachSlaveSyncEventLoop($loop);
        }
        $this->dialReconcileReady = $loop->createReadyWork($this->advanceDialReconcile(...));
        foreach ($this->seeds as $index => $seed) {
            $this->seedDials[$index] ??= new PeerDial($seed);
        }
        $this->reconcilePeerDials();
        foreach ($this->allDials() as $dial) {
            $this->bindDial($dial);
            if ($dial->connecting && $dial->socket !== null) {
                $this->watchConnectingDial($dial);
            }
            $dial->ready->markReady();
        }
    }

    /** Releases exact peer owners before base cleanup; established links retain their lifecycle. */
    public function detachEventLoop(): void
    {
        $this->boundCoordinator?->detachEventLoop();
        $this->boundCoordinator = null;
        $this->boundPlacement?->detachEventLoop();
        $this->boundPlacement = null;
        if ($this->protectedMode instanceof ProtectedModeSyncScheduler) {
            $this->protectedMode->detachSlaveSyncEventLoop();
        }
        $this->releaseDialWork();
        $this->dialLoop = null;
    }

    /** @return bool Consensus duty to dispatch before the master waits */
    public function hasConsensusReadyWork(): bool
    {
        return $this->coordinator?->hasReadyWork() ?? false;
    }

    /** @return bool Placement work to dispatch before waiting; pending reports or future timers sleep */
    public function hasPlacementReadyWork(): bool
    {
        return $this->placement?->hasReadyWork() ?? false;
    }

    /**
     * Reconciles the exact current owner and early-view authority before guarded work or wait.
     * Shutdown drain keeps existing duties without binding a new owner or renewing its waits.
     *
     * @throws EventLoopException When an original placement deadline cannot be armed
     * @throws EnvException When early-view authority configuration cannot be read
     */
    public function reconcilePlacementEventLoop(): void
    {
        if (!$this->preparingShutdown && $this->dialLoop !== null) {
            if ($this->boundPlacement !== $this->placement) {
                $this->boundPlacement?->detachEventLoop();
                $this->boundPlacement = $this->placement;
            }
            $this->boundPlacement?->attachEventLoop($this->dialLoop);
        }
        $this->placement?->reconcileAuthority();
    }

    /** Reconciles the private current owner, including late creation and same-base replacement. */
    private function bindConsensusEventLoop(): void
    {
        if ($this->preparingShutdown || $this->dialLoop === null) {
            return;
        }
        if ($this->boundCoordinator !== $this->coordinator) {
            $this->boundCoordinator?->detachEventLoop();
            $this->boundCoordinator = $this->coordinator;
        }
        $this->boundCoordinator?->attachEventLoop($this->dialLoop);
    }

    /** Dial retirement is separate from consensus duties still owed during shutdown drain. */
    private function releaseDialWork(): void
    {
        $this->dialReconcileReady?->close();
        $this->dialReconcileReady = null;
        $this->pendingDialNodes = [];
        foreach ($this->allDials() as $dial) {
            $this->cancelDialWait($dial);
            $dial->ready?->close();
            $dial->ready = null;
        }
    }

    /** Releases every dial cue before closing pending sockets; established links keep their lifecycle. */
    private function closePendingDials(): void
    {
        $this->releaseDialWork();
        if ($this->protectedMode instanceof ProtectedModeSyncScheduler) {
            $this->protectedMode->detachSlaveSyncEventLoop();
        }
        foreach ($this->allDials() as $dial) {
            if ($dial->socket !== null) {
                socket_close($dial->socket);
                $dial->socket = null;
            }
            $dial->connecting = false;
        }
    }

    /** @param PeerDial $dial Target whose coalesced owner portion is bound once */
    private function bindDial(PeerDial $dial): void
    {
        if ($this->preparingShutdown || $this->dialLoop === null || $dial->ready !== null) {
            return;
        }
        $dial->ready = $this->dialLoop->createReadyWork(fn() => $this->runDialReady($dial));
    }

    /**
     * Contains a target's failure while preserving the node-wide entropy refusal.
     *
     * @param PeerDial $dial Ready target, never the whole registry
     * @throws RandomException When the secure source refuses the peer handshake
     */
    private function runDialReady(PeerDial $dial): void
    {
        if ($this->preparingShutdown) {
            return;
        }
        try {
            $this->driveDial($dial, self::dialNow());
        } catch (RandomException $exception) {
            $this->discardFailedDial($dial);
            throw $exception;
        } catch (Throwable $exception) {
            ClientReadFailureLog::write(
                $this->getServerName(),
                ClientReadFailureLog::READER_EVENT_LOOP,
                $exception,
                hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND,
            );
            $this->reportContainedFailure(new ContainedFailure(
                MasterFailureUnit::CONNECTION,
                $this->getServerName() . ' ' . $dial->address->toString(),
                $exception,
            ));
            $this->discardFailedDial($dial);
        }
    }

    /** @param PeerDial $dial Failed target whose socket or promoted client still has one owner */
    private function discardFailedDial(PeerDial $dial): void
    {
        if ($dial->link !== null) {
            try {
                $this->dropClient($dial->link);
            } catch (Throwable) {
                // The original failure remains the one reported by the owner guard.
            }
            return;
        }
        $this->abortDial($dial, self::dialNow());
    }

    /** @return float Monotonic seconds used only for process-local dial waits */
    private static function dialNow(): float
    {
        return hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND;
    }

    /**
     * Turns membership knowledge into dial intent for the mesh.
     *
     * Reads the master registry — the source of truth for who is in the cluster —
     * and, for every known node the {@see ConnectionPolicy} selects, lazily opens a
     * dial toward its advertised address keyed by node id. This is the single point
     * where knowing a peer becomes dialing it, so a partial-mesh topology is a
     * policy swap here and nothing else; the registry and gossip stay untouched.
     * The node id is stamped on the dial up front so an inbound link to the same
     * peer suppresses the outbound dial through {@see driveDial()}. A peer with no
     * advertised address is left to reach us inbound. When a known peer re-handshakes
     * on a changed address (a restart on a new port, a NAT/DHCP change), its dial is
     * refreshed in place through {@see refreshPeerDial()} so the stale endpoint is not
     * dialed forever.
     */
    private function reconcilePeerDials(): void
    {
        $registry = $this->registry();
        if ($registry === null) {
            return;
        }

        foreach ($registry->snapshot() as $node) {
            if ($this->dialReconcileReady === null) {
                // Isolated fixtures may inspect policy selection without opening a native loop.
                $this->reconcilePeerDial($node);
            } else {
                $this->pendingDialNodes[$node->nodeId] = $node;
            }
        }
        if ($this->pendingDialNodes !== []) {
            $this->dialReconcileReady->markReady();
        }
    }

    /** Consumes one initial/reload fact; newer direct facts remove their queued predecessor. */
    private function advanceDialReconcile(): void
    {
        $nodeId = key($this->pendingDialNodes);
        if ($nodeId === null || $this->preparingShutdown) {
            return;
        }
        $this->reconcilePeerDial($this->pendingDialNodes[$nodeId]);
        if ($this->pendingDialNodes !== []) {
            $this->dialReconcileReady?->markReady();
        }
    }

    /** @param ClusterNode $node Changed membership fact, or one entry of the initial snapshot */
    private function reconcilePeerDial(ClusterNode $node): void
    {
        unset($this->pendingDialNodes[$node->nodeId]);
        if ($this->preparingShutdown || $node->address === null) {
            return;
        }

        $dial = $this->peerDials[$node->nodeId] ?? null;
        if ($dial !== null) {
            if ($dial->address->toString() !== $node->address->toString()) {
                $this->refreshPeerDial($dial, $node->address);
            }
            return;
        }
        if (!$this->connectionPolicy->shouldDial($this->localIdentity, $node)) {
            return;
        }

        $dial = new PeerDial($node->address);
        $dial->remoteNodeId = $node->nodeId;
        $this->peerDials[$node->nodeId] = $dial;
        $this->bindDial($dial);
        $dial->ready?->markReady();
    }

    /**
     * Re-points an existing dial at a peer's new advertised address.
     *
     * An address change is not a failure: any in-flight connect to the old endpoint is
     * torn down and the backoff is cleared so the new address is dialed promptly, while
     * a live established link is left untouched — only its future re-dial target moves.
     *
     * @param PeerDial $dial Dial to re-point
     * @param PeerAddress $address New advertised address to dial
     */
    private function refreshPeerDial(PeerDial $dial, PeerAddress $address): void
    {
        $this->cancelDialWait($dial);
        $dial->address = $address;

        if ($dial->connecting && $dial->socket !== null) {
            socket_close($dial->socket);
            $dial->socket = null;
            $dial->connecting = false;
        }

        $dial->nextAttemptAt = 0.0;
        $dial->ready?->markReady();
    }

    /**
     * Returns every active dial, seed and dial-on-learn alike.
     *
     * @return list<PeerDial> All dials the server is driving
     */
    private function allDials(): array
    {
        return array_merge(array_values($this->seedDials), array_values($this->peerDials));
    }

    /**
     * Advances one target on a link fact, WRITE readiness, or its own deadline.
     *
     * @param PeerDial $dial Seed dial state
     * @param float $now Current monotonic seconds
     */
    private function driveDial(PeerDial $dial, float $now): void
    {
        if ($this->preparingShutdown) {
            return;
        }
        // Our own dialed link is still alive (handshaking or established): leave it be.
        // A link discarded by the duplicate collapse is marked to close, so it counts as dropped.
        if ($dial->link !== null) {
            if (in_array($dial->link, $this->clients, true) && !$dial->link->shouldClose()) {
                return;
            }
            // TLS came up but this attempt closed before welcome. The peer's log may clarify why.
            if ($dial->link->closedUnwelcomed() && !$dial->unwelcomedReported) {
                Logger::warning(
                    "Peer {$dial->address->host}:{$dial->address->port} closed before welcome; check the peer log",
                );
                $dial->unwelcomedReported = true;
            }
            $dial->link = null;
            $dial->nextAttemptAt = $now + self::DIAL_RETRY_INTERVAL_SEC;
        }

        // The peer at this seed already reaches us over another link (an inbound dial, or the
        // link that won a duplicate collapse): do not open a second connection to the same node.
        if ($dial->remoteNodeId !== null && $this->hasHandshakedLinkToNode($dial->remoteNodeId)) {
            $this->clearConnectingDial($dial);
            return;
        }

        if ($dial->connecting) {
            $this->completeConnectingDial($dial, $now);
            return;
        }

        if ($now >= $dial->nextAttemptAt) {
            $this->beginDial($dial, $now);
        } else {
            $this->armDialDeadline($dial, $dial->nextAttemptAt);
        }
    }

    /**
     * Opens a non-blocking connect to a seed, promoting immediately if it lands.
     *
     * @param PeerDial $dial Seed dial state
     * @param float $now Current monotonic seconds
     */
    private function beginDial(PeerDial $dial, float $now): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            $this->abortDial($dial, $now);
            return;
        }

        $this->cancelDialWait($dial);
        $dial->socket = $socket;
        socket_set_nonblock($socket);

        // The pending connect is completed only by WRITE readiness or its own deadline.
        // warning-suppressed: the result is the branch condition, socket_last_error is read below
        if (@socket_connect($socket, $dial->address->host, $dial->address->port)) {
            $this->promoteDial($dial, $socket, $now);
            return;
        }

        $error = socket_last_error($socket);
        if (in_array($error, [SOCKET_EINPROGRESS, SOCKET_EALREADY, SOCKET_EWOULDBLOCK, SOCKET_EINTR], true)) {
            socket_clear_error($socket);
            $dial->socket = $socket;
            $dial->connecting = true;
            $dial->connectStartedAt = $now;
            $this->watchConnectingDial($dial);
            $this->armDialDeadline($dial, $now + self::CONNECT_TIMEOUT_SEC);
            return;
        }

        $this->abortDial($dial, $now);
    }

    /**
     * Completes a ready connect under the target guard; a pending socket never self-queues.
     *
     * @param PeerDial $dial Seed dial state
     * @param float $now Current monotonic seconds
     */
    private function completeConnectingDial(PeerDial $dial, float $now): void
    {
        $socket = $dial->socket;
        if ($socket === null) {
            $dial->connecting = false;
            return;
        }

        if (($now - $dial->connectStartedAt) >= self::CONNECT_TIMEOUT_SEC) {
            $this->abortDial($dial, $now);
            return;
        }

        if (!$dial->connectReady) {
            $this->armDialDeadline($dial, $dial->connectStartedAt + self::CONNECT_TIMEOUT_SEC);
            return;
        }
        $dial->connectReady = false;

        // warning-suppressed: false and the socket errno are checked before interpreting SO_ERROR
        $error = @socket_get_option($socket, SOL_SOCKET, SO_ERROR);
        if ($error === false) {
            $error = socket_last_error($socket);
            if (!in_array($error, [SOCKET_EINTR, SOCKET_EAGAIN, SOCKET_EWOULDBLOCK], true)) {
                $this->abortDial($dial, $now);
                return;
            }
        }
        if (in_array($error, [SOCKET_EINTR, SOCKET_EAGAIN, SOCKET_EWOULDBLOCK, SOCKET_EINPROGRESS, SOCKET_EALREADY], true)) {
            socket_clear_error($socket);
            $this->watchConnectingDial($dial);
            return;
        }
        if ($error !== 0) {
            $this->abortDial($dial, $now);
            return;
        }

        $this->promoteDial($dial, $socket, $now);
    }

    /** @param PeerDial $dial Pending connect whose WRITE notification is consumed once */
    private function watchConnectingDial(PeerDial $dial): void
    {
        if ($this->preparingShutdown || $this->dialLoop === null || $dial->socket === null) {
            return;
        }
        $socket = $dial->socket;
        $generation = $dial->generation;
        $this->dialLoop->registerWrite($socket, function () use ($dial, $socket, $generation): void {
            if ($generation !== $dial->generation || $dial->socket !== $socket) {
                return;
            }
            $this->dialLoop->unregisterWrite($socket);
            $dial->connectReady = true;
            $dial->ready?->markReady();
        });
    }

    /**
     * @param PeerDial $dial Target waiting on one unchanged connect or retry deadline
     * @param float $deadline Absolute monotonic seconds
     */
    private function armDialDeadline(PeerDial $dial, float $deadline): void
    {
        if ($this->preparingShutdown || $this->dialLoop === null) {
            return;
        }
        $generation = $dial->generation;
        $dial->timer ??= $this->dialLoop->createTimer(function () use ($dial, $generation): void {
            if ($generation === $dial->generation) {
                $dial->ready?->markReady();
            }
        });
        $dial->timer->armAt($deadline);
    }

    /** @param PeerDial $dial Target whose old completion facts must not survive a transition */
    private function cancelDialWait(PeerDial $dial): void
    {
        ++$dial->generation;
        if ($dial->socket !== null) {
            $this->dialLoop?->unregisterWrite($dial->socket);
        }
        $dial->timer?->close();
        $dial->timer = null;
        $dial->ready?->cancel();
        $dial->connectReady = false;
    }

    /** @param PeerDial $dial Pending socket whose native ownership is released before close */
    private function clearConnectingDial(PeerDial $dial): void
    {
        $this->cancelDialWait($dial);
        if ($dial->socket !== null) {
            socket_close($dial->socket);
            $dial->socket = null;
        }
        $dial->connecting = false;
    }

    /**
     * Wraps a connected socket in a dialing peer link and starts the handshake.
     *
     * @param PeerDial $dial Seed dial state
     * @param resource|object $socket Connected socket
     * @param float $now Current monotonic seconds
     */
    private function promoteDial(PeerDial $dial, $socket, float $now): void
    {
        $this->cancelDialWait($dial);
        socket_set_nonblock($socket);

        try {
            $link = new PeerLink(
                $socket,
                $this,
                $this->localIdentity,
                dialer: true,
                transport: TlsSocketTransport::dialing($socket, $this->dialingContext),
            );
        } catch (EnvException $e) {
            Logger::error("Failed to open dialed peer link: {$e->getMessage()}");
            $this->abortDial($dial, $now);
            return;
        }

        $dial->socket = null;
        $dial->connecting = false;
        $dial->link = $link;
        $this->addClient($link);
        $this->attachClient($link);
        $link->startHandshake();
        Logger::info($dial->remoteNodeId !== null
            ? "Dialed peer {$dial->remoteNodeId}"
            : "Dialed seed {$dial->address->host}:{$dial->address->port}");
    }

    /**
     * Closes a failed connect socket and schedules a retry.
     *
     * @param PeerDial $dial Seed dial state
     * @param float $now Current monotonic seconds
     */
    private function abortDial(PeerDial $dial, float $now): void
    {
        $this->clearConnectingDial($dial);
        $dial->nextAttemptAt = $now + self::DIAL_RETRY_INTERVAL_SEC;
        if (!$this->preparingShutdown) {
            $this->armDialDeadline($dial, $dial->nextAttemptAt);
        }
    }

    /**
     * Records a freshly handshaked peer, bootstraps it with the local roster, and
     * announces it to the other links.
     *
     * @param PeerLink $link Link that just completed its handshake
     * @param NodeIdentity $remote Remote node identity learned from the handshake
     * @throws PeerTransportException When the placement snapshot metadata is invalid
     * @throws EnvException When the leadership configuration is invalid
     * @throws HilosException When the observed membership changes quorum and its duties fail
     */
    public function onHandshakeComplete(PeerLink $link, NodeIdentity $remote): void
    {
        $this->activeLinks[$remote->nodeId] = $link;
        $this->wakeNodeDials($remote->nodeId);
        if ($this->protectedMode instanceof ProtectedModeSyncScheduler) {
            $this->protectedMode->noteSlaveSyncChanged();
        }
        $this->uploadMemory?->onPeerChanged();
        $registry = $this->registry();
        if ($registry === null) {
            return;
        }

        $now = microtime(true);
        $changed = $registry->merge($remote, true, $now);
        if ($changed) {
            $this->coordinator?->noteMembershipChanged();
            $this->reconcilePeerDial(ClusterNode::fromIdentity($remote, true, $now));
        }
        $this->sendRoster($link, $registry);

        if ($changed) {
            Logger::info("Peer joined: {$remote->nodeId} role={$remote->role->value}");
            $this->notifyJoined($remote, $now);
            $this->broadcastAnnounce(PeerNodeEntry::fromIdentity($remote), $link);
        }

        // Reconcile-on-rejoin: report the agents this node still hosts so a leader on the
        // other end can stop any that it has already re-placed elsewhere (a no-op otherwise).
        $this->placement?->onPeerHandshaked($remote->nodeId);
        if ($remote->role === NodeRole::Slave) {
            $this->protectedMode?->onSlaveConnected($remote->nodeId);
        }

        // Tell the peer what this node reads, so it starts sending those frames here (HIL-717).
        // The announcement is a broadcast and reached only the nodes linked at the time it was
        // made; this link is younger than all of them, so without this the peer would filter
        // every RT frame away from this node until its interest happened to change again.
        if ($this->announcedRtInterest !== [] || $this->announcedDbInterest !== []) {
            $link->sendFrame(new PeerSourceInterestDTO(
                $this->localIdentity->nodeId,
                $this->announcedRtInterest,
                $this->announcedDbInterest,
            ));
        }

        // Hand the peer the RT collections this node owns (HIL-586). Off the handshake and not
        // off the membership change for the same reason the line above is: the peer may be a
        // member already, but this link is what lets anything reach it. What of it actually goes
        // out is what that peer reads ({@see sendRtSnapshotToNode()}), and a peer this node has
        // yet to hear from asks again with its own announcement a moment later.
        // Only the peer's router row thaws here. Its replicas remain marked until a snapshot,
        // delta, or holder offer confirms them; the hand-over is requested just below.
        Hilos::$cluster?->rtSyncSink()?->noteNodeReachable($remote->nodeId);

        Hilos::$cluster?->rtSyncSink()?->handOverRtSnapshots($remote->nodeId);

        // And ask the peer for the collections this node holds nothing of (HIL-823). The
        // owner-driven hand-over above cannot cover the window in which a collection is claimed
        // by nobody - while a fleet of workers is being replaced, every neighbour holds its rows
        // and none of them owns it - so the node with the empty copy asks, and any holder may
        // answer. Last of the three frames on purpose: the answer is filtered by the reader map
        // the interest frame fills in, and the hand-over ahead of it is the cheaper way to the
        // same rows whenever an owner does exist.
        Hilos::$cluster?->rtSyncSink()?->askRtSnapshotsFromNode($remote->nodeId);

        // And the browser connections it holds (HIL-668), on exactly the same terms: without
        // them the peer resolves every one of this node's clients as unknown and answers them
        // locally, into nothing.
        Hilos::$cluster?->clientSignalSink()?->handOverConnections($remote->nodeId);

        // Nothing is handed over for the database - the peer reads the same one - but this is
        // where the window in which DB facts could not reach this node closes, so it is where
        // the rows it kept through that window stop being believed (HIL-670).
        Hilos::$cluster?->dbSyncSink()?->reReadAfterLink($remote->nodeId);
    }

    /**
     * Records the peer's node id on the seed dial that owns this link, if any.
     *
     * Inbound links have no dial; for a dialed link this lets the seed recognise
     * the peer later and stop re-dialing it once it is reachable another way.
     *
     * @param PeerLink $link Link that just handshaked
     * @param string $nodeId Remote node id learned from the handshake
     */
    public function stampDialRemote(PeerLink $link, string $nodeId): void
    {
        $dial = $this->dialForLink($link);
        if ($dial !== null) {
            $dial->remoteNodeId = $nodeId;
            // The target took this node's handshake to the end: a series of closes before
            // welcome, if one was running, is over.
            $dial->unwelcomedReported = false;
        }
    }

    /**
     * Reserves the certified candidate slot before any application frame can be sent on it.
     *
     * @param PeerLink $link Candidate connection
     * @param NodeIdentity $remote Identity certified by the peer transport
     * @throws PeerTransportException When a preferred candidate is already in progress
     * @throws EventLoopException When the superseded candidate's silence deadline cannot be armed
     */
    public function registerCandidate(PeerLink $link, NodeIdentity $remote): void
    {
        $existing = $this->candidateLinks[$remote->nodeId] ?? null;
        if ($existing !== null && $existing !== $link) {
            $dialedWins = PeerProtocol::dialedLinkWinsTieBreak($this->localIdentity->nodeId, $remote->nodeId);
            if ($existing->isDialer() === $dialedWins || $link->isDialer() !== $dialedWins) {
                throw new PeerTransportException("Peer candidate already in progress for {$remote->nodeId}");
            }
            if ($existing->isDialer() && $existing->certifiedPeerName() === $remote->nodeId) {
                $this->stampDialRemote($existing, $remote->nodeId);
            }
            $existing->discardAsDuplicate();
        }

        $this->candidateLinks[$remote->nodeId] = $link;
    }

    /** @param string $nodeId Remote node id */
    public function hasActiveLinkToNode(string $nodeId): bool
    {
        return $this->activeLinkToNode($nodeId) !== null;
    }

    /** @param string $nodeId Remote node id */
    public function hasPendingCandidateFor(string $nodeId): bool
    {
        return isset($this->candidateLinks[$nodeId]);
    }

    /**
     * @param string $nodeId Remote node id whose candidate sent a control frame out of order
     * @throws EventLoopException When the rejected candidate's silence deadline cannot be armed
     */
    public function rejectCandidateFor(string $nodeId): void
    {
        ($this->candidateLinks[$nodeId] ?? null)?->discardAsDuplicate();
    }

    /** @param string $nodeId Remote node id */
    public function allowOldLinkDrain(string $nodeId): void
    {
        $this->activeLinkToNode($nodeId)?->allowPeerDrain();
    }

    /**
     * Decides whether a candidate wins once the dialer has reported its old route.
     *
     * @param PeerLink $link Candidate link
     * @param string $nodeId Remote node id
     * @param bool $remoteHasActiveLink Whether the dialer has an old working route
     * @return bool Whether the candidate should become active
     */
    public function shouldKeepCandidate(PeerLink $link, string $nodeId, bool $remoteHasActiveLink): bool
    {
        if (!$remoteHasActiveLink || !$this->hasActiveLinkToNode($nodeId)) {
            return true;
        }

        return $link->isDialer() === PeerProtocol::dialedLinkWinsTieBreak($this->localIdentity->nodeId, $nodeId);
    }

    /**
     * Switches the route after the ack is queued or received, then drains the old stream.
     *
     * @param PeerLink $link Candidate link
     * @param NodeIdentity $remote Certified remote identity
     * @throws PeerTransportException When this link lost its candidate slot
     * @throws EnvException When leadership configuration is invalid
     * @throws HilosException When the observed membership changes quorum and its duties fail
     */
    public function activateCandidate(PeerLink $link, NodeIdentity $remote): void
    {
        if (($this->candidateLinks[$remote->nodeId] ?? null) !== $link) {
            throw new PeerTransportException("Peer candidate no longer current for {$remote->nodeId}");
        }

        $old = $this->activeLinkToNode($remote->nodeId);
        unset($this->candidateLinks[$remote->nodeId]);
        $link->activate($remote);
        $this->activeLinks[$remote->nodeId] = $link;
        $this->wakeNodeDials($remote->nodeId);
        if ($this->protectedMode instanceof ProtectedModeSyncScheduler) {
            $this->protectedMode->noteSlaveSyncChanged();
        }
        unset($this->protectedModeNodeReports[$remote->nodeId]);
        if ($old === null) {
            $this->onHandshakeComplete($link, $remote);
            return;
        }

        $old->beginDrain();
        $this->reconcilePeerDial(ClusterNode::fromIdentity($remote, true, microtime(true)));
        $this->uploadMemory?->onPeerChanged();
        Logger::info("Replaced duplicate peer link to {$remote->nodeId}");
        $registry = $this->registry();
        if ($registry !== null && $this->onlineNodeById($registry, $remote->nodeId) === null) {
            $this->onHandshakeComplete($link, $remote);
            return;
        }
        // The old transport's pending inbound report is canceled by its exact source guard.
        // Both writers therefore announce their last complete pair afresh after ready/ack,
        // including an empty pair. No cursor or full handshake hand-over moves to this link.
        $link->sendFrame(new PeerSourceInterestDTO(
            $this->localIdentity->nodeId,
            $this->announcedRtInterest,
            $this->announcedDbInterest,
        ));
        if ($remote->role === NodeRole::Slave) {
            $this->protectedMode?->onSlaveConnected($remote->nodeId);
        }
    }

    /**
     * Merges a received roster and re-announces every entry that changed the membership.
     *
     * A roster carries membership - which nodes exist, their role, capabilities and address -
     * and no liveness: a node is online here only by this node's own link to it (HIL-1059). Each
     * entry takes the one path {@see mergeEntry()} lays down, so an entry about a node this one
     * is linked to is dropped: that node told its membership itself. An entry that changed the
     * membership is announced to the other links, which is how a newcomer that came in through
     * one seed becomes known to every node.
     *
     * @param PeerLink $link Link the roster arrived on
     * @param PeerRosterDTO $roster Received roster
     * @throws HilosException When the observed membership changes quorum and its duties fail
     */
    public function onRosterReceived(PeerLink $link, PeerRosterDTO $roster): void
    {
        $registry = $this->registry();
        if ($registry === null) {
            return;
        }

        $now = microtime(true);
        foreach ($roster->nodes as $entry) {
            if ($this->mergeEntry($registry, $link, $entry, $now)) {
                $this->broadcastAnnounce($entry, $link);
            }
        }
    }

    /**
     * Merges a received announcement and stops it there.
     *
     * An announcement carries membership only - never liveness (HIL-1059) - and takes the one
     * path {@see mergeEntry()} lays down. The merged entry is never re-announced onward: the
     * node that made the change already told every peer it links to, so relaying only
     * re-amplifies it, and two nodes holding opposite views would echo the flip between them
     * without ever converging (HIL-746).
     *
     * @param PeerLink $link Link the announcement arrived on
     * @param PeerAnnounceDTO $announce Received announcement
     * @throws HilosException When the observed membership changes quorum and its duties fail
     */
    public function onAnnounceReceived(PeerLink $link, PeerAnnounceDTO $announce): void
    {
        $registry = $this->registry();
        if ($registry === null) {
            return;
        }

        $this->mergeEntry($registry, $link, $announce->node, microtime(true));
    }

    /**
     * Routes a received request-vote to the consensus coordinator, if one runs.
     *
     * A no-op on a slave (no coordinator) or before the coordinator is built; the
     * coordinator answers by sending a vote-reply back through this server's mesh.
     *
     * @param PeerRequestVoteDTO $frame Received request-vote frame
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
     */
    public function onRequestVote(PeerRequestVoteDTO $frame): void
    {
        $this->coordinator?->onRequestVote($frame);
    }

    /**
     * Routes a received vote-reply to the consensus coordinator, if one runs.
     *
     * @param PeerVoteReplyDTO $frame Received vote-reply frame
     * @throws HilosException Whatever the project's own leadership duties raise when this node wins a term
     */
    public function onVoteReply(PeerVoteReplyDTO $frame): void
    {
        $this->coordinator?->onVoteReply($frame);
    }

    /**
     * Routes a received heartbeat to the consensus coordinator, if one runs.
     *
     * @param PeerHeartbeatDTO $frame Received heartbeat frame
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
     */
    public function onHeartbeat(PeerHeartbeatDTO $frame): void
    {
        $this->coordinator?->onHeartbeat($frame);
    }

    /**
     * Reacts to a peer's announced graceful-leave.
     *
     * Marks the leaving node offline ahead of its link closing — a planned departure,
     * so membership converges at once rather than waiting for the socket to drop. Nothing
     * is fanned out: the leaving node sends this frame to every peer itself, so each peer
     * hears the leave from the node and not from a neighbour (HIL-1059). When this node is
     * the designated successor, triggers an immediate election so leadership transfers
     * without the election-timeout wait; the other followers keep waiting theirs, avoiding
     * a split vote. The ordinary HIL-339 election (driven by the link close's fast path)
     * remains the fallback.
     *
     * @param PeerNodeLeavingDTO $frame Received graceful-leave frame
     * @throws InvalidFormatException When a pending page recovery address is malformed
     * @throws RtActionsStateCollectionNullException When the cluster registry is unavailable
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
     * @throws HilosException When the observed membership changes quorum and its duties fail
     */
    public function onNodeLeaving(PeerNodeLeavingDTO $frame): void
    {
        $registry = $this->registry();
        if ($registry !== null) {
            $leaving = $this->onlineNodeById($registry, $frame->nodeId);
            $now = microtime(true);
            if ($leaving !== null && $registry->markOffline($frame->nodeId, $now)) {
                $this->coordinator?->noteMembershipChanged();
                $identity = NodeIdentity::of($leaving->nodeId, $leaving->name, $leaving->role, $leaving->capabilities, $leaving->address);
                $this->reconcilePeerDial(ClusterNode::fromIdentity($identity, false, $now));
                $this->wakeNodeDials($frame->nodeId);
                $this->notifyLeft($identity, $now);
            }
        }

        if ($frame->designatedSuccessor === $this->localIdentity->nodeId) {
            $this->coordinator?->triggerDesignatedElection();
        }
    }

    /**
     * Routes a received place-agent request to the placement coordinator to launch locally.
     *
     * A no-op before the coordinator is built; the coordinator answers the requesting
     * leader with a status frame through this server's mesh.
     *
     * @param PeerLink $link Link the request arrived on
     * @param PeerPlaceAgentDTO $frame Received place-agent frame
     * @throws InvalidFormatException When an abandoned start has an invalid stop completion address
     * @throws EventLoopException When the existing executor cannot arm an abandoned start stop deadline
     */
    public function onPlaceAgentReceived(PeerLink $link, PeerPlaceAgentDTO $frame): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($from !== null) {
            $this->placement?->onPlaceAgent($from, $frame);
        }
    }

    /**
     * Routes a received stop-agent request to the placement coordinator to stop locally.
     *
     * @param PeerLink $link Link the request arrived on
     * @param PeerStopAgentDTO $frame Received stop-agent frame
     * @throws InvalidFormatException When a tracked delivery has an invalid completion address
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
     */
    public function onStopAgentReceived(PeerLink $link, PeerStopAgentDTO $frame): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($from !== null) {
            $this->placement?->onStopAgent($from, $frame);
        }
    }

    /**
     * Routes a received agent-status reply to the placement coordinator to track.
     *
     * @param PeerLink $link Link the reply arrived on
     * @param PeerAgentStatusDTO $frame Received agent-status frame
     * @throws InvalidFormatException When a tracked delivery has an invalid completion address
     * @throws RtActionsStateCollectionNullException When the cluster registry is unavailable
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
     */
    public function onAgentStatusReceived(PeerLink $link, PeerAgentStatusDTO $frame): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($from !== null) {
            $this->placement?->onAgentStatus($from, $frame);
        }
    }

    /**
     * Routes a received placement query to the placement coordinator to answer.
     *
     * @param PeerLink $link Link the query arrived on
     * @throws PeerTransportException When the placement snapshot metadata is invalid
     */
    public function onPlacementQueryReceived(PeerLink $link): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($from !== null) {
            $this->placement?->onPlacementQuery($from);
        }
    }

    /**
     * Routes a received placement release to the placement coordinator, so a node that answered
     * to the sender forgets it and fences its placed work.
     *
     * @param PeerLink $link Link the release arrived on
     * @throws EventLoopException When a native placement deadline cannot be armed
     */
    public function onPlacementReleaseReceived(PeerLink $link): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($from !== null) {
            $this->placement?->onPlacementRelease(
                $from,
                hrtime(true) / TimeConstants::NS_PER_MILLISECOND / TimeConstants::MS_PER_SECOND,
            );
            if ($this->protectedMode instanceof ProtectedModeSyncScheduler) {
                $this->protectedMode->noteSlaveSyncChanged();
            }
        }
    }

    /**
     * Routes a received placement request to the placement coordinator for the leader to place.
     *
     * @param PeerLink $link Link the request arrived on
     * @param PeerPlacementRequestDTO $frame Received placement-request frame
     * @throws EventLoopException When a native placement deadline cannot be armed
     */
    public function onPlacementRequestReceived(PeerLink $link, PeerPlacementRequestDTO $frame): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($from !== null) {
            $this->placement?->onPlacementRequest($from, $frame);
        }
    }

    /**
     * Hands an authenticated peer restart request to this node's master.
     *
     * @param PeerLink $link Link the frame arrived on
     * @param PeerAgentRestartDTO $frame Received restart request
     * @throws EnvException When the leader id cannot be read
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
     */
    public function onAgentRestartReceived(PeerLink $link, PeerAgentRestartDTO $frame): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($from === null) {
            return;
        }
        if ($frame->inPlace && $from !== $this->placement?->knownLeaderId()) {
            Logger::warning("Dropping in-place agent restart from non-leader node '{$from}'");
            return;
        }
        $this->agentRestartDoor?->requestPeerAgentRestart($frame);
    }

    /**
     * Routes a received placement report to the placement coordinator to rebuild its view.
     *
     * @param PeerLink $link Link the report arrived on
     * @param PeerPlacementReportDTO $frame Received placement-report frame
     * @throws InvalidFormatException When a tracked delivery has an invalid completion address
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
     */
    public function onPlacementReportReceived(PeerLink $link, PeerPlacementReportDTO $frame): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($from !== null) {
            $this->placement?->onPlacementReport($from, $frame);
        }
    }

    /**
     * Routes a received placement view to the placement coordinator to answer lookups from.
     *
     * @param PeerLink $link Link the view arrived on
     * @param PeerPlacementViewDTO $frame Received placement-view frame
     * @throws EnvException When placement source confirmation cannot read cluster mode
     * @throws PeerTransportException When the placement snapshot metadata is invalid
     */
    public function onPlacementViewReceived(PeerLink $link, PeerPlacementViewDTO $frame): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($from !== null) {
            $this->placement?->onPlacementView($from, $frame);
        }
    }

    /**
     * Routes a received placement verdict to the placement coordinator to answer a waiting ask.
     *
     * @param PeerLink $link Link the verdict arrived on
     * @param PeerPlacementVerdictDTO $frame Received placement-verdict frame
     */
    public function onPlacementVerdictReceived(PeerLink $link, PeerPlacementVerdictDTO $frame): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($from !== null) {
            $this->placement?->onPlacementVerdict($from, $frame);
        }
    }

    /**
     * Routes a received claim report to the local claim sink for the leader to judge.
     *
     * The node id is taken from the frame rather than from the link, for the reason the RT sync
     * frames do it: what a node calls itself is what the map is keyed by everywhere else,
     * including on the leader folding in its own report, where there is no link to read.
     *
     * @param PeerLink $link Link the report arrived on
     * @param PeerRtClaimsDTO $frame Received RT-claims frame
     */
    public function onRtClaimsReceived(PeerLink $link, PeerRtClaimsDTO $frame): void
    {
        Hilos::$cluster?->rtClaimSink()?->applyRemoteRtClaims($frame->nodeId, $frame->claims);
    }

    /**
     * Routes a received claim query to the local claim sink so this node reports what it owns.
     *
     * @param PeerLink $link Link the query arrived on
     */
    public function onRtClaimsQueryReceived(PeerLink $link): void
    {
        $askerNodeId = $link->remoteIdentity()?->nodeId;
        if ($askerNodeId !== null) {
            Hilos::$cluster?->rtClaimSink()?->reportRtClaims($askerNodeId);
        }
    }

    /**
     * @param PeerLink $link Link the probe arrived on
     * @param PeerClockProbeDTO $frame Incoming correlated question
     */
    public function onClockProbeReceived(PeerLink $link, PeerClockProbeDTO $frame): void
    {
        if ($link->remoteIdentity()?->nodeId !== null && $this->clock !== null) {
            $link->sendFrame($this->clock->answer($frame));
        }
    }

    /**
     * @param PeerLink $link Link the reply arrived on
     * @param PeerClockReplyDTO $frame Incoming peer clock reading
     */
    public function onClockReplyReceived(PeerLink $link, PeerClockReplyDTO $frame): void
    {
        if ($link->remoteIdentity()?->nodeId !== null) {
            $this->clock?->onReply($link, $frame);
        }
    }

    /**
     * Routes a received claim refusal to the local claim sink for this node to act on.
     *
     * @param PeerLink $link Link the verdict arrived on
     * @param PeerRtClaimRefusedDTO $frame Received RT-claim-refused frame
     */
    public function onRtClaimRefusedReceived(PeerLink $link, PeerRtClaimRefusedDTO $frame): void
    {
        if ($link->remoteIdentity()?->nodeId !== null) {
            Hilos::$cluster?->rtClaimSink()?->applyRtClaimRefusal($frame);
        }
    }

    /**
     * Registers the node-local protected-mode handler the freeze frames route to.
     *
     * The leader-orchestration slice builds the handler and installs it here; until it does, the
     * seam stays null and the arriving frames route to a no-op, exactly like the placement seam
     * before a worker executor is registered.
     *
     * @param ProtectedModeCoordinator $coordinator Handler for the protected-mode freeze frames
     */
    public function registerProtectedMode(ProtectedModeCoordinator $coordinator): void
    {
        if ($this->protectedMode !== $coordinator && $this->protectedMode instanceof ProtectedModeSyncScheduler) {
            $this->protectedMode->detachSlaveSyncEventLoop();
        }
        $this->protectedMode = $coordinator;
        if (!$this->preparingShutdown && $this->dialLoop !== null && $coordinator instanceof ProtectedModeSyncScheduler) {
            $coordinator->attachSlaveSyncEventLoop($this->dialLoop);
        }
    }

    /** @return bool Finite slave-sync work that the master must dispatch before waiting */
    public function hasProtectedModeSyncReadyWork(): bool
    {
        return $this->protectedMode instanceof ProtectedModeSyncScheduler && $this->protectedMode->hasSlaveSyncReadyWork();
    }

    /**
     * Candidate and draining streams cannot contribute diagnostics or membership.
     * Publication follows the link/consensus pass, so it uses that pass's recognized leader.
     *
     * @param PeerLink $link Authenticated sender link
     * @param PeerProtectedModeNodeReportDTO $frame Full diagnostic snapshot
     * @throws EnvException When the consensus configuration is invalid
     * @throws PeerTransportException When invalidating a malformed cached snapshot
     */
    public function onProtectedModeNodeReportReceived(PeerLink $link, PeerProtectedModeNodeReportDTO $frame): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($this->localIdentity->role !== NodeRole::Master || $from === null || $this->activeLinkToNode($from) !== $link) {
            return;
        }

        $this->refreshProtectedModeReportAuthority();
        $this->protectedModeNodeReports[$from] = $frame;
    }

    /**
     * Routes a received protected-mode enable request to the local handler for the leader to act on.
     *
     * A no-op before the handler is registered; the envelope is unwrapped so the domain handler
     * receives the contract payload, never the wire frame.
     *
     * @param PeerLink $link Link the request arrived on
     * @param PeerProtectedModeEnableDTO $frame Received protected-mode enable frame
     * @throws PeerTransportException When the coordinator cannot build its cluster descriptor
     * @throws EnvException When the cluster-enabled flag value is invalid during initiator placement lookup
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
     */
    public function onProtectedModeEnableReceived(PeerLink $link, PeerProtectedModeEnableDTO $frame): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($from !== null) {
            $this->protectedMode?->onEnable($from, $frame->data);
        }
    }

    /**
     * Routes a received protected-mode ready confirmation to the local handler for the initiator to act on.
     *
     * @param PeerLink $link Link the confirmation arrived on
     * @param PeerProtectedModeReadyDTO $frame Received protected-mode ready frame
     * @throws EnvException When the cluster-enabled flag value is invalid during leader lookup
     */
    public function onProtectedModeReadyReceived(PeerLink $link, PeerProtectedModeReadyDTO $frame): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($from !== null) {
            $this->protectedMode?->onReady($from);
        }
    }

    /**
     * Routes a received protected-mode refused confirmation to the local handler for the initiator to act on.
     *
     * @param PeerLink $link Link the refusal arrived on
     * @param PeerProtectedModeRefusedDTO $frame Received protected-mode refused frame
     */
    public function onProtectedModeRefusedReceived(PeerLink $link, PeerProtectedModeRefusedDTO $frame): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($from !== null) {
            $this->protectedMode?->onRefused($from, $frame->reason);
        }
    }

    /**
     * Routes a received protected-mode disable request to the local handler for the leader to act on.
     *
     * @param PeerLink $link Link the request arrived on
     * @param PeerProtectedModeDisableDTO $frame Received protected-mode disable frame
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function onProtectedModeDisableReceived(PeerLink $link, PeerProtectedModeDisableDTO $frame): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($from !== null) {
            $this->protectedMode?->onDisable($from, $frame->initiatorAgentType, $frame->initiatorAgentIndex);
        }
    }

    /**
     * Routes a received protected-mode quiesce order to the local handler for this follower to freeze.
     *
     * A no-op before the handler is registered; the envelope is unwrapped so the domain handler
     * receives the freeze descriptor, never the wire frame.
     *
     * @param PeerLink $link Link the order arrived on
     * @param PeerProtectedModeQuiesceDTO $frame Received protected-mode quiesce frame
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     * @throws EnvException When the cluster-enabled flag value is invalid during leader lookup
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
     */
    public function onProtectedModeQuiesceReceived(PeerLink $link, PeerProtectedModeQuiesceDTO $frame): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($from !== null) {
            $this->protectedMode?->onQuiesce($from, $frame->data);
        }
    }

    /**
     * Unwraps the direct verification-window order for this follower's coordinator.
     * A no-op before the handler is registered.
     *
     * @param PeerLink $link Link the order arrived on
     * @param PeerProtectedModeWindowDTO $frame Received protected-mode window frame
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     * @throws EnvException When the cluster-enabled flag value is invalid during leader lookup
     */
    public function onProtectedModeWindowReceived(PeerLink $link, PeerProtectedModeWindowDTO $frame): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($from !== null) {
            $this->protectedMode?->onWindow($from, $frame->data);
        }
    }

    /**
     * Routes a received protected-mode quiesced report to the local handler for the leader to track.
     *
     * @param PeerLink $link Link the report arrived on
     * @param PeerProtectedModeQuiescedDTO $frame Received protected-mode quiesced frame
     * @throws EnvException When the cluster-enabled flag value is invalid during initiator placement lookup
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function onProtectedModeQuiescedReceived(PeerLink $link, PeerProtectedModeQuiescedDTO $frame): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($from !== null) {
            $this->protectedMode?->onQuiesced($from);
        }
    }

    /**
     * Routes the leader's word that every node has quiesced to the local handler for this follower.
     *
     * @param PeerLink $link Link the frame arrived on
     * @param PeerProtectedModeSettledDTO $frame Received protected-mode settled frame
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     * @throws EnvException When the cluster-enabled flag value is invalid during leader lookup
     */
    public function onProtectedModeSettledReceived(PeerLink $link, PeerProtectedModeSettledDTO $frame): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($from !== null) {
            $this->protectedMode?->onSettled($from);
        }
    }

    /**
     * Registers where node answers to a database re-hydrate announcement are credited.
     *
     * @param ReHydrateBarrierSink $sink Daemon-side owner of the open barrier
     */
    public function registerReHydrateBarrier(ReHydrateBarrierSink $sink): void
    {
        $this->reHydrateBarrier = $sink;
    }

    /**
     * Routes a received database re-hydrate announcement into this node's own re-read.
     *
     * Queues the fact onto the local router, exactly as an announcement from one of this node's
     * own workers would arrive: the daemon then fans it out to its workers, re-reads its own
     * collections and opens a local barrier over the two. What is different is the address - the
     * verdict of that barrier goes back to the node that announced the swap, as one answer for
     * this whole node.
     *
     * @param PeerLink $link Link the announcement arrived on
     * @param PeerDbReHydrateDTO $frame Received database re-hydrate frame
     * @throws InvalidArgumentException When the re-hydrate signal cannot be named
     */
    public function onDbReHydrateReceived(PeerLink $link, PeerDbReHydrateDTO $frame): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($from === null) {
            return;
        }

        Hilos::$sr->queueSignal(
            signalSource: new SignalSource(SignalSource::DB),
            signalType: new SignalType(SignalTypeConstants::DB_REHYDRATE),
            signalName: new SignalName(SignalConstants::DB_REHYDRATE),
            signalData: new DbReHydrateSignalData(
                agentId: null,
                replyToNodeId: $from,
                replyToRound: $frame->round,
            ),
        );
    }

    /**
     * Credits one node's answer to the barrier this node is holding open.
     *
     * The node answers for itself and its workers at once, so its own problem lines travel with a
     * negative answer and are quoted whole: the operator has to learn which process on which node
     * did not come back, and only that node knows its own roster.
     *
     * Who answered is decided by the link, not by the payload, like every other frame here: a
     * name a node writes about itself is a claim, and crediting it would let one node close
     * another's slot - after which the barrier reports complete for a node that never re-read.
     * The payload still names its sender, so a frame that disagrees with its own link is refused
     * rather than quietly re-addressed.
     *
     * @param PeerLink $link Link the report arrived on
     * @param PeerDbReHydratedDTO $frame Received database re-hydrated frame
     */
    public function onDbReHydratedReceived(PeerLink $link, PeerDbReHydratedDTO $frame): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($from === null || $from !== $frame->nodeId) {
            return;
        }

        $this->reHydrateBarrier?->ackReHydrateParticipant(
            $frame->round,
            ReHydrateRound::nodeParticipant($from),
            $frame->ok,
            $frame->problems === [] ? null : implode('; ', $frame->problems),
        );
    }

    /**
     * Routes a received protected-mode lift order to the local handler for this follower to release.
     *
     * @param PeerLink $link Link the order arrived on
     * @param PeerProtectedModeLiftDTO $frame Received protected-mode lift frame
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     * @throws EnvException When the cluster-enabled flag value is invalid during leader lookup
     */
    public function onProtectedModeLiftReceived(PeerLink $link, PeerProtectedModeLiftDTO $frame): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($from !== null) {
            $this->protectedMode?->onLift($from);
        }
    }

    /**
     * Routes a received protected-mode verify frame to the local handler.
     *
     * The frame travels both ways, so the handler is the one that decides whether this node is
     * the leader being asked or a follower being told.
     *
     * @param PeerLink $link Link the frame arrived on
     * @param PeerProtectedModeVerifyDTO $frame Received protected-mode verify frame
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     * @throws EnvException When the cluster-enabled flag value is invalid during leader lookup
     */
    public function onProtectedModeVerifyReceived(PeerLink $link, PeerProtectedModeVerifyDTO $frame): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($from !== null) {
            $this->protectedMode?->onVerify($from, $frame->initiatorAgentType, $frame->initiatorAgentIndex);
        }
    }

    /**
     * Routes a received protected-mode progress frame to the local handler.
     *
     * One direction only, so there is no half to decide between: the mark travels from the node
     * running the operation to the leader that watches over it.
     *
     * @param PeerLink $link Link the frame arrived on
     * @param PeerProtectedModeProgressDTO $frame Received protected-mode progress frame
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     */
    public function onProtectedModeProgressReceived(PeerLink $link, PeerProtectedModeProgressDTO $frame): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($from !== null) {
            $this->protectedMode?->onProgress($from, $frame->initiatorAgentType, $frame->initiatorAgentIndex);
        }
    }

    /**
     * Routes a received protected-mode pass frame to the local handler.
     *
     * @param PeerLink $link Link the frame arrived on
     * @param PeerProtectedModePassDTO $frame Received protected-mode pass frame
     * @throws EnvException When the cluster-enabled flag value is invalid during leader lookup
     */
    public function onProtectedModePassReceived(PeerLink $link, PeerProtectedModePassDTO $frame): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($from !== null) {
            $this->protectedMode?->onPass($from, $frame->initiatorAgentType, $frame->initiatorAgentIndex, $frame->passHash);
        }
    }

    /**
     * @param PeerLink $link Link the admission arrived on
     * @param PeerProtectedModeAdmitDTO $frame Code admission for one verifier session
     * @throws EnvException When leader lookup is unavailable
     */
    public function onProtectedModeAdmitReceived(PeerLink $link, PeerProtectedModeAdmitDTO $frame): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($from !== null) {
            $this->protectedMode?->onAdmit($from, $frame->passHash, $frame->sessionTokenHash);
        }
    }

    /**
     * Routes a received protected-mode circle frame to the local handler.
     *
     * @param PeerLink $link Link the frame arrived on
     * @param PeerProtectedModeCircleDTO $frame Received protected-mode circle frame
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     * @throws EnvException When the cluster-enabled flag value is invalid during leader lookup
     */
    public function onProtectedModeCircleReceived(PeerLink $link, PeerProtectedModeCircleDTO $frame): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($from !== null) {
            $this->protectedMode?->onCircle($from, $frame->initiatorAgentType, $frame->initiatorAgentIndex, $frame->snapshot);
        }
    }

    /**
     * Routes a received protected-mode refreeze frame to the local handler.
     *
     * Arrives on the leader only, from the initiator's node: the leader closes the window by
     * running the quiesce round again, and sends no follower this frame (HIL-1128).
     *
     * @param PeerLink $link Link the frame arrived on
     * @param PeerProtectedModeRefreezeDTO $frame Received protected-mode refreeze frame
     * @throws PeerTransportException When the coordinator cannot build its cluster descriptor
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this node's master is not the truth source
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
     */
    public function onProtectedModeRefreezeReceived(PeerLink $link, PeerProtectedModeRefreezeDTO $frame): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($from !== null) {
            $this->protectedMode?->onRefreeze($from, $frame->initiatorAgentType, $frame->initiatorAgentIndex);
        }
    }

    /**
     * Selects the authenticated working peers of either role for a freeze or DB re-hydrate barrier.
     *
     * Membership alone does not establish a working route. The existing link inventory excludes
     * candidates, replaced links and connections marked to close, and never includes self.
     *
     * @return list<string> Linked master and slave node ids excluding the local node
     */
    public function followerNodeIds(): array
    {
        return $this->linkedNodeIds();
    }

    /**
     * Entries retain exact route identities; a caller still checks current membership per visit.
     *
     * @return array<string, PeerLink> Original node-to-link map, captured without walking connections
     */
    public function activeLinkSnapshot(): array
    {
        return $this->activeLinks;
    }

    /** @return list<string> Working authenticated slaves only */
    public function slaveNodeIds(): array
    {
        return array_values(array_filter($this->linkedNodeIds(),
            fn(string $nodeId): bool => $this->activeLinkToNode($nodeId)?->remoteIdentity()?->role === NodeRole::Slave));
    }

    /**
     * @param string $nodeId Slave destination
     * @param PeerProtectedModeSyncDTO $snapshot State requested on this connection
     * @throws EnvException When the leadership configuration is invalid
     */
    public function sendProtectedModeSync(string $nodeId, PeerProtectedModeSyncDTO $snapshot): void
    {
        $link = $this->activeLinkToNode($nodeId);
        if ($this->leaderNodeId() !== $this->localIdentity->nodeId || $link?->remoteIdentity()?->role !== NodeRole::Slave) {
            return;
        }
        $this->protectedModeSyncLinks[$nodeId] = $link;
        $this->protectedModeSyncIds[$nodeId] = $snapshot->syncId;
        $link->sendFrame($snapshot);
    }

    /**
     * @param string $leaderNodeId Leader whose local effects are being continued
     * @param string $syncId Pending request id
     * @return bool Whether the original authenticated connection remains current
     * @throws EnvException When the leadership configuration is invalid
     */
    public function isProtectedModeSyncCurrent(string $leaderNodeId, string $syncId): bool
    {
        $link = $this->activeLinkToNode($leaderNodeId);
        return $this->localIdentity->role === NodeRole::Slave && $leaderNodeId === $this->leaderNodeId()
            && $link !== null && $link === $this->protectedModeSyncLeaderLink && $syncId === $this->protectedModeIncomingSyncId;
    }

    /**
     * @param string $leaderNodeId Leader whose request finished locally
     * @param string $syncId Applied request id
     * @param string $phase Applied phase
     * @throws EnvException When the leadership configuration is invalid
     * @throws PeerTransportException When the acknowledgement is invalid
     */
    public function sendProtectedModeSynced(string $leaderNodeId, string $syncId, string $phase): void
    {
        $link = $this->activeLinkToNode($leaderNodeId);
        if ($this->localIdentity->role !== NodeRole::Slave || $leaderNodeId !== $this->leaderNodeId()
            || $link === null || $link !== $this->protectedModeSyncLeaderLink || $syncId !== $this->protectedModeIncomingSyncId) {
            return;
        }
        $link->sendFrame(new PeerProtectedModeSyncedDTO($syncId, $phase));
    }

    /**
     * @param PeerLink $link Authenticated installed connection
     * @param PeerProtectedModeSyncDTO $frame Leader snapshot
     * @throws EnvException When the leadership or timeout configuration is invalid
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this master is not the truth source
     * @throws PeerTransportException When an acknowledgement cannot be built
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
     */
    public function onProtectedModeSyncReceived(PeerLink $link, PeerProtectedModeSyncDTO $frame): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($this->localIdentity->role !== NodeRole::Slave || $from === null || $from !== $this->leaderNodeId()
            || $link->remoteIdentity()?->role !== NodeRole::Master || $this->activeLinkToNode($from) !== $link) {
            return;
        }
        $this->protectedModeSyncLeaderLink = $link;
        $this->protectedModeIncomingSyncId = $frame->syncId;
        $this->protectedMode?->onSync($from, $frame);
    }

    /**
     * @param PeerLink $link Expected slave's installed connection
     * @param PeerProtectedModeSyncedDTO $frame Snapshot acknowledgement
     * @throws EnvException When leadership or initiator placement is invalid
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When this master is not the truth source
     * @throws PeerTransportException When the current snapshot cannot be built
     */
    public function onProtectedModeSyncedReceived(PeerLink $link, PeerProtectedModeSyncedDTO $frame): void
    {
        $from = $link->remoteIdentity()?->nodeId;
        if ($this->localIdentity->role !== NodeRole::Master || $from === null
            || $this->leaderNodeId() !== $this->localIdentity->nodeId || $link->remoteIdentity()?->role !== NodeRole::Slave
            || $this->activeLinkToNode($from) !== $link || ($this->protectedModeSyncLinks[$from] ?? null) !== $link
            || ($this->protectedModeSyncIds[$from] ?? null) !== $frame->syncId) {
            return;
        }
        $this->protectedMode?->onSynced($from, $frame);
    }

    /**
     * Returns the node id of the current cluster leader for an initiator to address its request.
     *
     * Masters use consensus; slaves use their placing leader only while its authenticated
     * working link exists. Placement release and takeover invalidate or replace that leader.
     *
     * @return ?string Leader node id, or null when leadership is unknown or the cluster is absent
     * @throws EnvException When the cluster-enabled flag value is invalid
     */
    public function leaderNodeId(): ?string
    {
        if ($this->localIdentity->role === NodeRole::Master) {
            return Hilos::$cluster?->leadership()->leaderId();
        }

        $leaderNodeId = $this->placement?->knownLeaderId();

        return $leaderNodeId !== null && $this->hasActiveLinkToNode($leaderNodeId) ? $leaderNodeId : null;
    }

    /**
     * Finds the current host of the initiator through the signal router's placement source.
     *
     * @param string $agentType Initiator agent type
     * @param ?int $agentIndex Initiator index, or null for a singleton
     * @return AgentLocation Current placement of the initiator
     * @throws EnvException When the cluster-enabled flag value is invalid
     */
    public function locateAgent(string $agentType, ?int $agentIndex): AgentLocation
    {
        return Hilos::$cluster?->workerPlacement()?->locate(
            $agentType,
            $agentIndex === null ? null : (string)$agentIndex,
        ) ?? AgentLocation::unknown();
    }

    /**
     * Forwards this initiator node's freeze request to the leader over the peer channel.
     *
     * @param string $leaderNodeId Node id of the current leader
     * @param ProtectedModeEnableSignalData $data Initiator identity and the operation the freeze protects
     */
    public function sendEnable(string $leaderNodeId, ProtectedModeEnableSignalData $data): void
    {
        $this->sendToNode($leaderNodeId, new PeerProtectedModeEnableDTO($data));
    }

    /**
     * Forwards this initiator node's release request to the leader over the peer channel.
     *
     * @param string $leaderNodeId Node id of the current leader
     * @param string $agentType Initiator agent type
     * @param ?int $agentIndex Initiator index, or null for a singleton
     */
    public function sendDisable(string $leaderNodeId, string $agentType, ?int $agentIndex): void
    {
        $this->sendToNode($leaderNodeId, new PeerProtectedModeDisableDTO($agentType, $agentIndex));
    }

    /**
     * Broadcasts the freeze order to every follower node.
     *
     * @param ProtectedModeQuiesceData $data Operation and initiator identity the freeze protects
     * @throws PeerTransportException When the descriptor has no barrier generation
     */
    public function broadcastQuiesce(ProtectedModeQuiesceData $data): void
    {
        $this->broadcastToAllPeers(new PeerProtectedModeQuiesceDTO($data));
    }

    /**
     * Opens a direct verification window on every follower node without stopping agents.
     *
     * @param ProtectedModeQuiesceData $data Operation and initiator identity the window protects
     * @throws PeerTransportException When the descriptor has no barrier generation
     */
    public function broadcastWindow(ProtectedModeQuiesceData $data): void
    {
        $this->broadcastToAllPeers(new PeerProtectedModeWindowDTO($data));
    }

    /**
     * Signals the initiator that every node has quiesced and its operation may proceed.
     *
     * @param string $nodeId Node where the initiator agent currently runs
     */
    public function sendReady(string $nodeId): void
    {
        $this->sendToNode($nodeId, new PeerProtectedModeReadyDTO());
    }

    /**
     * Signals the initiator that protected mode was refused and cannot be entered.
     *
     * @param string $initiatorNodeId Node id that hosts the initiator agent
     * @param string $reason Human-readable operator-facing refusal reason
     */
    public function sendRefused(string $initiatorNodeId, string $reason): void
    {
        $this->sendToNode($initiatorNodeId, new PeerProtectedModeRefusedDTO($reason));
    }

    /**
     * Broadcasts the database re-hydrate announcement to every other linked node.
     *
     * Sent by the node whose agent replaced the database, whether master or slave. All nodes hold
     * the same database and each answers for itself and its workers, so the announcement needs
     * no relay through the leader.
     *
     * The number is this node's own and travels as an opaque token: the receivers do not order
     * themselves by it and never compare it with a number of their own, they only return it, so
     * that an answer to a superseded announcement is not credited here (HIL-694).
     *
     * @param int $round Number this node opened its own round under
     */
    public function broadcastDbReHydrate(int $round): void
    {
        $this->broadcastToAllPeers(new PeerDbReHydrateDTO($round));
    }

    /**
     * Reports this node's whole re-hydrate verdict back to the node that announced the swap.
     *
     * @param string $announcerNodeId Node that sent the announcement
     * @param int $round Number that node is waiting under, as its announcement carried it
     * @param bool $ok Whether every process on this node re-read successfully
     * @param list<string> $problems This node's own problem lines, empty when it came back whole
     */
    public function sendDbReHydrated(string $announcerNodeId, int $round, bool $ok, array $problems): void
    {
        $this->sendToNode(
            $announcerNodeId,
            new PeerDbReHydratedDTO($this->localIdentity->nodeId, $round, $ok, $problems),
        );
    }

    /**
     * Broadcasts the release order to every follower node.
     */
    public function broadcastLift(): void
    {
        $this->broadcastToAllPeers(new PeerProtectedModeLiftDTO());
    }

    /**
     * Forwards this initiator node's request to open the verification window to the leader.
     *
     * @param string $leaderNodeId Node id of the current leader
     * @param string $agentType Initiator agent type
     * @param ?int $agentIndex Initiator index, or null for a singleton
     */
    public function sendVerify(string $leaderNodeId, string $agentType, ?int $agentIndex): void
    {
        $this->sendToNode($leaderNodeId, new PeerProtectedModeVerifyDTO($agentType, $agentIndex));
    }

    /**
     * Broadcasts the verification window to every follower node.
     * @param string $agentType Initiator agent type
     * @param ?int $agentIndex Initiator index, or null for a singleton
     */
    public function broadcastVerify(string $agentType, ?int $agentIndex): void
    {
        $this->broadcastToAllPeers(new PeerProtectedModeVerifyDTO($agentType, $agentIndex));
    }

    /**
     * Forwards this initiator node's progress mark to the leader over the peer channel.
     *
     * No broadcast twin, unlike the verification frames: only the leader reads the mark.
     *
     * @param string $leaderNodeId Node id of the current leader
     * @param string $agentType Initiator agent type
     * @param ?int $agentIndex Initiator index, or null for a singleton
     */
    public function sendProgress(string $leaderNodeId, string $agentType, ?int $agentIndex): void
    {
        $this->sendToNode($leaderNodeId, new PeerProtectedModeProgressDTO($agentType, $agentIndex));
    }

    /**
     * Forwards this initiator node's minted pass to the leader over the peer channel.
     *
     * @param string $leaderNodeId Node id of the current leader
     * @param string $agentType Initiator agent type
     * @param ?int $agentIndex Initiator index, or null for a singleton
     * @param string $passHash SHA-256 of the minted pass
     */
    public function sendPass(string $leaderNodeId, string $agentType, ?int $agentIndex, string $passHash): void
    {
        $this->sendToNode($leaderNodeId, new PeerProtectedModePassDTO($agentType, $agentIndex, $passHash));
    }

    /**
     * Broadcasts one minted pass to every follower node.
     *
     * @param string $agentType Initiator agent type
     * @param ?int $agentIndex Initiator index, or null for a singleton
     * @param string $passHash SHA-256 of the minted pass
     */
    public function broadcastPass(string $agentType, ?int $agentIndex, string $passHash): void
    {
        $this->broadcastToAllPeers(new PeerProtectedModePassDTO($agentType, $agentIndex, $passHash));
    }

    /**
     * @param string $leaderNodeId Node id of the leader
     * @param string $passHash Hash of the presented pass
     * @param string $sessionTokenHash Hash of the verifier session
     */
    public function sendAdmit(string $leaderNodeId, string $passHash, string $sessionTokenHash): void
    {
        $this->sendToNode($leaderNodeId, new PeerProtectedModeAdmitDTO($passHash, $sessionTokenHash));
    }

    /**
     * @param string $passHash Hash of the presented pass
     * @param string $sessionTokenHash Hash of the verifier session
     */
    public function broadcastAdmit(string $passHash, string $sessionTokenHash): void
    {
        $this->broadcastToAllPeers(new PeerProtectedModeAdmitDTO($passHash, $sessionTokenHash));
    }

    /**
     * Forwards the circle photographed on this initiator node to the leader over the peer channel.
     *
     * @param string $leaderNodeId Node id of the current leader
     * @param string $agentType Initiator agent type
     * @param ?int $agentIndex Initiator index, or null for a singleton
     * @param VerifierCircleSnapshot $snapshot The circle as this node photographed it
     */
    public function sendCircle(string $leaderNodeId, string $agentType, ?int $agentIndex, VerifierCircleSnapshot $snapshot): void
    {
        $this->sendToNode($leaderNodeId, new PeerProtectedModeCircleDTO($agentType, $agentIndex, $snapshot));
    }

    /**
     * Broadcasts the photographed circle to every follower node.
     *
     * @param string $agentType Initiator agent type
     * @param ?int $agentIndex Initiator index, or null for a singleton
     * @param VerifierCircleSnapshot $snapshot The circle as the initiator's node photographed it
     */
    public function broadcastCircle(string $agentType, ?int $agentIndex, VerifierCircleSnapshot $snapshot): void
    {
        $this->broadcastToAllPeers(new PeerProtectedModeCircleDTO($agentType, $agentIndex, $snapshot));
    }

    /**
     * Forwards this initiator node's request to close back out of the window to the leader.
     *
     * @param string $leaderNodeId Node id of the current leader
     * @param string $agentType Initiator agent type
     * @param ?int $agentIndex Initiator index, or null for a singleton
     */
    public function sendRefreeze(string $leaderNodeId, string $agentType, ?int $agentIndex): void
    {
        $this->sendToNode($leaderNodeId, new PeerProtectedModeRefreezeDTO($agentType, $agentIndex));
    }

    /**
     * Reports this follower's quiesced state back to the leader that ordered the freeze.
     *
     * @param string $leaderNodeId Node id of the leader that ordered the freeze
     */
    public function sendQuiesced(string $leaderNodeId): void
    {
        $this->sendToNode($leaderNodeId, new PeerProtectedModeQuiescedDTO());
    }

    /**
     * Tells every follower node that every node has stopped its roster, so each writes active.
     *
     * Sent at the end of every quiesce round, before the ready (HIL-1128).
     */
    public function broadcastSettled(): void
    {
        $this->broadcastToAllPeers(new PeerProtectedModeSettledDTO());
    }

    /**
     * Marks a closed working route's peer offline when no replacement became active.
     * Candidate and draining-link closes leave membership and reader interest intact.
     *
     * @param PeerLink $link Link that closed
     * @throws HilosException When upload accounting or a membership/quorum observer fails
     */
    public function onLinkClosed(PeerLink $link): void
    {
        $dial = $this->dialForLink($link);
        $dial?->ready?->markReady();
        $nodeId = $link->remoteIdentity()?->nodeId ?? $dial?->remoteNodeId;
        if ($nodeId !== null) {
            $this->wakeNodeDials($nodeId);
        }
        $this->uploadMemory?->onPeerClosed($link);
        $this->clock?->onLinkClosed($link);
        foreach ($this->candidateLinks as $nodeId => $candidate) {
            if ($candidate === $link) {
                unset($this->candidateLinks[$nodeId]);
                $this->activeLinkToNode($nodeId)?->cancelPeerDrain();
            }
        }

        $remote = $link->remoteIdentity();
        if ($remote === null) {
            return;
        }

        if (($this->activeLinks[$remote->nodeId] ?? null) !== $link) {
            // A replaced link has finished draining while its successor remains active.
            return;
        }
        unset($this->activeLinks[$remote->nodeId]);
        unset($this->protectedModeNodeReports[$remote->nodeId]);
        if ($this->protectedMode instanceof ProtectedModeSyncScheduler) {
            $this->protectedMode->noteSlaveSyncChanged();
        }

        $registry = $this->registry();
        if ($registry === null) {
            return;
        }

        $now = microtime(true);
        Logger::info("Peer left: {$remote->nodeId}");

        // The last link to that node is gone, so its RT deltas stop arriving here and the copies
        // this node holds of its rows stop being current (HIL-711). Told outside the branch
        // below on purpose: that one is silent when the node was already marked offline by its
        // own graceful leave, and a link dropping under a node believed offline is precisely the
        // case where replication has just stopped and nothing else would say so.
        Hilos::$cluster?->rtSyncSink()?->noteNodeUnreachable($remote->nodeId, $now);

        if ($registry->markOffline($remote->nodeId, $now)) {
            $this->coordinator?->noteMembershipChanged();
            $this->reconcilePeerDial(ClusterNode::fromIdentity($remote, false, $now));
            $this->notifyLeft($remote, $now);
        }

        // Fast-path leader-loss: a dropped link marks the peer offline instantly, so
        // a follower can react at once instead of waiting out the election timeout.
        $this->coordinator?->noteNodeOffline($remote->nodeId);
    }

    /**
     * Reports a node coming online to the membership observer.
     *
     * The registry already merged the record; this only fans the transition out to
     * the daemon's {@see DaemonManager::onNodeJoined} hook via
     * the cluster context. A no-op when the context is absent (non-daemon process).
     *
     * @param NodeIdentity $identity Node that came online
     * @param float $now Microtime of the transition
     */
    private function notifyJoined(NodeIdentity $identity, float $now): void
    {
        Hilos::$cluster?->notifyNodeJoined(ClusterNode::fromIdentity($identity, true, $now));
    }

    /**
     * Reports a node going offline to the membership observer.
     *
     * The registry already marked the node offline; this only fans the transition
     * out to the daemon's {@see DaemonManager::onNodeLeft} hook
     * via the cluster context. A no-op when the context is absent.
     *
     * @param NodeIdentity $identity Node that went offline
     * @param float $now Microtime of the transition
     * @throws RtActionsStateCollectionNullException When the cluster registry is unavailable
     * @throws EventLoopException When a live restart caller deadline cannot be armed or canceled
     */
    private function notifyLeft(NodeIdentity $identity, float $now): void
    {
        // What that node read goes with it (HIL-717). Here rather than at the link closing,
        // because a dropped link is re-dialed and a node whose interest was forgotten meanwhile
        // would be filtered out of every frame written during the gap - while a node that really
        // left announces itself again on the handshake if it ever comes back.
        $this->nodeReaderMap->release($identity->nodeId);

        Hilos::$cluster?->notifyNodeLeft(ClusterNode::fromIdentity($identity, false, $now));
    }

    /**
     * Merges what one gossip entry says a node is made of, the one path of a roster entry and
     * an announcement alike (HIL-1059).
     *
     * An entry about the local node is ignored: this node is authoritative about itself. An
     * entry a neighbour gives about a third node this one holds its own link to is ignored
     * whole: that node told this one its membership itself, on the handshake or in its own
     * announcement, and the retelling is older than its word (HIL-746). Anything else merges
     * membership only, never liveness. A change to a node that is online here is a join the
     * observer hears - and that node can only be the sender itself: online means this node's
     * own link, and entries about linked third nodes never get this far.
     *
     * @param ClusterRegistry $registry Master registry
     * @param PeerLink $source Link the entry arrived on
     * @param PeerNodeEntry $entry Received node entry
     * @param float $now Current microtime
     * @return bool True when the entry changed the membership
     */
    private function mergeEntry(ClusterRegistry $registry, PeerLink $source, PeerNodeEntry $entry, float $now): bool
    {
        if ($entry->nodeId === $this->localIdentity->nodeId) {
            return false;
        }

        if ($entry->nodeId !== $source->remoteIdentity()?->nodeId && $this->hasHandshakedLinkToNode($entry->nodeId)) {
            return false;
        }

        $identity = $entry->toIdentity();
        if (!$registry->mergeMembership($identity, $now)) {
            return false;
        }

        $this->coordinator?->noteMembershipChanged();
        $this->reconcilePeerDial(ClusterNode::fromIdentity($identity, $registry->isOnline($identity->nodeId), $now));
        $this->wakeNodeDials($identity->nodeId);
        if ($this->onlineNodeById($registry, $entry->nodeId) !== null) {
            $this->notifyJoined($identity, $now);
        }

        return true;
    }

    /**
     * Sends the local membership roster to one link.
     *
     * @param PeerLink $link Link to bootstrap
     * @param ClusterRegistry $registry Master registry
     */
    private function sendRoster(PeerLink $link, ClusterRegistry $registry): void
    {
        $entries = array_map(
            static fn(ClusterNode $node): PeerNodeEntry => PeerNodeEntry::fromNode($node),
            $registry->snapshot(),
        );

        $link->sendFrame(new PeerRosterDTO($entries));
    }

    /**
     * Re-announces the local node's current registry record to every peer.
     *
     * Invoked by the control-plane reload after the local role, capabilities, or
     * address were refreshed: it reads the freshly-merged local record from the
     * master registry and gossips it to all handshaked links so peers converge on
     * the new identity. A no-op when the registry or the local record is absent.
     *
     * @throws HilosException When the observed membership changes quorum and its duties fail
     */
    public function announceLocalNode(): void
    {
        $registry = $this->registry();
        if ($registry === null) {
            return;
        }

        $this->coordinator?->noteMembershipChanged();
        $this->reconcilePeerDials();
        foreach ($registry->snapshot() as $node) {
            if ($node->nodeId === $this->localIdentity->nodeId) {
                $this->broadcastAnnounce(PeerNodeEntry::fromNode($node));
                return;
            }
        }
    }

    /**
     * Returns the online master-role node ids from the registry, including self.
     *
     * Backs the coordinator's quorum check. Online means reachable over this node's
     * own link; a neighbour's word about a master does not count (HIL-1059). A registry
     * hiccup yields an empty set, which the coordinator reads as no quorum — the safe,
     * leadership-dropping side.
     *
     * @return list<string> Online master node ids
     */
    public function onlineMasterIds(): array
    {
        $registry = $this->registry();
        if ($registry === null) {
            return [];
        }

        $ids = [];
        foreach ($registry->snapshot() as $node) {
            if ($node->online && $node->role === NodeRole::Master) {
                $ids[] = $node->nodeId;
            }
        }

        return $ids;
    }

    /**
     * Sends a consensus frame to every handshaked master peer.
     *
     * @param PeerDTO $frame Consensus frame to broadcast
     */
    public function broadcastToMasters(PeerDTO $frame): void
    {
        $this->fanOutFrame(
            $frame,
            static fn(PeerLink $link): bool => $link->remoteIdentity()?->role === NodeRole::Master,
        );
    }

    /** @param UploadMemoryMaster $master Existing node-wide upload owner */
    public function setUploadMemoryMaster(UploadMemoryMaster $master): void
    {
        $this->uploadMemory = $master;
        $master->setPeerServer($this, $this->localIdentity->nodeId);
    }

    /**
     * @param string $nodeId Receiver identity captured by ordered acquisition
     * @return ?PeerLink Current real incarnation, or null while unlinked/draining
     */
    public function uploadLinkToNode(string $nodeId): ?PeerLink
    {
        $link = $this->activeLinkToNode($nodeId);

        return $link !== null && !$link->isDraining() ? $link : null;
    }

    /**
     * @param PeerLink $link Actual handshaked incarnation
     * @param PeerDTO $frame Upload control metadata
     * @throws PeerTransportException When upload budgeting is unavailable or the request is invalid
     * @throws HilosException When the owning master cannot reconcile its ledger
     */
    public function onUploadControlReceived(PeerLink $link, PeerDTO $frame): void
    {
        if ($this->uploadMemory === null) {
            throw new PeerTransportException('Peer upload control has no master ledger');
        }
        $this->uploadMemory->onPeerControl($link, $frame);
    }

    /**
     * Sends a frame to every handshaked peer, master or slave alike.
     *
     * Used for the graceful-leave announcement, which every peer needs (not just the
     * master set) so their membership converges on the planned departure.
     *
     * @param PeerDTO $frame Frame to broadcast
     */
    private function broadcastToAllPeers(PeerDTO $frame): void
    {
        $this->fanOutFrame($frame, static fn(PeerLink $link): bool => $link->remoteIdentity() !== null);
    }

    /**
     * Sends a consensus frame to one master peer by node id, if it is linked.
     *
     * @param string $nodeId Target master node id
     * @param PeerDTO $frame Consensus frame to send
     */
    public function sendToMaster(string $nodeId, PeerDTO $frame): void
    {
        $this->activeLinkToNode($nodeId)?->sendFrame($frame);
    }

    /**
     * Delivers a placement frame to one node by id, over any handshaked link.
     *
     * Unlike {@see sendToMaster()}, placement addresses any node — a data-plane slave
     * hosts placed agents — so no role filter applies. Returns whether a link carried it.
     *
     * @param string $nodeId Target node id
     * @param PeerDTO $frame Placement frame to send
     * @return bool True when a handshaked link carried the frame
     */
    public function sendToNode(string $nodeId, PeerDTO $frame): bool
    {
        $link = $this->activeLinkToNode($nodeId);
        if ($link !== null) {
            $link->sendFrame($frame);
            return true;
        }

        return false;
    }

    /**
     * Forwards one resolved signal to an agent on another node over the peer channel.
     *
     * The router already resolved the final target on this node; this wraps it in a
     * {@see PeerSignalDTO} stamped with the local node as origin and hands it to the target
     * over a live link. Delivery is best-effort: a false return (no handshaked link to the
     * node) is the caller's cue to drop and log, matching the local path that skips when no
     * worker hosts the agent. Buffering and retry on an offline node are out of scope.
     *
     * @param string $targetNodeId Id of the node hosting the target agent
     * @param string $agentType Resolved target agent type
     * @param ?string $agentIndex Resolved target agent index, or null for a singleton agent
     * @param SignalDTO $signal Signal to deliver on the target node
     * @return bool True when a live link carried the frame, false when the node is unlinked
     */
    public function sendSignalToNode(string $targetNodeId, string $agentType, ?string $agentIndex, SignalDTO $signal): bool
    {
        if ($signal->data instanceof WebSocketFrameBinarySignalDTO && $this->uploadMemory?->isBudgeted($signal->data)) {
            $link = $this->activeLinkToNode($targetNodeId);
            if ($link === null) {
                return false;
            }
            try {
                $this->uploadMemory->sendPeerSignal($link, $agentType, $agentIndex, $signal);
            } catch (HilosException $e) {
                Logger::warning('Budgeted upload peer delivery refused: ' . $e->getMessage());
                return false;
            }
            return true;
        }

        return $this->sendToNode($targetNodeId, new PeerSignalDTO(
            $this->localIdentity->nodeId,
            $targetNodeId,
            $agentType,
            $agentIndex,
            $signal,
        ));
    }

    /**
     * Delivers a received cross-node signal to the resolved agent on this node.
     *
     * Verifies the frame is addressed to this node, then hands the already-resolved target
     * straight to the local delivery sink — no re-routing, which structurally rules out a
     * forward loop or a second fan-out (F2). A mismatched target, an unregistered sink, or a
     * delivery failure is dropped and logged, keeping a bad forward from tearing down the
     * daemon loop (F1).
     *
     * @param PeerLink $link Link the signal arrived on
     * @param PeerSignalDTO $frame Received signal-forward frame
     */
    public function onSignalReceived(PeerLink $link, PeerSignalDTO $frame): void
    {
        if ($frame->targetNodeId !== $this->localIdentity->nodeId) {
            Logger::warning(
                "Dropping peer signal addressed to node '{$frame->targetNodeId}' received on node '{$this->localIdentity->nodeId}'",
            );
            return;
        }

        try {
            if ($frame->uploadReservation !== null) {
                if ($link->remoteIdentity()?->nodeId !== $frame->originNodeId || $this->uploadMemory === null) {
                    throw new PeerTransportException('Budgeted peer upload has no matching link identity or master ledger');
                }
                if ($this->uploadMemory->acceptPeerSignal($link, $frame) === null) {
                    return;
                }
            }
            $sink = Hilos::$cluster?->agentSignalSink();
            if ($sink === null) {
                Logger::warning("Dropping peer signal for agent '{$frame->agentType}': no local agent signal sink registered");
                return;
            }
            $sink->deliverSignalToAgent($frame->agentType, $frame->agentIndex, $frame->signal);
        } catch (Throwable $e) {
            Logger::warning("Failed to deliver peer signal to agent '{$frame->agentType}': {$e->getMessage()}");
        }
    }

    /**
     * Announces one RT sync fact written on this node to every other node of the mesh.
     *
     * Implements {@see RtSyncMesh}. A fact about an RT collection concerns every node alike, so
     * there is nobody to address:
     * the fact goes to every handshaked link, and each receiver applies it to its read-only
     * copy. Delivery is best-effort in the same sense as {@see sendSignalToNode()} — a node
     * that is not linked right now simply does not get this fact; it is re-offered the whole
     * collection when it joins, and durability is out of scope (HIL-183).
     *
     * @param string $signalType RT sync signal type being announced
     * @param SignalDTO $signal RT sync signal the other nodes apply
     * @param bool $partialOwner Whether this node holds only part of the right over the collection
     */
    public function broadcastRtSync(string $signalType, SignalDTO $signal, bool $partialOwner = false): void
    {
        $frame = new PeerRtSyncDTO($this->localIdentity->nodeId, $signalType, $signal, $partialOwner);
        $collectionKey = $signal->data instanceof SyncSignalDataInterface ? $signal->data->collectionKey : null;
        if ($collectionKey === null) {
            // Nothing to match an interest against, so the old address stands: everybody. The
            // daemon never produces such a fact - it refuses to announce one whose payload names
            // no collection - and if one ever arrives here, replicating it too widely is the side
            // of the mistake a receiver can still recover from.
            $this->broadcastToNodes($frame);

            return;
        }

        $this->broadcastToNodesHolding($frame, SourceChange::KIND_RT, $collectionKey);
    }

    /**
     * Tells every peer which RT collections this node reads, replacing what it said before.
     *
     * Implements {@see SourceInterestMesh}. Called off the daemon loop, which is where the union
     * of what this node's workers read is known
     * ({@see AgentManagerDaemon::consumeChangedReaderInterest()}).
     *
     * The list is remembered as well as sent, because a broadcast only reaches the nodes linked
     * right now and a peer joining later would otherwise never hear it ({@see onHandshakeComplete()}).
     *
     * @param list<string> $rtCollections RT collections the processes of this node read
     * @param list<string> $dbCollections DB collections the processes of this node read
     */
    public function announceSourceInterest(array $rtCollections, array $dbCollections): void
    {
        $this->announcedRtInterest = $rtCollections;
        $this->announcedDbInterest = $dbCollections;
        $this->broadcastToNodes(
            new PeerSourceInterestDTO($this->localIdentity->nodeId, $rtCollections, $dbCollections),
        );
    }

    /**
     * Records what another node reads, and offers it whatever this node owns of the new keys.
     *
     * The frame carries the whole of what that node reads, so it replaces its entry rather than
     * adding to it: a key it no longer names is a key it stopped reading, and merging would keep
     * sending frames for it until the node died.
     *
     * A collection that is new for that node is one it holds no copy of, so a delta would land on
     * nothing - which is why the hand-over runs here as well as off the handshake. It is a
     * top-up and not a condition of anything: the reader's own master decides when the reader may
     * read, out of the replica it already holds.
     *
     * The node id is taken from the frame rather than from the link for the reason the RT sync
     * frames do it: what a node says about itself is what the rest of the mesh keys its map by,
     * and reading it off the link would key this one differently from everybody else's.
     *
     * @param PeerLink $link Link the interest arrived on
     * @param PeerSourceInterestDTO $frame Received reader-interest frame
     */
    public function onSourceInterestReceived(PeerLink $link, PeerSourceInterestDTO $frame): void
    {
        if ($this->sourceInterestDelivery !== null) {
            ($this->sourceInterestDelivery)($this->sourceInterestFrameDelivery($link, $frame));
            return;
        }
        $this->applySourceInterest($frame);
    }

    /** @param Closure(Generator<int, void>): void $deliver Exact master's existing guarded source FIFO */
    public function setSourceInterestDelivery(Closure $deliver): void
    {
        $this->sourceInterestDelivery = $deliver;
    }

    /** @param Closure(Generator<int, void>): void $deliver Original binding; an old owner cannot remove its replacement */
    public function removeSourceInterestDelivery(Closure $deliver): void
    {
        if ($this->sourceInterestDelivery === $deliver) {
            $this->sourceInterestDelivery = null;
        }
    }

    /**
     * Applies a received RT replica to this node's copy of the collection.
     *
     * Hands the frame to the local apply port and stops there: the fact is not passed on to
     * the rest of the mesh, because the owner already announced it to everyone. That is the
     * whole echo defense — one hop, no hop counters, no de-duplication by id. An unregistered
     * sink or a failing apply is dropped and logged, so a bad frame cannot end the daemon loop.
     *
     * @param PeerLink $link Link the replica arrived on
     * @param PeerRtSyncDTO $frame Received RT sync frame
     */
    public function onRtSyncReceived(PeerLink $link, PeerRtSyncDTO $frame): void
    {
        $sink = Hilos::$cluster?->rtSyncSink();
        if ($sink === null) {
            Logger::warning(
                "Dropping peer RT sync from node '{$frame->originNodeId}': no local RT sync sink registered",
            );
            return;
        }

        try {
            $sink->applyRemoteRtSync(
                $frame->originNodeId,
                $frame->signalType,
                $frame->signal,
                $frame->partialOwner,
            );
        } catch (Throwable $e) {
            Logger::warning("Failed to apply peer RT sync from node '{$frame->originNodeId}': {$e->getMessage()}");
        }
    }

    /**
     * Announces one DB sync fact written on this node to every other node of the mesh.
     *
     * Implements {@see DbSyncMesh}. The twin of {@see broadcastRtSync()}, and addressed by the
     * same map for a reason of its own: the row lives in the database all the nodes share, so
     * nobody is owed a copy of it - but a node holding none of that collection has nothing to
     * apply the fact into, and its own announced interest is how it says so (HIL-750). Delivery
     * is best-effort — a node that is not linked right now does not get this fact, and what
     * covers the gap is that it stops trusting its rows when the link comes back
     * ({@see DbSyncSink::reReadAfterLink()}) rather than any redelivery here.
     *
     * @param string $signalType DB sync signal type being announced
     * @param SignalDTO $signal DB sync signal the other nodes apply
     * @param ?string $collectionKey Collection the fact belongs to, or null when it names none
     */
    public function broadcastDbSync(string $signalType, SignalDTO $signal, ?string $collectionKey = null): void
    {
        $frame = new PeerDbSyncDTO($this->localIdentity->nodeId, $signalType, $signal);
        if ($collectionKey === null) {
            // Nothing to match an interest against, so the old address stands: everybody. The
            // same fallback the runtime twin keeps, and for the same reason - replicating too
            // widely is the side of the mistake a receiver can still recover from.
            $this->broadcastToNodes($frame);

            return;
        }

        $this->broadcastToNodesHolding($frame, SourceChange::KIND_DB, $collectionKey);
    }

    /**
     * Applies a received DB replica to the rows this node holds.
     *
     * Hands the frame to the local apply port and stops there, exactly as
     * {@see onRtSyncReceived()} does: the writing node already announced the fact to everyone,
     * so passing it on would be an echo. An unregistered sink or a failing apply is dropped and
     * logged, so a bad frame cannot end the daemon loop.
     *
     * @param PeerLink $link Link the replica arrived on
     * @param PeerDbSyncDTO $frame Received DB sync frame
     */
    public function onDbSyncReceived(PeerLink $link, PeerDbSyncDTO $frame): void
    {
        $sink = Hilos::$cluster?->dbSyncSink();
        if ($sink === null) {
            Logger::warning(
                "Dropping peer DB sync from node '{$frame->originNodeId}': no local DB sync sink registered",
            );
            return;
        }

        try {
            $sink->applyRemoteDbSync($frame->originNodeId, $frame->signalType, $frame->signal);
        } catch (Throwable $e) {
            Logger::warning("Failed to apply peer DB sync from node '{$frame->originNodeId}': {$e->getMessage()}");
        }
    }

    /**
     * Hands one RT collection, or the rows of it this node owns, to a node that reads it.
     *
     * Implements {@see RtSyncMesh}. Addressed rather than broadcast: only the joining node is
     * behind on this collection, and the others would be told to replace a copy that is already
     * current. A node that is not linked is silently skipped — it cannot be behind on anything
     * it is not connected for, and it will be offered the collection when it does join.
     *
     * A node that does not read the collection is skipped for a different reason, and it is the
     * one that makes the addressing whole (HIL-717): a copy sent there would be the last thing
     * that node ever heard about it, since the deltas after it go only to readers. A copy nobody
     * maintains is worse than none - it stays exactly as current as the second it arrived, and
     * whatever reads it later would be served that. The reader this node has yet to hear from is
     * not lost by this: a node announces its interest as its handshake completes and again
     * whenever it moves, and either announcement asks for the hand-over again.
     *
     * @param string $nodeId Node being handed the collection
     * @param string $collectionKey RT collection this node owns
     * @param array<string, array<string, mixed>> $rows Rows by state id, as this node holds them
     * @param list<string> $scopeKeys Named rows this node speaks for; empty for other widths
     * @param list<string> $scopeSetKeys Sets this node speaks for; empty for other widths
     */
    public function sendRtSnapshotToNode(
        string $nodeId,
        string $collectionKey,
        array $rows,
        array $scopeKeys = [],
        array $scopeSetKeys = [],
    ): void {
        if (!$this->nodeReaderMap->holds($nodeId, SourceChange::KIND_RT, $collectionKey)) {
            return;
        }

        $this->sendToNode(
            $nodeId,
            new PeerRtSnapshotDTO($this->localIdentity->nodeId, $collectionKey, $rows, $scopeKeys, $scopeSetKeys),
        );
    }

    /**
     * Asks one node for the collections this node holds nothing of.
     *
     * Implements {@see RtSyncMesh}. Addressed at the peer whose handshake just completed, and
     * sent after this node's interest and its own hand-over: the answer is filtered by the same
     * reader map {@see sendRtSnapshotToNode()} consults, and a request that overtook the
     * interest frame would reach a holder that has never heard this node reads the collection.
     *
     * An empty list is not a frame. It would say nothing a holder could act on — it does not
     * know what this node is missing — and read generously it would ask for the whole runtime
     * of every peer.
     *
     * @param string $nodeId Node being asked
     * @param list<string> $collectionKeys RT collections this node holds nothing of
     */
    public function sendRtSnapshotQueryToNode(string $nodeId, array $collectionKeys): void
    {
        if ($collectionKeys === []) {
            return;
        }

        $this->sendToNode($nodeId, new PeerRtSnapshotQueryDTO($this->localIdentity->nodeId, $collectionKeys));
    }

    /**
     * Asks every linked node for the collections this node holds nothing of.
     *
     * Implements {@see RtSyncMesh}. Through {@see broadcastToNodes()} and deliberately not
     * through {@see broadcastToNodesHolding()}, although a filter by collection looks like the
     * closer fit here: what is wanted is a holder, and a holder need not be a reader. A node
     * that writes a collection without reading it is an ordinary member of this mesh, and the
     * precise question would drop the very peer most likely to be able to answer.
     *
     * @param list<string> $collectionKeys RT collections this node holds nothing of
     */
    public function broadcastRtSnapshotQuery(array $collectionKeys): void
    {
        if ($collectionKeys === []) {
            return;
        }

        $this->broadcastToNodes(new PeerRtSnapshotQueryDTO($this->localIdentity->nodeId, $collectionKeys));
    }

    /**
     * Offers a node that asked the rows of one collection, as written by one node.
     *
     * Implements {@see RtSyncMesh}. The only send of this class that carries RT state this node
     * does not own, and the guard that makes it safe is the same one the owner-driven hand-over
     * uses: the asker must be on record as reading the collection, or the copy would arrive
     * where no delta ever follows it and stay as current as the second it landed.
     *
     * A null origin is this node's own rows, and signing them here is the same thing every other
     * frame this class sends does: the local id lives on the transport, and the daemon above it
     * has no name of its own to give.
     *
     * @param string $nodeId Node that asked for the collection
     * @param ?string $originNodeId Node that wrote these rows, or null when this node wrote them
     * @param string $collectionKey RT collection the rows belong to
     * @param array<string, array<string, mixed>> $rows Rows by state id, as this node holds them
     */
    public function sendRtReplicaOfferToNode(
        string $nodeId,
        ?string $originNodeId,
        string $collectionKey,
        array $rows,
    ): void {
        if ($rows === [] || !$this->nodeReaderMap->holds($nodeId, SourceChange::KIND_RT, $collectionKey)) {
            return;
        }

        $this->sendToNode($nodeId, new PeerRtReplicaOfferDTO(
            $originNodeId ?? $this->localIdentity->nodeId,
            $collectionKey,
            $rows,
        ));
    }

    /**
     * Announces everything this node's agents own of the RT state to every linked peer.
     *
     * Implements {@see RtClaimMesh}. Announced rather than addressed at the leader, and the
     * reason is a hard property of this cluster rather than a preference: consensus runs on the
     * master set alone, so a data-plane node keeps {@see PendingLeadership} for the life of its
     * process and that consensus seam's leader id is null forever. Addressed through that seam,
     * the report would be dropped on exactly the nodes that host placed agents — every claim this guard exists to
     * judge. Placement answers the same question the same way: a node hands its hosted set to
     * whoever links, and a peer that does not lead ignores it ({@see ClusterPlacement}).
     *
     * The cost of telling four nodes instead of one is four small frames on the rare pass where
     * ownership moved, against a guard that is silent where it matters most.
     *
     * This node is told first, and without a frame: a leader has no link to itself, and its own
     * agents would otherwise be the one thing its map never held. The fold ignores the call on a
     * node that does not lead, so it is safe wherever this runs.
     *
     * @param list<PeerRtClaimEntry> $claims What each agent of this node owns
     */
    public function announceRtClaims(array $claims): void
    {
        Hilos::$cluster?->rtClaimSink()?->applyRemoteRtClaims($this->localIdentity->nodeId, $claims);
        $this->broadcastToNodes(new PeerRtClaimsDTO($this->localIdentity->nodeId, $claims));
    }

    /**
     * Tells one node everything this node's agents own, because that link has just appeared.
     *
     * Implements {@see RtClaimMesh}. The narrow form of {@see announceRtClaims()}: the cue names
     * one peer, so only that peer is short of this report.
     *
     * @param string $nodeId Node to tell
     * @param list<PeerRtClaimEntry> $claims What each agent of this node owns
     */
    public function sendRtClaimsToNode(string $nodeId, array $claims): void
    {
        if ($nodeId === $this->localIdentity->nodeId) {
            Hilos::$cluster?->rtClaimSink()?->applyRemoteRtClaims($this->localIdentity->nodeId, $claims);

            return;
        }

        $this->sendToNode($nodeId, new PeerRtClaimsDTO($this->localIdentity->nodeId, $claims));
    }

    /**
     * Asks every handshaked peer what its agents own of the RT state.
     *
     * Implements {@see RtClaimMesh}. Broadcast where the other two claim frames are addressed,
     * on the same terms {@see PeerPlacementQueryDTO} is: a fresh leader has no map to address
     * anything from, and rebuilding it is exactly what this asks for.
     */
    public function broadcastRtClaimsQuery(): void
    {
        $this->broadcastToNodes(new PeerRtClaimsQueryDTO());
    }

    /**
     * Tells one node that a claim its agent made is refused in favour of another node's.
     *
     * Implements {@see RtClaimMesh}. Sent to the losing node alone: the holder is working
     * correctly and has nothing to be told, and a node with no link right now re-reports the
     * claim on the link that comes back, which is judged again.
     *
     * @param string $nodeId Node whose claim lost
     * @param PeerRtClaimRefusedDTO $refusal What was claimed, by which agent, and who holds it
     */
    public function sendRtClaimRefused(string $nodeId, PeerRtClaimRefusedDTO $refusal): void
    {
        if ($nodeId === $this->localIdentity->nodeId) {
            // The leader can lose to a follower like anybody else - it reports last as readily as
            // first - and there is no link to itself for the verdict to travel over.
            Hilos::$cluster?->rtClaimSink()?->applyRtClaimRefusal($refusal);

            return;
        }

        $this->sendToNode($nodeId, $refusal);
    }

    /**
     * Replaces this node's copy of an RT collection with the one its owner handed over.
     *
     * The receiving twin of {@see sendRtSnapshotToNode()}, and it stops here in the same way
     * {@see onRtSyncReceived()} does: nothing is passed on, since the owner addressed the node
     * that needed it. An unregistered sink or a failing apply is dropped and logged.
     *
     * @param PeerLink $link Link the snapshot arrived on
     * @param PeerRtSnapshotDTO $frame Received RT snapshot frame
     */
    public function onRtSnapshotReceived(PeerLink $link, PeerRtSnapshotDTO $frame): void
    {
        $sink = Hilos::$cluster?->rtSyncSink();
        if ($sink === null) {
            Logger::warning(
                "Dropping peer RT snapshot from node '{$frame->originNodeId}': no local RT sync sink registered",
            );
            return;
        }

        try {
            $sink->applyRemoteRtSnapshot(
                $frame->originNodeId,
                $frame->collectionKey,
                $frame->rows,
                $frame->scopeKeys,
                $frame->scopeSetKeys,
            );
        } catch (Throwable $e) {
            Logger::warning(
                "Failed to apply peer RT snapshot from node '{$frame->originNodeId}': {$e->getMessage()}",
            );
        }
    }

    /**
     * Routes a received request for a collection to the local sink so this node answers it.
     *
     * The asker is read off the frame rather than off the link, the way {@see onRtSyncReceived()}
     * reads the origin: what a node calls itself is what the reader map is keyed by, and the
     * answer is addressed through that map.
     *
     * @param PeerLink $link Link the request arrived on
     * @param PeerRtSnapshotQueryDTO $frame Received RT-snapshot-query frame
     */
    public function onRtSnapshotQueryReceived(PeerLink $link, PeerRtSnapshotQueryDTO $frame): void
    {
        $sink = Hilos::$cluster?->rtSyncSink();
        if ($sink === null) {
            Logger::warning(
                "Dropping peer RT snapshot query from node '{$frame->nodeId}': no local RT sync sink registered",
            );
            return;
        }

        try {
            $sink->answerRtSnapshotQuery($frame->nodeId, $frame->collectionKeys);
        } catch (Throwable $e) {
            Logger::warning(
                "Failed to answer peer RT snapshot query from node '{$frame->nodeId}': {$e->getMessage()}",
            );
        }
    }

    /**
     * Tops this node's copy of a collection up with the rows a holder offered.
     *
     * The receiving twin of {@see sendRtReplicaOfferToNode()}, and it stops here exactly as
     * {@see onRtSnapshotReceived()} does: what arrives is applied and never announced on.
     *
     * @param PeerLink $link Link the offer arrived on
     * @param PeerRtReplicaOfferDTO $frame Received RT-replica-offer frame
     */
    public function onRtReplicaOfferReceived(PeerLink $link, PeerRtReplicaOfferDTO $frame): void
    {
        $sink = Hilos::$cluster?->rtSyncSink();
        if ($sink === null) {
            Logger::warning(
                "Dropping peer RT replica offer from node '{$frame->originNodeId}': no local RT sync sink registered",
            );
            return;
        }

        try {
            $sink->applyRemoteRtReplicaOffer($frame->originNodeId, $frame->collectionKey, $frame->rows);
        } catch (Throwable $e) {
            Logger::warning(
                "Failed to apply peer RT replica offer from node '{$frame->originNodeId}': {$e->getMessage()}",
            );
        }
    }

    /**
     * Delivers one signal to a browser attached to another node.
     *
     * Implements {@see ClientMesh}. The addressed twin of {@see sendSignalToNode()}, and
     * best-effort in the same sense: a false return (no handshaked link to the node) is the
     * caller's cue to drop and log, which is what the local path already does for a socket that
     * has gone. Buffering and retry on an offline node are out of scope.
     *
     * @param string $nodeId Id of the node holding the connection
     * @param string $acceptKey Accept key of the connection to deliver to
     * @param SignalDTO $signal Signal to deliver on that node
     * @return bool True when a live link carried the frame, false when the node is unlinked
     */
    public function sendSignalToClientNode(string $nodeId, string $acceptKey, SignalDTO $signal): bool
    {
        return $this->sendToNode($nodeId, new PeerClientSignalDTO(
            $this->localIdentity->nodeId,
            $nodeId,
            $acceptKey,
            $signal,
        ));
    }

    /**
     * Delivers a received cross-node signal to the browser attached to this node.
     *
     * Verifies the frame is addressed to this node, then hands the already-resolved accept key
     * straight to the local delivery sink — no re-routing, which structurally rules out a
     * forward loop or a second fan-out. A mismatched target, an unregistered sink, or a failing
     * write is dropped and logged, keeping a bad forward from tearing down the daemon loop.
     *
     * @param PeerLink $link Link the signal arrived on
     * @param PeerClientSignalDTO $frame Received client signal-forward frame
     */
    public function onClientSignalReceived(PeerLink $link, PeerClientSignalDTO $frame): void
    {
        if ($frame->targetNodeId !== $this->localIdentity->nodeId) {
            Logger::warning(
                "Dropping peer client signal addressed to node '{$frame->targetNodeId}'"
                . " received on node '{$this->localIdentity->nodeId}'",
            );
            return;
        }

        $sink = Hilos::$cluster?->clientSignalSink();
        if ($sink === null) {
            Logger::warning(
                "Dropping peer client signal for '{$frame->acceptKey}': no local client signal sink registered",
            );
            return;
        }

        try {
            $sink->deliverSignalToClient($frame->acceptKey, $frame->signal);
        } catch (Throwable $e) {
            Logger::warning("Failed to deliver peer client signal to '{$frame->acceptKey}': {$e->getMessage()}");
        }
    }

    /**
     * Carries an agent's HTTP reply to the node holding the parked connection.
     *
     * The HTTP twin of {@see sendSignalToClientNode()}, and best-effort in the same sense: a
     * false return (no handshaked link to the node) is the caller's cue to drop and log. The
     * browser behind the connection is then left to its own timeout, which is what a node that
     * cannot be reached costs every frame addressed to it.
     *
     * @param string $nodeId Id of the node holding the parked connection
     * @param SignalDTO $signal HTTP_REPLY signal to write on that node
     * @return bool True when a live link carried the frame, false when the node is unlinked
     */
    public function sendHttpReplyToNode(string $nodeId, SignalDTO $signal): bool
    {
        return $this->sendToNode($nodeId, new PeerHttpReplyDTO($this->localIdentity->nodeId, $nodeId, $signal));
    }

    /**
     * @param string $nodeId Node holding the console connection
     * @param SignalDTO $signal COMMAND_REPLY signal to write there
     * @return bool True when a live link carried the reply
     */
    public function sendCommandReplyToNode(string $nodeId, SignalDTO $signal): bool
    {
        return $this->sendToNode($nodeId, new PeerCommandReplyDTO($this->localIdentity->nodeId, $nodeId, $signal));
    }

    /**
     * Writes a received cross-node HTTP reply to the connection this node parked for it.
     *
     * Verifies the frame is addressed to this node, then hands the reply straight to the local
     * sink - no re-routing, as for a client signal. A mismatched target, an unregistered sink,
     * or a failing write is dropped and logged, keeping a bad forward from tearing down the
     * daemon loop.
     *
     * @param PeerLink $link Link the reply arrived on
     * @param PeerHttpReplyDTO $frame Received HTTP reply-forward frame
     */
    public function onHttpReplyReceived(PeerLink $link, PeerHttpReplyDTO $frame): void
    {
        if ($frame->targetNodeId !== $this->localIdentity->nodeId) {
            Logger::warning(
                "Dropping peer HTTP reply addressed to node '{$frame->targetNodeId}'"
                . " received on node '{$this->localIdentity->nodeId}'",
            );
            return;
        }

        $sink = Hilos::$cluster?->clientSignalSink();
        if ($sink === null) {
            Logger::warning("Dropping peer HTTP reply from node '{$frame->originNodeId}': no local client signal sink registered");
            return;
        }

        try {
            $sink->deliverHttpReply($frame->signal);
        } catch (Throwable $e) {
            Logger::warning("Failed to deliver peer HTTP reply from node '{$frame->originNodeId}': {$e->getMessage()}");
        }
    }

    /**
     * Writes a cross-node command reply straight to the asking node's held connection.
     *
     * @param PeerLink $link Link the reply arrived on
     * @param PeerCommandReplyDTO $frame Received command reply frame
     */
    public function onCommandReplyReceived(PeerLink $link, PeerCommandReplyDTO $frame): void
    {
        if ($frame->targetNodeId !== $this->localIdentity->nodeId) {
            Logger::warning(
                "Dropping peer command reply addressed to node '{$frame->targetNodeId}'"
                . " received on node '{$this->localIdentity->nodeId}'",
            );
            return;
        }

        $sink = Hilos::$cluster?->clientSignalSink();
        if ($sink === null) {
            Logger::warning("Dropping peer command reply from node '{$frame->originNodeId}': no local client signal sink registered");
            return;
        }

        try {
            $sink->deliverCommandReply($frame->signal);
        } catch (Throwable $e) {
            Logger::warning("Failed to deliver peer command reply from node '{$frame->originNodeId}': {$e->getMessage()}");
        }
    }

    /**
     * Asks every other node to fan one signal out to the browsers it holds.
     *
     * Implements {@see ClientMesh}. The unaddressed twin of {@see sendSignalToClientNode()},
     * and unaddressed by necessity rather than by convenience: only the node a browser hangs
     * on can say whether it is subscribed, so the frame names the signal and leaves the
     * resolving to each receiver. Best-effort like every other broadcast here — a node that is
     * not linked misses it, and a fan-out has no backlog to catch up from.
     *
     * @param SignalDTO $signal Signal every node expands against its own subscription registry
     */
    public function broadcastClientFanout(SignalDTO $signal): void
    {
        $this->broadcastToNodes(new PeerClientFanoutDTO($this->localIdentity->nodeId, $signal));
    }

    /**
     * Captures exact links now; a replacement never receives the remainder of an old fan-out.
     * The retained master dispatcher consumes this cursor across portions.
     *
     * @param SignalDTO $signal Original browser fan-out
     * @return Generator<int, void> Effect-free initial yield, then one physical link visit per step
     */
    public function clientFanoutDelivery(SignalDTO $signal): Generator
    {
        return $this->walkFanoutFrame(
            $this->clientCandidates(),
            new PeerClientFanoutDTO($this->localIdentity->nodeId, $signal),
            static fn(PeerLink $link): bool => $link->remoteIdentity() !== null,
        );
    }

    /**
     * Runs the existing announcement hook once, capturing its physical broadcasts at their
     * original call sites. It neither drives the event loop nor changes any peer wire frame.
     *
     * @param Closure(): void $announce Existing master announcement hook
     * @return Generator<int, void> Captured broadcasts in their original FIFO, one link visit per step
     */
    public function captureBroadcastDelivery(Closure $announce): Generator
    {
        $previous = $this->capturedBroadcasts;
        $captured = new SplQueue();
        $this->capturedBroadcasts = $captured;
        try {
            $announce();
        } finally {
            $this->capturedBroadcasts = $previous;
        }

        return $this->walkCapturedBroadcasts($captured);
    }

    /**
     * Resolves the exact original roster slot before invoking the existing send door once.
     * A missing or replaced route is still passed to that door as missing, preserving its refusal.
     *
     * @param string $nodeId Addressed node
     * @param Closure(): void $deliver Existing addressed delivery and its outcome handling
     * @return Generator<int, void> Constant-time membership preparation and one scoped delivery attempt
     */
    public function addressedDelivery(string $nodeId, Closure $deliver): Generator
    {
        $link = $this->activeLinks[$nodeId] ?? null;
        return $this->walkAddressedDelivery($nodeId, $link, $link === null ? null : ($this->peerSlots[$link] ?? null), $deliver);
    }

    /**
     * Captures a route before a retained source build. The predicate owns neither the server
     * nor the link, and a route that was absent, replaced or detached cannot become valid later.
     *
     * @param string $nodeId Original addressed node
     * @return Closure(): bool Whether that exact transport slot still answers for the node
     */
    public function nodeRouteGuard(string $nodeId): Closure
    {
        $server = WeakReference::create($this);
        $link = $this->activeLinks[$nodeId] ?? null;
        $slot = $link === null ? null : ($this->peerSlots[$link] ?? null);
        $link = $link === null ? null : WeakReference::create($link);
        return static function () use ($server, $link, $slot, $nodeId): bool {
            $owner = $server->get();
            $original = $link?->get();
            return $owner !== null && $original !== null && $slot !== null
                && $owner->isCurrentClient($slot, $original) && ($owner->activeLinks[$nodeId] ?? null) === $original;
        };
    }

    /**
     * Asks all other masters to close sibling sockets of a rotated session.
     *
     * @param list<string> $acceptKeys Sibling keys not held by this master
     */
    public function broadcastConnectionDrop(array $acceptKeys): void
    {
        $this->broadcastToMasters(new PeerConnectionDropDTO($this->localIdentity->nodeId, $acceptKeys));
    }

    /**
     * Delivers a sibling-drop request to this master's connection sink without forwarding it.
     *
     * @param PeerLink $link Link the request arrived on
     * @param PeerConnectionDropDTO $frame Received connection-drop frame
     */
    public function onConnectionDropReceived(PeerLink $link, PeerConnectionDropDTO $frame): void
    {
        $sink = Hilos::$cluster?->clientSignalSink();
        if ($sink === null) {
            Logger::warning(
                "Dropping peer connection drop from node '{$frame->originNodeId}':"
                . ' no local client signal sink registered',
            );
            return;
        }

        try {
            $sink->dropConnectionsForNode($frame->originNodeId, $frame->acceptKeys);
        } catch (Throwable $e) {
            Logger::warning(
                "Failed to drop peer connections from node '{$frame->originNodeId}': {$e->getMessage()}",
            );
        }
    }

    /**
     * Broadcasts a page access re-decision announcement to every other node.
     *
     * @param SignalDTO $signal By-user or by-connection announcement
     */
    public function broadcastPageAccessReassess(SignalDTO $signal): void
    {
        $this->broadcastToNodes(new PeerPageAccessReassessDTO($this->localIdentity->nodeId, $signal));
    }

    /**
     * Gives a received page access re-decision to the local daemon without forwarding it.
     *
     * @param PeerLink $link Link the announcement arrived on
     * @param PeerPageAccessReassessDTO $frame Received re-decision frame
     */
    public function onPageAccessReassessReceived(PeerLink $link, PeerPageAccessReassessDTO $frame): void
    {
        $sink = Hilos::$cluster?->clientSignalSink();
        if ($sink === null) {
            Logger::warning(
                "Dropping peer page access re-decision from node '{$frame->originNodeId}':"
                . ' no local client signal sink registered',
            );
            return;
        }

        try {
            $sink->deliverPageAccessReassess($frame->originNodeId, $frame->signal);
        } catch (Throwable $e) {
            Logger::warning(
                "Failed to deliver peer page access re-decision from node '{$frame->originNodeId}': {$e->getMessage()}",
            );
        }
    }

    /**
     * Asks every other node to drop every group membership of connections whose person changed.
     *
     * Implements {@see ClientMesh}. Sent to slaves too: the membership is written on the master
     * of the node whose agent admitted the join, and that may be any node (HIL-1284).
     *
     * @param list<string> $acceptKeys Connections leaving every group
     */
    public function broadcastGroupLeaveAll(array $acceptKeys): void
    {
        $this->broadcastToNodes(new PeerGroupLeaveAllDTO($this->localIdentity->nodeId, $acceptKeys));
    }

    /**
     * Gives a received group leave-all to the local daemon without forwarding it.
     *
     * @param PeerLink $link Link the announcement arrived on
     * @param PeerGroupLeaveAllDTO $frame Received leave-all frame
     */
    public function onGroupLeaveAllReceived(PeerLink $link, PeerGroupLeaveAllDTO $frame): void
    {
        $sink = Hilos::$cluster?->clientSignalSink();
        if ($sink === null) {
            Logger::warning(
                "Dropping peer group leave-all from node '{$frame->originNodeId}':"
                . ' no local client signal sink registered',
            );
            return;
        }

        try {
            $sink->deliverGroupLeaveAll($frame->originNodeId, $frame->acceptKeys);
        } catch (Throwable $e) {
            Logger::warning(
                "Failed to deliver peer group leave-all from node '{$frame->originNodeId}': {$e->getMessage()}",
            );
        }
    }

    /**
     * Fans a received cross-node job out to the browsers attached to this node.
     *
     * Hands the signal to the local delivery sink, which resolves it against this node's own
     * subscription registry — the one thing the sending node could not do — and writes to
     * whoever comes out. It stops here: passing it on is what the sending node already did for
     * everyone, and not doing it is the whole echo defense, the same one-hop rule
     * {@see onRtSyncReceived()} stands on. An unregistered sink or a failing expansion is
     * dropped and logged, so a bad frame cannot end the daemon loop.
     *
     * @param PeerLink $link Link the fan-out arrived on
     * @param PeerClientFanoutDTO $frame Received client fan-out frame
     */
    public function onClientFanoutReceived(PeerLink $link, PeerClientFanoutDTO $frame): void
    {
        $sink = Hilos::$cluster?->clientSignalSink();
        if ($sink === null) {
            Logger::warning(
                "Dropping peer client fanout from node '{$frame->originNodeId}':"
                . " no local client signal sink registered",
            );
            return;
        }

        try {
            $sink->deliverFanoutToClients($frame->originNodeId, $frame->signal);
        } catch (Throwable $e) {
            Logger::warning(
                "Failed to fan out peer client signal from node '{$frame->originNodeId}': {$e->getMessage()}",
            );
        }
    }

    /**
     * Hands one node the whole set of browser connections this node holds.
     *
     * Implements {@see ClientMesh}. Addressed rather than broadcast for the same reason
     * {@see sendRtSnapshotToNode()} is: only the node on the other end of the new link is
     * behind on this set, and the others have been kept current by the deltas. A node that is
     * not linked is silently skipped — it will be handed the set when it links.
     *
     * @param string $nodeId Node that just linked and is being handed the set
     * @param list<string> $acceptKeys Every accept key this node holds right now
     */
    public function sendConnectionsSnapshotToNode(string $nodeId, array $acceptKeys): void
    {
        $this->sendToNode($nodeId, new PeerConnectionsSnapshotDTO($this->localIdentity->nodeId, $acceptKeys));
    }

    /**
     * Announces which browser connections this node has gained and lost to every other node.
     *
     * Implements {@see ClientMesh}. Broadcast because any node may be the one whose agent
     * answers one of these browsers, and best-effort in the same sense as
     * {@see broadcastRtSync()}: a node that is not linked right now misses the delta and is
     * handed the whole set the moment it links.
     *
     * @param list<string> $opened Accept keys this node has gained since its last announcement
     * @param list<string> $closed Accept keys this node has lost since its last announcement
     */
    public function broadcastConnectionsDelta(array $opened, array $closed): void
    {
        $this->broadcastToNodes(new PeerConnectionsDeltaDTO($this->localIdentity->nodeId, $opened, $closed));
    }

    /**
     * Rebuilds this node's index of the browser connections another node holds.
     *
     * Stops here like every other received frame: the announcing node told everyone itself,
     * and passing it on is the echo this mesh was built to structurally rule out. A missing
     * index means the daemon has not registered one yet, which off-cluster it never does.
     *
     * @param PeerLink $link Link the snapshot arrived on
     * @param PeerConnectionsSnapshotDTO $frame Received connection-snapshot frame
     */
    public function onConnectionsSnapshotReceived(PeerLink $link, PeerConnectionsSnapshotDTO $frame): void
    {
        $connections = Hilos::$cluster?->clientConnections();
        if ($connections === null) {
            Logger::warning(
                "Dropping peer connections snapshot from node '{$frame->nodeId}': no local connection index registered",
            );
            return;
        }

        $connections->applySnapshot($frame->nodeId, $frame->acceptKeys);
    }

    /**
     * Applies another node's report of the browser connections it has gained and lost.
     *
     * @param PeerLink $link Link the delta arrived on
     * @param PeerConnectionsDeltaDTO $frame Received connection-delta frame
     */
    public function onConnectionsDeltaReceived(PeerLink $link, PeerConnectionsDeltaDTO $frame): void
    {
        $connections = Hilos::$cluster?->clientConnections();
        if ($connections === null) {
            Logger::warning(
                "Dropping peer connections delta from node '{$frame->nodeId}': no local connection index registered",
            );
            return;
        }

        $connections->applyDelta($frame->nodeId, $frame->opened, $frame->closed);
    }

    /**
     * Delivers a placement frame to every handshaked peer, master or slave alike.
     *
     * Backs the leader-change rebuild query, which every node must answer.
     *
     * @param PeerDTO $frame Placement frame to broadcast
     */
    public function broadcastToNodes(PeerDTO $frame): void
    {
        $this->fanOutFrame($frame, static fn(PeerLink $link): bool => $link->remoteIdentity() !== null);
    }

    /**
     * Delivers a frame about one collection only to the peers that said they read it.
     *
     * The addressed form of {@see broadcastToNodes()}, and the reason the two are separate rather
     * than one method with an optional key: membership decides who gets a placement frame, while
     * interest decides who gets a replica, and a frame sent by the wrong question either wakes a
     * node that has no use for it or never reaches one that does.
     *
     * A node absent from the map reads nothing of this kind and is skipped, which is the same
     * answer the map gives for a node that has not spoken yet. That silence is not a gap: a node
     * announces its interest as its handshake completes and again whenever it moves, and until it
     * has, it holds no copy for a delta to land on anyway.
     *
     * @param PeerDTO $frame Frame to deliver
     * @param string $kind Source kind of the collection, KIND_DB or KIND_RT of {@see SourceChange}
     * @param string $collectionKey Collection the frame is about
     */
    public function broadcastToNodesHolding(PeerDTO $frame, string $kind, string $collectionKey): void
    {
        $this->fanOutFrame($frame, function (PeerLink $link) use ($kind, $collectionKey): bool {
            $nodeId = $link->remoteIdentity()?->nodeId;

            return $nodeId !== null && $this->nodeReaderMap->holds($nodeId, $kind, $collectionKey);
        });
    }

    /**
     * Writes one frame to every link a broadcast's own question lets through, packing it once.
     *
     * The single loop behind all five broadcasts of this class, which differ in nothing but
     * whom they reach. The frame is the same object for all of them, so a second `json_encode`
     * of it is work the master loop pays for nothing - and this loop is on the master, where
     * every RT and DB sync fact passes. The same shape a floor below:
     * {@see DaemonManager::writeFrameToWorkers()} for the worker links, and
     * {@see DaemonManager::encodeSignalFrame()} with {@see DaemonManager::sendToAllClients()}
     * for the WebSocket ones.
     *
     * One pass rather than the fix repeated five times, because five copies of a naive loop
     * are how this cost spread here in the first place: a sixth broadcast written against this
     * method cannot pack per link even by accident.
     *
     * Packing is lazy instead of unconditional before the loop because a filter is allowed to
     * let nobody through - a node with no handshaked link yet, a mesh with no other master, a
     * collection no peer reads - and then the frame is never packed at all.
     *
     * @param PeerDTO $frame Frame to fan out
     * @param callable(PeerLink): bool $reaches Whether this broadcast reaches the given link
     */
    private function fanOutFrame(PeerDTO $frame, callable $reaches): void
    {
        $delivery = $this->walkFanoutFrame($this->clientCandidates(), $frame, $reaches);
        if ($this->capturedBroadcasts !== null) {
            $this->capturedBroadcasts->enqueue($delivery);
            return;
        }
        // The legacy entry still drains completely; the retained dispatcher uses the same
        // physical walk a portion at a time, without a second implementation of its filters.
        foreach ($delivery as $_) {
        }
    }

    /**
     * @param SplQueue<Generator<int, void>> $broadcasts Physical walks captured by one announcement hook
     * @return Generator<int, void> Original broadcast order, including filtered link visits
     */
    private function walkCapturedBroadcasts(SplQueue $broadcasts): Generator
    {
        yield;
        while (!$broadcasts->isEmpty()) {
            $delivery = $broadcasts->dequeue();
            yield from $delivery;
            unset($delivery);
        }
    }

    /**
     * Reader interest cannot overtake a partial source answer. The cursor owns the frame,
     * never its server or link; a new transport cannot inherit the original report.
     *
     * @param PeerLink $link Original inbound transport
     * @param PeerSourceInterestDTO $frame Complete original interest report
     * @return Generator<int, void> Private preparation by name, then one complete guarded report application
     */
    private function sourceInterestFrameDelivery(PeerLink $link, PeerSourceInterestDTO $frame): Generator
    {
        $server = WeakReference::create($this);
        $slot = $this->peerSlots[$link] ?? null;
        $nodeId = $link->remoteIdentity()?->nodeId;
        $binding = $this->sourceInterestDelivery === null ? null : WeakReference::create($this->sourceInterestDelivery);
        $link = WeakReference::create($link);
        $isCurrent = static function () use ($server, $slot, $nodeId, $link, $binding): bool {
            $owner = $server->get();
            $original = $link->get();
            return $owner !== null && $original !== null && $slot !== null && $nodeId !== null
                && $binding?->get() !== null && $owner->sourceInterestDelivery === $binding->get()
                && $owner->isCurrentClient($slot, $original)
                && ($owner->activeLinks[$nodeId] ?? null) === $original;
        };
        $map = WeakReference::create($this->nodeReaderMap);
        return (static function () use ($map, $frame, $isCurrent): Generator {
            yield;
            if (!$isCurrent() || $map->get() === null) {
                return;
            }
            $delivery = $map->get()->noteReportDelivery($frame->nodeId, $frame->rtCollections, $frame->dbCollections, $isCurrent);
            $added = yield from $delivery;
            if ($added[SourceChange::KIND_RT] !== [] && $isCurrent()) {
                Hilos::$cluster?->rtSyncSink()?->handOverRtSnapshots($frame->nodeId);
            }
            yield;
        })();
    }

    /**
     * DB interest changes filtering alone; new RT interest also requests the existing hand-over.
     *
     * @param PeerSourceInterestDTO $frame Complete reader-interest values in original source order
     */
    private function applySourceInterest(PeerSourceInterestDTO $frame): void
    {
        $this->nodeReaderMap->note($frame->nodeId, SourceChange::KIND_DB, $frame->dbCollections);
        $added = $this->nodeReaderMap->note($frame->nodeId, SourceChange::KIND_RT, $frame->rtCollections);
        if ($added !== []) {
            Hilos::$cluster?->rtSyncSink()?->handOverRtSnapshots($frame->nodeId);
        }
    }

    /**
     * @param string $nodeId Addressed node
     * @param ?PeerLink $link Original route, absent when the lookup began without a route
     * @param ?int $slot Exact original roster slot, absent without an attached route
     * @param Closure(): void $deliver Existing send door
     * @return Generator<int, void> One membership check and one attempt, including the missing-route case
     */
    private function walkAddressedDelivery(string $nodeId, ?PeerLink $link, ?int $slot, Closure $deliver): Generator
    {
        yield;
        $present = $link !== null && $slot !== null && $this->isCurrentClient($slot, $link)
            && ($this->activeLinks[$nodeId] ?? null) === $link;
        yield;
        $this->invokeAddressedDelivery($nodeId, $present ? $link : null, $present ? $slot : null, $deliver);
    }

    /**
     * @param string $nodeId Addressed node
     * @param ?PeerLink $link Exact original live route, or null after absence/replacement
     * @param ?int $slot Original roster slot, or null without a route
     * @param Closure(): void $deliver Existing send door, executed once without a second roster scan
     */
    private function invokeAddressedDelivery(string $nodeId, ?PeerLink $link, ?int $slot, Closure $deliver): void
    {
        $previousNode = $this->deliveryNodeId;
        $previousLink = $this->deliveryLink;
        $previousSlot = $this->deliverySlot;
        $this->deliveryNodeId = $nodeId;
        $this->deliveryLink = $link;
        $this->deliverySlot = $slot;
        try {
            $deliver();
        } finally {
            $this->deliveryNodeId = $previousNode;
            $this->deliveryLink = $previousLink;
            $this->deliverySlot = $previousSlot;
        }
    }

    /**
     * The roster cursor checks the original slot in O(1); the active map checks route identity.
     * No per-recipient in_array scan through activeLinkToNode is needed in this walk.
     *
     * @param Generator<int, ?PeerLink> $clients Original peer roster, including stale slots
     * @param PeerDTO $frame Shared broadcast frame
     * @param callable(PeerLink): bool $reaches Existing broadcast filter
     * @return Generator<int, void> One physical visit after the effect-free initial yield
     */
    private function walkFanoutFrame(Generator $clients, PeerDTO $frame, callable $reaches): Generator
    {
        $encoded = null;
        yield;
        foreach ($clients as $client) {
            $nodeId = $client?->remoteIdentity()?->nodeId;
            if ($nodeId !== null && ($this->activeLinks[$nodeId] ?? null) === $client && $reaches($client)) {
                $encoded ??= $frame->toJson();
                $client->sendEncodedFrame($encoded);
            }
            yield;
        }
    }

    /**
     * Returns the advertised capabilities of an online node from the registry, or null
     * when the node is unknown or offline.
     *
     * @param string $nodeId Node id to look up
     * @return ?list<string> Advertised capability tags, or null when unknown or offline
     */
    public function nodeCapabilities(string $nodeId): ?array
    {
        $registry = $this->registry();
        if ($registry === null) {
            return null;
        }

        foreach ($registry->snapshot() as $node) {
            if ($node->nodeId === $nodeId && $node->online) {
                return $node->capabilities;
            }
        }

        return null;
    }

    /**
     * Returns the ids of every currently-online node, including the local node.
     *
     * Reads the master registry so failover can scan the online set for a capable host. Online
     * means reachable over this node's own link (HIL-1059). A registry hiccup yields an empty
     * set — the safe side, which simply degrades a re-placement to unplaced rather than risking a
     * bad target.
     *
     * @return list<string> Online node ids
     */
    public function onlineNodeIds(): array
    {
        $registry = $this->registry();
        if ($registry === null) {
            return [];
        }

        $ids = [];
        foreach ($registry->snapshot() as $node) {
            if ($node->online) {
                $ids[] = $node->nodeId;
            }
        }

        return $ids;
    }

    /**
     * Returns the ids of the nodes this one currently holds a handshaked link to.
     *
     * The question an addressed frame asks: where will it arrive right now. Online in the registry
     * implies such a link (HIL-1059), but the converse can fail in one window - a node whose leave
     * frame has arrived is offline while its link is still up - so an addressed frame asks the
     * links, not membership. The local node is never among them: a node holds no link to itself.
     *
     * @return list<string> Node ids behind a handshaked link, each named once
     */
    public function linkedNodeIds(): array
    {
        $ids = [];
        foreach ($this->activeLinks as $nodeId => $link) {
            if ($this->activeLinkToNode($nodeId) === $link) {
                $ids[] = $nodeId;
            }
        }

        return $ids;
    }

    /**
     * Reports whether membership knows this node and currently has it down.
     *
     * The threshold is "known AND offline" rather than "not among the online ones", and the two
     * part company on the node the leader has not heard of YET: reading that as departure would
     * throw away a claim nobody would restate until ownership next changed. A node that LEFT is
     * not absent from the registry — it keeps its row and turns offline in it (HIL-337) — so the
     * leader has something to tell the two apart by. "Known AND offline" also covers a node heard
     * of only from a neighbour and not yet seen (HIL-1059), and that is no departure either - but
     * it never reaches this question: the claim report travels over the sender's own link
     * ({@see announceRtClaims()}), and by that link's handshake the sender is already online here.
     *
     * An unavailable registry answers false, which judges the report the way it was judged before
     * this question existed. A registry hiccup turning into a silent loss of claims would be a
     * worse failure than the one this guards.
     *
     * @param string $nodeId Node id to look up
     * @return bool True only when membership holds the node and marks it offline
     */
    public function nodeHasLeftTheMesh(string $nodeId): bool
    {
        $registry = $this->registry();
        if ($registry === null) {
            return false;
        }

        foreach ($registry->snapshot() as $node) {
            if ($node->nodeId === $nodeId) {
                return !$node->online;
            }
        }

        return false;
    }

    /**
     * Announces one node entry to every handshaked link, optionally skipping its source.
     *
     * @param PeerNodeEntry $entry Node entry to announce
     * @param ?PeerLink $source Link the change came from, excluded from the fan-out; null fans out to all
     */
    private function broadcastAnnounce(PeerNodeEntry $entry, ?PeerLink $source = null): void
    {
        $this->fanOutFrame(
            new PeerAnnounceDTO($entry),
            static fn(PeerLink $link): bool => $link !== $source && $link->remoteIdentity() !== null,
        );
    }

    /**
     * Finds the seed dial that owns a link, or null for an inbound link.
     *
     * @param PeerLink $link Link to match
     * @return ?PeerDial Owning dial, or null when the link was accepted
     */
    private function dialForLink(PeerLink $link): ?PeerDial
    {
        foreach ($this->allDials() as $dial) {
            if ($dial->link === $link) {
                return $dial;
            }
        }

        return null;
    }

    /** @param string $nodeId Peer whose link or membership fact changed */
    private function wakeNodeDials(string $nodeId): void
    {
        ($this->peerDials[$nodeId] ?? null)?->ready?->markReady();
        foreach ($this->seedDials as $dial) {
            if ($dial->remoteNodeId === $nodeId) {
                $dial->ready?->markReady();
            }
        }
    }

    /**
     * Returns the sole working link, excluding closing and draining connections.
     *
     * @param string $nodeId Remote node id
     * @return ?PeerLink Working link or null
     */
    private function activeLinkToNode(string $nodeId): ?PeerLink
    {
        if ($this->deliveryNodeId === $nodeId) {
            return $this->deliveryLink !== null && $this->deliverySlot !== null
                && $this->isCurrentClient($this->deliverySlot, $this->deliveryLink)
                && ($this->activeLinks[$nodeId] ?? null) === $this->deliveryLink ? $this->deliveryLink : null;
        }
        $link = $this->activeLinks[$nodeId] ?? null;
        $slot = $link === null ? null : ($this->peerSlots[$link] ?? null);
        if ($link === null || $slot === null || !$this->isCurrentClient($slot, $link)) {
            return null;
        }

        return $link;
    }

    /**
     * Reports whether a working link currently reaches the given node.
     *
     * @param string $nodeId Remote node id
     * @return bool True when the node has a working route
     */
    private function hasHandshakedLinkToNode(string $nodeId): bool
    {
        return $this->hasActiveLinkToNode($nodeId);
    }

    /**
     * Finds an online node in the registry by id, or null when absent or offline.
     *
     * @param ClusterRegistry $registry Master registry
     * @param string $nodeId Node id to look up
     * @return ?ClusterNode Online node record, or null
     */
    private function onlineNodeById(ClusterRegistry $registry, string $nodeId): ?ClusterNode
    {
        foreach ($registry->snapshot() as $node) {
            if ($node->nodeId === $nodeId && $node->online) {
                return $node;
            }
        }

        return null;
    }

    /**
     * Queues diagnostics independently of agents and subscribers; null RT means no runtime state.
     *
     * @param float $now Current tick observation in seconds
     * @throws EnvException When the consensus configuration is invalid
     * @throws PeerTransportException When the local snapshot is malformed
     * @throws InvalidFormatException When the projected rows violate the RT contract
     * @throws RtActionsCollectionNameNullException When the runtime action lacks its collection name
     * @throws RtTruthSourceWriteNotAllowedException When this process does not own the local row
     * @throws RtActionsStateCollectionNullException When the cluster registry is unavailable
     */
    private function tickProtectedModeNodeReports(float $now): void
    {
        $this->refreshProtectedModeReportAuthority();
        $leadership = Hilos::$cluster?->leadership();
        $participants = null;
        $pending = null;
        if ($leadership?->amLeader() && $this->protectedMode instanceof ProtectedModeQuiesceRoster) {
            $participants = $this->protectedMode->quiesceNodeIds();
            $pending = $participants !== null ? $this->protectedMode->pendingNodeIds() : null;
        }
        $report = new PeerProtectedModeNodeReportDTO(
            Hilos::$rt?->hilosProtectedModeRuntime?->phase,
            $this->protectedModeReportTerm,
            $participants,
            $pending,
            $participants !== null ? Hilos::$rt?->hilosProtectedModeRuntime?->initiatorNodeId : null,
        );
        $links = [];
        foreach ($this->activeLinks as $nodeId => $link) {
            if ($this->activeLinkToNode($nodeId) === $link) {
                $links[$nodeId] = $link;
            }
        }
        ksort($links, SORT_STRING);
        $this->protectedModeNodeReports = array_intersect_key($this->protectedModeNodeReports, $links);
        if ($this->lastProtectedModeNodeReport != $report || $this->protectedModeReportLinks !== $links
            || $this->lastProtectedModeNodeReportAt === null
            || $now - $this->lastProtectedModeNodeReportAt >= self::PROTECTED_MODE_NODE_REPORT_INTERVAL_SEC
        ) {
            $this->broadcastToMasters($report);
            $this->lastProtectedModeNodeReport = $report;
            $this->lastProtectedModeNodeReportAt = $now;
            $this->protectedModeReportLinks = $links;
        }

        if ($this->localIdentity->role !== NodeRole::Master || Hilos::$rt?->hilosProtectedModeRuntime === null) {
            return;
        }
        $registry = $this->registry();
        if ($registry === null) {
            return;
        }
        $nodeIds = [];
        foreach ($registry->snapshot() as $node) {
            $nodeIds[] = $node->nodeId;
        }
        $nodes = ProtectedModeNodesCollector::project(
            $nodeIds,
            [...array_keys($links), $this->localIdentity->nodeId],
            [...$this->protectedModeNodeReports, $this->localIdentity->nodeId => $report],
            $this->protectedModeReportLeaderId,
            $this->protectedModeReportTerm,
        );
        if (Hilos::$rt->hilosProtectedModeRuntime->maintenanceNodes !== $nodes) {
            Hilos::$rt->hilosProtectedModeRuntime->actions->updateMaintenanceNodes($nodes);
        }
    }

    /**
     * A changed leader/term cannot revive an earlier roster, even if the same leader returns.
     * The independent phase fact survives until its link changes or its sender reports again.
     *
     * @throws EnvException When the consensus configuration is invalid
     * @throws PeerTransportException When invalidating a malformed cached snapshot
     */
    private function refreshProtectedModeReportAuthority(): void
    {
        $leadership = Hilos::$cluster?->leadership();
        $leaderId = $leadership?->leaderId();
        $term = $leadership instanceof ConsensusInspection ? $leadership->term() : null;
        if ($leaderId === $this->protectedModeReportLeaderId && $term === $this->protectedModeReportTerm) {
            return;
        }
        foreach ($this->protectedModeNodeReports as $nodeId => $report) {
            $this->protectedModeNodeReports[$nodeId] = new PeerProtectedModeNodeReportDTO($report->phase, $report->term, null, null, null);
        }
        $this->protectedModeReportLeaderId = $leaderId;
        $this->protectedModeReportTerm = $term;
    }

    /**
     * Returns the master cluster registry, or null when it is unavailable.
     *
     * A registry hiccup must not tear down the daemon loop, so any failure is
     * logged and swallowed rather than propagated.
     *
     * @return ?ClusterRegistry Master registry, or null on failure
     */
    private function registry(): ?ClusterRegistry
    {
        try {
            return Hilos::$cluster?->registry();
        } catch (Throwable $e) {
            Logger::warning("Cluster registry unavailable: {$e->getMessage()}");
            return null;
        }
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Tests\Unit;

use Closure;
use Hilos\Cluster\DbSyncMesh;
use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Cluster\Peer\PeerServer;
use Hilos\Constants\SignalConstants;
use Hilos\Constants\SignalTypeConstants;
use Hilos\Constants\WorkerConstants;
use Hilos\Core\Agent\Daemon\AgentDaemonInterface;
use Hilos\Core\Agent\Daemon\AgentManagerDaemon;
use Hilos\Core\Agent\Exception\AgentDaemonCreationFailedException;
use Hilos\Core\Agent\Exception\AgentException;
use Hilos\Core\Daemon\DaemonManager;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Router\DTO\SignalDTO;
use Hilos\Core\Router\SignalName;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Router\SignalSource;
use Hilos\Core\Router\SignalType;
use Hilos\Core\Sync\DTO\DbSyncUpdatedSignalData;
use Hilos\Core\Sync\DTO\RtSyncUpdatedSignalData;
use Hilos\Database\Context\DbContext;
use Hilos\Hilos;
use Hilos\Socket\Client\WorkerClient;
use Hilos\Socket\Server\WorkerServer;
use Hilos\Socket\Worker\WorkerDTO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * How a database write travels between the nodes of a cluster (HIL-670).
 *
 * The nodes share one database, but not the rows they have read out of it: each keeps its own
 * copy in memory, and until this frame existed a row another node changed stayed as this node
 * first read it, for as long as the process lived. So the fact is announced — the very fact this
 * node's own workers are told — and every other node applies it to whatever it is holding.
 *
 * What is pinned here is the shape of that path: the fact leaves the writing node, it is applied
 * on the receiving node and handed to that node's own workers, and it stops there. The last one
 * is the load-bearing case, and for the same reason it is in the RT twin
 * ({@see DaemonManagerRtSyncPeerTest}): phase-1 of this cluster died on a gossip echo, and the
 * defense chosen instead of hop counters was structural. A change that starts forwarding
 * replicas would read as harmless.
 */
final class DaemonManagerDbSyncPeerTest extends TestCase
{
    /** @var string Node the replicas in these cases arrive from */
    public const string REMOTE_NODE = 'node-b';

    /** @var string Collection every case syncs */
    public const string COLLECTION = 'settings';

    /** @var string Row id every case syncs */
    public const string ROW_ID = '7';

    protected function tearDown(): void
    {
        Hilos::$sr = null;
        Hilos::$db = null;

        parent::tearDown();
    }

    /**
     * @throws AgentException When routing the signal fails
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testADbWriteOnThisNodeIsAnnouncedToTheMeshOnce(): void
    {
        $daemon = new DbSyncPeerTestManager();

        $daemon->queueDbSyncUpdated('Ada');
        $daemon->dispatch();

        $this->assertSame(
            [$daemon->peerServer],
            $daemon->announcedThrough,
            'The dispatch pass announces through the peer server it already found for this pass',
        );
        $announced = $daemon->mesh->singleAnnouncement();
        $this->assertSame(SignalTypeConstants::DB_SYNC_UPDATED, $announced->signalType);
    }

    /**
     * The signal is announced whole rather than unpacked into fields of a frame, so the peers
     * apply the very fact this node's worker produced.
     *
     * @throws AgentException When routing the signal fails
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testTheAnnouncementCarriesTheWrittenRowItself(): void
    {
        $daemon = new DbSyncPeerTestManager();

        $daemon->queueDbSyncUpdated('Ada');
        $daemon->dispatch();

        $data = $daemon->mesh->singleAnnouncement()->signal->data;
        $this->assertInstanceOf(DbSyncUpdatedSignalData::class, $data);
        $this->assertSame(self::COLLECTION, $data->collectionKey);
        $this->assertSame(['title' => 'Ada'], $data->row);
    }

    /**
     * No ownership question is asked before announcing, and that is the difference from the RT
     * twin rather than an omission: the row was written to the database every node reads, so the
     * fact is not this node's opinion about a collection it may not speak for.
     *
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testEveryDbFactIsAnnouncedWithoutAskingWhoOwnsTheCollection(): void
    {
        $daemon = new DbSyncPeerTestManager();

        $daemon->announce($daemon->dbSyncUpdated('Ada'));

        $this->assertCount(1, $daemon->mesh->announcements);
    }

    /**
     * The database being replaced is a restore, with a peer protocol and a barrier of its own.
     * Announcing it here would tell the peers about the swap a second time, on a channel where
     * nothing is waiting for their answer.
     *
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testTheDatabaseReplacementIsNotAnnouncedAsADbFact(): void
    {
        $daemon = new DbSyncPeerTestManager();

        $daemon->announce($daemon->signalOfType(SignalTypeConstants::DB_REHYDRATE, SignalConstants::DB_REHYDRATE));

        $this->assertSame([], $daemon->mesh->announcements);
    }

    /**
     * The two announce steps sit in one branch and each filters its own half, so an RT fact must
     * not leave through the DB port: a receiving node would apply it as a database row.
     *
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testAnRtFactIsNotAnnouncedThroughTheDbPort(): void
    {
        $daemon = new DbSyncPeerTestManager();

        $daemon->announce($daemon->rtSyncUpdated());

        $this->assertSame([], $daemon->mesh->announcements);
    }

    /**
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function testOffClusterNothingIsAnnounced(): void
    {
        $daemon = new DbSyncPeerTestManager();

        $daemon->announceWithoutMesh($daemon->dbSyncUpdated('Ada'));

        $this->assertSame([], $daemon->mesh->announcements);
    }

    /**
     * A replica is handed to this node's own workers, because their copies of the row are as
     * stale as the master's and nothing else will tell them.
     */
    public function testAReceivedReplicaIsHandedToThisNodesWorkers(): void
    {
        $daemon = new DbSyncPeerTestManager();

        $daemon->receive($daemon->dbSyncUpdated('Ada'));

        $this->assertSame(
            [WorkerConstants::MESSAGE_DB_SYNC_UPDATED],
            $daemon->workerServer->frameTypes(),
        );
    }

    /**
     * And stops there. The node that wrote the row announced it to everyone, so passing it on is
     * an echo, and one hop is the whole defense.
     */
    public function testAReceivedReplicaIsNotAnnouncedOnwards(): void
    {
        $daemon = new DbSyncPeerTestManager();

        $daemon->receive($daemon->dbSyncUpdated('Ada'));

        $this->assertSame([], $daemon->mesh->announcements);
    }

    /**
     * The seam is reachable from the wire, so a frame whose inner signal is not the type it
     * declares is dropped rather than applied. The name is checked beside the type because the
     * worker fan-out builds its frame from the NAME: a signal named after another sync fact would
     * reach the workers as that fact however well its type checked out.
     */
    public function testAFrameWhoseInnerSignalDisagreesWithItsDeclaredTypeIsDropped(): void
    {
        $daemon = new DbSyncPeerTestManager();

        $daemon->receiveDeclaredAs($daemon->dbSyncUpdated('Ada'), SignalTypeConstants::DB_SYNC_DELETED);

        $this->assertSame([], $daemon->workerServer->frameTypes());
    }

    /**
     * A link coming up ends a window in which facts from other nodes could not arrive, and no
     * process of this node can tell what it missed in it. So every one of them is told to stop
     * trusting what it holds - the workers through a frame, because their copies are their own
     * and nothing else would reach them.
     */
    public function testALinkComingUpTellsThisNodesWorkersToReRead(): void
    {
        $daemon = new DbSyncPeerTestManager();

        $daemon->linkedTo(self::REMOTE_NODE);

        $this->assertSame(
            [WorkerConstants::MESSAGE_DB_RE_READ],
            $daemon->workerServer->frameTypes(),
        );
    }

    /**
     * An RT fact arriving on the DB port goes the same way, and it has to: the two ports exist
     * because the two facts answer to different rules, and a node applying whatever it is handed
     * would make that separation decorative.
     */
    public function testAnRtFactArrivingOnTheDbPortIsDropped(): void
    {
        $daemon = new DbSyncPeerTestManager();

        $daemon->receive($daemon->rtSyncUpdated());

        $this->assertSame([], $daemon->workerServer->frameTypes());
    }
}

/**
 * Daemon manager wired to a recording mesh and a recording worker pool, so a dispatch pass and a
 * received replica can both be read back without a live cluster.
 */
final class DbSyncPeerTestManager extends DaemonManager
{
    /** The stand-in mesh every announcement is written to */
    public readonly DbSyncPeerTestMesh $mesh;

    /** The stand-in worker pool a replica is handed to */
    public readonly DbSyncPeerTestWorkerServer $workerServer;

    /** The peer server the dispatch pass is meant to find among the registered servers */
    public readonly PeerServer $peerServer;

    /** @var list<?DbSyncMesh> Port the announce step was handed, once per call */
    public array $announcedThrough = [];

    public function __construct()
    {
        parent::__construct();

        $this->mesh = new DbSyncPeerTestMesh();
        $this->workerServer = new DbSyncPeerTestWorkerServer();
        $this->workerServer->addWorker();
        $this->registerServer($this->workerServer);

        $this->peerServer = new PeerServer(
            '127.0.0.1',
            0,
            NodeIdentity::of('node-a', NodeRole::Master, []),
            [],
        );
        $this->registerServer($this->peerServer);

        // The apply step reaches for this node's collections; an empty context is enough, because
        // what the row does once it lands is the applicator's own subject, not the transport's.
        Hilos::$db = new DbSyncPeerTestDbContext();
    }

    /**
     * Builds the DB sync fact these cases travel, as a worker of the writing node produces it.
     *
     * @param string $title Row label the fact carries
     * @return SignalDTO Signal announcing that changed row
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function dbSyncUpdated(string $title): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::DAEMON),
            new SignalType(SignalTypeConstants::DB_SYNC_UPDATED),
            new SignalName(SignalConstants::DB_SYNC_UPDATED),
            new DbSyncUpdatedSignalData(
                DaemonManagerDbSyncPeerTest::COLLECTION,
                DaemonManagerDbSyncPeerTest::ROW_ID,
                ['title' => $title],
            ),
        );
    }

    /**
     * Builds a runtime-state fact, which travels the other half of the same dispatch branch.
     *
     * @return SignalDTO Signal announcing one changed runtime row
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function rtSyncUpdated(): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::RT),
            new SignalType(SignalTypeConstants::RT_SYNC_UPDATED),
            new SignalName(SignalConstants::RT_SYNC_UPDATED),
            new RtSyncUpdatedSignalData(
                DaemonManagerDbSyncPeerTest::COLLECTION,
                DaemonManagerDbSyncPeerTest::ROW_ID,
                ['title' => 'Ada'],
            ),
        );
    }

    /**
     * Builds a payload-free signal of a named type, for the facts these cases only need to name.
     *
     * @param string $signalType Signal type to build
     * @param string $signalName Signal name to build
     * @return SignalDTO Signal of that type carrying the ordinary DB payload
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function signalOfType(string $signalType, string $signalName): SignalDTO
    {
        return new SignalDTO(
            new SignalSource(SignalSource::DAEMON),
            new SignalType($signalType),
            new SignalName($signalName),
            new DbSyncUpdatedSignalData(
                DaemonManagerDbSyncPeerTest::COLLECTION,
                DaemonManagerDbSyncPeerTest::ROW_ID,
                ['title' => 'Ada'],
            ),
        );
    }

    /**
     * Queues a DB write made on this node, the way a worker's write does.
     *
     * @param string $title Row label the write carries
     * @throws InvalidArgumentException When the signal name is empty
     */
    public function queueDbSyncUpdated(string $title): void
    {
        $signal = $this->dbSyncUpdated($title);
        Hilos::$sr->queueSignal(
            signalSource: $signal->signalSource,
            signalType: $signal->signalType,
            signalName: $signal->signalName,
            signalData: $signal->data,
        );
    }

    /**
     * Runs the loop step that dispatches whatever is queued.
     *
     * @throws AgentException When routing a queued signal fails
     */
    public function dispatch(): void
    {
        $drain = Closure::bind(
            static function (DaemonManager $daemon): void {
                $daemon->dispatchSignals();
            },
            null,
            DaemonManager::class,
        );

        $drain($this);
    }

    /**
     * Offers one signal to the mesh, skipping the rest of the dispatch pass.
     *
     * @param SignalDTO $signal Signal the announce step judges
     */
    public function announce(SignalDTO $signal): void
    {
        $this->broadcastDbSyncToPeers($this->mesh, $signal);
    }

    /**
     * Offers one signal to the announce step of a node that has no mesh at all.
     *
     * @param SignalDTO $signal Signal the announce step judges
     */
    public function announceWithoutMesh(SignalDTO $signal): void
    {
        $this->broadcastDbSyncToPeers(null, $signal);
    }

    /**
     * Delivers a replica the way a handshaked link does.
     *
     * @param SignalDTO $signal DB sync fact another node announced
     */
    public function receive(SignalDTO $signal): void
    {
        $this->receiveDeclaredAs($signal, $signal->signalType->getType());
    }

    /**
     * Reports a link to another node, the way the transport does once it handshakes.
     *
     * @param string $nodeId Node this one can now reach
     */
    public function linkedTo(string $nodeId): void
    {
        $this->reReadAfterLink($nodeId);
    }

    /**
     * Delivers a replica whose frame declares a type of its own, as a frame off the wire may.
     *
     * @param SignalDTO $signal DB sync fact the frame carries
     * @param string $declaredType Type the frame claims to carry
     */
    public function receiveDeclaredAs(SignalDTO $signal, string $declaredType): void
    {
        $this->applyRemoteDbSync(DaemonManagerDbSyncPeerTest::REMOTE_NODE, $declaredType, $signal);
    }

    protected function createSignalRouter(): SignalRouter
    {
        return new SignalRouter();
    }

    protected function createAgentManagerDaemon(): AgentManagerDaemon
    {
        return new DbSyncPeerTestAgentManagerDaemon();
    }

    /**
     * Records the port the announce step was handed and sends it on to the recording mesh.
     *
     * The real {@see PeerServer} is final and announces over live links, so the fake stands one
     * port lower: what the dispatch pass found is asserted from {@see $announcedThrough}, and what
     * it announced from the mesh. A pass that found nothing is passed on as nothing, or the
     * off-cluster case would be answered by the substitution rather than by the code.
     *
     * @param ?DbSyncMesh $mesh Peer server the dispatch pass found, or null off-cluster
     * @param SignalDTO $signal Signal being dispatched
     */
    protected function broadcastDbSyncToPeers(?DbSyncMesh $mesh, SignalDTO $signal): void
    {
        $this->announcedThrough[] = $mesh;

        parent::broadcastDbSyncToPeers($mesh === null ? null : $this->mesh, $signal);
    }
}

final class DbSyncPeerTestAgentManagerDaemon extends AgentManagerDaemon
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

/**
 * Database context with nothing mounted: the transport is the subject here, and where the row
 * lands is the applicator's.
 */
final class DbSyncPeerTestDbContext extends DbContext
{
    public function configure(): void
    {
    }
}

/**
 * Mesh that keeps what this node announced, so the announce step can be read back.
 */
final class DbSyncPeerTestMesh implements DbSyncMesh
{
    /** @var list<DbSyncPeerTestAnnouncement> Facts offered to the mesh, in order */
    public array $announcements = [];

    /**
     * @param string $signalType DB sync signal type being announced
     * @param SignalDTO $signal DB sync signal the other nodes apply
     */
    public function broadcastDbSync(string $signalType, SignalDTO $signal): void
    {
        $this->announcements[] = new DbSyncPeerTestAnnouncement($signalType, $signal);
    }

    /**
     * @return DbSyncPeerTestAnnouncement The one fact this node announced
     * @throws RuntimeException When it announced any other number of facts
     */
    public function singleAnnouncement(): DbSyncPeerTestAnnouncement
    {
        if (count($this->announcements) !== 1) {
            throw new RuntimeException('One DB write announces exactly one fact.');
        }

        return $this->announcements[0];
    }
}

/**
 * One announcement as the mesh received it.
 */
final readonly class DbSyncPeerTestAnnouncement
{
    /**
     * @param string $signalType DB sync signal type announced
     * @param SignalDTO $signal Signal handed to the mesh
     */
    public function __construct(
        public string $signalType,
        public SignalDTO $signal,
    ) {
    }
}

/**
 * Worker pool that keeps what the daemon wrote to it, so the fan-out can be read back.
 */
final class DbSyncPeerTestWorkerServer extends WorkerServer
{
    public function __construct()
    {
    }

    /**
     * Adds the one worker these cases fan out to.
     */
    public function addWorker(): void
    {
        $this->clients[] = new DbSyncPeerTestWorkerClient();
    }

    /**
     * @return list<string> Message type of each frame written to the pool, in order
     */
    public function frameTypes(): array
    {
        $types = [];
        foreach ($this->clients as $client) {
            if ($client instanceof DbSyncPeerTestWorkerClient) {
                $types = [...$types, ...$client->frameTypes()];
            }
        }

        return $types;
    }

    protected function onStart(): void
    {
    }
}

/**
 * A worker link that keeps what was written to it, so the fan-out can be read back.
 */
final class DbSyncPeerTestWorkerClient extends WorkerClient
{
    /** @var list<string> Raw frames the daemon wrote to this link, in order */
    private array $written = [];

    public function __construct()
    {
        $this->setWorkerIndex(1);
    }

    public function isRegistered(): bool
    {
        return true;
    }

    /**
     * @param string $data Frame the daemon wants written to this worker
     */
    public function send(string $data): void
    {
        $this->written[] = $data;
    }

    /**
     * @return list<string> Message type of each frame written here, in order
     */
    public function frameTypes(): array
    {
        $types = [];
        foreach ($this->written as $frame) {
            $decoded = json_decode($frame, true);
            $type = is_array($decoded) ? $decoded[WorkerDTO::TYPE] ?? null : null;
            if (is_string($type)) {
                $types[] = $type;
            }
        }

        return $types;
    }
}

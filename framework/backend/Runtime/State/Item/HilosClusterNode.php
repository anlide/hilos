<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Hilos\Cluster\NodeIdentity;
use Hilos\Cluster\NodeRole;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\State\Collection\HilosClusterNodes;

/**
 * HilosClusterNode - one node of the cluster as this node's master observed it (HIL-337).
 *
 * The row that lets a worker know the cluster exists at all. `ClusterRegistry` is held on
 * the daemon master and reachable from nowhere else, so an agent or a page that wants to
 * address work at the node owning something had no list of nodes to address it by. This is
 * that list, published into runtime state by the master and read in the workers as ordinary
 * RT.
 *
 * Framework-owned runtime state mounted for every project ({@see HilosClusterNodes}).
 *
 * The view is NODE-LOCAL: every master publishes its own converged picture into its own copy
 * of the collection, and the collection is never announced to the mesh. That is what keeps
 * the picture alive through a lost quorum or a split - it is this node's own observation,
 * not a frozen replica of an owner that can no longer be reached.
 *
 * A node that left keeps its row, with `online` false and a fresh `lastSeen`, because that is
 * what the registry does: a node that was here and fell over is data, not the absence of it.
 *
 * With clustering off the collection is not empty either - the master publishes itself as the
 * single row - so a reader never needs a branch for the standalone case. `nodeId` is the empty
 * string there and only there: a configured node id can never be empty, since
 * {@see NodeIdentity::fromEnv()} refuses an empty CLUSTER_NODE_ID outright.
 */
final class HilosClusterNode extends RtState
{
    /** Runtime collection key mounted by the framework and used for RT sync. */
    public const string RT_COLLECTION = 'hilosClusterNodes';

    /**
     * Row id of the one node published on an install running without clustering.
     *
     * Empty because there is no identity to name it with - the same answer standalone
     * leadership gives when asked for the leader's id - and it collides with no real node,
     * since {@see NodeIdentity::fromEnv()} refuses an empty configured node id.
     */
    public const string STANDALONE_NODE_ID = '';

    public const string nodeId = 'nodeId';
    public const string role = 'role';
    public const string capabilities = 'capabilities';
    public const string address = 'address';
    public const string online = 'online';
    public const string lastSeen = 'lastSeen';

    /** Id of the node this row describes, and the row id; empty on a standalone install. */
    private(set) string $nodeId = '';

    /** Self-declared role of the node, a {@see NodeRole} value. */
    public string $role = '';

    /** @var list<string> Capability tags the node declared */
    public array $capabilities = [];

    /** Address peers dial to reach the node, or null when it advertises none. */
    public ?string $address = null;

    /** Whether the master saw the node as connected when it last published. */
    public bool $online = false;

    /** Microtime the node was last observed. */
    public float $lastSeen = 0.0;

    /**
     * Builds a row for a node the master has just seen for the first time.
     *
     * @param string $nodeId Node id, empty on a standalone install
     * @param string $role Node role value
     * @param list<string> $capabilities Capability tags the node declared
     * @param ?string $address Address peers dial to reach the node, or null
     * @param bool $online Whether the node is currently connected
     * @param float $lastSeen Microtime the node was last observed
     * @return static Fresh node row
     */
    public static function create(
        string $nodeId,
        string $role,
        array $capabilities,
        ?string $address,
        bool $online,
        float $lastSeen,
    ): static {
        $instance = new static();
        $instance->nodeId = $nodeId;
        $instance->role = $role;
        $instance->capabilities = $capabilities;
        $instance->address = $address;
        $instance->online = $online;
        $instance->lastSeen = $lastSeen;
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     * @return static Node row restored from a sync row
     * @throws InvalidFormatException When the row lost a field the node is built from
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->nodeId = self::requireString($row, self::nodeId);
        $instance->role = self::requireString($row, self::role);
        $instance->capabilities = self::requireStringList($row, self::capabilities);
        $instance->address = self::optionalString($row, self::address);
        $instance->online = self::requireBool($row, self::online);
        $instance->lastSeen = self::requireFloat($row, self::lastSeen);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * Applies an inbound RT sync diff to this row.
     *
     * Everything but the node id can move: a node goes offline and comes back, and a restart
     * with a re-read configuration can change its role, its capabilities and the address it
     * advertises. The id is not read here because it is the key the diff arrived under.
     *
     * @param array<string, mixed> $diff Changed fields and values from another process
     * @throws InvalidFormatException When the diff carries a field as the wrong type
     */
    public function applyDiff(array $diff): void
    {
        $this->role = self::patchString($diff, self::role, $this->role);
        $this->capabilities = self::patchStringList($diff, self::capabilities, $this->capabilities);
        $this->address = self::patchOptionalString($diff, self::address, $this->address);
        $this->online = self::patchBool($diff, self::online, $this->online);
        $this->lastSeen = self::patchFloat($diff, self::lastSeen, $this->lastSeen);
    }

    /**
     * @return string Runtime collection key for cluster node rows
     */
    public static function getRtCollectionKey(): string
    {
        return self::RT_COLLECTION;
    }

    /**
     * @return string Runtime row id, the node id
     */
    public function getId(): string
    {
        return $this->nodeId;
    }

    /**
     * @return array<string, mixed> Row suitable for runtime sync
     */
    public function toArray(): array
    {
        return [
            self::nodeId => $this->nodeId,
            self::role => $this->role,
            self::capabilities => $this->capabilities,
            self::address => $this->address,
            self::online => $this->online,
            self::lastSeen => $this->lastSeen,
        ];
    }
}

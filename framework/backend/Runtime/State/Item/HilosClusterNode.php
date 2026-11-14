<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Generator;
use Hilos\Cluster\NodeRole;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\Row\HilosClusterNodeRowCodec;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\RtRowCodec;
use Hilos\Runtime\RtRowReader;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\RtRowWriter;
use Hilos\Runtime\Snapshot\RtWork;
use Hilos\Runtime\State\Collection\HilosClusterNodes;
use LogicException;

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
 * `online` means reachable from here over this master's own link (HIL-1059), so a row can also
 * be offline because its node is only heard of from a neighbour and not seen yet.
 *
 * The non-empty name follows the node's re-read configuration, while its id stays stable.
 *
 * With clustering off the collection is not empty either - the master publishes itself as the
 * single row - so a reader never needs a branch for the standalone case. Its non-empty id is
 * the configured CLUSTER_NODE_ID or the stable standalone default.
 */
final class HilosClusterNode extends RtState
{
    /** Runtime collection key mounted by the framework and used for RT sync. */
    public const string RT_COLLECTION = 'hilosClusterNodes';

    /**
     * Row id of the one node published on an install running without clustering.
     *
     * Used when the standalone installation does not configure CLUSTER_NODE_ID.
     */
    public const string STANDALONE_NODE_ID = 'standalone';

    public const string nodeId = 'nodeId';
    public const string name = 'name';
    public const string role = 'role';
    public const string capabilities = 'capabilities';
    public const string address = 'address';
    public const string online = 'online';
    public const string lastSeen = 'lastSeen';

    /** Fixed row schema; variable values live in segmented pages. */
    private const array FIELD_TYPES = [
        self::nodeId => 'text',
        self::name => 'text',
        self::role => 'text',
        self::capabilities => 'list',
        self::address => '?text',
        self::online => 'bool',
        self::lastSeen => 'float',
    ];

    /** Application property defaults, not defaults for missing required incoming fields. */
    private const array FIELD_DEFAULTS = [
        self::nodeId => '',
        self::name => '',
        self::role => '',
        self::capabilities => [],
        self::address => null,
        self::online => false,
        self::lastSeen => 0.0,
    ];

    /** Exactly the fields accepted by the legacy patch door. */
    private const array PATCH_FIELDS = [
        self::name => true,
        self::role => true,
        self::capabilities => true,
        self::address => true,
        self::online => true,
        self::lastSeen => true,
    ];

    /** Id of the node this row describes, and the row id. */
    private(set) string $nodeId {
        get => isset($this->rowValues()[self::nodeId])
            ? $this->rowValues()[self::nodeId]->toNative()
            : self::FIELD_DEFAULTS[self::nodeId];
        set {
            $this->setRowValue(self::nodeId, RtRowValues::fromNative($value));
        }
    }

    /** Non-empty readable name declared by the node. */
    public string $name {
        get => isset($this->rowValues()[self::name])
            ? $this->rowValues()[self::name]->toNative()
            : self::FIELD_DEFAULTS[self::name];
        set {
            $this->setRowValue(self::name, RtRowValues::fromNative($value));
        }
    }

    /** Self-declared role of the node, a {@see NodeRole} value. */
    public string $role {
        get => isset($this->rowValues()[self::role])
            ? $this->rowValues()[self::role]->toNative()
            : self::FIELD_DEFAULTS[self::role];
        set {
            $this->setRowValue(self::role, RtRowValues::fromNative($value));
        }
    }

    /** @var list<string> Capability tags the node declared */
    public array $capabilities {
        get => isset($this->rowValues()[self::capabilities])
            ? $this->rowValues()[self::capabilities]->toNative()
            : self::FIELD_DEFAULTS[self::capabilities];
        set {
            $this->setRowValue(self::capabilities, RtRowValues::fromNative($value));
        }
    }

    /** Address peers dial to reach the node, or null when it advertises none. */
    public ?string $address {
        get => ($this->rowValues()[self::address] ?? null)?->toNative();
        set {
            $this->setRowValue(self::address, $value === null ? null : RtRowValues::fromNative($value));
        }
    }

    /** Whether the master saw the node as connected when it last published. */
    public bool $online {
        get => $this->rowValues()[self::online] ?? self::FIELD_DEFAULTS[self::online];
        set {
            $this->setRowValue(self::online, $value);
        }
    }

    /** Microtime the node was last observed. */
    public float $lastSeen {
        get => $this->rowValues()[self::lastSeen] ?? self::FIELD_DEFAULTS[self::lastSeen];
        set {
            $this->setRowValue(self::lastSeen, $value);
        }
    }

    /**
     * Builds a row for a node the master has just seen for the first time.
     *
     * @param string $nodeId Non-empty node id
     * @param string $name Non-empty readable node name
     * @param string $role Node role value
     * @param list<string> $capabilities Capability tags the node declared
     * @param ?string $address Address peers dial to reach the node, or null
     * @param bool $online Whether the node is currently connected
     * @param float $lastSeen Microtime the node was last observed
     * @return static Fresh node row
     */
    public static function create(
        string $nodeId,
        string $name,
        string $role,
        array $capabilities,
        ?string $address,
        bool $online,
        float $lastSeen,
    ): static {
        $instance = new static();
        $instance->nodeId = $nodeId;
        $instance->name = $name;
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
        $instance->name = self::requireString($row, self::name);
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
     * with a re-read configuration can change its name, role, capabilities and the address it
     * advertises. The id is not read here because it is the key the diff arrived under.
     *
     * @param array<string, mixed> $diff Changed fields and values from another process
     * @throws InvalidFormatException When the diff carries a field as the wrong type
     */
    public function applyDiff(array $diff): void
    {
        $this->name = self::patchString($diff, self::name, $this->name);
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
            self::name => $this->name,
            self::role => $this->role,
            self::capabilities => $this->capabilities,
            self::address => $this->address,
            self::online => $this->online,
            self::lastSeen => $this->lastSeen,
        ];
    }

    /** @return RtRowCodec Segmented codec preserving this row's nested-value contract */
    public function rowCodec(): RtRowCodec
    {
        return new HilosClusterNodeRowCodec();
    }

    /**
     * Prepares an unpublished row; the committed-root layer supplies its baseline later.
     *
     * @param RtRowReader $in Segmented full row
     * @return Generator<int, RtWork, int, static> Fully validated candidate
     * @throws InvalidFormatException When a field violates the existing row contract
     * @throws LogicException When segmented preparation violates writer lifetime
     */
    public static function fromValues(RtRowReader $in): Generator
    {
        $prepared = yield from self::prepareValues($in, null);
        yield new RtWork();
        $instance = new static();
        $instance->replaceRowValues($prepared);

        return $instance;
    }

    /**
     * Every accepted field is validated before the fixed descriptor is replaced.
     *
     * @param RtRowReader $diff Segmented changed fields
     * @return Generator<int, RtWork, int, void> Atomic candidate patch
     * @throws InvalidFormatException When a changed field violates the existing contract
     * @throws LogicException When segmented preparation violates writer lifetime
     */
    public function patchValues(RtRowReader $diff): Generator
    {
        $prepared = yield from self::prepareValues($diff, $this->rowValues());
        yield new RtWork();
        $this->replaceRowValues($prepared);
    }

    /**
     * @param RtRowWriter $out Unpublished output in any Runtime arena
     * @return Generator<int, RtWork, int, void> Bounded nested-value export
     * @throws InvalidFormatException When a stored segmented value is malformed
     * @throws LogicException When the output has already closed
     */
    public function exportValues(RtRowWriter $out): Generator
    {
        return $this->frozenValues()->export($out);
    }

    /** @return array<string, mixed> Fixed property defaults in codec export order */
    protected function rowFieldDefaults(): array
    {
        return self::FIELD_DEFAULTS;
    }

    /**
     * @param RtRowReader $input Segmented full row or diff
     * @param ?array<string, RtRowValues|int|float|bool|null> $current Fixed descriptor, null for restore
     * @return Generator<int, RtWork, int, array<string, RtRowValues|int|float|bool|null>> Validated fixed descriptor
     * @throws InvalidFormatException When a field violates its existing contract
     * @throws LogicException When a writer lifetime is violated
     */
    private static function prepareValues(RtRowReader $input, ?array $current): Generator
    {
        $prepared = $current ?? [];
        foreach (self::FIELD_TYPES as $key => $type) {
            yield new RtWork();
            if ($current !== null && !isset(self::PATCH_FIELDS[$key])) {
                continue;
            }
            $value = yield from $input->field($key);
            if ($value === null) {
                if ($current !== null) {
                    continue;
                }
                if (str_starts_with($type, '?')) {
                    $prepared[$key] = null;
                    continue;
                }
                throw new InvalidFormatException('Runtime row is missing required key ' . $key);
            }
            $reader = $value->reader();
            if ($reader->kind() === RtRowKind::Scalar) {
                $scalar = yield from $reader->scalar();
                if ($scalar === null && str_starts_with($type, '?')) {
                    $prepared[$key] = null;
                    continue;
                }
                $scalarType = ltrim($type, '?');
                if (($scalarType === 'int' && is_int($scalar)) || ($scalarType === 'bool' && is_bool($scalar))) {
                    $prepared[$key] = $scalar;
                    continue;
                }
                if ($scalarType === 'float' && (is_int($scalar) || is_float($scalar))) {
                    $prepared[$key] = (float)$scalar;
                    continue;
                }
            }
            if (in_array($type, ['list', '?list', 'array'], true)) {
                $prepared[$key] = yield from self::prepareList($reader);
                continue;
            }
            if ($reader->kind() !== RtRowKind::Text || !in_array($type, ['text', '?text', 'moment', '?moment'], true)) {
                throw new InvalidFormatException('Runtime row carries the wrong type under key ' . $key);
            }
            $prepared[$key] = yield from RtRowWriter::copy($reader, $input->arena(), RtRowRole::Candidate);
        }

        return $prepared;
    }

    /**
     * Strict string lists retain their order and duplicates without a native list.
     *
     * @param RtRowReader $input Incoming array
     * @return Generator<int, RtWork, int, RtRowValues> Validated segmented list
     * @throws InvalidFormatException When a list key or element violates the existing contract
     * @throws LogicException When segmented preparation violates writer lifetime
     */
    private static function prepareList(RtRowReader $input): Generator
    {
        $cursor = $input->entries();
        $writer = new RtRowWriter($input->arena(), RtRowRole::Candidate);
        $position = 0;
        while (($entry = yield from $cursor->advance()) !== null) {
            if ($entry->key->reader()->kind() !== RtRowKind::Scalar
                || (yield from $entry->key->reader()->scalar()) !== $position
                || $entry->value->reader()->kind() !== RtRowKind::Text) {
                throw new InvalidFormatException('Runtime row carries no list of strings');
            }
            $value = yield from RtRowWriter::copy($entry->value->reader(), $input->arena());
            yield from $writer->write($position++, $value);
        }

        return yield from $writer->finish();
    }
}

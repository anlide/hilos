<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Hilos\Cluster\ClusterContext;

/**
 * ProtectedModeRuntime - the singleton runtime state of the protected mode subsystem.
 *
 * Framework-owned runtime state registered by the project as a single item, mirroring
 * {@see BackupRuntime}. It tracks whether the cluster is quiescing for a destructive
 * operation (restore today, other initiators later) so the master's welcome path and
 * the browser page guards can lock every connection except the initiator's out.
 *
 * The truth source is the leader daemon: the leader gates every state decision behind
 * {@see ClusterContext::amLeader()} and drives the two-phase freeze
 * (activating -> active). Each node keeps a local writer so the row reaches that node's
 * workers: the leader writes by its own decision, followers write in reaction to the
 * peer QUIESCE/LIFT frames. This foundation defines the row shape only; the writer seam
 * and orchestration land in later slices of HIL-267.
 */
final class ProtectedModeRuntime extends RtState
{
    /** Runtime item alias registered by the project and used for RT sync. */
    public const string RT_ITEM = 'hilosProtectedModeRuntime';

    /** Stable singleton row id. */
    public const string ID = 'runtime';

    /** Phase value: no protected operation in flight. */
    public const string PHASE_INACTIVE = 'inactive';

    /** Phase value: initiator asked, leader is collecting quiesced reports. */
    public const string PHASE_ACTIVATING = 'activating';

    /** Phase value: every node quiesced, the initiator may run its operation. */
    public const string PHASE_ACTIVE = 'active';

    /** Phase value: the operation is over and a hand-picked circle verifies before the mode lifts for all. */
    public const string PHASE_VERIFYING = 'verifying';

    /** Phase value: initiator finished, the leader is lifting the freeze. */
    public const string PHASE_DEACTIVATING = 'deactivating';

    public const string phase = 'phase';
    public const string operation = 'operation';
    public const string initiatorAcceptKey = 'initiatorAcceptKey';
    public const string initiatorAgentType = 'initiatorAgentType';
    public const string initiatorAgentIndex = 'initiatorAgentIndex';
    public const string initiatorNodeId = 'initiatorNodeId';
    public const string startedAt = 'startedAt';
    public const string activatedAt = 'activatedAt';
    public const string progressAt = 'progressAt';
    public const string passHashes = 'passHashes';
    public const string admittedAcceptKeys = 'admittedAcceptKeys';

    /** Current lifecycle phase of the protected mode. */
    public string $phase = self::PHASE_INACTIVE;

    /** Operation name the initiator is running, or null when inactive. */
    public ?string $operation = null;

    /** Accept key of the initiator connection allowed through the lockdown, or null. */
    public ?string $initiatorAcceptKey = null;

    /** Agent type of the initiator agent left running during the freeze, or null. */
    public ?string $initiatorAgentType = null;

    /** Agent index of the initiator agent left running during the freeze, or null. */
    public ?int $initiatorAgentIndex = null;

    /** Node id that hosts the initiator agent, or null when inactive. */
    public ?string $initiatorNodeId = null;

    /** Epoch seconds when the leader began activating, or null when inactive. */
    public ?int $startedAt = null;

    /** Epoch seconds when the mode reached active, or null before it does. */
    public ?int $activatedAt = null;

    /**
     * Epoch seconds of the last progress mark the operation behind the freeze left, or null.
     *
     * What tells a long operation from a hung one: the initiator stamps it whenever its own work
     * moved, so a watchdog reading a stale value knows nothing is happening rather than merely
     * that time has passed. The value is written by the master that owns this row and is never
     * carried on the wire - the mark travels as a bare fact, so a node with a skewed clock cannot
     * push the arithmetic that reads it around.
     */
    public ?int $progressAt = null;

    /**
     * SHA-256 of every pass minted for the verification in flight; empty on every other phase.
     *
     * The pass itself is never stored: the clear key exists only in the operator's terminal
     * and in the verifier's browser, so a runtime snapshot that reaches a log leaks nothing
     * that opens the system.
     *
     * @var list<string>
     */
    public array $passHashes = [];

    /**
     * Accept keys of the connections that presented a valid pass; empty on every other phase.
     *
     * @var list<string>
     */
    public array $admittedAcceptKeys = [];

    /**
     * Creates the inactive singleton runtime row.
     *
     * @return static Inactive protected mode runtime state
     */
    public static function create(): static
    {
        $instance = new static();
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * @param array<string, mixed> $row Serialized runtime row
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->phase = (string)($row[self::phase] ?? self::PHASE_INACTIVE);
        $instance->operation = self::stringOrNull($row[self::operation] ?? null);
        $instance->initiatorAcceptKey = self::stringOrNull($row[self::initiatorAcceptKey] ?? null);
        $instance->initiatorAgentType = self::stringOrNull($row[self::initiatorAgentType] ?? null);
        $instance->initiatorAgentIndex = self::intOrNull($row[self::initiatorAgentIndex] ?? null);
        $instance->initiatorNodeId = self::stringOrNull($row[self::initiatorNodeId] ?? null);
        $instance->startedAt = self::intOrNull($row[self::startedAt] ?? null);
        $instance->activatedAt = self::intOrNull($row[self::activatedAt] ?? null);
        $instance->progressAt = self::intOrNull($row[self::progressAt] ?? null);
        $instance->passHashes = self::stringList($row[self::passHashes] ?? null);
        $instance->admittedAcceptKeys = self::stringList($row[self::admittedAcceptKeys] ?? null);
        $instance->markRtSyncBaseline();

        return $instance;
    }

    /**
     * Applies an inbound RT sync diff to this singleton.
     *
     * The freeze row is delivered as diffs of this item, so without it every worker but
     * the local writer's would keep showing the mode inactive and let connections through.
     *
     * @param array<string, mixed> $diff Changed fields and values from another worker
     */
    public function applyDiff(array $diff): void
    {
        if (array_key_exists(self::phase, $diff)) {
            $this->phase = (string)$diff[self::phase];
        }
        if (array_key_exists(self::operation, $diff)) {
            $this->operation = self::stringOrNull($diff[self::operation]);
        }
        if (array_key_exists(self::initiatorAcceptKey, $diff)) {
            $this->initiatorAcceptKey = self::stringOrNull($diff[self::initiatorAcceptKey]);
        }
        if (array_key_exists(self::initiatorAgentType, $diff)) {
            $this->initiatorAgentType = self::stringOrNull($diff[self::initiatorAgentType]);
        }
        if (array_key_exists(self::initiatorAgentIndex, $diff)) {
            $this->initiatorAgentIndex = self::intOrNull($diff[self::initiatorAgentIndex]);
        }
        if (array_key_exists(self::initiatorNodeId, $diff)) {
            $this->initiatorNodeId = self::stringOrNull($diff[self::initiatorNodeId]);
        }
        if (array_key_exists(self::startedAt, $diff)) {
            $this->startedAt = self::intOrNull($diff[self::startedAt]);
        }
        if (array_key_exists(self::activatedAt, $diff)) {
            $this->activatedAt = self::intOrNull($diff[self::activatedAt]);
        }
        if (array_key_exists(self::progressAt, $diff)) {
            $this->progressAt = self::intOrNull($diff[self::progressAt]);
        }
        if (array_key_exists(self::passHashes, $diff)) {
            $this->passHashes = self::stringList($diff[self::passHashes]);
        }
        if (array_key_exists(self::admittedAcceptKeys, $diff)) {
            $this->admittedAcceptKeys = self::stringList($diff[self::admittedAcceptKeys]);
        }
    }

    /**
     * @return string Runtime collection key for the protected mode runtime singleton
     */
    public static function getRtCollectionKey(): string
    {
        return self::RT_ITEM;
    }

    /**
     * @return string Stable singleton row id
     */
    public function getId(): string
    {
        return self::ID;
    }

    /**
     * Whether a connection holding this accept key is locked out of everything but
     * authentication while the freeze is up.
     *
     * The lockdown is binary: the initiator connection ({@see self::initiatorAcceptKey})
     * stays fully live so it can drive its destructive operation; every other connection
     * is frozen out. It engages the moment this node leaves {@see self::PHASE_INACTIVE} —
     * not only at {@see self::PHASE_ACTIVE} — because a follower node quiesces to
     * `activating` and never advances to `active` (that phase is the leader-local marker
     * that every node has quiesced), so a lockdown gated on `active` would leave followers
     * open for the whole freeze. The initiator's accept key is recorded only on the node
     * that froze itself with it, so on every other node the null key locks out all.
     *
     * {@see self::PHASE_VERIFYING} is the one phase that lets a second circle through, and it
     * does so by pass ({@see admits()}) rather than by widening this rule: every other phase
     * keeps the binary lockdown it had.
     *
     * @param ?string $acceptKey Connection accept key to test, or null when none is known
     * @return bool Whether the connection is locked out right now
     */
    public function locksOut(?string $acceptKey): bool
    {
        return $this->phase !== self::PHASE_INACTIVE
            && $acceptKey !== $this->initiatorAcceptKey
            && !$this->admits($acceptKey);
    }

    /**
     * Whether this connection presented a valid pass and was let in for the verification.
     *
     * Only {@see self::PHASE_VERIFYING} consults the admitted list at all. The frozen phases
     * do carry an empty list — the actions clear it on the way in and on the way out — but an
     * emptiness that is enforced by the phase beats one that is merely assumed of the row.
     *
     * @param ?string $acceptKey Connection accept key to test, or null when none is known
     * @return bool Whether the connection holds a pass admitted right now
     */
    public function admits(?string $acceptKey): bool
    {
        return $this->phase === self::PHASE_VERIFYING
            && $acceptKey !== null
            && in_array($acceptKey, $this->admittedAcceptKeys, true);
    }

    /**
     * @return array<string, mixed> Row suitable for runtime sync
     */
    public function toArray(): array
    {
        return [
            self::phase => $this->phase,
            self::operation => $this->operation,
            self::initiatorAcceptKey => $this->initiatorAcceptKey,
            self::initiatorAgentType => $this->initiatorAgentType,
            self::initiatorAgentIndex => $this->initiatorAgentIndex,
            self::initiatorNodeId => $this->initiatorNodeId,
            self::startedAt => $this->startedAt,
            self::activatedAt => $this->activatedAt,
            self::progressAt => $this->progressAt,
            self::passHashes => $this->passHashes,
            self::admittedAcceptKeys => $this->admittedAcceptKeys,
        ];
    }

    /**
     * @param mixed $value Raw row value
     * @return ?string Trimmed non-empty string, or null
     */
    private static function stringOrNull(mixed $value): ?string
    {
        return $value === null ? null : (string)$value;
    }

    /**
     * @param mixed $value Raw row value
     * @return list<string> String list; empty when the row carries no list at all
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_map(static fn(mixed $item): string => (string)$item, $value));
    }

    /**
     * @param mixed $value Raw row value
     * @return ?int Integer value, or null
     */
    private static function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int)$value;
    }
}

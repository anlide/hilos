<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

use Hilos\Cluster\ClusterContext;
use Hilos\Core\Exception\InvalidFormatException;

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
    public const string initiatorSessionTokenHash = 'initiatorSessionTokenHash';
    public const string initiatorAgentType = 'initiatorAgentType';
    public const string initiatorAgentIndex = 'initiatorAgentIndex';
    public const string initiatorNodeId = 'initiatorNodeId';
    public const string startedAt = 'startedAt';
    public const string activatedAt = 'activatedAt';
    public const string progressAt = 'progressAt';
    public const string passHashes = 'passHashes';
    public const string admittedAcceptKeys = 'admittedAcceptKeys';

    /** Hash algorithm the initiator's session token is stored and compared under. */
    private const string SESSION_TOKEN_HASH_ALGO = 'sha256';

    /** Current lifecycle phase of the protected mode. */
    public string $phase = self::PHASE_INACTIVE;

    /** Operation name the initiator is running, or null when inactive. */
    public ?string $operation = null;

    /** Accept key of the initiator connection allowed through the lockdown, or null. */
    public ?string $initiatorAcceptKey = null;

    /**
     * SHA-256 of the session token of the browser that asked for the operation, or null.
     *
     * The accept key above names one socket; this names the browser behind it, and it is what
     * survives a reload - the accept key is minted on the 101 and a reloaded tab arrives with a
     * new one, which is how the initiator used to lock itself out of its own restore (HIL-655).
     *
     * Only the hash is kept. The row lives in runtime state and travels the cluster, while the
     * token itself is the key to the account: the same reason {@see self::$passHashes} keeps
     * hashes and not the passes they were minted from.
     */
    public ?string $initiatorSessionTokenHash = null;

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
     * @return static Runtime singleton restored from a sync row
     * @throws InvalidFormatException When the row lost a field the freeze is judged by
     */
    public static function fromRow(array $row): static
    {
        $instance = new static();
        $instance->phase = self::requireString($row, self::phase);
        $instance->operation = self::optionalString($row, self::operation);
        $instance->initiatorAcceptKey = self::optionalString($row, self::initiatorAcceptKey);
        $instance->initiatorSessionTokenHash = self::optionalString($row, self::initiatorSessionTokenHash);
        $instance->initiatorAgentType = self::optionalString($row, self::initiatorAgentType);
        $instance->initiatorAgentIndex = self::optionalInt($row, self::initiatorAgentIndex);
        $instance->initiatorNodeId = self::optionalString($row, self::initiatorNodeId);
        $instance->startedAt = self::optionalInt($row, self::startedAt);
        $instance->activatedAt = self::optionalInt($row, self::activatedAt);
        $instance->progressAt = self::optionalInt($row, self::progressAt);
        $instance->passHashes = self::requireStringList($row, self::passHashes);
        $instance->admittedAcceptKeys = self::requireStringList($row, self::admittedAcceptKeys);
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
     * @throws InvalidFormatException When the diff carries a field as the wrong type
     */
    public function applyDiff(array $diff): void
    {
        $this->phase = self::patchString($diff, self::phase, $this->phase);
        $this->operation = self::patchOptionalString($diff, self::operation, $this->operation);
        $this->initiatorAcceptKey = self::patchOptionalString($diff, self::initiatorAcceptKey, $this->initiatorAcceptKey);
        $this->initiatorSessionTokenHash = self::patchOptionalString($diff, self::initiatorSessionTokenHash, $this->initiatorSessionTokenHash);
        $this->initiatorAgentType = self::patchOptionalString($diff, self::initiatorAgentType, $this->initiatorAgentType);
        $this->initiatorAgentIndex = self::patchOptionalInt($diff, self::initiatorAgentIndex, $this->initiatorAgentIndex);
        $this->initiatorNodeId = self::patchOptionalString($diff, self::initiatorNodeId, $this->initiatorNodeId);
        $this->startedAt = self::patchOptionalInt($diff, self::startedAt, $this->startedAt);
        $this->activatedAt = self::patchOptionalInt($diff, self::activatedAt, $this->activatedAt);
        $this->progressAt = self::patchOptionalInt($diff, self::progressAt, $this->progressAt);
        $this->passHashes = self::patchStringList($diff, self::passHashes, $this->passHashes);
        $this->admittedAcceptKeys = self::patchStringList($diff, self::admittedAcceptKeys, $this->admittedAcceptKeys);
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
     * The initiator is recognized by either half of its identity: the accept key of the socket
     * that asked, and the session token hash of the browser behind it ({@see belongsToInitiator()}).
     * The second is what a reload and a second tab arrive with - they carry the same cookie and a
     * brand new accept key - and without it the person watching their own restore was locked out
     * of it by pressing F5 (HIL-655).
     *
     * @param ?string $acceptKey Connection accept key to test, or null when none is known
     * @param ?string $sessionTokenHash Hash of the connection's session token, or null when it carries no session
     * @return bool Whether the connection is locked out right now
     */
    public function locksOut(?string $acceptKey, ?string $sessionTokenHash): bool
    {
        return $this->phase !== self::PHASE_INACTIVE
            && $acceptKey !== $this->initiatorAcceptKey
            && !$this->belongsToInitiator($sessionTokenHash)
            && !$this->admits($acceptKey);
    }

    /**
     * Whether this connection belongs to the browser session that asked for the operation.
     *
     * Both nulls are refusals rather than matches: a freeze entered without a session (a CLI or a
     * scheduled restore) leaves the row's hash null, and a connection carrying no session hashes to
     * null too - letting those two meet would open the whole node to every cookieless visitor.
     *
     * The comparison is {@see hash_equals()} because the left side is derived from a secret; the
     * right side is derived on this node from a cookie the client presented, which is exactly the
     * shape a timing comparison is worth removing from.
     *
     * @param ?string $sessionTokenHash Hash of the connection's session token, or null when it carries no session
     * @return bool Whether the session behind this connection is the initiator's
     */
    public function belongsToInitiator(?string $sessionTokenHash): bool
    {
        return $sessionTokenHash !== null
            && $this->initiatorSessionTokenHash !== null
            && hash_equals($this->initiatorSessionTokenHash, $sessionTokenHash);
    }

    /**
     * Hashes a session token into the form this row stores and compares it in.
     *
     * The one door to the algorithm, so the three places that need it - the agent recording the
     * initiator, the master gating a handshake and the row comparing them - cannot drift apart by
     * spelling it separately.
     *
     * @param string $sessionToken Session cookie token of a browser
     * @return string Hash of that token in the row's storage form
     */
    public static function hashSessionToken(string $sessionToken): string
    {
        return hash(self::SESSION_TOKEN_HASH_ALGO, $sessionToken);
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
            self::initiatorSessionTokenHash => $this->initiatorSessionTokenHash,
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
}

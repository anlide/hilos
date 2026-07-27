<?php

declare(strict_types=1);

namespace Hilos\Runtime\State\Item;

/**
 * ProtectedModeRuntime - the singleton runtime state of the protected mode subsystem.
 *
 * Framework-owned runtime state registered by the project as a single item, mirroring
 * {@see BackupRuntime}. It tracks whether the cluster is quiescing for a destructive
 * operation (restore today, other initiators later) so the master's welcome path and
 * the browser page guards can lock every connection except the initiator's out.
 *
 * The truth source is the leader daemon: the leader gates every state decision behind
 * {@see \Hilos\Cluster\ClusterContext::amLeader()} and drives the two-phase freeze
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
     * @return ?int Integer value, or null
     */
    private static function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int)$value;
    }
}

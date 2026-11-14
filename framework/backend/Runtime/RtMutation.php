<?php

declare(strict_types=1);

namespace Hilos\Runtime;

use Generator;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Source\SourceChangeProvenance;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Runtime\Ordinary\RtOrdinaryAdmission;
use Hilos\Runtime\Row\RtRowArena;
use Hilos\Runtime\Row\RtRowComparison;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\Snapshot\RtPreparedRow;
use Hilos\Runtime\Snapshot\RtVersionPages;
use Hilos\Runtime\Snapshot\RtWork;
use LogicException;

/**
 * Immutable Runtime operation shared by ordinary delivery and post-cut deltas.
 * Values, baseline, previous and context are arena pages, never live state handles.
 * The ordinary admission seals it ({@see RtOrdinaryAdmission}); SCAFFOLD: the stream carrier follows later.
 */
final readonly class RtMutation
{
    /** Codec-owned field names are bounded schema identifiers, never application keys. */
    private const int MAX_FIELD_NAME_BYTES = 256;

    /**
     * @internal Wrap only a complete mutation page retained by the caller.
     *
     * @param RtRowArena $arena Existing storage owner
     * @param RtRowValues $descriptor Independently held immutable operation
     */
    public function __construct(public RtRowArena $arena, public RtRowValues $descriptor)
    {
    }

    /**
     * Inputs are validated codec records. Every retained field is captured before apply.
     *
     * @param TableMutationType $type C/U/D operation
     * @param ?RtPreparedRow $before Current record, absent only for create
     * @param ?RtPreparedRow $after Validated next record, absent only for delete
     * @param RtRowValues $context Segmented source, origin and request context
     * @param SourceChangeProvenance $provenance Captured authorship, independent of browser origin
     * @return Generator<int, RtWork, int, self> Complete immutable operation
     * @throws InvalidFormatException When record fields or identities are malformed
     * @throws LogicException When the operation or its record leases are inconsistent
     */
    public static function prepare(
        TableMutationType $type,
        ?RtPreparedRow $before,
        ?RtPreparedRow $after,
        RtRowValues $context,
        SourceChangeProvenance $provenance = SourceChangeProvenance::LocalWrite,
    ): Generator {
        yield new RtWork();
        if (!in_array($type, [TableMutationType::Create, TableMutationType::Update, TableMutationType::Delete], true)
            || ($before === null) !== ($type === TableMutationType::Create)
            || ($after === null) !== ($type === TableMutationType::Delete)) {
            throw new LogicException('An RT mutation needs the before/after records of its C/U/D operation');
        }
        $arena = ($after ?? $before)->arena;
        $context->assertArena($arena);
        $before?->descriptor->assertArena($arena);
        $after?->descriptor->assertArena($arena);
        if ($before !== null && $after !== null) {
            if ($before->incarnation() !== $after->incarnation()
                || !(yield from $before->identity()->matches($after->identity()->descriptor->rootIn($arena)))) {
                throw new LogicException('An RT update cannot replace its row identity or incarnation');
            }
        }
        $previous = new RtRowWriter($arena);
        if ($type === TableMutationType::Update) {
            $values = new RtRowWriter($arena);
            $baseline = $before->baseline();
            $cursor = $after->values()->reader()->entries();
            while (($entry = yield from $cursor->advance()) !== null) {
                $key = yield from $entry->key->reader()->boundedText(self::MAX_FIELD_NAME_BYTES);
                $old = yield from $baseline->reader()->field($key);
                $same = $old === null
                    ? $entry->value->reader()->kind() === RtRowKind::Scalar && (yield from $entry->value->reader()->scalar()) === null
                    : yield from RtRowComparison::equal($entry->value->reader(), $old->reader());
                if (!$same) {
                    yield from $values->writeEntry($entry->key, $entry->value);
                    if ($old !== null) {
                        yield from $previous->writeEntry($entry->key, $old);
                    }
                }
            }
            $payload = yield from $values->finish();
        } else {
            $payload = ($after ?? $before)->values();
        }
        $previousValues = yield from $previous->finish();
        $details = yield from RtVersionPages::make(
            $arena,
            RtRowKind::MutationDetails,
            $provenance->name,
            first: $payload->rootIn($arena),
            second: $previousValues->rootIn($arena),
            next: $context->rootIn($arena),
        );
        $root = yield from RtVersionPages::make(
            $arena,
            RtRowKind::Mutation,
            $type->value,
            $before?->descriptor->rootIn($arena),
            $after?->descriptor->rootIn($arena),
            $details->rootIn($arena),
        );

        return new self($arena, $root);
    }

    /**
     * @return TableMutationType Prepared C/U/D operation
     * @throws LogicException When this descriptor lease closed
     */
    public function type(): TableMutationType
    {
        return TableMutationType::from($this->descriptor->rootIn($this->arena)->scalar);
    }

    /**
     * @return SourceChangeProvenance Captured local/remote authorship, never inferred from a later context
     * @throws LogicException When the descriptor closed or its internal provenance is invalid
     */
    public function provenance(): SourceChangeProvenance
    {
        return match ($this->descriptor->rootIn($this->arena)->next->scalar) {
            SourceChangeProvenance::LocalWrite->name => SourceChangeProvenance::LocalWrite,
            SourceChangeProvenance::AppliedRemote->name => SourceChangeProvenance::AppliedRemote,
            default => throw new LogicException('The immutable Runtime mutation has no known provenance'),
        };
    }

    /**
     * @return ?RtPreparedRow Frozen pre-write record, or absence for create
     * @throws LogicException When this descriptor lease closed
     */
    public function before(): ?RtPreparedRow
    {
        $page = $this->descriptor->rootIn($this->arena)->first;

        return $page === null ? null : new RtPreparedRow($this->arena, new RtRowValues($this->arena, $page, RtRowRole::Transient));
    }

    /**
     * @return ?RtPreparedRow Frozen next record, or absence for delete
     * @throws LogicException When this descriptor lease closed
     */
    public function after(): ?RtPreparedRow
    {
        $page = $this->descriptor->rootIn($this->arena)->second;

        return $page === null ? null : new RtPreparedRow($this->arena, new RtRowValues($this->arena, $page, RtRowRole::Transient));
    }

    /**
     * @return RtRowValues Full create/delete row, or update diff
     * @throws LogicException When this descriptor lease closed
     */
    public function values(): RtRowValues
    {
        return new RtRowValues($this->arena, $this->descriptor->rootIn($this->arena)->next->first, RtRowRole::Transient);
    }

    /**
     * @return RtRowValues Precise baseline fields replaced by an update
     * @throws LogicException When this descriptor lease closed
     */
    public function previous(): RtRowValues
    {
        return new RtRowValues($this->arena, $this->descriptor->rootIn($this->arena)->next->second, RtRowRole::Transient);
    }

    /**
     * @return RtRowValues Captured source/origin/request context
     * @throws LogicException When this descriptor lease closed
     */
    public function context(): RtRowValues
    {
        return new RtRowValues($this->arena, $this->descriptor->rootIn($this->arena)->next->next, RtRowRole::Transient);
    }
}

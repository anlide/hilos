<?php

declare(strict_types=1);

namespace Hilos\Runtime\Snapshot;

use Generator;
use Hilos\Runtime\Row\RtRowArena;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\RtRowValues;
use LogicException;

/**
 * @internal Values and baseline are frozen pages; no live RtState is retained.
 * SCAFFOLD: production row bindings will consume these prepared descriptors later.
 */
final readonly class RtPreparedRow
{
    /**
     * @internal Only completed record pages may be wrapped.
     *
     * @param RtRowArena $arena Storage owner
     * @param RtRowValues $descriptor Independent record lease
     */
    public function __construct(public RtRowArena $arena, public RtRowValues $descriptor)
    {
    }

    /**
     * Values have already passed their codec before this factory is called.
     *
     * @param RtPreparedIdentity $identity Prepared id/set keys
     * @param RtRowValues $values Immutable codec output
     * @param RtRowValues $baseline Immutable segmented sync baseline
     * @param int $incarnation Stable token for this row lifetime
     * @return Generator<int, RtWork, int, self> Immutable row record
     * @throws LogicException When leases closed or belong to different arenas
     */
    public static function prepare(RtPreparedIdentity $identity, RtRowValues $values, RtRowValues $baseline, int $incarnation): Generator
    {
        $arena = $identity->arena;
        $record = yield from RtVersionPages::make(
            $arena,
            RtRowKind::Record,
            $incarnation,
            $identity->descriptor->rootIn($arena),
            $values->rootIn($arena),
            $baseline->rootIn($arena),
        );

        return new self($arena, $record);
    }

    /**
     * @return RtPreparedIdentity Independently held id/set identity
     * @throws LogicException When the record lease closed
     */
    public function identity(): RtPreparedIdentity
    {
        return new RtPreparedIdentity(
            $this->arena,
            new RtRowValues($this->arena, $this->descriptor->rootIn($this->arena)->first, RtRowRole::Transient),
        );
    }

    /**
     * @return int This row lifetime's token
     * @throws LogicException When the record lease closed
     */
    public function incarnation(): int
    {
        return $this->descriptor->rootIn($this->arena)->scalar;
    }

    /**
     * @return RtRowValues Independent immutable value lease
     * @throws LogicException When the record lease closed
     */
    public function values(): RtRowValues
    {
        return new RtRowValues($this->arena, $this->descriptor->rootIn($this->arena)->second, RtRowRole::Transient);
    }

    /**
     * @return RtRowValues Independent immutable baseline lease
     * @throws LogicException When the record lease closed
     */
    public function baseline(): RtRowValues
    {
        return new RtRowValues($this->arena, $this->descriptor->rootIn($this->arena)->next, RtRowRole::Transient);
    }
}

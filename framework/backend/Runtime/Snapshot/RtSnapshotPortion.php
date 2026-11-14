<?php

declare(strict_types=1);

namespace Hilos\Runtime\Snapshot;

use Hilos\Runtime\RtRowValues;

/**
 * One bounded part of a snapshot inside this process: row ids with their immutable values, each
 * a lease on pages the version already holds, so nothing is copied or serialized to build it. It
 * carries no framing of its own; a frame is cut from portions where the wire is written.
 */
final readonly class RtSnapshotPortion
{
    /**
     * @param list<string> $ids Row ids, in the source's insertion order
     * @param list<RtRowValues> $values Values of the row at the same position
     * @param int $bytes Logical bytes processed to read the portion out
     */
    public function __construct(public array $ids, public array $values, public int $bytes)
    {
    }
}

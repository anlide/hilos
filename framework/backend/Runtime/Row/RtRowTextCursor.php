<?php

declare(strict_types=1);

namespace Hilos\Runtime\Row;

use Generator;
use Hilos\Runtime\RtRowValues;
use Hilos\Runtime\Snapshot\RtWork;

/** Byte cursor can split a leaf to fit even a one-byte pass. */
final class RtRowTextCursor
{
    private readonly RtRowValues $lease;
    private ?RtRowPage $leaf;
    private int $offset = 0;

    /**
     * @internal Constructed after the reader's text type check.
     *
     * @param RtRowArena $arena Storage owner
     * @param RtRowPage $root Text root
     */
    public function __construct(RtRowArena $arena, RtRowPage $root)
    {
        $this->lease = new RtRowValues($arena, $root, RtRowRole::Transient);
        $this->leaf = $root->first;
    }

    /**
     * @return Generator<int, RtWork, int, ?string> Next bounded bytes, or null at the end
     */
    public function advance(): Generator
    {
        if ($this->leaf === null) {
            yield new RtWork();

            return null;
        }
        $bytes = yield new RtWork($this->leaf->payloadBytes - $this->offset);
        $part = substr($this->leaf->scalar, $this->offset, $bytes);
        $this->offset += $bytes;
        if ($this->offset === $this->leaf->payloadBytes) {
            $this->leaf = $this->leaf->next;
            $this->offset = 0;
        }

        return $part;
    }
}

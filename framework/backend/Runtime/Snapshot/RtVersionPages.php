<?php

declare(strict_types=1);

namespace Hilos\Runtime\Snapshot;

use Generator;
use Hilos\Runtime\Row\RtRowArena;
use Hilos\Runtime\Row\RtRowKind;
use Hilos\Runtime\Row\RtRowPage;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\RtRowValues;
use LogicException;

/**
 * @internal Fixed-fan-out descriptors use the existing value arena and retirement.
 * SCAFFOLD: committed versions are isolated until the production write bridges land.
 */
final class RtVersionPages
{
    private const int MAX_METADATA_BYTES = 32;

    /**
     * Child pages must remain retained by the caller until this operation completes.
     * The new page owns each edge, including two edges to the same shared subtree.
     *
     * @param RtRowArena $arena Process-local owner
     * @param RtRowKind $kind Internal descriptor shape
     * @param int|string|null $scalar Fixed-size metadata
     * @param ?RtRowPage $first First retained subtree
     * @param ?RtRowPage $second Second retained subtree
     * @param ?RtRowPage $next Third retained subtree
     * @return Generator<int, RtWork, int, RtRowValues> One completed descriptor lease
     * @throws LogicException When inline metadata exceeds its fixed-size contract
     */
    public static function make(
        RtRowArena $arena,
        RtRowKind $kind,
        int|string|null $scalar = null,
        ?RtRowPage $first = null,
        ?RtRowPage $second = null,
        ?RtRowPage $next = null,
    ): Generator {
        yield new RtWork();
        $payloadBytes = is_string($scalar) ? strlen($scalar) : 0;
        if ($payloadBytes > self::MAX_METADATA_BYTES) {
            throw new LogicException('RT descriptor metadata must be a fixed-size key');
        }
        // Only fixed-size hashes are stored inline; arbitrary text stays segmented.
        $offset = 0;
        while ($offset < $payloadBytes) {
            $offset += yield new RtWork($payloadBytes - $offset);
        }
        yield new RtWork();
        $page = $arena->allocate($kind, $scalar, $payloadBytes);
        $lease = new RtRowValues($arena, $page, RtRowRole::Transient);
        foreach (['first' => $first, 'second' => $second, 'next' => $next] as $edge => $child) {
            yield new RtWork();
            if ($child !== null) {
                ++$child->references;
                $page->$edge = $child;
                $arena->grow($page, $child->logicalBytes);
            }
        }

        return $lease;
    }
}

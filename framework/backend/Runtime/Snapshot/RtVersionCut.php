<?php

declare(strict_types=1);

namespace Hilos\Runtime\Snapshot;

use Generator;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Runtime\Row\RtRowRole;
use Hilos\Runtime\RtRowValues;
use LogicException;

/**
 * @internal A cut owns one immutable version, never a map of mutable state handles.
 * SCAFFOLD: internal store cut; this is not production RtSnapshot::capture().
 */
final readonly class RtVersionCut
{
    /**
     * @param RtVersionDescriptor $version Independently pinned descriptor
     */
    public function __construct(private RtVersionDescriptor $version)
    {
    }

    /** @return int Committed local revision captured with the root */
    public function revision(): int
    {
        return $this->version->revision;
    }

    /** @return int Rows at this cut */
    public function count(): int
    {
        return $this->version->count;
    }

    /**
     * @param RtPreparedIdentity $identity Already prepared row key
     * @return Generator<int, RtWork, int, ?RtPreparedRow> Frozen row or absence
     * @throws InvalidFormatException When an identity is malformed
     * @throws LogicException When the cut/key closed or their arenas differ
     */
    public function find(RtPreparedIdentity $identity): Generator
    {
        $arena = $this->version->arena;
        $identity->descriptor->assertArena($arena);
        $source = $this->version->root->retain(RtRowRole::Transient);
        $page = $source->rootIn($arena);
        $entry = yield from new RtPersistentIndex($arena)->find($page->first, $identity->hash());
        while ($entry !== null) {
            yield new RtWork();
            if (yield from $identity->matches($entry->first->first)) {
                return new RtPreparedRow($arena, new RtRowValues($arena, $entry->first, RtRowRole::Transient));
            }
            $entry = $entry->next;
        }

        return null;
    }

    /**
     * @return RtIndexCursor Frozen membership in insertion order; each payload is a membership page
     * @throws LogicException When this cut closed
     */
    public function rows(): RtIndexCursor
    {
        $arena = $this->version->arena;

        return new RtIndexCursor($arena, $this->version->root->retain(RtRowRole::Snapshot), $this->version->root->rootIn($arena)->second);
    }

    /**
     * This prefix belongs to the latest committed batch, not an unbounded history, and exists only
     * while a reader holds the store's changes ({@see RtVersionStore::retainChanges()}); without
     * one it is empty. A delivery owner retains its own prefix before a later commit replaces it.
     *
     * @return RtIndexCursor Change records in committed revision order
     * @throws LogicException When this cut closed
     */
    public function changes(): RtIndexCursor
    {
        $arena = $this->version->arena;

        return new RtIndexCursor($arena, $this->version->root->retain(RtRowRole::Ordinary), $this->version->root->rootIn($arena)->next);
    }

    /** Ends this cut's lease immediately; other cuts/cursors keep their roots. */
    public function cancel(): void
    {
        $this->version->root->release();
    }
}

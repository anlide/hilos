<?php

declare(strict_types=1);

namespace Hilos\Core\Table\DTO;

/**
 * The places standing right outside a window: the one just before its first row and the one just after its last.
 *
 * This is a snapshot of the build, exactly like the boundary anchors of the window: an edit, a
 * removal or a creation at the edge of the window after it was built is not seen until the next
 * build. It is kept on the server and never reaches the client, so it has no wire form.
 *
 * The places are written in the keys of the row source, the same keys the boundary anchors use:
 * the ORM places by entity column, an in-memory set by row field.
 *
 * Two absences mean two different answers. No object where one is expected means the source did
 * not report the frame at all; an object without a place on one side means the set ends there.
 */
final readonly class TableWindowFrameDTO
{
    /**
     * @param ?TableAnchorDTO $before Place of the row (or of the anchor) standing right before the first row
     *     of the window, or null when the set starts with the window
     * @param ?TableAnchorDTO $after Place of the row (or of the anchor) standing right after the last row
     *     of the window, or null when the set ends with the window
     */
    public function __construct(
        public ?TableAnchorDTO $before = null,
        public ?TableAnchorDTO $after = null,
    ) {
    }
}

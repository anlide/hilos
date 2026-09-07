<?php

declare(strict_types=1);

namespace Hilos\Core\Browser\DTO;

use Hilos\Core\Table\DTO\TableSnapshotDTO;

/**
 * One window a connection has just been built, before it is written into a frame.
 *
 * Two frames carry a window and they carry it differently — the page subscription answers with
 * a section of its payload, a viewport request answers with a table_window signal — while the
 * work behind both is the same query, the same row serialization and the same record of what
 * this connection was given. This is the result of that one build, handed to whichever frame
 * asked for it (HIL-642).
 *
 * The rows are already in their wire shape; the snapshot is kept whole rather than unpacked,
 * because the coordinates a window travels with — its size, its total, and the places its
 * first and last rows sit at — are the snapshot's own answer and there is nothing to add to it.
 */
final readonly class BrowserTableWindow
{
    /**
     * @param list<array{rowKey: int|string, slots: array<string, mixed>, staleSources?: list<string>}> $rows Window
     *     rows in display order, in their wire shape
     * @param TableSnapshotDTO $snapshot Window the table served, with its total and its boundaries
     */
    public function __construct(
        public array $rows,
        public TableSnapshotDTO $snapshot,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Core\Table\Definition;

use Hilos\Core\Table\DTO\TableSnapshotDTO;

/**
 * Contract for a table that delivers its own browser rows.
 *
 * A self-snapshot table produces its browser rows from its own full snapshot
 * (initial subscription) and from a source-change mutation (reactive update),
 * instead of the BrowserContext DB/RT source fan-out. This fits tables whose row
 * set is not a direct projection of source items — settings, where catalog keys
 * without a persisted DB row must still appear as placeholder rows.
 *
 * It is the snapshot-delivering kind of {@see ViewportTable}: the shared
 * buildMutationForSourceEvent() and browserRow() also drive its server-windowed
 * viewport, while getFullSnapshot() here serves its non-viewport subscription. The
 * base BrowserContext reads getFullSnapshot() and buildMutationForSourceEvent(),
 * then serializes each row with browserRow(). A TableDefinition subclass satisfies
 * getFullSnapshot() and buildMutationForSourceEvent() by inheritance, so only
 * browserRow() is feature-specific.
 */
interface SelfSnapshotTable extends ViewportTable
{
    /**
     * Loads the complete table snapshot for an initial page subscription.
     *
     * @return TableSnapshotDTO Full snapshot with typed rows
     */
    public function getFullSnapshot(): TableSnapshotDTO;
}

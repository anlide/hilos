<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Item;

use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\TableLagRuntime as StateTableLagRuntime;
use Hilos\Runtime\View\Item\TableLagRuntime as ViewTableLagRuntime;

/**
 * Write operations for the test-only table lag singleton (HIL-1020).
 *
 * One method, because the command behind it names the whole state on every call: a lag the
 * caller did not name is a lag it wants off, so there is no partial write to express. The daemon
 * master of the node is the registered truth source; {@see RtActions::ensureCanWrite()} enforces
 * that.
 *
 * @extends RtActions<ViewTableLagRuntime, StateTableLagRuntime>
 * @property-read StateTableLagRuntime $state
 */
final class TableLagRuntimeActions extends RtActions
{
    /**
     * Puts both lags on the row and syncs them to the workers of this node.
     *
     * @param int $windowMs Milliseconds a changed table window is held back; 0 turns it off
     * @param int $facetsMs Milliseconds a facet count is held back; 0 turns it off
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function set(int $windowMs, int $facetsMs): void
    {
        $this->ensureCanWrite();

        $this->state->windowMs = $windowMs;
        $this->state->facetsMs = $facetsMs;
        $this->sync();
    }
}

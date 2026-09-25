<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Item;

use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\TableRefusalRuntime as StateTableRefusalRuntime;
use Hilos\Runtime\View\Item\TableRefusalRuntime as ViewTableRefusalRuntime;

/**
 * Write operations for the test-only table refusal singleton (HIL-1131).
 *
 * One method, because the command behind it names the whole state on every call: a call that
 * names no table wants the refusal off, so there is no partial write to express. The daemon
 * master of the node is the registered truth source; {@see RtActions::ensureCanWrite()} enforces
 * that.
 *
 * @extends RtActions<ViewTableRefusalRuntime, StateTableRefusalRuntime>
 * @property-read StateTableRefusalRuntime $state
 */
final class TableRefusalRuntimeActions extends RtActions
{
    /**
     * Puts the refused table on the row and syncs it to the workers of this node.
     *
     * @param string $tableKey Wire key of the table whose windows are refused; empty turns it off
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function set(string $tableKey): void
    {
        $this->ensureCanWrite();

        $this->state->tableKey = $tableKey;
        $this->sync();
    }
}

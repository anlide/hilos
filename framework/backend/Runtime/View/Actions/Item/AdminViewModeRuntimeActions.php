<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Item;

use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\AdminViewModeRuntime as StateAdminViewModeRuntime;
use Hilos\Runtime\View\Item\AdminViewModeRuntime as ViewAdminViewModeRuntime;

/**
 * Write operations for the admin view mode singleton of a node (HIL-1249).
 *
 * One method, because both writers name the whole state on every call: the start of the node with
 * its decision, and `test:admin-view-mode` with on or off. The daemon master of the node is the
 * registered truth source; {@see RtActions::ensureCanWrite()} enforces that.
 *
 * @extends RtActions<ViewAdminViewModeRuntime, StateAdminViewModeRuntime>
 * @property-read StateAdminViewModeRuntime $state
 */
final class AdminViewModeRuntimeActions extends RtActions
{
    /**
     * Puts the mode on the row and syncs it to the workers of this node.
     *
     * @param bool $enabled Whether a non-admin may open the admin section on this node to look
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function set(bool $enabled): void
    {
        $this->ensureCanWrite();

        $this->state->enabled = $enabled;
        $this->sync();
    }
}

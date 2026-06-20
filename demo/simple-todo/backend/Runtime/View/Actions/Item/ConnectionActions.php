<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Runtime\View\Actions\Item;

use Demo\SimpleTodo\Runtime\State\Item\Connection as StateConnection;
use Demo\SimpleTodo\Runtime\View\Item\Connection as RuntimeConnection;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Item\RtItemParentCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\View\Actions\Item\RtActions;

/**
 * Write operations for a single connection (RtItem).
 *
 * @extends RtActions<RuntimeConnection, StateConnection>
 * @property-read StateConnection $state
 */
final class ConnectionActions extends RtActions
{
    /**
     * Removes this connection from the runtime collection on socket close.
     *
     * @throws RtActionsCollectionNameNullException When the collection name is unavailable
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     * @throws RtItemParentCollectionNullException When this connection is not attached to a collection
     * @throws RtTruthSourceWriteNotAllowedException When the caller is not the truth source
     */
    public function unregister(): void
    {
        $this->remove();
    }
}

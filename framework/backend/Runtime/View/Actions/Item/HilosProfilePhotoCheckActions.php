<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Item;

use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Item\RtItemParentCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\HilosProfilePhotoCheck as StateHilosProfilePhotoCheck;
use Hilos\Runtime\View\Item\HilosProfilePhotoCheck;

/**
 * @extends RtActions<HilosProfilePhotoCheck, StateHilosProfilePhotoCheck>
 * @property-read StateHilosProfilePhotoCheck $state
 */
final class HilosProfilePhotoCheckActions extends RtActions
{
    /**
     * Ends this connection's pending check.
     *
     * @throws RtActionsCollectionNameNullException When the collection name is unavailable
     * @throws RtActionsStateCollectionNullException When the runtime collection is unavailable
     * @throws RtItemParentCollectionNullException When the item is detached
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws SourceChangeSubscriberException Whatever a subscriber raises
     */
    public function forget(): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);
        $this->remove();
    }
}

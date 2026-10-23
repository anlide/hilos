<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Item;

use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Item\RtItemParentCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\HilosModerationCheck as StateHilosModerationCheck;
use Hilos\Runtime\View\Item\HilosModerationCheck;

/**
 * Write operations for one moderation check (HIL-1625).
 *
 * A check is never edited: a changed content is a new attempt under a new request id. The one
 * move left to a known row is its end.
 *
 * @extends RtActions<HilosModerationCheck, StateHilosModerationCheck>
 * @property-read StateHilosModerationCheck $state
 */
final class HilosModerationCheckActions extends RtActions
{
    /**
     * Ends this check: its verdict was applied, its wait was given up, or its attempt is replaced.
     *
     * @throws RtActionsCollectionNameNullException When the collection name is unavailable
     * @throws RtActionsStateCollectionNullException When the runtime collection is unavailable
     * @throws RtItemParentCollectionNullException When the item is detached
     * @throws RtTruthSourceWriteNotAllowedException When the caller does not hold the set the check is in
     * @throws SourceChangeSubscriberException Whatever a subscriber raises
     */
    public function forget(): void
    {
        $this->remove();
    }
}

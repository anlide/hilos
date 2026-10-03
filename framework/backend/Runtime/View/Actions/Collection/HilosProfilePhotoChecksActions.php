<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Collection;

use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Runtime\Exception\Actions\RtActionsCallbackNotSetException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsItemClassException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Collection\HilosProfilePhotoChecks as StateHilosProfilePhotoChecks;
use Hilos\Runtime\State\Item\HilosProfilePhotoCheck as StateHilosProfilePhotoCheck;
use Hilos\Runtime\View\Collection\HilosProfilePhotoChecks;
use Hilos\Runtime\View\Item\HilosProfilePhotoCheck;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * @extends RtActions<HilosProfilePhotoCheck, HilosProfilePhotoChecks, StateHilosProfilePhotoChecks>
 * @property-read StateHilosProfilePhotoChecks $stateCollection
 */
final class HilosProfilePhotoChecksActions extends RtActions
{
    /**
     * Opens a check on a connection after its upload completed.
     *
     * @param string $acceptKey Connection awaiting the verdict
     * @param int $userId Person whose photo is checked
     * @param string $clientUploadId Completed upload id
     * @return HilosProfilePhotoCheck Pending check
     * @throws RtActionsCollectionNameNullException When the collection name is unavailable
     * @throws RtActionsStateCollectionNullException When the runtime collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws SourceChangeSubscriberException Whatever a subscriber raises
     * @throws RtActionsCallbackNotSetException When the item factory is unavailable
     * @throws RtActionsItemClassException When the item factory returns an invalid class
     */
    public function open(string $acceptKey, int $userId, string $clientUploadId): HilosProfilePhotoCheck
    {
        $this->ensureCanWrite();
        $state = StateHilosProfilePhotoCheck::create($acceptKey, $userId, $clientUploadId, TimeHelper::nowMs());
        $this->addStateToCollection($state);

        return $this->createRtItemFromState($state);
    }
}

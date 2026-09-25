<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Collection;

use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Fs\Exception\DirectoryNotFoundException;
use Hilos\Fs\Exception\FileDeleteException;
use Hilos\Runtime\Exception\Actions\RtActionsCallbackNotSetException;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsItemClassException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemParentCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Collection\HilosUploads as StateHilosUploads;
use Hilos\Runtime\State\Item\HilosUpload as StateHilosUpload;
use Hilos\Runtime\View\Actions\Item\HilosUploadActions;
use Hilos\Runtime\View\Collection\HilosUploads;
use Hilos\Runtime\View\Item\HilosUpload;

/**
 * Write API for the uploads, as a set (HIL-135).
 *
 * Two methods: an accepted declaration opens an upload, and the agent that owns the set wipes
 * it with every temporary file when it starts or stops. What happens to one upload in between -
 * its chunks, its ending, its removal - is a write over a row the agent has already found, and
 * lives on {@see HilosUploadActions}.
 *
 * @extends RtActions<HilosUpload, HilosUploads, StateHilosUploads>
 * @property-read StateHilosUploads $stateCollection
 */
final class HilosUploadsActions extends RtActions
{
    /**
     * Opens one accepted upload in the ready phase, with no byte received.
     *
     * @param string $acceptKey Accept key of the connection
     * @param string $clientUploadId Id the client gave the upload
     * @param string $target Name of the upload target
     * @param ?int $userId User signed in on the connection, or null for a guest
     * @param string $filename File name without any path
     * @param string $mimeType Declared type, normalized
     * @param int $declaredSize Declared size in bytes
     * @param string $tmpIndex Index of the empty temporary file created for it
     * @return HilosUpload The opened upload
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     * @throws RtActionsCallbackNotSetException When the collection's item factory is not configured
     * @throws RtActionsItemClassException When the item factory returns a class the collection does not accept
     */
    public function open(
        string $acceptKey,
        string $clientUploadId,
        string $target,
        ?int $userId,
        string $filename,
        string $mimeType,
        int $declaredSize,
        string $tmpIndex,
    ): HilosUpload {
        $this->ensureCanWrite();

        $state = StateHilosUpload::create(
            $acceptKey,
            $clientUploadId,
            $target,
            $userId,
            $filename,
            $mimeType,
            $declaredSize,
            $tmpIndex,
            time(),
        );
        $this->addStateToCollection($state);

        return $this->createRtItemFromState($state);
    }

    /**
     * Removes every upload and deletes every temporary file they hold.
     *
     * The owner's reset: on start the rows left by a predecessor that died cannot continue - the
     * agent that was receiving them is gone - and on stop nobody will receive them either.
     *
     * @return list<HilosUpload> The removed uploads, so the caller can tell their connections
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     * @throws RtActionsCallbackNotSetException When the collection's item factory is not configured
     * @throws RtActionsItemClassException When the item factory returns a class the collection does not accept
     * @throws RtItemActionsClassException When the item actions class is missing or invalid
     * @throws RtItemParentCollectionNullException When an item is not attached to the collection
     * @throws DirectoryNotFoundException When the tmp directory is not configured
     * @throws FileDeleteException When a temporary file exists but cannot be deleted
     */
    public function clearAllWithFiles(): array
    {
        $this->ensureCanWrite();

        $removed = [];
        foreach (iterator_to_array($this->stateCollection, false) as $state) {
            $upload = $this->createRtItemFromState($state);
            $upload->actions->forgetWithFile();
            $removed[] = $upload;
        }

        return $removed;
    }
}

<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Item;

use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Files\Upload\UploadPhase;
use Hilos\Fs\Exception\DirectoryNotFoundException;
use Hilos\Fs\Exception\FileDeleteException;
use Hilos\Hilos;
use Hilos\Runtime\Exception\Actions\RtActionsCollectionNameNullException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Item\RtItemParentCollectionNullException;
use Hilos\Runtime\Exception\TruthSource\RtTruthSourceWriteNotAllowedException;
use Hilos\Runtime\State\Item\HilosUpload as StateHilosUpload;
use Hilos\Runtime\View\Item\HilosUpload as ViewHilosUpload;

/**
 * Write operations for one upload (HIL-135).
 *
 * The moves an upload makes once it is open: bytes arrive, its content type is read, it
 * completes or fails, and it goes with its file. All of them are the uploads agent's alone.
 *
 * @extends RtActions<ViewHilosUpload, StateHilosUpload>
 * @property-read StateHilosUpload $state
 */
final class HilosUploadActions extends RtActions
{
    /**
     * Records how many bytes have arrived; the first write moves the upload to uploading.
     *
     * @param int $receivedBytes Exact count of bytes received so far
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function advance(int $receivedBytes): void
    {
        $this->applyDiffWithSync([
            StateHilosUpload::receivedBytes => $receivedBytes,
            StateHilosUpload::phase => UploadPhase::UPLOADING->value,
            StateHilosUpload::updatedAt => time(),
        ]);
    }

    /**
     * Records the type read from the content of the whole file, before the checks judge it.
     *
     * The phase does not move: the upload is not complete until the checks that read this type
     * have let it through.
     *
     * @param string $detectedMimeType Type read from the content
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function detect(string $detectedMimeType): void
    {
        $this->applyDiffWithSync([
            StateHilosUpload::detectedMimeType => $detectedMimeType,
            StateHilosUpload::updatedAt => time(),
        ]);
    }

    /**
     * Completes the upload: every declared byte arrived and passed every check.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function complete(): void
    {
        $this->applyDiffWithSync([
            StateHilosUpload::receivedBytes => $this->state->declaredSize,
            StateHilosUpload::phase => UploadPhase::COMPLETE->value,
            StateHilosUpload::updatedAt => time(),
        ]);
    }

    /**
     * Fails the upload and deletes its temporary file; the row stays to say why.
     *
     * @param string $code Stable error code
     * @param string $message Sentence shown to the person
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws DirectoryNotFoundException When the tmp directory is not configured
     * @throws FileDeleteException When the temporary file exists but cannot be deleted
     */
    public function fail(string $code, string $message): void
    {
        $this->ensureCanWrite();
        $this->deleteFile();

        $this->applyDiffWithSync([
            StateHilosUpload::tmpIndex => null,
            StateHilosUpload::phase => UploadPhase::FAILED->value,
            StateHilosUpload::errorCode => $code,
            StateHilosUpload::errorMessage => $message,
            StateHilosUpload::updatedAt => time(),
        ]);
    }

    /**
     * Removes the upload, deleting its temporary file first when it still has one.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtItemParentCollectionNullException When item is not attached to a collection
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     * @throws DirectoryNotFoundException When the tmp directory is not configured
     * @throws FileDeleteException When the temporary file exists but cannot be deleted
     */
    public function forgetWithFile(): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);
        $this->deleteFile();
        $this->remove();
    }

    /**
     * Deletes the temporary file, if the upload still has one; an absent file is not an error.
     *
     * @throws DirectoryNotFoundException When the tmp directory is not configured
     * @throws FileDeleteException When the temporary file exists but cannot be deleted
     */
    private function deleteFile(): void
    {
        if ($this->state->tmpIndex === null) {
            return;
        }

        Hilos::$fs?->getTmp()[$this->state->tmpIndex]->unlink();
    }
}

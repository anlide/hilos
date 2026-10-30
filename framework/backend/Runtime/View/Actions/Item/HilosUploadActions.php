<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Actions\Item;

use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\InvalidFormatException;
use Hilos\Core\Exception\InvalidStateException;
use Hilos\Core\Source\Exception\SourceChangeSubscriberException;
use Hilos\Core\TruthSource\TruthSourceOperation;
use Hilos\Files\ContentHash;
use Hilos\Files\Upload\DTO\UploadSnapshotData;
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
use Hilos\Utils\Helpers\TimeHelper;

/**
 * Write operations for one upload (HIL-135).
 *
 * The moves an upload makes once it is open: bytes arrive, its fingerprint and content type are
 * recorded, it completes or fails, and it goes - with its file, or handing the file over to the
 * files registry. All of them are the uploads agent's alone.
 *
 * @extends RtActions<ViewHilosUpload, StateHilosUpload>
 * @property-read StateHilosUpload $state
 */
final class HilosUploadActions extends RtActions
{
    /**
     * Holds received bytes until a fixed deadline. A released token never reopens, and repeating
     * a live token with its original deadline preserves any approval already recorded.
     *
     * @param string $uploadHoldId Fresh attempt token, shared across its stages
     * @param int $deadlineAt Fixed future epoch-millisecond deadline
     * @throws InvalidArgumentException When the token is empty or the deadline is not in the future
     * @throws InvalidStateException When bytes are incomplete, another token is active, or this token ended or changed deadline
     * @throws InvalidFormatException When the received byte identity is malformed
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When the caller is not the upload owner
     */
    public function hold(string $uploadHoldId, int $deadlineAt): void
    {
        $this->ensureCanWrite();
        $nowMs = TimeHelper::nowMs();
        if ($uploadHoldId === '' || $deadlineAt <= $nowMs) {
            throw new InvalidArgumentException('An upload hold requires a nonempty token and a future deadline.');
        }
        $this->assertHoldable();
        if ($this->state->uploadHoldId === $uploadHoldId) {
            if ($this->state->uploadHoldDeadlineAt !== $deadlineAt) {
                throw new InvalidStateException('A released hold cannot reopen and a hold deadline cannot change.');
            }

            return;
        }
        if ($this->state->uploadHoldDeadlineAt !== null && $this->state->uploadHoldDeadlineAt > $nowMs) {
            throw new InvalidStateException('Another attempt already holds these upload bytes.');
        }
        $this->applyDiffWithSync([
            StateHilosUpload::uploadHoldId => $uploadHoldId,
            StateHilosUpload::uploadHoldDeadlineAt => $deadlineAt,
            StateHilosUpload::uploadHoldApproved => false,
        ]);
    }

    /**
     * @param string $uploadHoldId Attempt whose final verdict approved these bytes
     * @throws InvalidArgumentException When the token is empty
     * @throws InvalidStateException When the hold is missing, mismatched, expired or released, or its bytes are incomplete
     * @throws InvalidFormatException When the received byte identity is malformed
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When the caller is not the upload owner
     */
    public function approveHold(string $uploadHoldId): void
    {
        $this->ensureCanWrite();
        if ($uploadHoldId === '') {
            throw new InvalidArgumentException('An upload hold token must be nonempty.');
        }
        if (
            $this->state->uploadHoldId !== $uploadHoldId || $this->state->uploadHoldDeadlineAt === null
            || $this->state->uploadHoldDeadlineAt <= TimeHelper::nowMs()
        ) {
            throw new InvalidStateException('Only the matching live upload hold can be approved.');
        }
        $this->assertHoldable();
        if (!$this->state->uploadHoldApproved) {
            $this->applyDiffWithSync([StateHilosUpload::uploadHoldApproved => true]);
        }
    }

    /**
     * Leaves a tombstone for a matching token. A late release for another token changes nothing.
     *
     * @param string $uploadHoldId Attempt releasing these bytes
     * @throws InvalidArgumentException When the token is empty
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When the caller is not the upload owner
     */
    public function releaseHold(string $uploadHoldId): void
    {
        $this->ensureCanWrite();
        if ($uploadHoldId === '') {
            throw new InvalidArgumentException('An upload hold token must be nonempty.');
        }
        if ($this->state->uploadHoldId !== $uploadHoldId || $this->state->uploadHoldDeadlineAt === null) {
            return;
        }
        $this->applyDiffWithSync([
            StateHilosUpload::uploadHoldDeadlineAt => null,
            StateHilosUpload::uploadHoldApproved => false,
        ]);
    }

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
     * Records what was learned from the whole file, before the checks judge it: its fingerprint
     * and, for a target that sniffs, the type read from its content.
     *
     * One write for both, so the checks - a project's included - read them on the row. The phase
     * does not move: the upload is not complete until those checks have let it through.
     *
     * @param string $contentHash Fingerprint of the whole file ({@see ContentHash})
     * @param ?string $detectedMimeType Type read from the content, or null when the target does not sniff
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     */
    public function inspect(string $contentHash, ?string $detectedMimeType): void
    {
        $diff = [
            StateHilosUpload::contentHash => $contentHash,
            StateHilosUpload::updatedAt => time(),
        ];
        if ($detectedMimeType !== null) {
            $diff[StateHilosUpload::detectedMimeType] = $detectedMimeType;
        }

        $this->applyDiffWithSync($diff);
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
     * Removes the upload and leaves its temporary file where it is: the file is handed over to
     * the files registry, which takes it from the tmp directory.
     *
     * Once the row is gone nothing of the upload's own can touch the file any more - neither
     * the sweep, nor a cancel, nor a declaration under the same id.
     *
     * @throws RtActionsCollectionNameNullException When collection name is unavailable
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     * @throws RtItemParentCollectionNullException When item is not attached to a collection
     * @throws RtTruthSourceWriteNotAllowedException When caller is not the truth source
     * @throws SourceChangeSubscriberException Whatever a subscriber to the collection's announcement raises
     */
    public function handOver(): void
    {
        $this->ensureCanWrite(TruthSourceOperation::Remove);
        $this->remove();
    }

    /**
     * @throws InvalidStateException When the upload has no complete received byte identity
     * @throws InvalidFormatException When the received byte identity is malformed
     */
    private function assertHoldable(): void
    {
        if (
            $this->state->phase !== UploadPhase::COMPLETE->value
            || $this->state->tmpIndex === null || $this->state->contentHash === null
        ) {
            throw new InvalidStateException('An upload hold requires complete bytes with a temporary file and fingerprint.');
        }
        new UploadSnapshotData($this->state->clientUploadId, $this->state->tmpIndex, $this->state->contentHash);
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

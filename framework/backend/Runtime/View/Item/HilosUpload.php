<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Item;

use Hilos\Files\Upload\UploadPhase;
use Hilos\Runtime\Exception\Item\RtItemActionsClassException;
use Hilos\Runtime\Exception\Item\RtItemPropertyNotFoundException;
use Hilos\Runtime\State\Item\HilosUpload as StateHilosUpload;
use Hilos\Runtime\View\Actions\Item\HilosUploadActions;

/**
 * Read-only wrapper over one file a connection is sending or has sent (HIL-135).
 *
 * What the uploads agent reads to accept a chunk, and what a consumer reads to take a received
 * file: its phase, its size, where its temporary file is. The phase is handed out as
 * {@see UploadPhase}; the raw string stays in the state.
 *
 * @extends RtItem<StateHilosUpload>
 *
 * @property-read string $acceptKey Accept key of the connection the upload belongs to
 * @property-read string $clientUploadId Id the client gave the upload
 * @property-read string $target Name of the upload target
 * @property-read ?int $userId User signed in on the connection at declaration, or null for a guest
 * @property-read string $filename File name without any path
 * @property-read string $mimeType Declared type, normalized
 * @property-read int $declaredSize Declared size in bytes
 * @property-read int $receivedBytes Bytes received as of the last write of the row
 * @property-read ?string $tmpIndex Index of the temporary file, or null once it is deleted
 * @property-read UploadPhase $phase Where the upload stands
 * @property-read ?string $detectedMimeType Type read from the content, only for a target that sniffs
 * @property-read ?string $errorCode Code of the failure, on the failed phase alone
 * @property-read ?string $errorMessage Sentence of the failure, on the failed phase alone
 * @property-read int $updatedAt Unix seconds of the last change of the row
 * @property-read HilosUploadActions $actions Actions for write operations
 */
final class HilosUpload extends RtItem
{
    /**
     * @param StateHilosUpload $state Backing runtime state
     */
    public function __construct(StateHilosUpload $state)
    {
        parent::__construct($state);
    }

    /**
     * @param string $name Property name
     * @return string|int|UploadPhase|HilosUploadActions|null Property value
     * @throws RtItemPropertyNotFoundException When $name is not a declared property
     * @throws RtItemActionsClassException When the item actions class is missing or invalid
     */
    public function __get(string $name): string|int|UploadPhase|HilosUploadActions|null
    {
        return match ($name) {
            StateHilosUpload::acceptKey => $this->_state->acceptKey,
            StateHilosUpload::clientUploadId => $this->_state->clientUploadId,
            StateHilosUpload::target => $this->_state->target,
            StateHilosUpload::userId => $this->_state->userId,
            StateHilosUpload::filename => $this->_state->filename,
            StateHilosUpload::mimeType => $this->_state->mimeType,
            StateHilosUpload::declaredSize => $this->_state->declaredSize,
            StateHilosUpload::receivedBytes => $this->_state->receivedBytes,
            StateHilosUpload::tmpIndex => $this->_state->tmpIndex,
            // The state refuses any other value on the way in (StateHilosUpload::readPhase()), so from() cannot fail here.
            StateHilosUpload::phase => UploadPhase::from($this->_state->phase),
            StateHilosUpload::detectedMimeType => $this->_state->detectedMimeType,
            StateHilosUpload::errorCode => $this->_state->errorCode,
            StateHilosUpload::errorMessage => $this->_state->errorMessage,
            StateHilosUpload::updatedAt => $this->_state->updatedAt,
            RtItem::actions => $this->getItemActions(),
            default => parent::__get($name),
        };
    }

    /**
     * @return array<string, mixed> Full state row
     */
    public function toArray(): array
    {
        return $this->_state->toArray();
    }
}

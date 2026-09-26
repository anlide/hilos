<?php

declare(strict_types=1);

namespace Hilos\Runtime\View\Collection;

use Hilos\Files\ContentHash;
use Hilos\Files\Upload\UploadPhase;
use Hilos\HilosException;
use Hilos\Runtime\Exception\Actions\RtActionsStateCollectionNullException;
use Hilos\Runtime\Exception\Collection\RtCollectionActionsClassException;
use Hilos\Runtime\Exception\Collection\RtCollectionPropertyNotFoundException;
use Hilos\Runtime\State\Collection\HilosUploads as StateHilosUploads;
use Hilos\Runtime\State\Item\HilosUpload as StateHilosUpload;
use Hilos\Runtime\State\Item\RtState;
use Hilos\Runtime\View\Actions\Collection\HilosUploadsActions;
use Hilos\Runtime\View\Item\HilosUpload;

/**
 * Read-only wrapper around the files connections are sending or have sent (HIL-135).
 *
 * Framework-owned on both halves and mounted by the uploads feature in every process: any
 * consumer may read a received upload here, and only the uploads agent writes.
 *
 * @extends RtCollection<HilosUpload, HilosUploadsActions>
 * @property-read HilosUploadsActions $actions Actions for write operations
 */
final class HilosUploads extends RtCollection
{
    /**
     * @return StateHilosUploads Backing state collection
     * @throws RtActionsStateCollectionNullException When runtime state collection is unavailable
     */
    public function getStateCollection(): StateHilosUploads
    {
        /** @var StateHilosUploads */
        return parent::getStateCollection();
    }

    /**
     * @param string $acceptKey Accept key of the connection
     * @param string $clientUploadId Id the client gave the upload
     * @return ?HilosUpload The upload, or null when the connection has none under that id
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     */
    public function find(string $acceptKey, string $clientUploadId): ?HilosUpload
    {
        return $this[StateHilosUpload::keyFor($acceptKey, $clientUploadId)];
    }

    /**
     * @param string $acceptKey Accept key of the connection
     * @return list<HilosUpload> Every upload of the connection, in any phase
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     */
    public function forConnection(string $acceptKey): array
    {
        $uploads = [];
        foreach ($this as $upload) {
            if ($upload->acceptKey === $acceptKey) {
                $uploads[] = $upload;
            }
        }

        return $uploads;
    }

    /**
     * Counts the uploads of one connection that keep a temporary file on disk.
     *
     * Ready, uploading and complete hold a file; a failed upload deleted its own and does not
     * count against the connection.
     *
     * @param string $acceptKey Accept key of the connection
     * @return int Number of the connection's uploads holding a file
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     */
    public function countHoldingFiles(string $acceptKey): int
    {
        $count = 0;
        foreach ($this->forConnection($acceptKey) as $upload) {
            if ($upload->phase->holdsFile()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Sums the declared sizes of every upload that holds a file, on every connection.
     *
     * What the uploads take of the storage limit: a declared upload has its place reserved
     * from the moment it is accepted, and a complete one keeps it until it is handed over.
     *
     * @return int Bytes declared by the uploads holding a file
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     */
    public function sumHoldingBytes(): int
    {
        $bytes = 0;
        foreach ($this as $upload) {
            if ($upload->phase->holdsFile()) {
                $bytes += $upload->declaredSize;
            }
        }

        return $bytes;
    }

    /**
     * Tells whether a person has another complete upload of this content.
     *
     * @param int $userId Person whose uploads are looked at
     * @param string $contentHash Fingerprint of the content ({@see ContentHash})
     * @param string $exceptRowId Row id of the upload asking, which is not counted
     * @return bool Whether another complete upload of that person carries that fingerprint
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     */
    public function hasCompleteWithContent(int $userId, string $contentHash, string $exceptRowId): bool
    {
        foreach ($this as $upload) {
            if ($upload->userId === $userId
                && $upload->phase === UploadPhase::COMPLETE
                && $upload->contentHash === $contentHash
                && $upload->getId() !== $exceptRowId
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param RtState $state StateHilosUpload instance
     * @return HilosUpload View item for this upload
     */
    protected function createRtItem(RtState $state): HilosUpload
    {
        /** @var StateHilosUpload $state */
        return new HilosUpload($state);
    }

    /**
     * @param mixed $offset Row id `acceptKey|clientUploadId`
     * @return ?HilosUpload Item or null
     * @throws RtActionsStateCollectionNullException When the runtime state collection is unavailable
     */
    public function offsetGet(mixed $offset): ?HilosUpload
    {
        /** @var ?HilosUpload $item */
        $item = parent::offsetGet($offset);

        return $item;
    }

    /**
     * @return HilosUploadsActions Actions instance
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     */
    protected function getActions(): HilosUploadsActions
    {
        /** @var HilosUploadsActions $actions */
        $actions = parent::getActions();

        return $actions;
    }

    /**
     * @param string $name Property name
     * @return HilosUploadsActions Actions instance
     * @throws RtCollectionPropertyNotFoundException When $name is not a declared property
     * @throws RtCollectionActionsClassException When actions class is missing or invalid
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): HilosUploadsActions
    {
        return match ($name) {
            self::actions => $this->getActions(),
            default => parent::__get($name),
        };
    }
}

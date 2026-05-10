<?php

declare(strict_types=1);

namespace Demo\Chat\Database\View\Collection;

use Demo\Chat\Database\Actions\Collection\EventAttachmentsActions;
use Demo\Chat\Database\Object\Collection\EventAttachments as ObjectEventAttachments;
use Demo\Chat\Database\View\Item\EventAttachment;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Fs\FsException;
use Hilos\Utils\Helpers\FileSystemHelper;

/**
 * EventAttachments - Db collection of published chat event attachments.
 *
 * @extends DbCollection<EventAttachment, ObjectEventAttachments>
 * @method ObjectEventAttachments|null getObjectCollection()
 * @method EventAttachment|null current()
 * @method EventAttachment|null first()
 * @method EventAttachment|null last()
 * @method EventAttachment|null offsetGet(mixed $offset)
 * @property-read EventAttachmentsActions $actions Collection actions
 */
final class EventAttachments extends DbCollection
{
    public const string DB_ITEM_CLASS = EventAttachment::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectEventAttachments::class;

    /**
     * Returns published attachments for one event id.
     *
     * The key is shared by Event.id and EventMessage.eventId, so both parent
     * items can expose this collection directly.
     *
     * @param ?int $eventId Parent event id, or null for no attachments
     * @return self Attachments linked to the event id
     * @throws DatabaseException If attachment collection loading fails
     * @throws CollectionNotManualException If manual collection add fails
     * @throws ObjectGetIdStringNotImplementedException If an attachment id is not available
     */
    public function forEventId(?int $eventId): self
    {
        $collection = self::initEmpty();
        if ($eventId === null) {
            return $collection;
        }

        $this->ensureAllLoaded();
        foreach ($this as $attachment) {
            if ($attachment->eventId === $eventId) {
                $collection->add($attachment);
            }
        }

        return $collection;
    }

    /**
     * Total size in bytes of files that still exist in published storage.
     *
     * @return int Total bytes
     * @throws DatabaseException If attachment collection loading fails
     */
    public function sumPublishedAttachmentBytes(): int
    {
        $this->ensureAllLoaded();
        $sum = 0;
        foreach ($this as $attachment) {
            try {
                $sum += $attachment->file->size();
            } catch (FsException) {
                continue;
            }
        }

        return $sum;
    }

    /**
     * Check whether a published attachment already uses the normalized filename.
     *
     * @param string $normalized Normalized basename
     * @return bool True when a published attachment has that original filename
     * @throws DatabaseException If attachment collection loading fails
     */
    public function hasPublishedFileWithNormalizedFilename(string $normalized): bool
    {
        $this->ensureAllLoaded();
        foreach ($this as $attachment) {
            if (FileSystemHelper::normalizeBasename($attachment->filename) === $normalized) {
                return true;
            }
        }

        return false;
    }

    /**
     * Loads the full attachment collection for aggregate reads.
     *
     * @throws DatabaseException If attachment collection loading fails
     */
    private function ensureAllLoaded(): void
    {
        $objectCollection = $this->getObjectCollection();
        if ($objectCollection !== null && !$objectCollection->isAllLoaded()) {
            $objectCollection->loadAllFromDB();
            $this->clearCache();
        }
    }
}

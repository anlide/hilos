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

/**
 * EventAttachments - Db collection of the links between message events and registry files.
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
     * Registry files of every attachment, for a cleanup that removes them once their events are gone.
     *
     * @return list<int> Ids of the attached registry files, in attachment order
     * @throws DatabaseException If attachment collection loading fails
     */
    public function allFileIds(): array
    {
        $this->ensureAllLoaded();
        $fileIds = [];
        foreach ($this as $attachment) {
            $fileIds[] = $attachment->fileId;
        }

        return $fileIds;
    }

    /**
     * The attachment that links one registry file, if any; a file is attached to one message at most.
     *
     * @param int $fileId Id of the registry file
     * @return ?EventAttachment The attachment linking the file, or null when no message carries it
     * @throws DatabaseException If attachment collection loading fails
     */
    public function forFileId(int $fileId): ?EventAttachment
    {
        $this->ensureAllLoaded();
        foreach ($this as $attachment) {
            if ($attachment->fileId === $fileId) {
                return $attachment;
            }
        }

        return null;
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

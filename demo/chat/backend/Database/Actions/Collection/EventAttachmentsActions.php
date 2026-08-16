<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Actions\Collection;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Entity\Item\EventAttachment;
use Demo\Chat\Database\Object\Collection\EventAttachments as ObjectEventAttachments;
use Demo\Chat\Database\Object\Item\EventAttachment as ObjectEventAttachment;
use Demo\Chat\Database\View\Collection\EventAttachments as DbCollectionEventAttachments;
use Demo\Chat\Database\View\Item\EventAttachment as DbEventAttachment;
use Hilos\Core\TruthSource\TruthSourceRegistry;
use Hilos\Database\Actions\Collection\DbActions;
use Hilos\HilosException;

/**
 * EventAttachmentsActions - write operations for published event attachments.
 *
 * @extends DbActions<DbEventAttachment, ObjectEventAttachments>
 * @property-read DbCollectionEventAttachments $collection
 * @property-read ObjectEventAttachments $objectCollection
 */
final class EventAttachmentsActions extends DbActions
{
    /**
     * Get table name for EventAttachments collection.
     *
     * @return string Table name
     */
    protected function getTableName(): string
    {
        return EventAttachment::_table;
    }

    /**
     * Creates metadata for one published attachment.
     *
     * @param int $eventId Parent event id
     * @param string $filename Original client filename
     * @param string $mimeType Published MIME type
     * @param string $storedName Basename in published storage
     * @return DbEventAttachment Created attachment item
     * @throws HilosException On database or truth-source failure
     */
    public function create(int $eventId, string $filename, string $mimeType, string $storedName): DbEventAttachment
    {
        TruthSourceRegistry::checkCanCreate(ChatDbContext::eventAttachments);
        $this->ensureCanWrite();

        $attachment = ObjectEventAttachment::create();
        $attachment->eventId = $eventId;
        $attachment->filename = $filename;
        $attachment->mimeType = $mimeType;
        $attachment->storedName = $storedName;
        $attachment->sync();

        $this->addObjectToCollection($attachment);

        return $this->createDbItemFromObject($attachment);
    }

    /**
     * Deletes every attachment metadata row and clears the collection cache.
     *
     * @throws HilosException On database or truth-source failure
     */
    public function deleteAll(): void
    {
        TruthSourceRegistry::checkCanWrite(ChatDbContext::eventAttachments);

        $this->deleteAllObjects();
    }
}

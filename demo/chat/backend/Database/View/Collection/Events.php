<?php

declare(strict_types=1);

namespace Demo\Chat\Database\View\Collection;

use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Database\Actions\Collection\EventsActions;
use Demo\Chat\Database\Object\Collection\Events as ObjectEvents;
use Demo\Chat\Database\View\Item\Event;
use Hilos\Database\View\Collection\DbCollection;
use Hilos\Utils\Helpers\FileSystemHelper;

/**
 * Events - Db collection of Event items.
 *
 * @extends DbCollection<Event, ObjectEvents>
 * @method ObjectEvents|null getObjectCollection()
 * @method Event|null current()
 * @method Event|null first()
 * @method Event|null last()
 * @method Event|null offsetGet(mixed $offset)
 * @property-read EventsActions $actions Actions for write operations
 */
final class Events extends DbCollection
{
    public const string DB_ITEM_CLASS = Event::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectEvents::class;

    /**
     * Total size in bytes of all published attachments.
     *
     * @return int Total bytes from each event's JSON `size` field, skipping malformed rows.
     */
    public function sumPublishedAttachmentBytes(): int
    {
        $sum = 0;
        foreach ($this as $event) {
            foreach ($this->getPublishedAttachments($event) as $attachment) {
                $sum += (int)($attachment['size'] ?? 0);
            }
        }

        return $sum;
    }

    /**
     * Check whether a published file event already uses the normalized filename.
     *
     * @param string $normalized Normalized basename
     * @return bool True when a published attachment already has that original filename
     */
    public function hasPublishedFileWithNormalizedFilename(string $normalized): bool
    {
        foreach ($this as $event) {
            foreach ($this->getPublishedAttachments($event) as $attachment) {
                $filename = $attachment['originalFilename'] ?? $attachment['filename'] ?? '';
                if (!is_string($filename)) {
                    continue;
                }
                if (FileSystemHelper::normalizeBasename($filename) === $normalized) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Find published file metadata by download token.
     *
     * @param string $token Download token from file event data
     * @return ?array{storedName: string, originalFilename: string, mimeType: string}
     */
    public function findPublishedFileMetaByToken(string $token): ?array
    {
        foreach ($this as $event) {
            foreach ($this->getPublishedAttachments($event) as $attachment) {
                $downloadToken = $attachment['downloadToken'] ?? '';
                if (!is_string($downloadToken) || $downloadToken !== $token) {
                    continue;
                }
                $storedName = $attachment['storedName'] ?? '';
                if (!is_string($storedName) || $storedName === '') {
                    continue;
                }
                $originalFilename = $attachment['originalFilename'] ?? $attachment['filename'] ?? 'file';
                $mimeType = $attachment['mimeType'] ?? 'application/octet-stream';

                return [
                    'storedName' => basename($storedName),
                    'originalFilename' => is_string($originalFilename) ? $originalFilename : 'file',
                    'mimeType' => is_string($mimeType) ? $mimeType : 'application/octet-stream',
                ];
            }
        }

        return null;
    }

    /**
     * Extract published attachment rows from current and legacy event shapes.
     *
     * @param Event $event Event row
     * @return list<array<string, mixed>> Attachment metadata rows
     */
    private function getPublishedAttachments(Event $event): array
    {
        $raw = $event->data;
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        if ($event->type === ChatEventType::FILE_SHARED->value) {
            return [$decoded];
        }
        if ($event->type !== ChatEventType::MESSAGE_SENT->value) {
            return [];
        }

        $attachments = $decoded['attachments'] ?? [];
        if (!is_array($attachments)) {
            return [];
        }

        $rows = [];
        foreach ($attachments as $attachment) {
            if (is_array($attachment)) {
                $rows[] = $attachment;
            }
        }

        return $rows;
    }
}

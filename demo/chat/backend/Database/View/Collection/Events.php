<?php

declare(strict_types=1);

namespace Demo\Chat\Database\View\Collection;

use Demo\Chat\Constants\ChatEventType;
use Demo\Chat\Database\Actions\Collection\EventsActions;
use Demo\Chat\Database\Object\Collection\Events as ObjectEvents;
use Demo\Chat\Database\View\Item\Event;
use Hilos\Database\View\Collection\DbCollection;

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
     * Total size in bytes of all {@see ChatEventType::FILE_SHARED} events (published attachments quota).
     *
     * @return int Total bytes from each event's JSON `size` field, skipping malformed rows.
     */
    public function sumPublishedAttachmentBytes(): int
    {
        $sum = 0;
        foreach ($this as $event) {
            if ($event->type !== ChatEventType::FILE_SHARED->value) {
                continue;
            }
            $raw = $event->data;
            if (!is_string($raw) || $raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded) || !isset($decoded['size'])) {
                continue;
            }
            $sum += (int)$decoded['size'];
        }

        return $sum;
    }
}

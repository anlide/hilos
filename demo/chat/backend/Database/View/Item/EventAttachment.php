<?php

declare(strict_types=1);

namespace Demo\Chat\Database\View\Item;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Object\Item\EventAttachment as ObjectEventAttachment;
use Demo\Chat\Hilos;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\View\Item\DbItem;
use Hilos\Fs\FsFile;
use Hilos\HilosException;

/**
 * EventAttachment - Db item for published chat attachment metadata.
 *
 * @extends DbItem<ObjectEventAttachment>
 * @method __construct(ObjectEventAttachment &$objectEventAttachment)
 *
 * @property-read ?int $id
 * @property-read int $eventId
 * @property-read string $filename
 * @property-read string $mimeType
 * @property-read string $storedName
 * @property-read ?EventMessage $eventMessage Message detail row for this attachment
 * @property-read FsFile $file Published file handle
 */
final class EventAttachment extends DbItem
{
    public const string file = 'file';

    /**
     * Property getter (read-only access).
     *
     * @param string $name Property or bridge name
     * @return mixed Property value, related item, or published file handle
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     * @throws HilosException Whatever the inherited getter raises
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectEventAttachment::id => $this->_object->id,
            ObjectEventAttachment::eventId => $this->_object->eventId,
            ObjectEventAttachment::filename => $this->_object->filename,
            ObjectEventAttachment::mimeType => $this->_object->mimeType,
            ObjectEventAttachment::storedName => $this->_object->storedName,
            ChatDbContext::eventMessage => Hilos::$db->eventMessages[$this->_object->eventId],
            self::file => Hilos::$fs->published[$this->_object->storedName],
            default => parent::__get($name),
        };
    }

    /**
     * Converts the attachment to a backend or legacy entity array.
     *
     * Browser page payloads use BrowserContext rows; the frontend mode remains
     * only for established generic entity callers.
     *
     * @param bool $withId Include ID fields
     * @param bool $idAsIndex Use ID as array index
     * @param bool $withBridges Include bridge data
     * @param bool $withCalculation Include calculated fields
     * @param bool $toFrontend Prepare a legacy frontend-safe entity payload
     * @return array<string, mixed> Attachment payload
     */
    public function toArray(
        bool $withId = true,
        bool $idAsIndex = true,
        bool $withBridges = false,
        bool $withCalculation = false,
        bool $toFrontend = false,
    ): array {
        $result = parent::toArray($withId, $idAsIndex, $withBridges, $withCalculation, $toFrontend);

        if ($toFrontend) {
            unset($result[ObjectEventAttachment::storedName]);
        }

        return $result;
    }
}

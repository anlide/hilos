<?php

declare(strict_types=1);

namespace Demo\Chat\Database\View\Item;

use Demo\Chat\Database\ChatDbContext;
use Demo\Chat\Database\Object\Item\EventAttachment as ObjectEventAttachment;
use Demo\Chat\Files\ChatAttachmentUploadTarget;
use Demo\Chat\Hilos;
use Hilos\Core\Exception\InvalidArgumentException;
use Hilos\Core\Exception\LogicException;
use Hilos\Database\DatabaseException;
use Hilos\Database\Exception\View\Collection\ActionsClassException;
use Hilos\Database\Exception\View\Item\PropertyNotFoundException;
use Hilos\Database\Object\Item\File as ObjectFile;
use Hilos\Database\View\Item\DbItem;
use Hilos\Database\View\Item\File;
use Hilos\Files\HilosFiles;
use Hilos\HilosException;

/**
 * EventAttachment - Db item linking a message event to the registry file sent with it (HIL-144).
 *
 * The file itself - its name, its type, its bytes - is the files registry's; the attachment
 * reaches it through the `file` bridge. The two addresses are not stored anywhere: the original
 * and the feed's thumbnail are served by the files library at an address only the server can
 * spell, since the thumbnail's carries the signature of its declaration.
 *
 * @extends DbItem<ObjectEventAttachment>
 * @method __construct(ObjectEventAttachment $objectEventAttachment)
 *
 * @property-read ?int $id
 * @property-read int $eventId
 * @property-read int $fileId
 * @property-read ?EventMessage $eventMessage Message detail row for this attachment
 * @property-read ?File $file Registry row of the attached file, null once the registry lost it
 * @property-read string $url Address the original is served at
 * @property-read string $thumbUrl Address the feed's thumbnail is served at; a file the engine cannot draw comes back as the original
 */
final class EventAttachment extends DbItem
{
    public const string file = 'file';
    public const string url = 'url';
    public const string thumbUrl = 'thumbUrl';

    /**
     * Property getter (read-only access).
     *
     * @param string $name Property or bridge name
     * @return mixed Property value, the registry row, or an address of the file
     * @throws PropertyNotFoundException If property does not exist
     * @throws ActionsClassException If item actions class is invalid or not configured
     * @throws InvalidArgumentException When the thumbnail variant is not declared by the chat
     * @throws HilosException Whatever the inherited getter or the registry read raises
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            ObjectEventAttachment::id => $this->_object->id,
            ObjectEventAttachment::eventId => $this->_object->eventId,
            ObjectEventAttachment::fileId => $this->_object->fileId,
            ChatDbContext::eventMessage => Hilos::$db->eventMessages[$this->_object->eventId],
            self::file => Hilos::$db->files[$this->_object->fileId],
            self::url => HilosFiles::downloadPath($this->_object->fileId),
            self::thumbUrl => HilosFiles::downloadPath($this->_object->fileId, ChatAttachmentUploadTarget::THUMB_VARIANT),
            default => parent::__get($name),
        };
    }

    /**
     * Converts the attachment to an array, with what the feed draws of its file.
     *
     * The name and the type are the registry row's, read through the `file` bridge, and the two
     * addresses are built here; the feed's list reads its fields from this array
     * (BrowserContext reads a field the item does not answer from `toArray()` with its defaults),
     * so they stand in both forms. A file the registry lost answers null for its name and type.
     *
     * @param bool $withId Include ID fields
     * @param bool $idAsIndex Use ID as array index
     * @param bool $withBridges Include bridge data
     * @param bool $withCalculation Include calculated fields
     * @param bool $toFrontend Prepare a legacy frontend-safe entity payload
     * @return array<string, mixed> Attachment payload
     * @throws LogicException When the base array form raises it for a related collection whose class constants are not configured
     * @throws InvalidArgumentException When the base array form raises it for a related collection whose object type does not
     *     match it, or the thumbnail variant is not declared by the chat
     * @throws DatabaseException When the registry row is not loaded yet and loading it fails
     */
    public function toArray(
        bool $withId = true,
        bool $idAsIndex = true,
        bool $withBridges = false,
        bool $withCalculation = false,
        bool $toFrontend = false,
    ): array {
        $result = parent::toArray($withId, $idAsIndex, $withBridges, $withCalculation, $toFrontend);
        $file = Hilos::$db->files[$this->_object->fileId];
        $result[ObjectFile::filename] = $file?->filename;
        $result[ObjectFile::mimeType] = $file?->mimeType;
        $result[self::url] = HilosFiles::downloadPath($this->_object->fileId);
        $result[self::thumbUrl] = HilosFiles::downloadPath($this->_object->fileId, ChatAttachmentUploadTarget::THUMB_VARIANT);

        return $result;
    }
}

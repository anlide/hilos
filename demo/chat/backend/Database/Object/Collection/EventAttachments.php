<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Object\Collection;

use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\Entity\Collection\EventAttachments as EntityEventAttachments;
use Demo\Chat\Database\Object\Item\EventAttachment as ObjectEventAttachment;
use Hilos\Database\Object\Objects;

/**
 * EventAttachments - Object collection for published event attachments.
 *
 * @extends Objects<ObjectEventAttachment>
 * @method ObjectEventAttachment|null current()
 * @method ObjectEventAttachment|null first()
 * @method ObjectEventAttachment|null last()
 * @method ObjectEventAttachment|null get(int|string $key)
 * @method ObjectEventAttachment|null offsetGet(mixed $offset)
 */
final class EventAttachments extends Objects
{
    public const string OBJECT_CLASS = ObjectEventAttachment::class;
    public const string ENTITY_COLLECTION_CLASS = EntityEventAttachments::class;
    public const string COLLECTION_KEY = DbChatContext::eventAttachments;
}

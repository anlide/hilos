<?php

declare(strict_types=1);

namespace Demo\Chat\Database\Object\Collection;

use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\Entity\Collection\EventUserRegistrations as EntityEventUserRegistrations;
use Demo\Chat\Database\Object\Item\EventUserRegistration as ObjectEventUserRegistration;
use Hilos\Database\Object\Objects;

/**
 * EventUserRegistrations - Object collection for registration event details.
 *
 * @extends Objects<ObjectEventUserRegistration>
 * @method ObjectEventUserRegistration|null current()
 * @method ObjectEventUserRegistration|null first()
 * @method ObjectEventUserRegistration|null last()
 * @method ObjectEventUserRegistration|null get(int|string $key)
 * @method ObjectEventUserRegistration|null offsetGet(mixed $offset)
 */
final class EventUserRegistrations extends Objects
{
    public const string OBJECT_CLASS = ObjectEventUserRegistration::class;
    public const string ENTITY_COLLECTION_CLASS = EntityEventUserRegistrations::class;
    public const string COLLECTION_KEY = DbChatContext::eventUserRegistrations;
}

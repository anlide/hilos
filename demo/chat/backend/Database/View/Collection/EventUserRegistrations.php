<?php

declare(strict_types=1);

namespace Demo\Chat\Database\View\Collection;

use Demo\Chat\Database\Actions\Collection\EventUserRegistrationsActions;
use Demo\Chat\Database\Object\Collection\EventUserRegistrations as ObjectEventUserRegistrations;
use Demo\Chat\Database\View\Item\EventUserRegistration;
use Hilos\Database\View\Collection\DbCollection;

/**
 * EventUserRegistrations - Db collection of registration event detail items.
 *
 * Array access uses event_id, so eventUserRegistrations[$eventId] returns the
 * registration detail for that parent event when it exists.
 *
 * @extends DbCollection<EventUserRegistration, ObjectEventUserRegistrations>
 * @method ObjectEventUserRegistrations|null getObjectCollection()
 * @method EventUserRegistration|null current()
 * @method EventUserRegistration|null first()
 * @method EventUserRegistration|null last()
 * @method EventUserRegistration|null offsetGet(mixed $offset)
 * @property-read EventUserRegistrationsActions $actions Actions for write operations
 */
final class EventUserRegistrations extends DbCollection
{
    public const string DB_ITEM_CLASS = EventUserRegistration::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectEventUserRegistrations::class;
}

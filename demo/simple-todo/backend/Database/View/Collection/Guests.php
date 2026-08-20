<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Database\View\Collection;

use Demo\SimpleTodo\Database\Actions\Collection\GuestsActions;
use Demo\SimpleTodo\Database\Object\Collection\Guests as ObjectGuests;
use Demo\SimpleTodo\Database\View\Item\Guest;
use Hilos\Database\View\Collection\DbCollection;

/**
 * Guests - Db collection of Guest items.
 *
 * @extends DbCollection<Guest, ObjectGuests>
 * @method ObjectGuests|null getObjectCollection()
 * @method Guest|null current()
 * @method Guest|null first()
 * @method Guest|null last()
 * @method Guest|null offsetGet(mixed $offset)
 * @property-read GuestsActions $actions Actions for write operations
 */
final class Guests extends DbCollection
{
    public const string DB_ITEM_CLASS = Guest::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectGuests::class;
}

<?php

declare(strict_types=1);

namespace Hilos\Database\View\Collection;

use Hilos\Database\Actions\Collection\StatusWorkComponentsActions;
use Hilos\Database\Object\Collection\StatusWorkComponents as ObjectStatusWorkComponents;
use Hilos\Database\View\Item\StatusWorkComponent;

/**
 * Array access uses the persisted primary id.
 *
 * @extends DbCollection<StatusWorkComponent, ObjectStatusWorkComponents>
 * @method ObjectStatusWorkComponents|null getObjectCollection()
 * @method StatusWorkComponent|null first()
 * @method StatusWorkComponent|null last()
 * @method StatusWorkComponent offsetGet(mixed $offset)
 * @property-read StatusWorkComponentsActions $actions Storage writes
 */
class StatusWorkComponents extends DbCollection
{
    public const int MAX_PORTION_ROWS = 32;
    public const string DB_ITEM_CLASS = StatusWorkComponent::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectStatusWorkComponents::class;
}

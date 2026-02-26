<?php

namespace Demo\Chat\Database\View\Collection;

use Demo\Chat\Database\Actions\Collection\BotsActions;
use Demo\Chat\Database\Object\Collection\Bots as ObjectBots;
use Demo\Chat\Database\View\Item\Bot;
use Hilos\Database\View\Collection\DbCollection;

/**
 * Bots Db collection - collection of Bot items.
 *
 * @extends DbCollection<Bot, ObjectBots>
 * @method ObjectBots|null getObjectCollection()
 * @method Bot|null current()
 * @method Bot|null first()
 * @method Bot|null last()
 * @method Bot|null offsetGet(mixed $offset)
 * @property-read BotsActions $actions Collection actions (create); items use BotActions
 */
final class Bots extends DbCollection
{
    public const string DB_ITEM_CLASS = Bot::class;
    public const string OBJECT_COLLECTION_CLASS = ObjectBots::class;
}

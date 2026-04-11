<?php

declare(strict_types=1);

namespace Demo\Chat\Database\View\Collection;

use Demo\Chat\Database\Actions\Collection\BotsActions;
use Demo\Chat\Database\Object\Collection\Bots as ObjectBots;
use Demo\Chat\Database\View\Item\Bot;
use Demo\Chat\Hilos;
use Hilos\Database\Exception\View\CollectionNotManualException;
use Hilos\Database\Object\Exception\ObjectGetIdStringNotImplementedException;
use Hilos\Database\View\Collection\DbCollection;

/**
 * Bots - Db collection of Bot items.
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

    /**
     * View collection containing only bots with active=true from the app database.
     *
     * @return self Active bots
     * @throws CollectionNotManualException If Bots collection is not manual (required for add)
     * @throws ObjectGetIdStringNotImplementedException If Bot object does not implement getIdString
     */
    public static function fromActiveOnly(): self
    {
        $collection = self::initEmpty();
        foreach (Hilos::$db->bots as $bot) {
            if ($bot->active) {
                $collection->add($bot);
            }
        }

        return $collection;
    }
}

<?php

namespace Demo\Chat\Database\Object\Collection;

use Demo\Chat\Database\Entity\Collection\Bots as EntityBots;
use Demo\Chat\Database\DbChatContext;
use Demo\Chat\Database\Object\Item\Bot as ObjectBot;
use Hilos\Database\Object\Objects;

/**
 * Bots Object Collection
 *
 * @extends Objects<ObjectBot>
 * @method ObjectBot|null current()
 * @method ObjectBot|null first()
 * @method ObjectBot|null last()
 * @method ObjectBot|null get(int|string $key)
 * @method ObjectBot|null offsetGet(mixed $offset)
 */
final class Bots extends Objects
{
    public const string OBJECT_CLASS = ObjectBot::class;
    public const string ENTITY_COLLECTION_CLASS = EntityBots::class;
    public const string COLLECTION_KEY = DbChatContext::bots;
}

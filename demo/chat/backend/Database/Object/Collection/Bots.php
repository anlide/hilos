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
 */
final class Bots extends Objects
{
    public const string OBJECT_CLASS = ObjectBot::class;
    public const string ENTITY_COLLECTION_CLASS = EntityBots::class;
    public const string COLLECTION_KEY = DbChatContext::bots;
}

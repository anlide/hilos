<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\Bot\Actions;

use Demo\Chat\Database\Object\Item\Bot as ObjectBot;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\Bot\DTO\BotCreateActionDTO;
use Hilos\Core\Table\Actions\TableActions;
use Hilos\Core\Table\Mutation\TableMutationEntry;
use Hilos\Core\Table\Mutation\TableMutationType;

/**
 * Collection-level actions for the bots table.
 */
class BotsTableActions extends TableActions
{
    public function create(BotCreateActionDTO $dto): TableMutationEntry
    {
        $bot = ObjectBot::create();
        $bot->name = $dto->name;
        $bot->description = $dto->description;
        $bot->style = $dto->style;
        $bot->topics = $dto->topics;
        $bot->personality = $dto->personality;
        $bot->active = $dto->active;
        $bot->sync();

        $objectCollection = Hilos::$db->bots->getObjectCollection();
        $objectCollection[$bot->getIdString()] = $bot;

        return $this->mutation(TableMutationType::Created, $bot->id, $bot->toArray());
    }
}

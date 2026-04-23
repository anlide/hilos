<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\Bot\Actions;

use Demo\Chat\Database\Object\Item\Bot as ObjectBot;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\Bot\DTO\BotCreateActionDTO;
use Hilos\Core\Table\Actions\TableActions;
use Hilos\Core\Table\Mutation\TableMutationEntry;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\HilosException;

/**
 * BotsTableActions - Collection-level actions for the bots table (table layer).
 *
 * Delegates to db layer (BotsActions) for actual create.
 */
final class BotsTableActions extends TableActions
{
    /**
     * Creates a bot and returns mutation for broadcasting.
     * DB_SYNC broadcast is triggered automatically by Object_::sync().
     *
     * @param BotCreateActionDTO $dto Create payload (name, description, style, etc.)
     * @return TableMutationEntry Mutation entry for broadcast
     * @throws HilosException On db or validation error
     */
    public function create(BotCreateActionDTO $dto): TableMutationEntry
    {
        $data = [
            ObjectBot::name => $dto->name,
            ObjectBot::description => $dto->description,
            ObjectBot::style => $dto->style,
            ObjectBot::topics => $dto->topics,
            ObjectBot::personality => $dto->personality,
            ObjectBot::active => $dto->active,
        ];

        $dbBot = Hilos::$db->bots->actions->create($data);

        return $this->mutation(TableMutationType::Created, $dbBot->id, $this->definition->makeRow($dbBot->toArray(toFrontend: true)));
    }
}

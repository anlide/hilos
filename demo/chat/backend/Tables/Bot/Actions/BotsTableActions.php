<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\Bot\Actions;

use Demo\Chat\Hilos;
use Demo\Chat\Tables\Bot\DTO\BotCreateActionDTO;
use Hilos\Core\Table\Actions\TableActions;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
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
     * @return TableRowMutationDTO Row mutation DTO for broadcast
     * @throws HilosException On db or validation error
     */
    public function create(BotCreateActionDTO $dto): TableRowMutationDTO
    {
        $dbBot = Hilos::$db->bots->actions->create(
            name: $dto->name,
            description: $dto->description,
            style: $dto->style,
            topics: $dto->topics,
            personality: $dto->personality,
            active: $dto->active,
        );

        return $this->mutation(TableMutationType::Create, $dbBot->id, $this->definition->makeRow($dbBot->toArray(toFrontend: true)));
    }
}

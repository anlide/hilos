<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\Bot\Actions;

use Demo\Chat\Database\Object\Item\Bot as ObjectBot;
use Demo\Chat\Hilos;
use Demo\Chat\Tables\Bot\DTO\BotUpdateActionDTO;
use Hilos\Core\Table\Actions\TableItemActions;
use Hilos\Core\Table\Mutation\TableMutationEntry;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\HilosException;

/**
 * BotItemActions - Item-level actions for a single bot (table layer).
 *
 * Delegates to db layer (BotActions) for actual updates and deletes.
 */
final class BotItemActions extends TableItemActions
{
    /**
     * Updates bot and returns mutation for broadcasting.
     * DB_SYNC broadcast is triggered automatically by Object_::sync().
     *
     * @param BotUpdateActionDTO $dto Update payload (only non-null fields applied)
     * @return TableMutationEntry Mutation entry for broadcast
     * @throws HilosException On db or permission error
     */
    public function update(BotUpdateActionDTO $dto): TableMutationEntry
    {
        $data = array_filter([
            ObjectBot::name => $dto->name,
            ObjectBot::description => $dto->description,
            ObjectBot::style => $dto->style,
            ObjectBot::topics => $dto->topics,
            ObjectBot::personality => $dto->personality,
            ObjectBot::active => $dto->active,
        ], static fn($v) => $v !== null);

        $dbBot = Hilos::$db->bots[$this->rowKey];
        if ($data !== []) {
            $dbBot->actions->update($data);
        }

        return $this->mutation(TableMutationType::Updated, $this->definition->makeRow($dbBot->toArray(toFrontend: true)));
    }

    /**
     * Deletes bot and returns mutation for broadcasting.
     * DB_SYNC broadcast is triggered automatically by Object_::delete().
     *
     * @return TableMutationEntry Mutation entry for broadcast
     * @throws HilosException On db or permission error
     */
    public function delete(): TableMutationEntry
    {
        Hilos::$db->bots[$this->rowKey]->actions->delete();
        return $this->mutation(TableMutationType::Deleted);
    }
}

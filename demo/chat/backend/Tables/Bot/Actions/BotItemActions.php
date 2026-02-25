<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\Bot\Actions;

use Demo\Chat\Hilos;
use Demo\Chat\Tables\Bot\DTO\BotUpdateActionDTO;
use Hilos\Core\Table\Actions\TableItemActions;
use Hilos\Core\Table\Mutation\TableMutationEntry;
use Hilos\Core\Table\Mutation\TableMutationType;

/**
 * Item-level actions for a single bot.
 */
class BotItemActions extends TableItemActions
{
    public function update(BotUpdateActionDTO $dto): TableMutationEntry
    {
        $objectCollection = Hilos::$db->bots->getObjectCollection();
        $object = $objectCollection[(string) $this->itemId];

        if ($dto->name !== null) $object->name = $dto->name;
        if ($dto->description !== null) $object->description = $dto->description;
        if ($dto->style !== null) $object->style = $dto->style;
        if ($dto->topics !== null) $object->topics = $dto->topics;
        if ($dto->personality !== null) $object->personality = $dto->personality;
        if ($dto->active !== null) $object->active = $dto->active;
        $object->sync();

        return $this->mutation(TableMutationType::Updated, $object->toArray());
    }

    public function delete(): TableMutationEntry
    {
        $objectCollection = Hilos::$db->bots->getObjectCollection();
        $object = $objectCollection[(string) $this->itemId];
        $object->delete();
        unset($objectCollection[(string) $this->itemId]);

        return $this->mutation(TableMutationType::Deleted);
    }
}

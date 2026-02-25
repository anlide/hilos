<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\User\Actions;

use Demo\Chat\Hilos;
use Demo\Chat\Tables\User\DTO\UserUpdateActionDTO;
use Hilos\Core\Table\Actions\TableItemActions;
use Hilos\Core\Table\Mutation\TableMutationEntry;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\Utils\Helpers\TimeHelper;

/**
 * Item-level actions for a single user.
 */
class UserItemActions extends TableItemActions
{
    public function update(UserUpdateActionDTO $dto): TableMutationEntry
    {
        $objectCollection = Hilos::$db->users->getObjectCollection();
        $object = $objectCollection[(string) $this->itemId];

        if ($dto->name !== null) $object->name = $dto->name;
        $object->lastActivity = TimeHelper::getSqlDateTime();
        $object->sync();

        $dbUser = Hilos::$db->users[(string) $this->itemId];
        $row = $dbUser->toArray(toFrontend: true);

        return $this->mutation(TableMutationType::Updated, $row);
    }
}

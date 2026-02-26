<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\User\Actions;

use Demo\Chat\Hilos;
use Demo\Chat\Tables\User\DTO\UserUpdateActionDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Table\Actions\TableItemActions;
use Hilos\Core\Table\Mutation\TableMutationEntry;
use Hilos\Core\Table\Mutation\TableMutationType;

/**
 * Item-level actions for a single user (table layer).
 *
 * Delegates to db layer (UserActions) for actual updates.
 */
class UserItemActions extends TableItemActions
{
    /**
     * Updates user name and returns mutation for broadcasting.
     *
     * @param UserUpdateActionDTO $dto Update payload (name is required)
     *
     * @return TableMutationEntry
     *
     * @throws InvalidActionPayloadException If name is empty
     */
    public function update(UserUpdateActionDTO $dto): TableMutationEntry
    {
        if ($dto->name === '') {
            throw new InvalidActionPayloadException('User name is required');
        }

        Hilos::$db->users[$this->itemId]->actions->rename($dto->name);
        $dbUser = Hilos::$db->users[$this->itemId];
        return $this->mutation(TableMutationType::Updated, $dbUser->toArray(toFrontend: true));
    }
}

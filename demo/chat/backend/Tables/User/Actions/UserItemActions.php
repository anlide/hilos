<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\User\Actions;

use Demo\Chat\Hilos;
use Demo\Chat\Tables\User\DTO\UserUpdateActionDTO;
use Hilos\Core\Router\Exception\InvalidActionPayloadException;
use Hilos\Core\Table\Actions\TableItemActions;
use Hilos\Core\Table\Mutation\TableMutationEntry;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\HilosException;
use Hilos\Runtime\Exception\RtBaseException;

/**
 * UserItemActions - Item-level actions for a single user (table layer).
 *
 * Delegates to db layer (UserActions) for actual updates.
 */
final class UserItemActions extends TableItemActions
{
    /**
     * Updates user name and returns mutation for broadcasting.
     *
     * @param UserUpdateActionDTO $dto Update payload (name required)
     * @return TableMutationEntry Mutation entry for broadcast
     * @throws InvalidActionPayloadException If name is empty
     * @throws HilosException On db or permission error
     */
    public function update(UserUpdateActionDTO $dto): TableMutationEntry
    {
        try {
            $dbUser = Hilos::$db->users[$this->itemId];
            $dbUser->actions->rename($dto->name);
        } catch (RtBaseException $e) {
            throw new HilosException('Failed to update user: ' . $e->getMessage(), previous: $e);
        }
        return $this->mutation(TableMutationType::Updated, $dbUser->toArray(toFrontend: true));
    }
}

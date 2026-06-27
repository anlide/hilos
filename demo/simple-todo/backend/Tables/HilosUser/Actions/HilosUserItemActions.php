<?php

declare(strict_types=1);

namespace Demo\SimpleTodo\Tables\HilosUser\Actions;

use Demo\SimpleTodo\Hilos;
use Demo\SimpleTodo\Tables\HilosUser\DTO\HilosUserUpdateActionDTO;
use Demo\SimpleTodo\Tables\HilosUser\HilosUsersTable;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Table\Actions\TableItemActions;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\HilosException;

/**
 * Item actions for rows in the Hilos users table.
 *
 * @property HilosUsersTable $definition Hilos users table definition that builds row mutation payloads.
 */
final class HilosUserItemActions extends TableItemActions
{
    /**
     * Renames the selected user and returns the updated table mutation payload.
     *
     * @param HilosUserUpdateActionDTO $dto Rename payload (target row key + new name)
     * @return TableRowMutationDTO Row update mutation for the renamed user
     * @throws HilosException When validation prevents the user rename
     */
    public function update(HilosUserUpdateActionDTO $dto): TableRowMutationDTO
    {
        try {
            $dbUser = Hilos::$db->users[$this->rowKey];
            $dbUser->actions->rename($dto->name);
        } catch (ValidationException $e) {
            throw new HilosException('Failed to update user: ' . $e->getMessage(), previous: $e);
        }

        return $this->mutation(TableMutationType::Update, $this->definition->rowFromUser($dbUser));
    }
}

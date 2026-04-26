<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\AdminUser\Actions;

use Demo\Chat\Hilos;
use Demo\Chat\Tables\AdminUser\DTO\AdminUserUpdateActionDTO;
use Hilos\Core\Exception\ValidationException;
use Hilos\Core\Table\Actions\TableItemActions;
use Hilos\Core\Table\DTO\TableRowMutationDTO;
use Hilos\Core\Table\Mutation\TableMutationType;
use Hilos\HilosException;

/**
 * Item actions for rows in the admin users table.
 */
final class AdminUserItemActions extends TableItemActions
{
    /**
     * Renames the selected user and returns the updated table mutation payload.
     *
     * @throws HilosException When validation prevents the user rename
     */
    public function update(AdminUserUpdateActionDTO $dto): TableRowMutationDTO
    {
        try {
            $dbUser = Hilos::$db->users[$this->rowKey];
            $dbUser->actions->rename($dto->name);
        } catch (ValidationException $e) {
            throw new HilosException('Failed to update user: ' . $e->getMessage(), previous: $e);
        }

        return $this->mutation(TableMutationType::Update, $this->definition->makeRow($dbUser->toArray(toFrontend: true)));
    }
}

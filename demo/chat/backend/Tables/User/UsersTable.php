<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\User;

use Demo\Chat\Tables\User\Actions\UserItemActions;
use Hilos\Core\Table\Definition\TableDefinition;

/**
 * Users table definition (update only, no create/delete).
 */
class UsersTable extends TableDefinition
{
    protected function init(): void
    {
        $this->setItemActionsClass(UserItemActions::class);
    }
}

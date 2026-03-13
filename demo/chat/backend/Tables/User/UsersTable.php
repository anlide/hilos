<?php

declare(strict_types=1);

namespace Demo\Chat\Tables\User;

use Demo\Chat\Hilos;
use Demo\Chat\Tables\User\Actions\UserItemActions;
use Hilos\Core\Table\DataSource\EntityTableDataSource;
use Hilos\Core\Table\DataSource\TableDataSourceInterface;
use Hilos\Core\Table\Definition\TableDefinition;

/**
 * Users table definition (update only, no create/delete).
 *
 * Backed by Hilos::$db->users. Supports user update via item actions.
 */
class UsersTable extends TableDefinition
{
    /**
     * Provides entity data source for the users collection.
     *
     * @return TableDataSourceInterface Entity table data source for users
     */
    protected function createDataSource(): TableDataSourceInterface
    {
        return new EntityTableDataSource(Hilos::$db->users);
    }

    /**
     * Configures item-level actions (UserItemActions for update).
     */
    protected function init(): void
    {
        $this->setItemActionsClass(UserItemActions::class);
    }
}

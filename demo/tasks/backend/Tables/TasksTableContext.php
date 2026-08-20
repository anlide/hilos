<?php

declare(strict_types=1);

namespace Demo\Tasks\Tables;

use Demo\Tasks\Hilos;
use Demo\Tasks\Tables\HilosUser\HilosUsersTable;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Tables\Settings\HilosSettingsTable;

/**
 * TasksTableContext - App-specific table context ($table layer) for tasks.
 *
 * Registers the framework settings table (as-is) and the project's Hilos users
 * table activation; accessed via Hilos::$table->settings / Hilos::$table->hilosUsers.
 *
 * @property-read HilosUsersTable $hilosUsers
 * @property-read HilosSettingsTable $settings
 */
final class TasksTableContext extends TableContext
{
    public const string hilosUsers = 'hilosUsers';
    public const string settings = HilosSettingsTable::TABLE;

    /**
     * Registers tasks table definitions from the project topology registry.
     */
    public function configure(): void
    {
        foreach (Hilos::TABLES as $tableName => $tableClass) {
            $this->register($tableName, new $tableClass());
        }
    }
}

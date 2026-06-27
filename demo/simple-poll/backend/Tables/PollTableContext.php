<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Tables;

use Demo\SimplePoll\Hilos;
use Demo\SimplePoll\Tables\HilosUser\HilosUsersTable;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Tables\Settings\HilosSettingsTable;

/**
 * PollTableContext - App-specific table context ($table layer) for simple-poll.
 *
 * Registers the framework settings table (as-is) and the project's Hilos users
 * table activation; accessed via Hilos::$table->settings / Hilos::$table->hilosUsers.
 *
 * @property-read HilosUsersTable $hilosUsers
 * @property-read HilosSettingsTable $settings
 */
final class PollTableContext extends TableContext
{
    public const string hilosUsers = 'hilosUsers';
    public const string settings = HilosSettingsTable::TABLE;

    /**
     * Registers simple-poll table definitions from the project topology registry.
     */
    public function configure(): void
    {
        foreach (Hilos::TABLES as $tableName => $tableClass) {
            $this->register($tableName, new $tableClass());
        }
    }
}

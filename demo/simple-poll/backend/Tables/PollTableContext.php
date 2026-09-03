<?php

declare(strict_types=1);

namespace Demo\SimplePoll\Tables;

use Demo\SimplePoll\Hilos;
use Demo\SimplePoll\Tables\HilosUser\HilosUsersTable;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Tables\Logs\HilosLogKeysTable;
use Hilos\Tables\Logs\HilosLogRotationsTable;
use Hilos\Tables\Logs\HilosLogWorkersTable;
use Hilos\Tables\Settings\HilosSettingsTable;

/**
 * PollTableContext - App-specific table context ($table layer) for simple-poll.
 *
 * Registers the framework settings and log tables (as-is) and the project's Hilos
 * users table activation; accessed via Hilos::$table->settings /
 * Hilos::$table->hilosUsers.
 *
 * @property-read HilosLogKeysTable $hilosLogKeys
 * @property-read HilosLogRotationsTable $hilosLogRotations
 * @property-read HilosLogWorkersTable $hilosLogWorkers
 * @property-read HilosUsersTable $hilosUsers
 * @property-read HilosSettingsTable $settings
 */
final class PollTableContext extends TableContext
{
    public const string hilosLogKeys = HilosLogKeysTable::TABLE;
    public const string hilosLogRotations = HilosLogRotationsTable::TABLE;
    public const string hilosLogWorkers = HilosLogWorkersTable::TABLE;
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

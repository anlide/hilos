<?php

declare(strict_types=1);

namespace Demo\OnlineTesting\Tables;

use Demo\OnlineTesting\Hilos;
use Demo\OnlineTesting\Tables\HilosUser\HilosUsersTable;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Tables\Logs\HilosLogKeysTable;
use Hilos\Tables\Logs\HilosLogRotationsTable;
use Hilos\Tables\Logs\HilosLogWorkersTable;
use Hilos\Tables\ProtectedMode\HilosVerifierCircleTable;
use Hilos\Tables\Settings\HilosSettingsTable;

/**
 * OnlineTestingTableContext - table bindings for this demo's admin sections.
 *
 * @property-read HilosSettingsTable $settings
 * @property-read HilosUsersTable $hilosUsers
 * @property-read HilosVerifierCircleTable $hilosVerifierCircle
 * @property-read HilosLogKeysTable $hilosLogKeys
 * @property-read HilosLogRotationsTable $hilosLogRotations
 * @property-read HilosLogWorkersTable $hilosLogWorkers
 */
final class OnlineTestingTableContext extends TableContext
{
    public const string settings = HilosSettingsTable::TABLE;
    public const string hilosUsers = 'hilosUsers';
    public const string hilosVerifierCircle = HilosVerifierCircleTable::TABLE;
    public const string hilosLogKeys = HilosLogKeysTable::TABLE;
    public const string hilosLogRotations = HilosLogRotationsTable::TABLE;
    public const string hilosLogWorkers = HilosLogWorkersTable::TABLE;

    /**
     * Registers table definitions from the project topology registry.
     */
    public function configure(): void
    {
        foreach (Hilos::TABLES as $tableName => $tableClass) {
            $this->register($tableName, new $tableClass());
        }
    }
}

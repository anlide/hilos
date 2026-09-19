<?php

declare(strict_types=1);

namespace Demo\Polls\Tables;

use Demo\Polls\Hilos;
use Demo\Polls\Tables\HilosUser\HilosUsersTable;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Tables\Logs\HilosLogKeysTable;
use Hilos\Tables\Logs\HilosLogRotationsTable;
use Hilos\Tables\Logs\HilosLogWorkersTable;
use Hilos\Tables\Settings\HilosSettingsTable;
use Hilos\Tables\Security\HilosSecurityOAuthProviderFieldsTable;
use Hilos\Tables\Security\HilosSecurityOAuthProvidersTable;
use Hilos\Tables\Security\HilosSecurityOAuthRedirectTable;
use Hilos\Tables\Security\HilosSecuritySignInMethodsTable;

/**
 * PollsTableContext - App-specific table context ($table layer) for polls.
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
 * @property-read HilosSecurityOAuthProvidersTable $hilosSecurityOauthProviders
 * @property-read HilosSecurityOAuthProviderFieldsTable $hilosSecurityOauthProviderFields
 * @property-read HilosSecurityOAuthRedirectTable $hilosSecurityOauthRedirect
 * @property-read HilosSecuritySignInMethodsTable $hilosSecuritySignInMethods
 */
final class PollsTableContext extends TableContext
{
    public const string hilosLogKeys = HilosLogKeysTable::TABLE;
    public const string hilosLogRotations = HilosLogRotationsTable::TABLE;
    public const string hilosLogWorkers = HilosLogWorkersTable::TABLE;
    public const string hilosUsers = 'hilosUsers';
    public const string settings = HilosSettingsTable::TABLE;
    public const string hilosSecurityOauthProviders = HilosSecurityOAuthProvidersTable::TABLE;
    public const string hilosSecurityOauthProviderFields = HilosSecurityOAuthProviderFieldsTable::TABLE;
    public const string hilosSecurityOauthRedirect = HilosSecurityOAuthRedirectTable::TABLE;
    public const string hilosSecuritySignInMethods = HilosSecuritySignInMethodsTable::TABLE;

    /**
     * Registers polls table definitions from the project topology registry.
     */
    public function configure(): void
    {
        foreach (Hilos::TABLES as $tableName => $tableClass) {
            $this->register($tableName, new $tableClass());
        }
    }
}

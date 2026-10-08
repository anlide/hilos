<?php

declare(strict_types=1);

namespace Demo\Polls\Tables;

use Demo\Polls\Tables\HilosLegal\HilosLegalAcceptancesTable;
use Hilos\Tables\Legal\HilosLegalDocumentsTable;
use Hilos\Tables\Legal\HilosLegalChecksTable;
use Hilos\Tables\Legal\HilosLegalRevisionsTable;
use Hilos\Tables\Legal\HilosLegalSettingsTable;

use Demo\Polls\Hilos;
use Demo\Polls\Tables\HilosUser\HilosUsersTable;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Tables\Logs\HilosLogKeysTable;
use Hilos\Tables\Logs\HilosLogRotationsTable;
use Hilos\Tables\Daemon\HilosDaemonCronTable;
use Hilos\Tables\Logs\HilosLogWorkersTable;
use Hilos\Tables\ProtectedMode\HilosVerifierCircleTable;
use Hilos\Tables\Settings\HilosSettingsTable;
use Hilos\Tables\Security\HilosSecurityOAuthProviderFieldsTable;
use Hilos\Tables\Security\HilosSecurityOAuthProvidersTable;
use Hilos\Tables\Security\HilosSecurityOAuthRedirectTable;
use Hilos\Tables\Security\HilosSecuritySignInMethodsTable;
use Hilos\Tables\Security\HilosSecurityTwoFactorTable;
use Hilos\Tables\Security\HilosSecurityStepUpTable;
use Hilos\Tables\Security\HilosSecurityImpersonationTable;

/**
 * PollsTableContext - App-specific table context ($table layer) for polls.
 *
 * Registers the framework settings and log tables (as-is) and the project's Hilos
 * users table activation; accessed via Hilos::$table->settings /
 * Hilos::$table->hilosUsers. Also binds the maintenance section's verifier circle table.
 *
 * @property-read HilosLogKeysTable $hilosLogKeys
 * @property-read HilosLogRotationsTable $hilosLogRotations
 * @property-read HilosLogWorkersTable $hilosLogWorkers
 * @property-read HilosDaemonCronTable $hilosDaemonCron
 * @property-read HilosUsersTable $hilosUsers
 * @property-read HilosVerifierCircleTable $hilosVerifierCircle
 * @property-read HilosSettingsTable $settings
 * @property-read HilosSecurityOAuthProvidersTable $hilosSecurityOauthProviders
 * @property-read HilosSecurityOAuthProviderFieldsTable $hilosSecurityOauthProviderFields
 * @property-read HilosSecurityOAuthRedirectTable $hilosSecurityOauthRedirect
 * @property-read HilosSecuritySignInMethodsTable $hilosSecuritySignInMethods
 * @property-read HilosSecurityTwoFactorTable $hilosSecurityTwoFactor
 * @property-read HilosLegalDocumentsTable $hilosLegalDocuments
 * @property-read HilosLegalChecksTable $hilosLegalChecks
 * @property-read HilosLegalRevisionsTable $hilosLegalRevisions
 * @property-read HilosLegalAcceptancesTable $hilosLegalAcceptances
 * @property-read HilosLegalSettingsTable $hilosLegalSettings
 * @property-read HilosSecurityStepUpTable $hilosSecurityStepUp
 * @property-read HilosSecurityImpersonationTable $hilosSecurityImpersonation
 */
final class PollsTableContext extends TableContext
{
    public const string hilosLogKeys = HilosLogKeysTable::TABLE;
    public const string hilosLogRotations = HilosLogRotationsTable::TABLE;
    public const string hilosLogWorkers = HilosLogWorkersTable::TABLE;
    public const string hilosDaemonCron = HilosDaemonCronTable::TABLE;
    public const string hilosUsers = 'hilosUsers';
    public const string hilosVerifierCircle = HilosVerifierCircleTable::TABLE;
    public const string settings = HilosSettingsTable::TABLE;
    public const string hilosSecurityOauthProviders = HilosSecurityOAuthProvidersTable::TABLE;
    public const string hilosSecurityOauthProviderFields = HilosSecurityOAuthProviderFieldsTable::TABLE;
    public const string hilosSecurityOauthRedirect = HilosSecurityOAuthRedirectTable::TABLE;
    public const string hilosSecuritySignInMethods = HilosSecuritySignInMethodsTable::TABLE;
    public const string hilosSecurityTwoFactor = HilosSecurityTwoFactorTable::TABLE;
    public const string hilosLegalDocuments = HilosLegalDocumentsTable::TABLE;
    public const string hilosLegalChecks = HilosLegalChecksTable::TABLE;
    public const string hilosLegalRevisions = HilosLegalRevisionsTable::TABLE;
    public const string hilosLegalAcceptances = HilosLegalAcceptancesTable::TABLE;
    public const string hilosLegalSettings = HilosLegalSettingsTable::TABLE;
    public const string hilosSecurityStepUp = HilosSecurityStepUpTable::TABLE;
    public const string hilosSecurityImpersonation = HilosSecurityImpersonationTable::TABLE;

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

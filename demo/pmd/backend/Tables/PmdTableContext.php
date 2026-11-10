<?php

declare(strict_types=1);

namespace Demo\Pmd\Tables;

use Demo\Pmd\Hilos;
use Demo\Pmd\Tables\HilosUser\HilosUsersTable;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Tables\Analytics\HilosAnalyticsActionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionListTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsPeopleTable;
use Hilos\Tables\Appearance\HilosAppearanceSettingsTable;
use Hilos\Tables\Communications\HilosCommunicationsChannelFieldsTable;
use Hilos\Tables\Communications\HilosCommunicationsChannelsTable;
use Hilos\Tables\Communications\HilosNotificationDeliveriesTable;
use Hilos\Tables\Legal\HilosPrivacyHistoryTable;
use Hilos\Tables\Legal\HilosTermsHistoryTable;
use Hilos\Tables\Security\HilosSecurityImpersonationTable;
use Hilos\Tables\Security\HilosSecurityOAuthProviderFieldsTable;
use Hilos\Tables\Security\HilosSecurityOAuthProvidersTable;
use Hilos\Tables\Security\HilosSecurityOAuthRedirectTable;
use Hilos\Tables\Security\HilosSecuritySignInMethodsTable;
use Hilos\Tables\Security\HilosSecurityStepUpTable;
use Hilos\Tables\Security\HilosSecurityTwoFactorTable;
use Hilos\Tables\Settings\HilosSettingsTable;
use Hilos\Tables\Users\HilosMergeCandidatesTable;

/**
 * PMD server table bindings.
 *
 * @property-read HilosSettingsTable $settings Registered table
 * @property-read HilosAppearanceSettingsTable $hilosAppearanceSettings Registered table
 * @property-read HilosUsersTable $hilosUsers Registered table
 * @property-read HilosTermsHistoryTable $hilosTermsHistory Registered table
 * @property-read HilosPrivacyHistoryTable $hilosPrivacyHistory Registered table
 * @property-read HilosAnalyticsActionsTable $hilosAnalyticsActions Registered table
 * @property-read HilosAnalyticsBrowserSessionsTable $hilosAnalyticsBrowserSessions Registered table
 * @property-read HilosAnalyticsBrowserSessionListTable $hilosAnalyticsBrowserSessionList Registered table
 * @property-read HilosAnalyticsPeopleTable $hilosAnalyticsPeople Registered table
 * @property-read HilosCommunicationsChannelsTable $hilosCommunicationsChannels Registered table
 * @property-read HilosCommunicationsChannelFieldsTable $hilosCommunicationsChannelFields Registered table
 * @property-read HilosNotificationDeliveriesTable $hilosNotificationDeliveries Registered table
 * @property-read HilosSecurityOAuthProvidersTable $hilosSecurityOauthProviders Registered table
 * @property-read HilosSecurityOAuthProviderFieldsTable $hilosSecurityOauthProviderFields Registered table
 * @property-read HilosSecurityOAuthRedirectTable $hilosSecurityOauthRedirect Registered table
 * @property-read HilosSecuritySignInMethodsTable $hilosSecuritySignInMethods Registered table
 * @property-read HilosSecurityTwoFactorTable $hilosSecurityTwoFactor Registered table
 * @property-read HilosSecurityStepUpTable $hilosSecurityStepUp Registered table
 * @property-read HilosSecurityImpersonationTable $hilosSecurityImpersonation Registered table
 * @property-read HilosMergeCandidatesTable $hilosMergeCandidates Registered table
 */
final class PmdTableContext extends TableContext
{
    public const string settings = HilosSettingsTable::TABLE;
    public const string hilosAppearanceSettings = HilosAppearanceSettingsTable::TABLE;
    public const string hilosUsers = 'hilosUsers';
    public const string hilosTermsHistory = HilosTermsHistoryTable::TABLE;
    public const string hilosPrivacyHistory = HilosPrivacyHistoryTable::TABLE;
    public const string hilosAnalyticsActions = HilosAnalyticsActionsTable::TABLE;
    public const string hilosAnalyticsBrowserSessions = HilosAnalyticsBrowserSessionsTable::TABLE;
    public const string hilosAnalyticsBrowserSessionList = HilosAnalyticsBrowserSessionListTable::TABLE;
    public const string hilosAnalyticsPeople = HilosAnalyticsPeopleTable::TABLE;
    public const string hilosCommunicationsChannels = HilosCommunicationsChannelsTable::TABLE;
    public const string hilosCommunicationsChannelFields = HilosCommunicationsChannelFieldsTable::TABLE;
    public const string hilosNotificationDeliveries = HilosNotificationDeliveriesTable::TABLE;
    public const string hilosSecurityOauthProviders = HilosSecurityOAuthProvidersTable::TABLE;
    public const string hilosSecurityOauthProviderFields = HilosSecurityOAuthProviderFieldsTable::TABLE;
    public const string hilosSecurityOauthRedirect = HilosSecurityOAuthRedirectTable::TABLE;
    public const string hilosSecuritySignInMethods = HilosSecuritySignInMethodsTable::TABLE;
    public const string hilosSecurityTwoFactor = HilosSecurityTwoFactorTable::TABLE;
    public const string hilosSecurityStepUp = HilosSecurityStepUpTable::TABLE;
    public const string hilosSecurityImpersonation = HilosSecurityImpersonationTable::TABLE;
    public const string hilosMergeCandidates = HilosMergeCandidatesTable::TABLE;

    /** Registers table definitions declared by the PMD facade. */
    public function configure(): void
    {
        foreach (Hilos::TABLES as $tableName => $tableClass) {
            $this->register($tableName, new $tableClass());
        }
    }
}

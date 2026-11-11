<?php

declare(strict_types=1);

namespace Demo\ConcertTickets;

use Demo\ConcertTickets\Agents\ConcertTicketsAgent;
use Demo\ConcertTickets\Agents\Hilos\DataExportAgent;
use Demo\ConcertTickets\Agents\Hilos\DemoHilosAgent;
use Demo\ConcertTickets\Agents\Hilos\DemoHilosAnalyticsAgent;
use Demo\ConcertTickets\Agents\Hilos\DemoHilosChangeLogAgent;
use Demo\ConcertTickets\Agents\Hilos\DemoHilosDaemonAgent;
use Demo\ConcertTickets\Agents\Hilos\DemoHilosLogsAgent;
use Demo\ConcertTickets\Agents\Hilos\FilesLibraryAgent;
use Demo\ConcertTickets\Agents\Hilos\NotificationsLibraryAgent;
use Demo\ConcertTickets\Agents\Hilos\SessionsLibraryAgent;
use Demo\ConcertTickets\Agents\Hilos\UserAgent;
use Demo\ConcertTickets\Agents\Hilos\UsersLibraryAgent;
use Demo\ConcertTickets\Agents\OAuthAgent;
use Demo\ConcertTickets\Auth\ConcertTicketsAuthMethodDirectory;
use Demo\ConcertTickets\Auth\ConcertTicketsCodeChannelRegistry;
use Demo\ConcertTickets\Auth\ConcertTicketsOAuthProviderDirectory;
use Demo\ConcertTickets\Browser\ConcertTicketsBrowserContext;
use Demo\ConcertTickets\Browser\ConcertTicketsBrowserRef;
use Demo\ConcertTickets\Browser\Data\SelfConnectionBrowserData;
use Demo\ConcertTickets\Browser\List\ProfileSessionsBrowserList;
use Demo\ConcertTickets\Core\Agent\Daemon\ConcertTicketsAgentDaemon;
use Demo\ConcertTickets\Core\Agent\Daemon\Hilos\DemoHilosAgentDaemon;
use Demo\ConcertTickets\Core\Agent\Daemon\Hilos\DemoHilosAnalyticsAgentDaemon;
use Demo\ConcertTickets\Core\Agent\Daemon\Hilos\DemoHilosChangeLogAgentDaemon;
use Demo\ConcertTickets\Core\Agent\Daemon\Hilos\DemoHilosDaemonAgentDaemon;
use Demo\ConcertTickets\Core\Agent\Daemon\Hilos\DemoHilosLogsAgentDaemon;
use Demo\ConcertTickets\Core\Agent\Daemon\Hilos\FilesLibraryAgentDaemon;
use Demo\ConcertTickets\Core\Agent\Daemon\Hilos\NotificationsLibraryAgentDaemon;
use Demo\ConcertTickets\Core\Agent\Daemon\Hilos\SessionsLibraryAgentDaemon;
use Demo\ConcertTickets\Core\Agent\Daemon\Hilos\UserAgentDaemon;
use Demo\ConcertTickets\Core\Agent\Daemon\Hilos\UsersLibraryAgentDaemon;
use Demo\ConcertTickets\Core\Agent\Daemon\OAuthAgentDaemon;
use Demo\ConcertTickets\Database\ConcertTicketsDbContext;
use Demo\ConcertTickets\Database\Pages\PageCatalog;
use Demo\ConcertTickets\Database\Settings\ConcertTicketsSettingsCatalog;
use Demo\ConcertTickets\Environment\ConcertTicketsEnvCatalog;
use Demo\ConcertTickets\Fs\ConcertTicketsFsContext;
use Demo\ConcertTickets\Groups\Hilos\NotificationsGroup;
use Demo\ConcertTickets\Legal\ConcertTicketsLegalCatalog;
use Demo\ConcertTickets\Notification\ConcertTicketsDeliveryChannelRegistry;
use Demo\ConcertTickets\Pages\Hilos\AboutPage;
use Demo\ConcertTickets\Pages\Hilos\ChangeLog\ChangeLogDashboardPage;
use Demo\ConcertTickets\Pages\Hilos\ChangeLog\ChangeLogFeedPage;
use Demo\ConcertTickets\Pages\Hilos\ChangeLog\ChangeLogNoisyPage;
use Demo\ConcertTickets\Pages\Hilos\ChangeLog\ChangeLogTableColumnsPage;
use Demo\ConcertTickets\Pages\Hilos\ChangeLog\ChangeLogTableHistoryPage;
use Demo\ConcertTickets\Pages\Hilos\ChangeLog\ChangeLogTablePage;
use Demo\ConcertTickets\Pages\Hilos\ChangeLog\ChangeLogTablesPage;
use Demo\ConcertTickets\Pages\Hilos\ChangeLog\ChangeLogTableTriggersPage;
use Demo\ConcertTickets\Pages\Hilos\Communications\CommunicationsDeliveriesPage;
use Demo\ConcertTickets\Pages\Hilos\DashboardPage;
use Demo\ConcertTickets\Pages\Hilos\AnalyticsPage;
use Demo\ConcertTickets\Pages\Hilos\Analytics\BrowserSessionsPage;
use Demo\ConcertTickets\Pages\Hilos\Analytics\BrowserSessionPage;
use Demo\ConcertTickets\Pages\Hilos\Analytics\PeoplePage;
use Demo\ConcertTickets\Pages\Hilos\Analytics\PersonPage;
use Demo\ConcertTickets\Pages\Hilos\Daemon\DaemonAgentsPage;
use Demo\ConcertTickets\Pages\Hilos\Daemon\DaemonCronPage;
use Demo\ConcertTickets\Pages\Hilos\Daemon\DaemonEnvMismatchPage;
use Demo\ConcertTickets\Pages\Hilos\Daemon\DaemonEnvOrphansPage;
use Demo\ConcertTickets\Pages\Hilos\Daemon\DaemonEnvPage;
use Demo\ConcertTickets\Pages\Hilos\Daemon\DaemonHttpServerPage;
use Demo\ConcertTickets\Pages\Hilos\Daemon\DaemonNodePage;
use Demo\ConcertTickets\Pages\Hilos\Daemon\DaemonPage;
use Demo\ConcertTickets\Pages\Hilos\Daemon\DaemonPlacementPage;
use Demo\ConcertTickets\Pages\Hilos\Daemon\DaemonWebsocketsPage;
use Demo\ConcertTickets\Pages\Hilos\Daemon\DaemonWorkersPage;
use Demo\ConcertTickets\Pages\Hilos\LicensePage;
use Demo\ConcertTickets\Pages\Hilos\LicenseDependenciesPage;
use Demo\ConcertTickets\Pages\Hilos\Logs\LogsKeysPage;
use Demo\ConcertTickets\Pages\Hilos\Logs\LogsOverviewPage;
use Demo\ConcertTickets\Pages\Hilos\Logs\LogsRotationsPage;
use Demo\ConcertTickets\Pages\Hilos\Logs\LogsSettingsPage;
use Demo\ConcertTickets\Pages\Hilos\Logs\LogsViewPage;
use Demo\ConcertTickets\Pages\Hilos\Logs\LogsWorkersPage;
use Demo\ConcertTickets\Pages\Hilos\PrivacyPage;
use Demo\ConcertTickets\Pages\Hilos\PrivacyHistoryPage;
use Demo\ConcertTickets\Pages\Hilos\ProfileAgreementsPage;
use Demo\ConcertTickets\Pages\Hilos\ProfileDataPage;
use Demo\ConcertTickets\Pages\Hilos\ProfileNotificationsPage;
use Demo\ConcertTickets\Pages\Hilos\ProfilePage;
use Demo\ConcertTickets\Pages\Hilos\ProfileSecurityPage;
use Demo\ConcertTickets\Pages\Hilos\ProfileSessionsPage;
use Demo\ConcertTickets\Pages\Hilos\ProfileSignInPage;
use Demo\ConcertTickets\Pages\Hilos\AppearancePage;
use Demo\ConcertTickets\Pages\Hilos\SettingsPage;
use Demo\ConcertTickets\Pages\Hilos\TermsPage;
use Demo\ConcertTickets\Pages\Hilos\TermsHistoryPage;
use Demo\ConcertTickets\Pages\Hilos\Users\UserPage;
use Demo\ConcertTickets\Pages\Hilos\Users\UsersPage;
use Demo\ConcertTickets\Pages\MainPage;
use Demo\ConcertTickets\Runtime\View\Context\ConcertTicketsRtContext;
use Demo\ConcertTickets\Tables\HilosUser\HilosUsersTable;
use Demo\ConcertTickets\Tables\ConcertTicketsTableContext;
use Hilos\Auth\Code\AuthCodeAgent;
use Hilos\Auth\Code\AuthCodeAgentDaemon;
use Hilos\Core\Agent\AgentRegistry;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Agent\Daemon\DaemonCollectorAgentDaemon;
use Hilos\Core\Agent\Daemon\DaemonNodeAgentDaemon;
use Hilos\Core\Agent\Hilos\DaemonCollectorAgent;
use Hilos\Core\Agent\Hilos\DaemonNodeAgent;
use Hilos\Core\Browser\Config\BrowserParamKey;
use Hilos\Core\Browser\Config\BrowserRuntimeParam;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Analytics\AnalyticsJournalAgent;
use Hilos\Core\Analytics\AnalyticsJournalAgentDaemon;
use Hilos\Core\Analytics\AnalyticsWriterAgent;
use Hilos\Core\Analytics\AnalyticsWriterAgentDaemon;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Table\Context\TableContext;
use Hilos\DataExport\DataExportAgentDaemon;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseGuarantee;
use Hilos\Database\Settings\Library\SettingsLibraryAgent;
use Hilos\Database\Settings\Library\SettingsLibraryAgentDaemon;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Environment\EnvAccessor;
use Hilos\Files\Image\ImagesAgent;
use Hilos\Files\Image\ImagesAgentDaemon;
use Hilos\Files\Upload\UploadsAgent;
use Hilos\Files\Upload\UploadsAgentDaemon;
use Hilos\Fs\Context\FsContext;
use Hilos\Hilos as HilosFacade;
use Hilos\Log\LogAggregatorAgent;
use Hilos\Log\LogAggregatorAgentDaemon;
use Hilos\Log\LogCarrierAgent;
use Hilos\Log\LogCarrierAgentDaemon;
use Hilos\Log\LogStoreAgent;
use Hilos\Log\LogStoreAgentDaemon;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Mail\Delivery\MailDeliveryChannelAgentDaemon;
use Hilos\Moderation\Library\ModerationRulesLibraryAgent;
use Hilos\Moderation\Library\ModerationRulesLibraryAgentDaemon;
use Hilos\Moderation\ModerationAgent;
use Hilos\Moderation\ModerationAgentDaemon;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Sms\Delivery\SmsDeliveryChannelAgentDaemon;
use Hilos\Sms\Delivery\SmsRawSendAgent;
use Hilos\Tables\Analytics\HilosAnalyticsActionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionListTable;
use Hilos\Tables\Analytics\HilosAnalyticsBrowserSessionsTable;
use Hilos\Tables\Analytics\HilosAnalyticsPeopleTable;
use Hilos\Tables\ChangeLog\HilosChangeLogFeedTable;
use Hilos\Tables\ChangeLog\HilosChangeLogHistoryTable;
use Hilos\Tables\ChangeLog\HilosChangeLogNoisyColumnsTable;
use Hilos\Tables\ChangeLog\HilosChangeLogTableColumnsTable;
use Hilos\Tables\ChangeLog\HilosChangeLogTablesTable;
use Hilos\Tables\ChangeLog\HilosChangeLogTableTriggersTable;
use Hilos\Tables\Daemon\HilosDaemonAgentsTable;
use Hilos\Tables\Daemon\HilosDaemonCronTable;
use Hilos\Tables\Daemon\HilosDaemonEnvKeysTable;
use Hilos\Tables\Daemon\HilosDaemonEnvMismatchTable;
use Hilos\Tables\Daemon\HilosDaemonEnvOrphansTable;
use Hilos\Tables\Daemon\HilosDaemonHttpRoutesTable;
use Hilos\Tables\Daemon\HilosDaemonPlacementTable;
use Hilos\Tables\Daemon\HilosDaemonWorkersTable;
use Hilos\Tables\Legal\HilosPrivacyHistoryTable;
use Hilos\Tables\Legal\HilosTermsHistoryTable;
use Hilos\Tables\Logs\HilosLogKeysTable;
use Hilos\Tables\Logs\HilosLogNodesTable;
use Hilos\Tables\Logs\HilosLogRotationsTable;
use Hilos\Tables\Logs\HilosLogWorkersTable;
use Hilos\Tables\Settings\HilosSettingsTable;
use Hilos\Tables\Appearance\HilosAppearanceSettingsTable;
use Hilos\Tables\Communications\HilosNotificationDeliveriesTable;
use Hilos\Tables\Users\HilosUserDetailBrowserTable;
use Hilos\Tables\Users\HilosUserPhotoBrowserTable;
use Hilos\Pages\Profile\HilosProfileIdentitiesBrowserList;

/**
 * Hilos - Main app facade for data access.
 *
 * Sign-in by password, passkey, phone code and GitHub; account pages, settings, Analytics,
 * email notifications, files and profile photos under moderation, and the Daemon, Logs and
 * Change Log admin sections.
 *
 * Usage:
 * - Hilos::$env[EnvConstants::HTTP_STATUS_HOST]->string()
 * - Hilos::$db->users
 * - Hilos::$rt->connections
 *
 * @property-read ConcertTicketsDbContext $db Database context (narrows parent's DbContext for IDE)
 * @property-read EnvAccessor $env Environment accessor (narrows parent's EnvAccessor for IDE)
 * @property-read SettingsAccessor $setting Settings accessor (narrows parent's SettingsAccessor for IDE)
 * @property-read ConcertTicketsRtContext $rt Runtime context (narrows parent's RtContext for IDE)
 * @property-read ConcertTicketsTableContext $table Table context (narrows parent's TableContext for IDE)
 * @property-read ConcertTicketsBrowserContext $browser Browser context (narrows parent's BrowserContext for IDE)
 */
final class Hilos extends HilosFacade
{
    public const array PAGES = [
        MainPage::PAGE => MainPage::class,
        DashboardPage::PAGE => DashboardPage::class,
        AnalyticsPage::PAGE => AnalyticsPage::class,
        BrowserSessionsPage::PAGE => BrowserSessionsPage::class,
        BrowserSessionPage::PAGE => BrowserSessionPage::class,
        PeoplePage::PAGE => PeoplePage::class,
        PersonPage::PAGE => PersonPage::class,
        SettingsPage::PAGE => SettingsPage::class,
        AppearancePage::PAGE => AppearancePage::class,
        ChangeLogDashboardPage::PAGE => ChangeLogDashboardPage::class,
        ChangeLogFeedPage::PAGE => ChangeLogFeedPage::class,
        ChangeLogNoisyPage::PAGE => ChangeLogNoisyPage::class,
        ChangeLogTablesPage::PAGE => ChangeLogTablesPage::class,
        ChangeLogTablePage::PAGE => ChangeLogTablePage::class,
        ChangeLogTableColumnsPage::PAGE => ChangeLogTableColumnsPage::class,
        ChangeLogTableTriggersPage::PAGE => ChangeLogTableTriggersPage::class,
        ChangeLogTableHistoryPage::PAGE => ChangeLogTableHistoryPage::class,
        DaemonPage::PAGE => DaemonPage::class,
        DaemonPlacementPage::PAGE => DaemonPlacementPage::class,
        DaemonNodePage::PAGE => DaemonNodePage::class,
        DaemonWorkersPage::PAGE => DaemonWorkersPage::class,
        DaemonAgentsPage::PAGE => DaemonAgentsPage::class,
        DaemonCronPage::PAGE => DaemonCronPage::class,
        DaemonWebsocketsPage::PAGE => DaemonWebsocketsPage::class,
        DaemonHttpServerPage::PAGE => DaemonHttpServerPage::class,
        DaemonEnvPage::PAGE => DaemonEnvPage::class,
        DaemonEnvOrphansPage::PAGE => DaemonEnvOrphansPage::class,
        DaemonEnvMismatchPage::PAGE => DaemonEnvMismatchPage::class,
        LogsOverviewPage::PAGE => LogsOverviewPage::class,
        LogsKeysPage::PAGE => LogsKeysPage::class,
        LogsWorkersPage::PAGE => LogsWorkersPage::class,
        LogsRotationsPage::PAGE => LogsRotationsPage::class,
        LogsViewPage::PAGE => LogsViewPage::class,
        LogsSettingsPage::PAGE => LogsSettingsPage::class,
        CommunicationsDeliveriesPage::PAGE => CommunicationsDeliveriesPage::class,
        ProfileNotificationsPage::PAGE => ProfileNotificationsPage::class,
        ProfilePage::PAGE => ProfilePage::class,
        ProfileSignInPage::PAGE => ProfileSignInPage::class,
        ProfileSessionsPage::PAGE => ProfileSessionsPage::class,
        ProfileSecurityPage::PAGE => ProfileSecurityPage::class,
        ProfileAgreementsPage::PAGE => ProfileAgreementsPage::class,
        ProfileDataPage::PAGE => ProfileDataPage::class,
        UsersPage::PAGE => UsersPage::class,
        UserPage::PAGE => UserPage::class,
        AboutPage::PAGE => AboutPage::class,
        TermsPage::PAGE => TermsPage::class,
        PrivacyPage::PAGE => PrivacyPage::class,
        TermsHistoryPage::PAGE => TermsHistoryPage::class,
        PrivacyHistoryPage::PAGE => PrivacyHistoryPage::class,
        LicensePage::PAGE => LicensePage::class,
        LicenseDependenciesPage::PAGE => LicenseDependenciesPage::class,
    ];

    public const array GROUPS = [
        NotificationsGroup::GROUP => NotificationsGroup::class,
    ];

    public const array AGENTS = [
        ConcertTicketsAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => ConcertTicketsAgent::class,
            AgentRegistryKey::DAEMON => ConcertTicketsAgentDaemon::class,
        ],
        DemoHilosAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DemoHilosAgent::class,
            AgentRegistryKey::DAEMON => DemoHilosAgentDaemon::class,
        ],
        DemoHilosAnalyticsAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DemoHilosAnalyticsAgent::class,
            AgentRegistryKey::DAEMON => DemoHilosAnalyticsAgentDaemon::class,
        ],
        AnalyticsJournalAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => AnalyticsJournalAgent::class,
            AgentRegistryKey::DAEMON => AnalyticsJournalAgentDaemon::class,
            AgentRegistryKey::SCOPE => AgentScope::NODE,
        ],
        AnalyticsWriterAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => AnalyticsWriterAgent::class,
            AgentRegistryKey::DAEMON => AnalyticsWriterAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        DataExportAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DataExportAgent::class,
            AgentRegistryKey::DAEMON => DataExportAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        SessionsLibraryAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => SessionsLibraryAgent::class,
            AgentRegistryKey::DAEMON => SessionsLibraryAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        NotificationsLibraryAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => NotificationsLibraryAgent::class,
            AgentRegistryKey::DAEMON => NotificationsLibraryAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        SettingsLibraryAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => SettingsLibraryAgent::class,
            AgentRegistryKey::DAEMON => SettingsLibraryAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        UserAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => UserAgent::class,
            AgentRegistryKey::DAEMON => UserAgentDaemon::class,
            AgentRegistryKey::INDEXED => true,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
            AgentRegistryKey::IDLE_TIMEOUT => AgentRegistry::DEFAULT_IDLE_TIMEOUT_SEC,
        ],
        UsersLibraryAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => UsersLibraryAgent::class,
            AgentRegistryKey::DAEMON => UsersLibraryAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        MailDeliveryChannelAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => MailDeliveryChannelAgent::class,
            AgentRegistryKey::DAEMON => MailDeliveryChannelAgentDaemon::class,
            AgentRegistryKey::INDEXED => true,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        ModerationAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => ModerationAgent::class,
            AgentRegistryKey::DAEMON => ModerationAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        ModerationRulesLibraryAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => ModerationRulesLibraryAgent::class,
            AgentRegistryKey::DAEMON => ModerationRulesLibraryAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        FilesLibraryAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => FilesLibraryAgent::class,
            AgentRegistryKey::DAEMON => FilesLibraryAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        UploadsAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => UploadsAgent::class,
            AgentRegistryKey::DAEMON => UploadsAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        ImagesAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => ImagesAgent::class,
            AgentRegistryKey::DAEMON => ImagesAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        DemoHilosChangeLogAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DemoHilosChangeLogAgent::class,
            AgentRegistryKey::DAEMON => DemoHilosChangeLogAgentDaemon::class,
        ],
        DemoHilosDaemonAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DemoHilosDaemonAgent::class,
            AgentRegistryKey::DAEMON => DemoHilosDaemonAgentDaemon::class,
        ],
        DemoHilosLogsAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DemoHilosLogsAgent::class,
            AgentRegistryKey::DAEMON => DemoHilosLogsAgentDaemon::class,
        ],
        OAuthAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => OAuthAgent::class,
            AgentRegistryKey::DAEMON => OAuthAgentDaemon::class,
        ],
        SmsRawSendAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => SmsRawSendAgent::class,
            AgentRegistryKey::DAEMON => SmsDeliveryChannelAgentDaemon::class,
            AgentRegistryKey::INDEXED => true,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        LogStoreAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => LogStoreAgent::class,
            AgentRegistryKey::DAEMON => LogStoreAgentDaemon::class,
            AgentRegistryKey::SCOPE => AgentScope::NODE,
        ],
        LogCarrierAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => LogCarrierAgent::class,
            AgentRegistryKey::DAEMON => LogCarrierAgentDaemon::class,
            AgentRegistryKey::SCOPE => AgentScope::NODE,
        ],
        LogAggregatorAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => LogAggregatorAgent::class,
            AgentRegistryKey::DAEMON => LogAggregatorAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        DaemonNodeAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DaemonNodeAgent::class,
            AgentRegistryKey::DAEMON => DaemonNodeAgentDaemon::class,
            AgentRegistryKey::SCOPE => AgentScope::NODE,
        ],
        DaemonCollectorAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DaemonCollectorAgent::class,
            AgentRegistryKey::DAEMON => DaemonCollectorAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
        AuthCodeAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => AuthCodeAgent::class,
            AgentRegistryKey::DAEMON => AuthCodeAgentDaemon::class,
            AgentRegistryKey::SCOPE => AgentScope::NODE,
        ],
    ];

    /** @var array<string, list<string>> Outstanding closed DB writer transitions. */
    public const array SHARED_DB_OWNERS = [
        HilosDbContext::accountDeletions => ['HIL-1952'],
        HilosDbContext::dataExports => ['HIL-1952'],
        HilosDbContext::identities => ['HIL-1952', 'HIL-1999'],
        HilosDbContext::legalAcceptances => ['HIL-1952'],
        HilosDbContext::notificationDeliveries => ['HIL-1910', 'HIL-1952'],
        HilosDbContext::notificationPreferences => ['HIL-1952'],
        HilosDbContext::notifications => ['HIL-1952'],
        HilosDbContext::passkeyCredentials => ['HIL-1952', 'HIL-1999'],
        HilosDbContext::pushSubscriptions => ['HIL-1906', 'HIL-1952'],
        HilosDbContext::secondFactorBackupCodes => ['HIL-1904', 'HIL-1952', 'HIL-1999'],
        HilosDbContext::secondFactorResets => ['HIL-1952', 'HIL-1999'],
        HilosDbContext::secondFactorSettings => ['HIL-1952', 'HIL-1999'],
        HilosDbContext::secondFactorTrusts => ['HIL-1907', 'HIL-1908', 'HIL-1909', 'HIL-1952', 'HIL-1999'],
        HilosDbContext::secondFactors => ['HIL-1903', 'HIL-1952', 'HIL-1999'],
        HilosDbContext::stepUps => ['HIL-1952'],
        HilosDbContext::userPhotos => ['HIL-1952'],
        HilosDbContext::userRenames => ['HIL-1952'],
        HilosDbContext::users => ['HIL-1952', 'HIL-1999'],
        HilosDbContext::verifications => ['HIL-1952'],
    ];

    public const array TABLES = [
        ConcertTicketsTableContext::settings => HilosSettingsTable::class,
        ConcertTicketsTableContext::hilosAppearanceSettings => HilosAppearanceSettingsTable::class,
        ConcertTicketsTableContext::hilosUsers => HilosUsersTable::class,
        ConcertTicketsTableContext::hilosNotificationDeliveries => HilosNotificationDeliveriesTable::class,
        ConcertTicketsTableContext::hilosTermsHistory => HilosTermsHistoryTable::class,
        ConcertTicketsTableContext::hilosPrivacyHistory => HilosPrivacyHistoryTable::class,
        ConcertTicketsTableContext::hilosAnalyticsActions => HilosAnalyticsActionsTable::class,
        ConcertTicketsTableContext::hilosAnalyticsBrowserSessionList => HilosAnalyticsBrowserSessionListTable::class,
        ConcertTicketsTableContext::hilosAnalyticsBrowserSessions => HilosAnalyticsBrowserSessionsTable::class,
        ConcertTicketsTableContext::hilosAnalyticsPeople => HilosAnalyticsPeopleTable::class,
        ConcertTicketsTableContext::hilosChangeLogFeed => HilosChangeLogFeedTable::class,
        ConcertTicketsTableContext::hilosChangeLogHistory => HilosChangeLogHistoryTable::class,
        ConcertTicketsTableContext::hilosChangeLogTableColumns => HilosChangeLogTableColumnsTable::class,
        ConcertTicketsTableContext::hilosChangeLogTableTriggers => HilosChangeLogTableTriggersTable::class,
        ConcertTicketsTableContext::hilosChangeLogNoisyColumns => HilosChangeLogNoisyColumnsTable::class,
        ConcertTicketsTableContext::hilosChangeLogTables => HilosChangeLogTablesTable::class,
        ConcertTicketsTableContext::hilosLogKeys => HilosLogKeysTable::class,
        ConcertTicketsTableContext::hilosLogNodes => HilosLogNodesTable::class,
        ConcertTicketsTableContext::hilosLogRotations => HilosLogRotationsTable::class,
        ConcertTicketsTableContext::hilosLogWorkers => HilosLogWorkersTable::class,
        ConcertTicketsTableContext::hilosDaemonCron => HilosDaemonCronTable::class,
        ConcertTicketsTableContext::hilosDaemonWorkers => HilosDaemonWorkersTable::class,
        ConcertTicketsTableContext::hilosDaemonEnvKeys => HilosDaemonEnvKeysTable::class,
        ConcertTicketsTableContext::hilosDaemonEnvOrphans => HilosDaemonEnvOrphansTable::class,
        ConcertTicketsTableContext::hilosDaemonAgents => HilosDaemonAgentsTable::class,
        ConcertTicketsTableContext::hilosDaemonEnvMismatch => HilosDaemonEnvMismatchTable::class,
        ConcertTicketsTableContext::hilosDaemonHttpRoutes => HilosDaemonHttpRoutesTable::class,
        ConcertTicketsTableContext::hilosDaemonPlacement => HilosDaemonPlacementTable::class,
    ];

    public const array BROWSER_LISTS = [
        HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::class,
        ProfileSessionsBrowserList::LIST => ProfileSessionsBrowserList::class,
    ];

    public const array BROWSER_TABLES = [
        HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::class,
        HilosUserPhotoBrowserTable::TABLE => HilosUserPhotoBrowserTable::class,
    ];

    public const array BROWSER_DATA = [
        SelfConnectionBrowserData::DATA => SelfConnectionBrowserData::class,
    ];

    public const array PAGE_LISTS = [
        ProfilePage::PAGE => [
            HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::BINDING,
        ],
        ProfileNotificationsPage::PAGE => [
            HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::BINDING,
        ],
        ProfileSignInPage::PAGE => [
            HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::BINDING,
        ],
        ProfileSessionsPage::PAGE => [
            HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::BINDING,
            ProfileSessionsBrowserList::LIST => [
                BrowserParamKey::PARAMS => [
                    BrowserRuntimeParam::ACCEPT_KEY => ConcertTicketsBrowserRef::ACCEPT_KEY,
                ],
            ],
        ],
        ProfileSecurityPage::PAGE => [
            HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::BINDING,
        ],
        ProfileAgreementsPage::PAGE => [
            HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::BINDING,
        ],
        ProfileDataPage::PAGE => [
            HilosProfileIdentitiesBrowserList::LIST => HilosProfileIdentitiesBrowserList::BINDING,
        ],
    ];

    public const array PAGE_DATA = [
        ProfilePage::PAGE => [
            SelfConnectionBrowserData::DATA => [
                BrowserParamKey::PARAMS => [
                    BrowserRuntimeParam::ACCEPT_KEY => ConcertTicketsBrowserRef::ACCEPT_KEY,
                ],
            ],
        ],
        ProfileSessionsPage::PAGE => [
            SelfConnectionBrowserData::DATA => [
                BrowserParamKey::PARAMS => [
                    BrowserRuntimeParam::ACCEPT_KEY => ConcertTicketsBrowserRef::ACCEPT_KEY,
                ],
            ],
        ],
    ];

    public const array PAGE_TABLES = [
        BrowserSessionsPage::PAGE => [
            ConcertTicketsTableContext::hilosAnalyticsBrowserSessionList => [],
        ],
        BrowserSessionPage::PAGE => [
            ConcertTicketsTableContext::hilosAnalyticsActions => [],
        ],
        PeoplePage::PAGE => [
            ConcertTicketsTableContext::hilosAnalyticsPeople => [],
        ],
        PersonPage::PAGE => [
            ConcertTicketsTableContext::hilosAnalyticsBrowserSessions => [],
        ],
        SettingsPage::PAGE => [
            ConcertTicketsTableContext::settings => [],
        ],
        AppearancePage::PAGE => [
            ConcertTicketsTableContext::hilosAppearanceSettings => [],
        ],
        CommunicationsDeliveriesPage::PAGE => [
            ConcertTicketsTableContext::hilosNotificationDeliveries => [],
        ],
        UsersPage::PAGE => [
            ConcertTicketsTableContext::hilosUsers => [],
        ],
        UserPage::PAGE => [
            HilosUserDetailBrowserTable::TABLE => HilosUserDetailBrowserTable::BINDING,
            HilosUserPhotoBrowserTable::TABLE => HilosUserPhotoBrowserTable::BINDING,
        ],
        ChangeLogFeedPage::PAGE => [
            ConcertTicketsTableContext::hilosChangeLogFeed => [],
        ],
        ChangeLogTablesPage::PAGE => [
            ConcertTicketsTableContext::hilosChangeLogTables => [],
        ],
        ChangeLogNoisyPage::PAGE => [
            ConcertTicketsTableContext::hilosChangeLogNoisyColumns => [],
        ],
        ChangeLogTableHistoryPage::PAGE => [
            ConcertTicketsTableContext::hilosChangeLogHistory => [],
        ],
        ChangeLogTableColumnsPage::PAGE => [
            ConcertTicketsTableContext::hilosChangeLogTableColumns => [],
        ],
        ChangeLogTableTriggersPage::PAGE => [
            ConcertTicketsTableContext::hilosChangeLogTableTriggers => [],
        ],
        LogsOverviewPage::PAGE => [
            ConcertTicketsTableContext::hilosLogNodes => [],
        ],
        LogsKeysPage::PAGE => [
            ConcertTicketsTableContext::hilosLogKeys => [],
        ],
        LogsRotationsPage::PAGE => [
            ConcertTicketsTableContext::hilosLogRotations => [],
        ],
        LogsWorkersPage::PAGE => [
            ConcertTicketsTableContext::hilosLogWorkers => [],
        ],
        DaemonCronPage::PAGE => [
            ConcertTicketsTableContext::hilosDaemonCron => [],
        ],
        DaemonWorkersPage::PAGE => [
            ConcertTicketsTableContext::hilosDaemonWorkers => [],
        ],
        DaemonEnvPage::PAGE => [
            ConcertTicketsTableContext::hilosDaemonEnvKeys => [],
        ],
        DaemonEnvOrphansPage::PAGE => [
            ConcertTicketsTableContext::hilosDaemonEnvOrphans => [],
        ],
        DaemonPlacementPage::PAGE => [
            ConcertTicketsTableContext::hilosDaemonPlacement => [],
        ],
        DaemonAgentsPage::PAGE => [
            ConcertTicketsTableContext::hilosDaemonAgents => [],
        ],
        DaemonEnvMismatchPage::PAGE => [
            ConcertTicketsTableContext::hilosDaemonEnvMismatch => [],
        ],
        DaemonHttpServerPage::PAGE => [
            ConcertTicketsTableContext::hilosDaemonHttpRoutes => [],
        ],
        TermsHistoryPage::PAGE => [
            ConcertTicketsTableContext::hilosTermsHistory => [],
        ],
        PrivacyHistoryPage::PAGE => [
            ConcertTicketsTableContext::hilosPrivacyHistory => [],
        ],
    ];

    protected const string ENV_CATALOG = ConcertTicketsEnvCatalog::class;

    protected const string AUTH_METHOD_DIRECTORY = ConcertTicketsAuthMethodDirectory::class;

    protected const string CODE_CHANNEL_REGISTRY = ConcertTicketsCodeChannelRegistry::class;

    protected const string OAUTH_PROVIDER_DIRECTORY = ConcertTicketsOAuthProviderDirectory::class;

    protected const string SETTINGS_CATALOG = ConcertTicketsSettingsCatalog::class;

    protected const string PAGE_CATALOG = PageCatalog::class;

    protected const string NOTIFICATION_CHANNEL_REGISTRY = ConcertTicketsDeliveryChannelRegistry::class;

    protected const ?string LEGAL_CATALOG = ConcertTicketsLegalCatalog::class;

    protected const array FEATURES = [
        HilosFeature::AUTH,
        HilosFeature::SETTINGS,
        HilosFeature::ANALYTICS,
        HilosFeature::HILOS_USERS,
        HilosFeature::NOTIFICATIONS,
        HilosFeature::NOTIFICATION_DELIVERY,
        HilosFeature::CODE_CHANNELS,
        HilosFeature::FILES,
        HilosFeature::UPLOADS,
        HilosFeature::IMAGES,
        HilosFeature::PROFILE_PHOTO,
        HilosFeature::MODERATION,
        HilosFeature::DAEMON,
        HilosFeature::LOGS,
        HilosFeature::CHANGE_LOG,
    ];

    protected const array DATABASE_GUARANTEES = [
        DatabaseGuarantee::ONE_LOGICAL_DATABASE,
        DatabaseGuarantee::READ_AFTER_WRITE,
    ];

    /**
     * Creates the concert-tickets database context.
     *
     * @return ConcertTicketsDbContext Concert Tickets database context
     */
    protected static function createDb(): HilosDbContext
    {
        return new ConcertTicketsDbContext();
    }

    /**
     * Creates the concert-tickets runtime context.
     *
     * @return ?ConcertTicketsRtContext Concert Tickets runtime context
     */
    protected static function createRuntime(): ?RtContext
    {
        return new ConcertTicketsRtContext();
    }

    /**
     * Creates the concert-tickets table context.
     *
     * @return ?ConcertTicketsTableContext Concert Tickets table context
     */
    protected static function createTable(): ?TableContext
    {
        return new ConcertTicketsTableContext();
    }

    /**
     * Creates the concert-tickets browser-facing context.
     *
     * @return ?ConcertTicketsBrowserContext Concert Tickets browser context
     */
    protected static function createBrowser(): ?BrowserContext
    {
        return new ConcertTicketsBrowserContext();
    }

    /**
     * @return ?ConcertTicketsFsContext Project filesystem bindings
     */
    protected static function createFs(): ?FsContext
    {
        return new ConcertTicketsFsContext();
    }
}

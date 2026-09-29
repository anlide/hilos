<?php

declare(strict_types=1);

namespace Demo\BinanceBtcTracker;

use Demo\BinanceBtcTracker\Agents\BinanceBtcTrackerAgent;
use Demo\BinanceBtcTracker\Agents\Hilos\DataExportAgent;
use Demo\BinanceBtcTracker\Agents\Hilos\DemoHilosAgent;
use Demo\BinanceBtcTracker\Agents\Hilos\SessionsLibraryAgent;
use Demo\BinanceBtcTracker\Agents\Hilos\UsersLibraryAgent;
use Demo\BinanceBtcTracker\Auth\BinanceBtcTrackerAuthMethodDirectory;
use Demo\BinanceBtcTracker\Backup\BackupCatalog;
use Demo\BinanceBtcTracker\Browser\BinanceBtcTrackerBrowserContext;
use Demo\BinanceBtcTracker\Core\Agent\Daemon\BinanceBtcTrackerAgentDaemon;
use Demo\BinanceBtcTracker\Core\Agent\Daemon\Hilos\DemoHilosAgentDaemon;
use Demo\BinanceBtcTracker\Core\Agent\Daemon\Hilos\SessionsLibraryAgentDaemon;
use Demo\BinanceBtcTracker\Core\Agent\Daemon\Hilos\UsersLibraryAgentDaemon;
use Demo\BinanceBtcTracker\Database\BinanceBtcTrackerDbContext;
use Demo\BinanceBtcTracker\Environment\BinanceBtcTrackerEnvCatalog;
use Demo\BinanceBtcTracker\Fs\BinanceBtcTrackerFsContext;
use Demo\BinanceBtcTracker\Legal\BinanceBtcTrackerLegalCatalog;
use Demo\BinanceBtcTracker\Pages\Hilos\AboutPage;
use Demo\BinanceBtcTracker\Pages\Hilos\Backup\BackupPage;
use Demo\BinanceBtcTracker\Pages\Hilos\DashboardPage;
use Demo\BinanceBtcTracker\Pages\Hilos\LicensePage;
use Demo\BinanceBtcTracker\Pages\Hilos\Maintenance\MaintenancePage;
use Demo\BinanceBtcTracker\Pages\Hilos\PrivacyPage;
use Demo\BinanceBtcTracker\Pages\Hilos\TermsPage;
use Demo\BinanceBtcTracker\Pages\MainPage;
use Demo\BinanceBtcTracker\Runtime\View\Context\BinanceBtcTrackerRtContext;
use Demo\BinanceBtcTracker\Tables\BinanceBtcTrackerTableContext;
use Hilos\Auth\Throttle\Agent\AuthThrottleAgent;
use Hilos\Auth\Throttle\Agent\AuthThrottleAgentDaemon;
use Hilos\Backup\Agent\BackupAgent;
use Hilos\Backup\Agent\BackupAgentDaemon;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\TruthSource\SharedOwnersKey;
use Hilos\DataExport\DataExportAgentDaemon;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Environment\EnvAccessor;
use Hilos\Fs\Context\FsContext;
use Hilos\Hilos as HilosFacade;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Mail\Delivery\MailDeliveryChannelAgentDaemon;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Tables\Backup\HilosBackupHistoryTable;
use Hilos\Tables\ProtectedMode\HilosVerifierCircleTable;

/**
 * Hilos - Main app facade for data access.
 *
 * The smallest complete shape of a project: sign-in by password, an empty home, the admin
 * dashboard and the four public footer pages. Two admin sections are activated so far -
 * Maintenance, the verifier circle a freeze lets through, and Backup, the database archives - and
 * the others arrive one by one, each with the leaf that moves its e2e onto this demo.
 *
 * Usage:
 * - Hilos::$env[EnvConstants::HTTP_STATUS_HOST]->string()
 * - Hilos::$db->users
 * - Hilos::$rt->connections
 *
 * @property-read BinanceBtcTrackerDbContext $db Database context (narrows parent's DbContext for IDE)
 * @property-read EnvAccessor $env Environment accessor (narrows parent's EnvAccessor for IDE)
 * @property-read SettingsAccessor $setting Settings accessor (narrows parent's SettingsAccessor for IDE)
 * @property-read BinanceBtcTrackerRtContext $rt Runtime context (narrows parent's RtContext for IDE)
 * @property-read BinanceBtcTrackerTableContext $table Table context (narrows parent's TableContext for IDE)
 * @property-read BinanceBtcTrackerBrowserContext $browser Browser context (narrows parent's BrowserContext for IDE)
 */
final class Hilos extends HilosFacade
{
    protected const string ENV_CATALOG = BinanceBtcTrackerEnvCatalog::class;

    protected const string AUTH_METHOD_DIRECTORY = BinanceBtcTrackerAuthMethodDirectory::class;

    protected const ?string LEGAL_CATALOG = BinanceBtcTrackerLegalCatalog::class;

    protected const ?string BACKUP_CATALOG = BackupCatalog::class;

    protected const array FEATURES = [
        HilosFeature::AUTH,
        HilosFeature::AUTH_THROTTLE,
        HilosFeature::BACKUP,
    ];

    public const array PAGES = [
        MainPage::PAGE => MainPage::class,
        DashboardPage::PAGE => DashboardPage::class,
        BackupPage::PAGE => BackupPage::class,
        MaintenancePage::PAGE => MaintenancePage::class,
        AboutPage::PAGE => AboutPage::class,
        TermsPage::PAGE => TermsPage::class,
        PrivacyPage::PAGE => PrivacyPage::class,
        LicensePage::PAGE => LicensePage::class,
    ];

    public const array AGENTS = [
        BinanceBtcTrackerAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => BinanceBtcTrackerAgent::class,
            AgentRegistryKey::DAEMON => BinanceBtcTrackerAgentDaemon::class,
        ],
        DemoHilosAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => DemoHilosAgent::class,
            AgentRegistryKey::DAEMON => DemoHilosAgentDaemon::class,
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
        AuthThrottleAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => AuthThrottleAgent::class,
            AgentRegistryKey::DAEMON => AuthThrottleAgentDaemon::class,
            AgentRegistryKey::SCOPE => AgentScope::NODE,
        ],
        BackupAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => BackupAgent::class,
            AgentRegistryKey::DAEMON => BackupAgentDaemon::class,
            AgentRegistryKey::PLACEMENT => AgentPlacement::POLICY,
        ],
    ];

    /**
     * The collections this demo still lets two owners hold, and who will part them.
     *
     * Receipts, not permissions: every pair here is a place where two agents write the same rows
     * today, and startup refuses both a pair that is missing from this list and a row whose
     * owners no longer collide.
     *
     * Three rows, all of them signing in: the people, their step-up proofs and the registration
     * holds, each shared by the sessions and the users library. They stand the same way in every
     * demo that switches the feature on; the rows the provider and the code agents add elsewhere
     * do not arise here, because this demo runs neither. Parting them has an address: HIL-630
     * gives the person an agent of their own, and the auth libraries are parted with it.
     */
    public const array SHARED_DB_OWNERS = [
        HilosDbContext::stepUps => [
            SharedOwnersKey::OWNERS => [UsersLibraryAgent::class, SessionsLibraryAgent::class],
            SharedOwnersKey::DEBT => 'HIL-630',
        ],
        HilosDbContext::users => [
            SharedOwnersKey::OWNERS => [SessionsLibraryAgent::class, UsersLibraryAgent::class],
            SharedOwnersKey::DEBT => 'HIL-630',
        ],
        HilosDbContext::registrationReservations => [
            SharedOwnersKey::OWNERS => [SessionsLibraryAgent::class, UsersLibraryAgent::class],
            SharedOwnersKey::DEBT => 'HIL-630',
        ],
    ];

    public const array TABLES = [
        BinanceBtcTrackerTableContext::hilosBackups => HilosBackupHistoryTable::class,
        BinanceBtcTrackerTableContext::hilosVerifierCircle => HilosVerifierCircleTable::class,
    ];

    public const array PAGE_TABLES = [
        BackupPage::PAGE => [
            BinanceBtcTrackerTableContext::hilosBackups => [],
        ],
        MaintenancePage::PAGE => [
            BinanceBtcTrackerTableContext::hilosVerifierCircle => [],
        ],
    ];

    /**
     * Creates the binance-btc-tracker database context.
     *
     * @return BinanceBtcTrackerDbContext Binance BTC tracker database context
     */
    protected static function createDb(): HilosDbContext
    {
        return new BinanceBtcTrackerDbContext();
    }

    /**
     * Creates the binance-btc-tracker runtime context.
     *
     * @return ?BinanceBtcTrackerRtContext Binance BTC tracker runtime context
     */
    protected static function createRuntime(): ?RtContext
    {
        return new BinanceBtcTrackerRtContext();
    }

    /**
     * Creates the binance-btc-tracker table context.
     *
     * @return ?BinanceBtcTrackerTableContext Binance BTC tracker table context
     */
    protected static function createTable(): ?TableContext
    {
        return new BinanceBtcTrackerTableContext();
    }

    /**
     * Creates the binance-btc-tracker browser-facing context.
     *
     * @return ?BinanceBtcTrackerBrowserContext Binance BTC tracker browser context
     */
    protected static function createBrowser(): ?BrowserContext
    {
        return new BinanceBtcTrackerBrowserContext();
    }

    /**
     * @return ?BinanceBtcTrackerFsContext Project filesystem bindings
     */
    protected static function createFs(): ?FsContext
    {
        return new BinanceBtcTrackerFsContext();
    }
}

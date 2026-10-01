<?php

declare(strict_types=1);

namespace Demo\OnlineTesting;

use Demo\OnlineTesting\Agents\OnlineTestingAgent;
use Demo\OnlineTesting\Agents\Hilos\DataExportAgent;
use Demo\OnlineTesting\Agents\Hilos\DemoHilosAgent;
use Demo\OnlineTesting\Agents\Hilos\SessionsLibraryAgent;
use Demo\OnlineTesting\Agents\Hilos\UsersLibraryAgent;
use Demo\OnlineTesting\Auth\OnlineTestingAuthMethodDirectory;
use Demo\OnlineTesting\Browser\OnlineTestingBrowserContext;
use Demo\OnlineTesting\Core\Agent\Daemon\OnlineTestingAgentDaemon;
use Demo\OnlineTesting\Core\Agent\Daemon\Hilos\DemoHilosAgentDaemon;
use Demo\OnlineTesting\Core\Agent\Daemon\Hilos\SessionsLibraryAgentDaemon;
use Demo\OnlineTesting\Core\Agent\Daemon\Hilos\UsersLibraryAgentDaemon;
use Demo\OnlineTesting\Database\OnlineTestingDbContext;
use Demo\OnlineTesting\Environment\OnlineTestingEnvCatalog;
use Demo\OnlineTesting\Fs\OnlineTestingFsContext;
use Demo\OnlineTesting\Legal\OnlineTestingLegalCatalog;
use Demo\OnlineTesting\Pages\Hilos\AboutPage;
use Demo\OnlineTesting\Pages\Hilos\DashboardPage;
use Demo\OnlineTesting\Pages\Hilos\LicensePage;
use Demo\OnlineTesting\Pages\Hilos\PrivacyPage;
use Demo\OnlineTesting\Pages\Hilos\TermsPage;
use Demo\OnlineTesting\Pages\MainPage;
use Demo\OnlineTesting\Runtime\View\Context\OnlineTestingRtContext;
use Hilos\Auth\Throttle\Agent\AuthThrottleAgent;
use Hilos\Auth\Throttle\Agent\AuthThrottleAgentDaemon;
use Hilos\Cluster\Probe\ClusterProbe;
use Hilos\Constants\HilosAgentType;
use Hilos\Core\Agent\Config\AgentPlacement;
use Hilos\Core\Agent\Config\AgentRegistryKey;
use Hilos\Core\Agent\Config\AgentScope;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\TruthSource\SharedOwnersKey;
use Hilos\DataExport\DataExportAgentDaemon;
use Hilos\Database\Context\HilosDbContext;
use Hilos\Database\DatabaseGuarantee;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Environment\EnvAccessor;
use Hilos\Fs\Context\FsContext;
use Hilos\Hilos as HilosFacade;
use Hilos\Mail\Delivery\MailDeliveryChannelAgent;
use Hilos\Mail\Delivery\MailDeliveryChannelAgentDaemon;
use Hilos\Runtime\View\Context\RtContext;

/**
 * Hilos - Main app facade for data access.
 *
 * The smallest complete shape of a project: sign-in by password, an empty home, the empty
 * admin dashboard and the four public footer pages. No admin section is activated yet - each
 * arrives with the leaf that moves its e2e onto this demo.
 *
 * Its cluster stand (docker/docker-compose.cluster.yml) runs the framework's fleet and
 * database probes.
 *
 * Usage:
 * - Hilos::$env[EnvConstants::HTTP_STATUS_HOST]->string()
 * - Hilos::$db->users
 * - Hilos::$rt->connections
 *
 * @property-read OnlineTestingDbContext $db Database context (narrows parent's DbContext for IDE)
 * @property-read EnvAccessor $env Environment accessor (narrows parent's EnvAccessor for IDE)
 * @property-read SettingsAccessor $setting Settings accessor (narrows parent's SettingsAccessor for IDE)
 * @property-read OnlineTestingRtContext $rt Runtime context (narrows parent's RtContext for IDE)
 * @property-read OnlineTestingBrowserContext $browser Browser context (narrows parent's BrowserContext for IDE)
 */
final class Hilos extends HilosFacade
{
    protected const string ENV_CATALOG = OnlineTestingEnvCatalog::class;

    protected const string AUTH_METHOD_DIRECTORY = OnlineTestingAuthMethodDirectory::class;

    protected const ?string LEGAL_CATALOG = OnlineTestingLegalCatalog::class;

    protected const array FEATURES = [
        HilosFeature::AUTH,
        HilosFeature::AUTH_THROTTLE,
    ];

    protected const array DATABASE_GUARANTEES = [
        DatabaseGuarantee::ONE_LOGICAL_DATABASE,
        DatabaseGuarantee::READ_AFTER_WRITE,
    ];

    public const array PAGES = [
        MainPage::PAGE => MainPage::class,
        DashboardPage::PAGE => DashboardPage::class,
        AboutPage::PAGE => AboutPage::class,
        TermsPage::PAGE => TermsPage::class,
        PrivacyPage::PAGE => PrivacyPage::class,
        LicensePage::PAGE => LicensePage::class,
    ];

    public const array AGENTS = [
        OnlineTestingAgent::AGENT_TYPE => [
            AgentRegistryKey::WORKER => OnlineTestingAgent::class,
            AgentRegistryKey::DAEMON => OnlineTestingAgentDaemon::class,
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
        // The probes of this demo's cluster stand - scenario 24 reads the fleet, scenario 11
        // writes and reads through the database probe. A probe starts only on a clustered node of
        // a non-production environment, so this demo on one node, on its Playwright stand and in
        // production carries the rows and runs none of them (docs/agents/testing.md, "The cluster
        // stands").
        HilosAgentType::HILOS_PROBE_FLEET => ClusterProbe::AGENTS[HilosAgentType::HILOS_PROBE_FLEET],
        HilosAgentType::HILOS_PROBE_DB => ClusterProbe::AGENTS[HilosAgentType::HILOS_PROBE_DB],
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

    /**
     * Creates the online-testing database context.
     *
     * @return OnlineTestingDbContext Online-testing database context
     */
    protected static function createDb(): HilosDbContext
    {
        return new OnlineTestingDbContext();
    }

    /**
     * Creates the online-testing runtime context.
     *
     * @return ?OnlineTestingRtContext Online-testing runtime context
     */
    protected static function createRuntime(): ?RtContext
    {
        return new OnlineTestingRtContext();
    }

    /**
     * Creates the online-testing browser-facing context.
     *
     * @return ?OnlineTestingBrowserContext Online-testing browser context
     */
    protected static function createBrowser(): ?BrowserContext
    {
        return new OnlineTestingBrowserContext();
    }

    /**
     * @return ?OnlineTestingFsContext Project filesystem bindings
     */
    protected static function createFs(): ?FsContext
    {
        return new OnlineTestingFsContext();
    }
}

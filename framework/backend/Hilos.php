<?php

declare(strict_types=1);

namespace Hilos;

use Hilos\Cluster\ClusterContext;
use Hilos\Core\Analytics\AnalyticsCollector;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\Group\AbstractGroup;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Topology\Exception\InvalidTopologyException;
use Hilos\Core\Topology\AgentActionRouteRegistry;
use Hilos\Core\Topology\AgentSignalRouteRegistry;
use Hilos\Core\Topology\PageSignalRouteRegistry;
use Hilos\Core\Topology\TopologyValidator;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogStub;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\LLM\Routing\LlmProfileCatalogStub;
use Hilos\LLM\Routing\LlmProfileOverrideSource;
use Hilos\LLM\Routing\LlmRouter;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Fs\Context\FsContext;
use Hilos\Runtime\View\Context\RtContext;

/**
 * Main framework facade providing global access to core layer singletons.
 *
 * Application classes should extend this class and expose:
 * - Hilos::$db         — database layer
 * - Hilos::$env        — catalog-backed environment variables
 * - Hilos::$setting    — catalog-backed setting values
 * - Hilos::$rt         — runtime layer
 * - Hilos::$table      — table layer
 * - Hilos::$browser    — browser-facing state layer
 * - Hilos::$fs         — filesystem layer
 * - Hilos::$sr         — signal router
 * - Hilos::$ac         — analytics collector
 * - Hilos::$cluster    — cluster mode and local node identity
 */
abstract class Hilos
{
    /** @var class-string<CatalogProviderInterface> Environment catalog provider class. */
    protected const string ENV_CATALOG = EnvCatalogStub::class;

    /** @var class-string<CatalogProviderInterface> Settings catalog provider class. */
    protected const string SETTINGS_CATALOG = SettingsCatalogStub::class;

    /** @var class-string<CatalogProviderInterface> LLM profile catalog provider class. */
    protected const string LLM_PROFILE_CATALOG = LlmProfileCatalogStub::class;

    /** @var ?class-string<LlmProfileOverrideSource> Optional runtime LLM profile override source (e.g. admin settings). */
    protected const ?string LLM_PROFILE_OVERRIDE = null;

    /**
     * Optional backup catalog provider class (schedule and reference registry).
     *
     * A project opts into the backup subsystem by pointing this at its own
     * catalog; the framework provides no default. Null leaves backup unconfigured.
     *
     * @var ?class-string<CatalogProviderInterface>
     */
    protected const ?string BACKUP_CATALOG = null;

    /** Page classes keyed by page name. */
    public const array PAGES = [];

    /**
     * Agent runtime bindings keyed by agent type.
     *
     * Each entry declares worker and daemon classes via AgentRegistryKey.
     */
    public const array AGENTS = [];

    /** Group classes keyed by group name. */
    public const array GROUPS = [];

    /** Registered table definition classes keyed by table name. */
    public const array TABLES = [];

    /** Browser table source config classes keyed by table key. */
    public const array BROWSER_TABLES = [];

    /** Browser list source config classes keyed by list key. */
    public const array BROWSER_LISTS = [];

    /** Browser data source config classes keyed by data key. */
    public const array BROWSER_DATA = [];

    /** Page table bindings keyed by page name, then table name. */
    public const array PAGE_TABLES = [];

    /** Page list bindings keyed by page name, then list key. */
    public const array PAGE_LISTS = [];

    /** Page data bindings keyed by page name, then data key. */
    public const array PAGE_DATA = [];

    /** Conventional name of the project-level persistent-data directory. */
    public const string DATA_DIR = 'data';

    /** @var ?EnvAccessor Catalog-backed environment accessor */
    public static ?EnvAccessor $env = null;

    /** @var ?DbContext Database layer singleton */
    public static ?DbContext $db = null;

    /** @var ?SettingsAccessor Catalog-backed settings accessor */
    public static ?SettingsAccessor $setting = null;

    /** @var ?LlmRouter Catalog-backed LLM profile router */
    public static ?LlmRouter $llm = null;

    /** @var ?RtContext Runtime layer singleton */
    public static ?RtContext $rt = null;

    /** @var ?TableContext Table layer singleton */
    public static ?TableContext $table = null;

    /** @var ?BrowserContext Browser-facing state layer singleton */
    public static ?BrowserContext $browser = null;

    /** @var ?FsContext Filesystem layer singleton */
    public static ?FsContext $fs = null;

    /** @var ?SignalRouter Signal router singleton */
    public static ?SignalRouter $sr = null;

    /** @var ?AnalyticsCollector Analytics collector singleton */
    public static ?AnalyticsCollector $ac = null;

    /** @var ?ClusterContext Cluster mode and local node identity singleton */
    public static ?ClusterContext $cluster = null;

    /**
     * Returns the project's backup catalog provider class, or null when backup is unconfigured.
     *
     * The backup subsystem reads the reference-object registry through this class; the
     * facade only exposes which catalog to read, leaving catalog interpretation to the
     * backup layer's reference registry.
     *
     * @return ?class-string<CatalogProviderInterface> Backup catalog provider class
     */
    public static function getBackupCatalogClass(): ?string
    {
        return static::BACKUP_CATALOG;
    }

    /**
     * Returns page subscription owner agent types declared by registered page classes.
     *
     * Invalid page registry entries are skipped here and reported by topology validation.
     *
     * @return array<string, string> Agent type keyed by page name
     */
    public static function getPageRoutes(): array
    {
        $pageRoutes = [];
        foreach (static::PAGES as $page => $pageClass) {
            if (!is_string($page) || !is_string($pageClass) || !is_subclass_of($pageClass, AbstractPage::class)) {
                continue;
            }

            $pageRoutes[$page] = $pageClass::SUBSCRIPTION_AGENT_TYPE;
        }

        return $pageRoutes;
    }

    /**
     * Returns group subscription owner agent types declared by registered group classes.
     *
     * Invalid group registry entries are skipped here and reported by topology validation.
     *
     * @return array<string, string> Agent type keyed by group name
     */
    public static function getGroupRoutes(): array
    {
        $groupRoutes = [];
        foreach (static::GROUPS as $group => $groupClass) {
            if (!is_string($group) || !is_string($groupClass) || !is_subclass_of($groupClass, AbstractGroup::class)) {
                continue;
            }

            $groupRoutes[$group] = $groupClass::SUBSCRIPTION_AGENT_TYPE;
        }

        return $groupRoutes;
    }

    /**
     * Returns page-owned WebSocket action routes declared by registered page classes.
     *
     * Invalid page registry entries and malformed action declarations are skipped
     * here and reported by topology validation.
     *
     * @return array<string, string> Page name keyed by action name
     */
    public static function getPageActionRoutes(): array
    {
        $actionRoutes = [];
        foreach (static::PAGES as $page => $pageClass) {
            if (!is_string($page) || !is_string($pageClass) || !is_subclass_of($pageClass, AbstractPage::class)) {
                continue;
            }

            foreach ($pageClass::ACTIONS as $action => $_dtoClass) {
                if (!is_string($action) || $action === '') {
                    continue;
                }

                $actionRoutes[$action] = $page;
            }
        }

        return $actionRoutes;
    }

    /**
     * Returns page-owned WebSocket action payload DTO classes.
     *
     * Invalid page registry entries and malformed action declarations are skipped
     * here and reported by topology validation.
     *
     * @return array<string, class-string<ActionPayloadDTO>> DTO class keyed by action name
     */
    public static function getActionDtoRoutes(): array
    {
        $dtoRoutes = [];
        foreach (static::PAGES as $page => $pageClass) {
            if (!is_string($page) || !is_string($pageClass) || !is_subclass_of($pageClass, AbstractPage::class)) {
                continue;
            }

            foreach ($pageClass::ACTIONS as $action => $dtoClass) {
                if (!is_string($action) || $action === '' || !is_string($dtoClass)) {
                    continue;
                }

                $dtoRoutes[$action] = $dtoClass;
            }
        }

        return $dtoRoutes;
    }

    /**
     * Returns WebSocket action owner agent types through page-owned action routes.
     *
     * @return array<string, string> Agent type keyed by action name
     */
    public static function getActionAgentRoutes(): array
    {
        $pageRoutes = static::getPageRoutes();
        $actionAgentRoutes = [];
        foreach (static::getPageActionRoutes() as $action => $page) {
            $agentType = $pageRoutes[$page] ?? '';
            if ($agentType === '') {
                continue;
            }

            $actionAgentRoutes[$action] = $agentType;
        }

        return $actionAgentRoutes;
    }

    /**
     * Returns CLI command owner agent types keyed by command name.
     *
     * A project overrides this to route a command received over the command socket
     * channel to the agent that handles it. The framework declares none.
     *
     * @return array<string, string> Agent type keyed by command name
     */
    public static function getCommandAgentRoutes(): array
    {
        return [];
    }

    /**
     * Returns page-owned non-action signal routes declared by registered page classes.
     *
     * Empty signal name lists declare a type-wide route; non-empty lists declare
     * named routes for that signal type.
     *
     * @return array<string, string|array<string, string>> Page route keyed by signal type, then signal name
     */
    public static function getPageSignalRoutes(): array
    {
        return PageSignalRouteRegistry::routes(static::PAGES);
    }

    /**
     * Returns page-owned named signal inner payload DTO classes.
     *
     * List-style signal name entries declare routing only. Map-style entries with
     * class-string values declare both routing and inner payload DTO classes.
     *
     * @return array<string, array<string, class-string<SignalDataInterface>>> DTO class keyed by signal type, then signal name
     */
    public static function getPageSignalDtoRoutes(): array
    {
        return PageSignalRouteRegistry::dtoRoutes(static::PAGES);
    }

    /**
     * Returns page-owned non-action signal owner agent routes.
     *
     * @return array<string, string|array<string, string>> Agent route keyed by signal type, then signal name
     */
    public static function getPageSignalAgentRoutes(): array
    {
        $pageRoutes = static::getPageRoutes();
        $signalAgentRoutes = [];
        foreach (static::getPageSignalRoutes() as $signalType => $route) {
            if (is_string($route)) {
                $agentType = $pageRoutes[$route] ?? '';
                if ($agentType !== '') {
                    $signalAgentRoutes[$signalType] = $agentType;
                }
                continue;
            }

            $signalAgentRoutes[$signalType] = [];
            foreach ($route as $signalName => $page) {
                $agentType = $pageRoutes[$page] ?? '';
                if ($agentType !== '') {
                    $signalAgentRoutes[$signalType][$signalName] = $agentType;
                }
            }
        }

        return $signalAgentRoutes;
    }

    /**
     * Returns agent-owned agent signal routes declared by registered agent classes.
     *
     * Accepts mixed list/map AGENT_SIGNALS shape:
     * - int-keyed string entry  → singleton route (agentIndex null)
     * - string-keyed class-string entry → singleton route with inner payload DTO
     * - string-keyed array entry → indexed route (agentIndex from INDEX_FIELD payload field)
     *
     * @return array<string, string> Agent type keyed by signal name
     */
    public static function getAgentSignalRoutes(): array
    {
        return AgentSignalRouteRegistry::routes(static::AGENTS);
    }

    /**
     * Returns agent-owned signal inner payload DTO classes.
     *
     * List-style signal name entries declare routing only. Map-style entries with
     * class-string values declare both routing and inner payload DTO classes.
     * Indexed config arrays may optionally declare AgentSignalConfigKey::DTO.
     *
     * @return array<string, class-string<SignalDataInterface>> DTO class keyed by signal name
     */
    public static function getAgentSignalDtoRoutes(): array
    {
        return AgentSignalRouteRegistry::dtoRoutes(static::AGENTS);
    }

    /**
     * Returns index field names for indexed agent signal routes.
     *
     * Only signals declared with AgentSignalConfigKey::INDEX_FIELD are returned.
     * At dispatch time SignalRouter reads this field from the inner payload's toArray().
     *
     * @return array<string, string> Payload field name keyed by signal name
     */
    public static function getAgentSignalIndexFields(): array
    {
        return AgentSignalRouteRegistry::indexFields(static::AGENTS);
    }

    /**
     * Returns agent-owned client-action owner agent types keyed by action name.
     *
     * The page-independent action seam: actions declared in agent AGENT_ACTIONS route
     * straight to the owning agent, alongside the page-owned {@see self::getPageActionRoutes()}.
     *
     * @return array<string, string> Agent type keyed by action name
     */
    public static function getAgentActionRoutes(): array
    {
        return AgentActionRouteRegistry::routes(static::AGENTS);
    }

    /**
     * Returns agent-owned client-action payload DTO classes keyed by action name.
     *
     * @return array<string, class-string<ActionPayloadDTO>> DTO class keyed by action name
     */
    public static function getAgentActionDtoRoutes(): array
    {
        return AgentActionRouteRegistry::dtoRoutes(static::AGENTS);
    }

    /**
     * Initializes env, settings, storage, runtime, table, browser, and filesystem layers.
     *
     * @throws InvalidTopologyException When project topology constants are inconsistent
     * @throws HilosException When a layer factory or configure step cannot initialize its singleton
     */
    public static function init(): void
    {
        static::validateTopology();

        if (static::$env === null) {
            static::$env = static::createEnv();
        }

        if (static::$setting === null) {
            static::$setting = static::createSetting();
        }

        if (static::$db === null) {
            static::$db = static::createDb();
            static::$db->configure();
        }

        if (static::$rt === null) {
            static::$rt = static::createRuntime();
            static::$rt?->configure();
        }

        if (static::$table === null) {
            static::$table = static::createTable();
            static::$table?->configure();
        }

        if (static::$browser === null) {
            static::$browser = static::createBrowser();
        }
        static::bindBrowserContext();

        if (static::$fs === null) {
            static::$fs = static::createFs();
            static::$fs?->configure();
        }

    }

    /**
     * Validates project topology constants before runtime layers use them.
     *
     * @throws InvalidTopologyException When topology constants are inconsistent
     */
    public static function validateTopology(): void
    {
        static::createTopologyValidator()->validate(static::class);
    }

    /**
     * Initializes the environment accessor before storage and daemon layers.
     *
     * @param ?string $rootPath Directory that contains .env and .env.example
     * @param bool $copyExample If true, copy .env.example to .env when .env is missing
     */
    public static function initEnv(?string $rootPath = null, bool $copyExample = true): void
    {
        if (static::$env === null) {
            static::$env = static::createEnv();
        }

        static::$env->init($rootPath, $copyExample);

        if (static::$llm === null) {
            static::$llm = static::createLlm();
        }

        if (static::$cluster === null) {
            static::$cluster = static::createCluster();
        }
    }

    /**
     * Loads an explicit env file into the active environment accessor.
     *
     * @param string $envFilePath Path to env file
     * @throws EnvInvalidValueException When the env file is missing
     */
    public static function loadEnv(string $envFilePath): void
    {
        if (static::$env === null) {
            static::$env = static::createEnv();
        }

        static::$env->load($envFilePath);
    }

    /**
     * Reloads the active environment accessor from its current env path.
     */
    public static function reloadEnv(): void
    {
        if (static::$env === null) {
            static::$env = static::createEnv();
        }

        static::$env->reload();
    }

    /**
     * Initializes the signal router layer.
     *
     * Called separately from init() because the signal router is created
     * by DaemonManager/WorkerManager during process startup, which may
     * happen at a different lifecycle stage than the other layers.
     *
     * @param SignalRouter $signalRouter Signal router instance
     */
    public static function initSignalRouter(SignalRouter $signalRouter): void
    {
        static::$sr = $signalRouter;
    }

    /**
     * Initializes the analytics collector.
     *
     * @param ?AnalyticsCollector $analyticsCollector Analytics collector instance
     */
    public static function initAnalytics(?AnalyticsCollector $analyticsCollector = null): void
    {
        static::$ac = $analyticsCollector ?? new AnalyticsCollector();
    }

    /**
     * Replaces the worker-local browser context.
     *
     * Tests and bootstrap code can reset the browser source-change buffer without
     * assigning facade globals directly. When no context is passed, the active
     * project factory creates the browser context.
     *
     * @param ?BrowserContext $browser Browser context to use for this worker
     */
    public static function initBrowser(?BrowserContext $browser = null): void
    {
        static::$browser = $browser ?? static::createBrowser();
        static::bindBrowserContext();
    }

    /**
     * Clears the worker-local browser context.
     *
     * Tests restore the clean unset state between cases without assigning the
     * facade global directly, which project facades narrow to a read-only type.
     */
    public static function resetBrowser(): void
    {
        static::$browser = null;
    }

    /**
     * Gives the active browser context access to this project facade topology.
     */
    private static function bindBrowserContext(): void
    {
        static::$browser?->bindHilosFacade(static::class);
    }

    /**
     * Creates database context instance.
     *
     * @return DbContext Database context instance
     */
    abstract protected static function createDb(): DbContext;

    /**
     * Creates topology validator instance.
     *
     * @return TopologyValidator Topology validator
     */
    protected static function createTopologyValidator(): TopologyValidator
    {
        return new TopologyValidator();
    }

    /**
     * Creates environment accessor.
     *
     * @return EnvAccessor Environment accessor
     */
    protected static function createEnv(): EnvAccessor
    {
        return new EnvAccessor(static::ENV_CATALOG);
    }

    /**
     * Creates the LLM profile router.
     *
     * @return LlmRouter LLM profile router
     */
    protected static function createLlm(): LlmRouter
    {
        $override = static::LLM_PROFILE_OVERRIDE !== null
            ? new (static::LLM_PROFILE_OVERRIDE)()
            : null;

        return new LlmRouter(static::LLM_PROFILE_CATALOG, $override);
    }

    /**
     * Creates the cluster context bound to the active environment accessor.
     *
     * @return ClusterContext Cluster mode and node-identity context
     */
    protected static function createCluster(): ClusterContext
    {
        return new ClusterContext();
    }

    /**
     * Creates settings accessor.
     *
     * @return SettingsAccessor Settings accessor
     */
    protected static function createSetting(): SettingsAccessor
    {
        return new SettingsAccessor(static::SETTINGS_CATALOG);
    }

    /**
     * Creates runtime context instance.
     *
     * @return ?RtContext Runtime context or null if not used
     */
    protected static function createRuntime(): ?RtContext
    {
        return null;
    }

    /**
     * Creates table context instance.
     *
     * @return ?TableContext Table context or null if not used
     */
    protected static function createTable(): ?TableContext
    {
        return null;
    }

    /**
     * Creates browser-facing state context instance.
     *
     * @return ?BrowserContext Browser context or null if not used
     */
    protected static function createBrowser(): ?BrowserContext
    {
        return null;
    }

    /**
     * Creates filesystem context instance.
     *
     * @return ?FsContext Filesystem context or null if not used
     */
    protected static function createFs(): ?FsContext
    {
        return null;
    }

}

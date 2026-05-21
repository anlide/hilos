<?php

declare(strict_types=1);

namespace Hilos;

use Hilos\Core\Analytics\AnalyticsCollector;
use Hilos\Core\Agent\AbstractAgent;
use Hilos\Core\Agent\Config\AgentSignalConfigKey;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Group\AbstractGroup;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Topology\Exception\InvalidTopologyException;
use Hilos\Core\Topology\TopologyValidator;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Environment\EnvAccessor;
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
 */
abstract class Hilos
{
    /** Page classes keyed by page name. */
    public const array PAGES = [];

    /** Agent classes keyed by agent type. */
    public const array AGENTS = [];

    /** Group classes keyed by group name. */
    public const array GROUPS = [];

    /** Registered table definition classes keyed by table name. */
    public const array TABLES = [];

    /** Browser-only table config classes keyed by table name. */
    public const array BROWSER_TABLES = [];

    /** Page table bindings keyed by page name, then table name. */
    public const array PAGE_TABLES = [];

    /** @var ?EnvAccessor Catalog-backed environment accessor */
    public static ?EnvAccessor $env = null;

    /** @var ?DbContext Database layer singleton */
    public static ?DbContext $db = null;

    /** @var ?SettingsAccessor Catalog-backed settings accessor */
    public static ?SettingsAccessor $setting = null;

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
     * Returns page-owned non-action signal routes declared by registered page classes.
     *
     * Empty signal name lists declare a type-wide route; non-empty lists declare
     * named routes for that signal type.
     *
     * @return array<string, string|array<string, string>> Page route keyed by signal type, then signal name
     */
    public static function getPageSignalRoutes(): array
    {
        $signalRoutes = [];
        foreach (static::PAGES as $page => $pageClass) {
            if (!is_string($page) || !is_string($pageClass) || !is_subclass_of($pageClass, AbstractPage::class)) {
                continue;
            }

            foreach ($pageClass::SIGNALS as $signalType => $signalNames) {
                if (!is_string($signalType) || $signalType === '' || !is_array($signalNames)) {
                    continue;
                }

                if ($signalNames === []) {
                    if (!array_key_exists($signalType, $signalRoutes)) {
                        $signalRoutes[$signalType] = $page;
                    }
                    continue;
                }

                if (isset($signalRoutes[$signalType]) && is_string($signalRoutes[$signalType])) {
                    continue;
                }

                if (!isset($signalRoutes[$signalType])) {
                    $signalRoutes[$signalType] = [];
                }

                foreach ($signalNames as $signalName) {
                    if (!is_string($signalName) || $signalName === '') {
                        continue;
                    }

                    $signalRoutes[$signalType][$signalName] = $page;
                }
            }
        }

        return $signalRoutes;
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
     * - string-keyed array entry → indexed route (agentIndex from INDEX_FIELD payload field)
     *
     * @return array<string, string> Agent type keyed by signal name
     */
    public static function getAgentSignalRoutes(): array
    {
        $signalRoutes = [];
        foreach (static::AGENTS as $agentType => $agentClass) {
            if (!is_string($agentType) || !is_string($agentClass) || !is_subclass_of($agentClass, AbstractAgent::class)) {
                continue;
            }

            foreach ($agentClass::AGENT_SIGNALS as $key => $value) {
                if (is_int($key) && is_string($value) && $value !== '') {
                    $signalRoutes[$value] = $agentType;
                    continue;
                }

                if (is_string($key) && $key !== '' && is_array($value)) {
                    $signalRoutes[$key] = $agentType;
                }
            }
        }

        return $signalRoutes;
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
        $indexFields = [];
        foreach (static::AGENTS as $agentType => $agentClass) {
            if (!is_string($agentType) || !is_string($agentClass) || !is_subclass_of($agentClass, AbstractAgent::class)) {
                continue;
            }

            foreach ($agentClass::AGENT_SIGNALS as $key => $value) {
                if (!is_string($key) || $key === '' || !is_array($value)) {
                    continue;
                }

                $indexField = $value[AgentSignalConfigKey::INDEX_FIELD] ?? null;
                if (is_string($indexField) && $indexField !== '') {
                    $indexFields[$key] = $indexField;
                }
            }
        }

        return $indexFields;
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
        return new EnvAccessor();
    }

    /**
     * Creates settings accessor.
     *
     * @return SettingsAccessor Settings accessor
     */
    protected static function createSetting(): SettingsAccessor
    {
        return new SettingsAccessor();
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

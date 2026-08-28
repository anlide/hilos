<?php

declare(strict_types=1);

namespace Hilos;

use Hilos\Auth\CodeChannel\CodeChannelRegistry;
use Hilos\Cluster\ClusterContext;
use Hilos\Constants\CommandConstants;
use Hilos\Core\Analytics\AnalyticsCollector;
use Hilos\Core\Browser\Context\BrowserContext;
use Hilos\Core\Catalog\CatalogProviderInterface;
use Hilos\Core\CLI\CliApplication;
use Hilos\Core\CLI\CliManager;
use Hilos\Core\CLI\Commands\UserTestSeedCommand;
use Hilos\Core\Exception\LogicException;
use Hilos\Core\Feature\DeferredFeatureRequirementsValidator;
use Hilos\Core\Feature\Exception\FeatureRuntimeOverwrittenException;
use Hilos\Core\Feature\Exception\IncompleteFeatureActivationException;
use Hilos\Core\Feature\FeatureActivationValidator;
use Hilos\Core\Feature\FeatureDefinition;
use Hilos\Core\Feature\FeatureRegistry;
use Hilos\Core\Feature\FeatureRequirements;
use Hilos\Core\Feature\HilosFeature;
use Hilos\Core\Group\AbstractGroup;
use Hilos\Core\Group\GroupNameMatch;
use Hilos\Core\Page\AbstractPage;
use Hilos\Core\Page\Config\PageAgentIndexRoute;
use Hilos\Core\Router\DTO\ActionPayloadDTO;
use Hilos\Core\Router\SignalDataInterface;
use Hilos\Core\Router\SignalRouter;
use Hilos\Core\Source\SourceChangeBus;
use Hilos\Core\Source\Subscriber\OutboundRtSyncSubscriber;
use Hilos\Core\Source\Subscriber\ViewCacheSubscriber;
use Hilos\Core\Table\Context\TableContext;
use Hilos\Core\Topology\Exception\InvalidTopologyException;
use Hilos\Core\Topology\AgentActionRouteRegistry;
use Hilos\Core\Topology\AgentCommandRouteRegistry;
use Hilos\Core\Topology\AgentSignalRouteRegistry;
use Hilos\Core\Topology\PageAgentIndexRouteRegistry;
use Hilos\Core\Topology\PageSignalRouteRegistry;
use Hilos\Core\Topology\TopologyValidator;
use Hilos\Database\Context\DbContext;
use Hilos\Database\Pages\PageCatalogProviderInterface;
use Hilos\Database\Pages\PageCatalogResolver;
use Hilos\Database\Pages\PageCatalogStub;
use Hilos\Database\Settings\SettingsAccessor;
use Hilos\Database\Settings\SettingsCatalogStub;
use Hilos\Environment\EnvAccessor;
use Hilos\Environment\EnvCatalogStub;
use Hilos\LLM\Routing\LlmProfileCatalogStub;
use Hilos\LLM\Routing\LlmProfileOverrideSource;
use Hilos\LLM\Routing\LlmRouter;
use Hilos\Environment\Exception\EnvInvalidValueException;
use Hilos\Fs\Context\FsContext;
use Hilos\Mail\HilosMailer;
use Hilos\Sms\HilosSmsSender;
use Hilos\Notification\Delivery\DeliveryChannelRegistry;
use Hilos\Notification\HilosNotifier;
use Hilos\Notification\NotificationTypeRegistry;
use Hilos\ProtectedMode\ProtectedModeStubConstants;
use Hilos\ProtectedMode\ProtectedModeStubCopy;
use Hilos\Runtime\Exception\Rt\StateCollectionNotFoundException;
use Hilos\Runtime\View\Context\RtContext;
use Hilos\Socket\Command\TestOnlyCommandRegistry;
use Hilos\Users\AdminAudience;

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
 * - Hilos::$notify     — durable notification emit seam
 */
abstract class Hilos
{
    /** @var class-string<CatalogProviderInterface> Environment catalog provider class. */
    protected const string ENV_CATALOG = EnvCatalogStub::class;

    /** @var class-string<CatalogProviderInterface> Settings catalog provider class. */
    protected const string SETTINGS_CATALOG = SettingsCatalogStub::class;

    /**
     * Page catalog provider class: the project's own admin page identity.
     *
     * The framework's own admin pages are carried by the framework catalog and need no
     * declaration; the stub adds nothing, so a project that owns no admin pages leaves this
     * alone.
     *
     * @var class-string<PageCatalogProviderInterface>
     */
    protected const string PAGE_CATALOG = PageCatalogStub::class;

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

    /**
     * Delivery-channel registry class (HIL-196).
     *
     * The dispatcher folded into {@see HilosNotifier::emit()} reads the enabled
     * delivery channels through this class. The framework default is the empty base,
     * so no channel delivers until a project points this at its own subclass; the
     * project adds channels by overriding the registry's channel map.
     *
     * @var class-string<DeliveryChannelRegistry>
     */
    protected const string NOTIFICATION_CHANNEL_REGISTRY = DeliveryChannelRegistry::class;

    /**
     * Code-channel registry class (HIL-492).
     *
     * The channels a one-time login code may be sent over. The framework default is
     * the empty base, so a project that points this nowhere offers no phone codes at
     * all; a project points it at its own subclass and the auth surface draws whatever
     * it registered, in registry order.
     *
     * Deliberately separate from {@see NOTIFICATION_CHANNEL_REGISTRY}: a notification
     * channel addresses a recipient by user id and honors their stored preferences,
     * while a login code goes to a stranger addressed by the number they just typed.
     *
     * @var class-string<CodeChannelRegistry>
     */
    protected const string CODE_CHANNEL_REGISTRY = CodeChannelRegistry::class;

    /**
     * Notification-type registry class (HIL-485).
     *
     * The dispatcher reads whether a notification type is mandatory (bypasses
     * per-user channel preferences) through this class. The framework default is the
     * empty base, treating every type as non-mandatory; a project points this at its
     * own subclass to declare mandatory types.
     *
     * @var class-string<NotificationTypeRegistry>
     */
    protected const string NOTIFICATION_TYPE_REGISTRY = NotificationTypeRegistry::class;

    /**
     * Admin audience class (HIL-279).
     *
     * Framework code that must notify the administrators from outside a browser request
     * reads them through this class. The framework default is the empty base, so a project
     * that declares nothing is notified about nothing; a project points this at its own
     * subclass to name its administrators.
     *
     * @var class-string<AdminAudience>
     */
    protected const string ADMIN_AUDIENCE = AdminAudience::class;

    /**
     * Words of the maintenance surface shown while protected mode holds the node (HIL-268).
     *
     * Keyed by the operation name recorded on the freeze row, plus a
     * {@see ProtectedModeStubConstants::DEFAULT_OPERATION} entry used by any operation that
     * registered none of its own. The framework ships the default; a project overrides the
     * constant WHOLESALE to speak in its own voice - the entries are not merged with the
     * framework's, so an override that drops the default key would leave every unregistered
     * operation without words. That is refused at startup by {@see TopologyValidator}, together
     * with an entry that is not an array, misses either copy field, or carries a field beyond
     * them (HIL-555); until then this paragraph was the only thing standing between an override
     * and a wordless maintenance screen. Unlike {@see NOTIFICATION_CHANNEL_REGISTRY} and
     * {@see BACKUP_CATALOG}, which name a class, this constant carries the content itself:
     * the copy is one sentence per operation and has nowhere else to live.
     *
     * Deliberately not a settings row: the database is exactly what a restore is rewriting,
     * so copy read from it during the mode is unreliable by construction.
     *
     * @var array<string, array{title: string, message: string}>
     */
    protected const array PROTECTED_MODE_STUB = [
        ProtectedModeStubConstants::DEFAULT_OPERATION => [
            ProtectedModeStubConstants::TITLE => 'Maintenance in progress',
            ProtectedModeStubConstants::MESSAGE => 'The application is briefly unavailable while'
                . ' a maintenance operation finishes. It will come back on its own.',
        ],
    ];

    /**
     * Framework features this project is built with.
     *
     * The single statement of activation: a feature is on because it is listed here, and off
     * because it is not. The default is empty - a project takes nothing it did not ask for.
     * What each declared feature obliges the project to register is checked at startup, so
     * the list cannot drift away from the registries that carry it.
     *
     * This is not the deployment switch. FEATURES says the feature is built into the project;
     * env values such as BACKUP_ENABLED say whether it is turned on at this installation.
     *
     * @var list<HilosFeature>
     */
    protected const array FEATURES = [];

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

    /**
     * Concrete project facade class captured at init.
     *
     * Bare `Hilos::` accessor calls made from framework code lose late static
     * binding to the project subclass, so `static::` there reads base constants
     * instead of the project override. Accessors that must resolve
     * project-overridden topology constants read {@see appClass()} instead.
     *
     * Captured by {@see initEnv()}, which every process spine runs before anything else.
     *
     * @var class-string<Hilos> Concrete project facade class, or the base when uninitialized
     */
    private static string $appClass = self::class;

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

    /** @var ?HilosNotifier Durable notification emit seam singleton */
    public static ?HilosNotifier $notify = null;

    /** @var ?HilosMailer Mail send seam singleton */
    public static ?HilosMailer $mail = null;

    /** @var ?HilosSmsSender SMS send seam singleton */
    public static ?HilosSmsSender $sms = null;

    /**
     * Returns the project's backup catalog provider class, or null when backup is unconfigured.
     *
     * The backup subsystem reads the reference-object registry, the schedule and the PII
     * registry through this class; the facade only exposes which catalog to read, leaving
     * catalog interpretation to the backup layer's own registries.
     *
     * Resolved through {@see appClass()}, not `static::`: every caller is framework code
     * writing a bare `Hilos::getBackupCatalogClass()`, which binds `static` to this base
     * class and reads its own null - so the answer was null however a project declared
     * itself (HIL-275).
     *
     * @return ?class-string<CatalogProviderInterface> Backup catalog provider class
     */
    public static function getBackupCatalogClass(): ?string
    {
        return static::appClass()::BACKUP_CATALOG;
    }

    /**
     * Returns the project's page catalog provider class.
     *
     * The framework half of the admin catalog is a constant nobody declares, so this names only
     * the project's own entries; {@see PageCatalogResolver} merges the two.
     *
     * Resolved through {@see appClass()} for the same reason as {@see getBackupCatalogClass()}:
     * every caller is framework code writing a bare `Hilos::getPageCatalogClass()`, which binds
     * `static` to this base class and would read the stub however a project declared itself.
     *
     * @return class-string<PageCatalogProviderInterface> Page catalog provider class
     */
    public static function getPageCatalogClass(): string
    {
        return static::appClass()::PAGE_CATALOG;
    }

    /**
     * Creates a fixture user with the given display name and returns its id, or null
     * when the project does not support seeding users.
     *
     * Minimal seam for the test-only {@see UserTestSeedCommand}:
     * the user table is still project-owned, so the framework cannot create a user row
     * itself. The base returns null — a project that never wired the seam (a demo with
     * no user table) cannot be seeded, and the command reports that instead of failing.
     * A project overrides this to register its users collection as a truth source and
     * create the row. Kept deliberately narrow (one display name in, an id out) so it
     * dissolves in a single line once the user table moves into the framework, rather
     * than growing into an API. Resolve through {@see appClass()} so a bare `Hilos::`
     * call-site reaches the project override.
     *
     * @param string $displayName Display name for the seeded user
     * @return ?int Created user id, or null when the project does not support fixture users
     * @throws HilosException Whatever the project's seeding of the user row raises
     */
    public static function createFixtureUser(string $displayName): ?int
    {
        return null;
    }

    /**
     * Returns the concrete project facade class captured at init.
     *
     * Framework code reaches project-overridden topology constants through this
     * class rather than `static::`, which bare `Hilos::` call-sites bind to the
     * base facade instead of the project subclass.
     *
     * @return class-string<Hilos> Concrete project facade class, or the base when uninitialized
     */
    public static function appClass(): string
    {
        return static::$appClass;
    }

    /**
     * Returns the project's delivery-channel registry class (HIL-196).
     *
     * @return class-string<DeliveryChannelRegistry> Delivery-channel registry class
     */
    public static function notificationChannelRegistryClass(): string
    {
        return static::appClass()::NOTIFICATION_CHANNEL_REGISTRY;
    }

    /**
     * Returns the project's code-channel registry class (HIL-492).
     *
     * @return class-string<CodeChannelRegistry> Code-channel registry class
     */
    public static function codeChannelRegistryClass(): string
    {
        return static::appClass()::CODE_CHANNEL_REGISTRY;
    }

    /**
     * Returns the project's notification-type registry class (HIL-485).
     *
     * @return class-string<NotificationTypeRegistry> Notification-type registry class
     */
    public static function notificationTypeRegistryClass(): string
    {
        return static::appClass()::NOTIFICATION_TYPE_REGISTRY;
    }

    /**
     * Returns the project's admin audience class (HIL-279).
     *
     * @return class-string<AdminAudience> Admin audience class
     */
    public static function adminAudienceClass(): string
    {
        return static::appClass()::ADMIN_AUDIENCE;
    }

    /**
     * Returns the project's protected-mode stub registry (HIL-268).
     *
     * The facade only hands over the entries; picking one for the running operation and falling
     * back to the default is {@see ProtectedModeStubCopy}'s job, the same split
     * {@see getBackupCatalogClass()} makes between naming a catalog and reading it.
     *
     * @return array<string, array{title: string, message: string}> Stub copy keyed by operation
     */
    public static function protectedModeStubRegistry(): array
    {
        return static::appClass()::PROTECTED_MODE_STUB;
    }

    /**
     * Returns the framework features this project declared.
     *
     * @return list<HilosFeature> Declared features, empty when the project takes none
     */
    public static function features(): array
    {
        return static::featuresOf(static::appClass());
    }

    /**
     * Tells whether the project declared a feature.
     *
     * The one way to ask whether a feature is on. Nothing else answers that question: an
     * artifact that happens to exist - a mounted runtime row, a registered page, a non-null
     * catalog constant - says only that the project registered it, which is exactly the
     * inference this registry replaced.
     *
     * No framework gate reads it yet: the four that were to be converted turned out to belong
     * to node freeze, which is unconditional and stays outside the registry (HIL-537 owns its
     * activation contract). It is a static on the facade because the gates that will read it -
     * the daemon manager, the socket client, the browser context - live where there is nothing
     * to inject into, and it is answered from constants alone, so it also holds before any
     * layer exists.
     *
     * Resolved through {@see appClass()} for the usual reason: a bare `Hilos::hasFeature()`
     * call from framework code binds `static::` to this base class, which declares no
     * features at all, and every such gate would answer no.
     *
     * @param HilosFeature $feature Feature to ask about
     * @return bool True when the project declared the feature
     */
    public static function hasFeature(HilosFeature $feature): bool
    {
        return in_array($feature, static::featuresOf(static::appClass()), true);
    }

    /**
     * Reads the feature declaration of a facade class that is not the running one.
     *
     * The activation validators need exactly this: they judge a facade class before it is the
     * running facade, and the declaration is a protected constant on purpose - a statement the
     * project makes to the framework, not API for callers (HIL-513). From outside the class
     * nothing answers it, so the read lives here, inside the scope that owns the constant,
     * and FEATURES stays unreadable to everyone else.
     *
     * @param class-string<Hilos> $hilosClass Facade class to read the declaration off
     * @return list<HilosFeature> Declared features, empty when the project takes none
     */
    public static function featuresOf(string $hilosClass): array
    {
        return $hilosClass::FEATURES;
    }

    /**
     * Reads a catalog constant off a facade class that is not the running one.
     *
     * Null for a constant the class does not declare, rather than a fatal: the name arrives
     * from {@see FeatureRequirements::$requiredCatalogConstant}, so a typo there must surface
     * as a named activation error - the feature points at no project catalog - and not as an
     * `Error` thrown out of the constant read.
     *
     * The second reader is {@see TopologyValidator}, which judges {@see PROTECTED_MODE_STUB}
     * through here for the reason the reader exists at all: the constant is protected, so a
     * `defined()` call from the validator's own scope answers false, and a rule reading it there
     * would judge an empty registry and refuse every project at startup (HIL-555).
     *
     * @param class-string<Hilos> $hilosClass Facade class to read the constant off
     * @param string $constant Catalog constant name
     * @return mixed Constant value, or null when the class declares no such constant
     */
    public static function catalogConstantOf(string $hilosClass, string $constant): mixed
    {
        return defined("{$hilosClass}::{$constant}") ? constant("{$hilosClass}::{$constant}") : null;
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
     * Returns per-instance page routes declared by registered page classes.
     *
     * Only pages that declare {@see AbstractPage::SUBSCRIPTION_AGENT_INDEX} appear; a page
     * absent from the map is served by its subscription agent type, as every page was
     * before per-instance routing existed. Malformed declarations are skipped here and
     * reported by topology validation.
     *
     * @return array<string, PageAgentIndexRoute> Per-instance route keyed by page name
     */
    public static function getPageAgentIndexRoutes(): array
    {
        return PageAgentIndexRouteRegistry::routes(static::PAGES);
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
     * Returns the registered group classes, keyed by the name each of them declares.
     *
     * The registry as the group layer reads it: {@see getGroupRoutes()} answers "who owns
     * this group", this one answers "which class IS this group", and a join needs the class -
     * it is what judges admission and builds the answer. Invalid registry entries are skipped
     * here and reported by topology validation.
     *
     * The name a class declares carries no param; a name off the wire is matched against this
     * map by {@see GroupNameMatch::resolve()}, exactly first and by its head after that.
     *
     * @return array<string, class-string<AbstractGroup>> Group class keyed by declared group name
     */
    public static function getGroupClasses(): array
    {
        $groupClasses = [];
        foreach (static::GROUPS as $group => $groupClass) {
            if (!is_string($group) || !is_string($groupClass) || !is_subclass_of($groupClass, AbstractGroup::class)) {
                continue;
            }

            $groupClasses[$group] = $groupClass;
        }

        return $groupClasses;
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
            $agentType = $pageRoutes[$page] ?? null;
            if ($agentType === null) {
                continue;
            }

            $actionAgentRoutes[$action] = $agentType;
        }

        return $actionAgentRoutes;
    }

    /**
     * Returns CLI command owner agent types keyed by command name.
     *
     * Aggregated from AGENT_COMMANDS declared by registered agent classes: a
     * command received over the command socket channel routes to the agent that
     * declares it. A project may still override this to add non-agent routes.
     *
     * @return array<string, string> Agent type keyed by command name
     */
    public static function getCommandAgentRoutes(): array
    {
        return AgentCommandRouteRegistry::routes(static::AGENTS);
    }

    /**
     * Returns agent-owned command inner payload DTO classes.
     *
     * List-style command name entries declare routing only. Map-style entries with
     * class-string values (or an AgentCommandConfigKey::DTO config array) declare
     * both routing and the inner payload DTO class hydrated at dispatch time.
     *
     * @return array<string, class-string<SignalDataInterface>> DTO class keyed by command name
     */
    public static function getCommandDtoRoutes(): array
    {
        return AgentCommandRouteRegistry::dtoRoutes(static::AGENTS);
    }

    /**
     * Returns the agent-owned commands this project declares test-only.
     *
     * Half of what {@see TestOnlyCommandRegistry} asks; the master's own three come from
     * {@see CommandConstants::MASTER_TEST_ONLY_COMMANDS}. Like its neighbours it answers off
     * `static::AGENTS`, so a bare `Hilos::` call-site reads the base facade's empty registry -
     * framework callers resolve the project subclass through {@see appClass()} first.
     *
     * @return list<string> Command names flagged test-only by a registered agent
     */
    public static function getTestOnlyCommands(): array
    {
        return AgentCommandRouteRegistry::testOnlyCommands(static::AGENTS);
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
                $agentType = $pageRoutes[$route] ?? null;
                if ($agentType !== null) {
                    $signalAgentRoutes[$signalType] = $agentType;
                }
                continue;
            }

            $signalAgentRoutes[$signalType] = [];
            foreach ($route as $signalName => $page) {
                $agentType = $pageRoutes[$page] ?? null;
                if ($agentType !== null) {
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
     * Also subscribes the framework to its own collection-change announcements, from scratch on
     * every call. The registration is one for all process roles - what a subscriber does with a
     * fact depends on the fact, not on who is running - and the order it is written in is the
     * order the subscribers are called: the view is repaired before the outgoing sync collects
     * its payload, so nothing reads a collection that still holds a row it lost.
     *
     * @throws InvalidTopologyException When project topology constants are inconsistent
     * @throws IncompleteFeatureActivationException When a declared feature is not fully activated
     * @throws FeatureRuntimeOverwrittenException When the project re-mounts runtime state a feature owns
     * @throws StateCollectionNotFoundException When a feature represents a collection it did not mount
     * @throws HilosException When a layer factory or configure step cannot initialize its singleton
     */
    public static function init(): void
    {
        static::validateTopology();
        static::validateFeatureActivation();

        if (static::$env === null) {
            static::$env = static::createEnv();
        }

        if (static::$setting === null) {
            static::$setting = static::createSetting();
        }

        if (static::$db === null) {
            static::$db = static::createDb();
            static::$db->configure();
            static::$db->declareProcessWideReads();
            static::$db->refreshDbGeneration();
        }

        if (static::$notify === null) {
            static::$notify = static::createNotifier();
        }

        if (static::$mail === null) {
            static::$mail = static::createMail();
        }

        if (static::$sms === null) {
            static::$sms = static::createSms();
        }

        if (static::$rt === null) {
            static::$rt = static::createRuntime();
            $definitions = static::featureDefinitions();
            if (static::$rt === null) {
                static::refuseRuntimeFeaturesWithoutContext($definitions);
            } else {
                static::$rt->mountFeatureRuntime($definitions);
                static::$rt->configure();
                static::$rt->assertFeatureRuntimeIntact();
                static::$rt->declareProcessWideReads();
                static::$rt->bindStateCollectionNames();
            }
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

        SourceChangeBus::reset();
        SourceChangeBus::subscribe(new ViewCacheSubscriber());
        SourceChangeBus::subscribe(new OutboundRtSyncSubscriber());

        static::validateTopologyReferences();
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
     * Validates browser source references against the layers this node mounted.
     *
     * The half of topology validation that cannot run with the other half: a source key names a
     * collection, and whether that collection exists is only knowable once `$db` and `$rt` are
     * up. Same shape as the pair {@see self::validateFeatureActivation()} and
     * {@see RtContext::assertFeatureRuntimeIntact()} already form around mounting.
     *
     * @throws InvalidTopologyException When a declaration names a collection no layer mounts
     */
    public static function validateTopologyReferences(): void
    {
        static::createTopologyValidator()->validateReferences(static::class);
    }

    /**
     * Returns the definition of every feature this project declared.
     *
     * @return list<FeatureDefinition> Definitions in declaration order
     * @throws IncompleteFeatureActivationException When a declared feature has no definition
     */
    protected static function featureDefinitions(): array
    {
        $registry = static::createFeatureRegistry();

        return array_map(
            static fn(HilosFeature $feature): FeatureDefinition => $registry->definition($feature),
            static::FEATURES,
        );
    }

    /**
     * Refuses a declaration that needs runtime state on a project that builds no runtime context.
     *
     * The check lives here rather than in the activation validator because the answer is not in
     * the constants: whether there is a context at all is known only once createRuntime() has
     * been asked. A feature that brings rows into a project with nowhere to put them is
     * activated in name only, which is the state this whole registry exists to make impossible.
     *
     * @param list<FeatureDefinition> $definitions Definitions of the features the project declared
     * @throws IncompleteFeatureActivationException When a declared feature mounts runtime state and there is no context
     */
    protected static function refuseRuntimeFeaturesWithoutContext(array $definitions): void
    {
        $errors = [];
        foreach ($definitions as $definition) {
            if ($definition->mountsRuntime()) {
                $errors[] = 'HilosFeature::' . $definition->feature()->name
                    . ' brings runtime state, but createRuntime() returned no context to mount it into';
            }
        }

        if ($errors !== []) {
            throw IncompleteFeatureActivationException::forErrors(static::class, $errors);
        }
    }

    /**
     * Validates that every declared framework feature is fully activated.
     *
     * Runs beside {@see validateTopology()} and for the same reason: a registry that disagrees
     * with the declaration is a fault of the project's composition, and composition faults are
     * cheapest to see at startup. Reads constants only, so it holds before any layer exists.
     *
     * @throws IncompleteFeatureActivationException When a declared feature is incomplete or an undeclared one is registered
     */
    public static function validateFeatureActivation(): void
    {
        static::createFeatureActivationValidator()->validate(static::class);
    }

    /**
     * Validates the feature requirements that startup deliberately does not check.
     *
     * Not called from {@see init()} and not meant to be: migrations are applied as a separate
     * step, so a process that starts before the migration run would fail on a table it is about
     * to get, and the CLI manager and the runtime context are built later than the constants
     * check or not at all. Each project's own unit test calls this instead - one place where the
     * whole layout is knowable and nothing is running.
     *
     * The three arguments are exactly what the facade does not own: its migration directory, the
     * CLI manager its entry point passes to {@see CliApplication}, and the runtime context class
     * behind {@see createRuntime()}.
     *
     * @param string $migrationsPath Directory holding this project's schema migrations
     * @param class-string<CliManager> $cliManagerClass CLI manager this project's entry point runs
     * @param ?class-string<RtContext> $rtContextClass Runtime context this project builds, or null when it builds none
     * @throws IncompleteFeatureActivationException When a declared feature misses a table, a command or a presence
     *     source, or when a project that serves pages keeps its connections off the framework base
     * @throws LogicException When the PCRE engine refuses to strip a migration file's comments
     * @throws StateCollectionNotFoundException When building the runtime context represents an unmounted collection
     */
    public static function validateDeferredFeatureRequirements(
        string $migrationsPath,
        string $cliManagerClass,
        ?string $rtContextClass,
    ): void {
        static::createDeferredFeatureRequirementsValidator()->validate(
            static::class,
            $migrationsPath,
            $cliManagerClass,
            $rtContextClass,
        );
    }

    /**
     * Initializes the environment accessor before storage and daemon layers.
     *
     * @param ?string $rootPath Directory that contains .env and .env.example
     */
    public static function initEnv(?string $rootPath = null): void
    {
        // The earliest point every process spine passes through while still naming the project
        // facade, and therefore where {@see appClass()} has to be captured. Capturing it in
        // init() alone was too late for the processes that never call it: the master daemon
        // reaches it only through the project's Database::initialize(), and a CLI command
        // marked DatabaseFreeCommand skips that connect entirely - so framework code asking a
        // bare `Hilos::` question there read the base facade's empty registries.
        static::$appClass = static::class;

        if (static::$env === null) {
            static::$env = static::createEnv();
        }

        static::$env->init($rootPath);

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
     *
     * Also captures the concrete project facade class so framework code can
     * resolve project-overridden topology constants through {@see appClass()}
     * from bare `Hilos::` call-sites that lose late static binding.
     */
    private static function bindBrowserContext(): void
    {
        static::$appClass = static::class;
        static::$browser?->bindHilosFacade(static::class);
    }

    /**
     * Creates database context instance.
     *
     * @return DbContext Database context instance
     */
    abstract protected static function createDb(): DbContext;

    /**
     * Creates the durable notification emit seam.
     *
     * Override to return a project HilosNotifier subclass (e.g. one that also fans
     * to channel-delivery agents in HIL-196+).
     *
     * @return HilosNotifier Notification emit seam
     */
    protected static function createNotifier(): HilosNotifier
    {
        return new HilosNotifier();
    }

    /**
     * Creates the mail send seam.
     *
     * Override to return a project HilosMailer subclass (e.g. one that routes raw-send
     * through a custom pool or records an audit trail).
     *
     * @return HilosMailer Mail send seam
     */
    protected static function createMail(): HilosMailer
    {
        return new HilosMailer();
    }

    /**
     * Creates the SMS send seam.
     *
     * Override to return a project HilosSmsSender subclass (e.g. one that routes raw-send
     * through a custom provider or records an audit trail).
     *
     * @return HilosSmsSender SMS send seam
     */
    protected static function createSms(): HilosSmsSender
    {
        return new HilosSmsSender();
    }

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
     * Creates the registry of framework feature definitions.
     *
     * Override to hand the activation validator and the runtime context a synthetic set of
     * features; the real six are the default.
     *
     * @return FeatureRegistry Feature definition registry
     */
    protected static function createFeatureRegistry(): FeatureRegistry
    {
        return new FeatureRegistry();
    }

    /**
     * Creates the startup feature activation validator over this project's feature registry.
     *
     * @return FeatureActivationValidator Feature activation validator
     */
    protected static function createFeatureActivationValidator(): FeatureActivationValidator
    {
        return new FeatureActivationValidator(static::createFeatureRegistry());
    }

    /**
     * Creates the deferred feature requirements validator over this project's feature registry.
     *
     * @return DeferredFeatureRequirementsValidator Deferred feature requirements validator
     */
    protected static function createDeferredFeatureRequirementsValidator(): DeferredFeatureRequirementsValidator
    {
        return new DeferredFeatureRequirementsValidator(static::createFeatureRegistry());
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

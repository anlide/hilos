# App Topology Registry

Read this before adding or changing project pages, agents, page subscription
routing, page/agent signal routing, registered tables, browser-only tables, or
page-table browser bindings.

## Core Rule

Declare application topology in the project `Hilos` facade registry and
page/agent-owned constants. Do not rebuild local page, agent, route, or table
lists in page factories, browser contexts, signal routers, worker managers, or
table contexts when they can read the project registry.

## Registry

- `Hilos::PAGES` registers page classes keyed by each page class `::PAGE`
  value.
- `Hilos::GROUPS` registers group classes keyed by each group class `::GROUP`
  value.
- `Hilos::AGENTS` registers agent runtime bindings keyed by each worker class
  `::AGENT_TYPE` value. Each entry declares `AgentRegistryKey::WORKER`,
  `AgentRegistryKey::DAEMON`, and optionally `AgentRegistryKey::INDEXED` (a
  sharded pool keyed by `agentIndex`) or `AgentRegistryKey::PER_NODE` (an
  every-node singleton started on each node as its workers become ready). The
  two placement flags are mutually exclusive. `TopologyAgentFactory` creates
  worker and daemon instances from this registry.
- Each page class declares its page subscription owner in
  `PageClass::SUBSCRIPTION_AGENT_TYPE`. `Hilos::getPageRoutes()` computes the
  page-to-agent routing map from `Hilos::PAGES` and those page-level constants.
  `SignalRouter` resolves page subscription signals from that computed registry
  through the active project Hilos facade; project routers should not rebuild
  the page-to-agent list in config.
- A page whose surface belongs to ONE entity may additionally declare
  `PageClass::SUBSCRIPTION_AGENT_INDEX`, and is then served by the agent of that
  instance rather than by the agent of a type. The declaration names where the index
  comes from (`PageAgentIndexKey::SOURCE` — a subscription param or the connection's
  user), the param when there is one, and the `FALLBACK_AGENT_TYPE` that takes the
  subscription when no instance can be determined. `Hilos::getPageAgentIndexRoutes()`
  computes the per-instance map; the master resolves the address once, on subscribe, and
  every later signal of that subscription is addressed off the subscription record. An
  empty declaration — the default — means the page routes by type exactly as before. See
  [signals/routing.md](signals/routing.md) for the full behavior and its consequences.
- Each group class declares its group subscription owner in
  `GroupClass::SUBSCRIPTION_AGENT_TYPE`. `Hilos::getGroupRoutes()` computes the
  group-to-agent routing map from `Hilos::GROUPS` and those group-level constants.
  `SignalRouter` resolves group subscription signals from that computed registry
  through the active project Hilos facade; project routers should not rebuild
  the group-to-agent list in config.
- Each page class declares WebSocket actions it owns in `PageClass::ACTIONS` as
  `action name => ActionPayloadDTO class`. `Hilos::getPageActionRoutes()`
  computes `action -> page`, `Hilos::getActionDtoRoutes()` computes
  `action -> payload DTO class`, and `Hilos::getActionAgentRoutes()` derives
  `action -> agent` through the owning page's `SUBSCRIPTION_AGENT_TYPE`.
  Signal routers, worker page routers, and WebSocket client action allowlists
  should read these computed registries instead of owning duplicate action lists.
  `HilosPageFactory` resolves page instances and parses action payloads from the
  same registry; projects should not add a local page factory when
  `HilosPageFactory` can read the project facade.
- Each page class declares non-action page-dispatched signals in
  `PageClass::SIGNALS`. `Hilos::getPageSignalRoutes()` computes
  `signal type/name -> page` routes for `PageSignalRouter`.
  Named routes may use a list of signal name strings (routing only) or a map
  of `signal name => SignalDataInterface` class (routing plus inner payload
  DTO). `Hilos::getPageSignalDtoRoutes()` computes
  `signal type/name -> inner payload DTO class` for map-style entries.
  `SignalRouter` derives the matching `signal type/name -> agent` routes
  through `Hilos::getPageSignalAgentRoutes()` and `SUBSCRIPTION_AGENT_TYPE`.
- Each agent class declares directly handled agent-to-agent signal names in
  `AgentClass::AGENT_SIGNALS`. `Hilos::getAgentSignalRoutes()` computes
  `agent signal name -> agent` routes. Named entries may be list-style strings
  (routing only), map-style `signal name => SignalDataInterface` class (routing
  plus inner payload DTO), or indexed config arrays with
  `AgentSignalConfigKey::INDEX_FIELD` and optional `AgentSignalConfigKey::DTO`.
  `Hilos::getAgentSignalDtoRoutes()` computes
  `agent signal name -> inner payload DTO class` for map-style and indexed
  entries. `SignalRouter::createAgentSignalPayloadDTO()` parses agent-owned
  signals before dispatch; `PageSignalRouter` uses
  `HilosPageFactory::createPageSignalPayloadDTO()` for page-routed agent
  signals.
- `Hilos::TABLES` registers server table definition classes keyed by table
  name.
- `Hilos::BROWSER_TABLES` registers browser-only table config classes keyed by
  each table class `::TABLE` value.
- A page may declare **no** browser subscription at all, and most do not: leave
  `BROWSER[BrowserConfigKey::SIGNAL]` unnamed and the page simply has no browser
  data (`AbstractPage::BROWSER` defaults to none — the About, Terms, Privacy and
  License pages are all in this state). That is a state with a name of its own:
  the resolved config carries `signalName === null`. Naming something that is
  not a signal is the other thing entirely, and is refused as a broken
  declaration rather than read as "no subscription"; see
  [page-access-control.md](architecture/page-access-control.md).
- `Hilos::PAGE_TABLES` declares which registered table or browser-only table is
  used by each page, including browser table params. Page classes must not put
  table bindings in `BROWSER[BrowserConfigKey::TABLES]`.

`Hilos::validateTopology()` runs before layer initialization and checks the
registry for missing classes, mismatched keys, duplicate signal ownership,
unknown page/table references, missing page subscription owners, and page-local
table bindings that should live in `Hilos::PAGE_TABLES`.

Each project pins this check with its own unit test,
`testProjectTopologyPassesStartupValidation` in its `*TopologyRegistryTest`,
because the framework's `TopologyValidatorTest` only judges invented fixture
facades.

## Feature Declaration

Framework features — `settings`, `hilos_users`, `backup`, `logs`,
`notifications`, `notification_delivery` — are switched on in one place:

```php
protected const array FEATURES = [
    HilosFeature::SETTINGS,
    HilosFeature::HILOS_USERS,
    HilosFeature::NOTIFICATIONS,
];
```

A feature is on because it is listed there and off because it is not. Nothing
else is an on-switch: registering the page, mounting the runtime row, or adding
the migration does not activate anything by itself, and the framework never
infers activation from an artifact it happens to find. Ask with
`Hilos::hasFeature(HilosFeature::BACKUP)`, never by testing whether some
registry entry or runtime row exists.

`FEATURES` is not a deployment switch. It says the feature is built into this
project; whether it runs at an installation stays with env and settings.

What each declared feature obliges the project to register lives with the
feature, in `Core/Feature/Definition/*Feature.php`, and is checked rather than
remembered:

- `Hilos::validateFeatureActivation()` runs in `init()` beside
  `validateTopology()` and reads constants only. It refuses to start when a
  declared feature is missing its page, agent pair, table, page-table binding,
  catalog constant or a feature it depends on — and equally when such an
  artifact is registered while the feature is *not* declared, which is the same
  half-flipped switch seen from the other side.
- `Hilos::validateDeferredFeatureRequirements($migrationsPath,
  $cliManagerClass, $rtContextClass)` covers what startup deliberately cannot
  see: the SQL tables the feature reads (migrations are applied as a separate
  step, so booting must not depend on them), the CLI commands it is driven by,
  and the presence source behind the users list. Each project calls it from its
  own topology unit test, passing the three layout facts the facade does not
  own.

Runtime state that belongs to a feature is mounted by the framework from the
declaration; a project must not mount it in `configure()`. See
[runtime/rt-context.md](runtime/rt-context.md).

## Workflow

1. For a new page, add the page class to `Hilos::PAGES` using
   `SomePage::PAGE => SomePage::class`.
2. Declare the page subscription owner in the page class:
   `public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CHAT;`.
3. When the page is the surface of one entity instance, declare
   `public const array SUBSCRIPTION_AGENT_INDEX = [...]` naming the index source, its
   param, and the fallback agent type. Leave it as the inherited empty array otherwise.
4. For a new WebSocket group, add the group class to `Hilos::GROUPS` using
   `SomeGroup::GROUP => SomeGroup::class` and declare
   `public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CHAT;` on the group
   class.
5. Declare inbound WebSocket actions owned by that page in
   `public const array ACTIONS = [ActionConstants::NAME => SomeActionDTO::class]`.
   Leave it as the inherited empty array when the page has no actions.
6. Declare page-dispatched non-action signals in
   `public const array SIGNALS = [...]` when the page handles them. For named
   agent-signal routes, prefer `signal name => SignalDataInterface` class over
   routing-only strings when the page handler needs a typed inner payload.
7. For a new agent, add worker and daemon classes to `Hilos::AGENTS` using
   `SomeAgent::AGENT_TYPE => [AgentRegistryKey::WORKER => SomeAgent::class, AgentRegistryKey::DAEMON => SomeAgentDaemon::class]`.
   Set `AgentRegistryKey::INDEXED => true` when creation requires `agentIndex`,
   or `AgentRegistryKey::PER_NODE => true` for an every-node singleton (started
   on each node via `WorkerServer::onInitialWorkersReady()`, e.g. the per-node
   log rotation agent). The two flags cannot be combined.
8. Declare directly handled agent-to-agent signal names in
   `public const array AGENT_SIGNALS = [...]` when the agent owns them. Prefer
   `signal name => SignalDataInterface` class for singleton typed signals. For
   indexed multi-instance agents, use
   `AgentSignalConfigKey::INDEX_FIELD` and optional `AgentSignalConfigKey::DTO`
   in the config array.
9. For a server table definition, add the table class to `Hilos::TABLES`.
10. For a browser-only table config, add the config class to
   `Hilos::BROWSER_TABLES`.
11. For page-shaped browser state, bind the page to its tables in
   `Hilos::PAGE_TABLES`, including any browser params.
12. Make table contexts, browser contexts, signal routers, worker page routers,
    and WebSocket clients read the project registry through their established
    hooks or factory methods instead of adding another local topology list.
    Use `HilosPageFactory` with the project facade class for page routing.
13. **Update the topology registry test snapshot whenever a project registry
    changes** — this is a shared, cross-ticket guard, not optional cleanup. Each
    demo has a `*TopologyRegistryTest` (`ChatTopologyRegistryTest`,
    `TasksTopologyRegistryTest`, `PollTopologyRegistryTest`,
    `ClusterTopologyRegistryTest`) whose hardcoded snapshots — e.g.
    `testComputedPageActionRoutesMatchChatActionOwnership`,
    `testComputedActionAgentRoutesUseOwningPageSubscriptionAgents`,
    `testComputedAgentSignalRoutesMatchChatAgentOwnership`,
    `testAgentSignalDtoRoutesCoverDeclaredAgentSignals` — list every registered
    page, group, agent, action, and signal. Registering a new one (a page, an
    agent, an `ACTIONS` / `SIGNALS` / `AGENT_SIGNALS` entry) leaves the snapshot
    stale and the demo's `test:unit` red until you add the matching line. Run
    `composer run test:unit` for that demo after the change. Because the snapshot
    is shared, a red run may already carry another ticket's missing entries: add
    only your own, and never assume a failing entry is foreign without checking
    it against your own diff — see `docs/agents/testing.md`, "Attributing a red
    snapshot guard".

## Preferred Shape

```php
final class SessionGroup extends AbstractGroup
{
    public const string GROUP = GroupConstants::SESSION;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CHAT;
}

final class MainPage extends AbstractPage
{
    public const string PAGE = PageConstants::MAIN;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CHAT;

    public const array ACTIONS = [
        ChatSignalConstants::MESSAGE => MessageActionDTO::class,
    ];

    public const array SIGNALS = [
        SignalTypeConstants::FRAME_BINARY => [],
        SignalTypeConstants::AGENT_SIGNAL => [
            ChatSignalConstants::MODERATION_RESULT => ModerationResultSignalData::class,
        ],
    ];
}

public const array PAGES = [
    MainPage::PAGE => MainPage::class,
];

public const array GROUPS = [
    SessionGroup::GROUP => SessionGroup::class,
];

final class ChatAgent extends AbstractAgent
{
    public const array AGENT_SIGNALS = [
        ChatSignalConstants::BOT_MESSAGE => BotMessageSignalData::class,
    ];
}

final class BotAgent extends AbstractAgent
{
    public const array AGENT_SIGNALS = [
        ChatSignalConstants::BOT_AGENT_START => [
            AgentSignalConfigKey::INDEX_FIELD => 'botId',
            AgentSignalConfigKey::DTO => BotAgentSignalData::class,
        ],
    ];
}

public const array AGENTS = [
    ChatAgent::AGENT_TYPE => [
        AgentRegistryKey::WORKER => ChatAgent::class,
        AgentRegistryKey::DAEMON => ChatAgentDaemon::class,
    ],
    BotAgent::AGENT_TYPE => [
        AgentRegistryKey::WORKER => BotAgent::class,
        AgentRegistryKey::DAEMON => BotAgentDaemon::class,
        AgentRegistryKey::INDEXED => true,
    ],
];

public const array TABLES = [
    ChatTableContext::users => UsersTable::class,
];

public const array BROWSER_TABLES = [
    MainUsersBrowserTable::TABLE => MainUsersBrowserTable::class,
];

public const array PAGE_TABLES = [
    MainPage::PAGE => [
        MainUsersBrowserTable::TABLE => [],
    ],
];
```

## Anti-Patterns

- Do not duplicate project page lists in a project `BrowserContext`; resolve
  page metadata from `Hilos::PAGES` and table bindings from
  `Hilos::PAGE_TABLES`.
- Do not duplicate project browser-only table lists in a project
  `BrowserContext`; resolve table config from `Hilos::BROWSER_TABLES`.
- Do not put page-table bindings in page `BROWSER` constants; use
  `Hilos::PAGE_TABLES`.
- Do not keep page subscription routing in project `SignalRouter` config when
  the project can compute it through `Hilos::getPageRoutes()`. Override the
  router's project facade hook and keep page ownership on page classes.
- Do not keep group subscription routing in project `SignalRouter` config when
  the project can compute it through `Hilos::getGroupRoutes()`. Override the
  router's project facade hook and keep group ownership on group classes.
- Do not keep WebSocket action routing only in `SignalRouter`, `WorkerManager`,
  or WebSocket client config when the project can compute it through
  `Hilos::getPageActionRoutes()` and `Hilos::getActionAgentRoutes()`.
  Framework `SignalRouter` reads action ownership at dispatch time.
- Do not keep page-dispatched signal routes in project `SignalRouter` config
  when the project can compute them through `Hilos::getPageSignalRoutes()` and
  `Hilos::getPageSignalAgentRoutes()`.
  Framework `SignalRouter` reads page signal ownership at dispatch time.
- Do not keep direct agent-to-agent signal routes in project `SignalRouter`
  config when the project can compute them through `Hilos::AGENTS` and
  `Hilos::getAgentSignalRoutes()`.
  Framework `SignalRouter` reads agent signal ownership at dispatch time.
- Do not add a project page factory that only mirrors `Hilos::PAGES` lookup or
  action, page-signal, or agent-signal DTO parsing. Use `HilosPageFactory`
  with the project facade class and `SignalRouter::createAgentSignalPayloadDTO()`
  for agent-owned signals.
- Do not add project worker/daemon factories that only mirror `Hilos::AGENTS`.
  Use `TopologyAgentFactory` with the project facade class.
- Do not duplicate `getPageSignalDtoRoutes()`, `getAgentSignalDtoRoutes()`, or
  local signal-name-to-DTO maps in project routers, worker managers, or page
  factories when the project Hilos facade already computes them from page and
  agent constants.
- Do not register a page, table, or browser table under a key that differs from
  the class constant that owns that key.

## Out Of Scope

Topology-driven inner payload DTO declarations apply to inbound signals whose
payload shape is owned by a page or agent constant. Do not extend this pattern
to:

- Type-wide page signal routes such as `SignalTypeConstants::FRAME_BINARY => []`
  — the framework envelope DTO is enough; page handlers receive the typed frame
  DTO directly.
- Framework WebSocket lifecycle signals (subscribe, handshake, close).
- DB/RT sync signals — broadcast to all workers; not page- or agent-owned
  topology routes.
- Outbound server→client WebSocket signals — constructed explicitly in code, not
  parsed from raw inbound JSON.

## Contract Gate

The root `AGENTS.md` contract approval gate still applies before
implementation. Stop and ask for explicit confirmation before changing
page `SUBSCRIPTION_AGENT_TYPE` values, page `SUBSCRIPTION_AGENT_INDEX`
declarations, page `SIGNALS`, page-owned `ACTIONS`,
agent `AGENT_SIGNALS`, `Hilos::AGENTS`, `SignalRouter`, `PageSignalRouter`, or
page/worker route config. The confirmation must list the exact pages, agents,
actions, agent routes, signal DTOs, and route declarations that would change.

## Validation

Use `$hilos-testing-cli` to choose the narrowest composer script. For framework
topology validation changes, run `composer run test:framework:unit`. For a
project registry change, run that project's unit tests covering topology.

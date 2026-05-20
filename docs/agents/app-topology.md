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
- `Hilos::AGENTS` registers agent classes keyed by each agent class
  `::AGENT_TYPE` value.
- Each page class declares its page subscription owner in
  `PageClass::SUBSCRIPTION_AGENT_TYPE`. `Hilos::getPageRoutes()` computes the
  page-to-agent routing map from `Hilos::PAGES` and those page-level constants.
  `SignalRouter` resolves page subscription signals from that computed registry
  through the active project Hilos facade; project routers should not rebuild
  the page-to-agent list in config.
- Each page class declares WebSocket actions it owns in `PageClass::ACTIONS`.
  `Hilos::getPageActionRoutes()` computes `action -> page`, and
  `Hilos::getActionAgentRoutes()` derives `action -> agent` through the owning
  page's `SUBSCRIPTION_AGENT_TYPE`. Signal routers, worker page routers, and
  WebSocket client action allowlists should read these computed registries
  instead of owning duplicate action lists.
- Each page class declares non-action page-dispatched signals in
  `PageClass::SIGNALS`. `Hilos::getPageSignalRoutes()` computes
  `signal type/name -> page` routes for `PageSignalRouter`.
  `SignalRouter` derives the matching `signal type/name -> agent` routes
  through `Hilos::getPageSignalAgentRoutes()` and `SUBSCRIPTION_AGENT_TYPE`.
- Each agent class declares directly handled agent-to-agent signal names in
  `AgentClass::AGENT_SIGNALS`. `Hilos::getAgentSignalRoutes()` computes
  `agent signal name -> agent` routes.
- `Hilos::TABLES` registers server table definition classes keyed by table
  name.
- `Hilos::BROWSER_TABLES` registers browser-only table config classes keyed by
  each table class `::TABLE` value.
- `Hilos::PAGE_TABLES` declares which registered table or browser-only table is
  used by each page, including browser table params. Page classes must not put
  table bindings in `BROWSER[BrowserConfigKey::TABLES]`.

`Hilos::validateTopology()` runs before layer initialization and checks the
registry for missing classes, mismatched keys, duplicate signal ownership,
unknown page/table references, missing page subscription owners, and page-local
table bindings that should live in `Hilos::PAGE_TABLES`.

## Workflow

1. For a new page, add the page class to `Hilos::PAGES` using
   `SomePage::PAGE => SomePage::class`.
2. Declare the page subscription owner in the page class:
   `public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CHAT;`.
3. Declare inbound WebSocket actions owned by that page in
   `public const array ACTIONS = [...]`. Leave it as the inherited empty array
   when the page has no actions.
4. Declare page-dispatched non-action signals in
   `public const array SIGNALS = [...]` when the page handles them.
5. For a new agent, add the agent class to `Hilos::AGENTS` using
   `SomeAgent::AGENT_TYPE => SomeAgent::class`.
6. Declare directly handled agent-to-agent signal names in
   `public const array AGENT_SIGNALS = [...]` when the agent owns them.
7. For a server table definition, add the table class to `Hilos::TABLES`.
8. For a browser-only table config, add the config class to
   `Hilos::BROWSER_TABLES`.
9. For page-shaped browser state, bind the page to its tables in
   `Hilos::PAGE_TABLES`, including any browser params.
10. Make page factories, table contexts, browser contexts, signal routers,
    worker page routers, and WebSocket clients read the project registry through
    their established hooks or factory
    methods instead of adding another local topology list.
11. Add or update a topology registry test when a project registry changes.

## Preferred Shape

```php
final class MainPage extends AbstractPage
{
    public const string PAGE = PageConstants::MAIN;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CHAT;

    public const array ACTIONS = [
        ChatSignalConstants::MESSAGE,
    ];

    public const array SIGNALS = [
        SignalTypeConstants::FRAME_BINARY => [],
    ];
}

public const array PAGES = [
    MainPage::PAGE => MainPage::class,
];

public const array AGENTS = [
    ChatAgent::AGENT_TYPE => ChatAgent::class,
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
- Do not register a page, table, or browser table under a key that differs from
  the class constant that owns that key.

## Contract Gate

The root `AGENTS.md` contract approval gate still applies before
implementation. Stop and ask for explicit confirmation before changing
page `SUBSCRIPTION_AGENT_TYPE` values, page `SIGNALS`, page-owned `ACTIONS`,
agent `AGENT_SIGNALS`, `Hilos::AGENTS`, `SignalRouter`, `PageSignalRouter`, or
page/worker route config. The confirmation must list the exact pages, agents,
actions, agent routes, signal DTOs, and route declarations that would change.

## Validation

Use `$hilos-testing-cli` to choose the narrowest composer script. For framework
topology validation changes, run `composer run test:framework:unit`. For a
project registry change, run that project's unit tests covering topology.

# App Topology Registry

Read this before adding or changing project pages, page subscription routing,
registered tables, browser-only tables, or page-table browser bindings.

## Core Rule

Declare application topology in the project `Hilos` facade registry and
page-owned constants. Do not rebuild local page, route, or table lists in page
factories, browser contexts, signal routers, or table contexts when they can
read the project registry.

## Registry

- `Hilos::PAGES` registers page classes keyed by each page class `::PAGE`
  value.
- Each page class declares its page subscription owner in
  `PageClass::SUBSCRIPTION_AGENT_TYPE`. `Hilos::getPageRoutes()` computes the
  page-to-agent routing map from `Hilos::PAGES` and those page-level constants.
  Signal router subclasses should import that computed registry instead of
  owning a duplicate page-to-agent list.
- Each page class declares WebSocket actions it owns in `PageClass::ACTIONS`.
  `Hilos::getPageActionRoutes()` computes `action -> page`, and
  `Hilos::getActionAgentRoutes()` derives `action -> agent` through the owning
  page's `SUBSCRIPTION_AGENT_TYPE`. Signal routers, worker page routers, and
  WebSocket client action allowlists should read these computed registries
  instead of owning duplicate action lists.
- `Hilos::TABLES` registers server table definition classes keyed by table
  name.
- `Hilos::BROWSER_TABLES` registers browser-only table config classes keyed by
  each table class `::TABLE` value.
- `Hilos::PAGE_TABLES` declares which registered table or browser-only table is
  used by each page, including browser table params. Page classes must not put
  table bindings in `BROWSER[BrowserConfigKey::TABLES]`.

`Hilos::validateTopology()` runs before layer initialization and checks the
registry for missing classes, mismatched keys, unknown page/table references,
missing page subscription owners, and page-local table bindings that should
live in `Hilos::PAGE_TABLES`.

## Workflow

1. For a new page, add the page class to `Hilos::PAGES` using
   `SomePage::PAGE => SomePage::class`.
2. Declare the page subscription owner in the page class:
   `public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CHAT;`.
3. Declare inbound WebSocket actions owned by that page in
   `public const array ACTIONS = [...]`. Leave it as the inherited empty array
   when the page has no actions.
4. For a server table definition, add the table class to `Hilos::TABLES`.
5. For a browser-only table config, add the config class to
   `Hilos::BROWSER_TABLES`.
6. For page-shaped browser state, bind the page to its tables in
   `Hilos::PAGE_TABLES`, including any browser params.
7. Make page factories, table contexts, browser contexts, signal routers,
   worker page routers, and WebSocket clients read the project registry through
   their established hooks or factory
   methods instead of adding another local topology list.
8. Add or update a topology registry test when a project registry changes.

## Preferred Shape

```php
final class MainPage extends AbstractPage
{
    public const string PAGE = PageConstants::MAIN;

    public const string SUBSCRIPTION_AGENT_TYPE = AgentType::CHAT;

    public const array ACTIONS = [
        ChatSignalConstants::MESSAGE,
    ];
}

public const array PAGES = [
    MainPage::PAGE => MainPage::class,
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
- Do not keep page subscription routing only in `SignalRouter` config when the
  project can compute it through `Hilos::getPageRoutes()`.
- Do not keep WebSocket action routing only in `SignalRouter`, `WorkerManager`,
  or WebSocket client config when the project can compute it through
  `Hilos::getPageActionRoutes()` and `Hilos::getActionAgentRoutes()`.
- Do not register a page, table, or browser table under a key that differs from
  the class constant that owns that key.

## Contract Gate

The root `AGENTS.md` contract approval gate still applies before
implementation. Stop and ask for explicit confirmation before changing
page `SUBSCRIPTION_AGENT_TYPE` values, `SignalRouter`, `PageSignalRouter`, or
page/worker route config. This includes adding, removing, or moving
page-owned `ACTIONS`. The confirmation must list the exact pages, actions,
agent routes, signal DTOs, and route declarations that would change.

## Validation

Use `$hilos-testing-cli` to choose the narrowest composer script. For framework
topology validation changes, run `composer run test:framework:unit`. For a
project registry change, run that project's unit tests covering topology.

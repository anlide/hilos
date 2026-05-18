# App Topology Registry

Read this before adding or changing project pages, page subscription routing,
registered tables, browser-only tables, or page-table browser bindings.

## Core Rule

Declare application topology in the project `Hilos` facade constants. Do not
rebuild local page, route, or table lists in page factories, browser contexts,
signal routers, or table contexts when they can read the project registry.

## Registry

- `Hilos::PAGES` registers page classes keyed by each page class `::PAGE`
  value.
- `Hilos::PAGE_ROUTES` declares page subscription routing by page key to agent
  type. Signal router subclasses should import this registry instead of owning
  a duplicate page-to-agent list.
- `Hilos::TABLES` registers server table definition classes keyed by table
  name.
- `Hilos::BROWSER_TABLES` registers browser-only table config classes keyed by
  each table class `::TABLE` value.
- `Hilos::PAGE_TABLES` declares which registered table or browser-only table is
  used by each page, including browser table params. Page classes must not put
  table bindings in `BROWSER[BrowserConfigKey::TABLES]`.

`Hilos::validateTopology()` runs before layer initialization and checks the
registry for missing classes, mismatched keys, unknown page/table references,
and page-local table bindings that should live in `Hilos::PAGE_TABLES`.

## Workflow

1. For a new page, add the page class to `Hilos::PAGES` using
   `SomePage::PAGE => SomePage::class`.
2. Declare the page subscription owner in `Hilos::PAGE_ROUTES`.
3. For a server table definition, add the table class to `Hilos::TABLES`.
4. For a browser-only table config, add the config class to
   `Hilos::BROWSER_TABLES`.
5. For page-shaped browser state, bind the page to its tables in
   `Hilos::PAGE_TABLES`, including any browser params.
6. Make page factories, table contexts, browser contexts, and signal routers
   read the project registry through their established hooks or factory
   methods instead of adding another local topology list.
7. Add or update a topology registry test when a project registry changes.

## Preferred Shape

```php
public const array PAGES = [
    MainPage::PAGE => MainPage::class,
];

public const array PAGE_ROUTES = [
    MainPage::PAGE => AgentType::CHAT,
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
  page config from `Hilos::PAGES` and `Hilos::PAGE_TABLES`.
- Do not duplicate project browser-only table lists in a project
  `BrowserContext`; resolve table config from `Hilos::BROWSER_TABLES`.
- Do not put page-table bindings in page `BROWSER` constants; use
  `Hilos::PAGE_TABLES`.
- Do not keep page subscription routing only in `SignalRouter` config when the
  project has `Hilos::PAGE_ROUTES`.
- Do not register a page, table, or browser table under a key that differs from
  the class constant that owns that key.

## Contract Gate

The root `AGENTS.md` contract approval gate still applies before
implementation. Stop and ask for explicit confirmation before changing
`Hilos::PAGE_ROUTES`, `SignalRouter`, `PageSignalRouter`, or page/worker route
config. The confirmation must list the exact pages, agent routes, signal DTOs,
and route declarations that would change.

## Validation

Use `$hilos-testing-cli` to choose the narrowest composer script. For framework
topology validation changes, run `composer run test:framework:unit`. For a
project registry change, run that project's unit tests covering topology.

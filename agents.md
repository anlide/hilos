# Hilos Framework — Agent Index

Quick navigation for AI agents. Read the relevant file before starting work.

## Critical Git Rule

- Never run `git commit`.
- Never run `git push`.

---

## Architecture

| File | Read when... |
|---|---|
| [architecture/daemon-lifecycle.md](docs/agents/architecture/daemon-lifecycle.md) | working with daemon startup, cron, shutdown, server registration |
| [architecture/worker-lifecycle.md](docs/agents/architecture/worker-lifecycle.md) | working with worker processes, forking, message handling |
| [architecture/agent-lifecycle.md](docs/agents/architecture/agent-lifecycle.md) | creating agents, onStart/onTick/onStop, sending signals |
| [architecture/event-loop.md](docs/agents/architecture/event-loop.md) | anything involving sockets, I/O, blocking operations |
| [architecture/browser-source-fanout.md](docs/agents/architecture/browser-source-fanout.md) | DB/RT sync to browser payloads, source-change fan-out, worker-local subscription mirrors |
| [architecture/admin-features.md](docs/agents/architecture/admin-features.md) | graduating or building an admin feature (page + browser table + actions): framework-owned vs project-owned-by-pattern, the framework/project boundary, extension points |
| [architecture/admin-feature-scaffold.md](docs/agents/architecture/admin-feature-scaffold.md) | activating or building an admin feature in a project: the layer-by-layer scaffold order and the three activation paths (configure-only / bound-sources / project-owned) |
| [architecture/page-access-control.md](docs/agents/architecture/page-access-control.md) | gating a page subscription: DB_EXISTS / ACCESS guards, the resolveCurrentUserId identity hook, 403/404/401 errors, guards on every delivery path, the cross-agent guard rule |
| [architecture/command-server.md](docs/agents/architecture/command-server.md) | CLI↔daemon control plane: the command socket, request/reply DTOs, held-parking, routing a command to an agent, admin:grant as the worked example |
| [architecture/llm-routing.md](docs/agents/architecture/llm-routing.md) | choosing an LLM provider or adding an agent that talks to an LLM: named-profile routing, provider decoupled from credentials, env-default+settings-override resolution, the reserved worker/node placement seam |

## Framework Development

| File | Read when... |
|---|---|
| [framework-development.md](docs/agents/framework-development.md) | changing framework-level APIs, facade globals, extension points, framework subsystem exceptions |

## Feature Development

| File | Read when... |
|---|---|
| [feature-development-flow.md](docs/agents/feature-development-flow.md) | starting a feature with AI assistance — tiers (spike/experimental/production), data-structure-first elicitation, the iterative planner, orchestration shape (working design) |

## App Topology

| File | Read when... |
|---|---|
| [app-topology.md](docs/agents/app-topology.md) | adding pages, page subscription routes, registered tables, browser-only tables, or page-table bindings |

## Frontend

| File | Read when... |
|---|---|
| [frontend/README.md](docs/agents/frontend/README.md) | any frontend change — routes to the core/connection, wire-protocol, data-model, table-subscription, conflict-resolution, SDK-packaging, multiframework-core, page-module file layout, toasts, styling, testing, and build specs, plus the AI-first premise and the rules/violations catalog |
| [frontend/toasts.md](docs/agents/frontend/toasts.md) | showing an outcome that is not attached to what the user is looking at: the toast store, toast vs inline error vs durable record, and what must never interrupt an uninvolved user |

The frontend is rebuilt from zero on this branch (Path 1 rewrite) and its
specification is graduated ahead of the code. Frontend FE↔BE contract changes
(signals, signal/action DTOs, routes, entity/RT shapes) pass the Contract
approval gate below.

## Agent System

| File | Read when... |
|---|---|
| [agent-system/adding-agent.md](docs/agents/agent-system/adding-agent.md) | adding a new agent type to the project |
| [agent-system/ontick-rule.md](docs/agents/agent-system/ontick-rule.md) | writing or reviewing onTick() implementation |
| [agent-system/monopolistic-agent.md](docs/agents/agent-system/monopolistic-agent.md) | working with truth sources, shared state, long operations |

## Signals

| File | Read when... |
|---|---|
| [signals/routing.md](docs/agents/signals/routing.md) | adding routing rules, tracing signal path, signal not arriving |
| [signals/subscriptions.md](docs/agents/signals/subscriptions.md) | page/group subscriptions, sendToUser/sendToGroup |
| [signals/dto-convention.md](docs/agents/signals/dto-convention.md) | creating signal payload DTOs, agent-to-agent signals |

## ORM

Start with [orm/README.md](docs/agents/orm/README.md) for any ORM change; it
routes to the mandatory entity, object, collection, bridge, accessor, and
migration documents.

Minimum ORM rules before editing:

- Do not add Repository or Service layers over `DbCollection`; use typed
  `Hilos::$db` collection/item APIs directly.
- `actions` are write APIs; reads belong on collections, items, objects, or
  typed read APIs.
- If a DB item key is known, update/delete through that item's `actions`.
- Entity/Object layers keep persisted rows scalar; View items expose
  caller-facing relations and read shapes.
- DB entity shape changes require the contract approval gate before editing.

## Runtime

| File | Read when... |
|---|---|
| [runtime/rt-context.md](docs/agents/runtime/rt-context.md) | Hilos::$rt usage, runtime collections, sync between workers |
| [runtime/rt-state.md](docs/agents/runtime/rt-state.md) | creating RtState subclasses, writing/reading state items |

## Anti-patterns (read before writing code)

| File | Read when... |
|---|---|
| [antipatterns/no-repository-service.md](docs/agents/antipatterns/no-repository-service.md) | any time you think about adding a Service or Repository class |
| [antipatterns/blocking-in-ontick.md](docs/agents/antipatterns/blocking-in-ontick.md) | any time you write code inside onTick() or signal handlers |
| [antipatterns/heavy-work-in-master.md](docs/agents/antipatterns/heavy-work-in-master.md) | any time you add DB, file, network, or blocking work to the master daemon / connection / handshake path, or weigh such an option |

## CLI

| File | Read when... |
|---|---|
| [cli/commands.md](docs/agents/cli/commands.md) | running migrations, schema checks, DB reset, monitoring |

## Testing

| File | Read when... |
|---|---|
| [testing.md](docs/agents/testing.md) | running unit / integration / e2e tests — **always** via the composer scripts, never `phpunit` directly |

## Code Style Rules

| File | Read when... |
|---|---|
| [code-style/README.md](docs/agents/code-style/README.md) | choosing which small style rule applies to a code change |
| [code-style/phpdoc.md](docs/agents/code-style/phpdoc.md) | writing PHPDoc, overriding inherited methods, adding `@see` links |
| [code-style/static-factories.md](docs/agents/code-style/static-factories.md) | writing or changing static factories (`fromArray`, `fromRow`, `create`) and their `self`/`static` return contract |
| [code-style/page-action-handlers.md](docs/agents/code-style/page-action-handlers.md) | editing `Page::onAction()`, action DTO routing, page action acks/errors |
| [code-style/signal-handlers.md](docs/agents/code-style/signal-handlers.md) | editing named signal handlers such as `onSignalAgent()` or `onSignalCron()` |
| [code-style/import-aliases-and-helper-names.md](docs/agents/code-style/import-aliases-and-helper-names.md) | adding or changing PHP import aliases or helper method names |
| [code-style/frontend-import-paths.md](docs/agents/code-style/frontend-import-paths.md) | adding or changing a relative import in frontend TS — explicit `.js`, the barrel `index.js`, the "Import can be shortened" warning |
| [code-style/cross-layer-field-names.md](docs/agents/code-style/cross-layer-field-names.md) | naming a data field that crosses layers — one concept name from DB column to PHP entity to wire key to TS field |
| [code-style/table-names.md](docs/agents/code-style/table-names.md) | naming a database table — entity first then purpose; bridge tables order both entities by project dominance |
| [code-style/php-class-members.md](docs/agents/code-style/php-class-members.md) | adding or reordering PHP class constants, properties, or methods |
| [code-style/local-variables.md](docs/agents/code-style/local-variables.md) | introducing temporary variables or reviewing one-use locals |

## AI Tool Integration

See [ai-tools.md](docs/agents/ai-tools.md) for Codex, Claude, Cursor, skill
wrappers, and rule-authoring integration.

---

## Contract approval gate (hard stop)

Before implementing any change in the following contract surfaces, stop and ask
the user for explicit confirmation:

- RT item state shape: adding, removing, renaming, or changing fields on
  concrete `Runtime/State/Item/*` rows, including their `create()`, `fromRow()`,
  `applyDiff()`, or `toArray()` field contract. This does not apply to RT
  collection-only changes.
- DB entity shape: adding, removing, renaming, or changing entity-level persisted
  fields, table mapping, schema/migration shape, or `Database/Entity/Item/*`
  row contracts.
- Signals and routes: adding, removing, renaming, or changing signal constants,
  signal DTO payload shape, topology DTO class declarations in page `SIGNALS`
  or agent `AGENT_SIGNALS`, or declarative routing in `SignalRouter`,
  `PageSignalRouter`, or worker/page route config.

The confirmation request must list the exact RT item fields, DB entity fields,
signals, DTOs, and routes that would change. If implementation discovers an
additional change in one of these surfaces, stop and ask again before editing it.

## Key rules (always apply)

1. **Never** use Repository or Service on top of DbCollection — call `Hilos::$db->collection->actions->...` directly
2. **Never** block in `onTick()` — must complete in < 0.1s
3. **Only the truth source agent** writes to its DB/RT collection
4. All PHP files: `declare(strict_types=1)` at top
5. Signal routing is **declarative** in `SignalRouter` — do not add routing logic in agents
6. For code style, use the matching small rule from [code-style/README.md](docs/agents/code-style/README.md)
7. Internal backend API uses typed parameters, DTOs, value objects, or typed collections — unstructured arrays need a boundary or explicit reason; do not leave magic-string keys in internal structured arrays — use named constants at minimum, a value object preferably
8. DB/RT `actions` are write APIs; put read-only helpers on collections, items, objects, or typed read APIs
9. If a DB/RT item key is known, update/delete that one item through `Hilos::$db/$rt->collection[$key]->actions`, not through collection actions that accept the key

## Project docs (existing)

- [docs/code-style.md](docs/code-style.md) — PHP/TS code style rules
- [docs/quality.md](docs/quality.md) — application quality guidelines
- [docs/reference.md](docs/reference.md) — API reference
- [docs/cli-commands.md](docs/cli-commands.md) — CLI reference (legacy, prefer docs/agents/cli/commands.md)
- [docs/new-project/README.md](docs/new-project/README.md) — creating a new Hilos project (backend + frontend), routes to the per-framework frontend parts

## Demo docs

Each demo documents itself: an index at `<demo>/agents.md`, the demo's own
documentation under `<demo>/spec/**`. These describe one demo's behavior and
never override a framework rule — see
[rule-authoring.md](docs/agents/rule-authoring.md), *Per-Demo Documentation*.

| File | Read when... |
|---|---|
| [demo/chat/agents.md](demo/chat/agents.md) | working in the chat demo: its agents, pages, data flows, runtime state, moderation/LLM setup, known issues |

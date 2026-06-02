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

## Framework Development

| File | Read when... |
|---|---|
| [framework-development.md](docs/agents/framework-development.md) | changing framework-level APIs, facade globals, extension points, framework subsystem exceptions |

## App Topology

| File | Read when... |
|---|---|
| [app-topology.md](docs/agents/app-topology.md) | adding pages, page subscription routes, registered tables, browser-only tables, or page-table bindings |

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
routes to the mandatory entity, object, collection, bridge, accessor,
frontend representation, and migration documents.

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

## Frontend SDK

| File | Read when... |
|---|---|
| [frontend-sdk/websocket-connection.md](docs/agents/frontend-sdk/websocket-connection.md) | WS connection lifecycle, acceptKey, reconnect |
| [frontend-sdk/backend-contract.md](docs/agents/frontend-sdk/backend-contract.md) | actions (client→server), signals (server→client), page subscription |
| [frontend-sdk/edit-in-modal.md](docs/agents/frontend-sdk/edit-in-modal.md) | editing an entity from a Vue page (rename, update fields) — always use Modal, never inline forms |

## Anti-patterns (read before writing code)

| File | Read when... |
|---|---|
| [antipatterns/no-repository-service.md](docs/agents/antipatterns/no-repository-service.md) | any time you think about adding a Service or Repository class |
| [antipatterns/blocking-in-ontick.md](docs/agents/antipatterns/blocking-in-ontick.md) | any time you write code inside onTick() or signal handlers |

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
| [code-style/page-action-handlers.md](docs/agents/code-style/page-action-handlers.md) | editing `Page::onAction()`, action DTO routing, page action acks/errors |
| [code-style/signal-handlers.md](docs/agents/code-style/signal-handlers.md) | editing named signal handlers such as `onSignalAgent()` or `onSignalCron()` |
| [code-style/import-aliases-and-helper-names.md](docs/agents/code-style/import-aliases-and-helper-names.md) | adding or changing PHP import aliases or helper method names |
| [code-style/php-class-members.md](docs/agents/code-style/php-class-members.md) | adding or reordering PHP class constants, properties, or methods |
| [code-style/local-variables.md](docs/agents/code-style/local-variables.md) | introducing temporary variables or reviewing one-use locals |
| [code-style/frontend-vue.md](docs/agents/code-style/frontend-vue.md) | editing Vue SFC templates, global components, or frontend line endings |

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
6. **Frontend edits go through `Modal` only** — inline edit forms on pages are forbidden (see [frontend-sdk/edit-in-modal.md](docs/agents/frontend-sdk/edit-in-modal.md))
7. For code style, use the matching small rule from [code-style/README.md](docs/agents/code-style/README.md)
8. Internal backend API uses typed parameters, DTOs, value objects, or typed collections — unstructured arrays need a boundary or explicit reason; do not leave magic-string keys in internal structured arrays — use named constants at minimum, a value object preferably
9. DB/RT `actions` are write APIs; put read-only helpers on collections, items, objects, or typed read APIs
10. If a DB/RT item key is known, update/delete that one item through `Hilos::$db/$rt->collection[$key]->actions`, not through collection actions that accept the key

## Project docs (existing)

- [docs/code-style.md](docs/code-style.md) — PHP/TS code style rules
- [docs/quality.md](docs/quality.md) — application quality guidelines
- [docs/reference.md](docs/reference.md) — API reference
- [docs/cli-commands.md](docs/cli-commands.md) — CLI reference (legacy, prefer docs/agents/cli/commands.md)

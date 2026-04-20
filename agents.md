# Hilos Framework — Agent Index

Quick navigation for AI agents. Read the relevant file before starting work.

---

## Architecture

| File | Read when... |
|---|---|
| [architecture/daemon-lifecycle.md](docs/agents/architecture/daemon-lifecycle.md) | working with daemon startup, cron, shutdown, server registration |
| [architecture/worker-lifecycle.md](docs/agents/architecture/worker-lifecycle.md) | working with worker processes, forking, message handling |
| [architecture/agent-lifecycle.md](docs/agents/architecture/agent-lifecycle.md) | creating agents, onStart/onTick/onStop, sending signals |
| [architecture/event-loop.md](docs/agents/architecture/event-loop.md) | anything involving sockets, I/O, blocking operations |

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

| File | Read when... |
|---|---|
| [orm/entity.md](docs/agents/orm/entity.md) | creating or modifying Entity classes, DB table mapping |
| [orm/object.md](docs/agents/orm/object.md) | creating Object layer, transforming entity data for views |
| [orm/db-collection.md](docs/agents/orm/db-collection.md) | querying data, writing actions, Hilos::$db usage |
| [orm/migrations.md](docs/agents/orm/migrations.md) | DB schema changes, migration files, seeds |

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

## Anti-patterns (read before writing code)

| File | Read when... |
|---|---|
| [antipatterns/no-repository-service.md](docs/agents/antipatterns/no-repository-service.md) | any time you think about adding a Service or Repository class |
| [antipatterns/blocking-in-ontick.md](docs/agents/antipatterns/blocking-in-ontick.md) | any time you write code inside onTick() or signal handlers |

## CLI

| File | Read when... |
|---|---|
| [cli/commands.md](docs/agents/cli/commands.md) | running migrations, schema checks, DB reset, monitoring |

---

## Key rules (always apply)

1. **Never** use Repository or Service on top of DbCollection — call `Hilos::$db->collection->actions->...` directly
2. **Never** block in `onTick()` — must complete in < 0.1s
3. **Only the truth source agent** writes to its DB/RT collection
4. All PHP files: `declare(strict_types=1)` at top
5. Signal routing is **declarative** in `SignalRouter` — do not add routing logic in agents

## Project docs (existing)

- [docs/code-style.md](docs/code-style.md) — PHP/TS code style rules
- [docs/quality.md](docs/quality.md) — application quality guidelines
- [docs/reference.md](docs/reference.md) — API reference
- [docs/cli-commands.md](docs/cli-commands.md) — CLI reference (legacy, prefer docs/agents/cli/commands.md)

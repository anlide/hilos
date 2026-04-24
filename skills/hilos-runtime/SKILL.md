---
name: hilos-runtime
description: Work with Hilos runtime state, Hilos::$rt, runtime collections, RtState subclasses, in-memory synchronization between workers, RT sync events, and non-database shared state. Use when creating or modifying runtime state objects, RT collection access, or worker synchronization behavior.
---

# Hilos Runtime

Use this skill for Hilos runtime state and cross-worker in-memory data. Start with `agents.md`, then read the matching runtime guide.

## Read First

- `Hilos::$rt`, runtime collections, worker sync: `docs/agents/runtime/rt-context.md`
- `RtState` subclasses and state item access: `docs/agents/runtime/rt-state.md`
- Truth sources and shared state ownership: `docs/agents/agent-system/monopolistic-agent.md`
- RT sync signal flow: use `$hilos-signals`

## Workflow

1. Decide whether the data is durable DB state or runtime-only RT state.
2. Use DB/ORM for durable state and `$hilos-orm` for schema-backed work.
3. Use `Hilos::$rt` only for runtime state that should not be persisted as DB rows.
4. Define `RtState` subclasses with explicit typed fields.
5. Keep the truth source rule clear before writing to any shared runtime collection.
6. If RT changes emit signals, verify routing and payload shape through `$hilos-signals`.
7. In custom RT action methods, write typed state fields and call `sync()`;
   reserve `applyDiff()` / `applyDiffToState()` for RT synchronization internals.
8. In collection RT actions, use `$this->stateCollection[$id]` for backing
   state rows instead of private lookup helpers when the collection key is known.
9. Add a concrete `@property-read StateFooCollection $stateCollection` PHPDoc
   on each collection actions class; the base generic documents the contract,
   but PhpStorm often needs the local property annotation.
10. Prefer real PHP 8.4 typed properties on `RtState` classes over magic-only
    `@property` fields; use `public private(set)` for immutable ids and property
    hooks only when a field needs normalization or invariant logic.
11. Do not implement `__get()` / `__set()` in concrete `RtState` classes for
    declared row fields; action code should read/write the declared properties
    directly, then call `sync()`.
12. In concrete `RtStates` collections, override `get()` as nullable
    `?StateFoo` and `offsetGet()` as non-null `StateFoo`; use `get()` for
    optional lookups and `[]` only when the row must already exist.

## Hard Rules

- Never run `git commit` or `git push`.
- Only the truth source agent writes to its owned RT collection.
- Do not use runtime state as a hidden durable database.
- Keep sync payloads explicit and typed.
- Do not expose application-level `applyDiff*()` write APIs on RT actions.

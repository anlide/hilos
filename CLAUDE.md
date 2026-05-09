# Hilos Framework

Read `agents.md` before starting work in this repository. Use it as the rule
index and follow the relevant `docs/agents/*` files before editing.

This file is a thin Claude adapter. The source of truth is:

1. `agents.md` for repository-wide hard stops, always-on rules, and navigation;
2. `docs/agents/*` for canonical task-specific rules;
3. `skills/hilos-*` only for Codex-specific skill wrappers.

When changing DB entity shape, RT item shape, signal DTO payloads, declarative
routing, or page/worker routes, stop and ask the user for explicit confirmation
as described in `agents.md`.

Do not run `git commit` or `git push`.

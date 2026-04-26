# AI Tool Integration

Hilos uses one shared source of truth for AI assistants:

- `agents.md` is the repository-level agent index.
- `docs/agents/*` contains the task-specific rules.
- `skills/hilos-*` contains Codex-format skill wrappers that point back to those docs.

## Codex

Install the Codex skill wrappers into the Codex skill directory:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/install-codex-skills.ps1
```

After installation, Codex can invoke skills such as `$hilos-orm`,
`$hilos-runtime`, `$hilos-data-extension`, `$hilos-signals`, and
`$hilos-testing-cli`.

## Claude

Claude uses `CLAUDE.md` at the repository root. It points Claude to `agents.md` and the same `docs/agents/*` files.

## Cursor

Cursor uses `.cursor/rules/hilos-framework.mdc`. The rule is always applied for this repository and points Cursor to `agents.md` and `docs/agents/*`.

## Updating The Rules

When Hilos conventions change, update `docs/agents/*` first. Then update the matching Codex skill wrapper only if the trigger description, workflow, or document routing changed.

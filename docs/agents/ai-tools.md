# AI Tool Integration

Hilos keeps one shared source of truth for AI assistants:

- `agents.md` is the repository-level agent index.
- `docs/agents/*` contains the task-specific rules.
- `skills/hilos-*` contains Codex-format skill packages that point back to those docs.

Per-tool configuration is **materialized from that source by one installer**.
Do not hand-maintain per-tool copies of the rules or skills.

## Install

```bash
composer ai:install         # install / repair / update for every tool
composer ai:install:check   # report drift without changing anything (CI, exit 1 on drift)
```

```bash
php scripts/install-ai-tooling.php --copy-only   # never use symlinks (Windows / CI)
```

The same idempotent pass performs first-run install, repair (heals missing or
wrong artifacts), and update (propagates canonical changes); orphaned managed
artifacts are pruned. Re-run it after pulling rule changes.

`scripts/install-codex-skills.ps1` (global `$CODEX_HOME/skills` copy) is
superseded by the in-repo `.agents/skills` tree the installer creates.

## When the installer refuses

A `[blocked]` line means the installer could **not** write an artifact and did not
pretend otherwise. The pass runs to the end, applies everything else, and exits
non-zero, so one report names every path you have to fix rather than one path per
run.

```text
[blocked ] .claude/skills/hilos-cli-commands  (cannot write 4 file(s): permission denied (owner root) — sudo chown -R you:you .claude/skills/hilos-cli-commands)
```

The usual cause is ownership: `composer` was once run from inside a container, so
the artifacts it created belong to `root` while you are not `root`. Fix it with
the chown the report already spells out, then re-run `composer ai:install`.

Two consequences worth knowing:

- **A skill is all-or-nothing.** If any file of a skill tree cannot be written,
  nothing in that tree is touched — an agent keeps reading the previous version
  whole, instead of a mixture of old and new files that never existed upstream.
- **`ai:install:check` stops advising a run that cannot help.** A blocked path is
  not drift the installer can apply; when everything pending is blocked, the
  summary says so and names no command to run.

Running the installer as `root` in a checkout somebody else owns prints a warning
in the header, because that run is what manufactures the problem above.

## What it materializes

All generated artifacts are **git-ignored and machine-local**. The strategy per
artifact follows how each tool discovers it: symlink where the tool reads by an
explicit named path, copy or generate where the tool walks a directory (those
walks skip symlinks).

| Tool(s) | Artifact | Strategy | Source |
|---|---|---|---|
| Claude Code | `.claude/skills/hilos-*` | copy | `skills/hilos-*` |
| Codex, Cursor, Windsurf | `.agents/skills/hilos-*` | per-skill symlink (real root dir) | `skills/hilos-*` |
| Codex, Cursor, Windsurf, Aider | `AGENTS.md` | symlink | `agents.md` |
| Gemini CLI | `GEMINI.md` | generate (imports `agents.md`) | `agents.md` |
| Aider | `.aider.conf.yml` | generate (reads `agents.md`) | `agents.md` |

On a case-insensitive filesystem `AGENTS.md` is skipped because `agents.md`
already resolves under that name.

## Tracked adapters (not managed by the installer)

These need tool-specific frontmatter/format, so they stay tracked and thin:

- `CLAUDE.md` — adapter that points Claude Code to `agents.md`.
- `.cursor/rules/hilos-framework.mdc` — always-applied Cursor rule that points
  to `agents.md` and `docs/agents/*`.

## Cross-platform note

Generated artifacts are not committed, so each contributor runs the installer for
their own OS. On POSIX/WSL it uses symlinks; on Windows without
`core.symlinks` + Developer Mode (or with `--copy-only`) it falls back to real
copies, because git would otherwise check a committed symlink out as a plain
text stub containing the link path — which a tool would read as rule content.

## Updating The Rules

Before creating, extracting, or restructuring rules, read
[rule-authoring.md](rule-authoring.md).

When Hilos conventions change, update `docs/agents/*` first. Then update the
matching `skills/hilos-*` wrapper only if its trigger description, workflow, or
document routing changed. Then run `composer ai:install` so every tool picks up
the change. Update the tracked Cursor or Claude adapters only when their
entry-point routing changes.

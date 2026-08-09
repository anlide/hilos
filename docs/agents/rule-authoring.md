# Rule Authoring

Read this before creating, moving, or restructuring Hilos agent rules, AI-facing
documentation, Codex skill wrappers, Claude adapters, or Cursor rules.

## Source Of Truth

- `docs/agents/*` contains the canonical task-specific rules.
- `agents.md` is the repository index plus global hard stops and always-on
  rules.
- `skills/hilos-*` contains Codex skill wrappers. Wrappers route Codex to the
  right canonical docs; they must not become a second source of truth.
- `.cursor/rules/hilos-framework.mdc` and `CLAUDE.md` are thin tool adapters.
  They should point to `agents.md` and `docs/agents/*` instead of duplicating
  detailed rules.
- All repository rule, skill, and adapter files must be written in English.

## Per-Demo Documentation

A demo documents itself; it never documents the framework.

- `<demo>/agents.md` is that demo's index for AI agents: a link table plus the
  demo's own always-on facts. It points up to `/agents.md` first.
- `<demo>/spec/**` holds the demo's own documentation — how *this* demo works
  (its agents, pages, data flows, runtime state). Name it `spec`, not `agents`:
  a folder called `agents` next to `backend/` reads as the demo's agent classes,
  which is a different thing that already exists in `<demo>/backend/Agents`.
- A demo doc describes behavior; it must not restate or override a framework
  rule. When a demo file starts prescribing how to build things in general, the
  rule belongs in `docs/agents/*` instead.
- Link every new demo index from the *Demo docs* table in `/agents.md`. An
  unlinked demo index is unreachable: an agent that never opens the demo folder
  never learns it exists.

## What Counts As A Rule

A rule is an actionable constraint that changes how an agent implements,
reviews, validates, or refuses a change.

A good rule says:

- when it applies;
- what the agent must do;
- what the agent must not do;
- where exceptions are allowed;
- which related docs or validation steps are required.

Background explanation, architecture overview, and rationale may support a rule,
but they are not rules by themselves.

## Rule File Shape

Use the smallest file that matches the task. Prefer this shape for new or
substantially rewritten rule files:

```md
# Area: Rule Name

Read this before changing ...

## Core Rule

One direct rule that changes agent behavior.

## Workflow

1. Concrete steps the agent should follow.
2. Keep this short and operational.

## Preferred Shape

Small correct examples, when examples clarify the rule.

## Anti-Patterns

Small wrong examples and the replacement shape.

## Exceptions

Explicit cases where the rule does not apply.

## Contract Gate

Only include this section when the rule touches DB entity shape, RT item shape,
signal DTO payloads, declarative routing, or page/worker routes.

## Validation

Which composer scripts, checks, or narrower rules should be used.
```

Omit sections that do not add actionable value. Do not add long background
sections unless they prevent a recurring mistake.

## Extraction Workflow

1. Read the source text and mark each sentence as hard rule, workflow step,
   preference, rationale, example, or adapter instruction.
2. Keep canonical behavior in `docs/agents/*`.
3. Put global hard stops and navigation in `agents.md`, not inside every
   narrower file.
4. Put tool-specific activation text in the matching adapter or skill wrapper.
5. Update a Codex skill wrapper only when the trigger description, required
   read order, workflow, or hard-rule summary changes.
6. Update `.cursor/rules/hilos-framework.mdc` or `CLAUDE.md` only when tool
   routing changes.
7. Add examples only when they make the rule easier to apply correctly.
8. If the extracted rule touches a contract-gated surface, keep the gate in the
   rule text and stop before any implementation that changes that surface.

## Language And Tone

- Use direct imperatives: `Use`, `Prefer`, `Do not`, `Never`, `Stop and ask`.
- Use `Never` only for hard rules with no local exception.
- Pair prohibitions with the replacement shape: `Do not use X; use Y`.
- Name the owner precisely: page, agent, DB collection, RT collection, DTO,
  signal router, table, or framework subsystem.
- Avoid vague advice such as "handle carefully" or "keep clean" unless the text
  also says what action that requires.
- Keep examples short, project-shaped, and focused on the disputed decision.

## When a rule keeps getting missed

A rule living only in `docs/agents/*` does not reliably bind at code time — an
agent can write past it without ever opening the file. When a committed rule is
violated, or is easy to miss, the fix is a **trigger skill**, not a one-off
correction: author or extend a `hilos-*` skill whose `description` fires on the
task shape ("when implementing or changing X …") and routes to the canonical
doc. The rule stays canonical in `docs/agents/*`; the skill is the thin trigger
that makes the agent read it at the right moment. A rule that is hard to enforce
without a matching trigger skill is incomplete.

A trigger skill does not exhaust enforcement. It improves the odds that the rule
is read; it cannot tell whether the rule was followed. When a rule is decidable
from the source — a forbidden shape, a name that may appear only under a given
path — it must also get a machine check, and the trigger skill stops being the
last line of defence. See
[code-style/automated-checks.md](code-style/automated-checks.md) for what is
checked today and how to add a rule to the guard.

## Codex Skill Wrapper Shape

A Hilos skill wrapper should stay small:

- frontmatter `description` names the exact tasks that trigger the skill;
- `Read First` points to `agents.md` and the canonical docs;
- `Workflow` lists only the steps needed before and during the task;
- `Hard Rules` summarizes only the rules that are easy to miss without loading
  every canonical doc;
- examples are included only when they are needed for correct routing.

Do not copy full canonical rule files into `SKILL.md`.

## Choosing The Wrapper: By Language, Then By Framework

Style wrappers are cut by language first and by view framework second, and the
trigger is the *shape of the task* — the file extension and the path — not a
judgment the agent has to make about which rules might apply. Editing a `.php`
file under `framework/backend/**` fires `$hilos-code-style-php`; editing a `.vue`
file under `demo/*/frontend/vue/**` fires `$hilos-code-style-vue`.

- A framework wrapper's first `Read First` route is its language wrapper
  (`$hilos-code-style-vue` → `$hilos-code-style-typescript`), and it carries only
  what is specific to its view layer.
- Cut a wrapper for a framework even when its slot is nearly empty. A thin
  wrapper costs a file; a missing one costs the route, and the agent silently
  edits with no style rules loaded at all.
- State an empty slot in words ("this framework has no code-shape rule of its
  own today"). Never fill it by routing to another framework's rules: those
  describe a different view layer, and following them produces code that matches
  no canon.
- A subject that has no language side — a field name crossing DB, wire, and view
  — stays one rule file, routed from both language wrappers. Split rule files by
  subject; express the language boundary with the *Applies to* column in
  [code-style/README.md](code-style/README.md), not with folders.

## Every Rule File Is Reachable From A Wrapper

A new or moved rule file under `docs/agents/` must be reachable from at least one
`hilos-*` skill wrapper, and listed in the index table that owns it (`agents.md`,
or the catalog `README.md` for a code-style rule). An unrouted rule file is
unreachable at code time: it is canonical, correct, and never read.
Checked automatically: `DOC-ROUTE`, but only the first half of that — that a
wrapper routes to the file, and only for the `docs/agents/code-style/` catalog.
Listing the file in the index table that owns it stays yours to do.

A file that needs no route by design says so in itself, on a line of its own
reading `Routed from: none — <reason>`. The reason is not optional: without it
the line is a silent mute, and the next reader — the one deciding whether to
route the file or delete it — learns that it stands apart but not why. The
refusal lives in the file rather than in a list inside the checker, because that
is where the person weighing it is already looking; and it is not a baseline
record, because a baseline record means "debt some leaf will pay", which a
deliberate decision never becomes. A file carrying both a route and the refusal
is reported like an unrouted one: a refusal that outlived its truth misleads the
reader exactly as much as no route at all.

When adding a rule file, walk the route the way an agent would — from the task
shape to the wrapper, from the wrapper to the file — and confirm no `.md` link on
that path is broken. Both halves of that walk are now machine-checked — the route
by `DOC-ROUTE`, the links by `DOC-LINK` — but only what a machine can judge:
see [code-style/automated-checks.md](code-style/automated-checks.md) for what
each of them deliberately leaves to you.

## Adapter Shape

Tool adapters should be thinner than skill wrappers. They should only establish
the repository entry point and source-of-truth order:

1. read `agents.md`;
2. choose the relevant `docs/agents/*` file;
3. follow the contract approval gate before contract-surface changes;
4. avoid duplicating detailed rules from canonical docs.

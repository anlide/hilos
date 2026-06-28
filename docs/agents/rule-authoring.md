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

## Codex Skill Wrapper Shape

A Hilos skill wrapper should stay small:

- frontmatter `description` names the exact tasks that trigger the skill;
- `Read First` points to `agents.md` and the canonical docs;
- `Workflow` lists only the steps needed before and during the task;
- `Hard Rules` summarizes only the rules that are easy to miss without loading
  every canonical doc;
- examples are included only when they are needed for correct routing.

Do not copy full canonical rule files into `SKILL.md`.

## Adapter Shape

Tool adapters should be thinner than skill wrappers. They should only establish
the repository entry point and source-of-truth order:

1. read `agents.md`;
2. choose the relevant `docs/agents/*` file;
3. follow the contract approval gate before contract-surface changes;
4. avoid duplicating detailed rules from canonical docs.

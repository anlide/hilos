---
name: hilos-rule-authoring
description: Author and maintain Hilos agent rules, AI-facing docs, Codex skill wrappers, Cursor rules, and Claude adapters. Use when creating, restructuring, or extracting rules into docs/agents, updating agents.md navigation, or changing skills/hilos-* trigger/workflow wrappers.
---

# Hilos Rule Authoring

Use this skill before changing Hilos rule documents, AI-tool adapters, or Codex
skill wrappers. Start with `agents.md`, then read
`docs/agents/rule-authoring.md`.

## Read First

- Rule authoring guide: `docs/agents/rule-authoring.md`
- AI tool integration: `docs/agents/ai-tools.md`
- Repository index and global hard stops: `agents.md`
- Skill creation/update mechanics: use `$skill-creator` when creating a new
  skill wrapper or substantially changing an existing one.

## Workflow

1. Decide whether the source text is a hard rule, workflow step, preference,
   rationale, example, or tool adapter instruction.
2. Keep canonical task-specific behavior in `docs/agents/*`.
3. Keep `agents.md` as navigation plus global hard stops and always-on rules.
4. Keep `skills/hilos-*` as Codex trigger and workflow wrappers, not duplicate
   canonical docs.
5. Keep `.cursor/rules/hilos-framework.mdc` and `CLAUDE.md` as thin adapters
   that point to `agents.md` and `docs/agents/*`.
6. Write all repository rule, skill, and adapter files in English.
7. When adding a new rule file, add it to `agents.md` under the smallest
   matching section.
8. When changing triggers or read order, update the matching `SKILL.md` and
   `agents/openai.yaml`.
9. When a rule should stop depending on memory, follow "Adding a rule" in
   `docs/agents/code-style/automated-checks.md`. A rule whose subject crosses the
   PHP↔TypeScript boundary is written twice under one rule id — `WIRE-KEY-CASE`
   is the worked example, with its TypeScript half in
   `framework/frontend/codestyle/`.
10. When a rule touches DB entity shape, RT item shape, signal DTO payloads, or
    routes, preserve the contract approval gate and stop before implementation
    changes to those surfaces.
11. When a change lands behavior a doc marks `(not in the code yet — HIL-<n>)`,
    grep `docs/` for that key and clear the markers in the same commit; see
    "A Rule Written Ahead Of Its Code" in `docs/agents/rule-authoring.md`.

## Hard Rules

- Do not make a skill wrapper the only place where a Hilos rule exists.
- Do not duplicate detailed canonical rules in Cursor or Claude adapters.
- Do not bury actionable constraints inside long rationale-only prose.
- Do not use `Never` unless the rule has no local exception.
- Do not hand a marker removal to another leaf: the leaf that lands the behavior
  clears it, in the same commit.

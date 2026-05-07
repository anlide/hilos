---
name: hilos-code-style
description: Apply Hilos PHP and TypeScript code style, PHPDoc conventions, strict types, page action handler style, local variable rules, DTO routing style, and project quality rules. Use when writing, reviewing, or refactoring Hilos code where naming, typing, comments, PHPDoc, handlers, method signatures, method contracts, or method behavior matter.
---

# Hilos Code Style

Use this skill for style-sensitive Hilos edits and reviews. Start with `agents.md`, then read the smallest style rule that applies.

## Read First

- Style rule index: `docs/agents/code-style/README.md`
- PHPDoc and inherited method docs: `docs/agents/code-style/phpdoc.md` -
  read when creating a PHP method, changing a method signature, changing
  visibility/parameters/return type, changing thrown exceptions, overriding a
  method, or substantially changing what a documented method does.
- Exception taxonomy and `@throws`: `docs/agents/code-style/exceptions.md`
- `Page::onAction()` and action handler routing: `docs/agents/code-style/page-action-handlers.md`
- Named signal handler routing (`onSignalAgent()`, `onSignalCron()`):
  `docs/agents/code-style/signal-handlers.md`
- Internal backend API contracts: `docs/agents/code-style/internal-backend-api.md`
- Temporary/local variable rules: `docs/agents/code-style/local-variables.md`
- Broader style guide: `docs/code-style.md`
- Quality guide: `docs/quality.md`

## Workflow

1. Before editing PHP methods, decide whether the change creates a method,
   changes a method signature, changes visibility/parameters/return type,
   changes thrown exceptions, overrides a method, or substantially changes
   documented behavior.
2. If yes, read `docs/agents/code-style/phpdoc.md` before editing the method's
   PHPDoc.
3. Read the specific style rule before changing code shape.
4. Keep PHP files strict with `declare(strict_types=1)`.
5. After changing a PHP method, update the affected PHPDoc so it describes the
   current local contract: what the method sends, mutates, routes, validates,
   returns, throws, or deliberately ignores.
6. Use PHPDoc only where the project rule asks for it or where it clarifies a contract.
7. Keep action routing and handler code aligned with the page action handler guide.
8. Keep named signal routing aligned with the signal handler guide.
9. Use typed parameters, DTOs, value objects, or typed collections for internal
   backend API; keep unstructured arrays at boundaries.
10. Avoid one-use locals and prefer inline nullsafe access for one immediate
   nullable member call under the local variable rule.
11. Avoid pass-through locals and DB/RT item aliases; keep known-key
   `Hilos::$db/$rt->collection[$key]` access and context item aliases such as
   `Hilos::$rt->selfConnection` visible unless the local variable adds domain
   meaning, snapshots state, or performs type narrowing that cannot be expressed
   by a guard.
12. Use named constants for action names, signal names, route params, model
   fields, DTO payload keys, table row keys, and boundary array keys whenever
   a constant exists. If a repeated payload key has no owner constant, add one
   to the owning DTO, projection, table row, entity, object, or context before
   using that key in examples or code.
13. During refactors, do not add new convenience read helpers or predicates
   such as `has*()`, `is*()`, `can*()`, or `get*()` on DB/RT View items,
   collections, objects, actions, or projections unless the user explicitly
   approved that exact method in the plan. Prefer explicit field access when
   preserving transparent data shape is the goal.
14. Keep comments concise and in English.
15. For `Page::onAction()`, do not add a local `try/catch` around the routing
   `switch`; the framework catches action exceptions and calls
   `onActionException()`.
16. For named signal handlers, use `switch ($name)` with explicit cases.
17. Override `onActionException()` only when the page has a specific fail/error
   contract; otherwise let the default framework `action_error` signal notify
   the initiator.
18. Use `AgentUnknownActionException` in `onAction()` default branches.
19. Do not add empty `default` branches. If a handler intentionally ignores
   shared broadcast names and the branch would only `return` or `break`, omit
   it and document the ignore contract in PHPDoc.
20. In PHPDoc, import exception classes with `use` and reference short names;
   do not write leading-backslash fully qualified exceptions such as
   `@throws \OutOfBoundsException`.
21. Use `ValidationException` and its children for user/business validation;
   read `docs/agents/code-style/exceptions.md` before changing exception types.
22. Before finishing a PHP method change, re-check the affected docblock against
   `docs/agents/code-style/phpdoc.md`.
23. Before finalizing PHPDoc, audit every added or changed `@throws`. Remove
   any `@throws` based only on assumed framework, DB, or signal failure rather
   than a direct throw, documented callee contract, or deliberate local
   caller-facing contract.

## Hard Rules

- Never run `git commit` or `git push`.
- Use `?type` for nullable PHP types in code and regular PHPDoc, unless a documented exception applies.
- Do not add unrelated refactors while applying style cleanup.
- Do not add unapproved convenience helpers or predicates during refactors.
- Do not write repeated payload/model/table keys as magic strings when an owner
  constant exists or should exist.

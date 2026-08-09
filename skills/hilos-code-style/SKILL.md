---
name: hilos-code-style
description: Apply Hilos PHP and TypeScript code style, PHPDoc conventions, strict types, page action handler style, local variable rules, DTO routing style, Reflection use in production PHP, and project quality rules. Use when writing, reviewing, or refactoring Hilos code where naming, typing, comments, PHPDoc, handlers, method signatures, method contracts, static factory return contracts, Reflection calls, or method behavior matter.
---

# Hilos Code Style

Use this skill for style-sensitive Hilos edits and reviews. Start with `agents.md`, then read the smallest style rule that applies.

## Read First

- Framework-level APIs: use `$hilos-framework-development` first before
  changing Hilos facade globals, shared base classes, extension points,
  framework subsystem exceptions, or other cross-project API contracts.
- Style rule index: `docs/agents/code-style/README.md`
- PHPDoc and inherited method docs: `docs/agents/code-style/phpdoc.md` -
  read when creating a PHP method, changing a method signature, changing
  visibility/parameters/return type, changing thrown exceptions, overriding a
  method, or substantially changing what a documented method does.
- Exception taxonomy and `@throws`: `docs/agents/code-style/exceptions.md`
- `Page::onAction()` and action handler routing: `docs/agents/code-style/page-action-handlers.md`
- Named signal handler routing (`onSignalAgent()`, `onSignalCron()`):
  `docs/agents/code-style/signal-handlers.md`
- Magic values: `docs/agents/code-style/magic-values.md` - read before writing a
  bare number or string into production code. A literal is magic when it is
  REPEATED (more than one place, in a file or between classes), when a number
  carries an invisible UNIT (`* 1000`, `> 2000.0`), or when a string belongs to
  a closed set that already has an OWNER (`AppEnv`, `HttpConstants`). A unique
  description read in one place, and `0`/`1`/`2`/`-1` in a structural role, stay
  literals. Cure a repeated number with a constant whose name carries the unit,
  never the digits; two quantities that merely happen to be equal get two
  constants. Checked automatically by `MAGIC-REPEAT`, which sees numbers only,
  one file at a time.
- Internal backend API contracts: `docs/agents/code-style/internal-backend-api.md`
- Method command/predicate/result contracts:
  `docs/agents/code-style/method-contracts.md` - also owns the empty string as a
  marker for "no value": read the section before writing `?? ''`. Checked
  automatically by `EMPTY-STRING-SENTINEL` inside its path zone.
- Static factory `self`/`static` return contract:
  `docs/agents/code-style/static-factories.md` - read when adding or changing a
  static factory (`fromArray`, `fromRow`, `create`, named constructors).
- Reflection in production PHP: `docs/agents/code-style/reflection.md` - read
  before adding or changing a `Reflection*` call in `framework/backend` or
  `demo/*/backend`. Adding one is the owner's decision, not the agent's; every
  surviving call carries an inline comment saying what it asks and why plain
  PHP does not answer.
- Error suppression: `docs/agents/code-style/error-suppression.md` - read
  before writing `@` in front of a call in `framework/backend` or
  `demo/*/backend`. `@` is not a warning silencer: a failing builtin becomes a
  typed exception, a checked error code, or a documented degrade. A surviving
  suppression carries a marker on the line directly above it -
  `// warning-suppressed: <what is checked instead of the warning>` - and the
  `ERROR-SUPPRESSION` guard fails `test:framework:unit` without it.
- Temporary/local variable rules: `docs/agents/code-style/local-variables.md`
- PHP class member order: `docs/agents/code-style/php-class-members.md` -
  read when adding or reordering class constants, properties, or methods.
- Frontend row-payload keys: `docs/agents/code-style/wire-key-ownership.md` -
  read when naming a row-payload key in a frontend admin module: adding or
  renaming a table column, writing a row resolver, or deciding what the core
  barrel exports.
- Database table names: `docs/agents/code-style/table-names.md` - read when
  naming a DB table; entity first then purpose, bridge tables order both
  entities by project dominance.
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
6. Always add PHPDoc when creating or changing a public/protected method; include
   applicable `@param`, `@return` (non-void), and `@throws` tags, each with a very
   short comment after the type. Omit the free-text summary only when it would
   repeat the signature or tag comments (see `docs/agents/code-style/phpdoc.md`
   rules 3-6).
7. Keep action routing and handler code aligned with the page action handler guide.
8. Keep named signal routing aligned with the signal handler guide.
9. Use typed parameters, DTOs, value objects, or typed collections for internal
   backend API; keep unstructured arrays at boundaries.
10. Do not return `bool` as a success flag from methods that perform work; use
   `void` plus exceptions, or return the produced domain value.
11. Keep `get*()` methods non-consuming. If retrieving a value clears it or
   advances state, name the method for that mutation, such as `consumeResult()`.
12. Avoid one-use locals and prefer inline nullsafe access for one immediate
   nullable member call under the local variable rule.
13. Avoid pass-through locals and DB/RT item aliases; keep known-key
   `Hilos::$db/$rt->collection[$key]` access and context item aliases such as
   `Hilos::$rt->selfConnection` visible unless the local variable adds domain
   meaning, snapshots state, or performs type narrowing that cannot be expressed
   by a guard.
   In concrete `Runtime/View/Item/*`, do not add local `/** @var StateFoo $state */`
   aliases for `$this->_state`; rely on `@extends RtItem<StateFoo>` and read
   `$this->_state->...` directly.
   In bridge `__get()` methods, do not alias the current item's own id or
   foreign key just to pass it into relation lookups.
14. Use named constants for action names, signal names, route params, model
   fields, DTO payload keys, table row keys, and boundary array keys whenever
   a constant exists. If a repeated payload key has no owner constant, add one
   to the owning DTO, browser payload, table row, entity, object, or context before
   using that key in examples or code. On the frontend, which module owns the
   constant and when it reaches the core barrel is
   `docs/agents/code-style/wire-key-ownership.md`.
15. During refactors, do not add new convenience read helpers or predicates
   such as `has*()`, `is*()`, `can*()`, or `get*()` on DB/RT View items,
   collections, objects, actions, or read payload objects unless the user explicitly
   approved that exact method in the plan. Prefer explicit field access when
   preserving transparent data shape is the goal.
16. Keep comments concise and in English.
17. For `Page::onAction()`, do not add a local `try/catch` around the routing
   `switch`; the framework catches action exceptions and calls
   `onActionException()`.
18. For named signal handlers, use `switch ($name)` with explicit cases.
19. Override `onActionException()` only when the page has a specific fail/error
   contract; otherwise let the default framework `action_error` signal notify
   the initiator.
20. Use `AgentUnknownActionException` in `onAction()` default branches.
21. Do not add empty `default` branches. If a handler intentionally ignores
   shared broadcast names and the branch would only `return` or `break`, omit
   it and document the ignore contract in PHPDoc.
22. In PHPDoc, import referenced classes with `use` and reference short names;
   do not write leading-backslash fully qualified types. This covers BOTH type
   positions (`@throws \OutOfBoundsException`,
   `@property-read \Demo\Chat\Database\Actions\Item\UserActions $actions`) AND
   `{@see ...}` / `{@link ...}` cross-references (`{@see UserActions::rename}`,
   not `{@see \Demo\Chat\Database\Actions\Item\UserActions::rename}`) — even when
   the class is referenced only in the docblock, add the `use` rather than a
   leading-backslash FQN. Checked automatically by the `PHPDOC-FQN` guard on
   `test:framework:unit` — see `docs/agents/code-style/automated-checks.md`.
23. Use `ValidationException` and its children for user/business validation;
   read `docs/agents/code-style/exceptions.md` before changing exception types.
24. Before finishing a PHP method change, re-check the affected docblock against
   `docs/agents/code-style/phpdoc.md`.
25. Before finalizing PHPDoc, audit every direct callee in the method body,
   including `parent::` calls, return expressions, `match` arms, and magic
   property or array access that resolves to a documented local API. Propagate
   documented callee `@throws` when the method does not catch or convert them.
   For statically known magic property or array keys, inspect the exact
   resolved branch and do not propagate broad default-branch exceptions when
   that branch only returns a scalar/object field. For normal reads of
   documented `@property-read` magic properties, document branch exceptions on
   the implementing `__get()`, not on caller methods that only read the
   property. Apply the same rule to documented context/facade properties such
   as `Hilos::$fs->published`; audit only explicit calls or array access after
   the declared property value. Remove any `@throws` based only on assumed
   framework, DB, or signal failure.

## Hard Rules

- Never run `git commit` or `git push`.
- A static factory typed `: static` or `@return static` must return
  `new static()`, never `new self()`. Mark a class with no subclasses `final`
  and keep its body, native return type, and `@return` aligned.
- Use `?type` for nullable PHP types in code and regular PHPDoc, unless a documented exception applies.
- Do not use `@` to silence a PHP warning in production code. Where suppression
  survives, it carries `// warning-suppressed: <reason>` on the line directly
  above the call, and the result is checked in the same few lines. Never
  `error_get_last()` after a suppressed call.
- Do not add unrelated refactors while applying style cleanup.
- Do not add unapproved convenience helpers or predicates during refactors.
- Do not write repeated payload/model/table keys as magic strings when an owner
  constant exists or should exist (see `magic-values.md`).
- Do not leave magic-string keys in an internal structured array; use named
  constants at minimum and a value object preferably (see `magic-values.md`).
- Do not repeat a number, and do not write one whose unit is invisible at the
  place of use; name it with a constant that carries the unit (see
  `magic-values.md`).
- Declare PHP class constants before properties; read `php-class-members.md`
  when adding or reordering class members.

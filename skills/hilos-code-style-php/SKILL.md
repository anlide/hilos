---
name: hilos-code-style-php
description: Apply Hilos code style to backend PHP — PHPDoc and `@throws` contracts, exception choice, method contracts, payload readers (`fromArray`/`fromJson`), static factories, magic values, class member order, local variables, Reflection, and error suppression. Use when writing, reviewing, or refactoring a `.php` file under `framework/backend/**`, `demo/*/backend/**`, `scripts/**`, or `tests/**`, or when a PHP style guard fails. For frontend code use `$hilos-code-style-typescript` and its per-framework wrappers.
---

# Hilos Code Style — PHP

Use this skill for style-sensitive PHP edits and reviews. Start with `agents.md`,
then read the smallest rule that applies — not the whole catalog.

This wrapper only routes. When it disagrees with a rule file, the canon in
`docs/agents/` wins.

## Read First

| Rule file | Read when... |
|---|---|
| `docs/agents/code-style/README.md` | choosing which small rule applies, or the change is not covered below |
| `docs/agents/framework-development.md` | changing framework-level APIs, facade globals, extension points, or framework subsystem exceptions — via `$hilos-framework-development` |
| `docs/agents/code-style/qualified-names.md` | writing a class name in code — a `catch`, a `new`, a base class, a static access, or a type in a signature — or removing a leading backslash from one |
| `docs/agents/code-style/phpdoc.md` | creating a method, changing a signature, visibility, parameters, return type, or thrown exceptions, overriding a method, adding `@see` links, or explaining in a comment why a value can be trusted |
| `docs/agents/code-style/exceptions.md` | choosing an exception class, documenting `@throws`, handling validation or business errors |
| `docs/agents/code-style/method-contracts.md` | changing a return type, a success/failure contract, a command method, a predicate, or a result-consumption API — also owns the empty string minted as a "no value" marker, and reading a field inside a payload or runtime-row reader |
| `docs/agents/code-style/static-factories.md` | adding or changing `fromArray`, `fromRow`, `create`, or another named constructor |
| `docs/agents/code-style/internal-backend-api.md` | changing backend contracts, DB actions, table actions, DTO/value-object boundaries, or typed collections |
| `docs/agents/code-style/magic-values.md` | writing a bare number or string into production code |
| `docs/agents/code-style/page-action-handlers.md` | editing `Page::onAction()`, action DTO routing, or action acks/errors |
| `docs/agents/code-style/signal-handlers.md` | editing a named signal handler such as `onSignalAgent()` or `onSignalCron()` |
| `docs/agents/code-style/php-class-members.md` | adding or reordering class constants, properties, or methods |
| `docs/agents/code-style/line-length.md` | a line runs long, or a class declaration or a call no longer fits on one |
| `docs/agents/code-style/local-variables.md` | introducing a temporary or one-use local, or an item/state alias |
| `docs/agents/code-style/import-aliases-and-helper-names.md` | adding or changing a PHP import alias or a helper method name |
| `docs/agents/code-style/table-names.md` | naming a database table |
| `docs/agents/code-style/php-language-level.md` | choosing between an old and a new PHP syntax form, or weighing an 8.4-only construct |
| `docs/agents/code-style/reflection.md` | adding or changing a `Reflection*` call, or judging an existing one |
| `docs/agents/code-style/error-suppression.md` | writing `@` in front of a PHP call, or deciding how a failing builtin reports |
| `docs/agents/code-style/random-source.md` | drawing a random value — minting a token, id or name — or judging a `RandomHelper` call |
| `docs/agents/code-style/blocking-resolution.md` | turning a host name into an address, or judging a call that waits on a nameserver |
| `docs/agents/code-style/automated-checks.md` | a style guard failed, a rule should stop depending on memory, or the known-debt baseline needs a record |
| `docs/agents/code-style/cross-layer-field-names.md` | naming a data field that crosses DB → PHP → wire → TypeScript |
| `docs/agents/code-style/spelling.md` | writing an English identifier, string key, UI copy, comment, or PHPDoc |
| `docs/agents/code-style/scaffold-markers.md` | leaving code wired but intentionally uncalled |
| `docs/code-style.md` | the baseline: PSR-12, `declare(strict_types=1)`, LF endings |
| `docs/quality.md` | judging application quality beyond the local code shape |

## Hard Rules

- Audit every direct callee before finalizing a docblock and propagate its
  documented `@throws`; no guard checks propagation, so green tests prove nothing
  here (`phpdoc.md`).
- Two quantities that merely happen to be equal get two constants, and a repeated
  number is cured by a name carrying the unit, never the digits (`magic-values.md`).
- A constant named in camelCase declares a wire field key, so its value is
  camelCase too; the `WIRE-KEY-CASE` guard fails `test:framework:unit` otherwise
  (`cross-layer-field-names.md`).
- Do not return `bool` as a success flag from a method that performs work, and do
  not mint a "no value" marker with `?? ''`, a ternary branch or a `match`
  `default` arm — in a test suite either (`method-contracts.md`).
- A payload field has two roles and no third: `BaseDTO::require*` for one the
  signal has no meaning without, `optional*` for one the sender may omit. Falling
  back to `''`, `0` or `0.0` fails the `PAYLOAD-SENTINEL` guard in
  `test:framework:unit`, and the guard judges the runtime-row readers too —
  `fromRow()`, `hydrateBase()` and `hydrateOwn()` beside `fromArray()` /
  `fromJson()` (`method-contracts.md`).
- Inside `applyDiff()`, `applyBaseDiff()` or `applyOwnDiff()` an absent key means
  the field did not change, so read it with `RtState::patch*`; the same guard
  reports an `optional*` call there, which would clear a field the diff never
  carried (`method-contracts.md`).
- A factory typed `: static` or `@return static` returns `new static()`, never
  `new self()` (`static-factories.md`).
- A surviving `@` carries `// warning-suppressed: <what is checked instead>` on
  the line directly above the call, and the `ERROR-SUPPRESSION` guard fails
  `test:framework:unit` without it; the marker does not buy a file primitive the
  right to owe an exception outside `Hilos\Fs\FsPath`, which the `FS-SEAM` guard
  fails on in the same run (`error-suppression.md`).
- A line is at most 150 characters wide, counted in characters and not in bytes;
  the `LINE-LENGTH` guard fails `test:framework:unit` on a wider one, and only a
  heredoc body is outside it (`line-length.md`).
- Do not alias `$this->_state` in a concrete `Runtime/View/Item/*`, and do not
  add a pass-through local for a single immediate member call
  (`local-variables.md`).

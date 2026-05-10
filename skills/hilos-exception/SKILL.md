---
name: hilos-exception
description: Choose and document Hilos PHP exceptions, including ValidationException vs DatabaseException vs RtBaseException, TableActionException, page subscription errors, agent lifecycle errors, and PHPDoc @throws contracts.
---

# Hilos Exception Taxonomy

Use this skill when choosing exception classes, changing `throw new ...`,
reviewing `catch (...)`, or documenting `@throws` in Hilos PHP code.

## Read First

- Exception taxonomy and examples: `docs/agents/code-style/exceptions.md`
- PHPDoc rules for `@throws`: `docs/agents/code-style/phpdoc.md`
- Page action error conversion: `docs/agents/code-style/page-action-handlers.md`

## Workflow

1. Identify the subsystem: validation/business input, table action, database,
   runtime state, page subscription, agent lifecycle, or framework invariant.
2. Pick the narrowest exception family from `docs/agents/code-style/exceptions.md`.
3. For user/business validation, use `ValidationException` or a child.
4. Do not use `RtBaseException` for DB action validation.
5. Do not use `DatabaseException` for domain validation.
6. Audit direct throws, documented direct callees, and converted exceptions,
   then update `@throws` to describe the caller-facing contract with a short
   reason. For statically known magic property or array keys, inspect the exact
   resolved branch instead of propagating the whole generic `__get()` or
   `offsetGet()` contract; for normal reads of documented `@property-read`
   magic properties, document branch exceptions on the implementing `__get()`,
   not on caller methods that only read the property. Apply the same rule to
   documented context/facade properties such as `Hilos::$fs->published`.
7. If a page action catches `Throwable`, verify the thrown exception becomes the
   expected user-facing fail/error signal.

## Hard Rules

- Never run `git commit` or `git push`.
- Keep validation/business errors under `ValidationException`.
- Keep `RtBaseException` limited to `Hilos::$rt` runtime state failures.
- Keep `DatabaseException` limited to DB infrastructure, SQL, schema, migration,
  and query failures.

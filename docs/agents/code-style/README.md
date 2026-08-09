# Code Style Rule Catalog

Use this catalog before editing project code. Pick the smallest rule file
that matches the change; do not read every rule file by default.

Before changing framework-level APIs, facade globals, extension points, or
framework subsystem exceptions, read [framework-development.md](../framework-development.md).

This catalog is one catalog for both languages: it splits rules by subject, not
by language, because a subject such as `cross-layer-field-names.md` has no side.
The language boundary is the *Applies to* column — `PHP`, `frontend`, or `both`.
The matching skill wrapper reads that column: `$hilos-code-style-php` routes to
the `PHP` and `both` rows, `$hilos-code-style-typescript` to the `frontend` and
`both` rows, and the `-vue` / `-react` / `-angular` wrappers add their view
layer's specifics on top.

| File | Applies to | Read when... |
|---|---|---|
| [automated-checks.md](automated-checks.md) | PHP | a guard test failed on a style rule, a rule should stop depending on memory, or the known-debt baseline needs a record |
| [phpdoc.md](phpdoc.md) | PHP | writing or changing PHPDoc, overriding inherited methods, adding `@see` links |
| [exceptions.md](exceptions.md) | PHP | choosing exception classes, documenting `@throws`, handling validation/business errors |
| [page-action-handlers.md](page-action-handlers.md) | PHP | editing `Page::onAction()`, action DTO routing, action acks/errors |
| [signal-handlers.md](signal-handlers.md) | PHP | editing named signal handlers such as `onSignalAgent()` or `onSignalCron()` |
| [magic-values.md](magic-values.md) | PHP | writing a bare number or string into production code — when a literal is magic, when it is data, and what names its cure carries |
| [internal-backend-api.md](internal-backend-api.md) | PHP | changing backend contracts, DB actions, table actions, DTO/value object boundaries, or typed collections |
| [method-contracts.md](method-contracts.md) | PHP | changing method return types, success/failure contracts, command methods, predicates, or result consumption APIs |
| [static-factories.md](static-factories.md) | PHP | adding or changing a static factory (`fromArray`, `fromRow`, `create`, named constructors) or its `self`/`static` return contract |
| [import-aliases-and-helper-names.md](import-aliases-and-helper-names.md) | PHP | adding or changing PHP import aliases or helper method names |
| [frontend-import-paths.md](frontend-import-paths.md) | frontend | adding or changing a relative import in frontend TypeScript — explicit `.js` extension, the barrel `index.js`, the "Import can be shortened" warning |
| [cross-layer-field-names.md](cross-layer-field-names.md) | both | naming a data field that crosses layers — one concept name from DB column to PHP entity to wire key to TypeScript field |
| [wire-key-ownership.md](wire-key-ownership.md) | frontend | naming a row-payload key in a frontend admin module — adding or renaming a table column, writing a row resolver, deciding what the core barrel exports |
| [table-names.md](table-names.md) | PHP | naming a database table — entity first then purpose; bridge tables order both entities by project dominance |
| [php-class-members.md](php-class-members.md) | PHP | adding or reordering PHP class constants, properties, or methods |
| [reflection.md](reflection.md) | PHP | adding or changing a Reflection call, or wondering whether an existing one is justified |
| [error-suppression.md](error-suppression.md) | PHP | writing `@` in front of a PHP call — how a failing builtin reports: exception, checked error code, or a marked degrade |
| [php-language-level.md](php-language-level.md) | PHP | choosing between an old and a new PHP syntax form, or wondering whether an 8.4-only construct is allowed |
| [local-variables.md](local-variables.md) | PHP | introducing temporary/local variables or reviewing noisy one-use variables |
| [spelling.md](spelling.md) | both | writing English identifiers, string keys, UI copy, comments, or docs — which dialect to use |
| [scaffold-markers.md](scaffold-markers.md) | both | leaving code/config wired but intentionally unused — marking a scaffold or a deliberate keep so a dead-code sweep does not cull it |
| [warnings-and-ide.md](warnings-and-ide.md) | frontend | silencing a toolchain/IDE warning — the zero-warning bar, the canonical-shape-over-suppression priority, TSDoc-all-params, Angular data-id placement |

The rules here complement the architecture guides in `docs/agents/`.
Architecture rules decide where code belongs; code style rules decide how the
local implementation should look once that location is known.

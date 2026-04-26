# Code Style Rule Catalog

Use this catalog before editing project code. Pick the smallest rule file
that matches the change; do not read every rule file by default.

| File | Read when... |
|---|---|
| [phpdoc.md](phpdoc.md) | writing or changing PHPDoc, overriding inherited methods, adding `@see` links |
| [exceptions.md](exceptions.md) | choosing exception classes, documenting `@throws`, handling validation/business errors |
| [page-action-handlers.md](page-action-handlers.md) | editing `Page::onAction()`, action DTO routing, action acks/errors |
| [internal-backend-api.md](internal-backend-api.md) | changing backend contracts, DB actions, table actions, DTO/value object boundaries, or typed collections |
| [import-aliases-and-helper-names.md](import-aliases-and-helper-names.md) | adding or changing PHP import aliases or helper method names |
| [local-variables.md](local-variables.md) | introducing temporary/local variables or reviewing noisy one-use variables |
| [frontend-vue.md](frontend-vue.md) | editing Vue SFC templates, global components, or frontend line endings |

The rules here complement the architecture guides in `docs/agents/`.
Architecture rules decide where code belongs; code style rules decide how the
local implementation should look once that location is known.

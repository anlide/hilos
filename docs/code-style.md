# Code Style Guide

This file is the stable entry point for project code style. Detailed rules are
split into small agent guides so agents can read only the rule that applies to
the current change.

## Agent Rule Catalog

Use [docs/agents/code-style/README.md](agents/code-style/README.md) as the
catalog for small code-style rules.

| Rule | Read when... |
|---|---|
| [PHPDoc](agents/code-style/phpdoc.md) | writing or changing PHPDoc, overrides, `@see` links |
| [Page Action Handlers](agents/code-style/page-action-handlers.md) | editing `Page::onAction()` and action handlers |
| [Local Variables](agents/code-style/local-variables.md) | introducing temporary variables or reviewing one-use locals |

## Baseline

- PHP follows PSR-12 style with 4-space indentation.
- Every project PHP file starts with `declare(strict_types=1);`.
- Repository text files use LF line endings; `.gitattributes` enforces this
  with `* text=auto eol=lf`.
- Use project architecture docs in `docs/agents/` before inventing a new
  backend pattern.
- Prefer local project helpers and existing abstractions over new generic
  layers.
- Keep comments and PHPDoc contractual; remove boilerplate that repeats the
  signature.

## Planned Rule Areas

The old content in this file was a draft plan, not active rules. Keep these
areas as backlog for future small rule files:

- PHP backend: namespaces, class/method/property naming, imports, line length,
  PHPDoc details.
- TypeScript/Vue frontend: Composition API, strict typing, component structure,
  file naming, imports.
- File and directory structure: backend layout, Entity/Object/Db placement,
  migration naming.
- Naming conventions: tables, database columns, entity properties,
  object-exclude directives.
- Error handling and logging: throw vs log, exception hierarchy, log levels.
- Database and ORM: Entity -> Object -> Db flow, runtime ORM, frontend ORM,
  generator/fixer commands, custom method preservation.
- Docker and environment: `.env` usage and compose naming.

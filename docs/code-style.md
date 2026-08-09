# Code Style Guide

This file is the stable entry point for project code style. Detailed rules are
split into small agent guides so agents can read only the rule that applies to
the current change.

## Agent Rule Catalog

Use [docs/agents/code-style/README.md](agents/code-style/README.md) as the
catalog for small code-style rules. It lists every rule file with the language it
applies to (PHP / frontend / both); this page does not repeat that list, so the
catalog stays the single place a rule is routed from.

## Baseline

- PHP follows PSR-12 style with 4-space indentation.
- Every project PHP file starts with `declare(strict_types=1);`.
- Repository text files use LF line endings; `.gitattributes` enforces this
  with `* text=auto eol=lf`.
- Use project architecture docs in `docs/agents/` before inventing a new
  backend pattern.
- Prefer local project helpers and existing abstractions over new generic
  layers.
- Use typed parameters, DTOs, value objects, or typed collections for internal
  backend API. Keep unstructured arrays at system boundaries.
- Keep comments and PHPDoc contractual. Every public/protected method docblock
  must include applicable `@param`, `@return` (non-void), and `@throws` tags,
  each with a very short comment after the type. Omit the free-text summary when
  it would only repeat the signature or tag comments.

## Planned Rule Areas

The old content in this file was a draft plan, not active rules. Keep these
areas as backlog for future small rule files:

- PHP backend: namespaces, class/method/property naming, imports, line length,
  PHPDoc details. Class member order is covered by
  [php-class-members.md](agents/code-style/php-class-members.md).
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

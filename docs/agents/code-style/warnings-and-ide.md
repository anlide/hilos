# Warnings and IDE Friction

Read this before silencing a toolchain or IDE warning. Keep the toolchain
warning-free; resolve IDE-side friction by a fixed priority, never a code crutch.

## Core Rule

Every change keeps the toolchain green. Frontend: `tsc` / `vue-tsc` / `eslint` /
`prettier` (run in-container). Backend: `php -l` and static analysis in the
container. A new warning is a defect to fix, not to tolerate — most of all in the
framework core, where a pristine toolchain is part of the product.

## Resolving IDE friction — the priority order

When an IDE (PhpStorm) flags something the real toolchain accepts, resolve it in
this order:

1. **Make the project shape canonical / the markup self-contained** — the form an
   ecosystem expects dissolves the warning structurally, with no IDE state.
2. **Scoped IDE-side suppression** where the flagged duplication or behavior is
   deliberate — a local scope or a per-machine mute, never a project-wide
   whitelist.
3. **Document and accept** a confirmed false positive that has no clean canonical
   form. If the convention is project-wide, graduate it into a `docs/agents/` rule
   so every agent knows the warning is ignored on purpose, rather than leaving it
   only in `.idea` or a personal note.

Never contort code for the IDE. When no clean canonical form exists, take the
document-and-accept path — do not add a code crutch.

## TSDoc lists every parameter

If a TSDoc block carries any `@param`, it lists **every** parameter — mirrors the
PHP full-PHPDoc rule ([phpdoc.md](phpdoc.md)). A pure-prose doc block with no
`@param` tags is also fine; the rule only forbids a partial `@param` list.

## Angular: data-id in the template, not the host

A static hyphenated attribute such as `data-id` goes in the **template** on a real
element, never on the Angular component `host`. Keep `host` to `class` plus
dynamic `[...]` / `(...)` bindings — a static hyphenated key in `host` trips the
IDE's host-binding parser and diverges from the Vue/React views, which render the
attribute on a real element.

## Backend

The same bar and priority apply to backend code: prefer the canonical PHP shape,
use scoped suppression only for a deliberate pattern, document-and-accept a
confirmed false positive, and never a crutch. Run `php -l` and analysis in the
container — the host PHP is stale (see [../cli/commands.md](../cli/commands.md)
and [../testing.md](../testing.md)).

This page is about warnings a *toolchain* raises about the code. A warning **PHP
itself** raises while the code runs is a different subject with a different
answer, and `@` is not it: read
[error-suppression.md](error-suppression.md) before suppressing one.

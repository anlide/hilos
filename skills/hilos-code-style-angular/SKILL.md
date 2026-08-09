---
name: hilos-code-style-angular
description: Apply Hilos code style to Angular components — the shared TypeScript rules plus the Angular specifics: a static hyphenated attribute such as `data-id` belongs in the template, never on the component `host`. Use when writing, reviewing, or refactoring a `.component.ts` or `.component.html` file under `framework/frontend/angular/**` or `demo/*/frontend/angular/**`.
---

# Hilos Code Style — Angular

Use this skill for style-sensitive edits to Angular components. Read
`$hilos-code-style-typescript` first: the rules for imports, wire keys, field
names, TSDoc, and spelling are shared, and this wrapper adds only what is
specific to the Angular view layer.

This wrapper only routes. When it disagrees with a rule file, the canon in
`docs/agents/` wins.

## Read First

| Route | Read when... |
|---|---|
| `$hilos-code-style-typescript` | always, first — the shared TypeScript rules the component class obeys |
| `docs/agents/code-style/warnings-and-ide.md` | placing `data-id` or another static attribute, silencing an `ng` / `eslint` / IDE warning, or writing a TSDoc block |

## Hard Rules

- A static hyphenated attribute such as `data-id` goes in the **template** on a
  real element, never on the component `host`; keep `host` to `class` plus
  dynamic `[...]` / `(...)` bindings (`warnings-and-ide.md`).
- Resolve an IDE warning the real toolchain accepts by making the shape
  canonical first, a scoped suppression second, document-and-accept last — never
  a code crutch (`warnings-and-ide.md`).

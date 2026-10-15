---
name: hilos-code-style-angular
description: "Apply Hilos Angular component style — shared TypeScript rules, native select/option value and selected bindings, and template-owned static data-id attributes. Use when writing, reviewing, or refactoring Angular `.ts` or `.html` components under `framework/frontend/angular/**` or an Angular demo’s `demo/*/frontend/src/**`, especially a native select or option."
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
| `docs/agents/code-style/angular-select-options.md` | writing a native select or option — option-owned selection, late options and empty choices |

## Hard Rules

- A native select leaves value unbound; every option with `[value]` also has
  `[selected]` (equivalently `bind-value` / `bind-selected`). A controlled empty
  option has its own selected predicate (`angular-select-options.md`).
- A static hyphenated attribute such as `data-id` goes in the **template** on a
  real element, never on the component `host`; keep `host` to `class` plus
  dynamic `[...]` / `(...)` bindings (`warnings-and-ide.md`).
- Resolve an IDE warning the real toolchain accepts by making the shape
  canonical first, a scoped suppression second, document-and-accept last — never
  a code crutch (`warnings-and-ide.md`).

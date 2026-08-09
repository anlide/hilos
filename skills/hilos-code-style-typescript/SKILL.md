---
name: hilos-code-style-typescript
description: Apply Hilos code style to frontend TypeScript — relative import paths and the `.js` extension, row-payload key ownership, cross-layer field names, the zero-warning bar and TSDoc, American spelling, and scaffold markers. Use when writing, reviewing, or refactoring a `.ts`/`.tsx` file under `framework/frontend/**` or `demo/*/frontend/**`, whatever the view framework. Vue, React, and Angular add their own wrapper on top of this one; for backend code use `$hilos-code-style-php`.
---

# Hilos Code Style — TypeScript

Use this skill for style-sensitive edits to frontend TypeScript — the agnostic
core, the SDK view packages, and the demos. Start with `agents.md`, then read the
smallest rule that applies.

The framework-specific wrappers (`$hilos-code-style-vue`,
`$hilos-code-style-react`, `$hilos-code-style-angular`) route here first and add
only what is specific to their view layer.

This wrapper only routes. When it disagrees with a rule file, the canon in
`docs/agents/` wins.

## Read First

| Rule file | Read when... |
|---|---|
| `docs/agents/code-style/README.md` | choosing which small rule applies, or the change is not covered below |
| `docs/agents/code-style/frontend-import-paths.md` | adding or changing a relative import — the explicit `.js` extension, the barrel `index.js`, the "Import can be shortened" warning |
| `docs/agents/code-style/wire-key-ownership.md` | naming a row-payload key in a frontend admin module — adding or renaming a table column, writing a row resolver, deciding what the core barrel exports |
| `docs/agents/code-style/warnings-and-ide.md` | silencing a toolchain or IDE warning, or writing a TSDoc block |
| `docs/agents/code-style/cross-layer-field-names.md` | naming a data field that crosses DB → PHP → wire → TypeScript |
| `docs/agents/code-style/spelling.md` | writing an English identifier, string key, route, UI copy, comment, or TSDoc |
| `docs/agents/code-style/scaffold-markers.md` | leaving code wired but intentionally uncalled |

## Hard Rules

- A relative import carries the explicit `.js` extension of its compiled target,
  and a barrel import names `index.js` in full — do not shorten `'…/index.js'` to
  the directory (`frontend-import-paths.md`).
- A row-payload key is declared once as a named constant in the `@hilos/core`
  module owning the view-model it resolves into; every site reads that constant
  (`wire-key-ownership.md`).
- Declare that constant as `<NAME>_FIELD` or as an entry of an `as const`
  `*RowKey` map: the form is what the `WIRE-KEY-CASE` guard reads, and a key
  declared outside it is judged by nobody (`wire-key-ownership.md`).
- Keep `tsc` / `vue-tsc` / `eslint` / `prettier` green, and a TSDoc block that
  carries any `@param` lists **every** parameter (`warnings-and-ide.md`).
- One data field keeps the same words through every layer — only the case
  convention changes (`snake_case` in SQL and PHP, `camelCase` on the wire and in
  TypeScript); no synonym, predicate prefix, or grammatical change at a boundary
  (`cross-layer-field-names.md`).
- Use American English spelling in identifiers, wire keys, routes, and UI copy
  (`spelling.md`).
- Code left wired but intentionally uncalled carries the marker at the code site,
  not only in the commit message (`scaffold-markers.md`).

---
name: hilos-code-style-vue
description: Apply Hilos code style to Vue single-file components — the shared TypeScript rules plus the Vue specifics: an SFC carries no `<style>` block, and styling is Bootstrap classes only. Use when writing, reviewing, or refactoring a `.vue` file under `framework/frontend/vue/**` or `demo/*/frontend/vue/**`, or when tempted to add CSS to a component.
---

# Hilos Code Style — Vue

Use this skill for style-sensitive edits to Vue SFCs. Read
`$hilos-code-style-typescript` first: the rules for imports, wire keys, field
names, TSDoc, and spelling are shared, and this wrapper adds only what is
specific to the Vue view layer.

This wrapper only routes. When it disagrees with a rule file, the canon in
`docs/agents/` wins.

## Read First

| Route | Read when... |
|---|---|
| `$hilos-code-style-typescript` | always, first — the shared TypeScript rules the `<script setup>` block obeys |
| `docs/agents/frontend/styling-rules.md` | styling anything: the Bootstrap-classes-only rule and the ban on the SFC `<style>` block |
| `docs/agents/code-style/warnings-and-ide.md` | silencing a `vue-tsc` / `eslint` / IDE warning, or writing a TSDoc block in `<script setup>` |

## Hard Rules

- An SFC carries **no** `<style>` block at all — not scoped, not a single rule;
  express styling with Bootstrap utility and component classes in the template
  (`styling-rules.md`).
- No inline `style` attribute, no global stylesheet, no hand-authored `.css` in
  app code; a custom declaration that truly cannot be a Bootstrap class goes into
  the Sass layer with a comment saying why (`styling-rules.md`).
- Resolve an IDE warning the real toolchain accepts by making the shape
  canonical first, a scoped suppression second, document-and-accept last — never
  a code crutch (`warnings-and-ide.md`).

---
name: hilos-code-style-react
description: Apply Hilos code style to React components — today that is the shared TypeScript rules and nothing else; React carries no code-shape rule of its own. Use when writing, reviewing, or refactoring a `.tsx` file under `framework/frontend/react/**` or `demo/*/frontend/react/**`.
---

# Hilos Code Style — React

Use this skill for style-sensitive edits to React components. Read
`$hilos-code-style-typescript` first: the rules for imports, wire keys, field
names, TSDoc, and spelling are shared.

**React has no code-shape specifics today.** This wrapper is a deliberate empty
slot, kept so the task shape "I am editing a `.tsx` view" reaches the shared
TypeScript rules the same way Vue and Angular do, and so a future React-only rule
has a place to land. Do not fill the gap by routing to a Vue or Angular rule:
those describe other view layers, and following them here produces code that
matches no canon.

This wrapper only routes. When it disagrees with a rule file, the canon in
`docs/agents/` wins.

## Read First

| Route | Read when... |
|---|---|
| `$hilos-code-style-typescript` | always — it carries every code-style rule that applies to a React file |

## Hard Rules

- There is no React-specific code-style rule. Follow
  `$hilos-code-style-typescript` and add nothing from the Vue or Angular
  wrappers.

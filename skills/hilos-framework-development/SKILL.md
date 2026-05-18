---
name: hilos-framework-development
description: Develop Hilos framework-level APIs and extension points safely. Use when changing framework/backend or framework/frontend shared APIs, Hilos facade globals, app topology registry, lifecycle factories, cross-project base classes, subsystem exception families, settings/catalog accessors, or docs/skills that govern framework development.
---

# Hilos Framework Development

Use this skill before changing framework-level contracts. Start with `AGENTS.md`,
then read `docs/agents/framework-development.md` and only the narrower skills
that match the affected subsystem.

## Read First

- Framework development rules: `docs/agents/framework-development.md`
- App topology registry: `docs/agents/app-topology.md`
- Style and PHPDoc contracts: use `$hilos-code-style`
- Exception families and `@throws`: use `$hilos-exception`
- Facade, magic, array, and key-based accessors: use `$hilos-accessor-contracts`
- DB model changes: use `$hilos-orm` and respect the contract approval gate
- Runtime state changes: use `$hilos-runtime` and respect the contract approval gate
- Frontend framework views/components: use `$hilos-frontend-sdk` and
  `docs/agents/code-style/frontend-vue.md`

## Workflow

1. Identify whether the change is framework code or application/demo code.
2. If it changes a shared framework API, read
   `docs/agents/framework-development.md` before editing.
3. If it changes `Hilos` topology constants, read
   `docs/agents/app-topology.md` before editing.
4. Check the contract approval gate before changing DB entity shape, RT item
   shape, signals, DTO payloads, or routing.
5. Prefer existing Hilos facade/global accessors and extension hooks. Do not
   pass `Hilos::$db`, `Hilos::$rt`, `Hilos::$setting`, or similar globals
   through constructors or helper parameters just to reach code that can read
   the facade itself.
6. For project-specific behavior, add or use a protected factory/override point
   on the framework class, then let the project subclass return `new ProjectX()`.
7. Keep catalog-style arrays inside catalog definitions or typed boundaries; do
   not carry them deeper as generic constructor state.
8. When a framework subsystem needs caller-facing failures, add a subsystem base
   exception under `HilosException` and concrete children instead of throwing a
   generic unrelated exception.
9. Keep framework command methods as `void` plus exceptions, or return the
   produced domain value. Do not return `bool` as a success flag.
10. Keep `get*()` methods non-consuming; use names such as `consumeResult()` for
   retrieval that clears buffers or advances state.
11. Update PHPDoc `@throws` with short caller-facing reasons for every exception
   that the public/protected contract deliberately exposes.
12. Validate through composer scripts selected by `$hilos-testing-cli`.

## Hard Rules

- Never run `git commit` or `git push`.
- Do not pass already-global Hilos facade dependencies as routine parameters.
- Do not duplicate `Hilos` topology constants in local page, route, browser, or
  table lists.
- Do not require DB initialization for catalog/static/default metadata unless
  persisted values are actually being read.
- Do not hide framework extension behind project services or repositories.
- Do not use unstructured arrays in internal framework APIs unless the local
  docs name that catalog/map shape as an explicit exception.

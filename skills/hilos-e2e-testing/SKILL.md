---
name: hilos-e2e-testing
description: Author or edit a Hilos e2e (Playwright) spec or shared helper — the rules for selectors, filling inputs, driving submit buttons, and waiting on state that arrives over the socket. Use when creating or changing an e2e spec/helper, or before adding form input, clicks, or state assertions to a Playwright test. For which suites to run use $hilos-selective-testing; for how to run them use $hilos-testing-cli.
---

# Hilos E2E Testing

Use this skill when **creating or editing an e2e (Playwright) spec or a shared
e2e helper** — the mechanics of how a spec drives the UI. For deciding *which*
suites a change warrants use `$hilos-selective-testing`; for *how to run* a
chosen command use `$hilos-testing-cli`. This skill is how a spec is *written*.

## Read First

- Selectors, filling inputs, submit discipline, source-vs-build, the test
  categories: `docs/agents/frontend/testing-strategy.md`.
- Full-reset-per-test and the re-run contract: `docs/agents/testing.md`.

## Workflow

1. Select interactive elements only by **stable `data-id`** — never by text or
   position.
2. Enter a value with `fill('')` to clear, then
   `pressSequentially(value, { delay: 10 })` — never a bare `fill(value)`, which
   can miss view reactivity and submit a stale payload.
3. Drive a submit button through its actionable states: scroll into view →
   assert visible → assert enabled → focus → click; then wait for the form to
   leave **or** the button to re-enable.
4. After triggering an action, wait for it to **settle** — loading cleared, the
   surface/dialog closed on success, or the inline error shown — before asserting
   its result. Asserting through an in-flight action races the reply and flakes.
5. Assert on **state that arrives over the socket**, not on fixed timeouts.
6. e2e runs the **built artifact** with a booted daemon — rebuild after a
   frontend change before the spec exercises it, and reset before re-running a
   data-mutating spec.

## Hard Rules

- Select by stable `data-id` only — never by text or position.
- Never set an input value with `fill(value)` — clear with `fill('')`, then
  `pressSequentially`.
- Never assert an action's result while the action is still in flight — wait for
  it to settle (success or error) first.
- Never run `git commit` or `git push`.

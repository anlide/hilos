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
  categories, and the shared toolbox:
  `docs/agents/frontend/testing-strategy.md`.
- What is already in the shared toolbox: `framework/frontend/e2e/index.ts`.
- Full-reset-per-test and the re-run contract: `docs/agents/testing.md`.
- The spec needs an external service on the stand — adding a channel or an
  emulator, a stub against an emulator, where a caught message lands:
  `docs/agents/stand-services.md`.

## Workflow

1. Before writing driving code, look in the shared toolbox
   (`framework/frontend/e2e/`) and **prefer what is already there**. It holds
   what reads the same in any demo; a demo's own `tests/e2e/helpers/` holds what
   belongs to that demo alone.
2. When the spec needs a wait, a sweep or a round trip another demo would need in
   the same words, **put it in the toolbox** rather than in a fourth local copy —
   that is how the folder grows.
3. Select interactive elements only by **stable `data-id`** — never by text or
   position.
4. Enter a value with `fill('')` to clear, then
   `pressSequentially(value, { delay: 10 })` — never a bare `fill(value)`, which
   can miss view reactivity and submit a stale payload.
5. Drive a submit button through its actionable states: scroll into view →
   assert visible → assert enabled → focus → click; then wait for the form to
   leave **or** the button to re-enable.
6. After triggering an action, wait for it to **settle** — loading cleared, the
   surface/dialog closed on success, or the inline error shown — before asserting
   its result. Asserting through an in-flight action races the reply and flakes.
7. Assert on **state that arrives over the socket**, not on fixed timeouts.
8. Where the action makes the application **reload itself** — today the lift of
   protected mode — do not navigate at all: mark the document beforehand with
   `markDocument`, wait for the mark to go with `expectSelfReload`, then add
   `expectPageReady` if the spec asserts what is on screen, or a `gotoPage` if it
   needs a different address. Both navigations carry the same url, so the failure
   reads as a browser oddity rather than as a race.
9. When a toast raised by a previous step is only in the way, sweep the stack
   with `dismissToasts(page)` before the click it covers. A spec that is *about*
   a notice asserts on it instead and never sweeps.
10. Measure geometry through the toolbox's `watchTop`, `watchHeight` or
    `watchFirstRowTop`: keep the bookmark and call its `unchanged()` after
    waiting for the expected state. Do not take or compare raw boxes.
11. e2e runs the **built artifact** with a booted daemon — rebuild after a
    frontend change before the spec exercises it, and reset before re-running a
    data-mutating spec.

## Hard Rules

- Select by stable `data-id` only — never by text or position.
- Never set an input value with `fill(value)` — clear with `fill('')`, then
  `pressSequentially`.
- Never assert an action's result while the action is still in flight — wait for
  it to settle (success or error) first.
- Never navigate a page the application is about to reload by itself — wait the
  reload out instead, and steer afterwards if a different address is wanted.
- Never measure a box in a spec — take a bookmark from the toolbox and ask it
  whether anything moved.
- A file in `framework/frontend/e2e/` imports from `@playwright/test` with
  `import type` and nothing else — a value import loads a second Playwright and
  the runner refuses the run.
- Never run `git commit` or `git push`.

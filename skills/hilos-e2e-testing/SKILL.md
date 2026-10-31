---
name: hilos-e2e-testing
description: >
  Choose or review Hilos e2e coverage before adding a spec or test; create,
  edit, move, split or port specs and helpers, including Node-only network
  wrappers. Use when extending an entity scenario, preparing command fixtures
  and cleanup, distinguishing behavior from cosmetics, choosing a demo or
  spec owner, reusing helpers, or adding selectors, input, submit, socket-state
  assertions, themes or viewports to a Playwright test.
  For which suites to run use $hilos-selective-testing; for how to run them
  use $hilos-testing-cli.
---

# Hilos E2E Testing

Use this skill when **choosing or reviewing e2e coverage**, or creating, editing,
moving, splitting or porting a spec or helper, including a Node-only network
wrapper. It routes scenario choice to the canon and browser/network mechanics
to their shared homes. For deciding *which* suites a
change warrants use `$hilos-selective-testing`; for *how to run* a chosen command
use `$hilos-testing-cli`.

## Read First

- Before adding a spec/test or reviewing assertions:
  `docs/agents/frontend/testing-strategy.md`, "Choose the scenario before adding
  coverage" and "What assertions prove — seven rules", including the behavioral,
  accessibility and geometry exceptions.
- Which demo a new spec belongs in, and whether it carries a `spec-owner`
  line: `docs/agents/frontend/testing-strategy.md`, "Which demo carries a spec".
- Selectors — stable action hooks and semantic h1 lookup — filling inputs,
  submit discipline, source-vs-build, test categories and the shared toolbox:
  `docs/agents/frontend/testing-strategy.md`.
- Before writing or copying helpers, inspect both shared homes:
  `framework/frontend/e2e/index.ts` and the relevant
  `framework/frontend/scripts/*.mjs` modules — for example `standCommand.mjs`,
  `commandChannel.mjs`, `i18n.mjs`, `i18nFixtures.mjs`, `testUser.mjs`, `mail.mjs`
  and `standMailbox.mjs`.
- Test-owned setup/cleanup and account preparation:
  `docs/agents/frontend/testing-strategy.md`, "Preconditions — test commands with
  owned cleanup" and "People and administrators as preconditions".
- Clean cycles between runs, with shared DB/daemon inside a suite:
  `docs/agents/frontend/testing-strategy.md`, "Backend state"; re-run mechanics
  in `docs/agents/testing.md`, "Re-running tests and state between runs".
- Static quarantine tags and affected parameterized variants:
  `docs/agents/frontend/testing-strategy.md`, "Quarantine — a static tag and one attempt after main".
- The spec needs an external service on the stand — adding a channel or an
  emulator, a stub against an emulator, where a caught message lands:
  `docs/agents/stand-services.md`.

## Workflow

1. Before adding a test/spec, name action → visible result → unique failure not
   already caught by unit tests or an existing scenario. Find the entity's
   existing journey and extend it with `test.step` when appropriate. A new entity
   or independent behavioral boundary may need a new scenario; a ticket or CRUD
   command alone does not. A cosmetic change adds no e2e; read the canon's
   assertion rules and exceptions when reviewing mixed tests.
2. Only after justifying a new file, choose its demo: a file that checks the server
   goes to the least-loaded demo where its feature is on and its stand carries
   the needed services — the shortest `check` + `php` + `e2e` chain in the
   summary of the latest green full run, not in `$demos` of
   `scripts/test-suite.php`. A new test in an existing spec goes into that
   spec. A spec of a screen stays with its view framework and area; a feature
   switched on only in chat leaves chat as the only candidate.
3. Whenever you touch a spec, check whose it is: `demo` needs that demo's
   product, `stand` checks an emulator without the product, and a framework
   spec could travel by switching its feature on. Add or fix the single
   `// spec-owner: demo — <why>` or `// spec-owner: stand — <why>` line under
   the imports, above the header; a framework spec has no line. Whole tests
   of both sides in one file mean a split, with the framework half keeping
   the file name; one assertion of the other side does not.
4. Run the test in the light theme at the desktop viewport its config gives
   it: no dark-theme variant, no loop over palettes or widths, no narrowed
   viewport, no check of what only a narrow screen draws. Only the theme
   feature's own specs choose dark, to prove the pick:
   `docs/agents/frontend/testing-strategy.md`, "Light theme, desktop width".
5. Before driving code or a move/split/port, inspect both toolbox homes above
   and use the existing calls. A moved spec changes imports and product
   composition; it does not copy helpers. A demo's `tests/e2e/helpers/` keeps
   only its own fixtures and composition; see the Chat sign-up example in
   "The shared toolbox" of `docs/agents/frontend/testing-strategy.md`.
6. An operation with the same meaning for another demo, view framework or stand
   belongs in the shared home **from its first consumer**: browser action/check
   in `framework/frontend/e2e/`, network-only work in
   `framework/frontend/scripts/`. Read stand addresses from the runner
   environment through the shared helpers. Checks receive the spec's `expect`;
   scenario order and navigation stay in the spec.
7. Use test-command operations for available preconditions with test-scoped,
   awaited cleanup, including failures and timeouts. Reserve cleanup before a
   UI creation that is itself the subject. Prepare an ordinary admin once per
   scenario with `grantAdminToSelf`; use existing account helpers when needed.
   Keep subject-specific UI sign-up/session transitions; follow the canon's
   ownership, teardown and missing-command contract rules.
8. Select actions by **stable `data-id`**, not text or position; find the page
   heading with `getByRole('heading', { level: 1 })`. The hook is a locator, not
   an outcome to assert with `toHaveAttribute('data-id', ...)`.
9. Enter an ordinary value with `await typeInto(field, value)`. Standalone
   clearing and the native-date, disabled-input and append cases keep their
   specific keyboard behavior; see "Filling inputs" in
   `docs/agents/frontend/testing-strategy.md`.
10. Submit with `await clickSubmit(expect, button)`. It retries preparation before
    one click; the calling scenario then waits explicitly for its action's
    success or refusal. The helper does not wait for server or subscription data.
11. After triggering an action, wait for it to **settle** — loading cleared, the
    surface/dialog closed on success, or the inline error shown — before asserting
    its result. Asserting through an in-flight action races the reply and flakes.
12. Assert on **state that arrives over the socket**, not on fixed timeouts.
13. Where the action makes the application **reload itself** — today the lift of
    protected mode — do not navigate at all: mark the document beforehand with
    `markDocument`, wait for the mark to go with `expectSelfReload`, then add
    `expectPageReady` if the spec asserts what is on screen, or a `gotoPage` if it
    needs a different address. Both navigations carry the same url, so the failure
    reads as a browser oddity rather than as a race.
14. When a toast raised by a previous step is only in the way, sweep the stack
    with `dismissToasts(page)` before the click it covers. A spec that is
    *about* a notice asserts on it instead and never sweeps.
15. Measure geometry through the toolbox's `watchTop`, `watchHeight` or
    `watchFirstRowTop`: keep the bookmark and call its `unchanged()` after
    waiting for the expected state. Do not take or compare raw boxes.
16. e2e runs the **built artifact** with a booted daemon — rebuild after a
    frontend change before the spec exercises it, and reset before re-running a
    data-mutating spec.
17. A test whose own work outlives the base cap declares its own ceiling —
    `test.slow()` or a named factor over `test.info().timeout` — with the reason
    in a comment: `docs/agents/frontend/testing-strategy.md`, "A test longer than
    the cap declares its own".
18. A test that ran out of its cap gets 15 seconds more only when it was already
    near the cap on the base (two thirds or more), passes alone with the extra,
    and its steps are no slower than on the base. Otherwise the timeout is a
    finding — a slowdown or a hang — not a budget: same file, "A test that ran
    out of its cap".

19. When parking an identified flake, keep its reason, date and body and declare
    `@quarantine:F-NNN` statically on the affected variant; follow the linked
    quarantine section instead of adding a runtime `test.fixme`. A test that
    failed on its own test timeout is not quarantined: switch it off with a
    static `test.fixme`, and its cluster phase in `FLAKY_SKIP`, per the same
    section.

## Hard Rules

- Choose coverage before a new test/file; extend the existing journey when it
  fits. Assert behavior, not cosmetics, under the canon's seven rules and
  exceptions; a cosmetic change adds no e2e.
- Use available test-command preconditions and owned cleanup; UI creation or
  sign-up stays when it is the subject. Prepare the admin/account once per
  scenario with the existing toolbox, without sharing away session isolation.
- Run every test in the light theme at the desktop width: never narrow the
  viewport and never repeat or check a screen in the dark theme. Only the theme
  feature's own specs choose dark, to prove the pick.
- A spec of a demo's own product or of the stand carries
  `// spec-owner: demo|stand — <why>`; a framework spec carries none.
- Select actions by stable `data-id`; find the page h1 by role. Do not assert
  the hook itself or replace action hooks with text/position selectors.
- Reuse both toolbox homes when creating or moving specs/helpers; put a missing
  shared operation there with its first consumer, not after a second copy.
- Use `typeInto` for ordinary input and `clickSubmit` for submit preparation,
  instead of repeating their steps or setting a value with bare `fill(value)`.
  Keep the input exceptions in the canon and the action's outcome wait in the
  caller.
- Never assert an action's result while the action is still in flight — wait for
  it to settle (success or error) first.
- Never navigate a page the application is about to reload by itself — wait the
  reload out instead, and steer afterwards if a different address is wanted.
- Never measure a box in a spec — take a bookmark from the toolbox and ask it
  whether anything moved.
- Never lean on the host timeout factor, and never add a floor under it to cure
  one test.
- Never raise a test's cap that was not already near it on the base, and never
  raise an `expect`, action or navigation timeout to cure a red test.
- A file in `framework/frontend/e2e/` imports from `@playwright/test` with
  `import type` and nothing else — a value import loads a second Playwright and
  the runner refuses the run. Checks, including `clickSubmit` preparation, take
  the spec's `expect` as their first parameter; helpers without checks need not.
- Inside either toolbox, import the sibling module directly, not its own index
  barrel.
- Never run `git commit` or `git push`.

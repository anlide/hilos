# Testing Strategy

Two kinds of test: **unit** (vitest, across the whole monorepo) and **end-to-end**
(Playwright, multi-context). The hard real-time features — sync, conflict, groups
— are exercised by multi-client e2e, which is why the e2e categories below are
first-class, not an afterthought.

## Unit tests — vitest across the monorepo

Unit testing is **vitest** everywhere. Because the agnostic core is framework-free
([multiframework-core.md](multiframework-core.md)), most logic — protocol parsing, stores, the headless
table and conflict state machines — is unit-tested with **no browser** at all.
Per-framework view layers test with vitest too:

- the React slice uses vitest + `@testing-library/react`;
- the Angular slice uses vitest via the Angular CLI's native unit-test builder
  (and leans on e2e for any part too fiddly to drive in vitest — the core logic
  is already covered framework-free).

## End-to-end tests — Playwright, multi-context

End-to-end uses **Playwright**, with **one** test driving **N** browser contexts
against one live daemon. The multi-context shape is what makes the broadcast and
cross-user features testable at all.

### The three categories

1. **Main features** — a single client exercising a feature end to end.
2. **Same-user broadcast** — two tabs of the **same user**: an edit in one tab
   must appear in the other (the User scope fan-out, [data-model.md](data-model.md)).
3. **Three different users** — three users exercising cross-user behavior:
   **conflict resolution** ([conflict-resolution.md](conflict-resolution.md)) and **group subscriptions**
   ([wire-protocol.md](wire-protocol.md)). The hardest features require this category to test, so it
   is built in from the start.

## Backend state — full reset per test

Each test gets a **full reset of the database and daemon** — the established
approach, fast enough in practice. This is deliberately **not**
transaction-rollback (a poor fit for a long-running, multi-worker WS daemon) and
**not** data-namespacing. A test starts from a known, seeded state.

## Selectors — stable ids only

e2e interacts through **stable element ids** only: every interactive element
carries an id, and tests never use text- or position-based selectors. This keeps
e2e robust against copy and layout changes.

## Opening a page — `gotoPage`, never `goto`

A spec opens a page through the demo's **`gotoPage(page, path)`** wrapper, which
waits for the subscription's answer — the answer, not a good answer, since a page
closed to a guest answers with a refusal and the specs that walk into one are
asserting exactly that. Pass `PAGE_REFUSED` (or `PAGE_READY`) as the third
argument only when the spec is about which answer came. **Never call Playwright's
`goto` directly**; `E2E-PAGE-GOTO`
(`framework/frontend/codestyle/e2eGoto.ts`) reports every direct call outside the
`helpers/page.ts` that owns the wrappers.

`goto` waits for the document and nothing else. The page behind it is a live
subscription, and its answer — the payload, or a refusal the gate raises — comes
one round trip later; until it lands the routed outlet holds the page back. A
spec that navigated and asserted straight away was therefore racing the round
trip: it passed while the DOM query outran the answer, and failed when it did
not. That failure reads as a flaky element, which is why such a race can sit in a
suite for days being retried instead of being fixed. The wrappers wait on the
outlet's own state (`hilos-page-state`: `loading` → `ready` or `error`), so the
spec resumes exactly when the page is settled and a refusal is reported as a
refusal rather than as a missing element.

The same applies to the second window of a two-window spec, and to any helper
that navigates on a spec's behalf.

## A retried test is reported, and is not automatically your debt

`retries` is 2 in CI, so a test that fails and then passes leaves its step green
and the run's exit code zero. That is the right verdict and an invisible one, so
the run says it out loud in three places — each of them silent when there is
nothing to say. The step's log carries one
`hilos-unstable: <N> (<spec:line>, ...)` line from
`framework/frontend/scripts/unstable-reporter.mjs`; the ledger entry for that step
becomes `<id> rc=0 unstable=<N>`; and the run's summary ends with an
`=== unstable: ... ===` section naming the steps and the tests behind them. A
clean run prints none of it, so the section showing up is itself the news.

**A named flicker is not automatically your change's fault.** Name the test, then
find out how long it has been flickering: a spec that flickered before your branch
existed is older debt, usually with a ticket of its own. Do not bounce your own
work over one, and do not repair a foreign spec inside your ticket — report it.
The rule is written down because the opposite happened: an untraced flake read as
a fresh regression cost HIL-468 two review bounces, a `needs-human` label and a
day of a healthy ticket standing still.

What the report never does is move the verdict. A step that only passed on a retry
stays `ok` and the run's exit code is unchanged: failing the run on a flicker would
turn every crowded box red, which is the exact trade the timeout scaling below
exists to avoid.

## Filling inputs — keyboard, not `fill`

Enter values the way a user does. **Do not set a value with `fill(value)`** — a
bare `fill` sets `.value` and dispatches a single synthetic `input`, which can
miss the reactivity a view relies on (watchers, debounced state, a form state
machine's computed submittability), so a submit can ship a stale or empty
payload. Clear with `fill('')`, then type with
`pressSequentially(value, { delay: 10 })`, which emits real per-key events
(keydown / keypress / input / keyup).

Drive a submit button through its actionable states, not a bare click: scroll it
into view, assert it visible and enabled, focus it, then click. After the click,
wait for the form to leave **or** the button to re-enable — never assume a click
that landed on a still-disabled control did anything.

## Wait for the action to settle before asserting its result

An action dispatched from the UI (a submit, a save) is **in flight** until its
reply lands — the control shows loading meanwhile. Do not assert the post-action
state while it is still loading: first wait for the action to **settle** — the
loading cleared, the surface or dialog closed on success, **or** the inline error
shown on rejection. Asserting through an in-flight action races the reply and is a
classic flaky pattern — the gated sign-in specs flaked exactly this way: the
helper clicked submit and asserted the profile before the session upgrade landed.
Only after the settle does the follow-up assertion — the resumed page, the closed
dialog, the error text — run against a resolved state. This is distinct from, and
comes before, waiting on the **subscription** reply the result itself depends on
(e.g. the profile snapshot that fills the card): settle the action first, then
assert the data.

## Timeouts scale with how starved the host is

Every Playwright cap — the test timeout, the `expect` timeout, and the action and
navigation timeouts — is the base value multiplied by a factor of 1.0–4.0 that
`framework/frontend/scripts/timeout-scale.mjs` derives from the host's
load-per-CPU and its `MemAvailable`. All three demo configs import the one module;
none of them carries its own numbers. The factor and the readings behind it are
printed as the run starts, so a slow step stays explainable from its log.

This exists because the full run puts **two demo lanes on the box at once**
(`../testing.md`): a starved host must make the suite slower, not red. An
unmeasurable host resolves to 1.0 — today's behavior — and a runaway one is capped
at 4.0 so a genuine hang still ends. `HILOS_E2E_TIMEOUT_SCALE` pins the factor
explicitly; it can only raise, never shorten.

The heuristic is a port of `resolve_timeout_scale()` in
`demo/cluster/docker/cluster_e2e.py`, and the port is **deliberately half**: that
suite also retries a scenario that failed purely on a convergence timeout and
never one that violated an invariant. Playwright gives no cheap way to tell the
two apart at retry time, so retrying on timeout only cannot be expressed — retries
stay at 2 in CI, and only the caps move.

## Source vs build

Unit tests run against **source**; e2e runs against the **built artifact** with a
**booted daemon** — you test what you ship. The full environment and test matrix
is in [build-and-docker.md](build-and-docker.md).

## Running the whole suite

One root aggregate answers "is the whole frontend green": `composer run
test:frontend:all`. It installs and builds the SDK, runs the SDK checks
(typecheck, unit, lint, format), then for every demo runs the app typecheck and
the full e2e cycle. The SDK build comes first because consumers resolve
`@hilos/*` to the built `dist` ([sdk-packaging.md](sdk-packaging.md)), so the aggregate passes
on a fresh clone. Run it at milestones and before handing a change over.

The aggregate is **not** the inner loop — do not re-run the whole matrix per
iteration. Day-to-day stays pointed: bring one demo's e2e stack up once
(`composer run test:e2e-up`), then run only the slice under work with
`composer run test:e2e -- --grep <pattern>` — one feature, or one of the
categories above — and tear down when done. Unit tests are pointed the same
way: `npm run test` in the SDK container, or one package via `npm run test -w
<package>`.

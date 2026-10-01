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
- the Angular slice uses vitest directly, mounting components through `TestBed`
  in jsdom. The CLI's native unit-test builder is **not** used: it is marked
  experimental, and it splits `@angular/common` across two module graphs, so the
  DOM adapter is registered in one copy while `ɵgetDOM()` reads the other and
  gets null.

Because Angular's tests run against source, a declarable reaches the runtime
uncompiled and Angular compiles it JIT — and JIT reads a directive's inputs off
its decorators, which an initializer-based input such as `input()` does not have.
So `framework/frontend/angular/vitest.config.ts` runs Angular's own
`angularJitApplicationTransform` over the package's declarables, the same
transform the Angular CLI applies to its JIT unit-test builds. Skip it and a
mount looks like it works — the template compiles, the view renders — while the
input is silently missing.

Because Vue's tests compile the single-file components themselves, the template
compiler they get is the development one, and its parser keeps comment nodes
where the shipped build's parser drops them. So
`framework/frontend/vue/vitest.config.ts` asks `@vitejs/plugin-vue` for
`comments: false`, and a component test compiles the same template the demos
ship. Kept, a comment standing between two branches of a `v-if` / `v-else-if`
chain is hoisted by the compiler into the branch that follows it, which turns
that branch into a fragment whose anchor is null on the first patch that
removes it. Drop the option and nothing fails to compile: a component test that
swaps a step branch a second time fails on an assertion about a field that is
no longer in the DOM, and the failure never names its cause (HIL-994). The
`<!--v-if-->` placeholder the runtime writes for a branch-less `v-if` is not a
template comment and stays.

### Where a unit test file lives

A test sits next to its module if and only if that module is a Vue SFC; every
other test lives in its package's `test/` mirror. The check is the extension of
the file under test, not the package it sits in.

    framework/frontend/vue/src/admin/logs/HilosLogsViewPage.vue
      -> framework/frontend/vue/src/admin/logs/HilosLogsViewPage.test.ts
    framework/frontend/core/src/auth/passkeyCeremony.ts
      -> framework/frontend/core/test/auth/passkeyCeremony.test.ts

The SFC is the one format where template and logic live in a single file, are
compiled by a plugin, and are edited in one commit — the test mounts that very
file, so a mirror would leave the component's own folder silent about whether it
is covered. A `.ts` or `.tsx` module has no such tie: it is imported by name and
its test lives its own life. That is why React components — `.tsx`, and mounted
much as Vue's are — still test from the mirror.

The shape of the mirror differs per package, and neither shape is guessable from
the other. In `core` the mirror repeats the module's path under `src/`. In
`react`, `angular`, `vue` and `prerender` the mirror is flat, however deep the
module sits: `react/src/admin/logs/HilosLogsViewPage.tsx` ->
`react/test/HilosLogsViewPage.test.tsx`. The reason is size and subject:
`core`'s mirror holds around sixty files whose folders carry the subject, while
a view package's test answers for one component, and a flat `test/` reads as a
table of contents of what is covered.

A test that covers a scenario rather than a single module goes in the folder of
its subject and is named after the scenario:
`framework/frontend/core/test/auth/oauthTrip.test.ts`,
`framework/frontend/core/test/connection/sessionRotation.test.ts`,
`framework/frontend/core/test/subscription/coldEntryWindow.test.ts`.

`framework/frontend/scripts` and `framework/frontend/codestyle` have no `src/`
at all: they are flat packages, and a test sits beside its module in the package
root (`codestyle/wireKeyCase.ts` -> `codestyle/wireKeyCase.test.ts`). They are
full vitest projects all the same — both are listed in
`framework/frontend/vitest.config.ts`.

The environment comes from the package config, not from the file: `vue` runs
happy-dom, `react` and `angular` run jsdom, and every other package runs with
no browser at all (`framework/frontend/core/vitest.config.ts` sets no
environment). A test that needs another environment declares it on its own
first line: a `core` test that needs a DOM says `// @vitest-environment
happy-dom` (`framework/frontend/core/test/auth/oauthTrip.test.ts` is one), and a
view-package test that renders on the server says `// @vitest-environment node`
(`framework/frontend/vue/src/serverRender.test.ts`,
`framework/frontend/react/test/serverRender.test.tsx`).

Browser APIs are stubbed in place rather than mocked as modules: `vi.spyOn` for
functions (`framework/frontend/core/test/auth/oauthTrip.test.ts:187`) and
`Object.defineProperty` for read-only host objects
(`framework/frontend/core/test/auth/passkeyCeremony.test.ts:94`). `vi.mock` is
used on the frontend only to replace a whole package, and it appears exactly
once — `framework/frontend/prerender/test/discovery-unrouted.test.ts:6`.

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

### Which demo carries a spec — the account stays, the operation moves

A spec lives on the side of the boundary its feature belongs to (the owner's
rule, 2026-09-27). The **account** side stays in chat, tasks and polls:
sign-in, profile, second factor, step-up, passkey, the account itself —
deletion, merge, export — sessions, connection, the application shell
(navigation, layout, a11y, prerender, error pages), the stand contracts
(stand-gateway, stand-model, stand-oauth), and chat's showcase. The
**operations** side lives in the new demos: backup, protected mode,
maintenance, logs, settings, users, admin, tables, notifications and
communications. Every demo keeps its own smoke and its own sign-in.

The demos pair by view framework: chat (Vue) → binance-btc-tracker (Vue),
tasks (React) → ecommerce-shop (React), polls (Angular) → online-testing
(Angular). An operations spec lives in the new demo of the same framework as
the demo it leaves.

| Area | Specs today | Lives in | Leaf |
|---|---|---|---|
| Settings, people and admin | binance-btc-tracker: settings, users, admin, admin-gating, account-lifecycle | binance-btc-tracker | HIL-1219 |
| Backup | binance-btc-tracker: backup, backup-reopen | binance-btc-tracker | HIL-1220 |
| Protected mode and maintenance | binance-btc-tracker: protected-mode | binance-btc-tracker | HIL-1221 |
| Logs | binance-btc-tracker: logs, logs-rotation | binance-btc-tracker | HIL-1222 |
| Tables | binance-btc-tracker: table-lag, table-refusal | binance-btc-tracker | HIL-1223 |
| Notifications and communications | binance-btc-tracker: notifications, communications | binance-btc-tracker | HIL-1224 |
| The operations half of tasks | tasks: users, settings, notifications, protected-mode, maintenance, backup-reopen | ecommerce-shop (not in the code yet — HIL-1225) | HIL-1225 |
| The operations half of polls | polls: users, settings, notifications, protected-mode, maintenance, logs | online-testing (not in the code yet — HIL-1226) | HIL-1226 |

`admin-gating` and `account-lifecycle` are the screens of an administrator
over people, and they go with settings, people and admin: framework-level
tests, in the owner's words on the HIL-1209 interview, not chat's.

Three pieces of that area stayed in chat by the same rule — a spec lives on the
side of the feature it is about. `admin-users.spec` is chat's own "Users"
screen (`/hilos/app/users`, its own table), which binance-btc-tracker does not
have. The test that switches on the confirmation before removing rights sits in
`step-up.spec`: its subject is the switch, and the switch lives in the Security
Center, on the account side. `admin-view-mode.spec` is the shell's gear over
chat's screen; where the view-mode e2e lie after the moves is HIL-1273.

The old demos switch nothing off — only the spec moves; the new demo switches
on what it tests, plus sign-in.

**A move is a rewrite, not a copy.** Chat's e2e hold on to chat's product:
`signUp` enters through `message-signin`
(`demo/chat/tests/e2e/helpers/session.ts`), moderation runs before a rename,
`message-input` sits inside the notifications specs, and the seeds of
`test:db-prepare` (`demo/chat/composer.json`: `test:user:seed` for 25 people)
shape the data. A new demo seeds what its moved specs read in its own
`test:db-prepare` — binance-btc-tracker carries the 25 people, the
`test:notification:seed` rows the notification center reads, and the orphan
setting row the settings spec deletes. What is shared comes from the
toolbox — `framework/frontend/e2e/` and `framework/frontend/scripts/`, "The
shared toolbox" below — and is not copied across.

**Until the new demo carries an area, its specs — old and new — are written
where the area lives today, and move together.** The leaf that moves an area
takes every spec of it, named in its card or not. An area in neither list
stays in its demo; a new operations area is born in the new demo of its
framework.

Why: on 2026-09-27 (run 0593) `chat-e2e` took ≈17 of the ≈20 minutes of a full
run — 243 tests on one Playwright worker, and ≈874 of its ≈998 seconds went to
the framework's own surfaces rather than to chat's product. A stand per demo
buys parallelism, and every operations feature gets proved on every view
framework.

## Backend state — full reset per test

Each test gets a **full reset of the database and daemon** — the established
approach, fast enough in practice. This is deliberately **not**
transaction-rollback (a poor fit for a long-running, multi-worker WS daemon) and
**not** data-namespacing. A test starts from a known, seeded state.

## Selectors — stable ids only

e2e interacts through **stable element ids** only: every interactive element
carries an id, and tests never use text- or position-based selectors. This keeps
e2e robust against copy and layout changes.

## The shared toolbox — look there first, leave there what you grow

`framework/frontend/e2e/` holds the e2e helpers that would read the same in any
demo, because what they drive is the framework's own surface — the toast stack,
the page outlet, the admin shell — or the screen of a stand resident, such as the
OAuth emulator's consent window. A demo's own `tests/e2e/helpers/` keeps what
belongs to that demo alone: its login, its fixtures, its command-channel address
and command names, and its product-mail reads. The socket round trip itself is
shared.

**Look in the toolbox before writing driving code, and prefer what is already
there** — a helper that exists has already been argued about once.

**Grow it from the work.** When a spec needs a wait, a sweep or a round trip that
another demo would need in the same words, put it in a shared home rather than in
a fourth local copy. The command-channel round trip reached that point in chat:
`helpers/logs.ts` became the fourth copy beside `adminGrant.ts`,
`notifications.ts` and `protectedMode.ts` because its leaf did not own the other
three. The toolbox has since absorbed the browser-driving copies, and the
scripts package has absorbed that node-side round trip.

Two rules keep it working:

- **A file here imports from `@playwright/test` with `import type` and nothing
  else.** The demo suite carries its own installed Playwright, and pulling a
  second copy out of this folder is refused by the runner itself — *Requiring
  @playwright/test second time*. A type import is erased before that can happen;
  everything a helper needs at runtime arrives through the `Page` it is handed.
  ESLint holds the rule: `framework/frontend/eslint.config.mjs` refuses a value
  import of `@playwright/test` under `e2e/`, so the `fe-checks` lint goes red
  before any demo runner does.
- **Demos reach it by relative path**, the way their Playwright configs already
  reach `framework/frontend/scripts/timeout-scale.mjs`. The runner mounts the
  whole repository, so no package boundary stands in between, and no install step
  is added to a suite.

Node-side shared e2e mechanics do not move into this folder merely because an
e2e spec calls them. A helper that drives the browser belongs here under the
import-type rule above; a round trip that needs only `node:net` belongs in
`framework/frontend/scripts/` beside `timeout-scale.mjs`. Nothing about that
mechanic is Playwright's, and the scripts folder is a vitest project, so
`commandChannel.mjs` carries the shared rule together with a running unit test.

The helpers of the stand's residents live in the same two homes, once for every
demo that reaches the stand: `standGateway.mjs`, `standOAuth.mjs`, `standSms.mjs`,
`standTelegram.mjs`, `standModel.mjs` and `standMailbox.mjs` in
`framework/frontend/scripts/`, and the person at the OAuth consent window in
`framework/frontend/e2e/standOAuthUser.ts`. A resident's helper is born here with
its first leaf; a demo does not copy it, and keeps only a wrapper that turns a
resident's raw answer into its own terms. The addresses — `STAND_GATEWAY_URL` and
`MAILPIT_URL` — come from the environment of the demo's runner, and the resident
itself is described in [stand-services.md](../stand-services.md).

### `dismissToasts(page)` — when the notice is in the way

A toast stands over the bottom-right corner for twenty seconds and takes clicks
([toasts.md](toasts.md)), so a notice raised by the step just performed can cover
the control the next step aims at. Playwright then retries that click until the
card expires on its own, and the spec pays twenty seconds for coverage the toast
specs already own.

Call `dismissToasts(page)` after a step whose notice is not the subject of the
spec, before the step that clicks what it may be covering. A spec that **is**
about a notice asserts on it instead and never calls this — sweeping is how a
spec says "this step is not about the notices", not a way to hide them.

### `armSocketDrop(page)` / `dropSocket(page)` — a socket that dies while the page stands

Some specs need the connection to drop under a page that stays put: the reconnect
indicator, the re-subscribe that follows, a page re-sent into a table that stood
refused. Playwright's offline emulation does not give that — Chromium blocks new
requests but leaves an established WebSocket running, so the client never
notices. The seam left is the one the page itself goes through:
`armSocketDrop(page)` wraps the WebSocket constructor, so call it **before the
page loads** — a socket opened before the wrap is out of reach — and
`dropSocket(page)` closes every socket the page opened. Nothing in the product is
touched; everything after the drop is the real client's own reconnect.

Prove the drop by the **next socket**, counted with `page.on('websocket')`, not
by a glimpse of the disconnected label, which a fast reconnect can pass through
unseen.

### `watchPasskeyCreation(page)` / `readPasskeyCreations(page)` / `platformsLeftOut(creation)` — judge what the page asks an authenticator for

HIL-658 left Windows Hello out of the OS chooser: the request offered no
algorithm that its TPM could sign with, while the virtual authenticator kept
the suite green. CDP cannot vary that device's algorithms, so the toolbox
judges the request itself against the promised platform table.

Call `watchPasskeyCreation(page)` **before loading the page**. It records each
`navigator.credentials.create` request and passes it unchanged to the native
API, preserving its promise and the virtual authenticator's ceremony.
`readPasskeyCreations(page)` returns the records without consuming them;
`platformsLeftOut(creation)` returns a platform name and reason for each missing
algorithm match. An empty list means every promised platform can answer with
one of its signing algorithms. Both profile enrollment and passkey sign-up
assert that verdict in chat's passkey spec.

The table is our knowledge: a change in a platform's own behavior is caught
only by a manual run. The real-device steps and the hand-over rule are in
[manual-checks.md](../manual-checks.md).

### The settings edit form — open, draft, set and clear

The toolbox owns the moves of the framework settings dialog through
`openSettingEdit`, `draftCustomSetting`, `setCustomSetting` and
`clearCustomSetting`. They address the framework's stable controls, type string
values through keyboard events, drive Save through its actionable states and
settle on the dialog closing. `clearCustomSetting` also sweeps notices that may
cover its row and returns immediately when the setting already uses its default.

Use the whole-operation functions for setup and teardown, `openSettingEdit` when
the dialog itself is the subject, and `draftCustomSetting` when a spec must
assert on an unsaved value or a refusal. Keep table moves such as isolating a row
and all assertions in the demo: those describe what the spec is proving, not how
the shared framework form is driven.

### The verifier circle — name, confirm removal, clear

`addToMaintenanceCircle`, `confirmMaintenanceCircleRemoval` and `clearMaintenanceCircle`
drive the Maintenance section's dialogs and settle on their closing. They expect the
operator's page on `/hilos/maintenance`. `maintenanceCircleRow` and
`maintenanceCircleOnline` locate the row and its presence mark; `CIRCLE_ONLINE` and
`CIRCLE_OFFLINE` are that mark's words. Only an address somebody has confirmed can be
named: a phone signed into by SMS code, or an email proven by its registration code or
confirmed through the profile (HIL-825). The database list outlives a scenario, so
start every circle scenario with `clearMaintenanceCircle`. Assertions stay in the demo.

### The bell and the channel switch — `openBell`, `unreadBadge`, `enableEmailChannel`

`openBell` opens the bell a demo mounts in its header slot (`HilosNotificationBell`)
and settles on its dropdown; `unreadBadge` is the unread count, matched at the front
of its text because its label carries a visually-hidden suffix. `enableEmailChannel`
turns the email channel on from the communications hub and expects the operator's
page already on `/hilos/communications` — navigation is the demo's. It is
idempotent: the channel's switch is a setting of the whole stand, so a channel
another test switched on is left alone. The emit that makes a notification exist
and the sign-in that makes its recipient are the demo's; so are the assertions.

### Geometry — take a bookmark and ask whether it moved

Measure boxes through `watchTop(element)`, `watchHeight(element)` or
`watchFirstRowTop(page)` from the shared toolbox. The first two take a Locator;
the third reads the first table row's top relative to the table root in one
pass, so scrolling the page cannot masquerade as a move inside the table.

Each returns a `Watched` bookmark with an `unchanged()` method and a description
of what it watches. Its constructor and readings are private: the spec keeps
one bookmark and asks it to measure again, even when checking several later
states. Do not compare bookmarks or take a second one for the same check.

```ts
const submitTop = await watchTop(page.getByTestId('auth-submit'))
// Trigger the action and wait for the state the spec is proving.
await submitTop.unchanged()
```

`unchanged()` compares against the original reading with **one CSS pixel of
slack**, inclusive. A repaint can round a box differently; a larger move fails
with both readings and the bookmark's description. A missing box fails both
when taking the bookmark and when checking it. Measurements are single reads,
with no polling for the layout to return: wait for the expected state in the
spec before measuring or checking, as for any other assertion.

One more question has a helper, and it is not a bookmark: where one element
lies over another. `overlapSpot(over, under)` returns the middle of the area the
two share, as a click `position` on `under`, and refuses when they share none —
for a spec proving that a click passes through something drawn over its target.
Aim the click there: two boxes that share a strip along an edge leave the
target's middle, where a plain click goes, uncovered (HIL-1097).

**Never call `boundingBox()` or `getBoundingClientRect()` in a demo spec or its
helpers.** `E2E-BOX-MEASURE` (`framework/frontend/codestyle/boxMeasure.ts`)
reports direct calls throughout `demo/*/tests/e2e`, with no file exceptions.
The shared toolbox owns those calls and lies outside that scan. Exact counters,
remaining scroll distance and document height read through
`scrollHeight` / `clientHeight` / `scrollTop` remain valid: this rule governs
boxes, not every numeric assertion.

## Opening a page — `gotoPage`, never `goto`

A spec opens a page through the demo's **`gotoPage(page, path)`** wrapper, which
waits for the subscription's answer — the answer, not a good answer, since a page
closed to a guest answers with a refusal and the specs that walk into one are
asserting exactly that. Pass `PAGE_REFUSED` (or `PAGE_READY`) as the third
argument only when the spec is about which answer came. **Never call Playwright's
`goto` directly**; `E2E-PAGE-GOTO`
(`framework/frontend/codestyle/e2eGoto.ts`) reports every direct call outside the
`helpers/page.ts` that owns the wrappers.

One address is not a page of the product, and `goto` is right for it: the screen of
a stand resident, which a spec opens in the provider's place — the OAuth consent
screen (HIL-923, [stand-services.md](../stand-services.md)). The gateway serves it,
no subscription stands behind it, and `gotoPage` would wait for an answer that never
comes. The checker lets such a call through when its address is **written from
`STAND_GATEWAY_URL`** imported from `framework/frontend/scripts/standGateway.mjs` —
the base itself, a template opening with it, or a concatenation starting with it —
and only then. It reads the address and not the file: the base hidden behind a
function call, placed further into the address, or declared by the spec itself is
reported like any other `goto`, so a spec that opens a stand screen and a product
page is still held to the rule for the second one.

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

### Except where the application navigates itself — then do not navigate at all

There is one page the spec must NOT open, and it is the page the spec is about to
want: one the application has just told itself to reload. Today there is exactly one
such moment — the lift of protected mode, where the client calls `location.reload()`
rather than go on living with rows from before a restore (`createHilosConnection.ts`,
`onProtectedModeLift`). Every browser holding a socket does it at once, on the frame
that carries the news.

A `gotoPage` fired into that moment does not fail cleanly. Both navigations carry the
SAME address — the page reloads to where it already stood — so what Playwright reports
is `Navigation to "<url>" is interrupted by another navigation to "<url>"`, an address
against itself, which reads as a browser oddity rather than as the race it is. That
signature was 16 first-attempt failures of the chat suite across a single week.

What the spec does instead is mark the document before the action and wait for the
mark to be gone after it (`markDocument` / `expectSelfReload` in the demo's
`helpers/page.ts`). A mark is indifferent to order: it is equally true if the reload
has already come and gone, where an event subscription armed a moment too late waits
out the whole test ceiling. A replaced document says only that the reload finished, so
a spec that then asserts what is on screen adds `expectPageReady` — and one that needs
a DIFFERENT address navigates there afterwards, once the reload has landed and there
is nothing left to collide with.

Waiting for the frozen surface to disappear is NOT a substitute, and was tried: the
stub goes on the frame that arrives, and the reload follows it.

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

## Timeouts come from the host, not from the lanes

Every Playwright cap — the test timeout, the `expect` timeout, and the action and
navigation timeouts — is the base value multiplied by a factor of 1.0–4.0 that
`framework/frontend/scripts/timeout-scale.mjs` resolves. Every demo config
imports the one module; none of them carries its own numbers. The factor and the
readings behind it are printed as the run starts, so a slow step stays
explainable from its log.

The factor comes from **what the box says about itself** at the moment the config
is read. A load per CPU above 0.75 stretches the caps by the excess, and available
memory sets a floor under that (2.0 under 2 GiB, 3.0 under 1 GiB), because a box
about to swap costs far more than its load average admits. An unmeasurable host
resolves to 1.0, and a runaway one is capped at 4.0 so a genuine hang ends. This
is the one path there is: the heuristic runs the same inside the full run and
under Playwright started by hand.

`HILOS_E2E_TIMEOUT_SCALE` is a **pin made by hand** — someone debugging a step, a
CI that knows its own box. Nothing in the repository sets it; the demo compose
files only forward it into the e2e runner. A pinned value is a **floor**, not the
finished factor: memory may still raise it, while the load-per-CPU term steps
aside for as long as it is set (HIL-853). A value below 1 is raised to 1: the knob
can lengthen a timeout and never shorten one.

**The lane count is no part of this** (HIL-1227). `scripts/run-test-suite.php`
used to export the variable from the number of lanes it resolved, and at seven
lanes that put every cap at the ceiling of four. It was dropped on what the box of
the line measured: `chat-e2e` alone at a factor of 1.0 passed 244 tests without
one retry (run 0615), and the cluster suite at 1.0 passed 19 of 19 (run 0623) — a
factor above 1 rescued no test, while a test that hangs costs 30 seconds an
attempt at 1.0 against 120 at seven lanes.

This exists because a full run puts several suites on the box at once
(`../testing.md`): a starved host must make the suite slower, not red.

The heuristic is a port of `resolve_timeout_scale()` in the shared cluster
harness (`framework/docker/cluster/scenarios.py`), and the port is
**deliberately half**: that suite also retries a scenario that failed purely
on a convergence timeout and never one that violated an invariant. Playwright
gives no cheap way to tell the two apart at retry time, so retrying on timeout
only cannot be expressed — retries stay at 2 in CI, and only the caps move. The
cluster scale reads `CLUSTER_E2E_TIMEOUT_SCALE` under the same rule as this one:
nothing but a hand sets it, memory may raise it above the pin, and the loadavg
term steps aside whenever it is set.

### A test longer than the cap declares its own

A test whose own work runs past the base cap — three letters in a row, a wait on
a clock — says so itself: `test.slow()` when a flat triple fits, or a named factor
over `test.info().timeout` when it does not (`SIGN_IN_LINK_TIMEOUT_FACTOR` in
`demo/polls/tests/e2e/tests/auth.spec.ts` is the sample), with the measured
duration and the reason in a comment.

Do not lean on the host factor as a budget: it is 1.0 on an idle box, and a test
that passes only because the box was loaded goes red on the day the box is quiet
(P-379). Never raise a floor under the factor — in the runner or in a config — to
cure one test.

## Source vs build

Unit tests run against **source**; e2e runs against the **built artifact** with a
**booted daemon** — you test what you ship. The full environment and test matrix
is in [build-and-docker.md](build-and-docker.md).

## Running the whole suite

One root aggregate answers "is the whole frontend green": `composer run
test:frontend:all`. It installs and builds the SDK, runs the SDK checks
(typecheck, unit, lint, format), then for every demo runs the frontend
typecheck, lint and format-check, the e2e package's lint and format-check,
and then the full e2e cycle. The SDK build comes first because consumers resolve
`@hilos/*` to the built `dist` ([sdk-packaging.md](sdk-packaging.md)), so the aggregate passes
on a fresh clone. Run it at milestones and before handing a change over.

The aggregate is **not** the inner loop — do not re-run the whole matrix per
iteration. Day-to-day stays pointed: run one demo's full cycle pointed at the
slice under work — `composer run test:e2e-full -- --grep <pattern>`, or a spec
file — one feature, or one of the categories above. It is the same clean cycle
as the unpointed run (teardown, fresh database, fresh daemon), so a repeat is a
fresh verdict; the mechanics are in [testing.md](../testing.md). Unit tests are
pointed the same way: `npm run test` in the SDK container, or one package via
`npm run test -w <package>`.

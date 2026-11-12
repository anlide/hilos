# Testing Strategy

Two kinds of test: **unit** (vitest, across the whole monorepo) and **end-to-end**
(Playwright, multi-context). The hard real-time features — sync, conflict, groups
— are exercised by multi-client e2e, which is why the e2e categories below are
first-class, not an afterthought.

For literals in unit/component tests, e2e and shared test helpers, read
[Magic Values — Tests](../code-style/magic-values.md#tests).

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

### Shared behavior across view frameworks

Shared SDK behavior has one text of **steps and expectations**, exercised by
the real Vue, React and Angular components in their own test environments.
Define a subject's scenarios once and register them from each SDK's local test
entry. A small, subject-specific mount/update/flush/unmount adapter connects
props, context and signals and performs that framework's lifecycle and cleanup.
It contains no assertions, expected result, or implementation of the behavior
under test. Do not reshape product components or core to fit the adapter.

This is an ordinary TypeScript registration pattern to adopt through the pilots
below, not a shipped harness, test package or public `@hilos/*` API. It needs no
new dependency, Browser Mode, Storybook or universal adapter. This compact
sketch illustrates the boundary; its registrar and adapter types are proposed
test-local shapes, not existing exports:

```ts
function registerActionErrorCases(runner: LocalVitest, mount: MountActionError) {
  runner.it('announces the current refusal', async () => {
    const view = await mount({ error: null })
    try {
      await view.update({ error: 'Value must be an integer of 0 or more' })
      await view.flush()
      const alert = view.root.querySelector('[role="alert"]')
      runner.expect(alert?.getAttribute('aria-live')).toBe('assertive')
      runner.expect(alert?.textContent).toContain('Value must be an integer of 0 or more')
    } finally {
      await view.unmount()
    }
  })
}

// Each call belongs to its own existing local discovery entry.
registerActionErrorCases({ it, expect }, mountVueActionError)     // Vue
registerActionErrorCases({ it, expect }, mountReactActionError)   // React
registerActionErrorCases({ it, expect }, mountAngularActionError) // Angular
```

The contract supplies the expected result; another SDK is not the oracle. An
identical bug in all three must fail the explicit expectation. Before migrating
a family, write down what its existing checks mean. Matching file names or the
intersection of three check lists is not a semantic inventory: preserve useful
behavioral and accessibility proof found in only one SDK too. A conflicting or
undefined product expectation needs an interview in the future migration leaf;
do not choose whichever SDK currently happens to do it. Different `loadingDelay`
checks, for example, do not make React's result authoritative.

Keep framework-specific API, lifecycle, SSR and hooks tests local when they
need that framework, with a short explanation of why. Avoid private copies of
shared behavior (the owner's decision, P-668). CSS snapshots and appearance
checks do not become a shared behavioral contract: apply
[the assertion rules below](#unit-and-component-assertions--behavior-accessibility-and-semantics).
Shared fixtures read their existing owners through `@hilos/core/base` and
`@hilos/core/admin/<section>`; the SDK packages have no bare entry. Supporting
vocabulary comes from its owner; checked values remain explicit examples under
[Magic Values — Tests](../code-style/magic-values.md#tests). Do not invent a
local page or error-code dictionary for the shared fixture.

#### Rollout

HIL-1787 records this decision and the following proposal for later work. It
does not implement a harness, run a pilot, migrate tests or inventory every
assertion. Creating follow-up leaves or an epic, or changing other tickets and
their dependencies, needs separate explicit owner approval. These acceptance
checks belong to those future leaves, not to this documentation change:

1. **Component pilot:** one small family, one scenario module and three real
   mount adapters. `HilosActionError` is a candidate for the subject interview:
   `framework/frontend/vue/src/HilosActionError.test.ts`,
   `framework/frontend/react/test/HilosActionError.test.tsx` and
   `framework/frontend/angular/test/HilosActionError.test.ts`. Preserve its
   behavior and ARIA semantics; judge existing cosmetic checks by the assertion
   rules instead of copying them. Completion means one text of steps and
   expectations, three real component runs, no root imports in touched files,
   isolated state and cleanup, no duplicate old test body, and failures that
   identify both SDK and case. The pilot determines the concrete files and
   wiring; complex settings-page suites are not the starting family.
2. **Browser pilot:** share the text of one comparable screen scenario already
   run on Vue, React and Angular. The candidate is `theme-guest.spec.ts` in
   `demo/{roblox-game-server,solar-system-model,preferans}/tests/e2e/tests/`. Keep all useful
   steps and expectations, local registration entries, fixtures and cleanup, and the same
   number and distribution of runs. Verify local spec attribution in reports.
   The theme feature may choose dark to prove the mode selection under the
   existing exception; other scenarios gain no palette matrix. The
   demo-specific `theme-smoke` specs in Binance, Ecommerce and Online do not
   become portable framework scenarios because their names match.
3. **After both pilots:** migrate bounded behavioral areas — basic controls and
   forms, then tables and editing, then admin/account sections. Each leaf carries
   the shared scenario and all three registrations together; do not split by
   framework and leave three independent bodies alive. The pilots determine
   grouping, scope and leaf count. Basename overlap is not a completed inventory,
   and no timing measurements or bulk-migration readiness are claimed here.
4. **Compatibility roots (historical proposal):** HIL-1787 proposed moving each
   area's actual importers to `/base` and `/admin/<section>` as that area moved,
   including justified framework-specific tests, before removing the roots in
   HIL-1788. The four roots have since been removed; current imports use their
   existing public owners, including `/status` and `/admin-views` where needed.
   The import-only migration does not replace the shared-contract rollout above
   or authorize duplicate behavioral scenarios.

### Unit and component assertions — behavior, accessibility and semantics

When creating, changing or reviewing a unit/component test, assert behavior,
accessibility and the component's semantic contract. A component's own test
holds its meaningful structure: roles, accessible names, relationships and
data. It does not freeze its Bootstrap styling (`d-flex`, `gap-3`, `py-3`,
`fw-semibold`), colors or layout. Removing a cosmetic e2e assertion is not a
reason to move that assertion into a unit test.

For example, a notification preference's Email data, accessible switch name
and decorative icon's `aria-hidden` are meaningful checks in its component
test; the row's spacing classes and the icon's Bootstrap class are not. Keep
that accessibility proof in the owning component test instead of repeating it
in every entity scenario.

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
`framework/frontend/vitest.config.mts`.

The environment comes from the package config, not from the file: `vue` runs
happy-dom, `react` and `angular` run jsdom, and every other package runs with
no browser at all (`framework/frontend/core/vitest.config.ts` sets no
environment). A test that needs another environment declares it on its own
first line: a `core` test that needs a DOM says `// @vitest-environment
happy-dom` (`framework/frontend/core/test/auth/oauthTrip.test.ts` is one), and a
view-package test that renders on the server says `// @vitest-environment node`
(`framework/frontend/vue/src/serverRender.test.ts`,
`framework/frontend/react/test/serverRender.test.tsx`).

A test of a control inheriting `:disabled` from a disabled `fieldset` declares
`// @vitest-environment jsdom` on its first line. The core focus-trap test and
the Vue modal test use this override:
`framework/frontend/core/test/dom/focusTrap.test.ts` and
`framework/frontend/vue/src/HilosModal.test.ts`. The verified happy-dom 16.8.1
baseline did not model inherited `:disabled`; keep the override on these test
files rather than changing the package environment or assuming behavior from a
later happy-dom version. jsdom checks the selector state, but does not establish
native browser focus fixup or implicit Enter submission.

For shared behavior, the existing local `*.test.ts` / `*.test.tsx` files stay
discovery entries in the places above and call the common scenario module.
That test-only module is not a second SDK API and does not enter the runtime
bundle; the pilot determines its files and wiring. Keep the package DOM
environments, Vue template compiler option, Angular JIT transform and setup,
and per-test environment overrides. Do not simplify them to make a common
registration shape pass.

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

### Choose the scenario before adding coverage

Before adding an e2e test or spec, name **action → visible result → unique
failure** that unit tests and existing scenarios do not already catch. Search
for the entity's existing spec and the human journey's test/step first. Add the
new action or refusal to that journey when it belongs there, using `test.step`
to keep each action and its visible outcome readable.

A new entity or an independent behavioral boundary can justify a new scenario;
a ticket name or an individual CRUD command cannot. Putting one test per
action under a shared `describe` does not make an entity journey. The language
and country lifecycle tests in
`demo/file-gallery-manager/tests/e2e/tests/i18n-language.spec.ts` and
`i18n-country.spec.ts` show one test-scoped fixture and administrator across
named steps. Their independent live, identity and multi-tab scenarios remain
separate tests. No test depends on a previous test completing successfully.

Only after a new file is justified, apply "Which demo carries a spec" below
to choose its home and load. This does not redistribute existing copies among
the SDKs. A cosmetic change brings no new e2e test; use the assertion rules
below when reviewing existing coverage too.

### What assertions prove — seven rules

1. **Function, not decoration.** Do not assert styling classes (`h4`, `bg-*`,
   `text-*`, `bi-*`, `.card`), computed colors or styling properties through
   `toHaveCSS`. Browser geometry that proves an element fits or does not
   overflow is functional coverage; use the shared geometry helpers. Unit and
   component tests follow "Unit and component assertions" above too.
2. **Keep behavior through a redraw.** Do not freeze either the old or the new
   layout: the presence or absence of a shared head, breadcrumbs, a tab row,
   the former child-page card grid or a card wrapper around a table is not an
   e2e outcome. A screen awaiting a redraw and a redrawn screen owe the same
   behavior. The state-specific absence of a head on a 404, while its answer
   is held, or after leaving the page remains a behavioral check.
3. **Use hooks to find, not to prove.** `data-id` is Playwright's stable lookup
   hook, not an assertion subject. Do not write
   `toHaveAttribute('data-id', ...)` to prove which component rendered a node.
   Find the page heading with `getByRole('heading', { level: 1 })`; the correct
   title and exactly one h1 still prove data and accessibility. Action selectors
   keep their stable ids; see "Selectors" below.
4. **Do not freeze DOM arrangement.** Do not assert element order or adjacency
   (`nextElementSibling`, Time zone before Theme), the order of sections or
   links, or field/wrapper counts as a picture of the page (exactly three `dd`
   or one `hilos-table-cards`). Test titles name behavior, without prescribing
   order or appearance. The component's own unit tests hold meaningful
   semantic structure, subject to the unit rule above; cosmetic arrangement
   does not become a unit contract.
5. **Read data, not permanent copy.** Do not reread fixed help text and field
   captions in place of checking values. An accessible name or the reason a
   particular action was refused is a semantic outcome, not decorative copy.
6. **A caption-only visit is not a scenario.** Opening a page and rereading its
   permanent container or heading earns no separate test when navigation,
   cold entry and data are already proved elsewhere. In a coverage cleanup,
   remove such a test rather than renaming it as a scenario. Compare the
   promise in its comment with its actual assertions. For a mixed test,
   preserve useful data, navigation and live checks and remove only the
   cosmetic assertions; do not discard the whole test.
7. **Preserve behavioral and accessibility proofs.** Keep creation and cleanup,
   data, access and refusal reasons, read-only guarantees, clearing a field,
   live updates, reconnect, cold entry, F5, links and Back/Forward navigation.
   Keep accessible roles, one h1, `aria-current`, keyboard and focus behavior,
   and stand transport proofs. Judge the assertion's purpose, not its matcher
   name or the mere fact that it reads the DOM:

   - Zero write controls on a read-only surface proves its promise; counting
     `div`, `dd` or cards to freeze its drawing does not.
   - `tablist` count zero while ordinary navigation links remain live proves
     accessibility; an absent tab row merely proving a new layout does not.
     `aria-current` and following the links prove real navigation.
   - `fitsInScrollport` proves fit; `overflow-wrap: break-word` prescribes a
     styling choice. Geometry uses the toolbox and still runs at the configured
     desktop width: it permits neither 375 px nor a width/dark-theme matrix.
     The one exception is a component's geometry on its own stand, checked at
     1440, 768 and 375 and never in the dark theme there either; see "Light
     theme, desktop width" below.
   - The theme feature's own specs keep proof of the chosen mode, including
     the built first frame; the "Light theme, desktop width" exception below
     stays narrow. Other features do not gain palette checks.
   - A decorative icon's `aria-hidden` belongs in its component's own unit
     test, without duplicating it in every entity journey.

### The three categories

1. **Main features** — a single client exercising a feature end to end.
2. **Same-user broadcast** — two tabs of the **same user**: an edit in one tab
   must appear in the other (the User scope fan-out, [data-model.md](data-model.md)).
3. **Three different users** — three users exercising cross-user behavior:
   **conflict resolution** ([conflict-resolution.md](conflict-resolution.md)) and **group subscriptions**
   ([wire-protocol.md](wire-protocol.md)). The hardest features require this category to test, so it
   is built in from the start.

### Light theme, desktop width — no dark theme and no narrow screen

Every e2e test runs in the light theme at the desktop viewport its config gives
it (the owner's rule, 2026-10-21). A spec does not:

- repeat a scenario in the dark theme, or loop a test over the two palettes;
- check how a screen looks in the dark theme — its colors, its contrast;
- narrow the viewport — no `setViewportSize` and no context `viewport` below
  the desktop width of 1024 px (1024 itself is allowed), no loop over
  widths — and so it does not check what only a narrow screen draws: cards in
  place of rows, a row that scrolls sideways, a form that holds still on a
  small screen.

The narrow screen and the dark palette stay behavior of the product, described
in its docs and mockups; e2e is not where they are proved. A doc or a research
that asks for a check at 375 px or in both palettes does not override this
section.

The theme feature is the one place the dark theme appears: its own specs
(`theme-*.spec.ts`, `appearance.spec.ts`, the cluster `theme.spec.ts`) choose
it through the real controls or the system preference and check that the pick
takes effect — the mode, the header and profile positions, the first frame — is
stored and reaches other tabs and nodes. They do not run one scenario in both
palettes to see it drawn twice, and they run at the desktop width too. The
names above describe them; the checker below knows them by exact path, so a new
file named like one of them is refused the dark theme like any other spec.

A component's geometry on its own stand is the one place a spec narrows the
screen. The stand is a page with no server behind it that mounts the real SDK
component, and its spec proves only that nothing is cut off and nothing moves.
It checks this at 1440, 768 and 375 — the three widths every screen of the
design must survive (the design foundations, rule 2) — and may loop over those
three; it runs in the light theme only. The checker knows such specs by exact
path, as it knows the theme specs; the first is
`demo/pmd/tests/e2e/tests/modal-geometry.spec.ts`, the stand of the modal. A new
stand spec joins that list in the checker; a file named like one elsewhere gains
nothing. Product e2e and every other spec keep the whole ban, a geometry check
inside a product spec included: it runs at the desktop width with the rest of
its spec. Why the stand is let through: it has no server and no scenario, so a
width costs one page that opens in a second, not another pass over a server
path (the owner's decision, 2026-11-10, P-846).

Checked automatically: `E2E-LIGHT-DESKTOP`
(`framework/frontend/codestyle/e2eDisplay.ts`) reports, throughout
`demo/*/tests/e2e`, a viewport width below 1024, a viewport width taken from a
loop, and the dark theme chosen outside the theme specs; in a geometry stand
spec it lets 768, 375 and a loop whose every width is 1440, 768 or 375 through,
and reports any other narrow width, a loop with another or an unknown width,
and the dark theme as anywhere. It judges only what the file proves, and its
sites and blind spots are listed in
[automated-checks.md](../code-style/automated-checks.md). This section is wider
than the check: a narrow width or a width loop it cannot work out — a phone's
device descriptor, a helper's result, arithmetic on a loop's variable — passes
it silently and is still forbidden here.

Why: a palette or a width multiplies a scenario without reaching a different
server path. One leaf's 1440/375 × light/dark matrix grew chat-e2e from 180 to
406 tests and from ~14 to ~26 minutes (HIL-1749), and chat-e2e is the floor of
the full run.

### Explicit browser time zones

Every new or edited e2e scenario runs with an **explicit, fixed IANA browser
time zone**, different from UTC and the stand's effective project time zone.
Compare canonical identities: another alias of UTC or of the project zone is
still the same zone. Keep an existing explicit zone that already meets the
rule and the scenario's behavioral preconditions; changing a spec does not
require drawing its suitable zones again.

Draw a new or replacement browser zone **once, while writing the test**, with
the demo's `test:e2e-time-zone` Composer command. Active ordinary demos provide
it; each planned carrier must supply the same interface
under its activation marker in the
[ordinary carrier table](../testing.md#ordinary-demo-carriers).
`binance-btc-tracker` and `online-testing` also provide
`test:cluster:e2e-time-zone` for their cluster browser entries. Use the command
for the same stand the scenario runs on. From the repository root, for example:

```bash
composer -d demo/chat run test:e2e-time-zone -- --count 2 \
  --exclude Europe/Kyiv --exclude Asia/Tokyo
composer -d demo/binance-btc-tracker run test:cluster:e2e-time-zone -- --count 1
```

`--count N` asks for N different zones and defaults to 1. Repeat `--exclude IANA`
for roles already saved in the scenario, including a person's chosen zone, so
those roles can stay as they are. The generator draws without replacement using
`node:crypto`'s `randomInt`; exclusions and distinctness include equivalent IANA
aliases. Copy the returned names into literals in `use.timezoneId`, `test.use`
or explicit context options. This is real randomness at authoring time; the
committed literal stays unchanged on every run and retry. Do not import the
generator into a config or call it from `globalSetup` or a test run. Do not add
a time-zone matrix or randomize zones at runtime. A zone literal is test data,
not a reason to introduce a product constant.

The command reads metadata through the project's Hilos facade in the selected
stand's **application service**, with a PHP entrypoint and `--no-deps`.
`EntrypointPrelude::initEnvironment` reads that service's process environment,
`tests/.env` and environment catalog at `/app`; the metadata contains only
`projectTimeZone` and PHP's IANA names. The Node step runs in that stand's e2e
runner and opens blank Chromium to intersect its actual supported names with
PHP's catalog, excluding UTC, the project zone and `--exclude` roles. Do not
infer the project zone from `.env.example`, the host or the e2e runner's own
environment. The commands use the already installed test environment; they do
not install dependencies, visit the product, start its daemon or require a DB.

On success stdout contains exactly N IANA names, one per line; diagnostics and
instructions use stderr. Invalid arguments or metadata, an unsupported zone,
too few distinct candidates, or PHP/Chromium failure produce a nonzero exit
without a partial list or fallback. The PHP metadata is validated even for
`--help`, so a failed first step cannot look like a successful pipeline;
`--help` itself needs neither Chromium nor a running daemon.

An explicit config `use.timezoneId` covers contexts that inherit it. In the
pinned Playwright Test 1.64.1, combined `use` options fill **absent keys**, also
for `browser.newContext()` and `browser.newPage()`. An explicit override wins;
`timezoneId: undefined` is a present key and does not inherit the default.
Do not duplicate the config literal at every context creation. A scenario with
an explicit browser role can use the toolbox's `timeZoneContextOptions` while
preserving its other context options and session isolation. Chromium launched
outside Playwright Test has no such inheritance: set the explicit zone at that
entry itself.

In a scenario about browser, project and a person's own time zones, keep those
roles distinct. A person's pick through the profile is a separate role from
the browser's `timezoneId`; preserve a suitable existing pick. Different zone
names alone do not prove different displayed times or calendar days. Keep a
fixture that makes the result the scenario asserts distinguishable for the
chosen zones. If the draw fails that precondition, draw again manually while
authoring, then save the final literal. Calculate time expectations with the
toolbox's independent `Intl` oracle from the original UTC timestamps and the
expected zone, never from the DOM result being checked. Preserve useful actions,
records, isolated sessions, Automatic and reload checks; fix UTC assumptions in
expectations without weakening the proof or changing the product's formatter,
the stand's project zone or the Node unit process's zone.

For time-zone journeys, use `expectTimeZoneEnvironment` from the shared toolbox
with browser and project zones named by the spec in advance. It checks the
original welcome's `projectTimeZone`, rejects decoder failures, reads real
browser `Intl`, and refuses a browser zone equal to UTC or the project zone,
including aliases. Do not derive the expected project zone from that same
welcome, or rewrite the welcome to pass the check. This existing runtime check
does not call for a new static time-zone guard. A cold public Status scenario
whose subject is reading without a daemon or connection does not open a socket
just to run this helper.

### Which demo carries a spec — the server check travels, the product stays home

A spec whose screen only leads the scenario while what it checks is the
**server** — the session, cookies, letters, database rows — may live in a demo
of any view framework where its feature is switched on (the owner's rule,
2026-10-04). This is a judgment with examples, not a mechanical test: sign-in by
a code or a link, step-up, second factor and session rotation check the server;
the sign-in card and its still button check the screen. A spec whose subject is
the **screen** stays with its view framework and its area. Every demo keeps its
own smoke and its own sign-in.

Keep one instance of a server proof in its chosen demo. A shared **screen**
scenario already exercised on several SDKs may instead have one source body
and local registration entries using their current `test`, `expect` and
fixtures. All steps and expectations live in that shared scenario; local
composition contains no independent copy. This narrow source-sharing exception
preserves demo/stand ownership, smoke/sign-in and the current distribution of
runs. It neither runs every e2e on every demo nor moves specs between demos, and
sharing text alone does not reduce run time. A scenario registrar owns the
scenario; an ordinary toolbox helper does not acquire its caller's order or
navigation. See [the shared registrar sketch](#shared-screen-scenario-registration).

For a **new file** that checks the server, choose where the load is least at
the moment:

1. Name the functions and stand services the spec needs.
2. Find the demos where the feature is switched on — the feature constants and
   registered pages and agents in `demo/<demo>/backend/Hilos.php` — and whose
   stand carries the needed service. Read
   `demo/<demo>/docker/docker-compose.test.yml`: the Telegram/SMS gateway is
   currently in chat, tasks and polls. Check Mailpit, the gateway and the model
   against each activated stand's actual declarations; do not infer service
   availability from membership in the twelve-demo target. A planned carrier's
   activation below must supply the services its transferred areas need.
3. Of those candidates, choose the shortest group chain: add the demo's
   `check`, `php` and `e2e` durations from the `=== SUMMARY` table that
   `scripts/run-test-suite.php` prints at the end of the latest green full
   run. A tie permits either. A feature switched on only in chat leaves chat
   as the only candidate.

A new test in an existing spec goes into that spec. The durations come from a
run, never from `$demos` in `scripts/test-suite.php`: those numbers only hint
the launch order, nobody refreshes them, and every spec that lands leaves them
further behind. Nobody keeps the lengths level either. Load decides only where
a new spec file is born. A spec born
away from its area takes shared helpers from `framework/frontend/e2e/` and
`framework/frontend/scripts/`, without copying them; follow
[the toolbox route](#the-shared-toolbox--look-there-first-leave-there-what-you-grow)
before moving, splitting or porting a spec too.

#### Approved target ownership — HIL-1984

The [twelve ordinary carriers and their current activation state](../testing.md#ordinary-demo-carriers)
are cataloged in the testing guide, which also owns their commands, isolation,
cleanup and phase boundary. The matrix below is the approved **target** for
the framework areas that move. Its markers name the leaves that activate the
recipients and transfer the areas; a marked row is not a completed transfer.
It does not replace the load-based choice for a new server-spec file above.

In this inventory, `donor/{a,b}` means the existing
`demo/<donor>/tests/e2e/tests/a.spec.ts` and `b.spec.ts`; a single
`donor/name` means the same path with `name.spec.ts`. The destination is
`demo/<target>/tests/e2e/tests/`. Names identify whole areas, including later
additions before execution, rather than freezing their file list today.

| Target / view | Whole framework areas and current source specs | Activation and transfer owner |
|---|---|---|
| `pmd` / Vue | **Table geometry:** `pmd/table-geometry` (moved from chat in HIL-2016 slice 13). **Account/profile/security:** `pmd/{profile,profile-data,profile-time-zone,profile-session-time-zone,profile-photo}` (moved from chat in HIL-2016 slice 9); `pmd/{rename-moderation,account-deletion,account-merge,second-factor-mail}` (moved from chat in slice 10); `pmd/security-center` (moved from chat in slice 11). **Analytics:** `pmd/{analytics,analytics-browser-session}` (moved from chat in slice 11). **People/settings/Appearance:** `pmd/{users,settings,appearance}` (moved from binance-btc-tracker in HIL-2016 slice 12). | PMD active; all sixteen specs moved in slices 9–13. |
| `roblox-game-server` / Vue | **Change Log/Moderation/Communications:** `roblox-game-server/{change-log,change-log-table,change-log-tables,moderation,communications}` (moved from chat in HIL-2017). **Theme:** `roblox-game-server/{theme-account,theme-guest}` (moved from chat in HIL-2017). **Daemon/Backup/Logs/maintenance:** `roblox-game-server/{daemon,backup,backup-reopen,logs,logs-rotation,maintenance-restore,maintenance-words}` (moved from binance-btc-tracker in HIL-2017). | Roblox Game Server active; all fourteen specs moved in HIL-2017. |
| `solar-system-model` / React | **Account/profile:** `solar-system-model/{profile,profile-data,profile-time-zone,profile-photo,account-deletion}` (moved from tasks). **Theme/Analytics:** `solar-system-model/{theme-account,theme-guest,analytics,analytics-browser-session}` (moved from tasks). **Table geometry:** `solar-system-model/table-geometry` — the entire independent Geometry area split out of `ecommerce-shop/users`, with its React frontend fixture; the Users scenarios, F-001 included, moved on whole to `file-gallery-manager/users`. | Solar active; all ten specs moved. |
| `file-gallery-manager` / React | **i18n lifecycle:** `file-gallery-manager/{i18n-country-names,i18n-country,i18n-language-names,i18n-language,i18n-locale,i18n-overview}` (moved from tasks). **Daemon/Change Log/Communications:** `file-gallery-manager/{daemon,change-log,change-log-table,change-log-tables,communications}` (moved from tasks). **Users:** `file-gallery-manager/users` — the entire Users remainder of `ecommerce-shop/users` after Geometry was separated, including F-001 (moved from ecommerce-shop). | Gallery active; all twelve specs moved. |
| `preferans` / Angular | **i18n lifecycle:** `preferans/{i18n-country-names,i18n-country,i18n-language-names,i18n-language,i18n-locale,i18n-overview}`. **Theme/Appearance:** `preferans/{theme-account,theme-guest,appearance}`. **Analytics:** `preferans/{analytics,analytics-browser-session}`. | Active; transferred from polls (HIL-2020) |
| `concert-tickets` / Angular | **Daemon/Change Log:** `concert-tickets/{daemon,change-log,change-log-table,change-log-tables}`. **Profile/account deletion:** `concert-tickets/{profile,profile-data,profile-time-zone,profile-photo,account-deletion}`. | Active; transferred from polls (HIL-2021) |

**Move a whole area within its view framework, leaving no second proof behind.**
The only physical file split in this plan was the shop's `users.spec.ts`:
Geometry and Users were independent areas sharing one file, not a license to
divide arbitrary assertions. Geometry now lives in
[`table-geometry.spec.ts`](../../../demo/solar-system-model/tests/e2e/tests/table-geometry.spec.ts)
of the Solar System Model, and
the Users remainder of the shop moved whole to
[`users.spec.ts`](../../../demo/file-gallery-manager/tests/e2e/tests/users.spec.ts)
of File Gallery Manager. Geometry's fixture moves with its consumer.
The existing examples are
[`TableGeometryFixture.vue`](../../../demo/pmd/frontend/tests/tableGeometry/TableGeometryFixture.vue)
and
[`TableGeometryFixture.tsx`](../../../demo/solar-system-model/frontend/tests/tableGeometry/TableGeometryFixture.tsx).
A donor whose accessibility checks still open the fixture builds it from that
one source with its own dependencies into its own output, as chat does from
PMD's and the shop from Solar's.
Each activation carries the required frontend wiring, fixtures, preparation and
stand services with the consuming area. F-001 stays with Users, with its
existing quarantine status; a transfer does not heal it.

Areas not named above stay with their donor, including product and stand-owned
suites. Each donor keeps its own smoke/sign-in and leaves its features enabled.
Each recipient is responsible for its own guest, sign-in and real Analytics
activation proof, as specified in the testing guide. That local proof does not
create a second copy of a transferred framework suite. The existing exception
for one shared screen body with local registrations still applies; source
sharing alone does not reduce the number of runs. Its
[rollout](#rollout) is unchanged by these moves.

**Until activation, write both old and new tests of an area where it works
today; they move together.** The named leaf takes every spec of that area,
including additions since this inventory. It updates the current paths below
and other references it moves, and clears its markers in the same code commit
under [rule authoring](../rule-authoring.md#a-rule-written-ahead-of-its-code).
The React/Angular parity pass that followed these moves is HIL-2022.

#### Approved cluster-browser target — HIL-1985

The [six cluster carriers and their shapes](../testing.md#the-cluster-stands--six-demos-six-shapes)
are cataloged in the testing guide. This matrix is the approved **target**,
not a completed transfer; each marker names the leaf that moves the area.
Names identify whole areas, including additions before execution. Transfers
stay within their view framework and carry their quarantine status unchanged
(F-016, F-023, F-036, F-060); moving a spec does not heal a flake. Use shared
helpers from `framework/frontend/e2e/`, fixed browser time zones and the
existing behavior assertions; do not bring back decorative checks.

Here `donor/live/{a,b}` means
`demo/<donor>/tests/e2e/cluster/live/a.spec.ts` and `b.spec.ts`; `daemon` and
`after-loss` use the same path convention. The destination is
`demo/<target>/tests/e2e/cluster/`.

| Target / view | Whole areas and current source specs | Transfer owner |
|---|---|---|
| `roblox-game-server` / Vue | **Daemon and delivery:** `binance-btc-tracker/daemon/daemon`, `binance-btc-tracker/live/{daemon-clock,daemon-env-mismatch,daemon-env-orphans,daemon-section,delivery-window}`. | (not in the code yet — HIL-2030) |
| `roblox-game-server` / Vue | **Sessions, theme, i18n, protected mode:** `binance-btc-tracker/live/{sessions,theme,i18n,protected-mode}`. | (not in the code yet — HIL-2031) |
| `concert-tickets` / Angular | **Moderation:** `online-testing/live/moderation`, with five rotations of three distinct roles instead of three; F-060 stays on its identified variant. | (not in the code yet — HIL-2033) |

Binance keeps `live/{backup,logs,change-log}` and `after-loss/{backup,logs}`:
these need an archive holder and journal writer on a slave, which Roblox's
mixed roles do not guarantee. Online-testing keeps `live/analytics` with its
three source/viewer rotations.

React has no cluster-browser corpus to transfer. Cross-node sign-in, sign-out
and grant/revoke on the new React and Angular carriers are new proofs
(not in the code yet — HIL-2034). Browser phases and fixtures are chosen from
the stand declaration and native plan.
After the transfers each source file exists exactly once
(not in the code yet — HIL-2035).

#### Current owners during activation

The inventory and explanations below describe the working placement before the
target transfers. They remain the place to find today's paths, not a competing
target assignment. A leaf that actually moves an area updates its entries;
this documentation change does not pretend the destination is active.

The operations moves of 2026-09-27 paired the demos by view framework:
chat (Vue) → binance-btc-tracker (Vue), tasks (React) → ecommerce-shop (React),
polls (Angular) → online-testing (Angular).

| Area | Specs today | Lives in | Leaf |
|---|---|---|---|
| Settings, people and admin | binance-btc-tracker: settings, users, admin, admin-gating, account-lifecycle | binance-btc-tracker | HIL-1219 |
| Backup | roblox-game-server: backup, backup-reopen | roblox-game-server | HIL-1220, HIL-2017 |
| Protected mode and maintenance | binance-btc-tracker: protected-mode; roblox-game-server: maintenance-restore, maintenance-words | binance-btc-tracker, roblox-game-server | HIL-1221, HIL-2017 |
| Logs | roblox-game-server: logs, logs-rotation | roblox-game-server | HIL-1222, HIL-2017 |
| Cluster browser — backup, logs, protected mode, one person's tabs, theme settings/choices/reset, Change Log and live i18n edits across masters | binance-btc-tracker: tests/e2e/cluster (live, after-loss), playwright.cluster.config.ts | binance-btc-tracker | HIL-1232, HIL-1443, HIL-1465, HIL-1505 |
| Cluster browser — Analytics and Moderation across all three masters | online-testing: tests/e2e/cluster/live/analytics.spec.ts, tests/e2e/cluster/live/moderation.spec.ts, playwright.cluster.config.ts | online-testing | HIL-1423, HIL-1639 |
| Tables | binance-btc-tracker: table-lag, table-refusal | binance-btc-tracker | HIL-1223 |
| Notifications and communications | binance-btc-tracker: notifications, communications | binance-btc-tracker | HIL-1224 |
| The operations half of tasks | ecommerce-shop: settings, notifications, protected-mode, maintenance, backup-reopen; file-gallery-manager: users | ecommerce-shop, file-gallery-manager | HIL-1225 |
| The operations half of polls | online-testing: users, settings, notifications, protected-mode, maintenance, logs | online-testing | HIL-1226 |
| Sign-in on the server — third-party providers and their failures, codes over Telegram, SMS and phone, sign-in by a code and by a link, sign-up in several tabs of one browser | tasks: auth, oauth, phone-codes, admin-view-mode (the sign-in methods viewer case) | tasks | HIL-1324 |
| Step-up, session rotation, passkey | tasks: step-up, session-rotation, passkey | tasks | HIL-1324 |
| Profile on the server — password, address, other browsers' sessions, the GitHub link | concert-tickets: profile | concert-tickets | HIL-1325, HIL-2021 |
| Second factor | polls: second-factor | polls | HIL-1325 |
| Legal | polls: legal-admin, legal-reconsent, legal-terms, legal-consent, legal-agreements | polls | HIL-1325 |
| Data and privacy | polls: data-export, privacy-erase, impersonation-settings | polls | HIL-1325 |
| Languages and countries — each kit | the 25 i18n-* areas across binance-btc-tracker, file-gallery-manager, polls and preferans; Preferans and File Gallery Manager carry the six transferred lifecycle specs | binance-btc-tracker (Vue), file-gallery-manager (React), polls and preferans (Angular) | HIL-1504 |

tasks switched on Ways to sign in (`/profile/sign-in`) to carry them (HIL-1324);
polls switched on Ways to sign in, Sessions and Agreements of the profile, and its
Terms gained a substantial and an editorial revision, to carry them (HIL-1325).

The current Daemon screen specs live where their views are mounted:
binance-btc-tracker (Vue), file-gallery-manager (React) and concert-tickets
(Angular, moved from polls in HIL-2021). Ecommerce-shop also
activates the feature without `LOGS` and carries a standalone smoke of its own.
Online-testing has the backend feature without a UI. Chat checks the section's accessibility
in its existing `tests/e2e/tests/a11y.spec.ts`. The matrix above moves the three
ordinary `daemon` areas; the independent smoke and cluster proofs stay with
their existing owners in this phase.

Binance carries an independent live cluster smoke at
`tests/e2e/cluster/live/daemon-section.spec.ts`, titled “daemon section
navigation: follower entry points name the live leader and every section link
owns its address”. It opens the browser on a follower, checks existing consensus
inspection and the source selected by delivered Overview data, compares its
command port with the declared stand listener, follows the metrics link to that
source node's HTTP page, and follows the Placement and Env mismatch links. It
does not fault or restart a node or exercise the host handshake. The existing
destructive case at `tests/e2e/cluster/daemon/daemon.spec.ts` remains parked by
a static `test.fixme` and the host's `FLAKY_SKIP['daemon']`; it is not evidence
for the independent live section smoke. Its scenario still covers remote node
reports, a remote log-store restart, mismatch privacy over matching frames and
leader-loss recovery on a surviving follower. The host phase and restoration
steps remain documented in [../testing.md](../testing.md).

The parked case retains its shared `expectClusterOverview`,
`expectClusterDaemonNodePage`, `expectClusterLeaderPlacement` and
`expectClusterEnvironmentMismatch` checks in `daemon.ts`. The live smoke uses
`expectDaemonEntryPoints`, `expectDaemonPlacementAnswer`,
`expectDaemonPlacementRequests` and `expectDaemonPlacementOwnership`. The
shared helpers compare received page frames and real stand facts, not hidden
root DOM. `expectDaemonWorkerAgent` still expands newly reported worker rows
until the named agent is visible; a one-time list of expand buttons can miss an
arriving worker. `watchDaemonMismatchFrames(page)` remains armed before
navigation and returns matching mismatch `page_response`/`table_window` texts
plus `stop()`; direct node Env frames are outside that privacy boundary.
Navigation, order, contexts, the host handshake and inode/offset node-log
observations stay in the demo. The fresh `test:cluster:e2e -- --daemon` phase
and restoration before the old phases remain documented in [../testing.md](../testing.md).

The current Moderation section specs live where its views are mounted:
roblox-game-server (Vue), tasks (React) and polls (Angular) each carry
`moderation.spec.ts` with one set of tests — tests 1–5 have the same titles and
steps in all three, the checks they share are one copy in
`framework/frontend/e2e/moderation.ts`, and each file keeps the scenario, its
order and its navigation. The three kits declare no kind of their own, so the
section each walks holds the framework's two, the profile name and the profile
photo. The file runs in file order (`mode: 'default'`) and writes rules of its
own only on `hilos_profile_photo`, the kind every kit builds in; each test
prepares its own rules and reserves their removal through `cleanupFixtures`
before it opens Add, so no test relies on a neighbor having passed. Only
roblox-game-server carries test 6: a rule edited on the Rules page is what the
model's next photo check carries, read from the stand model's requests. Only
tasks carries tests 7 and 8: test 7 proves on the server that forged rule
writes from a view-mode viewer are refused with the view-mode reason; test 8
guards the shared cleanup, `deleteModerationOwnRules`, which deletes only the
reserved rule and keeps a neighbor that differs from it only by surrounding
spaces. The `Moderation accessibility` group of the `a11y.spec.ts` of chat,
tasks and polls creates one rule of its own on `hilos_profile_name` and walks
the section, the Add, Edit and Delete dialogs and a view-mode viewer through
the shared steps; roblox-game-server has no accessibility spec. The model's
check of a profile name — published only after approval, the previous name kept
after a refusal — is one test of the same title in `profile.spec.ts` of
solar-system-model and concert-tickets. None of these files carries a
`spec-owner` line; chat's message and message picture moderation specs do
(HIL-1638).
Chat's own kinds — its messages and their pictures — are proved by one test
of `message-moderation.spec.ts`: the section lists both as the project's and
opens each on its Overview.

The current Analytics section proofs are in PMD (Vue), solar-system-model
(React) and preferans (Angular): each carries `analytics.spec.ts` and
`analytics-browser-session.spec.ts`. The Vue pair moved from Chat, the React
pair from Tasks and the Angular pair from Polls.
The three session specs share one test title, while the titles in
`analytics.spec.ts` follow the pages each kit serves. The actions the specs look
at are the sign-ins of the person and of the administrator, because
solar-system-model and preferans declare no actions of their own. PMD and
Preferans sign in on the main page, so the actions stand on `main`; Solar signs
in on `/hilos`, where its sign-in form opens in place, so they stand on
`hilos`. Their framework journeys carry
no `spec-owner` line (HIL-1422); PMD retains the approved demo owner line for its
moved Vue screen journeys (HIL-2016).

online-testing additionally carries cluster browser proofs for Analytics and
Moderation over the stand's three masters. The Analytics spec uses three
sequential source/viewer pairs and the shared checks. The Moderation spec
rotates a person, a rule-writing administrator and an observing administrator
across distinct masters: the saved name rule reaches the observer without
reload, governs one own-name model request, and its removal reaches the writer
as pending until the standard Apply action removes the row. Both are framework
specs; the separate cluster config keeps them out of the single-node suite, and
the stand runs live only (HIL-1423, HIL-1639).

Current Theme coverage has one full UI owner per kit and separate build probes.
The target matrix moves the named Theme/Appearance areas; first-frame build
probes, product shell smoke and cluster proofs keep the owners below:

| Proof | Specs | Lives in |
|---|---|---|
| Guest storage, choices, System media, reset and reload/new tabs; account header/profile delivery between independent sessions, another person's isolation, an open receiving modal, keyboard/focus | theme-guest, theme-account | roblox-game-server (Vue), solar-system-model (React), preferans (Angular) |
| Stale Dark reconnect after explicit reset without adoption; first login adoption by a never-chosen account; synchronized account's cached first frame | theme-account | roblox-game-server only |
| Both Appearance editors, live viewer rows, Save/Enter refusal, default and Off/On delivery, discard, keyboard/focus | appearance | binance-btc-tracker (Vue), tasks (React), preferans (Angular) |
| Built head: remembered Dark and fresh System dark on `/` and `/about`, HTML attribute and painted background with application JS blocked | theme-first-frame | chat (Vue/Vite), ecommerce-shop (React/Vite), online-testing (Angular build/SSG) |
| Product shell account header/reload/reset; also guest choices and the Appearance card with its two live rows in the React and Angular shells | theme-smoke | binance-btc-tracker, ecommerce-shop, online-testing |
| Three masters: live settings, independent account choices and reset, new tabs and stale Dark reconnect without adoption | cluster/live/theme | binance-btc-tracker |

Only the shell smoke carries `spec-owner: demo`. The guest/account, Appearance
and first-frame specs are framework proofs. Profile Off/On stays within each
installation: chat changes its existing settings page; tasks and preferans use
their own Appearance. Solar has no Appearance page, so React's Off/On proof
stays in Tasks' `appearance.spec.ts`. The build probes cover a cached pick or a
fresh browser's System fallback, not a server account choice that browser has
never received.
The full production build/head sweep already checks every emitted entrypoint.

The current shared Change Log receipt and history scenarios live in
roblox-game-server (Vue), file-gallery-manager (React) and concert-tickets
(Angular, moved from polls in HIL-2021).
They cover an administrator blocking a person from
that person's card: the column's value is journaled in every demo, and the
signed-in person is the author (a registration receipt has no author because it
comes from a guest). roblox-game-server additionally covers the adopted Vue
section head, overview tiles, four section links with Back, and direct Feed and
Noisy columns entry through F5. File Gallery Manager checks the React section
head through the shared owner-page checks. Concert Tickets checks the Angular section and table heads; its
table paths cover direct entry, reload/F5, Back and Forward, with receipt/history
links, record-filter clearing, the old-address refusal and the Noisy page.
Only chat carries `change-log-bot.spec.ts` (`spec-owner: demo`): a bot's
description is the only long text under the journal. binance-btc-tracker,
ecommerce-shop and online-testing mount the section without the single-node
specs (HIL-1464).
binance-btc-tracker additionally carries the separate cluster proof in
`tests/e2e/cluster/live/change-log.spec.ts`: one remote writer's block receipt
reads identically through every master (HIL-1465).

The current languages and countries coverage spans the same 25
`i18n-*.spec.ts` areas across binance-btc-tracker (Vue), file-gallery-manager
(React), polls (Angular) and preferans (Angular). Preferans carries the six
lifecycle specs transferred from polls and File Gallery Manager the six
transferred from tasks; the other i18n areas stay with their current owners, and
Vue's i18n stays in binance-btc-tracker. Their test titles match but for the
country head scenarios of `i18n-country-card` and `i18n-country-name-write`,
which each kit splits its own way. The Vue kit moved from chat to
binance-btc-tracker on the owner's word
(2026-10-15), taking about 4.4 minutes off chat-e2e, the floor of the run. The
country's shared head, its Overview · Names row, its live name and its live 404
are proved in these files on all three kits, through the checks of
`framework/frontend/e2e/i18n.ts`: `expectCountryPageHead`,
`watchI18nLivePages`, `expectDeletedCountryCard` and
`expectDeletedCountryCardReloaded`. The temporary React head specs of
ecommerce-shop (HIL-1604) moved into tasks (HIL-1606), so ecommerce-shop and
online-testing mount the section without specs. The section's tests share the
database and daemon within a run. Prepare each scenario's preconditions without
depending on file order. Use the shared test-command operations in
`framework/frontend/scripts/i18n.mjs` through the test-scoped
`framework/frontend/scripts/i18nFixtures.mjs` fixture described below.

**What stays in chat after this phase:** its product (the specs marked `demo`),
the stand's own specs (marked `stand`), the shell (`a11y`, `about`, `navigation`,
`layout`, `page-error`, `unbuilt-page`, `prerender`, `license`, `smoke`, `connection`,
`admin-view-mode`), its remaining `auth` cases and every unlisted area. Its
`auth` cases include the still button, sign-up with a return, a wrong password,
and the re-check of an open admin and profile on sign-out. The view-only
takeover test parked by the owner stays in chat's `impersonation-settings` —
the write it is refused is a chat message.

PMD currently carries the sixteen transferred framework specs: `table-geometry`;
`profile`, `profile-data`, `profile-time-zone`, `profile-session-time-zone`,
`profile-photo`, `rename-moderation`, `account-deletion`, `account-merge`,
`second-factor-mail`, `security-center`, `analytics`,
`analytics-browser-session`, `users`, `settings` and `appearance`. Its
`profile.spec.ts` keeps complete section navigation, including registered
Devices and Agreements, plus the rename, unsent-draft and conflict cases.
PMD's own `activation.spec.ts` is an additional carrier proof, not a transferred
framework spec. Chat keeps `chat-photo.spec.ts` for its product photo flow and
`notifications.spec.ts`'s own-rename proof; moving framework specs does not
move Chat's product behavior.

`admin-gating` and `account-lifecycle` are the screens of an administrator
over people: framework-level tests moved to binance-btc-tracker under HIL-1219.
They are not named in the new transfer matrix and stay there; the new plan
moves its named Users/settings/Appearance areas, not every admin spec.

The same placement rule distinguishes the remaining cases: a spec lives on the
side of the feature it is about. The two-tab rename conflict on the framework
people card is `users.spec` in binance-btc-tracker, which already carries that
screen, until the Users area moves under the matrix. The test that switches on
the confirmation before removing rights sits in
`step-up.spec`: its subject is the switch, and the switch lives in the Security
Center, on the account side. `admin-view-mode.spec` is the shell's gear over
chat's screen; where every viewer e2e lies is the table in
[../architecture/admin-view-mode.md](../architecture/admin-view-mode.md), "Tests".

The old demos switch nothing off — only the spec moves; the new demo switches
on what it tests together with the shell, sign-in and working administrative
Analytics required by its activation in the testing guide.

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

#### Whose a spec is — the spec-owner line

A spec of a demo's own product or of the stand carries one of these lines,
literally, on a line of its own under the imports, above its header comment:

```ts
// spec-owner: demo — <why>
// spec-owner: stand — <why>
```

Use **one line per file**. No line means the framework's spec; never write
`framework` as the owner.

- **demo:** another demo could not carry the spec without this demo's product
  — its entities, screens or domain events. Chat's rooms and messages, bots,
  the moderation of its messages and their pictures, and its own Users screen
  belong here.
- **framework:** another demo could carry it by switching the feature on,
  even when the scenario passes through the product. Chat's moderator judges
  a profile photo, but the photo is a framework feature, so `profile-photo`
  carries no line. The Moderation section is the framework's too: its
  `moderation.spec.ts` carries no line in any kit.
- **stand:** the spec holds an emulator of the stand to its contract, with no
  product taking part.

A file containing **whole tests of both sides** is mixed: cut it in two, with
the framework half keeping the file name. One assertion of the other side
inside a test does not make a mixed spec.

**Whenever you touch a spec, check its side.** If it is about the demo or the
stand and has no line, add one. If its side changed, fix the line — remove it
when it becomes a framework spec. If the sides mixed, cut the file. No guard
holds the line; this rule does.

The distinction matters because framework specs are read across Vue, React
and Angular; a demo's own spec has nothing to compare there, and a stand spec
belongs beside its emulator.

Why the moves: on 2026-10-03 (run 0786, the test box, seven lanes), chat's chain —
`chat-php`, `chat-e2e` and `chat-check` on one stand — took 18m48s of the full
run's 19m14s. `chat-e2e` alone carried 211 tests and took 891 seconds. After
HIL-1324 and HIL-1325 moved their specs (run 0995, the test box, 2026-10-09),
`chat-e2e` carried 129 tests and took 572 seconds, and the chain about 11m54s
(`chat-php` 117 s, `chat-e2e` 572 s, `chat-check` 25 s).

## Backend state — clean runs and test-owned preconditions

The full clean cycle resets the database and daemon **between runs**, including
a pointed re-run; follow [Re-running tests and state between runs](../testing.md#re-running-tests-and-state-between-runs).
Within a suite, tests share the database and daemon. There is no full reset
before every test and no per-test transaction rollback around the live daemon.
Each test owns its created preconditions and their cleanup, and must work
without a neighboring test having passed. Use the fixture operations below;
neither an implicit global reset nor direct SQL substitutes for that ownership.

## Selectors — stable action hooks and semantic headings

Select interactive elements by stable `data-id` through `getByTestId`; do not
replace action hooks with generic text or positional searches. `data-id` is
the configured `testIdAttribute`, not an outcome to assert with
`toHaveAttribute('data-id', ...)`.

Find the page heading with `page.getByRole('heading', { level: 1 })`, without
a component-specific head id. Assert its title and count when proving page
identity and the single-h1 accessibility contract. This semantic lookup survives
a head redraw and does not relax the stable-hook rule for actions.

## The shared toolbox — look there first, leave there what you grow

Before creating, changing, moving, splitting or porting an e2e spec or helper:

1. **Open both shared homes before writing driving code or copying a helper.**
   Read the exports in `framework/frontend/e2e/index.ts` and the relevant
   `framework/frontend/scripts/*.mjs` modules, including for a Node-only task.
2. Use the existing call when it serves the operation. Browser actions and
   checks live in `framework/frontend/e2e/`; network-only mechanics live in
   `framework/frontend/scripts/`.
3. If the operation is missing and would mean the same thing in another demo,
   view framework or stand, add it to the appropriate shared home **with its
   first consumer**. Shared meaning decides placement; a second copy is not a
   prerequisite.

Each demo's Playwright `test` is imported through its local
`tests/e2e/test.js` entry by relative path. That entry composes the demo's
installed Playwright base with `withAdaptiveTimeouts`. Specs and helpers may
still import `expect`, Playwright types, `defineConfig` and `devices` from
`@playwright/test`; the demo import guard rejects value imports of `test` through
named, aliased, namespace, default or re-export forms, with
`demo/*/tests/e2e/test.ts` as its only exception.

From `demo/*/tests/e2e/tests/`, both homes are reached directly:

```ts
import { typeInto, clickSubmit } from '../../../../../framework/frontend/e2e/index.js'
import { switchLanguageOn } from '../../../../../framework/frontend/scripts/i18n.mjs'
```

A demo's `tests/e2e/helpers/` keeps its own fixtures and product composition.
Framework sign-in, command transport and mailbox reads stay shared. For example,
`demo/chat/tests/e2e/helpers/session.ts` imports the shared `signUp` as
`signUpSession`, and its local `signUp` supplies Chat's entry control:

```ts
return signUpSession(expect, page, { entryId: 'message-signin' })
```

That wrapper exists because Chat opens sign-in through its message composer.
It composes the shared flow; it does not reimplement registration. A local
wrapper is warranted by the product's own composition or fixtures, not merely
by a different demo directory or stand address. Moving, splitting or porting a
spec updates its imports and any product composition; it does not carry a copy
of the shared helper along with it.

`lazyBundles.ts`, exported through the toolbox `index.ts`, reads the canonical
`initial-bundle-report.json`, records real client JS/CSS requests and page
replies, holds a selected bundle or first reply, and observes outlet/departure
presentation. Use its `selectLazyBundle`, `holdClientBundle`, `holdPageReply`,
`watchClientBundles`, `watchPageReplies`, `watchPagePresentation` and shared
`expectLazy…` checks instead of guessing chunk names or readiness from one DOM
node. Each demo's `lazy-pages.spec.ts` exercises its built production artifact:
slow code/data, failure and revisit, sibling isolation, direct/history
navigation, reconnect and access outcomes. The six artifacts cover Vue, React
and Angular. These Chromium checks and the output graph/package/AOT checks prove
different seams; neither claims Firefox/Safari mobile or owner real-device
acceptance. Loading and retry limits are in
[multiframework-core.md](multiframework-core.md#page-code-and-first-answer-state);
measurement and source-proof scope are in
[build-and-docker.md](build-and-docker.md#initial-client-graph-and-budget).

Two rules keep it working:

- **A file in `framework/frontend/e2e/` imports from `@playwright/test` with
  `import type` and nothing else.** The demo suite carries its own installed
  Playwright, and pulling a second copy out of this folder is refused by the
  runner itself — *Requiring @playwright/test second time*. A type import is
  erased before that can happen;
  browser objects arrive from the caller, and a helper that makes checks
  receives the spec's `expect` as its first parameter.
  ESLint holds the rule: `framework/frontend/eslint.config.mjs` refuses a value
  import of `@playwright/test` under `e2e/`, so the `fe-checks` lint goes red
  before any demo runner does.
- **Demos reach it by relative path**, the way their Playwright configs already
  reach `framework/frontend/scripts/timeout-scale.mjs`. The runner mounts the
  whole repository, so no package boundary stands in between, and no install step
  is added to a suite. Inside either toolbox, import a sibling module directly
  (`./input.js`, for example), not through its own index barrel.

Section checks all three kits must meet alike — what a page shows, its totals,
the node's page — live here as `expect…(expect, page, …)` functions. Tests stay
in the demo's spec, directly or through the shared screen registrar below:
the scenario owns its order, navigation and choice of checks, never an ordinary
toolbox helper. Playwright reports a test under that demo spec's path; the Tests tab and
flake accounting use that path. Three copies of the Daemon checks drifted:
placement totals were fixed in only two of them. Sharing the checks keeps that
contract in one place. A driving helper that checks readiness, such as
`clickSubmit(expect, button)`, also receives the spec's `expect`. Helpers without
checks need not take it; scenario assertions stay with the caller.

For Node-side work, use `switchLanguageOn(code)` and the other operations in
`framework/frontend/scripts/i18n.mjs` for language/country fixtures. For a
command without an existing operation wrapper, `createStandCommandChannel`
(`standCommand.mjs`) reads `COMMAND_HOST` and `COMMAND_PORT` from the runner's
environment and delegates transport to `createCommandChannel`
(`commandChannel.mjs`). Do not embed a stand address in a helper; an explicit
cluster host override remains supported by `createStandCommandChannel`.
The scripts folder is a vitest project, so the shared transport has its unit
test beside it.

### Shared screen scenario registration

For the screen exception in
[Which demo carries a spec](#which-demo-carries-a-spec--the-server-check-travels-the-product-stays-home),
a common browser registrar receives the local `test` and `expect`. It imports
Playwright types only, never a second runner by value. Keep the shared homes:
browser scenarios and helpers in `framework/frontend/e2e/`, network operations
in `framework/frontend/scripts/`. Local entries supply their existing fixtures
and product composition without copying the scenario's steps or assertions.
Ordinary helpers retain the rules above; extracting a scenario is an explicit
choice, not permission to hide changing scenario order inside a helper.

This is a registration sketch, not an existing module or export. `LocalTest`
and `LocalExpect` stand for the local runner's types; the pilot supplies the
concrete typing and registration wiring without adding a dependency:

```ts
function registerGuestThemeCase(test: LocalTest, expect: LocalExpect) {
  test('guest can choose the dark mode', async ({ page }) => {
    await gotoPage(expect, page, '/', PAGE_READY)
    await chooseHeaderTheme(expect, page, 'dark')
    await expect(page.locator('html')).toHaveAttribute('data-bs-theme', 'dark')
  })
}

// Three separate existing local spec entries, each with its own imports:
// import { test, expect } from '../test.js'
registerGuestThemeCase(test, expect) // Chat / Vue
registerGuestThemeCase(test, expect) // Tasks / React
registerGuestThemeCase(test, expect) // Polls / Angular
```

This excerpt illustrates one body, not the full pilot's coverage. The migration
must retain the useful cases and cleanup of its existing specs. It inherits
each carrier's settings, including its declared browser time zone, and adds no
runtime randomness or cross-demo environment matrix. Keep the theme exception
limited to proving the theme choice. Local reporting, fixtures and test-owned
cleanup must survive registration; their verification is part of the
[browser pilot](#rollout), not a claim that the harness already exists.

### Preconditions — test commands with owned cleanup

Prepare surrounding state with an existing test-only command operation through
the shared transport, not through UI forms when that operation is available.
An operation owns only its test's resources and pairs setup with cleanup in a
test-scoped fixture or `finally`, so setup failures, failed assertions and test
timeouts still run teardown. Await it: remove dependents before roots, attempt
every registered cleanup, and report cleanup errors without losing the original
failure. Do not delete foreign, built-in or default data. Restore borrowed state
to what the test found instead of clearing its row.

`framework/frontend/scripts/i18nFixtures.mjs` is the existing example over
`i18n.mjs`: `createI18nFixtureScope` registers cleanup after successful command
creation and consumes it in LIFO order; `i18nFixtures` supplies Playwright's
test-scoped `finally` and reports failures with `AggregateError`. Declare roots
before dependents. Use it directly for i18n; another family follows the ownership
and teardown contract without copying the whole i18n scope.

From `demo/*/tests/e2e/tests/`, the real fixture binding is:

```ts
import { expect, test as base } from '@playwright/test'
import { grantAdminToSelf } from '../../../../../framework/frontend/e2e/index.js'
import {
  i18nFixtures,
  type I18nFixtureScope,
} from '../../../../../framework/frontend/scripts/i18nFixtures.mjs'

const test = base.extend<{ i18n: I18nFixtureScope }>({ i18n: i18nFixtures })
```

Inside that test, `await i18n.country({ code: 'qr', currencySymbol: '¤',
currencyCode: 'XQR' })` prepares a companion country and registers its cleanup.
When **creation itself is the action under test**, use
`await i18n.ownCountry('gg')` to reserve the test-owned UI subject and arm cleanup
before opening Add; the person then creates it through the UI. Reservation does
not create the row and does not authorize taking a business row. The lifecycle
examples in "Choose the scenario" use both shapes.

If the needed command operation does not exist, resolve it through the task's
normal contract process, including approval for any gated contract change.
Do not silently add SQL or a general reset, or invent an API in the spec.

### Time — a command brings the moment to now

When a scenario meets a timer — an expiry, a cooldown, a delivery window, a
polling or reporting interval, a heartbeat and its staleness, a cron rule, a
rotation by age, a delayed answer — and the assertion depends on that period
expiring, the default is the feature's own test-only command. It brings that
one moment to now and stamps it where the browser reads it; the spec then waits
for the state that arrives over the socket. Try that shape first:
`test:verification:end-pause` ages the resend gate and stamps the browser's line
at now (HIL-1186), so the step after the pause runs at once and the production
cooldown stays what it is. A browser spec calls the shared
`endResendPause(page, address)` from `framework/frontend/e2e` instead of a local
copy; it wraps `endVerificationPause`, the network call in
`framework/frontend/scripts`. A missing command is the leaf's contract work.
If a cron rule's natural run can invalidate the spec's own checks, pause that
rule before `test:cron:fire` and remove the pause in awaited `finally` cleanup;
do not work around the race by choosing a less frequent rule.
See
[cron schedule pause commands](../cli/commands.md#pausing-a-cron-schedule-while-its-test-checks-the-rule).

**The nightly log rotation is held far away on every stand.** A stand that runs
a daemon sets `LOG_ROTATION_CRON=0 3 29 2 *` (29 February), because a rotation
carries `daemon.log` off and everything reading the live file stays red until
the daemon's life ends. A spec that needs a rotation brings it to now with
`test:logs:rotate` or `test:cron:fire hilos_log_store/hilos_log_rotation` and
leaves the stand's schedule alone. A spec that writes `logs.rotation.cron` —
applying a logging mode does — clears that setting before it ends, so the far
schedule answers again.

A setup that avoids waiting for the clock is also allowed when it catches the
same regression and leaves the stand's timer unchanged — for example,
subscribe before the action and assert on the same event. Ordinary waiting for
result delivery or a subscription update, without depending on a period
expiring, does not count toward the real-wait budget below.

**Temporary e2e exception.** The executor may keep real-clock waits without
approval only in e2e, with a total of **at most 3000 ms per test**, calculated as
the sum of the upper limits of all real-clock waits in that test. Use bounded
polling of an observable server response or page state, for example
`expect.poll`. Put this line directly above each poll, naming its upper limit
and the observable state:

```text
// real-wait: <limit> ms — <observable state>; replace with a feature test command
```

If a real wait remains, the executor must **file a proposal** to replace it with
the feature's test command. A proposal number on the comment line is optional.
Two waits bounded by 1000 ms and 2000 ms fit the exception with those comments
and the proposal; 1000 ms and 2001 ms require approval. Fixed pauses
(`waitForTimeout` or sleep), raised general timeouts and `test.slow()` bought to
let a period pass do not qualify for this exception.

Real-clock waits outside that exception, including every such wait in a PHP
test regardless of duration, require the owner's approval in the leaf's
contract ([agents.md](../../../agents.md#contract-approval-gate-hard-stop)).
Shortening a production timer on the stand through test env or a compose
overlay always requires that approval: every spec on that stand runs on the
changed clock, and two specs that need different clocks end up split into
phases with daemon restarts between them. Name the timer, its production value
and why no feature command can bring its moment to now in the approval request.
The feature's own test command needs no approval under this time gate.

A production value is not proved by waiting it out; assert the default or the
constant in a unit test. Where the period itself is the subject — a delivery
window's cadence — observe a few real periods at the value the product runs
with and drive the source by a command, not through a slow UI; do not stretch
or shrink the period to fit. Those real waits follow the same exception and
approval boundaries above.

Command isolation, the absence of a universal clock and the existing OAuth
clock-wait debt are in
[`../cli/commands.md`](../cli/commands.md#time-based-features-no-universal-clock).

### People and administrators as preconditions

For an ordinary administrator, call `await grantAdminToSelf(expect, page)` once
per scenario, not once per step. It opens the main page, grants the visitor
rights over the command channel and waits for full access in that browser.
When the scenario needs a verified account, use
`createTestUser(expect, page, options)`; for a verified administrator use
`createTestAdmin(expect, page, options)`. All three are exported from
`framework/frontend/e2e/index.ts`. The account helpers use the existing
`testUser.mjs` create/bind operations; preserve their declared account/session
lifetime and do not infer a global session or `storageState` policy from them.

Keep UI sign-up when registration, consent or identity creation is the subject,
including an event whose production depends on that real registration. Likewise,
do not replace a sign-in or session transition the scenario is proving with a
command bind. Independent people and independent sessions keep their own browser
contexts; a fixture must not collapse the isolation the scenario tests.

### Other shared network operations

For delivered letters, use `waitForMailCode(address, subject)` or the named
registration, recovery and sign-in readers in `mail.mjs`; for the mailbox
itself, use `waitForMailTo(address, subject)` / `waitForAnyMailTo(address)` in
`standMailbox.mjs`. These calls own the polling and reads; a demo keeps only
the interpretation specific to its own product.
The authenticator-app code (`totp.mjs`) is node-side, in
`framework/frontend/scripts/`; connecting the first app (`secondFactor.ts`) and
session-cookie name helpers (`sessionCookie.ts`) live in
`framework/frontend/e2e/`.

The helpers of the stand's residents live in the same two homes, once for every
demo that reaches the stand: `standGateway.mjs`, `standOAuth.mjs`, `standSms.mjs`,
`standTelegram.mjs`, `standModel.mjs` and `standMailbox.mjs` in
`framework/frontend/scripts/`, and the person at the OAuth consent window in
`framework/frontend/e2e/standOAuthUser.ts`. A resident's helper is born here with
its first leaf; a demo does not copy it, and keeps only a wrapper that turns a
resident's raw answer into its own terms. The framework parses a moderation
verdict, so its wrapper `dictateModerationVerdict(key, allow, reason)` lives in
`standModel.mjs` beside `dictateModelAnswer`. The addresses — `STAND_GATEWAY_URL` and
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

`MAINTENANCE_PATH` is the Overview address, `/hilos/maintenance`; the child
Circle address is `MAINTENANCE_CIRCLE_PATH`, `/hilos/maintenance/circle`, and
the Words address `MAINTENANCE_WORDS_PATH`, `/hilos/maintenance/words`.
`expectMaintenancePageCurrent(expect, page, currentPage)` identifies the current
Maintenance page by one level-1 `Maintenance` heading and the `currentPage` link
(`hilos_maintenance`, `hilos_maintenance_circle` or `hilos_maintenance_words`) as
the only link in the `Maintenance pages` navigation marked
`aria-current="page"`. Page transitions and addresses stay in the spec; the helper
does not constrain the head's other links or its drawing.

`expectMaintenanceWords(expect, page, log, after)` reads the first Words
`page_response` in the delivery log after bookmark `after`. Start the log before
the page's first socket and take the bookmark before entering Words. It checks
every response row against the visible table and returns `MaintenanceWordsRow[]`
for the existing comparisons across navigation and reload. It also verifies the
read-only table has no search, filters, main action, actions, selection or row
controls.

`addToMaintenanceCircle`, `confirmMaintenanceCircleRemoval` and
`clearMaintenanceCircle` drive the circle dialogs and settle on their closing.
The helpers do not navigate: their page must already show the SDK's circle table.
Every SDK draws that table on the Circle page.
`confirmMaintenanceCircleRemoval` also expects the removal dialog to be open.
`maintenanceCircleRow` and
`maintenanceCircleOnline` locate a row and its presence mark; `CIRCLE_ONLINE` and
`CIRCLE_OFFLINE` are that mark's words. Only an address somebody has confirmed can
be named: a phone signed into by SMS code, or an email proven by its registration
code or confirmed through the profile (HIL-825). The database list outlives a
scenario, so start every circle scenario with `clearMaintenanceCircle`. Assertions
stay in the demo.

The same file, `framework/frontend/e2e/maintenance.ts`, drives the manual window
on Overview. `turnOnMaintenance` presses **Turn on maintenance** on the open
banner, confirms, and settles on the dialog's closing, which only the ack brings.
`issueMaintenanceCode` issues a code from the manual card, reads it off its one
showing and closes the result, which forgets it on the page; it returns the code.
`turnOffMaintenance` confirms **Turn off maintenance** and does not wait for the
dialog: the lift reloads every open page, this one too, so wait for the expected
state on the reloaded page before navigating. `presentMaintenanceCode` types a
code into the maintenance screen and submits it. The first three expect the
operator's page on `/hilos/maintenance`. Teardown uses `openProtectedModeIfAny`
from `framework/frontend/scripts/protectedMode.mjs` to turn a manual window off
because the test open refuses one. `expectManualMaintenanceOn` proves the manual
state on Overview; circle membership is proved separately on the SDK's table page.

`MAINTENANCE_PATH`, the manual copy (`MANUAL_STUB_TITLE`, `MANUAL_STUB_MESSAGE`,
`MAINTENANCE_ON_NOTICE`) and `MANUAL_MAINTENANCE_OPERATION`;
`expectManualMaintenanceOn`, `expectHeldByManualMaintenance`,
`expectMaintenanceCodeIssued`, `expectAdmittedToManualMaintenance` and
`expectMaintenanceOpen` check the manual window, each taking the spec's `expect`
first, then its `Page`. Each kit's `protected-mode.spec.ts` runs one manual
scenario under the same title; only Vue (binance-btc-tracker) carries the console
entry, one code for two browsers and every page reloading itself — server and core
checks.

### The bell and the channel switch — `openBell`, `unreadBadge`, `enableEmailChannel`

`openBell` opens the bell a demo mounts in its header slot (`HilosNotificationBell`)
and settles on its dropdown; `unreadBadge` is the unread count, matched at the front
of its text because its label carries a visually-hidden suffix. `enableEmailChannel`
turns the email channel on from the communications hub and expects the operator's
page already on `/hilos/communications` — navigation is the demo's. It is
idempotent: the channel's switch is a setting of the whole stand, so a channel
another test switched on is left alone. The emit that makes a notification exist
and the sign-in that makes its recipient are the demo's; so are the assertions.

### `leaveSecondAdmin(browser, grantAdmin, contextOptions)` — an administrator to stay behind

The server refuses to take the administrator flag from the last administrator,
and no seed holds one. A test that revokes the flag therefore needs another
administrator to exist, and without one it passed only when an earlier test of the
same run had happened to leave one. `leaveSecondAdmin` is how such a test brings
that administrator itself, before its revoke, so it also passes pointed alone on a
fresh stand. It is a tool of those tests; it is not a rule for the others.

It takes three arguments: the `browser` of the test; `grantAdmin`, a function that
makes the visitor on the `Page` it is handed an administrator and returns that
person's user id; and `contextOptions`, the project's `baseURL` and
`ignoreHTTPSErrors` from `test.info().project.use`. A context made off the
`browser` fixture inherits none of them, the tolerance for the stand's
self-signed certificate included, so the caller passes them on. The grant runs in
a browser context of its own, not in a second tab of the test's page: identity is
the session cookie, and two tabs of one context are one person. The context closes
whether the grant succeeds or throws, and a failed grant is not swallowed — the
scenario does not go on without its second administrator. Nothing revokes the new
account, deletes it or remembers it for the next test: it stays on the stand until
the stand is reset.

The demo supplies the grant callback by composing `grantAdminToSelf` or
`signUpAdmin` from `framework/frontend/e2e/index.ts` with the spec's `expect`
and any product entry options. The shared helper confirms full access in the
browser; its command endpoint comes from the runner environment. The cluster
test wraps the grant in a callback that first points the second page at the
follower.

### The Daemon section — names and checks

[`daemon.ts`](../../../framework/frontend/e2e/daemon.ts) owns the `DAEMON_*`
paths, child and breadcrumb ids, placement types,
cell id and totals. `expectDaemonPage` and `expectStandaloneOverview` check the
Overview; the Overview helper checks its entry line and that no Placement view
is mounted there. `expectStandalonePlacement` follows the link to the separate
Placement address, and `expectUnfilteredTotals` reads the complete summary on
that page. `expectStandaloneWorkers`, `expectStandaloneCron`,
`expectStandaloneHttp`, `expectStandaloneEnvironment` and
`expectStandaloneMismatch` check the remaining pages. Every check takes the
spec's `expect` first, then its `Page`. `daemonRestart.ts` owns
`RESTART_MARK_TIMEOUT_MS`, the observation budget for the shared restart mark.
The three mounted SDKs keep their own section spec; only Vue carries the forged
restart request, a server check.

The shared section checks include `expectDaemonSectionHead` and
`expectDaemonEntryPoints` for the catalogued head, navigation and source ports;
`expectDaemonPlacementAnswer`, `expectDaemonPlacementPending`,
`expectDaemonPlacementRequests` and `expectDaemonPlacementOwnership` for the
Placement page's complete initial reply, pending state, direct subscription and
table ownership; `expectDaemonSectionAccessibility` for the named table and
keyboard controls; `watchDaemonPlacementLive` and
`expectDaemonPlacementReset` for live retention and navigation cleanup. The
`readDaemonSectionOverview` helper parses through the strict
`daemonOverviewSchema`. `expectDaemonRootEntryPoints` checks source links on the
Overview without assigning a Placement window to it.

Binance's live cluster carrier is `cluster/live/daemon-section.spec.ts`; it is
separate from the parked destructive scenario in `cluster/daemon/daemon.spec.ts`.
The live test opens on a follower and confirms the live leader's delivered
entry-points projection, the source HTTP page and the separate addresses for
Overview, Agent placement and Env mismatch, without node failure or restart.
The parked test's host phases, cleanup and artifacts are described in
[testing.md](../testing.md). `expectClusterOverview` and
`expectClusterDaemonNodePage` wait for real node reports; the latter also accepts
a reported empty roster. `expectDaemonWorkerAgent` expands visible worker rows
as they arrive until the requested agent is visible. Given the node's command
host, these checks bring its report to now (below). The parked scenario's
`expectClusterLeaderPlacement` checks the `Leader-hosted` label, exactly one
instance in the new leader's cell and `?` in the dead member's cell.
`expectClusterEnvironmentMismatch` checks remote digests and unknown silent cells.

The cold standalone Placement cases cover admin and view-mode readers. Their
first reply carries the head, `daemon` data and first table window in one
`page_response`; a cold tab subscribes only to `hilos_daemon_placement`, without
a header or summary action. Reload (F5) and Back/Forward history are checked;
data-only updates retain search and selection while the page stays open, and
leaving then returning resets them. The full-source cold proof first uses
`expectDaemonPlacementSourceReady` in a separate setup Placement tab, then
opens the cold tab. A `daemon: null` response before a complete snapshot is
valid, and the first-ever load is not promised an immediate source.

[`daemonSource.ts`](../../../framework/frontend/e2e/daemonSource.ts) supplies
Binance's causal live-source proof. It reads real received frames and the
committed Placement projection, rejects unrelated or
replacement frames, and checks that the displayed change follows the delivered
fact. Its unit cases cover unchanged values, a foreign socket, whole-window
replacement, acknowledgements and an uncommitted projection. It does not
synthesize traffic or inspect hidden root DOM.

The accessibility check runs in chat's existing `a11y.spec.ts`: one h1, the
shared owner and catalogued breadcrumb, named ordinary links and current page,
table naming, and keyboard navigation and Placement controls. Daemon e2e follow
the existing Light theme and desktop-width convention; narrow layouts and both
themes remain product and shared-mechanism review concerns.

`watchDaemonCronFrames(page: Page)` returns
`{ frames: DaemonPageFrame[], stop(): void }`. Arm it before the first navigation
or socket on that `Page`, and call `stop()` in `finally`. It retains the full raw
JSON only for `data.page === 'hilos_daemon_cron'` and types `page_response` or
`table_window`. `DaemonPageFrame`, declared in `framework/frontend/e2e/daemon.ts`,
has `{ type: 'page_response' | 'table_window'; raw: string }`;
`DaemonMismatchFrame` remains an alias of it. `watchDaemonMismatchFrames(page)`
keeps the same page-specific boundary for `hilos_daemon_env_mismatch`, including
recovery answers, so privacy assertions can inspect every matching received frame.

`expectClusterDaemonCronSchedule(expect, page, frames, { agentId, ruleName })`
accepts the spec's `Expect`, a `Page`, `readonly DaemonPageFrame[]` and
`{ agentId: string; ruleName: string }`, and returns `Promise<void>`.
It polls the captured answers: `cronReported` comes from
`data.payload.data.cronReported`; the initial rows come from
`data.payload.windows.hilosDaemonCron.rows`, replaced by `data.rows` on later
`table_window` frames whose `data.tableKey` is `hilosDaemonCron`. It reads each
row's `slots.rule` and requires `cronReported === true`, the requested `agentId`
and rule `name`, numeric `nextRunAt`, `idleReason === null` and no daemon rows
(`agentId === null`). It then checks the real visible row/card and executor with
`shownByTestId`, and zero loading, waiting, silent, reported-empty and
`hilos-daemon-cron-not-leader` elements. Both Cron functions are exported from
`framework/frontend/e2e/index.ts`.

The carrier checks `{ agentId: 'hilos_log_store', ruleName: 'hilos_log_rotation' }` and
attaches the captured Cron frames to its report. A reported empty roster cannot
satisfy this case, though it remains valid for other nodes. Do not add a daemon
rule to produce a notice or wait for the rotation to fire or for an exact time;
the positive notice and reserved-room cases stay in the core and SDK unit tests.

**A node report and a cron run are brought to now, never waited for.** A Daemon
page draws what its node agent last reported, and the node agent reports a
change only after its change throttle, behind the master's own windows for the
connection count and the process roster; a cron row moves only when its rule
fires. A spec waits out none of it: it calls the commands of
[Daemon node report and cron firing commands](../cli/commands.md#daemon-node-report-and-cron-firing-commands),
and the stand keeps the production timers. There are two kinds of wait, and the
cause decides which:

- **The master already knows the cause** — the page is subscribed, a restart was
  answered, a rule was fired. Call `reportDaemonNode()` once, then wait for the
  frame or the screen it brings in the ordinary `expect` budget. It lives in
  `framework/frontend/scripts/daemonMoments.mjs`, is exported from the toolbox
  index, and returns `{ nodeId, reportedAt }`.
- **The cause reaches the master by itself** — a browser opened or closed, a
  restart clicked on a page, a worker stopped or created — so one report may
  leave before the master knows of it. Call
  `reportDaemonNodeUntil(expect, check)` from `daemonReport.ts`: it repeats
  "report, then check" through `poll` of the `expect` it is given, in that
  expect's timeout — the project's, or the one `expect.configure({ timeout })`
  gave it — and lets the last failed check out. `toPass()` is not the form: it
  reads neither timeout and repeats until the test's own deadline. `check` reads
  what has already been delivered — frames, a journal, the screen — and waits by
  itself no longer than the collector's fan-out window. A refused command is not
  repeated: it comes out at once.

Inside the toolbox, `reportDaemonNodeWhileFalse(predicate)` is the second kind
for a helper that is handed a waiter instead of `expect`. `readMonopolisticWorker`
wraps its Workers-window waits in it, so it brings the report by itself, and so
does `readRestartWorker` through it. Binance's `withStatusAgentLoss`
(`tests/e2e/helpers/status.ts`) holds both kinds: the agent's loss reaches the
master by itself, so `run` is handed `untilReported(check)`, that repeat under
the helper's paused `expect`, whose timeout ends within the pause window; once
the restart is answered the helper sends one report for the waits on recovery.

`fireCronRule('<owner>/<rule>')`, in the same module and imported from it
directly (the toolbox index does not export it), runs one rule once now by its
ordinary path — `daemon/<rule>` for a rule of the daemon,
`<agentId>/<rule>` for an agent's — and returns `{ firedAt }`, the `lastRunAt`
its row then carries; its expression stays unchanged. When natural firing of
that rule could change what the spec is checking, use `pauseCronRule` before
`fireCronRule` and cancel its returned id with `await cancelCronPause(pauseId)`
in `finally`. Both helpers are in `daemonMoments.mjs`; the pause suppresses only
natural checks, so the explicit test fire still runs. The pause is local to the
current rule instance and does not alter the expression or run history. Each
report calculates `nextRunAt` from the expression at its report time, so it may
advance as time passes without a run. Follow the command contract in
[cron schedule pause commands](../cli/commands.md#pausing-a-cron-schedule-while-its-test-checks-the-rule).

After the report confirms `lastRunAt === firedAt`, compare the displayed pair
with the row in one current delivered frame. `expectDaemonCronMoments` reads both
cells together using independent browser `Intl`; if that row's frame changes
during the DOM read, it discards the observation and repeats within the same
delivery timeout. Use the current pair on each check because `nextRunAt` may
advance without a run. In the Binance zone spec, pass the selected display zone
(`browserZone` for Automatic) and `{ timeOnly: true }`: both moments are checked
by hour and minute, while component tests cover the full calendar labels. The
spec also keeps the original `lastRunAt` and every row field other than
`nextRunAt` fixed, plus its document, table, row, connection, applied window and
no-new-request checks.

Collect a test failure and a cleanup failure so cancellation cannot hide the
original assertion failure. The Binance Cron zone spec uses this shape around
its fire, report, and zone checks:

```ts
const failures: unknown[] = []
const { pauseId } = await pauseCronRule(expireKey)
try {
  const { firedAt } = await fireCronRule(expireKey)
  await reportDaemonNodeUntil(expect, () => {
    expect(
      readDaemonCronMoment(socket.frames, expireKey)?.rule.lastRunAt,
    ).toBe(firedAt)
  })
  await expectDaemonCronMoments(
    expect,
    page,
    socket.frames,
    expireKey,
    browserZone,
    { timeOnly: true },
  )
} catch (error) {
  failures.push(error)
} finally {
  try {
    await cancelCronPause(pauseId)
  } catch (error) {
    failures.push(error)
  }
}
if (failures.length === 1) throw failures[0]
if (failures.length > 1) {
  throw new AggregateError(
    failures,
    'Cron reader time zone and pause cleanup failed',
    { cause: failures[0] },
  )
}
```

This preserves a lone failure as-is and reports both failures together, with the
test failure as `cause`. Keep observer disposal and socket closure in their own
`finally` blocks, as the full spec does.

On a cluster, name the node. The report and firing helpers and
`readMonopolisticWorker` take `{ host }`, because the command asks the node
whose socket it reaches, and the cluster runner's default `COMMAND_HOST` is a
follower's container. So the cluster checks take the host of the node they read
— `host`, or `hosts` by node id for `expectClusterOverview` and
`expectClusterEnvironmentMismatch` — and a silent member is not asked. Without a
host they bring no report and only wait: for one the caller has brought already,
or for the node's own. The options no running spec passes yet, kept for the
parked scenario, carry a `SCAFFOLD` note.

### Theme and Appearance — choices, rows and the built first frame

`theme.ts` owns the independent test vocabulary (`ThemeTestPosition`,
`ThemeTestPick`, `THEME_TEST_POSITIONS`, `THEME_TEST_LABELS`, `THEME_TEST_ICONS`),
the browser keys (`THEME_PICK_STORAGE_KEY`, `THEME_SETTINGS_STORAGE_KEY`) and
the expected painted frames (`LIGHT_FRAME`, `DARK_FRAME`). Expected values do
not come from the production theme resolver.

`emulateSystemTheme(page, mode)` sets that tab's system preference without
changing its pick. `chooseHeaderTheme(expect, page, pick)` and
`chooseProfileTheme(expect, page, pick)` drive the real controls, including
`null` for reset, and wait for the closed surface, end of busy and returned
focus. `openProfileTheme(expect, page)` opens the live profile window.
`expectThemeMode` checks the effective palette; `expectThemeHeader` and
`expectProfileTheme` check the displayed position, default badge and reset
command separately. Those checks take `expect` and `page` first; the profile
check preserves a window the caller already opened.

`expectThemeFirstFrame(expect, page, path, mode)` uses `openFirstFrame` to
block application bundles and check both the HTML attribute and painted body.
It removes only its own route handler. The spec sets media explicitly and
uses a fresh context with project URL and HTTPS options for System dark. After
a probe, ordinary UI work starts with the shared `gotoPage`. Theme-choice
scenarios use real controls, without an init script that would impose the pick
again on reload.

`appearance.ts` owns `APPEARANCE_PATH`, `THEME_SWITCHING_KEY`,
`THEME_DEFAULT_KEY` and `appearanceSettingValue(page, key)`, which reads the
visible table or card projection. `expectAppearanceRows(expect, page,
switchingEnabled = true, defaultTheme = 'system')` checks both live values,
catalog defaults, editor count and accessible table name.
`openAppearanceSetting(expect, page, key)` opens an editor; the spec changes
its fields. `saveAppearanceSwitching(expect, page, enabled)` and
`saveAppearanceDefaultTheme(expect, page, position)` save that already edited
window and wait for its close, success toast, live row and returned focus.
Navigation, registration, scenario order, independent sessions and cleanup
remain in the demo specs.

`themeFrames.ts` owns `watchThemeFrames(page)`, registered before navigation.
It retains every `handshake_response` and `hilos_theme_settings` payload and raw
text, including malformed relevant frames. The cluster theme spec checks whole
intervals after each action, with a separate person's real Light round trip as
the account-isolation boundary; cached settings alone do not prove delivery.

### The Analytics section — names and checks

`analytics.ts` owns `ANALYTICS_WRITER_TIMEOUT_MS`, the frame observer `watchAnalyticsFrames`
(called before the page signs in), the row reader `readAnalyticsActionRows` (the
no-value sign reads as a null page name, as the frames carry it) and the cell
locator `analyticsSessionLink`. `expectAnalyticsDashboardCard` checks the way
in. The shared Overview check always requires tiles and has no body `kind` or
empty mode. Its signature is
`expectAnalyticsOverview(expect: Expect, page: Page, kit: 'legacy' | 'vue' | 'react' | 'angular' = 'legacy'): Promise<void>`:
Vue, React and Angular expect Overview, Browser sessions and People tabs plus
count links; legacy checks the breadcrumb and tiles without a shared head.
`expectAnalyticsSectionHead(expect: Expect, page: Page, current: 'overview' | 'sessions' | 'people', kit: 'vue' | 'react' | 'angular' = 'vue'): Promise<void>`
and
`expectAnalyticsPeopleAccessibility(expect: Expect, page: Page, direction: 'ascending' | 'descending', kit: 'vue' | 'react' | 'angular' = 'vue'): Promise<void>`
select the right tab row and People table; React and Angular callers pass their
kit explicitly, while Vue callers retain the default. The section head expects
the Browser sessions tab in all three kits.
`expectAnalyticsSessionListAccessibility(expect: Expect, page: Page, direction: 'ascending' | 'descending', kit: 'vue' | 'react' | 'angular' = 'vue'): Promise<void>`
checks the Browser sessions list the same way: the section head, one `h1` that
names the list's table through `aria-labelledby` with no table title of its own,
and the `aria-sort` and accessible name of Last seen. The PMD (Vue) and
preferans (Angular) Overview specs arm `watchSocketFrames` before setup and
bookmark the log before opening Analytics from the dashboard; the
solar-system-model (React) spec arms one `watchDeliveryFrames` instead,
bookmarks its received packets the same way and reads the list's requests from
that same log.
`readAnalyticsOverviewSnapshot`
reads one complete reply from that opening's frames;
`expectAnalyticsOverviewTiles` compares all three counts and their captured
period/zone labels with an independent `Intl` oracle. Missing or malformed
snapshots fail instead of becoming zero counts.
The `expectAnalytics…`,
`expectNoAnalytics…` and `expectOtherAnalyticsSession` checks cover the person
page and the session page. `expectAnalyticsActionsOnPage` checks that every
action row shown for the session stands on the same named page; the real
sign-ins on the main page expect `main` for every row. Every check takes the
spec's `expect` first, then its `Page`.

For ordinary People/session preconditions, `createTestUser` creates a verified
fixture account through `test:user:create` and binds the already connected
browser session through `test:session:bind`, without an auth flow.
`createTestAdmin` uses that preparation and grants admin; `grantAdminToSelf`
uses `admin:create` for the current browser session. These setup helpers do
not perform a real UI sign-in or promise its action journal.
The three demos' `analytics-browser-session.spec.ts` carriers instead create
both the person and administrator accounts with `seedTestUser`, then perform a
real sign-in. PMD and Preferans use the standard sign-in entry: `openSignIn` /
`login` on the main page. Solar's public header has no sign-in control, so its
spec opens the form in place on `/hilos` with `openSignInOnPage` and then calls
`login`.

`waitAnalyticsBrowserSession(expect, sendCommand, userId, expectedAction?, journals = [sendCommand])`
waits for the journal writer and returns
`{ browserSessionId: number | null, actionNames: readonly string[] }`.
Before each probe of a round it sends `test:analytics:rotate` to every request
function in `journals` at once, so the writer loads what each node's journal
holds now and the wait is the ordinary `expect` limit, not a rotation period.
A refused rotation rejects the wait with the server's text. What remains is the
journal's outbox, which hands its batch of events to the journal about once a
second.
It waits for a non-null session and, only when `expectedAction` is supplied,
for that action in `actionNames`. The session-action specs wait for
`AUTH_ACTION_LOGIN` (`'hilos_login'`) for both actors before following the
person's admin card into Analytics. Ordinary People/session preconditions
can use the same waiter without `expectedAction`.

Solar's React `analytics.spec.ts` uses the shared Overview helper with the
`'react'` kit, keeps the snapshot oracle and carries the count links — each
opens Browser sessions on its own period, and Period, Reset, F5, Back and
Forward keep the addressed period — the list-to-session journey with its return
by the crumb, Signed in to Person to session, People-to-Person navigation,
direct entry, F5, browser history and return to People, plus Period changes and
Reset over the captured context, and a viewer's Hidden list values with only
public session links. Its existing `analytics-browser-session.spec.ts` keeps the
User-to-Person-to-session privacy and action-order regression and checks the
session head with `expectAnalyticsBrowserSessionHead`, F5 and the crumb back to
the list. The Analytics accessibility group stays in Tasks' `a11y.spec.ts`; it
covers the count links' accessible names and Enter on a period, and the
assembled list/People/Person/session outlet, including keyboard navigation and table
semantics; its keyboard path starts at the list, with the table name and the
Last seen sort, then People and Person, the session by Enter and the return by
its crumb.
Preferans' Angular `analytics.spec.ts` uses the shared helpers with the `'angular'`
kit. It checks that each overview count opens Browser sessions on its period,
and that Period, Reset, F5, Back, Forward and an unknown route tail preserve the
addressed period; it also covers a real session from the list and
People-to-Person navigation with the captured Today context. Its separate
`analytics-browser-session.spec.ts` checks the real sign-in session's privacy
and action order, the shared session head, F5 and return to the list by its
crumb. The PMD Vue, Solar React and Preferans Angular Analytics journeys keep
their existing scenario coverage. The separate Analytics accessibility groups
remain in Chat, Binance, Tasks and Polls. Polls retains its Angular group in
`demo/polls/tests/e2e/tests/a11y.spec.ts`, which checks count-link names and Enter
on a period, the Browser sessions table name and sort, keyboard navigation to a
session and return by its crumb, plus `aria-current` and focus on the People return.

The cluster proof also uses `cluster.ts`: `readClusterBrowserStand` validates
the harness roles and address/log maps, `newClusterMasterPage` sets the master
cookie before opening a page, and `setClusterMaster` selects future sockets.
`clusterCommandChannel` addresses a member, while `expectClusterLeaderUnchanged`
checks a fresh follower view against the original leader and term.
`watchClusterMasterUpgrades` checks every Chromium `/ws` handshake for status
101 and the selected master's actual upstream address.
`expectClusterAnalyticsAgents` polls POLICY inspect for the writer and replays
fresh daemon logs for all live Analytics agents, clearing stopped agents, dead
workers and earlier daemon lives. Missing or incomplete journals fail the proof.
It first addresses the reader through the existing read-only browser-session
probe, since that agent starts on demand; this probe creates no account or
session. The live cluster scenario creates fixture accounts for the person
and administrator through `seedTestUser` / `test:user:create`, performs real
UI sign-ins on the selected nodes and waits for both actors' `hilos_login`
with `waitAnalyticsBrowserSession`, passing as `journals` a command channel
for every node in `stand.nodeLogs` — the follower alone would rotate its own
journal, while the sessions sit in the journals of the nodes that served the
logins.
The latter three checks take the spec's `expect` first. Common readiness uses
`probeStatic`, `probeWebSocketUpgrade` and `waitUntilReady`; demo wrappers keep
their own setup order. Scenario order, navigation and context cleanup stay in
the cluster spec.

### The languages and countries section — rows and checks

`i18n.ts` owns `i18nNameRow` and `i18nLocaleRow`, a names or locales row found
through its stable control, and the checks the three kits meet alike:
`expectDefaultLanguageHeader`, `expectNoOtherLanguageNames`,
`expectCountryPageHead` (a country's shared head, trail and Overview · Names
row on either tab) and `expectDeletedCountryCard` /
`expectDeletedCountryCardReloaded` (a deleted country's tab as the live 404,
with the deletion line and, after a reload, without it), each taking the
spec's `expect` first, then its `Page`; the tests, their order and navigation
stay in the demo spec. Beside them, `settleRemovedRow` (`table.ts`) waits a
removed row out through the table's Apply gate, and `countSentFrames`
(`socket.ts`) counts the frames a page sends that carry a text, armed before the
socket opens.
`expectI18nOverview(expect, page, overview)` and `watchI18nOverview(page)`
live in `framework/frontend/e2e/i18nOverview.ts` and are exported from
`framework/frontend/e2e/index.ts`. The first compares both tiles with one
wire summary; the second collects summaries from the `page_response` of the
`hilos_i18n` root. The overview specs of Binance, File Gallery Manager and
Preferans call them:
`demo/binance-btc-tracker/tests/e2e/tests/i18n-overview.spec.ts`,
`demo/file-gallery-manager/tests/e2e/tests/i18n-overview.spec.ts` and
`demo/preferans/tests/e2e/tests/i18n-overview.spec.ts`.

### The Change Log section — names and checks

`changeLog.ts` owns `CHANGE_LOG_PATH`, `CHANGE_LOG_FEED_PATH`,
`CHANGE_LOG_TABLES_PATH`, `CHANGE_LOG_NOISY_PATH`, `CHANGE_LOG_LEAD`,
`CHANGE_LOG_TABLES_LEAD` and `CHANGE_LOG_BLOCK_ACTION`; the path constants and
exported checks are re-exported from `framework/frontend/e2e/index.ts`.
`chooseChangeLogFeedTable` takes no `expect` and chooses by text, since option numbers belong to each demo.
`expectChangeLogOverview`, `expectChangeLogFeed`, `expectTrackedTablesPage`,
`expectChangeLogReference` and `expectChangeLogNoisyColumns` check their own
page bodies. `expectChangeLog…`, `expectRegistrationReceiptFacts` and
`expectTracked…` take the spec's `expect` first, then its `Page`.
`expectChangeLogSectionHead(expect, page, currentPage)` checks the adopted
owner in all three kits, its four section links, current page and absence of
child cards. The owner-page checks exercise the shared head in Vue Chat,
React Tasks and Angular Concert Tickets, while preserving the exact legacy heading
fallback for consumers that have not adopted the shared head.
`expectChangeLogReceiptWriter(expect, page, actorLabel, action, agentId)` checks
the exact Who, Action and Agent values in the receipt's first attribution `dl`.
It returns the complete visible header with whitespace normalized, including
When and Source, for comparison across masters.
`expectTrackedTableOverview(expect, page, table, since?)`,
`expectTrackedTableRecord(expect, page, table, recordKey, since?)` and
`expectTrackedTableWhole(expect, page, table, since?)` accept optional final
`since?: number` values and forward them to the table-owner head check. Tasks
passes fixture value `110` and Concert Tickets `1`, the migration each journals
`hilos_user` from; the defaults remain `hilos_user: 153` and
`bot: 153`. The table-head helper keeps its legacy heading fallback for consumers
without the shared head and retains its existing legacy layout checks.
The shared row reader is `tableRowKeyByText(expect, page, text)` in `table.ts`;
chat keeps a wrapper. The shared receipt checks remain common across kits.
Chat's section-head, overview-tile, direct-entry, F5 and Back scenarios cover
Vue adoption; Tasks' and Concert Tickets' table specs check their shared heads through
the same owner-page checks.

### The Moderation section — names and checks

[`moderation.ts`](../../../framework/frontend/e2e/moderation.ts) owns
`ModerationKindDeclaration` — what a kind declares as its Overview and Rules
pages show it: `kind`, `title`, `owner`, `modelLabel`, `model`, `profile`,
`reasons` and `defaultRules` — and `FRAMEWORK_MODERATION_KINDS`, the
framework's `hilos_profile_name` and `hilos_profile_photo` with the moderation
profile defaults they answer to. A project's own kinds, and which rules of its
own a kind holds, stay in the demo spec. The declaration, the constant and
every function are re-exported from `framework/frontend/e2e/index.ts`.

The drivers write on the open Rules page and take the closed dialog as the
acknowledgement. `addModerationRule(expect, page, declared, rule): Promise<number>`
saves through Add and returns the new row's id, as its Edit opener names it;
`editModerationRule(expect, page, declared, id, rule)` saves through Edit. Both
read only `declared.title`, for the dialog's name, and sweep standing toasts
first. `deleteModerationRule(expect, page, id, rule)` deletes the row `id`
through the Delete dialog, which names the held `rule`, and waits for the row
to leave without its data. `deleteModerationOwnRules(expect, page, rules)`
deletes only the reserved exact texts and passes over one that is absent: a row
is matched by its complete DOM text, and the rule the Delete dialog holds by
its `title` attribute. A spec reserves the texts before it opens Add with
`reserveModerationRules(expect, page, cleanup, declared, rules)`, which defers
through the test's `cleanup` fixture a visit to the kind's Rules and that
deletion — every text the test may save, a forged or drafted one included.

`expectModerationKindHead(expect, page, declared, current)` checks the owner's
one heading, the title, owner and key, the trail Hilos › Moderation › title
and, through `expectModerationKindTabs(expect, page, kind, current)`, the two
link tabs Overview and Rules with their addresses and the current one;
`current` is `'overview' | 'rules'`. `expectModerationKindOverview(expect, page, declared)`
reads the model, profile and reasons and finds nothing to write with;
`expectModerationRulesInForce(expect: Expect, page: Page, declared:
ModerationKindDeclaration, ownRuleCount: number)` reads the Overview's count
as `<declared.defaultRules.length> built-in + <ownRuleCount> of your own`,
including zero values. On Rules,
`expectModerationBuiltInRules(expect: Expect, page: Page, declared:
ModerationKindDeclaration)` checks the empty own-rules table, the generic
`Built-in rules` heading and the exact built-in list; it does not require the
former title-bearing permanent caption.
`expectModerationOwnRules(expect: Expect, page: Page, declared:
ModerationKindDeclaration, rules: readonly string[])` checks the exact built-in
list alongside the own rows, plus the server's count, search, Add and row
openers; a fresh window has no placeholders.
`expectModerationBuiltInRulesAfterDelete(expect: Expect, page: Page, declared:
ModerationKindDeclaration)` checks that built-ins remain visible after the own
data leaves, the count is `0 – 0 of 0`, and no Apply is pending; data-free
departure placeholders may remain. Each of these helpers returns
`Promise<void>`.
`expectModerationRulesOpening(expect, log, declared)` reads a `DeliveryFrameLog`
instead of a page: a cold Rules subscription answers in one `page_response`
with the head, the tabs and both windows, and no action or `table_viewport`
request is sent; `declared` is `{ kind, ownRules, defaultRules }`.

The other frame checks take the `DeliveryFrameLog` and the length `log.sent`
had before the step, and read only what was sent since.
`expectModerationSortReply(expect, log, sentBefore, declared, direction): Promise<{ answeredOnce(): Promise<void> }>`
waits for the first `table_window` answering the click on the Rule header,
then counts exactly one `table_viewport` for the kind's rules, sorted by rule
in `direction` (`'asc' | 'desc'`), and one answer to it; it is called after the
rows have settled, because an ascending order equal to the insertion order
shows nothing in the DOM. A second answer may still be on its way then, so the
spec calls the returned `answeredOnce()` after the next sort's rows have
settled: it waits until a later window request of the kind's rules is answered
— on the one socket that answer comes after any answer to the first — and
counts exactly one answer to the first.

The two write echoes count the actions of the rules family
`hilos_moderation_rule_*` sent since `sentBefore`; an action outside the
family, such as the toast stack reporting its reading, is not counted.
`expectModerationUpdateEcho(expect, log, sentBefore, declared, saved)` finds
exactly one action of the family, the update of `saved.id` with `saved.rule`
and `saved.expectedRule`, answered once with its `action_success`, and no
window or page request on the one connection.
`expectModerationDeleteEcho(expect, log, sentBefore, declared, removed)` finds
exactly one action of the family, the delete of `removed.id` with
`removed.expectedRule`, answered once with its `action_success`; it does not
read window or page requests — the removal echo does.
`expectModerationRemovalEcho(expect, log, sentBefore, id, own)` reads exactly
one `row_removed` for `id`, tagged `own` only for the tab that deleted it, and
no window or page request on the one connection.

The accessibility steps take a framework kind's key and find its title in
`FRAMEWORK_MODERATION_KINDS`. `expectModerationPagesAccessibility(expect, page, kind)`
starts on the opened `/hilos/moderation` and reaches Overview and Rules by
keyboard. `expectModerationAddDialogAccessibility(expect, page, kind, rule)`
ends with the one save that creates `rule`, the row on which
`expectModerationRowDialogsAccessibility(expect, page, kind, rule)` then opens
Edit and Delete. `expectModerationViewerAccessibility(expect, viewer, kind, rule): Promise<() => number[]>`
takes a page of a view-mode context before its first navigation and opens Rules
itself, because the write frames are counted from the socket's birth; it
returns a reader of the add, update and delete frames sent so far. The view-mode
switch and the viewer's context stay with the caller.

Every function takes the spec's `expect` first, then its `Page` — the frame
checks their log and the count `log.sent` had before the step. The tests, their
order and navigation stay in the demo spec.

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

Whether an element fits is asked through three checks, not bookmarks. Each
reads the element once, with the same one pixel of slack, refuses an element
that is not visible, and refuses with what it found:

- `fitsInScrollport(element)` — the element is not cut off by the window or by
  any ancestor that scrolls or clips it. Scroll it into view first.
- `fitsWithoutOverflow(element)` — the element is not wider than itself: its
  own content does not run out of it sideways.
- `fitsWithoutSidewaysScroll(element)` — nothing from the element up to the
  page scrolls or cuts sideways: the element itself, every ancestor whose
  `overflow-x` is not `visible`, and the page's scrolling root. An ancestor that
  hides or clips its overflow counts, since it cuts what it would scroll. The
  refusal names the first offender by its tag and, when it has one, its
  `data-id`, with both widths.

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

A spec opens a page through **`gotoPage(expect, page, path)`** from the shared
toolbox, which waits for the subscription's answer — the answer, not a good
answer, since a page closed to a guest answers with a refusal and the specs that
walk into one are asserting exactly that. Pass `PAGE_REFUSED` (or `PAGE_READY`) as the fourth
argument only when the spec is about which answer came. **Never call Playwright's
`goto` directly**; `E2E-PAGE-GOTO`
(`framework/frontend/codestyle/e2eGoto.ts`) reports every direct call outside the
`framework/frontend/e2e/page.ts` that owns the wrappers.

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

While that answer is in flight, a still copy of the departing page occupies its
place without `data-id` and outside the accessibility tree. Test-id and role
locators cannot mistake it for the live page; waiting on `hilos-page-state`
remains the way to know the new page has settled.

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
mark to be gone after it (`markDocument` / `expectSelfReload` in
`framework/frontend/e2e/page.ts`). A mark is indifferent to order: it is equally true
if the reload has already come and gone, where an event subscription armed a moment too late waits
out the whole test ceiling. A replaced document says only that the reload finished, so
a spec that then asserts what is on screen adds `expectPageReady` — and one that needs
a DIFFERENT address navigates there afterwards, once the reload has landed and there
is nothing left to collide with.

Waiting for the frozen surface to disappear is NOT a substitute, and was tried: the
stub goes on the frame that arrives, and the reload follows it.

## A retried test makes the step red, and its cause must be traced

A test that fails and then passes on an automatic retry makes its step `FAILED`
in the repository runner. Playwright's retry count stays unchanged, and its
reporter only supplies evidence: `hilos-unstable: <N> (<spec:line>, ...)`.
The shared PHP runner computes the effective exit code before dependencies can
start. A successful command with retry evidence becomes `rc=1`; a command's own
nonzero code is preserved. The ledger reads `<id> rc=1 unstable=<N>` in the first
case, and the summary keeps `=== unstable: ... ===` with step and test names.
A clean step adds neither the field nor the section. Cluster timeout retries
report the same grammar, with names such as `cluster/scenario-46`.

**Trace a named flicker's origin.** Name the test and find out how long it has
been flickering. A spec that flickered before the branch existed may have an
older cause and a ticket of its own. Report that history and do not repair a
foreign spec inside the current ticket. History explains attribution; it does
not make a red full run green.

The full run owns the verdict. A narrow green re-run on the same HEAD, including
five observations with `--tests=... --repeat=5`, is diagnostic evidence and does
not replace the full red result. The command and validation rules live in
[testing.md](../testing.md#narrow-runs-collect-evidence).

### Quarantine — a static tag and one attempt after main

Park an already identified flake with a static Playwright details tag tied to
its existing F record, keeping its body, assertions, original reason and date:

```ts
test('the scenario', { tag: '@quarantine:F-023' }, async ({ page }) => {
  // The original scenario and assertions stay here.
})
```

For a parameterized test, tag only the affected variant at declaration time:
`test(name, viewer ? {} : { tag: '@quarantine:F-019' }, fn)`. The viewer remains
in main. A runtime `test.fixme(!viewer)` cannot supply the static plan; replace
it with that details argument. Do not use `test.fail()` to mask a refusal.

A test whose failure is its own test timeout (`Test timeout of … exceeded`,
status `timedOut`) is not quarantined: quarantine would spend its whole cap, and
for a cluster phase the host setup as well, on every full run. Switch it off
instead with a static `test.fixme(title, body)` under the same `FIXME(F-NNN, …)`
comment, keeping its F record; when the cluster host prepares its phase, also
list the phase in `FLAKY_SKIP` of `framework/docker/cluster/browser.py`. An
`expect`, locator or navigation timeout inside the test is an ordinary failure
and may be quarantined.

The shared entry collects the original selectors and project filters first,
then runs main with its configured retries. Once every selected main phase
finishes, even when main is red, it runs each selected quarantined test once,
with no retries. A quarantine pass requires a real browser pass and successful
host checks. A browser failure or timeout stays failed; a skipped, interrupted
or missing test is reported as not run. These observations do not change main's
rc or its retry-to-red rule. The report and isolated artifacts are documented
in [testing.md](../testing.md#playwright-quarantine).

Keep the tag and the F record until removal is explicitly authorized; one green
attempt does not heal a flake. Moving a test into quarantine does not authorize
changing its selectors, waits, assertions or timeout. Explicit diagnostic
repeats exercise tagged tests through the native retry path, outside quarantine
statistics; see [narrow runs](../testing.md#narrow-runs-collect-evidence).

## Filling inputs — keyboard, not `fill`

For ordinary value entry, call **`await typeInto(field, value)`** from the shared
toolbox. It already clears with `fill('')` and types with
`pressSequentially(value, { delay: 10 })`; use the call instead of repeating
those steps. Per-key events reach the view's reactivity, where a bare
`fill(value)` can leave a stale or empty submit payload.

Keep operations that need different keyboard behavior:

- A standalone clear stays `await field.fill('')`; it needs no
  `typeInto(field, '')` call.
- Native date segments use `enterCalendarDate(expect, field, date)` from the
  toolbox (`moments.ts`); segment navigation is part of that operation.
- A disabled-input probe sends keys to the disabled field and checks that its
  value did not change; clearing first would change the operation being tested.
- Appending to an existing prefix keeps that prefix and places the caret before
  typing the suffix. Chat's `attachment.spec.ts` exercises both this case and
  the disabled-input probe across a reconnect.

For a submit button, call **`await clickSubmit(expect, button)`**, then explicitly
wait for that action's success or refusal in the calling scenario. The helper in
`input.ts` retries only preparation — scroll into view, assert visible and
enabled, focus — before one click. It does not wait for a server reply, a form's
completion or subscription data. The caller waits for the form to close, the
button to re-enable or the action's inline refusal, as appropriate to that flow,
before asserting the received result.

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

Each demo config passes its initial factor as `metadata.hilosTimeoutScale` to
the local test entry's `withAdaptiveTimeouts` fixture. The automatic fixture is
test-scoped and has no browser or page dependency. It samples host pressure
immediately during fixture setup, before `beforeEach`, then every two seconds.
Its narrow adapter supports Playwright 1.64.1. It checks the public
`FullConfig.version` first, then validates the timeout-manager shape before
reading pressure, registering its finish callback or changing a timeout. An
unsupported version throws `hilos adaptive timeouts: unsupported Playwright
version "<actual>"; supported: 1.64.1`; an unexpected internal shape throws
`hilos adaptive timeouts: incompatible Playwright 1.64.1 timeout internals`.
Either error fails fixture setup, so the run is red and the test body does not
start. There is no warning or fallback. This adapter is the only production
access to Playwright's timeout internals.

During an active test or `beforeEach`, the sampler can only raise the native
overall cap in Playwright's `defaultSlot`, using the greatest factor reached in
that attempt (capped at 4). It preserves the slot's elapsed time and start, and
reschedules its live deadline only when that overall slot is active. If a timed
fixture owns the current slot, including a lazy worker fixture, the adapter
updates the saved overall cap without changing that fixture's timeout, deadline
or elapsed time; Playwright uses the new cap when it returns to `defaultSlot`.
The adapter does not use the public `TestInfo.setTimeout` for sampler writes.
The controller scales from the attempt's baseline, preserves `slow()` and
explicit timeout adjustments without repeatedly accumulating an extension, and
never lowers the cap. A timeout of zero stays disabled.

The finish callback stops the sampler before cleanup; the fixture's `finally`
also stops it on every exit. Writes are limited to the active test and
`beforeEach`, so `afterEach`, `afterAll`, teardown and worker-scoped budgets are
not adapted. Cleanup uses Playwright's normal `afterHooksSlot`, initially capped
at `max(project.timeout, testInfo.timeout)` with elapsed time zero. A higher cap
reached earlier can therefore raise cleanup's starting budget, but sampling
does not grow it after cleanup starts. This is not a fixed 30-second cleanup
budget. `expect`, action and navigation caps remain unchanged.

Only a confirmed increase to the overall cap emits one
`hilos-timeout-extended: <JSON>` line with test context, host measurements, the
previous and new caps, the factor and its reason. This includes a saved overall
cap updated while a separate timed fixture is active. Rejected writes, cleanup,
ordinary samples, non-increases and falling pressure stay quiet.

`HILOS_E2E_TIMEOUT_SCALE` is a **pin made by hand** — someone debugging a step, a
CI that knows its own box. The demo compose files only forward it into the e2e
runner, and one graph step sets it: `binance-btc-tracker-cluster-e2e` pins both
factors at 2, because it runs beside the demo e2e steps (the owner's decision,
2026-10-25; `../testing.md`, "The full run"). A pinned value is a **floor**, not the
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
nothing but a hand and that one cluster browser step sets it, memory may raise it
above the pin, and the loadavg term steps aside whenever it is set.

### A test longer than the cap declares its own

A test whose own work runs past the base cap — three letters in a row — says so
itself: `test.slow()` when a flat triple fits, or a named factor
over `test.info().timeout` when it does not (`SIGN_IN_LINK_TIMEOUT_FACTOR` in
`demo/polls/tests/e2e/tests/auth.spec.ts` is the sample), with the measured
duration and the reason in a comment. A wait for a period to pass is not such
work: a command brings the moment to now
([Time](#time--a-command-brings-the-moment-to-now)).

Do not lean on the host factor as a budget: it is 1.0 on an idle box, and a test
that passes only because the box was loaded goes red on the day the box is quiet
(P-379). Never raise a floor under the factor — in the runner or in a config — to
cure one test.

### A test that ran out of its cap — 15 seconds more, only if it was already near it

A test that fails with `Test timeout of … exceeded` on every attempt gets **15
seconds more** (the owner's rule, 2026-10-21) when all three hold:

1. **It was already near its cap.** In the base's green runs it passes using two
   thirds of its cap or more; each step log prints every test's duration.
2. **It only needed the time.** Re-run alone on the branch (`--tests=`) with the
   15 seconds added, it passes.
3. **Its steps are not slower.** On the branch it takes no longer than its base
   duration plus the steps the branch added to this test.

Declare the extra in the test itself, as the section above does: a named constant
added to `test.info().timeout`, with the base duration and the branch duration in
the comment.

When one of the three fails, the cap is not the problem, and raising it hides the
problem:

- **1 fails** — the test had room and lost it: something on the branch got slower
  or stuck. HIL-1565 held every answer to a page subscription for one delivery
  window; each navigation grew from 0.19 to 1.30 seconds, whole suites ran twice
  as long, and tests near their cap went red. A longer cap would have hidden it.
- **2 fails** — what the test waits for never arrives. More time only makes the red
  slower.
- **3 fails** — the same steps run slower on the branch than on the base. That
  slowdown is the finding.

The rule is about the test's cap. An `expect`, action or navigation timeout is a
wait for one state, not a budget, and is not raised this way.

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

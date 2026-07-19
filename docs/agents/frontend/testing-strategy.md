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

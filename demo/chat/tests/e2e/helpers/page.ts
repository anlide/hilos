import { expect, type Page } from '@playwright/test'

// The one way a spec opens a page. `page.goto` on its own only waits for the
// document; the page behind it is a live subscription, and its answer — the
// payload or a refusal — arrives one round trip later. A spec that navigated and
// asserted straight away was racing that round trip: it passed while the DOM
// query outran the answer, and failed the moment it did not, which reads as a
// flaky element rather than as the timing it is. Waiting on the routed outlet's
// own state (HilosView / router.pageLoading, published as `hilos-page-state`)
// removes the guess: `ready` means the server has answered and the page on
// screen is the one that stays.
//
// Bare `page.goto` is refused by the E2E-PAGE-GOTO checker
// (framework/frontend/codestyle/e2eGoto.ts) everywhere but this file, which owns
// the wrappers.

/** The marker element the routed outlet keeps in the DOM at every state. */
const PAGE_STATE = 'hilos-page-state'

/**
 * Either settled state. `gotoPage` waits for the answer, not for a good answer:
 * a page closed to a guest answers with a refusal, and the specs that walk into
 * one on purpose are asserting exactly that. A spec that cares which answer came
 * says so with {@link expectPageReady} or {@link expectPageRefused}.
 */
const SETTLED = /^(ready|error)$/

/**
 * Wait until the routed outlet reports the state named.
 *
 * @param page The Playwright page.
 * @param state The settled state to wait for: `ready` or `error`.
 */
async function expectPageState(page: Page, state: string): Promise<void> {
  await expect(page.getByTestId(PAGE_STATE)).toHaveAttribute(
    'data-state',
    state,
  )
}

/**
 * The settled states a navigation can end in, for a spec that cares which one it
 * got. Passing neither is the common case: wait for the answer, then let the
 * spec's own assertions say what it expected to find.
 */
export const PAGE_READY = 'ready'

/** The other settled state: the server refused the subscription. */
export const PAGE_REFUSED = 'error'

/** Either settled state, as {@link gotoPage} requires when given no preference. */
export type PageOutcome = typeof PAGE_READY | typeof PAGE_REFUSED

/**
 * Wait until the routed outlet has settled on the page it is showing.
 *
 * @param page The Playwright page.
 */
export async function expectPageReady(page: Page): Promise<void> {
  await expectPageState(page, PAGE_READY)
}

/**
 * Wait until the routed outlet has settled on a subscription error, for a spec
 * asserting that a page is refused rather than shown.
 *
 * @param page The Playwright page.
 */
export async function expectPageRefused(page: Page): Promise<void> {
  await expectPageState(page, PAGE_REFUSED)
}

/**
 * Open a page and wait for its subscription to answer.
 *
 * The answer, not a good answer: a page closed to a guest answers with a
 * refusal, and the specs that walk into one on purpose are asserting exactly
 * that. Name the outcome only when the spec is about which answer came — a
 * refusal it means to pin, or a page it wants reported as refused rather than as
 * a missing element.
 *
 * @param page The Playwright page.
 * @param path Path to open, as the address bar would hold it.
 * @param expected The settled state to require, or undefined for either.
 */
export async function gotoPage(
  page: Page,
  path: string,
  expected?: PageOutcome,
): Promise<void> {
  await page.goto(path)
  await expect(page.getByTestId(PAGE_STATE)).toHaveAttribute(
    'data-state',
    expected ?? SETTLED,
  )
}

/**
 * The maintenance surface the shell raises over every route while frozen.
 */
const MAINTENANCE = 'maintenance'

/**
 * Open a url while the node is under protected mode and wait for the stub.
 *
 * The freeze is the other navigation {@link gotoPage} cannot serve: the shell
 * replaces the routed outlet with the maintenance surface, so the
 * `hilos-page-state` marker that wrapper waits on is not in the DOM at all and
 * the wait would time out on every call. What settles instead is the stub, which
 * is painted from the welcome frame before any subscription — which is exactly
 * what makes a cold load worth asserting on: the route, and so the surface type,
 * is known before the socket answers.
 *
 * @param page The Playwright page.
 * @param path Path to open, as the address bar would hold it.
 */
export async function gotoMaintenance(page: Page, path: string): Promise<void> {
  await page.goto(path)
  await expect(page.getByTestId(MAINTENANCE)).toBeVisible()
}

/**
 * Open an auth return route and wait for its relay screen to be on screen.
 *
 * A return route — `/auth/magic`, and `/auth/callback` when it is entered cold —
 * is the one navigation {@link gotoPage} cannot serve: the app swaps `HilosView`
 * out for the relay (App.vue), so the `hilos-page-state` marker that wrapper waits
 * on is not in the DOM at all and the wait would time out on every call. What
 * settles instead is the relay's own root, so this waits for that.
 *
 * An OAuth trip does not come back this way any more (HIL-633): the provider
 * returns the WINDOW the trip opened, and that window couriers the return to the
 * page that started it and closes. Playwright sees it as a page of its own; the
 * page under test never navigates.
 *
 * The wait deliberately stops there, at "the screen is up". What the relay does
 * next — hold, sign in, give up — is the behavior a spec calling this is about,
 * and a wrapper that waited for one of those outcomes would decide it in advance.
 *
 * @param page The Playwright page.
 * @param pathWithQuery Path and query of the return route, as the link carries it.
 * @param relayId The `data-id` of the relay screen that owns the route.
 */
export async function gotoAuthReturn(
  page: Page,
  pathWithQuery: string,
  relayId: string,
): Promise<void> {
  await page.goto(pathWithQuery)
  await expect(page.getByTestId(relayId)).toBeAttached()
}

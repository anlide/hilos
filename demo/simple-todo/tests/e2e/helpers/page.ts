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
 * Wait until the routed outlet has settled on the page it is showing.
 *
 * @param page The Playwright page.
 */
export async function expectPageReady(page: Page): Promise<void> {
  await expectPageState(page, 'ready')
}

/**
 * Wait until the routed outlet has settled on a subscription error, for a spec
 * asserting that a page is refused rather than shown.
 *
 * @param page The Playwright page.
 */
export async function expectPageRefused(page: Page): Promise<void> {
  await expectPageState(page, 'error')
}

/**
 * Open a page and wait for its subscription to answer.
 *
 * @param page The Playwright page.
 * @param path Path to open, as the address bar would hold it.
 */
export async function gotoPage(page: Page, path: string): Promise<void> {
  await page.goto(path)
  await expectPageReady(page)
}

/**
 * Open a page the server is expected to refuse, and wait for that refusal. The
 * spec then asserts which refusal it is — the status, the code, the copy.
 *
 * @param page The Playwright page.
 * @param path Path to open, as the address bar would hold it.
 */
export async function gotoRefusedPage(page: Page, path: string): Promise<void> {
  await page.goto(path)
  await expectPageRefused(page)
}

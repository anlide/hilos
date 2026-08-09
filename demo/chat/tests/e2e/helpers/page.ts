import { expect, type Page } from '@playwright/test'

// Waiting on the routed outlet's own state instead of on whichever element a
// page happens to render. A page is held back until its subscription answers
// (HilosView / router.pageLoading), so `ready` means the server has answered and
// the page on screen is the one that stays — an assertion made before that
// raced the round trip and passed only while the answer was slower than the DOM
// query.

/** The marker element the routed outlet keeps in the DOM at every state. */
const PAGE_STATE = 'hilos-page-state'

/**
 * Wait until the routed outlet has settled on the page it is showing.
 *
 * @param page The Playwright page.
 */
export async function expectPageReady(page: Page): Promise<void> {
  await expect(page.getByTestId(PAGE_STATE)).toHaveAttribute(
    'data-state',
    'ready',
  )
}

/**
 * Wait until the routed outlet has settled on a subscription error, for a spec
 * asserting that a page is refused rather than shown.
 *
 * @param page The Playwright page.
 */
export async function expectPageRefused(page: Page): Promise<void> {
  await expect(page.getByTestId(PAGE_STATE)).toHaveAttribute(
    'data-state',
    'error',
  )
}

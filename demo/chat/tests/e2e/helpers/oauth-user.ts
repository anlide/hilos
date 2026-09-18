import { expect, type Page } from '@playwright/test'
import type { StandOAuthAccount } from './oauth'
import { clickSubmit } from './session'

// stub-oauth-user: the PERSON at the provider's window (HIL-923). The provider itself is
// the stand gateway's resident and knows only its own world (helpers/oauth.ts); everything
// a human does at the consent screen is here, and it is a spec that gives the orders —
// wait for the window, pick this account, confirm, refuse, or walk away.
//
// He lives in the spec's set rather than in the gateway, and that is the whole reason this
// file exists apart. Waiting for a window to appear, pressing a button in it and closing it
// can only be done by whoever is in the browser; a server-side half of that person could drive
// the screen only through a script on the page, and then the button would be pressed by the
// page rather than by a person — which is precisely what an emulator must not fake.
//
// New habits of the person are added HERE, never to the gateway. The gateway gains a habit
// only when the PROVIDER gains one.

/** Test id of the consent screen, which is how the person knows the provider's window is up. */
const CONSENT = 'oauth-consent'

/** Test id prefix of one account's choice; the account's id follows it. */
const ACCOUNT_PREFIX = 'oauth-account-'

/** Test id of the button that grants access. */
const CONFIRM = 'oauth-confirm'

/** Test id of the button that refuses access. */
const DENY = 'oauth-deny'

/**
 * Wait for the provider's window to open and show its consent screen.
 *
 * The window is a real popup at a foreign address, opened because the product sent the
 * browser there — not a surface of the product with another name.
 *
 * @param page The product's page, which is what opens the window.
 * @returns The provider's window, ready to be acted on.
 */
export async function waitForProviderWindow(page: Page): Promise<Page> {
  const providerWindow = await page.waitForEvent('popup')
  await expect(providerWindow.getByTestId(CONSENT)).toBeVisible()

  return providerWindow
}

/**
 * Pick one account on the consent screen.
 *
 * @param providerWindow The provider's window.
 * @param account The account to sign in as, as `declareOAuthAccount` returned it.
 */
export async function chooseAccount(
  providerWindow: Page,
  account: StandOAuthAccount,
): Promise<void> {
  await providerWindow.getByTestId(`${ACCOUNT_PREFIX}${account.subject}`).check()
}

/**
 * Grant the client the access it asked for.
 *
 * @param providerWindow The provider's window.
 */
export async function confirmConsent(providerWindow: Page): Promise<void> {
  await clickSubmit(providerWindow.getByTestId(CONFIRM))
}

/**
 * Refuse the client the access it asked for; the provider sends the browser back saying so.
 *
 * @param providerWindow The provider's window.
 */
export async function denyConsent(providerWindow: Page): Promise<void> {
  await clickSubmit(providerWindow.getByTestId(DENY))
}

/**
 * Walk away: close the window without answering.
 *
 * Nothing is sent, so the provider sees no call at all and the client is never told
 * anything — which is the point of being able to do it.
 *
 * @param providerWindow The provider's window.
 */
export async function abandonConsent(providerWindow: Page): Promise<void> {
  await providerWindow.close()
}

/**
 * The nine cases out of ten, in one call: wait for the window, pick the account, confirm.
 *
 * @param page The product's page, which is what opens the window.
 * @param account The account to sign in as, as `declareOAuthAccount` returned it.
 */
export async function signInAs(
  page: Page,
  account: StandOAuthAccount,
): Promise<void> {
  const providerWindow = await waitForProviderWindow(page)
  await chooseAccount(providerWindow, account)
  await confirmConsent(providerWindow)
}

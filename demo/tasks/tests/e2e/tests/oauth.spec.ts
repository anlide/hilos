import { test, expect, type Page } from '@playwright/test'

import {
  abandonConsent,
  denyConsent,
  signInAs,
  signUpAs,
  waitForProviderWindow,
} from '../../../../../framework/frontend/e2e/index.js'
import { dictateGatewayBehavior } from '../../../../../framework/frontend/scripts/standGateway.mjs'
import {
  declareOAuthAccount,
  orderExpiredCode,
  type StandOAuthAccount,
  type StandOAuthProfile,
} from '../../../../../framework/frontend/scripts/standOAuth.mjs'
import { gotoPage } from '../helpers/page'
import { uniqueEmail } from '../helpers/session'

test('signs in by OAuth provider redirect and callback (HIL-281)', async ({
  page,
}) => {
  // OAuth login drives mechanism B end to end through the stand's provider emulator
  // (HIL-923, HIL-924): the click opens the provider's consent screen at its own
  // address, the person picks the declared account and confirms, and the provider
  // redirects that window back to /auth/callback with a real code. The monopolistic
  // OAuth agent (a separate, leader-pinned process) exchanges the code for a token
  // and reads userinfo over HTTPS, resolves the (provider, subject) to a fresh
  // account after consent, and the bound session rides the current-user fan-out
  // (HIL-161) that signs the visitor in. This is the login-path e2e that was missing while the
  // callback handed the pending op to the agent through a never-synced runtime
  // collection — the copy the agent read stayed empty.
  //
  // Quarantined as HIL-281 while the first sign-in after a fresh daemon hung. A
  // frame for an agent still starting now waits for it in the master (HIL-629),
  // and this flow was taken off quarantine on the first run against a fresh stand.
  //
  // The account is declared fresh — an id and an address no other test holds — so
  // the sign-in asks for consent before minting an account instead of resolving
  // somebody else's or landing in linking by address (HIL-282).
  const account = await declareOAuthAccount('google', { email: uniqueEmail() })

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await expect(page.getByTestId('profile-name')).toHaveCount(0)

  // The trip runs in a window of its own (HIL-633): the provider sends that window
  // back to /auth/callback, it couriers the code home and closes, and THIS page
  // does the exchange — so the sign-in lands in place, on the gated page it
  // started from, and the address never leaves it. The wait for that window starts
  // BEFORE the click: the click handler opens it synchronously, and a wait begun
  // after the click would miss the event.
  const signingIn = signUpAs(page, account)
  await page.getByTestId('auth-icon-oauth-google').click()
  await signingIn
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await expect(page.getByTestId('auth-surface')).toHaveCount(0)
  await expect(page.getByTestId('auth-oauth-wait')).toHaveCount(0)
  expect(new URL(page.url()).pathname).toBe('/profile')

  // The upgraded session persists: the gated profile now resolves in place, with the
  // OAuth identity bound to the freshly created account.
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await expect(page.getByTestId('auth-surface')).toHaveCount(0)
})

/** What a sign-in the provider failed says, whichever way it failed (oauthLogin.ts). */
const OAUTH_FAILED_MESSAGE = 'OAuth login failed. Please try again.'

/**
 * How long the provider keeps the connection open after its answer in the HIL-732
 * scenario. The number is the one HIL-732 was measured with — a TLS peer that hung up at
 * once gave green on the broken client five times out of five, one that held for 50 ms
 * gave red five out of five — and HIL-929 measured that the stand keeps the signature at
 * the same number. It stays far below the agent's request deadline (AbstractOAuthAgent
 * DEFAULT_HTTP_TIMEOUT_MS, 5 s). A hold that outlasts the deadline lives in the
 * neighboring HIL-1043 scenario.
 */
const PROVIDER_HOLD_MS = 50

/**
 * How long the provider holds the connection in the HIL-1043 scenario. It is above the
 * agent's request deadline (AbstractOAuthAgent DEFAULT_HTTP_TIMEOUT_MS, 5 s) — otherwise
 * the scenario would be green before the fix. That request deadline is the only product
 * clock on the way since HIL-1044: the exchange and the browser trip wait on facts.
 */
const PROVIDER_HOLD_PAST_DEADLINE_MS = 6000

/**
 * Start a sign-in with a provider from the gated profile and see the person through
 * the consent screen, the way the HIL-281 test does: the wait for the provider's window
 * starts BEFORE the click, because the click opens it synchronously.
 *
 * @param page The product's page.
 * @param account The account the person picks and confirms.
 * @param newAccount Whether this successful exchange must finish consent.
 */
async function signInThroughProvider(
  page: Page,
  account: StandOAuthAccount,
  newAccount = false,
): Promise<void> {
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()

  const signingIn = newAccount
    ? signUpAs(page, account)
    : signInAs(page, account)
  await page.getByTestId(`auth-icon-oauth-${account.profile}`).click()
  await signingIn
}

/**
 * The sign-in a provider failed ends as a refusal of the form (HIL-926): the sentence
 * on the form's refusal line and not in the notice, the field and the provider's icon
 * back so the person can try again the same way, and nobody signed in.
 *
 * @param page The product's page.
 * @param profile The provider the sign-in went to.
 * @param timeout How long the refusal may take to arrive, when not the default.
 */
async function expectProviderRefusal(
  page: Page,
  profile: StandOAuthProfile,
  timeout?: number,
): Promise<void> {
  await expect(page.getByTestId('auth-error')).toHaveText(
    OAUTH_FAILED_MESSAGE,
    { timeout },
  )
  await expect(page.getByTestId('auth-notice')).toHaveCount(0)
  await expect(page.getByTestId('auth-identifier')).toBeVisible()
  await expect(page.getByTestId(`auth-icon-oauth-${profile}`)).toBeVisible()
  await expect(page.getByTestId('profile-name')).toHaveCount(0)
}

// A provider that failed (HIL-926). The four failures are spread over both profiles and
// both calls the OAuth agent makes — the code exchange and the userinfo read — so one run
// walks both legs of the agent at both providers. The house's levers are keyed by the
// account's id (the derived key of HIL-923), which is why each failure is declared
// BEFORE the click, like the account itself. GitHub refuses a code with a 200 carrying
// the error inside, the sneakiest form a product can be handed.

test('refuses the sign-in on the form when the provider answers the exchange with a 500', async ({
  page,
}) => {
  const account = await declareOAuthAccount('google', { email: uniqueEmail() })
  await dictateGatewayBehavior('/oauth/google/token', account.subject, {
    status: 500,
  })

  await signInThroughProvider(page, account)

  await expectProviderRefusal(page, 'google')
})

test('refuses the sign-in when the provider stays silent past the wait', async ({
  page,
}) => {
  test.slow()
  // The refusal comes as the agent's verdict once its own request deadline passes
  // (AbstractOAuthAgent DEFAULT_HTTP_TIMEOUT_MS, 5 s) - since HIL-1044 the only product
  // clock on the way: the exchange and the browser trip wait on facts, so nothing else
  // could answer first. The 60 s silence is not derived from any product clock — it is
  // merely longer than the one there is.
  const account = await declareOAuthAccount('google', { email: uniqueEmail() })
  await dictateGatewayBehavior('/oauth/google/userinfo', account.subject, {
    delayMs: 60_000,
  })

  await signInThroughProvider(page, account)

  await expectProviderRefusal(page, 'google', 25_000)
})

test('refuses the sign-in when the provider cuts its answer halfway', async ({
  page,
}) => {
  const account = await declareOAuthAccount('github', { email: uniqueEmail() })
  await dictateGatewayBehavior('/oauth/github/userinfo', account.subject, {
    cut: true,
  })

  await signInThroughProvider(page, account)

  await expectProviderRefusal(page, 'github')
})

test('refuses the sign-in when the provider says the code has expired', async ({
  page,
}) => {
  const account = await declareOAuthAccount('github', { email: uniqueEmail() })
  await orderExpiredCode(account)

  await signInThroughProvider(page, account)

  await expectProviderRefusal(page, 'github')
})

test('returns quietly to the field when the person refuses at the provider', async ({
  page,
}) => {
  await declareOAuthAccount('github', { email: uniqueEmail() })
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()

  const providerWindow = waitForProviderWindow(page)
  await page.getByTestId('auth-icon-oauth-github').click()
  await denyConsent(await providerWindow)

  // A decision, not a failure: back to the field with nothing to say.
  await expect(page.getByTestId('auth-identifier')).toBeVisible()
  await expect(page.getByTestId('auth-error')).toHaveCount(0)
  await expect(page.getByTestId('auth-notice')).toHaveCount(0)
  await expect(page.getByTestId('profile-name')).toHaveCount(0)
})

test('returns quietly to the field when the person closes the provider window', async ({
  page,
}) => {
  await declareOAuthAccount('google', { email: uniqueEmail() })
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()

  const providerWindow = waitForProviderWindow(page)
  await page.getByTestId('auth-icon-oauth-google').click()
  await abandonConsent(await providerWindow)

  // Nothing is sent when a window is closed; the trip notices by asking whether the
  // window is still there (OAUTH_WINDOW_POLL_MS, every 500 ms), which the wait for the
  // field covers.
  await expect(page.getByTestId('auth-identifier')).toBeVisible()
  await expect(page.getByTestId('auth-error')).toHaveCount(0)
  await expect(page.getByTestId('auth-notice')).toHaveCount(0)
  await expect(page.getByTestId('profile-name')).toHaveCount(0)
})

// The regression of HIL-732, and the reason it lives on the stand. On TLS a socket is
// announced readable as soon as protocol bytes arrive, while the decrypted bytes of the
// answer are not there yet, so an empty read means "no data yet" and never "the peer is
// done" (framework/backend/API/AsyncHttpClient.php, processReceiving(), the comment above
// the buffer append). A client that takes the empty read for the end parses an empty
// buffer and the agent refuses the sign-in. A peer that hangs up at once hides the
// defect and a peer that lingers exposes it, hence the hold — on both calls the agent
// makes, the code exchange and the userinfo read, since each answer is a window of its
// own. Measured by HIL-929 with that reading put back into the client: red 10 runs out
// of 10, each with "empty response buffer" in the OAuth agent's log, against green 10
// out of 10 on the healthy client. With no hold the same mutation was red 6 runs out of
// 10 in one measurement and 9 out of 10 in the next: the hold is what makes the red
// certain. This scenario replaced the only fork of the unit suite
// (serveTlsResponseInChild() in framework/tests/Unit/AsyncHttpClientTest.php at
// c32457783).
test('signs in when the provider keeps the connection open after its answer (HIL-732)', async ({
  page,
}) => {
  const account = await declareOAuthAccount('google', { email: uniqueEmail() })
  await dictateGatewayBehavior('/oauth/google/token', account.subject, {
    holdMs: PROVIDER_HOLD_MS,
  })
  await dictateGatewayBehavior('/oauth/google/userinfo', account.subject, {
    holdMs: PROVIDER_HOLD_MS,
  })

  await signInThroughProvider(page, account, true)

  await expect(page.getByTestId('profile-name')).toBeVisible()
  await expect(page.getByTestId('auth-surface')).toHaveCount(0)
  await expect(page.getByTestId('auth-error')).toHaveCount(0)
})

/**
 * Unlike the HIL-732 neighbor, which holds 50 ms to expose an empty TLS read, this
 * hold outlasts the agent's request deadline and exposes waiting for close.
 */
test('signs in when the provider holds the connection past the request deadline (HIL-1043)', async ({
  page,
}) => {
  const account = await declareOAuthAccount('google', { email: uniqueEmail() })
  await dictateGatewayBehavior('/oauth/google/token', account.subject, {
    holdMs: PROVIDER_HOLD_PAST_DEADLINE_MS,
  })
  await dictateGatewayBehavior('/oauth/google/userinfo', account.subject, {
    holdMs: PROVIDER_HOLD_PAST_DEADLINE_MS,
  })

  await signInThroughProvider(page, account, true)

  await expect(page.getByTestId('profile-name')).toBeVisible()
  await expect(page.getByTestId('auth-surface')).toHaveCount(0)
  await expect(page.getByTestId('auth-error')).toHaveCount(0)
})

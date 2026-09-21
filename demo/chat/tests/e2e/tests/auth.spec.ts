import { test, expect, type Locator, type Page } from '@playwright/test'

import { watchHeight, watchTop } from '../../../../../framework/frontend/e2e/index.js'
import { setAdmin } from '../helpers/adminGrant'
import {
  mailsTo,
  readMagicLinkCode,
  readMagicLinkUrl,
  readRegisterCode,
  readResetCode,
} from '../helpers/mail'
import {
  clickSubmit,
  continueFromDone,
  enterIdentifierAndPassword,
  login,
  logout,
  nameFromEmail,
  PASSWORD,
  register,
  registerWithoutPassword,
  signUp,
  submitFirstPassword,
  submitRegistration,
  submitRegistrationCode,
  typeInto,
  uniqueEmail,
} from '../helpers/session'
import { expectPageRefused, gotoAuthReturn, gotoPage } from '../helpers/page'
import { uniquePhone, waitForSmsCode } from '../helpers/sms'
import { dictateGatewayBehavior } from '../helpers/gateway'
import {
  declareOAuthAccount,
  orderExpiredCode,
  type StandOAuthAccount,
  type StandOAuthProfile,
} from '../helpers/oauth'
import {
  abandonConsent,
  denyConsent,
  signInAs,
  waitForProviderWindow,
} from '../helpers/oauth-user'
import { setTelegramReachable, waitForTelegramCode } from '../helpers/telegram'

// Auth e2e umbrella (HIL-167): the email+password sign-in flow end to end through
// the live daemon and built frontend. It covers the surfaces that landed with the
// session≠user rework (HIL-360) and the auth stack (HIL-161…165, HIL-364):
//   - the AUTHENTICATED-guarded profile page 401s an anonymous subscribe and the
//     auth gate mounts the project sign-in surface IN PLACE (no redirect), then
//     resumes the preserved subscription off the session upgrade — no navigation;
//   - registering takes three steps (HIL-415/423): the address and its password,
//     the terms screen that actually holds the address and mails one code, and the
//     code that creates the account and signs the session in; logout reverts the
//     session to anonymous so the gated page re-gates;
//   - the surface is identifier-first (HIL-412/423): one field, and what it reveals
//     is the live lookup's answer — an address with no account never gets a sign-in
//     form to fail against, it gets the registration path;
//   - the anonymous visitor reads the chat but the composer gates sending behind
//     the same surface, opened as the auth-gate modal via the composer's Sign in button.
// Recovery's own e2e leg is HIL-426, which owns the new coverage of the reworked
// flows; this file is the existing coverage brought onto the new surface. The
// backend of each action is already covered by the Integration suite; this file
// is the UI-driven, cross-surface flow. The register/login/logout helpers are
// shared with the other specs that establish a session (helpers/session.ts).
//
// The code step is driven from the letter the daemon really delivered — read out
// of the file transport's artifact (helpers/mail.ts), the same shape as the hleb
// reference reading its catcher. Reservation expiry is not exercised here: the
// hold lasts 15 minutes, so its rollback stays an integration test.

/** Seconds in a minute, for reading the `m:ss` countdown as a number. */
const SECONDS_PER_MINUTE = 60

/** The passphrase a recovery saves, told apart from the one it replaces. */
const RECOVERED_PASSWORD = 'a whole new passphrase'

test('registers and signs back in through the gated profile surface, resuming it in place', async ({
  page,
}) => {
  const email = uniqueEmail()

  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  // Anonymous: no session user yet, so the chat composer prompts to sign in.
  await expect(page.getByTestId('message-signin')).toBeVisible()

  // The framework profile page is AUTHENTICATED-guarded: an anonymous subscribe
  // 401s and the gate mounts the sign-in surface in place of the profile view.
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await expect(page.getByTestId('profile-name')).toHaveCount(0)

  // Register: autoLoginAfterRegister (default) upgrades the live session, the
  // gate clears the 401, and the preserved profile subscription resumes with no
  // document reload and no route change.
  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })
  await register(page, email)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await expect(page.getByTestId('auth-surface')).toHaveCount(0)
  expect(new URL(page.url()).pathname).toBe('/profile')
  expect(fullLoads).toBe(0)

  // Logout reverts to anonymous; re-reaching the gated profile 401s again and the
  // surface mounts anew.
  await logout(page)
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await expect(page.getByTestId('profile-name')).toHaveCount(0)

  // Anonymous again: the gated profile re-mounts the surface (login mode by
  // default). Signing in with the credentials just registered resumes the same
  // preserved subscription in place — no navigation. Reaching the gated page
  // again was a full load on purpose (gotoPage), so the counter starts over
  // here and vouches for the sign-in the way it vouched for the registration.
  fullLoads = 0
  await login(page, email, PASSWORD)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await expect(page.getByTestId('auth-surface')).toHaveCount(0)
  expect(new URL(page.url()).pathname).toBe('/profile')
  expect(fullLoads).toBe(0)
})

test('signs in by OAuth provider redirect and callback (HIL-281)', async ({
  page,
}) => {
  // OAuth login drives mechanism B end to end through the stand's provider emulator
  // (HIL-923, HIL-924): the click opens the provider's consent screen at its own
  // address, the person picks the declared account and confirms, and the provider
  // redirects that window back to /auth/callback with a real code. The monopolistic
  // OAuth agent (a separate, leader-pinned process) exchanges the code for a token
  // and reads userinfo over HTTPS, resolves the (provider, subject) to a fresh
  // account, and the bound session rides the current-user fan-out (HIL-161) that
  // signs the visitor in. This is the login-path e2e that was missing while the
  // callback handed the pending op to the agent through a never-synced runtime
  // collection — the copy the agent read stayed empty.
  //
  // Quarantined as HIL-281 while the first sign-in after a fresh daemon hung. A
  // frame for an agent still starting now waits for it in the master (HIL-629),
  // and this flow was taken off quarantine on the first run against a fresh stand.
  //
  // The account is declared fresh — an id and an address no other test holds — so
  // the sign-in mints a new account instead of resolving somebody else's or landing
  // in linking by address (HIL-282).
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
  const signingIn = signInAs(page, account)
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
 * DEFAULT_HTTP_TIMEOUT_MS, 5 s): a hold that outlasts the deadline is another defect and
 * another scenario (HIL-1043).
 */
const PROVIDER_HOLD_MS = 50

/**
 * Start a sign-in with a provider from the gated profile and see the person through
 * the consent screen, the way the HIL-281 test does: the wait for the provider's window
 * starts BEFORE the click, because the click opens it synchronously.
 *
 * @param page The product's page.
 * @param account The account the person picks and confirms.
 */
async function signInThroughProvider(
  page: Page,
  account: StandOAuthAccount,
): Promise<void> {
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()

  const signingIn = signInAs(page, account)
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
  // (AbstractOAuthAgent DEFAULT_HTTP_TIMEOUT_MS, 5 s) — and, were that one longer, its
  // operation deadline (OAuthPendingLogin EXCHANGE_TTL_MS, 15 s) would still answer the
  // same refusal first. It is waited for longer than the trip's own deadline in the
  // browser (OAUTH_EXCHANGE_TIMEOUT_MS, 20 s): were the browser's clock to answer
  // instead, the text would read "OAuth login timed out…" and this assertion would fail
  // on the wrong sentence rather than on a timeout. The 60 s silence is not derived
  // from any product clock — it is merely longer than all of them.
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
  const account = await declareOAuthAccount('github', { email: uniqueEmail() })
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
  const account = await declareOAuthAccount('google', { email: uniqueEmail() })
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

  await signInThroughProvider(page, account)

  await expect(page.getByTestId('profile-name')).toBeVisible()
  await expect(page.getByTestId('auth-surface')).toHaveCount(0)
  await expect(page.getByTestId('auth-error')).toHaveCount(0)
})

test('answers a wrong password inline, and an unknown address with the registration path', async ({
  page,
}) => {
  const email = uniqueEmail()

  // Seed a known account, then log out so the sign-in path is exercised.
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await register(page, email)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await logout(page)

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()

  // A known address reveals its password field and the wrong one is refused with
  // the backend's own sentence, still gated. The room the sentence lands in was
  // taken before it existed (HIL-647), so nothing on the card moves: the slot
  // keeps its height, the field the person is about to correct keeps its place,
  // and so does the button they just pressed - the node the old block used to
  // shove down, because it stood between the two.
  await enterIdentifierAndPassword(page, email, 'a different password')
  const slotRoom = await watchHeight(page.getByTestId('auth-error-slot'))
  const passwordTop = await watchTop(page.getByTestId('auth-password'))
  const submitTop = await watchTop(page.getByTestId('auth-submit'))
  await clickSubmit(page.getByTestId('auth-submit'))
  await expect(page.getByTestId('auth-error')).toHaveText('Incorrect password')
  await slotRoom.unchanged()
  await passwordTop.unchanged()
  await submitTop.unchanged()
  await expect(page.getByTestId('profile-name')).toHaveCount(0)

  // An address with no account is never given a sign-in to fail: the lookup in
  // FRONT of the form answers first, so the same screen becomes the registration
  // (HIL-414) — which is why there is no "no account found" sentence any more. It
  // shows no password field either (HIL-825): a registration is asked for one
  // after the code, on the screen that creates the account.
  await typeInto(page.getByTestId('auth-identifier'), uniqueEmail())
  await expect(page.getByTestId('auth-heading')).toHaveText(
    'Create your account',
  )
  await expect(page.getByTestId('auth-password')).toHaveCount(0)
  await expect(page.getByTestId('auth-error')).toHaveCount(0)
  await expect(page.getByTestId('profile-name')).toHaveCount(0)
})

test('holds the refusal to one line on a narrow screen', async ({ page }) => {
  const email = uniqueEmail()

  // 375 is the narrowest screen the frontend is built for, and the card (24rem)
  // is wider than it: this is where a refusal used to take a second line, and
  // where the truncation has to keep it to one.
  await page.setViewportSize({ width: 375, height: 800 })

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await register(page, email)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await logout(page)

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()

  await enterIdentifierAndPassword(page, email, 'a different password')
  const slotRoom = await watchHeight(page.getByTestId('auth-error-slot'))
  const submitTop = await watchTop(page.getByTestId('auth-submit'))
  await clickSubmit(page.getByTestId('auth-submit'))
  await expect(page.getByTestId('auth-error')).toHaveText('Incorrect password')

  await slotRoom.unchanged()
  await submitTop.unchanged()
})

test('keeps the main button still while the field answers, on a narrow screen', async ({
  page,
}) => {
  const member = uniqueEmail()
  const free = uniqueEmail()

  // 375 is the narrowest screen the frontend is built for, and the one the owner
  // saw the form jump on. What must not move is the button under the field.
  await page.setViewportSize({ width: 375, height: 800 })

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await register(page, member)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await logout(page)

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()

  // A free address: the grey line under the field and nothing else.
  await typeInto(page.getByTestId('auth-identifier'), free)
  await expect(page.getByTestId('auth-identifier-hint')).toHaveText(
    'No account yet — this creates one.',
  )
  const submitTop = await watchTop(page.getByTestId('auth-submit'))

  // An account that signs in with a password: the reveal, which is the tallest
  // thing this slot ever holds and therefore what its room was measured on.
  await typeInto(page.getByTestId('auth-identifier'), member)
  await expect(page.getByTestId('auth-password')).toBeVisible()
  await submitTop.unchanged()

  // And back. The room was taken with the first character and never given up,
  // so no reply the lookup brings moves what sits under it (HIL-1008).
  await typeInto(page.getByTestId('auth-identifier'), free)
  await expect(page.getByTestId('auth-identifier-hint')).toHaveText(
    'No account yet — this creates one.',
  )
  await submitTop.unchanged()
})

test('gates sending behind the surface, and returns the identity line to anonymous on logout', async ({
  page,
}) => {
  const email = uniqueEmail()

  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')

  // Anonymous read: the live event stream renders without a session.
  await expect(page.getByTestId('events-scroll')).toBeVisible()

  // The composer is gated: the message input is disabled and the send control
  // becomes a Sign in button rather than sending.
  await expect(page.getByTestId('message-input')).toBeDisabled()
  await expect(page.getByTestId('message-signin')).toBeVisible()

  // The composer's Sign in button opens the same surface as the auth-gate modal (requireAuth),
  // in place over the live page.
  await page.getByTestId('message-signin').click()
  const modal = page.getByTestId('modal')
  await expect(modal).toBeVisible()
  await expect(modal.getByTestId('auth-surface')).toBeVisible()

  // Registering through the modal upgrades the session; the gate closes the modal
  // off the session upgrade and the composer un-gates in place.
  await register(page, email)
  await expect(modal).toBeHidden()
  await expect(page.getByTestId('message-input')).toBeEnabled()
  await expect(page.getByTestId('message-signin')).toHaveCount(0)
  await expect(page.getByTestId('self-user')).toHaveText(nameFromEmail(email))

  // The identity line's live transition (HIL-625). Logging out is one of the
  // four ways into the anonymous state named in the ticket — purge, expiry and
  // an anonymized restore are the others — and on the wire all four are the
  // same thing: a handshake response with no current user. This is the one of
  // them a browser can walk into, and it walks into it WITHOUT a navigation: the
  // session scope drops the user, and the line re-renders off that ref the same
  // way the shell drops the profile link. What the line must not do is keep the
  // "Signed in as" sentence with the name gone from it.
  await logout(page)

  await expect(page.getByTestId('self-anonymous')).toHaveText(
    'Browsing anonymously',
  )
  await expect(page.getByTestId('self-user')).toHaveCount(0)
})

test('holds the address on submit and creates the account only on the mailed code', async ({
  page,
}) => {
  const email = uniqueEmail()

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()

  // Accepting the terms reserves the address and mails one code — it registers
  // nobody and takes no password, so the page stays gated and the surface steps to
  // the code screen.
  await submitRegistration(page, email)
  await expect(page.getByTestId('auth-code')).toBeVisible()
  await expect(page.getByTestId('profile-name')).toHaveCount(0)

  const code = await readRegisterCode(email)
  // Any code but the delivered one; picked off it so the run can never guess right.
  const wrongCode = code === '000000' ? '111111' : '000000'

  // A wrong code is an inline error on the step the person is already on: no
  // account is left behind and nothing rolls back.
  await submitRegistrationCode(page, wrongCode)
  await expect(page.getByTestId('auth-error')).toBeVisible()
  await expect(page.getByTestId('auth-code')).toBeVisible()
  await expect(page.getByTestId('profile-name')).toHaveCount(0)

  // The delivered code proves the address and hands over the password screen; the
  // password saved there is what creates the account and signs the session in
  // (HIL-825) — and the surface says so instead of vanishing (HIL-422). The gate
  // holds the resume until that panel is acknowledged, which is why the profile is
  // still gated here and comes through on Continue.
  await submitRegistrationCode(page, code)
  await expect(page.getByTestId('auth-heading')).toHaveText('Choose a password')
  await expect(page.getByTestId('profile-name')).toHaveCount(0)
  await submitFirstPassword(page, PASSWORD)
  await expect(page.getByTestId('auth-continue')).toBeVisible()
  await expect(page.getByTestId('profile-name')).toHaveCount(0)

  await continueFromDone(page)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await expect(page.getByTestId('auth-surface')).toHaveCount(0)
})

test('makes an account with no password, then signs it in by the mailed code', async ({
  page,
}) => {
  const email = uniqueEmail()

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()

  // Where the choice between a password and none lives since HIL-1008: not at the
  // address field, where both roads mail the same address and there is nothing to
  // choose between, but under Save password, once the address is proved.
  await registerWithoutPassword(page, email)
  await expect(page.getByTestId('profile-name')).toHaveText(nameFromEmail(email))
  await logout(page)

  // And what the account it made signs in by from then on. The link is its only
  // way in, so the lookup promotes it to the MAIN button instead of standing it
  // beside a password field that does not exist.
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await typeInto(page.getByTestId('auth-identifier'), email)
  await expect(page.getByTestId('auth-identifier-hint')).toHaveText(
    'This account has no password.',
  )
  await expect(page.getByTestId('auth-password')).toHaveCount(0)
  await clickSubmit(page.getByTestId('auth-icon-magic-link'))

  // The screen the person asked for does not change under them — it grows a
  // field (HIL-606). The link is still there to click; this spec is the person
  // who cannot, because their mail is open on another device.
  await expect(page.getByTestId('auth-heading')).toHaveText('Check your inbox')
  await expect(page.getByTestId('auth-link-sent')).toBeVisible()
  await expect(page.getByTestId('auth-code')).toBeVisible()

  await typeInto(page.getByTestId('auth-code'), await readMagicLinkCode(email))
  await clickSubmit(page.getByTestId('auth-submit'))
  await continueFromDone(page)
  await expect(page.getByTestId('profile-name')).toHaveText(nameFromEmail(email))

  // Two letters and no more: the code that proved the address, and the link that
  // signed it back in. Neither half of the second one bought a third.
  expect(await mailsTo(email)).toHaveLength(2)
})

test('signs a member in by the code, on an address that already has an account', async ({
  page,
}) => {
  // A DIFFERENT account from the case above, and made the ordinary way: the send
  // gate holds a second letter to one address for a minute, so a spec that asked
  // twice for the same one would be waiting on a letter nobody mailed.
  const email = uniqueEmail()

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await register(page, email)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await logout(page)

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await typeInto(page.getByTestId('auth-identifier'), email)
  await expect(page.getByTestId('auth-password')).toBeVisible()
  await page.getByTestId('auth-icon-magic-link').click()

  // An address that has an account is not held and shows no terms: the letter
  // goes out on the click.
  await expect(page.getByTestId('auth-heading')).toHaveText('Check your inbox')
  await expect(page.getByTestId('auth-code')).toBeVisible()

  await typeInto(page.getByTestId('auth-code'), await readMagicLinkCode(email))
  await clickSubmit(page.getByTestId('auth-submit'))
  await continueFromDone(page)
  await expect(page.getByTestId('profile-name')).toHaveText(nameFromEmail(email))
})

/**
 * The path and query of a sign-in link, as the address bar should hold it.
 *
 * The letter carries an absolute URL built from `HILOS_MAGIC_LINK_URL`, which is
 * the address a real deployment publishes and NOT the stand's own base — opening
 * it verbatim would leave the stand entirely. Only the path and query belong to
 * the click; the host is the deployment's business.
 *
 * @param url The sign-in URL exactly as the letter spells it.
 * @returns Its path and query, for `gotoAuthReturn`.
 */
function returnPath(url: string): string {
  const parsed = new URL(url)

  return `${parsed.pathname}${parsed.search}`
}

test('signs a member in by clicking the link in the letter, from a cold load', async ({
  page,
}) => {
  const email = uniqueEmail()

  // An account made the ordinary way first. The envelope stands where it still
  // stands after HIL-1008 — beside the password field of an account that has one,
  // as the way past that password — and a free address no longer offers it at all.
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await register(page, email)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await logout(page)

  // The other half of the letter (HIL-606 covers the code): this is the person
  // whose mail is open on the same device, who just clicks.
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await typeInto(page.getByTestId('auth-identifier'), email)
  await expect(page.getByTestId('auth-password')).toBeVisible()
  await page.getByTestId('auth-icon-magic-link').click()
  await expect(page.getByTestId('auth-link-sent')).toBeVisible()

  // The click itself: a full browser load of the return route, which is the
  // condition the defect needed (HIL-607). The relay mounts while the socket is
  // still opening, so the confirm has to wait for a connection that can carry it
  // rather than be dropped and reported as an unreachable server.
  await gotoAuthReturn(page, returnPath(await readMagicLinkUrl(email)), 'auth-magic')

  // Home, signed in: the relay navigates on success and the session it upgraded
  // is the one this tab is holding.
  await expect(page.getByTestId('nav-logout')).toBeVisible()
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('profile-name')).toHaveText(nameFromEmail(email))

  // Two letters and no more: the code the account was made with, and the link it
  // came back by. Whichever half of the second was used, the click bought no third.
  expect(await mailsTo(email)).toHaveLength(2)
})

test('turns a tampered sign-in link down on its own screen', async ({
  page,
}) => {
  const email = uniqueEmail()

  // An account first, for the same reason as the case above: the envelope is
  // offered beside a password, never on a free address (HIL-1008).
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await register(page, email)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await logout(page)

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await typeInto(page.getByTestId('auth-identifier'), email)
  await expect(page.getByTestId('auth-password')).toBeVisible()
  await page.getByTestId('auth-icon-magic-link').click()
  await expect(page.getByTestId('auth-link-sent')).toBeVisible()

  // A token nobody minted. The point is WHICH screen answers: a refusal that
  // reached the server looks like this, and a click that reached nobody used to
  // look exactly the same — which is what made the defect invisible.
  const tampered = new URL(await readMagicLinkUrl(email))
  tampered.searchParams.set('token', 'not-the-token-that-was-mailed')
  await gotoAuthReturn(page, returnPath(tampered.toString()), 'auth-magic')

  await expect(page.getByTestId('auth-magic-error')).toBeVisible()
  await expect(page.getByTestId('auth-magic-to-login')).toBeVisible()
  // Nothing was signed in, and the letter is still the only one.
  await expect(page.getByTestId('nav-logout')).toHaveCount(0)
  expect(await mailsTo(email)).toHaveLength(2)
})

test('says how the letter is going, to every tab of the browser and across a reload', async ({
  page,
  context,
}) => {
  const email = uniqueEmail()

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await submitRegistration(page, email)

  // The line the person watches instead of guessing. It arrives over the socket a
  // tick behind the screen, so waiting for its last state is also waiting for the
  // whole chain to have run: the command reported "queued", the mail queue reported
  // "sending", and the transport took the letter (HIL-826).
  // The code field is measured before the line settles and after: the line
  // takes room the screen held for it from the start, so neither its arrival
  // nor its changes move what is under it (HIL-977).
  const codeField = page.getByTestId('auth-code')
  await expect(codeField).toBeVisible()
  const codeTop = await watchTop(codeField)
  const sent = `Sent to ${email}`
  await expect(page.getByTestId('auth-send-progress')).toContainText(sent)
  await codeTop.unchanged()

  // The whole line sits behind a button in every state, not only on a refusal.
  await expect(page.getByTestId('auth-send-progress-details')).toBeVisible()

  // A reload keeps it, because the line belongs to the SESSION and not to the
  // socket: the tab that comes back is told what it is owed by its handshake, the
  // same frame that gives it the code screen back.
  await page.reload()
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await expect(page.getByTestId('auth-code')).toBeVisible()
  await expect(page.getByTestId('auth-send-progress')).toContainText(sent)

  // And a second tab of the same browser reads the same line, having sent nothing
  // and asked for nothing - which is the same addressing that keeps it away from
  // every OTHER browser.
  const second = await context.newPage()
  await gotoPage(second, '/')
  await expect(second.getByTestId('conn-state')).toHaveText('connected')
  await second.getByTestId('message-signin').click()
  await expect(second.getByTestId('auth-code')).toBeVisible()
  await expect(second.getByTestId('auth-send-progress')).toContainText(sent)

  // One letter for all of that: reading the line costs no send.
  expect(await mailsTo(email)).toHaveLength(1)
})

test('converges a second waiting tab onto the registration the first one confirms', async ({
  page,
  context,
}) => {
  const email = uniqueEmail()

  // Tab A holds the address from the gated profile.
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await submitRegistration(page, email)

  // Tab B opens the same session's surface and is ALREADY on the code screen,
  // naming the address it never typed (HIL-486): the unfinished step lives on the
  // server, so it is answered to every tab of the session. Nothing is submitted
  // here, so no second letter goes out either — both wait on the one in the inbox.
  const second = await context.newPage()
  await gotoPage(second, '/')
  await expect(second.getByTestId('conn-state')).toHaveText('connected')
  await second.getByTestId('message-signin').click()
  await expect(second.getByTestId('auth-code')).toBeVisible()
  await expect(second.getByTestId('auth-surface')).toContainText(email)
  expect(await mailsTo(email)).toHaveLength(1)

  // Tab A confirms. Tab B is not merely moved along: it really enters, resolving
  // the self user of the account tab A just created. It gets no panel of its own,
  // and that is the merged rule rather than a gap: the login rotates the session
  // token and drops every socket but the initiator's (HIL-582), the sentence lives
  // on the CONNECTION (HIL-422), and only the socket that trades the rotation
  // ticket inherits one (HIL-423). Tab B comes back on the new token signed in and
  // un-gated, which is exactly what it shows below.
  await submitRegistrationCode(page, await readRegisterCode(email))
  await submitFirstPassword(page, PASSWORD)
  await continueFromDone(page)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await expect(second.getByTestId('self-user')).toHaveText(nameFromEmail(email))
  await expect(second.getByTestId('modal')).toBeHidden()
  await expect(second.getByTestId('message-input')).toBeEnabled()
})

test('puts a held address back on its code step after a silent walk-away', async ({
  page,
}) => {
  const email = uniqueEmail()

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await submitRegistration(page, email)
  await readRegisterCode(email)

  // Leaving in SILENCE — closing the tab, coming back later — says nothing and
  // sends nothing, so the hold this browser took stands (HIL-608). This is the
  // half of the old "not that address?" that survived HIL-829: the other half,
  // pressing the way out, now frees the address instead (the case below).
  await gotoPage(page, '/profile')

  // And the return is answered with the code step of the letter already sent:
  // nothing was submitted and no second letter was mailed. Only THIS browser's
  // hold answers that way — another browser is offered the ways to register it.
  await expect(page.getByTestId('auth-code')).toBeVisible()
  await expect(page.getByTestId('auth-surface')).toContainText(email)
  await expect(page.getByTestId('auth-error')).toHaveCount(0)
  expect(await mailsTo(email)).toHaveLength(1)
})

test('gives the held address its own code back, instead of a second registration', async ({
  page,
}) => {
  const email = uniqueEmail()

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await submitRegistration(page, email)
  const code = await readRegisterCode(email)

  // The same silent return as the case above, carried through to the end: what
  // the returning browser is handed is its own unfinished registration, not the
  // offer to start one on an address it has already reserved (HIL-651). So the
  // code from the FIRST letter is the one this screen accepts, and no second
  // letter was ever mailed.
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-code')).toBeVisible()
  await expect(page.getByTestId('auth-password')).toHaveCount(0)

  await submitRegistrationCode(page, code)
  await submitFirstPassword(page, PASSWORD)
  await continueFromDone(page)
  await expect(page.getByTestId('profile-name')).toHaveText(nameFromEmail(email))
  expect(await mailsTo(email)).toHaveLength(1)
})

test('frees the address when the way out is pressed, and starts over on it', async ({
  page,
}) => {
  const email = uniqueEmail()

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await submitRegistration(page, email)
  await readRegisterCode(email)

  // Pressed, not walked away from: the person said this registration is not
  // wanted, so the hold goes with the wait (HIL-829). The control says as much,
  // at the foot of the card and in red.
  const wayOut = page.getByTestId('auth-cancel-registration')
  await expect(wayOut).toHaveText('Cancel registration')
  await wayOut.click()
  await expect(page.getByTestId('auth-identifier')).toBeVisible()

  // The proof the address is free again is what the lookup answers about it: a
  // free address it offers to register, where a held one would have offered the
  // standing code back. submitRegistration asserts that heading itself, and
  // lands on the code step of a SECOND registration.
  await submitRegistration(page, email)
  await expect(page.getByTestId('auth-code')).toBeVisible()

  // No second letter, and that is the price of the gate rather than a gap: the
  // cooldown lives on the ADDRESS and outlives the cancel (HIL-421), or the way
  // out would be a channel for mailing a stranger without limit. So the new
  // registration opens under the countdown, and the code already delivered is
  // the one it accepts.
  await expect(page.getByTestId('auth-resend-in')).toContainText(/\d+:\d{2}/)
  expect(await mailsTo(email)).toHaveLength(1)
})

test('gives the code back to a tab that returns to an address another tab of the browser just held', async ({
  page,
  context,
}) => {
  // A held address is reached via two tabs because every exit from the code
  // screen frees the address (HIL-829), which took away the old single-tab way
  // back. The screen itself is kept: when tab B returns to a field whose address
  // tab A just held, it is told its browser already holds a code.
  const email = uniqueEmail()

  // Tab A opens first so the session already exists when tab B arrives.
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()

  // Tab B opens the surface on the landing modal, standing on an empty field.
  const second = await context.newPage()
  await gotoPage(second, '/')
  await expect(second.getByTestId('conn-state')).toHaveText('connected')
  await second.getByTestId('message-signin').click()

  // Tab B must step off the field onto the terms screen BEFORE tab A holds the
  // address: typing an address that is ALREADY held immediately moves the
  // machine to the code step (applyDetection, HIL-651), bypassing held_identifier.
  // Continuing to terms is a purely local transition and sends nothing.
  await typeInto(second.getByTestId('auth-identifier'), email)
  await expect(second.getByTestId('auth-heading')).toHaveText('Create your account')
  await clickSubmit(second.getByTestId('auth-submit'))
  await expect(second.getByTestId('auth-heading')).toHaveText('Terms and privacy')

  // Tab A holds the address and sends the one code. Taking a hold broadcasts
  // nothing to the other tabs of the session: hilos_auth_converge only fires on
  // cancel, confirmed code, created account, or expiry (cancelRegistration(),
  // grantRegistrationToSession(), convergeRegistration() and
  // rollBackRegistrationWaiters() in AbstractSessionsLibraryAgent.php), so tab B
  // remains un-moved on its terms screen.
  await submitRegistration(page, email)
  const code = await readRegisterCode(email)

  // Tab B goes back to the identifier field. Returning from terms invokes
  // backToIdentifier() in authFlow.ts, which re-asks the lookup without
  // advancing the step, and screenKeyOf() draws the screen its answer names.
  // Because the address was held while B was away, the lookup answers pending —
  // rendering the held_identifier screen.
  await second.getByTestId('auth-restart').click()
  await expect(second.getByTestId('auth-heading')).toHaveText('You already have a code')
  await expect(second.getByTestId('auth-identifier')).toHaveValue(email)
  await expect(second.getByTestId('auth-password')).toHaveCount(0)
  await expect(second.getByTestId('auth-resume-code')).toHaveText('Enter the code')

  // The way back to the code screen sends nothing and requests no second code:
  // it resumes the held registration locally.
  await clickSubmit(second.getByTestId('auth-resume-code'))
  await expect(second.getByTestId('auth-code')).toBeVisible()
  await expect(second.getByTestId('auth-heading')).toHaveText('Confirm your email')

  // The code from the FIRST letter carries tab B through to completion.
  await submitRegistrationCode(second, code)
  await submitFirstPassword(second, PASSWORD)
  await continueFromDone(second)
  await expect(second.getByTestId('self-user')).toHaveText(nameFromEmail(email))

  // Exactly one letter for both tabs: no second code was mailed.
  expect(await mailsTo(email)).toHaveLength(1)
})

test('carries a proved registration to its password screen, in every tab, and leaves nothing behind if it is dropped', async ({
  page,
  context,
  browser,
}) => {
  // HIL-825, end to end. Three claims in one trip because they are one decision:
  // the code buys a screen and not an account, that screen belongs to the SESSION
  // and not to the tab that typed the code, and a registration abandoned on it
  // leaves neither an account nor a credential.
  const email = uniqueEmail()

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await submitRegistration(page, email)
  await submitRegistrationCode(page, await readRegisterCode(email))

  // The account is not made yet, which is the whole point: between the code and a
  // password there would stand an account with a proved address and no way in.
  await expect(page.getByTestId('auth-heading')).toHaveText('Choose a password')
  await expect(page.getByTestId('auth-new-password')).toBeVisible()
  await expect(page.getByTestId('profile-name')).toHaveCount(0)

  // The hidden login the password manager files the entry under. Absent, it saves
  // a password with no address against it, and its owner cannot use it later.
  await expect(page.getByTestId('auth-username')).toHaveValue(email)

  // A second tab of the same browser opens ON that screen, having typed nothing:
  // the proof is durable on the hold, so the handshake answers every tab of the
  // session with the step the registration really stands on.
  const second = await context.newPage()
  await gotoPage(second, '/')
  await expect(second.getByTestId('conn-state')).toHaveText('connected')
  await second.getByTestId('message-signin').click()
  await expect(second.getByTestId('auth-new-password')).toBeVisible()
  await expect(second.getByTestId('auth-code')).toHaveCount(0)

  // Dropped here, on purpose, with nothing saved. Asked from a browser that shares
  // none of this session's state, the address must read as free — no account was
  // made, and no credential was stored for one that was not.
  const stranger = await browser.newContext()
  const strangerPage = await stranger.newPage()
  await gotoPage(strangerPage, '/profile')
  await expect(strangerPage.getByTestId('auth-surface')).toBeVisible()
  await typeInto(strangerPage.getByTestId('auth-identifier'), email)
  await expect(strangerPage.getByTestId('auth-heading')).toHaveText(
    'Create your account',
  )
  await expect(strangerPage.getByTestId('auth-password')).toHaveCount(0)
  await stranger.close()
})

test('opens a fresh tab on the new-password step once the code is accepted', async ({
  page,
  context,
}) => {
  // HIL-648. The recovery leg's own e2e is HIL-426; this case is here because the
  // defect is here: a tab opened AFTER the code was accepted was drawing the code
  // screen again, a step its session had already passed.
  const email = uniqueEmail()

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await register(page, email)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await logout(page)

  // Tab A recovers as far as the accepted code: the key icon beside the password
  // is what starts it, and the mailed code buys the password screen.
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await typeInto(page.getByTestId('auth-identifier'), email)
  await expect(page.getByTestId('auth-password')).toBeVisible()
  await page.getByTestId('auth-recovery').click()
  await expect(page.getByTestId('auth-code')).toBeVisible()
  await typeInto(page.getByTestId('auth-code'), await readResetCode(email))
  await clickSubmit(page.getByTestId('auth-submit'))
  await expect(page.getByTestId('auth-new-password')).toBeVisible()

  // Tab B opens the same session's surface and lands where the SESSION stands -
  // on the new password, not back on the code it never typed. The grant belongs
  // to the session, so the step is answered to every tab of it.
  const second = await context.newPage()
  await gotoPage(second, '/')
  await expect(second.getByTestId('conn-state')).toHaveText('connected')
  await second.getByTestId('message-signin').click()
  await expect(second.getByTestId('auth-new-password')).toBeVisible()
  await expect(second.getByTestId('auth-code')).toHaveCount(0)

  // And it is a real screen rather than a drawing: the password typed in the tab
  // that proved nothing itself is accepted, on the grant its session holds. A
  // DIFFERENT passphrase, so a save that silently did nothing could not pass.
  await typeInto(second.getByTestId('auth-new-password'), RECOVERED_PASSWORD)
  await clickSubmit(second.getByTestId('auth-submit'))
  await expect(second.getByTestId('self-user')).toHaveText(nameFromEmail(email))
})

test('tells the tab that did not save the password that the flow succeeded', async ({
  page,
  context,
}) => {
  // HIL-649. The recovery leg's own e2e is HIL-426; this case is here because the
  // defect is here, and because the case above already stages the two tabs it needs.
  // Saving the password signs the session in, and a sign-in rotates the token and
  // drops every OTHER socket of that session (HIL-582). Tab A therefore came back on
  // the new cookie carrying nothing, the gate saw a session that was up and closed the
  // surface — so the screen the flow had just earned was taken away before it could be
  // read. The flow ended in that tab, and never said it had succeeded.
  const email = uniqueEmail()

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await register(page, email)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await logout(page)

  // Tab A gets as far as the password screen, exactly as the case above does.
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await typeInto(page.getByTestId('auth-identifier'), email)
  await expect(page.getByTestId('auth-password')).toBeVisible()
  await page.getByTestId('auth-recovery').click()
  await expect(page.getByTestId('auth-code')).toBeVisible()
  await typeInto(page.getByTestId('auth-code'), await readResetCode(email))
  await clickSubmit(page.getByTestId('auth-submit'))
  await expect(page.getByTestId('auth-new-password')).toBeVisible()

  // Tab B of the same browser is the one that saves it, and so the one that acts.
  const second = await context.newPage()
  await gotoPage(second, '/')
  await expect(second.getByTestId('conn-state')).toHaveText('connected')
  await second.getByTestId('message-signin').click()
  await expect(second.getByTestId('auth-new-password')).toBeVisible()
  await typeInto(second.getByTestId('auth-new-password'), RECOVERED_PASSWORD)
  await clickSubmit(second.getByTestId('auth-submit'))
  await expect(second.getByTestId('self-user')).toHaveText(nameFromEmail(email))

  // The case: tab A typed nothing and is told what happened anyway, rather than
  // being emptied. Nothing is clicked in it to get there.
  //
  // Waited for with toPass rather than a plain expect, for the same reason
  // session-rotation.spec.ts waits on the rotated cookie that way: what has to
  // happen first is a whole round trip of the browser's own - this tab's socket is
  // dropped by the sign-in, the tab reconnects, trades nothing, and only its new 101
  // carries the sentence. One expect timeout covers that on an idle box and does not
  // when the run shares the machine with a second demo lane.
  await expect(async () => {
    await expect(page.getByTestId('auth-heading')).toHaveText('Password changed')
  }).toPass()
  await expect(page.getByTestId('auth-continue')).toHaveText('Continue')

  // And read once is read everywhere (HIL-865): Continue in the tab that DID act
  // takes the panel off this one too. The truth is the row - clearSessionAck
  // clears the mark and republishes it to every live socket of the session - so
  // what closes the copy here is the cleared mark arriving, not the click.
  await continueFromDone(second)
  await expect(second.getByTestId('auth-surface')).toHaveCount(0)

  // toPass for the same reason as above: this is a whole trip through the server
  // and back down the other tab's socket, not a local unmount.
  await expect(async () => {
    await expect(page.getByTestId('auth-surface')).toHaveCount(0)
  }).toPass()
})

test('lowers the standing panel in the other tab when the session logs out', async ({
  page,
  context,
}) => {
  const email = uniqueEmail()

  // Tab A registers from the gated profile, so its surface stands IN PLACE rather
  // than in a modal and the shell around it stays reachable. Continue is
  // deliberately not pressed: the session is signed in and still owes the sentence.
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await submitRegistration(page, email)
  await submitRegistrationCode(page, await readRegisterCode(email))
  await submitFirstPassword(page, PASSWORD)
  await expect(page.getByTestId('auth-heading')).toHaveText(
    'Your account is ready',
  )

  // Tab B typed nothing and finished nothing. The mark belongs to the SESSION
  // (HIL-875), so it arrives on this tab's own handshake and the gate raises the
  // same panel here — which is what makes the next step a two-tab case at all.
  const second = await context.newPage()
  await gotoPage(second, '/')
  await expect(second.getByTestId('conn-state')).toHaveText('connected')
  await expect(second.getByTestId('auth-heading')).toHaveText(
    'Your account is ready',
  )
  await expect(second.getByTestId('auth-continue')).toBeVisible()

  // The case: the session ends with the panel still standing. What it announces is
  // about the account this session no longer has, so the logout takes it down; it
  // used to restate it instead, and tab B was left holding an announcement over an
  // anonymous shell, with nothing on screen that could answer it and no way out
  // but a reload.
  await logout(page)

  // toPass because this is a whole trip through the server and back down the other
  // tab's socket, as in the recovery case above, and not a local unmount.
  await expect(async () => {
    await expect(second.getByTestId('auth-surface')).toHaveCount(0)
  }).toPass()
  await expect(second.getByTestId('modal')).toBeHidden()
  await expect(second.getByTestId('nav-profile')).toHaveCount(0)
})

test('offers a countdown instead of a resend while the cooldown holds', async ({
  page,
}) => {
  const email = uniqueEmail()

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await submitRegistration(page, email)
  const code = await readRegisterCode(email)

  // The gate is the backend's and it is armed by the send itself (HIL-421/486), so
  // the screen cannot even offer a second letter yet: where the button would be,
  // it counts down to when one becomes possible.
  await expect(page.getByTestId('auth-resend-in')).toContainText(/\d+:\d{2}/)
  await expect(page.getByTestId('auth-resend')).toHaveCount(0)

  // And the first code is still the live one — nothing was minted behind the
  // countdown, so it still opens the account.
  await submitRegistrationCode(page, code)
  await submitFirstPassword(page, PASSWORD)
  await continueFromDone(page)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  expect(await mailsTo(email)).toHaveLength(1)
})

// Code channels (HIL-492). Delivery of a login code became a registry, so what is
// exercised here is what a registry is FOR: the same number reaches its account
// through whichever channel can carry it, and a channel that cannot costs the person
// nothing on the way to one that can.
//
// The Telegram leg goes through the stand's mock Gateway (helpers/telegram.ts) rather
// than around it: the daemon builds a real request, posts it, and the mock refuses one
// that carries no bearer token — so a transport quietly removed would fail here.
//
// Not covered, and deliberately: a project whose registry has no Telegram at all draws
// no icon row. That is a different build of the demo, not a state a spec can arrange,
// and the channel list is unit-tested instead (CodeChannelRegistryTest).

test('signs in with the code delivered over Telegram', async ({ page }) => {
  // No global reset: every state the mock holds is keyed by number, and the number
  // is unique per test, so these specs are isolated without reaching into a store
  // the other workers share.
  const phone = uniquePhone()

  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await page.getByTestId('message-signin').click()
  await typeInto(page.getByTestId('auth-identifier'), phone)

  // A number reveals its channels instead of a password: choosing one IS the send,
  // and there is no separate send button behind the icon. For a number with no
  // account the choice is only STORED — an account is never made by a click that
  // never showed the terms — so the terms screen is what sends.
  await clickSubmit(page.getByTestId('auth-channel-telegram'))
  await page.getByTestId('auth-consent-accept').check()
  await clickSubmit(page.getByTestId('auth-submit'))

  // The code screen opens on the agent's outcome signal, not on the click, and it
  // names the channel the code actually went over.
  await expect(page.getByTestId('auth-code')).toBeVisible()
  await expect(page.getByTestId('auth-delivered-channel')).toContainText(
    'Telegram',
  )

  await typeInto(page.getByTestId('auth-code'), await waitForTelegramCode(phone))
  await clickSubmit(page.getByTestId('auth-submit'))
  await continueFromDone(page)

  await expect(page.getByTestId('self-user')).toHaveText(phone)
})

test('leaves a number that is not on Telegram free to sign in by SMS', async ({
  page,
}) => {
  const phone = uniquePhone()
  await setTelegramReachable(phone, false)

  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await page.getByTestId('message-signin').click()
  await typeInto(page.getByTestId('auth-identifier'), phone)
  await clickSubmit(page.getByTestId('auth-channel-telegram'))
  await page.getByTestId('auth-consent-accept').check()
  await clickSubmit(page.getByTestId('auth-submit'))

  // The refusal says so where the send was made from, and the code screen never
  // opens: nothing was minted, so there is no code to enter.
  await expect(page.getByTestId('auth-error')).toBeVisible()
  await expect(page.getByTestId('auth-code')).toHaveCount(0)

  // Back at the field the refused channel is dimmed — it is dimmed about this
  // NUMBER, so it stays that way until the number is edited.
  await page.getByTestId('auth-restart').click()
  await expect(page.getByTestId('auth-channel-telegram')).toBeDisabled()

  // The whole point of probing before minting: SMS still has this number's first
  // code to give, because the refused channel spent no cooldown.
  await clickSubmit(page.getByTestId('auth-channel-sms'))
  await page.getByTestId('auth-consent-accept').check()
  await clickSubmit(page.getByTestId('auth-submit'))
  await expect(page.getByTestId('auth-code')).toBeVisible()
  await expect(page.getByTestId('auth-delivered-channel')).toContainText('SMS')

  await typeInto(page.getByTestId('auth-code'), await waitForSmsCode(phone))
  await clickSubmit(page.getByTestId('auth-submit'))
  await continueFromDone(page)

  await expect(page.getByTestId('self-user')).toHaveText(phone)
})

test('says the code could not be sent when the Telegram gateway fails the send', async ({
  page,
}) => {
  // The probe still passes and only the send is refused, so this is not the "number
  // is not on Telegram" case above: the refusal travels back through the transport
  // that delivers the code, as a 500 the daemon reads as a failed send (HIL-922).
  const phone = uniquePhone()
  await dictateGatewayBehavior('/telegram/sendVerificationMessage', phone, {
    status: 500,
  })

  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await page.getByTestId('message-signin').click()
  await typeInto(page.getByTestId('auth-identifier'), phone)
  await clickSubmit(page.getByTestId('auth-channel-telegram'))
  await page.getByTestId('auth-consent-accept').check()
  await clickSubmit(page.getByTestId('auth-submit'))

  await expect(page.getByTestId('auth-error')).toContainText(
    'Could not send the code',
  )
  await expect(page.getByTestId('auth-code')).toHaveCount(0)
})

test('comes back to the phone code screen after a reload, and finishes there', async ({
  page,
}) => {
  const phone = uniquePhone()

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await typeInto(page.getByTestId('auth-identifier'), phone)
  await clickSubmit(page.getByTestId('auth-channel-sms'))
  await page.getByTestId('auth-consent-accept').check()
  await clickSubmit(page.getByTestId('auth-submit'))

  await expect(page.getByTestId('auth-code')).toBeVisible()
  const code = await waitForSmsCode(phone)

  // The tab keeps nothing across a reload, so the step it comes back to is the one
  // the SERVER remembers: the code went out, so the session is still waiting on this
  // number and is given its screen back rather than an empty identifier field
  // (HIL-486).
  await page.reload()
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await expect(page.getByTestId('auth-code')).toBeVisible()
  await expect(page.getByTestId('auth-identifier')).toHaveCount(0)

  // Down to the channel: which one carried the code is part of what is remembered,
  // because the screen has to name it and the click that chose it is gone.
  await expect(page.getByTestId('auth-delivered-channel')).toContainText('SMS')
  await expect(page.getByTestId('auth-expires-in')).toContainText(/\d+:\d{2}/)

  // And it is the same registration: the code texted before the reload is the one
  // this screen still accepts, and accepting it makes the account.
  await typeInto(page.getByTestId('auth-code'), code)
  await clickSubmit(page.getByTestId('auth-submit'))
  await continueFromDone(page)
  await expect(page.getByTestId('profile-name')).toBeVisible()
})

test('counts the code down and comes back to it, still counting, after a reload', async ({
  page,
}) => {
  const email = uniqueEmail()

  await gotoPage(page, '/profile')
  await submitRegistration(page, email)
  await expect(page.getByTestId('auth-code')).toBeVisible()

  // The screen says how long the code it is asking for is still good for, and the
  // number moves on its own - a caption that never changed would satisfy every
  // other assertion here (HIL-486).
  const countdown = page.getByTestId('auth-expires-in')
  await expect(countdown).toContainText(/\d+:\d{2}/)
  const started = await remainingSeconds(countdown)
  await expect.poll(async () => remainingSeconds(countdown)).toBeLessThan(started)
  const beforeReload = await remainingSeconds(countdown)

  // The whole reason the moment comes from the server instead of from a duration
  // this tab started counting: a reload has no memory of when the counting began.
  // Coming back to a FULL countdown would be the same defect as coming back to the
  // address field, so what is asserted is that it kept SHRINKING across the reload.
  await page.reload()
  await expect(page.getByTestId('auth-code')).toBeVisible()
  await expect
    .poll(async () => remainingSeconds(countdown))
    .toBeLessThan(beforeReload)

  // And it is the same registration: the code mailed before the reload is the one
  // this screen still accepts.
  await submitRegistrationCode(page, await readRegisterCode(email))
  await submitFirstPassword(page, PASSWORD)
  await continueFromDone(page)
  await expect(page.getByTestId('profile-name')).toBeVisible()
})

// Signing out re-decides the page that is still open (HIL-652). These two are the
// readiness of that leaf, and they are two cases and not one because the two
// surfaces are closed by different halves of it: the administrative page has a
// client reaction watching the identity, /profile has none at all and is closed by
// the server's answer alone. What both assert is that the answer ARRIVES - the
// outlet settles on a refusal - because a client that merely blanked the screen
// would satisfy every assertion about what is gone.

test('re-decides an open admin page when the person signs out, in place', async ({
  page,
}) => {
  const { userId } = await signUp(page)
  await setAdmin(userId, true)
  await gotoPage(page, '/hilos/app/users')
  await expect(page.getByTestId('hilos-viewport-table')).toBeVisible()

  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  await logout(page)

  // The table is gone because the outlet stopped rendering the page, not because
  // the view hid itself: this component draws unconditionally.
  await expectPageRefused(page)
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await expect(page.getByTestId('admin-users-view')).toHaveCount(0)
  await expect(page.getByTestId('hilos-viewport-table')).toHaveCount(0)
  expect(new URL(page.url()).pathname).toBe('/hilos/app/users')
  expect(fullLoads).toBe(0)

  // Revoke so the shared-DB user does not stay admin for later specs.
  await setAdmin(userId, false)
})

test('re-decides an open profile when the person signs out, in place', async ({
  page,
}) => {
  await signUp(page)
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('profile-name')).toBeVisible()

  let fullLoads = 0
  page.on('load', () => {
    fullLoads += 1
  })

  await logout(page)

  // /profile is not an administrative surface and gets no client reaction of any
  // kind, so the sign-in invitation on screen is the server's own answer to a
  // subscription it re-decided - the case the by-user criterion could never reach,
  // since the person it would have named is exactly who just left.
  await expectPageRefused(page)
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await expect(page.getByTestId('profile-name')).toHaveCount(0)
  expect(new URL(page.url()).pathname).toBe('/profile')
  expect(fullLoads).toBe(0)
})

/**
 * Read the countdown as seconds, so two readings can be compared as numbers.
 *
 * A line that is not there yet - or says something else - answers a value no
 * countdown can hold, so a poll waiting for the number to shrink goes on waiting
 * instead of passing on the absence of one.
 *
 * @param countdown The locator of the countdown line.
 * @returns Seconds it says are left, or an unreachably large number while it says nothing readable.
 */
async function remainingSeconds(countdown: Locator): Promise<number> {
  const parts = /(\d+):(\d{2})/.exec((await countdown.textContent()) ?? '')

  return parts === null
    ? Number.MAX_SAFE_INTEGER
    : Number(parts[1]) * SECONDS_PER_MINUTE + Number(parts[2])
}

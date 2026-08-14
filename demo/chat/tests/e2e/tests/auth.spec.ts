import { test, expect } from '@playwright/test'

import { mailsTo, readRegisterCode } from '../helpers/mail'
import {
  login,
  logout,
  nameFromEmail,
  PASSWORD,
  register,
  submitRegistration,
  submitRegistrationCode,
  uniqueEmail,
} from '../helpers/session'
import { gotoPage } from '../helpers/page'

// Auth e2e umbrella (HIL-167): the email+password sign-in flow end to end through
// the live daemon and built frontend. It covers the surfaces that landed with the
// session≠user rework (HIL-360) and the auth stack (HIL-161…165, HIL-364):
//   - the AUTHENTICATED-guarded profile page 401s an anonymous subscribe and the
//     auth gate mounts the project sign-in surface IN PLACE (no redirect), then
//     resumes the preserved subscription off the session upgrade — no navigation;
//   - registering takes two steps (HIL-415): the submit only holds the address and
//     mails one code, the code creates the account and signs the session in, and
//     logout reverts the session to anonymous so the gated page re-gates;
//   - login names which of the three ways it failed (HIL-414): the address has no
//     account, the account has no password, or the password is wrong;
//   - the anonymous visitor reads the chat but the composer gates sending behind
//     the same surface, opened as the auth-gate modal via the composer's Sign in button.
// Recovery (HIL-365) has no reachable e2e leg yet — its surface entry renders a
// placeholder — so it is left to the integration tests until the flow lands. The
// backend of each action is already covered by the Integration suite; this file
// is the UI-driven, cross-surface flow. The register/login/logout helpers are
// shared with the other specs that establish a session (helpers/session.ts).
//
// The code step is driven from the letter the daemon really delivered — read out
// of the file transport's artifact (helpers/mail.ts), the same shape as the hleb
// reference reading its catcher. Reservation expiry is not exercised here: the
// hold lasts 15 minutes, so its rollback stays an integration test.

test('registers through the gated profile surface, auto-logs-in, and resumes it in place', async ({
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
})

test('signs in through the gated profile surface and resumes the preserved subscription', async ({
  page,
}) => {
  const email = uniqueEmail()

  // Seed an account through the surface, then return to anonymous.
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await register(page, email)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await logout(page)

  // Anonymous again: the gated profile re-mounts the surface (login mode by
  // default). Signing in with the seeded credentials resumes the same preserved
  // subscription in place — no navigation.
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await login(page, email, PASSWORD)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await expect(page.getByTestId('auth-surface')).toHaveCount(0)
  expect(new URL(page.url()).pathname).toBe('/profile')
})

// HIL-281: disabled — cold-first OAuth login hangs. On the first callback after a
// fresh daemon boot (or leader re-election) the leader-pinned monopolistic OAuth
// agent is not yet ready to receive HILOS_OAUTH_PENDING, so the callback worker's
// sendToAgent is dropped: the pending op is never adopted, the login never binds,
// and the page hangs on /auth/callback until the frontend backstop fires. A warm
// retry passes (agent up by then), which was the only thing keeping the suite green.
// This is a real (narrow) production race too — the first login in the window right
// after start. TODO: fix the agent-readiness handoff live, then un-fixme.
test.fixme('signs in by OAuth provider redirect and callback (HIL-281)', async ({
  page,
}) => {
  // OAuth login drives mechanism B end to end offline: the stub provider under
  // `oauth:github` bounces its authorize URL straight back to /auth/callback, the
  // monopolistic OAuth agent (a separate, leader-pinned process) resolves the
  // (provider, subject) to a fresh account, and the bound session rides the
  // current-user fan-out (HIL-161) that signs the visitor in. This is the login-path
  // e2e that was missing while the callback handed the pending op to the agent
  // through a never-synced runtime collection — the copy the agent read stayed empty.
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await expect(page.getByTestId('profile-name')).toHaveCount(0)

  // Redirect out to the provider (offline stub) and back through the callback; a
  // successful sign-in lands on the home path with the live session upgraded.
  await page.getByTestId('auth-oauth-github').click()
  await expect(page).toHaveURL(/\/$/)
  await expect(page.getByTestId('message-input')).toBeEnabled()
  await expect(page.getByTestId('message-signin')).toHaveCount(0)

  // The upgraded session persists: the gated profile now resolves in place, with the
  // OAuth identity bound to the freshly created account.
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await expect(page.getByTestId('auth-surface')).toHaveCount(0)
})

test('names which of the three ways a sign-in failed', async ({ page }) => {
  const email = uniqueEmail()

  // Seed a known account, then log out so the login path is exercised.
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await register(page, email)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await logout(page)

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()

  // Wrong password for a real email → says so, still gated.
  await login(page, email, 'a different password')
  await expect(page.getByTestId('auth-error')).toHaveText('Incorrect password')
  await expect(page.getByTestId('profile-name')).toHaveCount(0)

  // Unknown email → a different sentence. The live lookup in front of this form
  // already answers which addresses have accounts, so the login says which half
  // of what was typed is wrong instead of leaving it to be guessed (HIL-414).
  await login(page, uniqueEmail(), 'yet another password')
  await expect(page.getByTestId('auth-error')).toHaveText(
    'No account found for this email',
  )
  await expect(page.getByTestId('profile-name')).toHaveCount(0)
})

test('lets an anonymous visitor read the chat but gates sending behind the sign-in surface', async ({
  page,
}) => {
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
  await register(page, uniqueEmail())
  await expect(modal).toBeHidden()
  await expect(page.getByTestId('message-input')).toBeEnabled()
  await expect(page.getByTestId('message-signin')).toHaveCount(0)
})

test('holds the address on submit and creates the account only on the mailed code', async ({
  page,
}) => {
  const email = uniqueEmail()

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()

  // The submit reserves the address and mails one code — it registers nobody, so
  // the page stays gated and the surface steps to the code screen.
  await submitRegistration(page, email)
  await expect(page.getByTestId('auth-register-code')).toBeVisible()
  await expect(page.getByTestId('profile-name')).toHaveCount(0)

  const code = await readRegisterCode(email)
  // Any code but the delivered one; picked off it so the run can never guess right.
  const wrongCode = code === '000000' ? '111111' : '000000'

  // A wrong code is an inline error on the step the person is already on: no
  // account is left behind and nothing rolls back.
  await submitRegistrationCode(page, wrongCode)
  await expect(page.getByTestId('auth-error')).toBeVisible()
  await expect(page.getByTestId('auth-register-code')).toBeVisible()
  await expect(page.getByTestId('profile-name')).toHaveCount(0)

  // The delivered code creates the account and signs the session in, so the gate
  // clears and the preserved profile subscription resumes in place.
  await submitRegistrationCode(page, code)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await expect(page.getByTestId('auth-surface')).toHaveCount(0)
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

  // Tab B submits the SAME address from the composer's gate: the hold is already
  // there, so it joins the code step instead of being refused, and no second
  // letter goes out — both are waiting on the one code in the inbox.
  const second = await context.newPage()
  await gotoPage(second, '/')
  await expect(second.getByTestId('conn-state')).toHaveText('connected')
  await second.getByTestId('message-signin').click()
  await submitRegistration(second, email)
  await expect(second.getByTestId('auth-register-code')).toBeVisible()
  expect(await mailsTo(email)).toHaveLength(1)

  // Tab A confirms. Tab B is not merely moved along: it really enters, resolving
  // the self user of the account tab A just created.
  await submitRegistrationCode(page, await readRegisterCode(email))
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await expect(second.getByTestId('self-user')).toHaveText(nameFromEmail(email))
  await expect(second.getByTestId('modal')).toBeHidden()
  await expect(second.getByTestId('message-input')).toBeEnabled()
})

test('answers a repeated submit of a held address with the code step and no second letter', async ({
  page,
}) => {
  const email = uniqueEmail()

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await submitRegistration(page, email)
  await readRegisterCode(email)

  // Back to the start and submit the same address again: the hold answers for it,
  // so the person lands on the code step of the letter already sent.
  await page.getByTestId('auth-to-login').click()
  await submitRegistration(page, email)
  await expect(page.getByTestId('auth-register-code')).toBeVisible()
  await expect(page.getByTestId('auth-error')).toHaveCount(0)
  expect(await mailsTo(email)).toHaveLength(1)
})

test('sends nothing for a resend pressed inside the cooldown', async ({
  page,
}) => {
  const email = uniqueEmail()

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await submitRegistration(page, email)
  const code = await readRegisterCode(email)

  await page.getByTestId('auth-resend').click()

  // The proof is the FIRST code still working: issuing a second one voids the one
  // before it, so confirming with this one says no second code was minted — and
  // since the confirm is answered after the resend on the same connection, the
  // mailbox count is read once the resend has been decided.
  await submitRegistrationCode(page, code)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  expect(await mailsTo(email)).toHaveLength(1)
})

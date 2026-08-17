import { test, expect, type Locator } from '@playwright/test'

import { mailsTo, readRegisterCode } from '../helpers/mail'
import {
  clickSubmit,
  continueFromDone,
  enterIdentifierAndPassword,
  login,
  logout,
  nameFromEmail,
  PASSWORD,
  register,
  submitRegistration,
  submitRegistrationCode,
  typeInto,
  uniqueEmail,
} from '../helpers/session'
import { gotoPage } from '../helpers/page'
import { uniquePhone, waitForSmsCode } from '../helpers/sms'
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
  await page.getByTestId('auth-icon-oauth-github').click()
  await expect(page).toHaveURL(/\/$/)
  await expect(page.getByTestId('message-input')).toBeEnabled()
  await expect(page.getByTestId('message-signin')).toHaveCount(0)

  // The upgraded session persists: the gated profile now resolves in place, with the
  // OAuth identity bound to the freshly created account.
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await expect(page.getByTestId('auth-surface')).toHaveCount(0)
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
  // the backend's own sentence, still gated.
  await login(page, email, 'a different password')
  await expect(page.getByTestId('auth-error')).toHaveText('Incorrect password')
  await expect(page.getByTestId('profile-name')).toHaveCount(0)

  // An address with no account is never given a sign-in to fail: the lookup in
  // FRONT of the form answers first, so the same screen becomes the registration
  // (HIL-414) — which is why there is no "no account found" sentence any more.
  await enterIdentifierAndPassword(page, uniqueEmail(), PASSWORD)
  await expect(page.getByTestId('auth-heading')).toHaveText(
    'Create your account',
  )
  await expect(page.getByTestId('auth-error')).toHaveCount(0)
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

  // Accepting the terms reserves the address and mails one code — it registers
  // nobody, so the page stays gated and the surface steps to the code screen.
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

  // The delivered code creates the account and signs the session in — and the
  // surface says so instead of vanishing (HIL-422). The gate holds the resume
  // until that panel is acknowledged, which is why the profile is still gated
  // here and comes through on Continue.
  await submitRegistrationCode(page, code)
  await expect(page.getByTestId('auth-continue')).toBeVisible()
  await expect(page.getByTestId('profile-name')).toHaveCount(0)

  await continueFromDone(page)
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
  await continueFromDone(page)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await expect(second.getByTestId('self-user')).toHaveText(nameFromEmail(email))
  await expect(second.getByTestId('modal')).toBeHidden()
  await expect(second.getByTestId('message-input')).toBeEnabled()
})

test('puts a held address back on its code step from the lookup alone', async ({
  page,
}) => {
  const email = uniqueEmail()

  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await submitRegistration(page, email)
  await readRegisterCode(email)

  // "Not that address?" drops what THIS SESSION remembers, but not the hold on the
  // address itself — one session cannot free an address another is registering.
  await page.getByTestId('auth-restart').click()
  await expect(page.getByTestId('auth-identifier')).toBeVisible()

  // So typing it again is answered by the hold: the lookup alone puts the person
  // back on the code step of the letter already sent, with nothing submitted and
  // no second letter mailed.
  await typeInto(page.getByTestId('auth-identifier'), email)
  await expect(page.getByTestId('auth-code')).toBeVisible()
  await expect(page.getByTestId('auth-error')).toHaveCount(0)
  expect(await mailsTo(email)).toHaveLength(1)
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
  await continueFromDone(page)
  await expect(page.getByTestId('profile-name')).toBeVisible()
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

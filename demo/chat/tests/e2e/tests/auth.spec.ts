import { test, expect } from '@playwright/test'

import { login, logout, PASSWORD, register, uniqueEmail } from '../helpers/session'

// Auth e2e umbrella (HIL-167): the email+password sign-in flow end to end through
// the live daemon and built frontend. It covers the surfaces that landed with the
// session≠user rework (HIL-360) and the auth stack (HIL-161…165, HIL-364):
//   - the AUTHENTICATED-guarded profile page 401s an anonymous subscribe and the
//     auth gate mounts the project sign-in surface IN PLACE (no redirect), then
//     resumes the preserved subscription off the session upgrade — no navigation;
//   - register auto-logs-in (autoLoginAfterRegister default), and logout reverts
//     the session to anonymous so the gated page re-gates;
//   - login rejects a wrong password and an unknown email with the SAME generic
//     "Invalid email or password" (no user enumeration);
//   - the anonymous visitor reads the chat but the composer gates sending behind
//     the same surface, opened as the auth-gate modal via the banner CTA.
// Recovery (HIL-365) has no reachable e2e leg yet — its surface entry renders a
// placeholder — so it is left to the integration tests until the flow lands. The
// backend of each action is already covered by the Integration suite; this file
// is the UI-driven, cross-surface flow. The register/login/logout helpers are
// shared with the other specs that establish a session (helpers/session.ts).

test('registers through the gated profile surface, auto-logs-in, and resumes it in place', async ({
  page,
}) => {
  const email = uniqueEmail()

  await page.goto('/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  // Anonymous: no session user yet, so the chat composer prompts to sign in.
  await expect(page.getByTestId('register-banner')).toBeVisible()

  // The framework profile page is AUTHENTICATED-guarded: an anonymous subscribe
  // 401s and the gate mounts the sign-in surface in place of the profile view.
  await page.goto('/profile')
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
  await page.goto('/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await expect(page.getByTestId('profile-name')).toHaveCount(0)
})

test('signs in through the gated profile surface and resumes the preserved subscription', async ({
  page,
}) => {
  const email = uniqueEmail()

  // Seed an account through the surface, then return to anonymous.
  await page.goto('/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await register(page, email)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await logout(page)

  // Anonymous again: the gated profile re-mounts the surface (login mode by
  // default). Signing in with the seeded credentials resumes the same preserved
  // subscription in place — no navigation.
  await page.goto('/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await login(page, email, PASSWORD)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await expect(page.getByTestId('auth-surface')).toHaveCount(0)
  expect(new URL(page.url()).pathname).toBe('/profile')
})

test('signs in by OAuth provider redirect and callback (HIL-281)', async ({
  page,
}) => {
  // OAuth login drives mechanism B end to end offline: the stub provider under
  // `oauth:github` bounces its authorize URL straight back to /auth/callback, the
  // monopolistic OAuth agent (a separate, leader-pinned process) resolves the
  // (provider, subject) to a fresh account, and the bound session rides the
  // current-user fan-out (HIL-161) that signs the visitor in. This is the login-path
  // e2e that was missing while the callback handed the pending op to the agent
  // through a never-synced runtime collection — the copy the agent read stayed empty.
  await page.goto('/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await expect(page.getByTestId('profile-name')).toHaveCount(0)

  // Redirect out to the provider (offline stub) and back through the callback; a
  // successful sign-in lands on the home path with the live session upgraded.
  await page.getByTestId('auth-oauth-github').click()
  await expect(page).toHaveURL(/\/$/)
  await expect(page.getByTestId('message-input')).toBeEnabled()
  await expect(page.getByTestId('register-banner')).toHaveCount(0)

  // The upgraded session persists: the gated profile now resolves in place, with the
  // OAuth identity bound to the freshly created account.
  await page.goto('/profile')
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await expect(page.getByTestId('auth-surface')).toHaveCount(0)
})

test('rejects a wrong password and an unknown email with the same generic message', async ({
  page,
}) => {
  const email = uniqueEmail()

  // Seed a known account, then log out so the login path is exercised.
  await page.goto('/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await register(page, email)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await logout(page)

  await page.goto('/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()

  // Wrong password for a real email → generic rejection, still gated.
  await login(page, email, 'a different password')
  await expect(page.getByTestId('auth-error')).toHaveText(
    'Invalid email or password',
  )
  await expect(page.getByTestId('profile-name')).toHaveCount(0)

  // Unknown email → the identical message, so a guessing attacker cannot tell a
  // real account from a missing one.
  await login(page, uniqueEmail(), 'yet another password')
  await expect(page.getByTestId('auth-error')).toHaveText(
    'Invalid email or password',
  )
  await expect(page.getByTestId('profile-name')).toHaveCount(0)
})

test('lets an anonymous visitor read the chat but gates sending behind the sign-in surface', async ({
  page,
}) => {
  await page.goto('/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')

  // Anonymous read: the live event stream renders without a session.
  await expect(page.getByTestId('events-scroll')).toBeVisible()

  // The composer is gated: the message input is disabled and a banner prompts to
  // sign in rather than sending.
  await expect(page.getByTestId('message-input')).toBeDisabled()
  await expect(page.getByTestId('register-banner')).toBeVisible()

  // The banner CTA opens the same surface as the auth-gate modal (requireAuth),
  // in place over the live page.
  await page.getByTestId('register-banner-cta').click()
  const modal = page.getByTestId('modal')
  await expect(modal).toBeVisible()
  await expect(modal.getByTestId('auth-surface')).toBeVisible()

  // Registering through the modal upgrades the session; the gate closes the modal
  // off the session upgrade and the composer un-gates in place.
  await register(page, uniqueEmail())
  await expect(modal).toBeHidden()
  await expect(page.getByTestId('message-input')).toBeEnabled()
  await expect(page.getByTestId('register-banner')).toHaveCount(0)
})

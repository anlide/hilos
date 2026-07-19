import { expect, type Page } from '@playwright/test'

// Shared sign-in helpers for the session≠user model (HIL-360). Since auto-guest
// was dropped, a fresh browser context is anonymous: it reads the chat but has
// no self user until it registers or logs in through the project auth surface
// (AuthSurface.vue). Every spec that needs an authenticated (or admin) user
// establishes one through these helpers rather than relying on a connect-time
// auto-registration that no longer happens. The auth surface itself is exercised
// end to end by auth.spec.ts; here it is only the means to a signed-in session.

/** A valid password (>= the 8-char minimum the surface and backend enforce). */
export const PASSWORD = 'correct horse battery'

/** A fresh, globally-unique email so parallel specs and retries never collide on
 * the shared test database (registration rejects a taken email). */
export function uniqueEmail(): string {
  return `e2e-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@example.test`
}

/** The display name the backend derives from an email — the local part before
 * the first '@' (MainPage::displayNameFromEmail) — which the self user renders. */
export function nameFromEmail(email: string): string {
  const atPosition = email.indexOf('@')

  return atPosition === -1 ? email : email.slice(0, atPosition)
}

/** Fill and submit the register form on the currently mounted auth surface. */
export async function register(page: Page, email: string): Promise<void> {
  await page.getByTestId('auth-to-register').click()
  await expect(page.getByTestId('auth-heading')).toHaveText('Create your account')
  await page.getByTestId('auth-email').fill(email)
  await page.getByTestId('auth-password').fill(PASSWORD)
  await page.getByTestId('auth-confirm').fill(PASSWORD)
  await page.getByTestId('auth-submit').click()
}

/** Fill and submit the login form on the currently mounted surface (default mode). */
export async function login(
  page: Page,
  email: string,
  password: string = PASSWORD,
): Promise<void> {
  await page.getByTestId('auth-email').fill(email)
  await page.getByTestId('auth-password').fill(password)
  await page.getByTestId('auth-submit').click()
}

/** Log out through the shell control and wait for the anonymous state to settle
 * (the navbar profile link is bound to a named user, so its removal confirms the
 * backend processed the logout before the next navigation reconnects). */
export async function logout(page: Page): Promise<void> {
  await page.getByTestId('nav-logout').click()
  await expect(page.getByTestId('nav-profile')).toHaveCount(0)
}

/** A registered session: the account's email, its derived display name, and its
 * durable user id (as the self user exposes it). */
export interface SignedInUser {
  email: string
  name: string
  userId: number
}

/**
 * Register a fresh account from the anonymous main page and return its identity.
 *
 * Opens the auth-gate modal through the composer's "register to send" banner CTA
 * and registers; auto-login (autoLoginAfterRegister default) upgrades the live
 * session in place, so the page is left on '/' signed in with the self user
 * resolved. This is the standard way an authenticated spec obtains its user under
 * the session≠user model.
 *
 * @param page Page starting from any location (it navigates to '/')
 * @returns The registered account's email, display name, and durable user id
 */
export async function signUp(page: Page): Promise<SignedInUser> {
  const email = uniqueEmail()

  await page.goto('/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await page.getByTestId('register-banner-cta').click()
  await register(page, email)

  const name = nameFromEmail(email)
  // Auto-login resolves the self user in place; assert the name to be sure the
  // session upgrade landed before reading the id.
  await expect(page.getByTestId('self-user')).toHaveText(name)
  const userId = Number(await page.getByTestId('self-user-id').textContent())

  return { email, name, userId }
}

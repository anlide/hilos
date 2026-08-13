import { expect, type Locator, type Page } from '@playwright/test'
import { readRegisterCode } from './mail'
import { gotoPage } from './page'

// Shared sign-in helpers for the session≠user model (HIL-360). Since auto-guest
// was dropped, a fresh browser context is anonymous: it reads the chat but has
// no self user until it registers or logs in through the project auth surface
// (AuthSurface.vue). Every spec that needs an authenticated (or admin) user
// establishes one through these helpers rather than relying on a connect-time
// auto-registration that no longer happens. The auth surface itself is exercised
// end to end by auth.spec.ts; here it is only the means to a signed-in session.
//
// Registering takes two steps since HIL-415 — the submit only holds the address
// and mails a code, and the account exists once that code comes back. register()
// absorbs both, reading the code out of the delivered letter (helpers/mail.ts), so
// a spec that just needs an account still asks for one in a single call. The steps
// are also exported on their own, for the specs that are about the code step.

/** A valid password (>= the 8-char minimum the surface and backend enforce). This same
 * value is the default password of the `test:user:seed` CLI (DEFAULT_PASSWORD in
 * UserTestSeedCommand), so a spec can log in as any seeded fixture user with it — the
 * way settings.spec.ts pins the e2e_orphan_delete key to its seed command. */
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

/**
 * Enter a value the way a user does: clear, then type key by key. Never fill(value)
 * — a bare fill sets .value and dispatches a single synthetic `input`, which can
 * miss the Vue reactivity the auth surface relies on (the machine's form field, the
 * computed `submittable`), so a submit ships an empty payload. pressSequentially
 * emits real per-key events (keydown/keypress/input/keyup) that drive the surface.
 *
 * @param field The input locator.
 * @param value The value to type.
 */
async function typeInto(field: Locator, value: string): Promise<void> {
  await field.fill('')
  await field.pressSequentially(value, { delay: 10 })
}

/**
 * Click a submit button once it is genuinely actionable: scrolled into view,
 * visible, enabled, focused. Guards against clicking a still-disabled control and
 * against a click that no-ops before the surface is ready.
 *
 * @param button The submit-button locator.
 */
async function clickSubmit(button: Locator): Promise<void> {
  await button.scrollIntoViewIfNeeded()
  await expect(button).toBeVisible()
  await expect(button).toBeEnabled()
  await button.focus()
  await button.click()
}

/**
 * Wait for an auth submit to SETTLE before the caller asserts its result. The
 * dispatched login/register is in flight until its reply lands: on success the
 * session upgrades and the surface closes (the gate/modal unmounts on the
 * current-user signal); on rejection an inline error appears and the surface
 * stays. Either outcome means the reply arrived — so the next assertion runs
 * against a resolved state, never a still-loading one (that race is what made the
 * gated-profile specs flaky).
 *
 * @param page The page whose auth surface is submitting.
 */
async function waitAuthSettled(page: Page): Promise<void> {
  await expect(async () => {
    const closed = (await page.getByTestId('auth-surface').count()) === 0
    const failed = (await page.getByTestId('auth-error').count()) > 0
    expect(closed || failed).toBeTruthy()
  }).toPass()
}

/**
 * Wait for a register submit to SETTLE: the code step is up, or the submit was
 * refused inline. Unlike a login, a successful register neither closes the surface
 * nor upgrades anything — it hands the address a hold and moves one step on — so
 * the arrival of the code field is what says the reply landed.
 *
 * @param page The page whose auth surface is submitting.
 */
async function waitRegisterSettled(page: Page): Promise<void> {
  await expect(async () => {
    const onCodeStep =
      (await page.getByTestId('auth-register-code').count()) > 0
    const failed = (await page.getByTestId('auth-error').count()) > 0
    expect(onCodeStep || failed).toBeTruthy()
  }).toPass()
}

/**
 * Fill and submit the register form; the surface lands on its code step.
 *
 * The account does NOT exist afterwards: the submit reserves the address and has
 * one code mailed to it (HIL-415).
 *
 * @param page The page with the auth surface mounted.
 * @param email The address to register.
 */
export async function submitRegistration(
  page: Page,
  email: string,
): Promise<void> {
  await page.getByTestId('auth-to-register').click()
  await expect(page.getByTestId('auth-heading')).toHaveText('Create your account')
  await typeInto(page.getByTestId('auth-email'), email)
  await typeInto(page.getByTestId('auth-password'), PASSWORD)
  await typeInto(page.getByTestId('auth-confirm'), PASSWORD)
  await clickSubmit(page.getByTestId('auth-submit'))
  await waitRegisterSettled(page)
}

/**
 * Submit a confirmation code on the register code step.
 *
 * A valid code is what creates the account and signs the session in, so this
 * settles the same way a login does — the surface closes, or an inline error stays.
 *
 * @param page The page sitting on the code step.
 * @param code The code to type.
 */
export async function submitRegistrationCode(
  page: Page,
  code: string,
): Promise<void> {
  await typeInto(page.getByTestId('auth-register-code'), code)
  await clickSubmit(page.getByTestId('auth-submit'))
  await waitAuthSettled(page)
}

/**
 * Register an account end to end on the currently mounted auth surface: submit the
 * form, read the code out of the delivered letter, confirm it.
 *
 * @param page The page with the auth surface mounted.
 * @param email The address to register.
 */
export async function register(page: Page, email: string): Promise<void> {
  await submitRegistration(page, email)
  await submitRegistrationCode(page, await readRegisterCode(email))
}

/** Fill and submit the login form on the currently mounted surface (default mode). */
export async function login(
  page: Page,
  email: string,
  password: string = PASSWORD,
): Promise<void> {
  await typeInto(page.getByTestId('auth-email'), email)
  await typeInto(page.getByTestId('auth-password'), password)
  await clickSubmit(page.getByTestId('auth-submit'))
  await waitAuthSettled(page)
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
 * Opens the auth-gate modal through the composer's "Sign in to send" button and
 * registers, code step included; confirming the code creates the account and
 * upgrades the live session in place, so the page is left on '/' signed in with
 * the self user resolved. This is the standard way an authenticated spec obtains
 * its user under the session≠user model.
 *
 * @param page Page starting from any location (it navigates to '/')
 * @returns The registered account's email, display name, and durable user id
 */
export async function signUp(page: Page): Promise<SignedInUser> {
  const email = uniqueEmail()

  await gotoPage(page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await page.getByTestId('message-signin').click()
  await register(page, email)

  const name = nameFromEmail(email)
  // The confirmed code resolves the self user in place; assert the name to be sure
  // the session upgrade landed before reading the id.
  await expect(page.getByTestId('self-user')).toHaveText(name)
  const userId = Number(await page.getByTestId('self-user-id').textContent())

  return { email, name, userId }
}

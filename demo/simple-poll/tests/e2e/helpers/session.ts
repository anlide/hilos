import { expect, type Locator, type Page } from '@playwright/test'

import { readRegisterCode } from './mail'

// Sign-in helpers for the simple-poll demo (HIL-634). A fresh browser context is
// a guest: it reads the app and carries a guest name, but has no account until it
// registers or logs in through the project auth surface (auth/authSurface.ts).
// The surface itself is exercised end to end by auth.spec.ts; here it is only
// the means to a signed-in session.
//
// The surface is identifier-first (HIL-423): there is ONE field, and what it
// reveals is decided by the live lookup rather than by a mode the spec picks. So
// a helper types the identifier, waits for the reveal, and only then fills what
// appeared.

/** A valid password (>= the 8-char minimum the surface and backend enforce). */
export const PASSWORD = 'correct horse battery'

/** A fresh, globally-unique email so parallel specs and retries never collide on
 * the shared test database (registration rejects a taken email). */
export function uniqueEmail(): string {
  return `e2e-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@example.test`
}

/** The display name the backend derives from an email — the local part before
 * the first '@' (AbstractLibraryCommands::displayNameFromEmail) — which the main
 * page's identity line renders. */
export function nameFromEmail(email: string): string {
  const atPosition = email.indexOf('@')

  return atPosition === -1 ? email : email.slice(0, atPosition)
}

/**
 * Enter a value the way a person does: clear, then type key by key. Never
 * fill(value) — a bare fill sets .value and dispatches a single synthetic
 * `input`, which can miss the reactivity the auth surface relies on (the
 * machine's form field, the computed submittable), so a submit ships an empty
 * payload. pressSequentially emits real per-key events that drive the surface.
 *
 * @param field The input locator.
 * @param value The value to type.
 */
export async function typeInto(field: Locator, value: string): Promise<void> {
  await field.fill('')
  await field.pressSequentially(value, { delay: 10 })
}

/**
 * Click a submit button once it is genuinely actionable. Guards against clicking
 * a still-disabled control and against a click that no-ops before the surface is
 * ready.
 *
 * Only retrying calls are used, and that is the whole point of the shape. An
 * opening scrollIntoViewIfNeeded() throws outright on an element that has just
 * been detached rather than waiting for the next one, so a surface re-rendering
 * under the helper failed the click instead of repeating it — which is how
 * Continue went flaky on the page-in-place case, where the resume can take the
 * whole outlet away around the moment it is pressed. The two assertions below
 * are web-first and retry on their own, and click() scrolls into view and
 * re-resolves the locator on every actionability attempt, so nothing is lost by
 * dropping the manual scroll and focus.
 *
 * @param button The submit-button locator.
 */
export async function clickSubmit(button: Locator): Promise<void> {
  await expect(button).toBeVisible()
  await expect(button).toBeEnabled()
  await button.click()
}

/**
 * Wait for an auth submit to SETTLE before the caller asserts its result. The
 * dispatched login is in flight until its reply lands: on success the session
 * upgrades and the surface closes; on rejection an inline error appears and the
 * surface stays. Either outcome means the reply arrived — so the next assertion
 * runs against a resolved state, never a still-loading one.
 *
 * @param page The page whose auth surface is submitting.
 */
export async function waitAuthSettled(page: Page): Promise<void> {
  await expect(async () => {
    const closed = (await page.getByTestId('auth-surface').count()) === 0
    const failed = (await page.getByTestId('auth-error').count()) > 0
    expect(closed || failed).toBeTruthy()
  }).toPass()
}

/**
 * Wait for a register submit to SETTLE: the code step is up, or the submit was
 * refused inline. Unlike a login, a successful register neither closes the
 * surface nor upgrades anything — it hands the address a hold and moves one step
 * on — so the arrival of the code field is what says the reply landed.
 *
 * @param page The page whose auth surface is submitting.
 */
async function waitRegisterSettled(page: Page): Promise<void> {
  await expect(async () => {
    const onCodeStep = (await page.getByTestId('auth-code').count()) > 0
    const failed = (await page.getByTestId('auth-error').count()) > 0
    expect(onCodeStep || failed).toBeTruthy()
  }).toPass()
}

/**
 * Wait for a flow to reach its done screen, or be refused inline. A flow that
 * ends by signing somebody in does not close the surface on its own (HIL-422) —
 * it says what was achieved and waits for Continue.
 *
 * @param page The page whose auth surface is submitting.
 */
async function waitDoneSettled(page: Page): Promise<void> {
  await expect(async () => {
    const done = (await page.getByTestId('auth-continue').count()) > 0
    const failed = (await page.getByTestId('auth-error').count()) > 0
    expect(done || failed).toBeTruthy()
  }).toPass()
}

/**
 * Type an identifier and wait for the live lookup to answer it with the password
 * field (HIL-414).
 *
 * The identifier-first surface has one field and reveals the rest from the
 * reply, so a spec cannot type a password that is not on screen yet: the field
 * appearing IS the lookup having landed.
 *
 * @param page The page with the auth surface mounted.
 * @param identifier The email to type into the single field.
 * @param password The password to type once it is revealed.
 */
export async function enterIdentifierAndPassword(
  page: Page,
  identifier: string,
  password: string,
): Promise<void> {
  await typeInto(page.getByTestId('auth-identifier'), identifier)
  const passwordField = page.getByTestId('auth-password')
  await expect(passwordField).toBeVisible()
  await typeInto(passwordField, password)
}

/**
 * Acknowledge a finished flow's panel, which is what closes the surface.
 *
 * Continue clears the announcement the session owes (HIL-422); the gate has been
 * HOLDING its resume until then, so this is also the moment a gated page is let
 * through.
 *
 * @param page The page sitting on the done screen.
 */
export async function continueFromDone(page: Page): Promise<void> {
  await clickSubmit(page.getByTestId('auth-continue'))
  await waitAuthSettled(page)
}

/**
 * Fill and submit the register form; the surface lands on its code step.
 *
 * The account does NOT exist afterwards: the submit reserves the address and has
 * one code mailed to it (HIL-415).
 *
 * @param page The page with the auth surface mounted.
 * @param email The address to register.
 * @param password The password the new account is created with.
 */
export async function submitRegistration(
  page: Page,
  email: string,
  password: string = PASSWORD,
): Promise<void> {
  await enterIdentifierAndPassword(page, email, password)
  // Nothing was chosen: the lookup found no account for the address, so the one
  // screen turned itself into a registration.
  await expect(page.getByTestId('auth-heading')).toHaveText(
    'Create your account',
  )
  // A local move to the terms screen — this dispatches nothing.
  await clickSubmit(page.getByTestId('auth-submit'))
  await page.getByTestId('auth-consent-accept').check()
  // Accepting the terms is what reserves the address and mails the code.
  await clickSubmit(page.getByTestId('auth-submit'))
  await waitRegisterSettled(page)
}

/**
 * Submit a code on the step that is asking for one.
 *
 * @param page The page sitting on a code step.
 * @param code The code to type.
 */
export async function submitCode(page: Page, code: string): Promise<void> {
  await typeInto(page.getByTestId('auth-code'), code)
  await clickSubmit(page.getByTestId('auth-submit'))
  await waitDoneSettled(page)
}

/**
 * Register an account end to end on the currently mounted auth surface: submit
 * the form, read the code out of the delivered letter, confirm it, and
 * acknowledge the finished panel.
 *
 * @param page The page with the auth surface mounted.
 * @param email The address to register.
 * @param password The password the new account is created with.
 */
export async function register(
  page: Page,
  email: string,
  password: string = PASSWORD,
): Promise<void> {
  await submitRegistration(page, email, password)
  await submitCode(page, await readRegisterCode(email))
  await continueFromDone(page)
}

/**
 * Sign in on the currently mounted surface: type the address, wait for the
 * lookup to reveal the password, submit.
 *
 * Signing in with a password leaves no announcement (SessionAck has no kind for
 * it — nothing was achieved beyond arriving), so the surface simply closes.
 *
 * @param page The page with the auth surface mounted.
 * @param email The address of an existing account.
 * @param password The password to submit.
 */
export async function login(
  page: Page,
  email: string,
  password: string = PASSWORD,
): Promise<void> {
  await enterIdentifierAndPassword(page, email, password)
  await clickSubmit(page.getByTestId('auth-submit'))
  await waitAuthSettled(page)
}

/**
 * Open the sign-in surface from the shell the way a guest does.
 *
 * The header button is this demo's only entry point into the modal: it calls the
 * gate's requireAuth() directly, so a guest reaches sign-in without first
 * walking into a page that refuses them.
 *
 * @param page The page showing the guest shell.
 */
export async function openSignIn(page: Page): Promise<void> {
  await page.getByTestId('nav-signin').click()
  await expect(page.getByTestId('auth-surface')).toBeVisible()
}

/**
 * Sign out through the shell control and wait for the anonymous state to settle.
 *
 * The name in the shell is bound to an account, so its removal confirms the
 * daemon processed the sign-out before the next navigation reconnects.
 *
 * @param page The page showing the signed-in shell.
 */
export async function logout(page: Page): Promise<void> {
  await page.getByTestId('nav-logout').click()
  await expect(page.getByTestId('nav-profile-name')).toHaveCount(0)
}

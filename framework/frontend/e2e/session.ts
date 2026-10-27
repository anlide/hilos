import type { Expect, Page } from '@playwright/test'
import { clickSubmit, typeInto } from './input.js'
import { gotoPage } from './page.js'
import { readRegisterCode, waitForMailCode } from '../scripts/mail.mjs'
import { waitForSmsCode } from '../scripts/standSms.mjs'

/** A valid password shared with the test-user seed command. */
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
 * Wait for an auth submit to SETTLE before the caller asserts its result. The
 * dispatched login is in flight until its reply lands: on success the session
 * upgrades and the surface closes; on rejection an inline error appears and the
 * surface stays. Either outcome means the reply arrived — so the next assertion
 * runs against a resolved state, never a still-loading one.
 *
 * @param expect The calling spec's Playwright expect.
 * @param page The page whose auth surface is submitting.
 */
export async function waitAuthSettled(
  expect: Expect,
  page: Page,
): Promise<void> {
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
 * @param expect The calling spec's Playwright expect.
 * @param page The page whose auth surface is submitting.
 */
async function waitRegisterSettled(expect: Expect, page: Page): Promise<void> {
  await expect(async () => {
    const onCodeStep = (await page.getByTestId('auth-code').count()) > 0
    const failed = (await page.getByTestId('auth-error').count()) > 0
    expect(onCodeStep || failed).toBeTruthy()
  }).toPass()
}

/**
 * Wait for a registration code to SETTLE: the password step is up, or the code
 * was refused inline. The code proves the address and creates nothing (HIL-825),
 * so what says the reply landed is the field the next screen asks for.
 *
 * @param expect The calling spec's Playwright expect.
 * @param page The page whose auth surface is submitting.
 */
async function waitPasswordStepSettled(
  expect: Expect,
  page: Page,
): Promise<void> {
  await expect(async () => {
    const passwordField = page.getByTestId('auth-new-password')
    const onPasswordStep = (await passwordField.count()) > 0
    const failed = (await page.getByTestId('auth-error').count()) > 0
    expect(onPasswordStep || failed).toBeTruthy()
  }).toPass()
}

/**
 * Wait for a flow to reach its done screen, or be refused inline. A flow that
 * ends by signing somebody in does not close the surface on its own (HIL-422) —
 * it says what was achieved and waits for Continue.
 *
 * @param expect The calling spec's Playwright expect.
 * @param page The page whose auth surface is submitting.
 */
export async function waitDoneSettled(
  expect: Expect,
  page: Page,
): Promise<void> {
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
 * @param expect The calling spec's Playwright expect.
 * @param page The page with the auth surface mounted.
 * @param identifier The email to type into the single field.
 * @param password The password to type once it is revealed.
 */
export async function enterIdentifierAndPassword(
  expect: Expect,
  page: Page,
  identifier: string,
  password: string,
): Promise<void> {
  const identifierField = page.getByTestId('auth-identifier')
  await typeInto(identifierField, identifier)
  await expect(identifierField).toHaveValue(identifier)
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
 * @param expect The calling spec's Playwright expect.
 * @param page The page sitting on the done screen.
 */
export async function continueFromDone(
  expect: Expect,
  page: Page,
): Promise<void> {
  await clickSubmit(expect, page.getByTestId('auth-continue'))
  await waitAuthSettled(expect, page)
}

/**
 * Submit the register form; the surface lands on its code step.
 *
 * The address is all it asks for (HIL-825), and the account does NOT exist
 * afterwards: the submit reserves the address and has one code mailed to it
 * (HIL-415).
 *
 * @param expect The calling spec's Playwright expect.
 * @param page The page with the auth surface mounted.
 * @param email The address to register.
 */
export async function submitRegistration(
  expect: Expect,
  page: Page,
  email: string,
): Promise<void> {
  await typeInto(page.getByTestId('auth-identifier'), email)
  // Nothing was chosen: the lookup found no account for the address, so the one
  // screen turned itself into a registration.
  await expect(page.getByTestId('auth-heading')).toHaveText(
    'Create your account',
  )
  // A local move to the terms screen — this dispatches nothing.
  await clickSubmit(expect, page.getByTestId('auth-submit'))
  await page.getByTestId('auth-consent-accept').check()
  // Accepting the terms is what reserves the address and mails the code.
  await clickSubmit(expect, page.getByTestId('auth-submit'))
  await waitRegisterSettled(expect, page)
}

/**
 * Submit a registration code; the surface lands on the password step.
 *
 * A valid code proves the address and creates nothing (HIL-825), so unlike a
 * recovery code this one settles on the screen that asks for a password.
 *
 * @param expect The calling spec's Playwright expect.
 * @param page The page sitting on the registration code step.
 * @param code The code to type.
 */
export async function submitRegistrationCode(
  expect: Expect,
  page: Page,
  code: string,
): Promise<void> {
  await typeInto(page.getByTestId('auth-code'), code)
  await clickSubmit(expect, page.getByTestId('auth-submit'))
  await waitPasswordStepSettled(expect, page)
}

/**
 * Submit the password that creates the account, on the registration password step.
 *
 * @param expect The calling spec's Playwright expect.
 * @param page The page sitting on the registration password step.
 * @param password The password the account is created with.
 */
export async function submitFirstPassword(
  expect: Expect,
  page: Page,
  password: string = PASSWORD,
): Promise<void> {
  await typeInto(page.getByTestId('auth-new-password'), password)
  await clickSubmit(expect, page.getByTestId('auth-submit'))
  await waitDoneSettled(expect, page)
}

/**
 * Take the way PAST the password on the registration password step (HIL-1008).
 *
 * The other ending of the same screen: the account is created here, with no
 * secret of its own, and the mailed link is what it signs in by from then on. It
 * settles exactly where the password save settles, because what follows it is
 * the same landing.
 *
 * @param expect The calling spec's Playwright expect.
 * @param page The page sitting on the registration password step.
 */
export async function finishWithoutPassword(
  expect: Expect,
  page: Page,
): Promise<void> {
  await clickSubmit(expect, page.getByTestId('auth-complete-passwordless'))
  await waitDoneSettled(expect, page)
}

/**
 * Register an account with NO password end to end, the way somebody who means to
 * sign in by a mailed link does (HIL-1008): submit the address, confirm the code,
 * then take the exit instead of choosing a password.
 *
 * @param expect The calling spec's Playwright expect.
 * @param page The page with the auth surface mounted.
 * @param email The address to register.
 */
export async function registerWithoutPassword(
  expect: Expect,
  page: Page,
  email: string,
): Promise<void> {
  await submitRegistration(expect, page, email)
  await submitRegistrationCode(expect, page, await readRegisterCode(email))
  await finishWithoutPassword(expect, page)
  await continueFromDone(expect, page)
}

/**
 * Register an account end to end on the currently mounted auth surface: submit
 * the address, read the code out of the delivered letter, confirm it, choose a
 * password, and acknowledge the finished panel.
 *
 * @param expect The calling spec's Playwright expect.
 * @param page The page with the auth surface mounted.
 * @param email The address to register.
 * @param password The password the new account is created with.
 */
export async function register(
  expect: Expect,
  page: Page,
  email: string,
  password: string = PASSWORD,
): Promise<void> {
  await submitRegistration(expect, page, email)
  await submitRegistrationCode(expect, page, await readRegisterCode(email))
  await submitFirstPassword(expect, page, password)
  await continueFromDone(expect, page)
}

/**
 * Sign in on the currently mounted surface: type the address, wait for the
 * lookup to reveal the password, submit.
 *
 * Signing in with a password leaves no announcement (SessionAck has no kind for
 * it — nothing was achieved beyond arriving), so the surface simply closes.
 *
 * @param expect The calling spec's Playwright expect.
 * @param page The page with the auth surface mounted.
 * @param email The address of an existing account.
 * @param password The password to submit.
 */
export async function login(
  expect: Expect,
  page: Page,
  email: string,
  password: string = PASSWORD,
): Promise<void> {
  await enterIdentifierAndPassword(expect, page, email, password)
  await clickSubmit(expect, page.getByTestId('auth-submit'))
  await waitAuthSettled(expect, page)
}

/**
 * Open the sign-in surface from the shell the way a guest does.
 *
 * The entry calls the auth gate directly, without navigating to a gated page.
 *
 * @param expect The calling spec's Playwright expect.
 * @param page The page showing the guest shell.
 * @param entryId The control that opens the auth surface.
 */
export async function openSignIn(
  expect: Expect,
  page: Page,
  entryId: string = 'nav-signin',
): Promise<void> {
  await page.getByTestId(entryId).click()
  await expect(page.getByTestId('auth-surface')).toBeVisible()
}

/**
 * Sign out through the shell control and wait for the anonymous state to settle.
 *
 * The name in the shell is bound to an account, so its removal confirms the
 * daemon processed the sign-out before the next navigation reconnects.
 *
 * @param expect The calling spec's Playwright expect.
 * @param page The page showing the signed-in shell.
 * @param options The identity marker removed when sign-out reaches the shell.
 */
export async function logout(
  expect: Expect,
  page: Page,
  { profileId = 'nav-profile-name' }: { profileId?: string } = {},
): Promise<void> {
  await page.getByTestId('nav-logout').click()
  await expect(page.getByTestId(profileId)).toHaveCount(0)
}

/**
 * Register a verified-email account that deliberately has no password, from the
 * profile's own sign-in surface, and land on the profile signed in.
 *
 * @param expect The calling spec's Playwright expect.
 * @param page Page starting from any location (it navigates to '/profile').
 * @returns The account's confirmed address.
 */
export async function registerEmailOnly(
  expect: Expect,
  page: Page,
): Promise<string> {
  const email = uniqueEmail()
  await gotoPage(expect, page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await registerWithoutPassword(expect, page, email)
  await expect(page.getByTestId('profile-name')).toBeVisible()

  return email
}

/**
 * Sign in a FRESH number end to end: pick the SMS channel, accept the terms, read
 * the texted code, confirm it, and acknowledge the finished panel.
 *
 * Written for a number with no account, which is what makes it linear: an unknown
 * identifier means the register intent, and under that intent choosing a channel
 * only STORES the choice — the terms screen is what sends. An existing number
 * skips the terms, so this helper is not the way to sign one back in.
 *
 * @param expect The calling spec's Playwright expect.
 * @param page The page with the auth surface mounted.
 * @param phone The fresh number to mint an account for.
 * @returns The code the sign-in was proven with, for a later wait on this
 *          number that has to pass this one over.
 */
export async function signInByPhone(
  expect: Expect,
  page: Page,
  phone: string,
): Promise<string> {
  await typeInto(page.getByTestId('auth-identifier'), phone)
  await clickSubmit(expect, page.getByTestId('auth-channel-sms'))
  await page.getByTestId('auth-consent-accept').check()
  await clickSubmit(expect, page.getByTestId('auth-submit'))

  // The request is asynchronous for every channel (HIL-492): its ack means only
  // "accepted", and the surface advances when the code agent signals that a code
  // really went out. So the code field appearing is what says the code has been
  // issued — and only then is there an artifact to read.
  await expect(page.getByTestId('auth-code')).toBeVisible()
  const code = await waitForSmsCode(phone)
  await typeInto(page.getByTestId('auth-code'), code)
  await clickSubmit(expect, page.getByTestId('auth-submit'))
  await waitDoneSettled(expect, page)
  await continueFromDone(expect, page)

  return code
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
 * Opens the auth-gate modal through the shell's sign-in button and registers,
 * code and password steps included; creating the account upgrades the live
 * session in place, so the page is left on '/' signed in with the self user
 * resolved. This is the standard way an authenticated spec obtains its user
 * under the session≠user model.
 *
 * @param expect The calling spec's Playwright expect.
 * @param page Page starting from any location (it navigates to '/')
 * @param options The entry control used to open sign-in.
 * @returns The registered account's email, display name, and durable user id
 */
export async function signUp(
  expect: Expect,
  page: Page,
  { entryId = 'nav-signin' }: { entryId?: string } = {},
): Promise<SignedInUser> {
  const email = uniqueEmail()

  await gotoPage(expect, page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await openSignIn(expect, page, entryId)
  await register(expect, page, email)

  const name = nameFromEmail(email)
  // Registration resolves the self user in place; assert the name to be sure
  // the session upgrade landed before reading the id.
  await expect(page.getByTestId('self-user')).toHaveText(name)
  const userId = Number(await page.getByTestId('self-user-id').textContent())

  return { email, name, userId }
}

/**
 * Change an existing password through operation confirmation and a code from Mailpit.
 *
 * @param expect The calling spec's Playwright expect.
 * @param page The signed-in browser.
 * @param options The account address, current and new passwords, and session choice.
 */
export async function changePassword(
  expect: Expect,
  page: Page,
  {
    email,
    currentPassword,
    newPassword,
    signOutOthers = true,
  }: {
    email: string
    currentPassword: string
    newPassword: string
    signOutOthers?: boolean
  },
): Promise<void> {
  await gotoPage(expect, page, '/profile/sign-in')
  await clickSubmit(expect, page.getByTestId('profile-password-change'))
  await expect(page.getByTestId('profile-password-modal')).toBeVisible()
  if (await page.getByTestId('step-up-password').count()) {
    await typeInto(page.getByTestId('step-up-password'), currentPassword)
    await clickSubmit(
      expect,
      page.getByTestId('profile-password-step-up-confirm'),
    )
  }
  await clickSubmit(expect, page.getByTestId('profile-password-send-code'))
  await expect(page.getByTestId('profile-password-code')).toBeVisible()
  await typeInto(
    page.getByTestId('profile-password-code'),
    await waitForMailCode(email, 'Confirm changing your password'),
  )
  await clickSubmit(expect, page.getByTestId('profile-password-confirm-code'))
  await expect(page.getByTestId('profile-password-new')).toBeVisible()
  await typeInto(page.getByTestId('profile-password-new'), newPassword)
  await page
    .getByTestId('profile-password-sign-out-others')
    .setChecked(signOutOthers)
  await clickSubmit(expect, page.getByTestId('profile-password-save'))
  await expect(page.getByTestId('profile-password-outcome')).toBeVisible()
}

import type { Expect, Page } from '@playwright/test'
import {
  PASSWORD,
  expectSessionRotation,
  clickSubmit,
  typeInto,
  gotoPage,
  signInByPhone,
  uniqueEmail,
  logout as logoutSession,
  signUp as signUpSession,
  type SignedInUser,
} from '../../../../../framework/frontend/e2e/index.js'
import {
  uniquePhone,
  waitForAnotherSmsCode,
} from '../../../../../framework/frontend/scripts/standSms.mjs'
import { waitForMailCode } from '../../../../../framework/frontend/scripts/mail.mjs'

// Chat alone seeds an orphan cookie to exercise its anonymous identity line.
/**
 * A session token of the minted shape (32 lowercase hex, SessionToken::isValid)
 * that names no session on the server.
 *
 * Well-formed on purpose: a malformed value is simply replaced by a freshly minted
 * one on the 101, which would make the browser arrive as if it had carried no
 * cookie at all. Unique per run for the reason uniqueEmail() is: the stand is
 * shared across specs and retries, and a repeated value could name a session
 * another test really holds.
 *
 * @returns The orphan token.
 */
export function orphanSessionToken(): string {
  const noise = (): string =>
    Math.floor(Math.random() * 0x100000000)
      .toString(16)
      .padStart(8, '0')

  return Date.now().toString(16).padStart(16, '0') + noise() + noise()
}

// Chat opens sign-in through its message composer.
export async function signUp(
  expect: Expect,
  page: Page,
): Promise<SignedInUser> {
  return signUpSession(expect, page, { entryId: 'message-signin' })
}

// Chat waits for token rotation before interacting with the new socket.
export async function logout(expect: Expect, page: Page): Promise<void> {
  await expectSessionRotation(expect, page, async () => {
    await logoutSession(expect, page, { profileId: 'nav-profile' })
  })
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
}

/** The subject of the add-password confirmation letter. */
const EMAIL_ADD_SUBJECT = 'Confirm your email address'

// Chat alone composes an account with both a phone and a verified password address.
/**
 * Establish an account with two ways in: a phone (SMS) and a password on an email
 * address the server itself has verified.
 *
 * A spec that needs only a verified address takes `signUp`: the registration code
 * proves the address, and registration writes it verified (HIL-825). This helper
 * is for a spec that needs a second way in beside the password, and it builds the
 * account the long way — the way the product actually offers:
 *
 *   1. sign in by SMS with a fresh number, which mints a user with no password and
 *      no email at all (MainPage::handleConfirmPhoneCode creates the user if new);
 *   2. walk that user through the profile's add-password wizard, which mails a
 *      code to a chosen address and, on the confirm, writes a password identity
 *      on it and marks it verified (ProfilePage::handleConfirmAddPassword).
 *
 * Both codes are read from the stand's interceptors through the shared
 * standSms.mjs and mail.mjs, never from a test-only backdoor: the flow under the
 * account is the product's own, so a change that breaks it for a user breaks it
 * here too.
 *
 * @param expect The calling spec's Playwright expect.
 * @param page Page starting from any location (it navigates to '/' and '/profile/sign-in').
 * @returns The account's proven email, its display name (User and six digits,
 *          as a phone sign-up names the account), and its durable user id.
 */
export async function signUpWithVerifiedEmail(
  expect: Expect,
  page: Page,
): Promise<SignedInUser> {
  const phone = uniquePhone()

  await gotoPage(expect, page, '/')
  await expect(page.getByTestId('conn-state')).toHaveText('connected')
  await page.getByTestId('message-signin').click()
  const loginCode = await signInByPhone(expect, page, phone)

  await expect(page.getByTestId('self-user')).toHaveText(/^User[1-9]\d{5}$/)
  const name = await page.getByTestId('self-user').innerText()
  const userId = Number(await page.getByTestId('self-user-id').textContent())

  // Step 1 of the wizard: name the address. It is only offered to a user with
  // neither a password nor a verified email — which is exactly what an SMS-minted
  // account is.
  const email = uniqueEmail()
  await gotoPage(expect, page, '/profile/sign-in')
  await clickSubmit(expect, page.getByTestId('profile-sign-in-add'))

  // Adding a way in is a protected operation (HIL-1138), and the strongest proof
  // this account holds is its phone: the dialog opens on a code step, and the
  // code is the SECOND text to this number - the sign-in's still sits in the
  // mailbox, so the wait passes that one over.
  await expect(page.getByTestId('step-up-code')).toBeVisible()
  await typeInto(
    page.getByTestId('step-up-code'),
    await waitForAnotherSmsCode(phone, loginCode),
  )
  await clickSubmit(
    expect,
    page.getByTestId('profile-sign-in-add-step-up-confirm'),
  )
  await clickSubmit(expect, page.getByTestId('profile-sign-in-choose-password'))
  await typeInto(page.getByTestId('profile-add-password-email'), email)
  await clickSubmit(expect, page.getByTestId('profile-add-password-request'))

  // Step 2: prove it with the mailed code and set a password on it. The section
  // flips to change-mode when the server fans profile_password_updated back, so
  // the Change control appearing confirms the identity arrived verified.
  await expect(page.getByTestId('profile-add-password-code')).toBeVisible()
  await typeInto(
    page.getByTestId('profile-add-password-code'),
    await waitForMailCode(email, EMAIL_ADD_SUBJECT),
  )
  await typeInto(page.getByTestId('profile-add-password-new'), PASSWORD)
  await typeInto(page.getByTestId('profile-add-password-confirm'), PASSWORD)
  await clickSubmit(expect, page.getByTestId('profile-add-password-save'))
  await expect(page.getByTestId('profile-sign-in-add-modal')).toHaveCount(0)
  await expect(page.getByTestId('profile-password-change')).toBeVisible()

  return { email, name, userId }
}

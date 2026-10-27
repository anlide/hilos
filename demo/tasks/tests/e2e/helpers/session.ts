import type { Expect, Page } from '@playwright/test'
import {
  PASSWORD,
  clickSubmit,
  typeInto,
  waitDoneSettled,
} from '../../../../../framework/frontend/e2e/index.js'
import { waitForMailCode } from '../../../../../framework/frontend/scripts/mail.mjs'

// Tasks alone composes finishWithPasskey for its passkey scenarios.
/**
 * Take the passkey ending of the registration password step (HIL-1104).
 *
 * The third ending of the same screen: the device makes a key, and the account is
 * created on it with the proved address beside it and no password. The key comes
 * from whatever authenticator the page carries — a spec attaches a virtual one
 * first — and it settles where the other two endings settle, on the same landing.
 *
 * @param expect The calling spec's Playwright expect.
 * @param page The page sitting on the registration password step.
 */
export async function finishWithPasskey(
  expect: Expect,
  page: Page,
): Promise<void> {
  await clickSubmit(expect, page.getByTestId('auth-complete-passkey'))
  await waitDoneSettled(expect, page)
}

// Tasks alone composes createAccountWithPasskey for its passkey scenarios.
/**
 * Create an account without an address, through consent and the device key (HIL-1106).
 *
 * @param expect The calling spec's Playwright expect.
 * @param page The page with an empty sign-in field and a platform authenticator.
 */
export async function createAccountWithPasskey(
  expect: Expect,
  page: Page,
): Promise<void> {
  await clickSubmit(expect, page.getByTestId('auth-create-passkey'))
  await page.getByTestId('auth-consent-accept').check()
  await clickSubmit(expect, page.getByTestId('auth-submit'))
  await waitDoneSettled(expect, page)
}

// Tasks alone composes addPasswordFromAddressStep for its passkey scenarios.
/**
 * Walk the add window's password steps from their first one: name the address,
 * prove it with the mailed code and set a password on it (HIL-1166).
 *
 * The window must already stand on `profile-add-password-email` — the step an
 * account without a confirmed address opens on, whether it chose Password in the
 * window or came straight from a button that named the way. Done when the window
 * has closed on the server's profile_password_updated.
 *
 * @param expect The calling spec's Playwright expect.
 * @param page Page whose add window stands on the address step.
 * @param email The address the password goes on; a fresh one, never seen by the stand.
 */
export async function addPasswordFromAddressStep(
  expect: Expect,
  page: Page,
  email: string,
): Promise<void> {
  await expect(page.getByTestId('profile-add-password-email')).toBeVisible()
  await typeInto(page.getByTestId('profile-add-password-email'), email)
  await clickSubmit(expect, page.getByTestId('profile-add-password-request'))
  await expect(page.getByTestId('profile-add-password-code')).toBeVisible()
  await typeInto(
    page.getByTestId('profile-add-password-code'),
    await waitForMailCode(email, 'Confirm your email address'),
  )
  await typeInto(page.getByTestId('profile-add-password-new'), PASSWORD)
  await typeInto(page.getByTestId('profile-add-password-confirm'), PASSWORD)
  await clickSubmit(expect, page.getByTestId('profile-add-password-save'))
  await expect(page.getByTestId('profile-sign-in-add-modal')).toHaveCount(0)
}

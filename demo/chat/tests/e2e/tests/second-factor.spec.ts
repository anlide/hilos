// Two-step verification end to end (HIL-494): an authenticator app connected in
// the profile with its first code, the code step it adds to the next sign-in and
// the browser it may trust after it, a backup code that signs in once, the
// delayed removal announced by mail and canceled by the link in it, and the
// enrolment an administrator's policy requires on the way in. The codes are
// computed here from the secret the enrolment screen prints, as any app would.
import { test, expect, type Browser, type Page } from '@playwright/test'

import { shownByTestId } from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant'
import { waitForMailTo } from '../helpers/mail'
import { enableEmailChannel } from '../helpers/notifications'
import { expectPageReady, gotoAuthReturn, gotoPage } from '../helpers/page'
import { connectFirstApp } from '../helpers/secondFactor'
import {
  PASSWORD,
  clickSubmit,
  enterIdentifierAndPassword,
  logout,
  signUp,
  signUpWithVerifiedEmail,
  typeInto,
} from '../helpers/session'
import { nextTotpCode, totpStep } from '../helpers/totp'

/** The subject of the notice that a removal was asked for. */
const RESET_REQUESTED_SUBJECT = 'Removal of two-step verification requested'

/** The "it was not me" link in that notice. */
const CANCEL_LINK_PATTERN =
  /(https?:\/\/\S+\/auth\/second-factor\/cancel\?token=\S+)/

/**
 * Sign in with a password and stop where the surface asks for the second factor.
 *
 * @param page An anonymous page.
 * @param email The account's address.
 */
async function signInToCodeStep(page: Page, email: string): Promise<void> {
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await enterIdentifierAndPassword(page, email, PASSWORD)
  await clickSubmit(page.getByTestId('auth-submit'))
  await expect(page.getByTestId('auth-heading')).toHaveText(
    'Two-step verification',
  )
}

/**
 * Submit the code step and wait for the surface to close on the sign-in.
 *
 * @param page The page standing on the code step.
 */
async function submitCodeStep(page: Page): Promise<void> {
  await clickSubmit(page.getByTestId('auth-submit'))
  await expect(page.getByTestId('auth-surface')).toHaveCount(0)
  await expectPageReady(page)
}

/**
 * A page in a browser of its own: no cookie, so no trust either.
 *
 * @param browser The test's browser.
 */
async function freshPage(browser: Browser): Promise<Page> {
  return (await browser.newContext()).newPage()
}

/**
 * Set who must use a second factor on the administration page.
 *
 * @param page An administrator's page.
 * @param value The value of the setting.
 * @param shown What the row says once it is stored.
 */
async function setRequired(
  page: Page,
  value: string,
  shown: string,
): Promise<void> {
  await gotoPage(page, '/hilos/security/2fa')
  // The table draws a row and its card; the one on screen is the one to press.
  await clickSubmit(
    shownByTestId(page, 'hilos-2fa-edit-auth.second_factor.required'),
  )
  await page.getByTestId('hilos-2fa-input').selectOption(value)
  await clickSubmit(page.getByTestId('hilos-2fa-save'))
  await expect(
    shownByTestId(page, 'hilos-2fa-value-auth.second_factor.required'),
  ).toHaveText(shown)
}

test.describe('two-step verification', () => {
  test('asks the code on the next sign-in, and not again on a trusted browser', async ({
    page,
  }) => {
    // The sign-in code has to wait out the step the enrolment spent, up to
    // thirty seconds, which alone is the cap a test of DOM work is given.
    test.slow()

    const user = await signUp(page)
    const app = await connectFirstApp(page)

    await logout(page)
    await signInToCodeStep(page, user.email)
    const { code, step } = await nextTotpCode(app.secret, app.spent)
    await typeInto(page.getByTestId('auth-code'), code)
    await page.getByTestId('auth-trust-device').check()
    await submitCodeStep(page)
    expect(step).toBeGreaterThan(app.spent)

    // The browser is trusted now: the same password lets it straight in.
    await logout(page)
    await gotoPage(page, '/profile')
    await enterIdentifierAndPassword(page, user.email, PASSWORD)
    await clickSubmit(page.getByTestId('auth-submit'))
    await expect(page.getByTestId('auth-surface')).toHaveCount(0)
  })

  test('takes a backup code once', async ({ page, browser }) => {
    const user = await signUp(page)
    const app = await connectFirstApp(page)
    const backupCode = app.backupCodes[0] ?? ''

    const first = await freshPage(browser)
    await signInToCodeStep(first, user.email)
    await first.getByTestId('auth-backup-toggle').click()
    await typeInto(first.getByTestId('auth-code'), backupCode)
    await submitCodeStep(first)

    const second = await freshPage(browser)
    await signInToCodeStep(second, user.email)
    await second.getByTestId('auth-backup-toggle').click()
    await typeInto(second.getByTestId('auth-code'), backupCode)
    await clickSubmit(second.getByTestId('auth-submit'))
    await expect(second.getByTestId('auth-error')).toHaveText('Invalid code')
  })

  test('announces a removal by mail, and its link cancels it without signing in', async ({
    page,
    browser,
  }) => {
    // The notice is mandatory, yet it still rides only a channel the stand has
    // switched on, to an address the person has proven there.
    const admin = await freshPage(browser)
    await signUpAdmin(admin)
    await enableEmailChannel(admin)
    const user = await signUpWithVerifiedEmail(page)
    await connectFirstApp(page)

    await clickSubmit(page.getByTestId('profile-2fa-reset-request'))
    await clickSubmit(page.getByTestId('profile-2fa-reset-submit'))
    await expect(page.getByTestId('profile-2fa-reset-pending')).toBeVisible()

    const mail = await waitForMailTo(user.email, RESET_REQUESTED_SUBJECT)
    const link = CANCEL_LINK_PATTERN.exec(mail.text)?.[1]
    expect(link).toBeTruthy()
    const url = new URL(link ?? '')

    const stranger = await freshPage(browser)
    await gotoAuthReturn(
      stranger,
      `${url.pathname}${url.search}`,
      'auth-second-factor-cancel',
    )
    await expect(
      stranger.getByTestId('auth-second-factor-cancel-done'),
    ).toBeVisible()

    // The owner's open profile hears of it by itself.
    await expect(page.getByTestId('profile-2fa-reset-pending')).toHaveCount(0)
    await expect(page.getByTestId('profile-2fa-reset-request')).toBeVisible()
  })

  test('sets the factor up on the way in when the administrator requires it', async ({
    page,
    browser,
  }) => {
    const member = await freshPage(browser)
    const user = await signUp(member)
    await logout(member)

    await signUpAdmin(page)
    await setRequired(page, 'everyone', 'Everyone')
    // The policy outlives this test on the shared stand, so it goes back
    // whatever happens in between.
    try {
      await gotoPage(member, '/profile')
      await enterIdentifierAndPassword(member, user.email, PASSWORD)
      await clickSubmit(member.getByTestId('auth-submit'))
      await expect(member.getByTestId('auth-setup-lead')).toBeVisible()
      const secret = (
        await member.getByTestId('auth-setup-secret').textContent()
      )?.trim()
      expect(secret).toBeTruthy()
      const { code } = await nextTotpCode(secret ?? '', totpStep() - 1)
      await typeInto(member.getByTestId('auth-code'), code)
      await clickSubmit(member.getByTestId('auth-submit'))

      await expect(
        member.getByTestId('backup-codes-list').locator('li'),
      ).toHaveCount(10)
      await member.getByTestId('backup-codes-saved').check()
      await submitCodeStep(member)
    } finally {
      await setRequired(page, 'none', 'Nobody')
    }
  })
})

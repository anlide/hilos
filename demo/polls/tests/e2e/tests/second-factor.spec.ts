// Two-step verification end to end (HIL-494): an authenticator app connected in
// the profile with its first code, the code step it adds to the next sign-in and
// the browser it may trust after it, a backup code that signs in once, and the
// enrolment an administrator's policy requires on the way in. The removal notice
// rides notification delivery, tested in chat's second-factor-mail.spec.ts. The codes are
// computed here from the secret the enrolment screen prints, as any app would.
import { test, expect, type Browser, type Page } from '@playwright/test'

import {
  connectFirstApp,
  shownByTestId,
} from '../../../../../framework/frontend/e2e/index.js'
import {
  nextTotpCode,
  totpStep,
} from '../../../../../framework/frontend/scripts/totp.mjs'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { setAdminViewMode } from '../helpers/adminViewMode.js'
import { expectPageReady, gotoPage, PAGE_READY } from '../helpers/page.js'
import {
  PASSWORD,
  clickSubmit,
  enterIdentifierAndPassword,
  logout,
  signUp,
  typeInto,
} from '../helpers/session.js'

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
    const user = await signUp(page)
    await gotoPage(page, '/profile/security')
    const app = await connectFirstApp(page, PASSWORD)

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
    await gotoPage(page, '/profile/security')
    const app = await connectFirstApp(page, PASSWORD)
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

test.describe('in the admin view mode', () => {
  test.afterEach(() => setAdminViewMode(false))

  test('a guest opens a two-factor setting modal and finds save disabled by the view mode', async ({
    page,
  }) => {
    await setAdminViewMode(true)
    await gotoPage(page, '/hilos/security/2fa', PAGE_READY)
    await clickSubmit(
      shownByTestId(page, 'hilos-2fa-edit-auth.second_factor.required'),
    )
    await expect(page.getByTestId('hilos-2fa-input')).toBeVisible()
    await expect(
      page.getByTestId('modal').getByTestId('hilos-hidden'),
    ).toHaveCount(0)
    const save = page.getByTestId('hilos-2fa-save')
    await expect(save).toBeDisabled()
    await expect(save).toHaveAttribute(
      'aria-describedby',
      /(^| )hilos-view-mode-strip-text( |$)/,
    )
    await page.keyboard.press('Escape')
    await expect(page.getByTestId('modal')).toBeHidden()
  })
})

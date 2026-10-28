// Two-step verification end to end (HIL-494): an authenticator app connected in
// the profile with its first code, the code step it adds to the next sign-in and
// the browser it may trust after it, a backup code that signs in once, and the
// enrolment an administrator's policy requires on the way in, and the factor an
// administrator turns off for everyone and back on (HIL-1303). The removal notice
// rides notification delivery, tested in chat's second-factor-mail.spec.ts. The codes are
// computed here from the secret the enrolment screen prints, as any app would.
import { test, expect, type Browser, type Page } from '@playwright/test'

import {
  PASSWORD,
  enterIdentifierAndPassword,
  logout,
  createTestUser,
  connectFirstApp,
  shownByTestId,
  clickSubmit,
  typeInto,
  expectPageReady,
  gotoPage,
  PAGE_READY,
  createTestAdmin,
} from '../../../../../framework/frontend/e2e/index.js'
import {
  nextTotpCode,
  totpStep,
} from '../../../../../framework/frontend/scripts/totp.mjs'
import { setAdminViewMode } from '../../../../../framework/frontend/scripts/adminViewMode.mjs'

/**
 * Sign in with a password and stop where the surface asks for the second factor.
 *
 * @param page An anonymous page.
 * @param email The account's address.
 */
async function signInToCodeStep(page: Page, email: string): Promise<void> {
  await gotoPage(expect, page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await enterIdentifierAndPassword(expect, page, email, PASSWORD)
  await clickSubmit(expect, page.getByTestId('auth-submit'))
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
  await clickSubmit(expect, page.getByTestId('auth-submit'))
  await expect(page.getByTestId('auth-surface')).toHaveCount(0)
  await expectPageReady(expect, page)
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
  await gotoPage(expect, page, '/hilos/security/2fa')
  // The table draws a row and its card; the one on screen is the one to press.
  await clickSubmit(
    expect,
    shownByTestId(page, 'hilos-2fa-edit-auth.second_factor.required'),
  )
  await page.getByTestId('hilos-2fa-input').selectOption(value)
  await clickSubmit(expect, page.getByTestId('hilos-2fa-save'))
  await expect(
    shownByTestId(page, 'hilos-2fa-value-auth.second_factor.required'),
  ).toHaveText(shown)
}

test.describe('two-step verification', () => {
  // Unstable since it moved here from chat (HIL-1325): red on the first attempt in
  // runs 0994, 1005 and 1013, 2 of the 16 polls runs after the move, where its chat
  // original was red once in 64 (0975). Each time the trusted browser was not let
  // straight in: auth-surface still stood 5.1-5.75 s after the password. Parked by
  // the owner on 09.10.2026 (HOTFIX) without a diagnosis.
  // Quarantine: F-010; original reason and parking date above.
  test(
    'asks the code on the next sign-in, and not again on a trusted browser',
    { tag: '@quarantine:F-010' },
    async ({ page }) => {
      const user = await createTestUser(expect, page)
      await gotoPage(expect, page, '/profile/security')
      const app = await connectFirstApp(page, PASSWORD)

      await logout(expect, page)
      await signInToCodeStep(page, user.email)
      const { code, step } = await nextTotpCode(app.secret, app.spent)
      await typeInto(page.getByTestId('auth-code'), code)
      await page.getByTestId('auth-trust-device').check()
      await submitCodeStep(page)
      expect(step).toBeGreaterThan(app.spent)

      // The browser is trusted now: the same password lets it straight in.
      await logout(expect, page)
      await gotoPage(expect, page, '/profile')
      await enterIdentifierAndPassword(expect, page, user.email, PASSWORD)
      await clickSubmit(expect, page.getByTestId('auth-submit'))
      await expect(page.getByTestId('auth-surface')).toHaveCount(0)
    },
  )

  test('takes a backup code once', async ({ page, browser }) => {
    const user = await createTestUser(expect, page)
    await gotoPage(expect, page, '/profile/security')
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
    await clickSubmit(expect, second.getByTestId('auth-submit'))
    await expect(second.getByTestId('auth-error')).toHaveText('Invalid code')
  })

  test('sets the factor up on the way in when the administrator requires it', async ({
    page,
    browser,
  }) => {
    const member = await freshPage(browser)
    const user = await createTestUser(expect, member)
    await logout(expect, member)

    await createTestAdmin(expect, page)
    await setRequired(page, 'everyone', 'Everyone')
    // The policy outlives this test on the shared stand, so it goes back
    // whatever happens in between.
    try {
      await gotoPage(expect, member, '/profile')
      await enterIdentifierAndPassword(expect, member, user.email, PASSWORD)
      await clickSubmit(expect, member.getByTestId('auth-submit'))
      await expect(member.getByTestId('auth-setup-lead')).toBeVisible()
      const secret = (
        await member.getByTestId('auth-setup-secret').textContent()
      )?.trim()
      expect(secret).toBeTruthy()
      const { code } = await nextTotpCode(secret ?? '', totpStep() - 1)
      await typeInto(member.getByTestId('auth-code'), code)
      await clickSubmit(expect, member.getByTestId('auth-submit'))

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

/** The first switch of the two-step verification tab. */
const ENABLED_SWITCH = 'hilos-2fa-switch-auth.second_factor.enabled'

/**
 * Turn the second factor off on the administration page: the switch opens its
 * window, the window asks the administrator's password, then Turn off.
 *
 * @param page An administrator's page whose account holds a password.
 */
async function turnSecondFactorOff(page: Page): Promise<void> {
  await gotoPage(expect, page, '/hilos/security/2fa')
  const on = shownByTestId(page, ENABLED_SWITCH)
  await expect(on).toBeChecked()
  await clickSubmit(expect, on)
  await expect(page.getByTestId('hilos-2fa-disable-window')).toBeVisible()
  await typeInto(page.getByTestId('step-up-password'), PASSWORD)
  await clickSubmit(
    expect,
    page.getByTestId('hilos-2fa-disable-step-up-confirm'),
  )
  await clickSubmit(expect, page.getByTestId('hilos-2fa-disable-confirm'))
  await expect(page.getByTestId('hilos-2fa-disable-window')).toHaveCount(0)
  await expect(shownByTestId(page, ENABLED_SWITCH)).not.toBeChecked()
}

/**
 * Turn the second factor back on: the switch alone, no window.
 *
 * @param page An administrator's page on the two-step verification tab.
 */
async function turnSecondFactorOn(page: Page): Promise<void> {
  const off = shownByTestId(page, ENABLED_SWITCH)
  await clickSubmit(expect, off)
  await expect(off).toBeChecked()
}

test.describe('turned off for everyone', () => {
  test('lets a person with an app in without the code, says so in the profile, and asks again once it is back on', async ({
    page,
    browser,
  }) => {
    const member = await freshPage(browser)
    const user = await createTestUser(expect, member)
    await gotoPage(expect, member, '/profile/security')
    await connectFirstApp(member, PASSWORD)
    await logout(expect, member)

    await createTestAdmin(expect, page)
    try {
      await turnSecondFactorOff(page)

      await gotoPage(expect, member, '/profile')
      await expect(member.getByTestId('auth-surface')).toBeVisible()
      await enterIdentifierAndPassword(expect, member, user.email, PASSWORD)
      await clickSubmit(expect, member.getByTestId('auth-submit'))
      await expect(member.getByTestId('auth-surface')).toHaveCount(0)
      await gotoPage(expect, member, '/profile/security')
      await expect(member.getByTestId('hilos-2fa-turned-off')).toBeVisible()

      await turnSecondFactorOn(page)
      await logout(expect, member)
      await signInToCodeStep(member, user.email)
    } finally {
      // The policy outlives this test on the shared stand, so it goes back on
      // whatever happens in between.
      await gotoPage(expect, page, '/hilos/security/2fa')
      if (!(await shownByTestId(page, ENABLED_SWITCH).isChecked())) {
        await turnSecondFactorOn(page)
      }
    }
  })
})

test.describe('in the admin view mode', () => {
  test.afterEach(() => setAdminViewMode(false))

  test('a guest opens a two-factor setting modal and finds save disabled by the view mode', async ({
    page,
  }) => {
    await setAdminViewMode(true)
    await gotoPage(expect, page, '/hilos/security/2fa', PAGE_READY)
    await clickSubmit(
      expect,
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

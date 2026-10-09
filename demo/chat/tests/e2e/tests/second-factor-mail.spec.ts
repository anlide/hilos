// Mandatory removal notice of two-step verification rides notification delivery,
// which is enabled only in chat and binance-btc-tracker.
import { test, expect, type Browser, type Page } from '@playwright/test'

import {
  connectFirstApp,
  enableEmailChannel,
} from '../../../../../framework/frontend/e2e/index.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { waitForMailTo } from '../helpers/mail.js'
import { gotoAuthReturn, gotoPage } from '../helpers/page.js'
import { clickSubmit, PASSWORD, signUp } from '../helpers/session.js'

/** The subject of the notice that a removal was asked for. */
const RESET_REQUESTED_SUBJECT = 'Removal of two-step verification requested'

/** The "it was not me" link in that notice. */
const CANCEL_LINK_PATTERN =
  /(https?:\/\/\S+\/auth\/second-factor\/cancel\?token=\S+)/

/**
 * A page in a browser of its own: no cookie, so no trust either.
 *
 * @param browser The test's browser.
 */
async function freshPage(browser: Browser): Promise<Page> {
  return (await browser.newContext()).newPage()
}

test('announces a removal by mail, and its link cancels it without signing in', async ({
  page,
  browser,
}) => {
  // The notice is mandatory, yet it still rides only a channel the stand has
  // switched on, to an address the person has proven there.
  const admin = await freshPage(browser)
  await signUpAdmin(admin)
  await gotoPage(admin, '/hilos/communications')
  await enableEmailChannel(admin)
  const user = await signUp(page)
  await gotoPage(page, '/profile/security')
  await connectFirstApp(page, PASSWORD)

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

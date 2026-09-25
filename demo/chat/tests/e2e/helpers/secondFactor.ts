import { expect, type Page } from '@playwright/test'

import { gotoPage } from './page'
import { clickSubmit, typeInto } from './session'
import { nextTotpCode, totpStep } from './totp'

/** What connecting an app leaves behind: its secret, spent step, and backup codes. */
export interface ConnectedApp {
  secret: string
  spent: number
  backupCodes: string[]
}

/**
 * Connect the first authenticator app and close the enrollment modal.
 *
 * @param page A signed-in page.
 * @returns The connected app proof material used by later scenarios.
 */
export async function connectFirstApp(page: Page): Promise<ConnectedApp> {
  await gotoPage(page, '/profile/security')
  await expect(page.getByTestId('profile-2fa-off')).toBeVisible()

  await clickSubmit(page.getByTestId('profile-2fa-add'))
  await typeInto(page.getByTestId('profile-2fa-enroll-label'), 'Work phone')
  await clickSubmit(page.getByTestId('profile-2fa-enroll-submit'))

  const secret = (
    await page.getByTestId('profile-2fa-enroll-secret').textContent()
  )?.trim()
  expect(secret).toBeTruthy()
  const { code, step } = await nextTotpCode(secret ?? '', totpStep() - 1)
  await typeInto(page.getByTestId('profile-2fa-enroll-code'), code)
  await clickSubmit(page.getByTestId('profile-2fa-enroll-submit'))

  const list = page.getByTestId('backup-codes-list').locator('li')
  await expect(list).toHaveCount(10)
  const backupCodes = (await list.allTextContents()).map((text) => text.trim())
  await page.getByTestId('backup-codes-saved').check()
  await clickSubmit(page.getByTestId('profile-2fa-enroll-submit'))
  await expect(page.getByTestId('profile-2fa-enroll-more')).toBeVisible()
  await page.getByTestId('modal-close').click()

  await expect(page.getByTestId('profile-2fa-codes-left')).toHaveText(
    '10 of 10 left',
  )

  return { secret: secret ?? '', spent: step, backupCodes }
}

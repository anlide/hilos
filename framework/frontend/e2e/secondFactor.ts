import type { Page } from '@playwright/test'

import { nextTotpCode, totpStep } from '../scripts/totp.mjs'

/** What connecting an app leaves behind: its secret, spent step, and backup codes. */
export interface ConnectedApp {
  secret: string
  spent: number
  backupCodes: string[]
}

/**
 * Connect the first authenticator app and close the enrollment modal.
 *
 * Connecting the first app is a protected operation (HIL-1138): the dialog
 * opens on the confirmation step, and every account these scenarios start from
 * holds a password, so the password is what it asks for.
 *
 * The caller places the page on /profile/security before calling this helper.
 *
 * @param page A signed-in page on /profile/security whose account holds a password.
 * @param password The account password.
 * @returns The connected app proof material used by later scenarios.
 */
export async function connectFirstApp(
  page: Page,
  password: string,
): Promise<ConnectedApp> {
  const offNotice = page.getByTestId('profile-2fa-off')
  await offNotice.waitFor({ state: 'visible' })

  const add = page.getByTestId('profile-2fa-add')
  await add.scrollIntoViewIfNeeded()
  await add.waitFor({ state: 'visible' })
  if (!(await add.isEnabled())) {
    throw new Error('profile-2fa-add button is disabled')
  }
  await add.focus()
  await add.click()

  const passwordField = page.getByTestId('step-up-password')
  await passwordField.waitFor({ state: 'visible' })
  await passwordField.fill('')
  await passwordField.pressSequentially(password, { delay: 10 })

  const enrollSubmit = page.getByTestId('profile-2fa-enroll-submit')
  await enrollSubmit.scrollIntoViewIfNeeded()
  await enrollSubmit.waitFor({ state: 'visible' })
  if (!(await enrollSubmit.isEnabled())) {
    throw new Error('profile-2fa-enroll-submit is disabled')
  }
  await enrollSubmit.focus()
  await enrollSubmit.click()

  const labelField = page.getByTestId('profile-2fa-enroll-label')
  await labelField.waitFor({ state: 'visible' })
  await labelField.fill('')
  await labelField.pressSequentially('Work phone', { delay: 10 })

  await enrollSubmit.scrollIntoViewIfNeeded()
  await enrollSubmit.waitFor({ state: 'visible' })
  if (!(await enrollSubmit.isEnabled())) {
    throw new Error('profile-2fa-enroll-submit is disabled')
  }
  await enrollSubmit.focus()
  await enrollSubmit.click()

  const secretElement = page.getByTestId('profile-2fa-enroll-secret')
  await secretElement.waitFor({ state: 'visible' })
  const secret = (await secretElement.textContent())?.trim()
  if (!secret) {
    throw new Error('profile-2fa-enroll-secret is empty')
  }

  const { code, step } = await nextTotpCode(secret, totpStep() - 1)
  const codeField = page.getByTestId('profile-2fa-enroll-code')
  await codeField.waitFor({ state: 'visible' })
  await codeField.fill('')
  await codeField.pressSequentially(code, { delay: 10 })

  await enrollSubmit.scrollIntoViewIfNeeded()
  await enrollSubmit.waitFor({ state: 'visible' })
  if (!(await enrollSubmit.isEnabled())) {
    throw new Error('profile-2fa-enroll-submit is disabled')
  }
  await enrollSubmit.focus()
  await enrollSubmit.click()

  await page.getByTestId('backup-codes-list').waitFor({ state: 'visible' })
  await page.waitForFunction(() => {
    const items = document.querySelectorAll('[data-id="backup-codes-list"] li')

    return items.length === 10
  })
  const list = page.getByTestId('backup-codes-list').locator('li')
  const backupCodes = (await list.allTextContents()).map((text) => text.trim())
  if (backupCodes.length !== 10) {
    throw new Error(`expected 10 backup codes, got ${backupCodes.length}`)
  }

  const savedCheckbox = page.getByTestId('backup-codes-saved')
  await savedCheckbox.waitFor({ state: 'visible' })
  await savedCheckbox.check()

  await enrollSubmit.scrollIntoViewIfNeeded()
  await enrollSubmit.waitFor({ state: 'visible' })
  if (!(await enrollSubmit.isEnabled())) {
    throw new Error('profile-2fa-enroll-submit is disabled')
  }
  await enrollSubmit.focus()
  await enrollSubmit.click()

  const enrollMore = page.getByTestId('profile-2fa-enroll-more')
  await enrollMore.waitFor({ state: 'visible' })

  const modalClose = page.getByTestId('modal-close')
  await modalClose.waitFor({ state: 'visible' })
  await modalClose.click()

  const codesLeft = page.getByTestId('profile-2fa-codes-left')
  await codesLeft.waitFor({ state: 'visible' })
  await page.waitForFunction(() => {
    const el = document.querySelector('[data-id="profile-2fa-codes-left"]')

    return el?.textContent?.trim() === '10 of 10 left'
  })

  return { secret, spent: step, backupCodes }
}

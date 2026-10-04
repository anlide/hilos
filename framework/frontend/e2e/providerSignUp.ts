import type { Page } from '@playwright/test'

import type { StandOAuthAccount } from '../scripts/standOAuth.mjs'

import { signInAs } from './standOAuthUser.js'

/**
 * Finish the first provider sign-in after its return stopped on legal consent.
 * The provider identity and acceptance records do not exist until this submit.
 *
 * @param page The product tab on the provider consent step.
 */
export async function acceptTermsAsNewAccount(page: Page): Promise<void> {
  await page.getByTestId('legal-consent').waitFor({ state: 'visible' })
  const checkbox = page.getByTestId('auth-consent-accept')
  if ((await checkbox.count()) > 0) {
    await checkbox.check()
  }

  const submit = page.getByTestId('auth-submit')
  await submit.scrollIntoViewIfNeeded()
  await submit.waitFor({ state: 'visible' })
  await page.waitForFunction(() => {
    const button = document.querySelector<HTMLButtonElement>(
      '[data-id="auth-submit"]',
    )

    return button !== null && !button.disabled
  })
  if (!(await submit.isEnabled())) {
    throw new Error('Provider consent submit stayed disabled')
  }
  await submit.focus()
  await submit.click()

  await page.getByTestId('auth-continue').waitFor({ state: 'visible' })
  const heading = await page.getByTestId('auth-heading').textContent()
  if (heading?.trim() !== 'Your account is ready') {
    throw new Error(
      `Provider registration ended on an unexpected screen: ${heading}`,
    )
  }
  const continueButton = page.getByTestId('auth-continue')
  await continueButton.scrollIntoViewIfNeeded()
  await continueButton.waitFor({ state: 'visible' })
  if (!(await continueButton.isEnabled())) {
    throw new Error('Provider registration Continue stayed disabled')
  }
  await continueButton.focus()
  await continueButton.click()
  await page.getByTestId('auth-surface').waitFor({ state: 'hidden' })
}

/**
 * Complete a fresh provider account's first sign-in, including legal consent.
 * Start this before clicking the provider icon so the popup wait is armed.
 *
 * @param page The product tab whose provider icon will be clicked.
 * @param account The account the stand provider will let the person choose.
 */
export async function signUpAs(
  page: Page,
  account: StandOAuthAccount,
): Promise<void> {
  await signInAs(page, account)
  await acceptTermsAsNewAccount(page)
}

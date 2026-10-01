import { expect, test } from '@playwright/test'
import {
  clickSubmit,
  continueFromDone,
  PASSWORD,
  submitFirstPassword,
  submitRegistrationCode,
  typeInto,
  uniqueEmail,
} from '../helpers/session.js'
import { readRegisterCode } from '../helpers/mail.js'
import { gotoPage } from '../helpers/page.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import { sidewaysOverflow } from '../../../../../framework/frontend/e2e/index.js'

test('reads the current documents before registration and records both accepted revisions', async ({
  page,
}) => {
  const email = uniqueEmail()
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()
  await typeInto(page.getByTestId('auth-identifier'), email)
  await expect(page.getByTestId('auth-heading')).toHaveText(
    'Create your account',
  )
  await clickSubmit(page.getByTestId('auth-submit'))
  await expect(page.getByTestId('auth-heading')).toHaveText(
    'Before you continue',
  )
  await expect(page.getByTestId('auth-consent-identifier')).toHaveText(email)
  await expect(page.getByTestId('legal-consent-standard-toggle')).toHaveText(
    'Standard Hilos terms · 13 clauses',
  )
  // The third terms revision adds the demo's own availability clause (HIL-500).
  await expect(page.getByTestId('legal-consent-deviation')).toHaveCount(5)
  await expect(page.getByTestId('legal-consent-direction')).toHaveText([
    'stricter',
    'stricter',
    'stricter',
    'stricter',
    'looser',
  ])
  await expect(page.getByTestId('legal-consent-deviation')).toContainText([
    'Hilos standard:',
    'Hilos standard:',
    'Hilos standard:',
    'Hilos standard:',
    'Hilos standard:',
  ])
  await expect(page.getByTestId('auth-consent-accept')).not.toBeChecked()
  await expect(page.getByTestId('auth-submit')).toBeDisabled()
  await clickSubmit(page.getByTestId('legal-consent-standard-toggle'))
  await expect(page.getByTestId('legal-consent-standard-item')).toHaveCount(13)
  await clickSubmit(
    page.locator('[data-id="legal-consent-read"][data-document="terms"]'),
  )
  await expect(page.getByTestId('legal-consent-reading')).toBeVisible()
  await expect(page.getByTestId('legal-revision-clause')).toHaveCount(6)
  await expect(page.getByTestId('legal-revision-clause-deviation')).toHaveCount(
    4,
  )
  await page.setViewportSize({ width: 375, height: 812 })
  expect(await sidewaysOverflow(page)).toEqual([0, 0])
  await clickSubmit(page.getByTestId('legal-consent-back'))
  await expect(
    page.getByTestId('legal-consent-standard-toggle'),
  ).toHaveAttribute('aria-expanded', 'true')
  await page.getByTestId('auth-consent-accept').check()
  await clickSubmit(page.getByTestId('auth-submit'))
  await expect(page.getByTestId('auth-code')).toBeVisible()
  await submitRegistrationCode(page, await readRegisterCode(email))
  await submitFirstPassword(page, PASSWORD)
  await continueFromDone(page)
  await expect(page.getByTestId('profile-agreements-summary')).toHaveText(
    'Terms and privacy accepted',
  )
})

test('previews the same complete consent body from a legal document', async ({
  page,
}) => {
  await signUpAdmin(page)
  await gotoPage(page, '/hilos/legal/terms')
  await clickSubmit(page.getByTestId('legal-preview-consent'))
  const preview = page.getByTestId('legal-consent-preview')
  await expect(preview).toBeVisible()
  await expect(preview.getByTestId('legal-consent-deviation')).toHaveCount(5)
  await expect(preview.getByTestId('auth-consent-accept')).not.toBeChecked()
  await preview.getByTestId('auth-consent-accept').check()
  await expect(preview.getByTestId('auth-submit')).toHaveCount(0)
  await clickSubmit(page.getByTestId('legal-consent-preview-close'))
  await expect(preview).toBeHidden()
})

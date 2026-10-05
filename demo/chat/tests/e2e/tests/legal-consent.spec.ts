import { expect, test } from '@playwright/test'
import {
  clickSubmit,
  continueFromDone,
  logout,
  PASSWORD,
  submitFirstPassword,
  submitRegistrationCode,
  typeInto,
  uniqueEmail,
} from '../helpers/session.js'
import { readRegisterCode } from '../helpers/mail.js'
import { gotoPage } from '../helpers/page.js'
import { signUpAdmin } from '../helpers/adminGrant.js'
import {
  acceptTermsAsNewAccount,
  sidewaysOverflow,
  signInAs,
} from '../../../../../framework/frontend/e2e/index.js'
import { declareOAuthAccount } from '../../../../../framework/frontend/scripts/standOAuth.mjs'

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
  // Four Terms and two Privacy deviations, including the analytics declaration.
  await expect(page.getByTestId('legal-consent-deviation')).toHaveCount(6)
  await expect(page.getByTestId('legal-consent-direction')).toHaveText([
    'stricter',
    'stricter',
    'stricter',
    'stricter',
    'looser',
    'looser',
  ])
  await expect(page.getByTestId('legal-consent-deviation')).toContainText([
    'Hilos standard:',
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
  await expect(preview.getByTestId('legal-consent-deviation')).toHaveCount(6)
  await expect(preview.getByTestId('auth-consent-accept')).not.toBeChecked()
  await preview.getByTestId('auth-consent-accept').check()
  await expect(preview.getByTestId('auth-submit')).toHaveCount(0)
  await clickSubmit(page.getByTestId('legal-consent-preview-close'))
  await expect(preview).toBeHidden()
})

test('creates a new provider account only after consent and records both agreements', async ({
  page,
}) => {
  const email = uniqueEmail()
  const account = await declareOAuthAccount('github', {
    email,
    name: 'Provider Newcomer',
  })
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('auth-surface')).toBeVisible()

  const signingIn = signInAs(page, account)
  await clickSubmit(page.getByTestId('auth-icon-oauth-github'))
  await signingIn
  await expect(page.getByTestId('legal-consent')).toBeVisible()
  const plaque = page.getByTestId('auth-consent-provider')
  await expect(plaque).toHaveAttribute('data-provider', 'oauth:github')
  await expect(plaque).toHaveText(email)
  await expect(page.getByTestId('auth-consent-identifier')).toHaveCount(0)

  await acceptTermsAsNewAccount(page)
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await expect(page.getByTestId('profile-agreements-summary')).toHaveText(
    'Terms and privacy accepted',
  )
  await clickSubmit(page.getByTestId('profile-agreements-open'))
  const terms = page.locator(
    '[data-id="legal-agreement-row"][data-document="terms"]',
  )
  const privacy = page.locator(
    '[data-id="legal-agreement-row"][data-document="privacy"]',
  )
  await expect(terms.getByTestId('legal-agreement-state')).toContainText(
    'accepted',
  )
  await expect(privacy.getByTestId('legal-agreement-state')).toContainText(
    'accepted',
  )

  await logout(page)
  await gotoPage(page, '/profile')
  const returning = signInAs(page, account)
  await clickSubmit(page.getByTestId('auth-icon-oauth-github'))
  await returning
  await expect(page.getByTestId('profile-name')).toBeVisible()
  await expect(page.getByTestId('legal-consent')).toHaveCount(0)
})

test('Back from provider consent creates nothing and the next sign-in asks again', async ({
  page,
}) => {
  const account = await declareOAuthAccount('github', { email: uniqueEmail() })
  await gotoPage(page, '/profile')

  const first = signInAs(page, account)
  await clickSubmit(page.getByTestId('auth-icon-oauth-github'))
  await first
  await expect(page.getByTestId('legal-consent')).toBeVisible()
  await clickSubmit(page.getByTestId('auth-restart'))
  await expect(page.getByTestId('auth-identifier')).toBeVisible()
  await expect(page.getByTestId('auth-identifier')).toHaveValue('')
  await expect(page.getByTestId('auth-consent-provider')).toHaveCount(0)

  const second = signInAs(page, account)
  await clickSubmit(page.getByTestId('auth-icon-oauth-github'))
  await second
  await expect(page.getByTestId('legal-consent')).toBeVisible()
  await expect(page.getByTestId('auth-consent-provider')).toBeVisible()
})

test('provider consent names GitHub when it reports no email', async ({
  page,
}) => {
  const account = await declareOAuthAccount('github', { name: 'No Mail' })
  await gotoPage(page, '/profile')

  const signingIn = signInAs(page, account)
  await clickSubmit(page.getByTestId('auth-icon-oauth-github'))
  await signingIn
  await expect(page.getByTestId('legal-consent')).toBeVisible()
  const plaque = page.getByTestId('auth-consent-provider')
  await expect(plaque).toHaveAttribute('data-provider', 'oauth:github')
  await expect(plaque).toHaveText('GitHub')
})

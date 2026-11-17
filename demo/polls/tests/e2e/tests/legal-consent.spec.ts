import { expect, test } from '../test.js'

import { readRegisterCode } from '../../../../../framework/frontend/scripts/mail.mjs'
import {
  continueFromDone,
  logout,
  PASSWORD,
  submitFirstPassword,
  submitRegistrationCode,
  uniqueEmail,
  acceptTermsAsNewAccount,
  signInAs,
  clickSubmit,
  typeInto,
  gotoPage,
  nameFromEmail,
} from '../../../../../framework/frontend/e2e/index.js'
import { declareOAuthAccount } from '../../../../../framework/frontend/scripts/standOAuth.mjs'

test('registers after reading consent and inspects personal agreements history', async ({
  page,
}) => {
  const email = uniqueEmail()

  await test.step('reads the current documents before registration and records both accepted revisions', async () => {
    await gotoPage(expect, page, '/profile')
    await expect(page.getByTestId('auth-surface')).toBeVisible()
    await typeInto(page.getByTestId('auth-identifier'), email)
    await expect(page.getByTestId('auth-heading')).toHaveText(
      'Create your account',
    )
    await clickSubmit(expect, page.getByTestId('auth-submit'))
    await expect(page.getByTestId('auth-heading')).toHaveText(
      'Before you continue',
    )
    await expect(page.getByTestId('auth-consent-identifier')).toHaveText(email)
    await expect(page.getByTestId('legal-consent-standard-toggle')).toHaveText(
      'Standard Hilos terms · 13 clauses',
    )
    // One Terms (availability) and one Privacy (deletion) stricter deviation.
    await expect(page.getByTestId('legal-consent-deviation')).toHaveCount(2)
    await expect(page.getByTestId('legal-consent-direction')).toHaveText([
      'stricter',
      'stricter',
    ])
    await expect(page.getByTestId('legal-consent-deviation')).toContainText([
      'Hilos standard:',
      'Hilos standard:',
    ])
    await expect(page.getByTestId('auth-consent-accept')).not.toBeChecked()
    await expect(page.getByTestId('auth-submit')).toBeDisabled()
    await clickSubmit(expect, page.getByTestId('legal-consent-standard-toggle'))
    await expect(page.getByTestId('legal-consent-standard-item')).toHaveCount(
      13,
    )
    await clickSubmit(
      expect,
      page.locator('[data-id="legal-consent-read"][data-document="terms"]'),
    )
    await expect(page.getByTestId('legal-consent-reading')).toBeVisible()
    await expect(page.getByTestId('legal-revision-clause')).toHaveCount(6)
    await expect(
      page.getByTestId('legal-revision-clause-deviation'),
    ).toHaveCount(1)
    await clickSubmit(expect, page.getByTestId('legal-consent-back'))
    await expect(
      page.getByTestId('legal-consent-standard-toggle'),
    ).toHaveAttribute('aria-expanded', 'true')
    await expect(page.getByTestId('auth-submit')).toBeDisabled()
    await page.getByTestId('auth-consent-accept').check()
    await clickSubmit(expect, page.getByTestId('auth-submit'))
    await expect(page.getByTestId('auth-code')).toBeVisible()
    await submitRegistrationCode(expect, page, await readRegisterCode(email))
    await submitFirstPassword(expect, page, PASSWORD)
    await continueFromDone(expect, page)
    await gotoPage(expect, page, '/profile/agreements')
    await expect(page.getByTestId('legal-agreement-state')).toHaveText([
      /^\s*Revision of 7 October 2026 · accepted .+\s*$/,
      /^\s*Revision of 9 October 2026 · accepted .+\s*$/,
    ])
  })

  await test.step('reads personal agreements and compares published revisions', async () => {
    await gotoPage(expect, page, '/profile')
    await expect(page.getByTestId('profile-name')).toBeVisible()
    const name = nameFromEmail(email)
    await expect(page.getByTestId('profile-name')).toHaveText(name)
    await clickSubmit(
      expect,
      page.getByTestId('hilos-page-tab-hilos_profile_agreements'),
    )
    await expect(page.getByTestId('profile-agreements-view')).toBeVisible()
    await expect(page.getByTestId('legal-agreement-row')).toHaveCount(2)
    const terms = page.locator(
      '[data-id="legal-agreement-row"][data-document="terms"]',
    )
    await expect(terms.getByTestId('legal-agreement-state')).toHaveText(
      /^\s*Revision of 7 October 2026 · accepted /,
    )
    await clickSubmit(expect, terms.getByTestId('legal-agreement-open'))
    const text = page.getByTestId('legal-revision-text-modal')
    await expect(text).toBeVisible()
    await expect(text.getByTestId('legal-revision-clause')).toHaveCount(6)
    await expect(
      text.getByTestId('legal-revision-clause-deviation'),
    ).toHaveCount(1)
    await clickSubmit(expect, page.getByTestId('legal-text-close'))
    await expect(text).toBeHidden()

    await clickSubmit(
      expect,
      page.getByTestId('hilos-page-tab-hilos_profile_agreements_history'),
    )
    await expect(
      page.getByTestId('profile-agreements-history-view'),
    ).toBeVisible()
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(name)
    await expect(
      page.getByTestId('hilos-page-tab-hilos_profile_agreements_history'),
    ).toHaveAttribute('aria-current', 'page')
    await expect(
      page.getByTestId('hilos-page-tab-hilos_profile_agreements'),
    ).not.toHaveAttribute('aria-current', 'page')
    const history = page.locator(
      '[data-id="legal-history-document"][data-document="terms"]',
    )
    await expect(history.getByTestId('legal-history-revision')).toHaveCount(3)
    const current = history.locator(
      '[data-id="legal-history-revision"][data-revision="2026-10-07"]',
    )
    const substantial = history.locator(
      '[data-id="legal-history-revision"][data-revision="2026-10-01"]',
    )
    const first = history.locator(
      '[data-id="legal-history-revision"][data-revision="2026-09-17"]',
    )
    await expect(current.getByTestId('legal-history-current')).toHaveText(
      'current',
    )
    await expect(current).toContainText('7 October 2026')
    await expect(current).toContainText(
      'Editorial change · changed by the project',
    )
    await expect(substantial).toContainText(
      'Substantial change · took effect 1 October 2026 · changed by the project',
    )
    await expect(first).toContainText('First revision · Hilos standard 1')
    await expect(first.getByTestId('legal-history-compare')).toHaveCount(0)
    const privacyHistory = page.locator(
      '[data-id="legal-history-document"][data-document="privacy"]',
    )
    await expect(
      privacyHistory.getByTestId('legal-history-revision'),
    ).toHaveCount(2)
    const privacyCurrent = privacyHistory.locator(
      '[data-id="legal-history-revision"][data-revision="2026-10-09"]',
    )
    await expect(
      privacyCurrent.getByTestId('legal-history-current'),
    ).toHaveText('current')

    await clickSubmit(expect, substantial.getByTestId('legal-history-compare'))
    const comparison = page.getByTestId('legal-changes-modal')
    await expect(comparison).toBeVisible()
    await expect(page.getByTestId('legal-dialog-loading')).toBeHidden()
    await expect(page.getByTestId('legal-dialog-refusal')).toHaveCount(0)
    const wide = comparison.getByTestId('legal-changes-wide')
    await expect(wide).toBeVisible()
    await expect(wide.getByTestId('legal-change-row')).toHaveCount(1)
    await expect(wide.getByTestId('legal-change-kind')).toHaveText('changed')
    await expect(wide).toContainText('standard.availability')
    await expect(wide).toContainText('Hilos standard text')
    await expect(wide).toContainText('Project deviation')
    await expect(wide).toContainText('This is a demo: its data may be wiped')

    await clickSubmit(expect, page.getByTestId('legal-history-close'))
    await expect(comparison).toBeHidden()

    // History text is a separate on-demand action, including old revisions.
    await clickSubmit(expect, first.getByTestId('legal-history-open'))
    await expect(text).toBeVisible()
    await expect(page.getByTestId('legal-dialog-loading')).toBeHidden()
    await expect(text.getByTestId('legal-revision-clause')).toHaveCount(6)
    await expect(text).toContainText('kept for 12 months and then deleted')
    await clickSubmit(expect, page.getByTestId('legal-history-close'))
    await expect(text).toBeHidden()
  })
})

test('returns from provider consent before accepting and signing in again', async ({
  page,
}) => {
  const email = uniqueEmail()
  const account = await declareOAuthAccount('github', {
    email,
    name: 'Provider Newcomer',
  })

  await test.step('Back from provider consent creates nothing and the next sign-in asks again', async () => {
    await gotoPage(expect, page, '/profile')
    await expect(page.getByTestId('auth-surface')).toBeVisible()

    const first = signInAs(page, account)
    await clickSubmit(expect, page.getByTestId('auth-icon-oauth-github'))
    await first
    await expect(page.getByTestId('legal-consent')).toBeVisible()
    await clickSubmit(expect, page.getByTestId('auth-restart'))
    await expect(page.getByTestId('auth-identifier')).toBeVisible()
    await expect(page.getByTestId('auth-identifier')).toHaveValue('')
    await expect(page.getByTestId('auth-consent-provider')).toHaveCount(0)

    const second = signInAs(page, account)
    await clickSubmit(expect, page.getByTestId('auth-icon-oauth-github'))
    await second
    await expect(page.getByTestId('legal-consent')).toBeVisible()
    await expect(page.getByTestId('auth-consent-provider')).toBeVisible()
  })

  await test.step('creates a new provider account only after consent and records both agreements', async () => {
    const plaque = page.getByTestId('auth-consent-provider')
    await expect(plaque).toHaveAttribute('data-provider', 'oauth:github')
    await expect(plaque).toHaveText(email)
    await expect(page.getByTestId('auth-consent-identifier')).toHaveCount(0)

    await acceptTermsAsNewAccount(page)
    await expect(page.getByTestId('profile-name')).toHaveText(account.login)
    await gotoPage(expect, page, '/profile/agreements')
    await expect(page.getByTestId('legal-agreement-state')).toHaveText([
      /^\s*Revision of .+ · accepted .+\s*$/,
      /^\s*Revision of .+ · accepted .+\s*$/,
    ])
    const terms = page.locator(
      '[data-id="legal-agreement-row"][data-document="terms"]',
    )
    const privacy = page.locator(
      '[data-id="legal-agreement-row"][data-document="privacy"]',
    )
    await expect(terms.getByTestId('legal-agreement-state')).toHaveText(
      /^\s*Revision of 7 October 2026 · accepted /,
    )
    await expect(privacy.getByTestId('legal-agreement-state')).toHaveText(
      /^\s*Revision of 9 October 2026 · accepted /,
    )

    await logout(expect, page)
    await gotoPage(expect, page, '/profile')
    const returning = signInAs(page, account)
    await clickSubmit(expect, page.getByTestId('auth-icon-oauth-github'))
    await returning
    await expect(page.getByTestId('profile-name')).toHaveText(account.login)
    await expect(page.getByTestId('legal-consent')).toHaveCount(0)
  })
})

test('provider consent names GitHub when it reports no email', async ({
  page,
}) => {
  const account = await declareOAuthAccount('github', { name: 'No Mail' })
  await gotoPage(expect, page, '/profile')

  const signingIn = signInAs(page, account)
  await clickSubmit(expect, page.getByTestId('auth-icon-oauth-github'))
  await signingIn
  await expect(page.getByTestId('legal-consent')).toBeVisible()
  const plaque = page.getByTestId('auth-consent-provider')
  await expect(plaque).toHaveAttribute('data-provider', 'oauth:github')
  await expect(plaque).toHaveText('GitHub')
})

import { test, expect } from '@playwright/test'
import {
  createTestUser,
  clickSubmit,
  gotoPage,
} from '../../../../../framework/frontend/e2e/index.js'

test('reads personal agreements and compares published revisions', async ({
  page,
}) => {
  await createTestUser(expect, page)
  await gotoPage(expect, page, '/profile')
  await expect(page.getByTestId('profile-agreements-summary')).toHaveText(
    'Terms and privacy accepted',
  )
  await clickSubmit(expect, page.getByTestId('profile-agreements-open'))
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
  await expect(text.getByTestId('legal-revision-clause-deviation')).toHaveCount(
    1,
  )
  await clickSubmit(expect, page.getByTestId('legal-text-close'))
  await expect(text).toBeHidden()

  await clickSubmit(expect, page.getByTestId('profile-agreements-history-open'))
  await expect(
    page.getByTestId('profile-agreements-history-view'),
  ).toBeVisible()
  await expect(page.getByTestId('hilos-breadcrumb-hilos_profile')).toHaveText(
    'Profile',
  )
  await expect(
    page.getByTestId('hilos-breadcrumb-hilos_profile_agreements'),
  ).toHaveText('Agreements')
  await expect(page.getByTestId('hilos-page-title')).toHaveText(
    'Revision history',
  )
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
  await expect(privacyCurrent.getByTestId('legal-history-current')).toHaveText(
    'current',
  )

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

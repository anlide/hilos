import { test, expect } from '@playwright/test'
import { clickSubmit, signUp } from '../helpers/session.js'
import { gotoPage } from '../helpers/page.js'
import { sidewaysOverflow } from '../../../../../framework/frontend/e2e/index.js'

test('reads personal agreements and compares published revisions on wide and narrow screens', async ({
  page,
}) => {
  await signUp(page)
  await gotoPage(page, '/profile')
  await expect(page.getByTestId('profile-agreements-summary')).toHaveText(
    'Terms and privacy accepted',
  )
  await clickSubmit(page.getByTestId('profile-agreements-open'))
  await expect(page.getByTestId('profile-agreements-view')).toBeVisible()
  await expect(page.getByTestId('legal-agreement-row')).toHaveCount(2)
  const terms = page.locator(
    '[data-id="legal-agreement-row"][data-document="terms"]',
  )
  await expect(terms.getByTestId('legal-agreement-state')).toHaveText(
    /^Revision of 1 October 2026 · accepted /,
  )
  await clickSubmit(terms.getByTestId('legal-agreement-open'))
  const text = page.getByTestId('legal-revision-text-modal')
  await expect(text).toBeVisible()
  await expect(text.getByTestId('legal-revision-clause')).toHaveCount(6)
  await expect(text.getByTestId('legal-revision-clause-deviation')).toHaveCount(
    4,
  )
  await clickSubmit(page.getByTestId('legal-text-close'))
  await expect(text).toBeHidden()

  await clickSubmit(page.getByTestId('profile-agreements-history-open'))
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
  // The third terms revision says the demo's data may be wiped (HIL-500).
  await expect(history.getByTestId('legal-history-revision')).toHaveCount(3)
  const current = history.locator(
    '[data-id="legal-history-revision"][data-revision="2026-10-01"]',
  )
  const wording = history.locator(
    '[data-id="legal-history-revision"][data-revision="2026-09-27"]',
  )
  const first = history.locator(
    '[data-id="legal-history-revision"][data-revision="2026-09-17"]',
  )
  await expect(current.getByTestId('legal-history-current')).toHaveText(
    'current',
  )
  await expect(current).toContainText('1 October 2026')
  await expect(current).toContainText(
    'Substantial change · took effect 1 October 2026 · changed by the project',
  )
  await expect(wording).toContainText(
    'Editorial change · changed by the project',
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
    '[data-id="legal-history-revision"][data-revision="2026-10-05"]',
  )
  await expect(privacyCurrent.getByTestId('legal-history-current')).toHaveText(
    'current',
  )

  await clickSubmit(current.getByTestId('legal-history-compare'))
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

  await page.setViewportSize({ width: 375, height: 812 })
  const narrow = comparison.getByTestId('legal-changes-narrow')
  await expect(narrow).toBeVisible()
  await expect(wide).toBeHidden()
  await expect(narrow.getByTestId('legal-change-row')).toHaveCount(1)
  await expect(narrow).toContainText('Before — revision 27 September 2026')
  await expect(narrow).toContainText('After — revision 1 October 2026')
  expect(await sidewaysOverflow(page)).toEqual([0, 0])
  await clickSubmit(page.getByTestId('legal-history-close'))
  await expect(comparison).toBeHidden()

  // History text is a separate on-demand action, including old revisions.
  await clickSubmit(first.getByTestId('legal-history-open'))
  await expect(text).toBeVisible()
  await expect(page.getByTestId('legal-dialog-loading')).toBeHidden()
  await expect(text.getByTestId('legal-revision-clause')).toHaveCount(6)
  await expect(text).toContainText(
    'a message is not deleted by the passage of time',
  )
  await clickSubmit(page.getByTestId('legal-history-close'))
  await expect(text).toBeHidden()
})

import { expect, test } from '@playwright/test'
import { holdLegalRevision } from '../helpers/legalHold.js'
import { gotoPage, PAGE_READY } from '../helpers/page.js'
import { clickSubmit, logout, signUp } from '../helpers/session.js'
import { sidewaysOverflow } from '../../../../../framework/frontend/e2e/index.js'

// The public Terms page (HIL-501): its body is the text of the Terms revision in
// force, read from chat's legal catalog, with the reader's standing above it and
// the revision history under it. Chat's third Terms revision is substantial and
// took effect the day it was published, so a person test:legal:hold puts back on
// the second is past the deadline at once, and frozen under the default setting.

/** The revision in force: chat's third, the reset clause. */
const CURRENT_TERMS = '2026-10-01'
/** The revision before it, which the hold puts the person back on. */
const HELD_TERMS = '2026-09-27'
/** The first revision. */
const FIRST_TERMS = '2026-09-17'

test('a guest reads the revision in force and opens an older one from the history', async ({
  page,
}) => {
  await gotoPage(page, '/terms', PAGE_READY)
  const reader = page.getByTestId('terms-reader')
  await expect(reader).toHaveAttribute('data-state', 'guest')
  await expect(reader).toContainText('in force since 1 October 2026')

  const text = page.getByTestId('terms-text')
  await expect(text.getByTestId('legal-revision-clause')).toHaveCount(6)
  await expect(text.getByTestId('legal-revision-clause-deviation')).toHaveCount(
    4,
  )

  const revisions = page.getByTestId('terms-history-revision')
  await expect(revisions).toHaveCount(3)
  await expect(revisions.first()).toHaveAttribute(
    'data-revision',
    CURRENT_TERMS,
  )
  await expect(
    revisions.first().getByTestId('terms-history-current'),
  ).toBeVisible()
  await expect(page.getByTestId('terms-history-accepted')).toHaveCount(0)
  await expect(page.getByTestId('terms-accept')).toHaveCount(0)

  // An older revision opens through the page's own read, open to a guest.
  await clickSubmit(
    page
      .locator(
        `[data-id="terms-history-revision"][data-revision="${FIRST_TERMS}"]`,
      )
      .getByTestId('terms-history-open'),
  )
  const opened = page.getByTestId('terms-revision-modal')
  await expect(opened).toBeVisible()
  await expect(page.getByTestId('legal-dialog-loading')).toBeHidden()
  await expect(opened.getByTestId('legal-revision-clause')).toHaveCount(6)
  await expect(
    opened.getByTestId('legal-revision-clause-deviation'),
  ).toHaveCount(3)
  await clickSubmit(page.getByTestId('terms-revision-close'))
  await expect(opened).toBeHidden()
})

test('a person who accepted at sign-up sees the revision in force as theirs', async ({
  page,
}) => {
  await signUp(page)
  await gotoPage(page, '/terms', PAGE_READY)
  const reader = page.getByTestId('terms-reader')
  await expect(reader).toHaveAttribute('data-state', 'covered')
  await expect(reader).toContainText(
    'You accepted the revision of 1 October 2026',
  )
  await expect(
    page
      .locator(
        `[data-id="terms-history-revision"][data-revision="${CURRENT_TERMS}"]`,
      )
      .getByTestId('terms-history-accepted'),
  ).toBeVisible()
  await expect(page.getByTestId('terms-accept')).toHaveCount(0)

  // Signing out in the tab turns the line back into a guest's, without a reload.
  await logout(page)
  await expect(reader).toHaveAttribute('data-state', 'guest')
  await expect(page.getByTestId('terms-history-accepted')).toHaveCount(0)
})

test('the page follows a revision moved under it, and accepting there lifts the freeze', async ({
  page,
}) => {
  const person = await signUp(page)
  await gotoPage(page, '/terms', PAGE_READY)
  const reader = page.getByTestId('terms-reader')
  await expect(reader).toHaveAttribute('data-state', 'covered')

  const held = await holdLegalRevision(person.userId, 'terms', HELD_TERMS)
  expect(held.standing).toBe('lapsed')
  expect(held.frozen).toBe(true)

  // No reload: the group frame makes the page ask again, and the freeze arrives
  // with the sessions library's next tick - /terms stays open while frozen.
  await expect(reader).toHaveAttribute('data-state', 'due')
  await expect(page.getByTestId('terms-reader-plate')).toContainText(
    'account frozen · deadline passed 1 October 2026',
  )
  await expect(page.getByTestId('legal-frozen-screen')).toHaveCount(0)

  await clickSubmit(page.getByTestId('terms-changes-open'))
  const comparison = page.getByTestId('terms-changes-modal')
  await expect(comparison).toBeVisible()
  const wide = comparison.getByTestId('legal-changes-wide')
  await expect(wide.getByTestId('legal-change-row')).toHaveCount(1)
  await expect(wide.getByTestId('legal-change-kind')).toHaveText('changed')

  await page.setViewportSize({ width: 375, height: 812 })
  await expect(comparison.getByTestId('legal-changes-narrow')).toBeVisible()
  expect(await sidewaysOverflow(page)).toEqual([0, 0])

  await clickSubmit(page.getByTestId('terms-changes-accept'))
  await expect(comparison).toHaveCount(0)
  await expect(reader).toHaveAttribute('data-state', 'covered')

  // The acceptance reset the verdict: the product opens again.
  await page.getByTestId('nav-brand').click()
  await expect(page.getByTestId('message-input')).toBeVisible()
  await expect(page.getByTestId('legal-frozen-screen')).toHaveCount(0)
})

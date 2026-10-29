import { expect, test } from '@playwright/test'
import {
  logout,
  signUp,
  createTestUser,
  clickSubmit,
  expectPageReady,
  gotoPage,
  PAGE_READY,
  ROWS,
  shownByTestId,
  watchClientDocument,
} from '../../../../../framework/frontend/e2e/index.js'

import { holdLegalRevision } from '../helpers/legalHold.js'

// Terms keeps the current text and acceptance; /terms/history serves the public
// revision window and reads older text through Terms's public action. Polls's
// second revision is substantial and immediately effective, so holding a person
// on the first puts them past the deadline and freezes their account.

/** The revision in force: polls's third, the wording revision of the reset clause. */
const CURRENT_TERMS = '2026-10-07'
/** The older revision with the reset clause. */
const OLDER_TERMS = '2026-10-01'
/** The first revision, which the hold puts the person back on. */
const HELD_TERMS = '2026-09-17'

test('a guest reads the revision in force and opens an older one from the history', async ({
  page,
}) => {
  const revisions = page.getByTestId('hilos-viewport-table').locator(ROWS)
  const opened = page.getByTestId('public-legal-history-modal')

  await test.step('direct history entry reads an older Terms revision as a guest', async () => {
    await gotoPage(expect, page, '/terms/history', PAGE_READY)
    await expect(page.getByTestId('public-legal-history')).toHaveAttribute(
      'data-document',
      'terms',
    )
    await expect(revisions).toHaveCount(3)
    await expect(
      revisions.first().getByTestId('public-legal-history-current'),
    ).toHaveAttribute('data-revision', CURRENT_TERMS)
    await expect(page.getByTestId('public-legal-history-accepted')).toHaveCount(
      0,
    )
    await expect(page.getByTestId('legal-document-accept')).toHaveCount(0)

    await clickSubmit(
      expect,
      shownByTestId(page, 'public-legal-history-open').and(
        page.locator(`[data-revision="${OLDER_TERMS}"]`),
      ),
    )
    await expect(opened).toBeVisible()
    await expect(page.getByTestId('legal-dialog-loading')).toBeHidden()
    await expect(opened).toHaveAttribute('data-document', 'terms')
    await expect(opened).toHaveAttribute('data-revision', OLDER_TERMS)
    await expect(opened.getByTestId('legal-revision-clause')).toHaveCount(6)
    await expect(
      opened.getByTestId('legal-revision-clause-deviation'),
    ).toHaveCount(1)
    await expect(opened).toContainText(
      'Do not keep anything here that you would miss.',
    )
    await clickSubmit(expect, page.getByTestId('public-legal-history-close'))
    await expect(opened).toHaveCount(0)
  })

  await test.step('Terms still owns the current text and guest reader', async () => {
    await page.getByTestId('hilos-page-tab-hilos_terms').click()
    await expect(page).toHaveURL('/terms')
    await expectPageReady(expect, page)
    const reader = page.getByTestId('terms-reader')
    await expect(reader).toHaveAttribute('data-state', 'guest')
    await expect(reader).toContainText('in force since 7 October 2026')
    const text = page.getByTestId('terms-text')
    await expect(text.getByTestId('legal-revision-clause')).toHaveCount(6)
    await expect(
      text.getByTestId('legal-revision-clause-deviation'),
    ).toHaveCount(1)
    await expect(reader.getByTestId('legal-document-accept')).toHaveCount(0)
  })

  await test.step('history survives its tab link, F5 and Back/Forward', async () => {
    await page.getByTestId('hilos-page-tab-hilos_terms_history').click()
    await expect(page).toHaveURL('/terms/history')
    await expectPageReady(expect, page)
    await expect(revisions).toHaveCount(3)
    await page.reload()
    await expectPageReady(expect, page)
    await expect(revisions).toHaveCount(3)
    await page.goBack()
    await expect(page).toHaveURL('/terms')
    await expectPageReady(expect, page)
    await expect(page.getByTestId('terms-reader')).toHaveAttribute(
      'data-state',
      'guest',
    )
    await page.goForward()
    await expect(page).toHaveURL('/terms/history')
    await expectPageReady(expect, page)
    await expect(revisions).toHaveCount(3)
    await clickSubmit(
      expect,
      shownByTestId(page, 'public-legal-history-open').and(
        page.locator(`[data-revision="${CURRENT_TERMS}"]`),
      ),
    )
    await expect(opened).toBeVisible()
    await expect(page.getByTestId('legal-dialog-loading')).toBeHidden()
    await expect(opened).toHaveAttribute('data-revision', CURRENT_TERMS)
    await expect(opened.getByTestId('legal-revision-clause')).toHaveCount(6)
    await expect(opened).toContainText('Keep nothing here you would miss.')
    await clickSubmit(expect, page.getByTestId('public-legal-history-close'))
    await expect(opened).toHaveCount(0)
  })
})

test('sign-up acceptance appears in Terms history and sign-out closes its reader', async ({
  page,
  context,
}) => {
  await signUp(expect, page)
  await gotoPage(expect, page, '/terms', PAGE_READY)
  const reader = page.getByTestId('terms-reader')
  await expect(reader).toHaveAttribute('data-state', 'covered')
  await expect(reader).toContainText(
    'You accepted the revision of 7 October 2026',
  )
  await expect(reader.getByTestId('legal-document-accept')).toHaveCount(0)

  await page.getByTestId('hilos-page-tab-hilos_terms_history').click()
  await expect(page).toHaveURL('/terms/history')
  await expectPageReady(expect, page)
  const document = await watchClientDocument(page)
  const otherTab = await context.newPage()
  try {
    await test.step('history marks the exact accepted revision and opens it', async () => {
      const accepted = shownByTestId(page, 'public-legal-history-accepted')
      await expect(accepted).toHaveCount(1)
      await expect(accepted).toHaveAttribute('data-revision', CURRENT_TERMS)
      await clickSubmit(
        expect,
        shownByTestId(page, 'public-legal-history-open').and(
          page.locator(`[data-revision="${CURRENT_TERMS}"]`),
        ),
      )
      await expect(page.getByTestId('public-legal-history-modal')).toBeVisible()
      await expect(page.getByTestId('legal-dialog-loading')).toBeHidden()
    })

    await test.step('sign-out in another tab clears personal history and closes the modal', async () => {
      await gotoPage(expect, otherTab, '/', PAGE_READY)
      await logout(expect, otherTab)
      await expect(page.getByTestId('nav-profile-name')).toHaveCount(0)
      await expect(page.getByTestId('public-legal-history-modal')).toHaveCount(
        0,
      )
      await expect(
        page.getByTestId('public-legal-history-accepted'),
      ).toHaveCount(0)
      await expect(
        page.getByTestId('hilos-viewport-table').locator(ROWS),
      ).toHaveCount(3)
      await document.unchanged()
      await page.getByTestId('hilos-page-tab-hilos_terms').click()
      await expectPageReady(expect, page)
      await expect(reader).toHaveAttribute('data-state', 'guest')
    })
  } finally {
    await otherTab.close()
    await document.dispose()
  }
})

test('the page follows a revision moved under it, and accepting there lifts the freeze', async ({
  page,
}) => {
  const person = await createTestUser(expect, page)
  await gotoPage(expect, page, '/terms', PAGE_READY)
  const reader = page.getByTestId('terms-reader')
  await expect(reader).toHaveAttribute('data-state', 'covered')

  const held = await holdLegalRevision(person.userId, 'terms', HELD_TERMS)
  expect(held.standing).toBe('lapsed')
  expect(held.frozen).toBe(true)

  // No reload: the group frame makes the page ask again, and the freeze arrives
  // with the sessions library's next tick - /terms stays open while frozen.
  await expect(reader).toHaveAttribute('data-state', 'due')
  await expect(reader.getByTestId('legal-document-reader-plate')).toContainText(
    'account frozen · deadline passed 1 October 2026',
  )
  await expect(page.getByTestId('legal-frozen-screen')).toHaveCount(0)

  await clickSubmit(expect, reader.getByTestId('legal-document-changes-open'))
  const comparison = page.getByTestId('terms-changes-modal')
  await expect(comparison).toBeVisible()
  const wide = comparison.getByTestId('legal-changes-wide')
  await expect(wide.getByTestId('legal-change-row')).toHaveCount(1)
  await expect(wide.getByTestId('legal-change-kind')).toHaveText('changed')

  await clickSubmit(expect, page.getByTestId('terms-changes-accept'))
  await expect(comparison).toHaveCount(0)
  await expect(reader).toHaveAttribute('data-state', 'covered')

  // The acceptance reset the verdict: the product opens again.
  await page.getByTestId('nav-brand').click()
  await expect(page.getByTestId('self-user')).toBeVisible()
  await expect(page.getByTestId('legal-frozen-screen')).toHaveCount(0)
})

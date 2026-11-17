import { expect, test as base } from '../test.js'
import {
  cleanupFixtures,
  type TestCleanup,
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

const test = base.extend<{ cleanup: TestCleanup }>({
  cleanup: cleanupFixtures,
})

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
const CURRENT_PRIVACY = '2026-10-09'
const HELD_PRIVACY = '2026-09-17'

test('reads public legal history and returns keyboard focus in both documents', async ({
  page,
}) => {
  await test.step('public pages have one heading and legal history readers support keyboard focus', async () => {
    await gotoPage(expect, page, '/')
    await expect(page.getByTestId('conn-state')).toHaveText('connected')
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Polls')

    for (const history of [
      { document: 'terms', title: 'Terms', clauses: 6 },
      { document: 'privacy', title: 'Privacy', clauses: 7 },
    ]) {
      if (history.document === 'terms') {
        await test.step('a guest reads the revision in force and opens an older one from the history', async () => {
          const revisions = page
            .getByTestId('hilos-viewport-table')
            .locator(ROWS)
          const opened = page.getByTestId('public-legal-history-modal')

          await test.step('direct history entry reads an older Terms revision as a guest', async () => {
            await gotoPage(expect, page, '/terms/history', PAGE_READY)
            const heading = page.getByRole('heading', { level: 1 })
            await expect(heading).toHaveCount(1)
            await expect(heading).toHaveText(history.title)
            await expect(page).toHaveTitle(
              `${history.title} · Revision history · Hilos Polls`,
            )
            const table = page.getByRole('table', {
              name: history.title,
              exact: true,
            })
            await expect(table).toHaveCount(1)
            await expect(table).toBeVisible()
            await expect(
              page.getByTestId('public-legal-history'),
            ).toHaveAttribute('data-document', 'terms')
            await expect(revisions).toHaveCount(3)
            await expect(
              revisions.first().getByTestId('public-legal-history-current'),
            ).toHaveAttribute('data-revision', CURRENT_TERMS)
            await expect(
              page.getByTestId('public-legal-history-accepted'),
            ).toHaveCount(0)
            await expect(page.getByTestId('legal-document-accept')).toHaveCount(
              0,
            )

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
            await expect(
              opened.getByTestId('legal-revision-clause'),
            ).toHaveCount(6)
            await expect(
              opened.getByTestId('legal-revision-clause-deviation'),
            ).toHaveCount(1)
            await expect(opened).toContainText(
              'Do not keep anything here that you would miss.',
            )
            await clickSubmit(
              expect,
              page.getByTestId('public-legal-history-close'),
            )
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
            await expect(text.getByTestId('legal-revision-clause')).toHaveCount(
              6,
            )
            await expect(
              text.getByTestId('legal-revision-clause-deviation'),
            ).toHaveCount(1)
            await expect(
              reader.getByTestId('legal-document-accept'),
            ).toHaveCount(0)
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
            await expect(
              opened.getByTestId('legal-revision-clause'),
            ).toHaveCount(6)
            await expect(opened).toContainText(
              'Keep nothing here you would miss.',
            )
            await clickSubmit(
              expect,
              page.getByTestId('public-legal-history-close'),
            )
            await expect(opened).toHaveCount(0)
          })
        })
      } else {
        await test.step('a guest reads Privacy and its older policy through the public history route', async () => {
          const revisions = page
            .getByTestId('hilos-viewport-table')
            .locator(ROWS)
          const opened = page.getByTestId('public-legal-history-modal')

          await test.step('direct history entry reads an older Privacy revision as a guest', async () => {
            await gotoPage(expect, page, '/privacy/history', PAGE_READY)
            const heading = page.getByRole('heading', { level: 1 })
            await expect(heading).toHaveCount(1)
            await expect(heading).toHaveText(history.title)
            await expect(page).toHaveTitle(
              `${history.title} · Revision history · Hilos Polls`,
            )
            const table = page.getByRole('table', {
              name: history.title,
              exact: true,
            })
            await expect(table).toHaveCount(1)
            await expect(table).toBeVisible()
            await expect(
              page.getByTestId('public-legal-history'),
            ).toHaveAttribute('data-document', 'privacy')
            await expect(revisions).toHaveCount(2)
            await expect(
              revisions.first().getByTestId('public-legal-history-current'),
            ).toHaveAttribute('data-revision', CURRENT_PRIVACY)
            await expect(
              page.getByTestId('public-legal-history-accepted'),
            ).toHaveCount(0)
            await expect(page.getByTestId('legal-document-accept')).toHaveCount(
              0,
            )

            await clickSubmit(
              expect,
              shownByTestId(page, 'public-legal-history-open').and(
                page.locator(`[data-revision="${HELD_PRIVACY}"]`),
              ),
            )
            await expect(opened).toBeVisible()
            await expect(page.getByTestId('legal-dialog-loading')).toBeHidden()
            await expect(opened).toHaveAttribute('data-document', 'privacy')
            await expect(opened).toHaveAttribute('data-revision', HELD_PRIVACY)
            await expect(
              opened.getByTestId('legal-revision-clause'),
            ).toHaveCount(7)
            await expect(
              opened.getByTestId('legal-revision-clause-deviation'),
            ).toHaveCount(0)
            await expect(opened).not.toContainText(
              'numbered analytics events and network addresses',
            )
            await clickSubmit(
              expect,
              page.getByTestId('public-legal-history-close'),
            )
            await expect(opened).toHaveCount(0)
          })

          await test.step('Privacy still owns its current policy, acceptance and browser erase', async () => {
            await page.getByTestId('hilos-page-tab-hilos_privacy').click()
            await expect(page).toHaveURL('/privacy')
            await expectPageReady(expect, page)
            const reader = page.getByTestId('privacy-reader')
            await expect(reader).toHaveAttribute('data-state', 'guest')
            await expect(
              reader.getByTestId('legal-document-reader-line'),
            ).toHaveText(
              'Revision in force since 9 October 2026. Without an account there is nothing to accept.',
            )
            const text = page.getByTestId('privacy-text')
            await expect(text.getByTestId('legal-revision-clause')).toHaveCount(
              7,
            )
            await expect(
              text.getByTestId('legal-revision-clause-deviation'),
            ).toHaveCount(1)
            await expect(text).toContainText(
              'Account deletion leaves numbered analytics events and network addresses',
            )
            await expect(
              reader.getByTestId('legal-document-accept'),
            ).toHaveCount(0)
            await expect(
              reader.getByTestId('legal-document-changes-open'),
            ).toHaveCount(0)
            await expect(page.getByTestId('privacy-erase')).toBeEnabled()
          })

          await test.step('history survives its tab link, F5 and Back/Forward', async () => {
            await page
              .getByTestId('hilos-page-tab-hilos_privacy_history')
              .click()
            await expect(page).toHaveURL('/privacy/history')
            await expectPageReady(expect, page)
            await expect(revisions).toHaveCount(2)
            await page.reload()
            await expectPageReady(expect, page)
            await expect(revisions).toHaveCount(2)
            await page.goBack()
            await expect(page).toHaveURL('/privacy')
            await expectPageReady(expect, page)
            await expect(page.getByTestId('privacy-reader')).toHaveAttribute(
              'data-state',
              'guest',
            )
            await page.goForward()
            await expect(page).toHaveURL('/privacy/history')
            await expectPageReady(expect, page)
            await expect(revisions).toHaveCount(2)
            await clickSubmit(
              expect,
              shownByTestId(page, 'public-legal-history-open').and(
                page.locator(`[data-revision="${CURRENT_PRIVACY}"]`),
              ),
            )
            await expect(opened).toBeVisible()
            await expect(page.getByTestId('legal-dialog-loading')).toBeHidden()
            await expect(opened).toHaveAttribute(
              'data-revision',
              CURRENT_PRIVACY,
            )
            await expect(
              opened.getByTestId('legal-revision-clause'),
            ).toHaveCount(7)
            await expect(opened).toContainText(
              'numbered analytics events and network addresses',
            )
            await clickSubmit(
              expect,
              page.getByTestId('public-legal-history-close'),
            )
            await expect(opened).toHaveCount(0)
          })
        })
      }

      await test.step(`${history.title} history has one named table and a keyboard reader`, async () => {
        const open = shownByTestId(page, 'public-legal-history-open').and(
          page.locator('[data-revision="2026-09-17"]'),
        )
        await expect(open).toHaveAccessibleName('Open')
        await open.focus()
        await expect(open).toBeFocused()
        await open.press('Enter')
        const dialog = page.getByTestId('modal')
        const text = page.getByTestId('public-legal-history-modal')
        await expect(dialog).toHaveRole('dialog')
        await expect(dialog).toHaveAttribute('aria-modal', 'true')
        await expect(dialog).toHaveAccessibleName(
          `${history.title} — revision of 17 September 2026`,
        )
        await expect(dialog).toBeFocused()
        await expect(page.getByTestId('legal-dialog-loading')).toBeHidden()
        await expect(text).toHaveAttribute('data-document', history.document)
        await expect(text.getByTestId('legal-revision-clause')).toHaveCount(
          history.clauses,
        )
        await dialog.press('Escape')
        await expect(dialog).toBeHidden()
        await expect(open).toBeFocused()

        await open.press('Enter')
        await expect(dialog).toBeVisible()
        await expect(dialog).toBeFocused()
        await expect(page.getByTestId('legal-dialog-loading')).toBeHidden()
        const close = page.getByTestId('public-legal-history-close')
        await expect(close).toHaveAccessibleName('Close')
        await close.focus()
        await close.press('Enter')
        await expect(dialog).toBeHidden()
        await expect(open).toBeFocused()
      })
    }
  })
})

test('clears both accepted legal history readers after one sign-out', async ({
  page,
  context,
  cleanup,
}) => {
  await signUp(expect, page)
  const privacyPage = await context.newPage()
  cleanup.defer(() => privacyPage.close())
  const otherTab = await context.newPage()
  cleanup.defer(() => otherTab.close())

  await test.step('sign-up acceptance appears in Terms history and sign-out closes its reader', async () => {
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
    cleanup.defer(() => document.dispose())

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

    await test.step('sign-up acceptance appears in Privacy history and sign-out closes its reader', async () => {
      await gotoPage(expect, privacyPage, '/privacy', PAGE_READY)
      const privacyDocument = await watchClientDocument(privacyPage)
      cleanup.defer(() => privacyDocument.dispose())
      const privacyReader = privacyPage.getByTestId('privacy-reader')
      await expect(privacyReader).toHaveAttribute('data-state', 'covered')
      await expect(privacyReader).toContainText(
        'You accepted the revision of 9 October 2026',
      )
      await expect(
        privacyReader.getByTestId('legal-document-accept'),
      ).toHaveCount(0)

      await test.step('history marks the exact accepted Privacy revision and opens it', async () => {
        await privacyPage
          .getByTestId('hilos-page-tab-hilos_privacy_history')
          .click()
        await expect(privacyPage).toHaveURL('/privacy/history')
        await expectPageReady(expect, privacyPage)
        const accepted = shownByTestId(
          privacyPage,
          'public-legal-history-accepted',
        )
        await expect(accepted).toHaveCount(1)
        await expect(accepted).toHaveAttribute('data-revision', CURRENT_PRIVACY)
        await clickSubmit(
          expect,
          shownByTestId(privacyPage, 'public-legal-history-open').and(
            privacyPage.locator(`[data-revision="${CURRENT_PRIVACY}"]`),
          ),
        )
        await expect(
          privacyPage.getByTestId('public-legal-history-modal'),
        ).toBeVisible()
        await expect(
          privacyPage.getByTestId('legal-dialog-loading'),
        ).toBeHidden()
        await expect(
          privacyPage.getByTestId('public-legal-history-modal'),
        ).toContainText('numbered analytics events and network addresses')
      })

      await gotoPage(expect, otherTab, '/', PAGE_READY)
      await logout(expect, otherTab)

      await test.step('sign-out in another tab clears personal history and closes the modal', async () => {
        await expect(privacyPage.getByTestId('nav-profile-name')).toHaveCount(0)
        await expect(
          privacyPage.getByTestId('public-legal-history-modal'),
        ).toHaveCount(0)
        await expect(
          privacyPage.getByTestId('public-legal-history-accepted'),
        ).toHaveCount(0)
        await expect(
          privacyPage.getByTestId('hilos-viewport-table').locator(ROWS),
        ).toHaveCount(2)
        await privacyDocument.unchanged()
        await privacyPage.getByTestId('hilos-page-tab-hilos_privacy').click()
        await expectPageReady(expect, privacyPage)
        await expect(privacyReader).toHaveAttribute('data-state', 'guest')
        await expect(privacyReader).not.toContainText('You accepted')
        await expect(
          privacyPage.getByTestId('privacy-changes-modal'),
        ).toHaveCount(0)
      })
      await privacyDocument.unchanged()
    })

    await test.step('sign-out in another tab clears personal history and closes the modal', async () => {
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
    await document.unchanged()
  })
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

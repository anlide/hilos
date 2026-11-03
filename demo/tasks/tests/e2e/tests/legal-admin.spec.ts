import type { Page } from '@playwright/test'
import { expect, test } from '../test.js'

import {
  createTestAdmin,
  expectPageReady,
  gotoPage,
  PAGE_READY,
  shownByTestId,
  tableRowKeys,
  typeInto,
} from '../../../../../framework/frontend/e2e/index.js'

// spec-owner: demo — Tasks' historical Privacy precedes its analytics exception.

/*
 * Legal Documents on Tasks: the React tab mounted by this demo reaches the
 * server's real window — through the Overview tab, its search and sort, the
 * Open link and back, and on a cold entry and a reload of its own address.
 * Document tabs keep their scope through revision Open/Back and cold entry.
 * Historical Privacy Text keeps its clauses through keyboard navigation,
 * Back/Forward, direct entry and F5, without the current analytics exception.
 * Frames and live updates are proven by the Chat scenario on the same server
 * path.
 */

test.use({ colorScheme: 'light', timezoneId: 'Atlantic/Canary' })

const DOCUMENTS_PATH = '/hilos/legal/documents'
const DOCUMENT_PATH = '/hilos/legal/document/privacy'
const HISTORICAL_REVISION_PATH = `${DOCUMENT_PATH}/revision/2026-09-17`
const OPENING_ROWS = ['privacy', 'terms']

/** The Documents rows the server window put into the DOM. */
async function expectDocumentRows(page: Page, keys: string[]) {
  await expect.poll(() => tableRowKeys(page)).toEqual(keys)
}

/** Tasks' first Privacy revision keeps the deletion promise it declared. */
async function expectHistoricalPrivacyText(page: Page) {
  await expect(page).toHaveURL(`${HISTORICAL_REVISION_PATH}/text`)
  await expectPageReady(expect, page)
  const heading = page.getByRole('heading', { level: 1 })
  await expect(heading).toHaveCount(1)
  await expect(heading).toHaveText('Revision of 2026-09-17')
  await expect(page.getByTestId('legal-revision-metadata')).toContainText(
    'Privacy policy · Published 2026-09-17 · Effective 2026-09-17 · Set 1',
  )
  await expect(
    page.getByTestId('hilos-page-tab-hilos_legal_revision_text'),
  ).toHaveAttribute('aria-current', 'page')
  const text = page.getByTestId('legal-revision-text')
  await expect(text).toContainText('Your account is deleted on request.')
  await expect(text).toContainText(
    'You can ask for your account to be deleted, and it will be.',
  )
  await expect(text).toContainText(
    'Deleting an account removes your profile and the personal data attached to it.',
  )
  await expect(text).not.toContainText(
    'Account deletion leaves numbered analytics events and network addresses',
  )
  await expect(text).not.toContainText(
    'Analytics is an exception to full erasure',
  )
}

test('Legal Documents serve real rows through tabs, search, sort, open, back and reload', async ({
  page,
}) => {
  await createTestAdmin(expect, page)

  await test.step('Overview counts the declared documents', async () => {
    await gotoPage(expect, page, '/hilos/legal', PAGE_READY)
    await expect(page.getByTestId('legal-document-count')).toHaveText('2')
  })

  await test.step('The Documents tab opens the server window', async () => {
    const tab = page.getByTestId('hilos-page-tab-hilos_legal_documents')
    await expect(tab).toHaveAttribute('href', DOCUMENTS_PATH)
    await tab.click()
    await expect(page).toHaveURL(DOCUMENTS_PATH)
    await expectPageReady(expect, page)
    await expectDocumentRows(page, OPENING_ROWS)
  })

  await test.step('Search by key narrows to one row and clearing restores both', async () => {
    const search = page.getByTestId('hilos-table-search')
    await typeInto(search, 'terms')
    await expectDocumentRows(page, ['terms'])
    await page.getByTestId('hilos-table-search-clear').click()
    await expect(search).toHaveValue('')
    await expectDocumentRows(page, OPENING_ROWS)
  })

  await test.step('Sorting by label orders the rows both ways', async () => {
    const sort = page.getByTestId('hilos-table-sort-documentLabel')
    const header = page.getByRole('columnheader').filter({ has: sort })
    await sort.click()
    // Ascending labels match the opening order, so the header says it settled.
    await expect(header).toHaveAttribute('aria-sort', 'ascending')
    await expectDocumentRows(page, ['privacy', 'terms'])
    await sort.click()
    await expect(header).toHaveAttribute('aria-sort', 'descending')
    await expectDocumentRows(page, ['terms', 'privacy'])
  })

  await test.step('Open shows the privacy document and Back returns to Documents', async () => {
    const open = shownByTestId(page, 'legal-document-open').and(
      page.locator('[data-document="privacy"]'),
    )
    await expect(open).toHaveAttribute('href', DOCUMENT_PATH)
    await open.click()
    await expect(page).toHaveURL(DOCUMENT_PATH)
    await expectPageReady(expect, page)
    const heading = page.getByRole('heading', { level: 1 })
    await expect(heading).toHaveCount(1)
    await expect(heading).toHaveText('Privacy policy')
    await expect(page.getByTestId('legal-document-sources')).toContainText(
      'Demo\\Tasks\\Legal\\TasksLegalCatalog',
    )
    await page.goBack()
    await expect(page).toHaveURL(DOCUMENTS_PATH)
    await expectPageReady(expect, page)
    await expectDocumentRows(page, OPENING_ROWS)
  })

  await test.step('A cold entry and a reload of Documents draw the rows', async () => {
    await gotoPage(expect, page, DOCUMENTS_PATH, PAGE_READY)
    await expectDocumentRows(page, OPENING_ROWS)
    await page.reload()
    await expectPageReady(expect, page)
    await expectDocumentRows(page, OPENING_ROWS)
  })

  await test.step('Document tabs open their own scoped server windows', async () => {
    await shownByTestId(page, 'legal-document-open')
      .and(page.locator('[data-document="privacy"]'))
      .click()
    await expect(page).toHaveURL(DOCUMENT_PATH)
    await expectPageReady(expect, page)

    for (const [key, suffix] of [
      ['hilos_legal_document_set', '/set'],
      ['hilos_legal_document_deviations', '/deviations'],
      ['hilos_legal_document_revisions', '/revisions'],
    ]) {
      const path = `${DOCUMENT_PATH}${suffix}`
      const tab = page.getByTestId(`hilos-page-tab-${key}`)
      await expect(tab).toHaveAttribute('href', path)
      await tab.click()
      await expect(page).toHaveURL(path)
      await expectPageReady(expect, page)
      if (suffix === '/deviations') {
        await expect
          .poll(() => tableRowKeys(page))
          .toEqual(['standard.deletion'])
      } else {
        await expect.poll(() => tableRowKeys(page)).not.toHaveLength(0)
      }
    }
  })

  await test.step('Historical Privacy Text survives keyboard navigation and Back/Forward', async () => {
    const open = shownByTestId(page, 'legal-revision-open').and(
      page.locator('[data-revision="2026-09-17"]'),
    )
    await expect(open).toHaveAttribute('href', HISTORICAL_REVISION_PATH)
    await open.click()
    await expect(page).toHaveURL(HISTORICAL_REVISION_PATH)
    await expectPageReady(expect, page)
    const overview = page.getByTestId('hilos-page-tab-hilos_legal_revision')
    const text = page.getByTestId('hilos-page-tab-hilos_legal_revision_text')
    await expect(overview).toHaveAttribute('aria-current', 'page')
    await expect(text).toHaveRole('link')
    await expect(text).toHaveAccessibleName('Text')
    await expect(text).toHaveAttribute(
      'href',
      `${HISTORICAL_REVISION_PATH}/text`,
    )
    await overview.focus()
    await page.keyboard.press('Tab')
    await expect(text).toBeFocused()
    await text.press('Enter')
    await expectHistoricalPrivacyText(page)
    await page.goBack()
    await expect(page).toHaveURL(HISTORICAL_REVISION_PATH)
    await expectPageReady(expect, page)
    await expect(overview).toHaveAttribute('aria-current', 'page')
    await page.goForward()
    await expectHistoricalPrivacyText(page)
    await page.goBack()
    await expect(page).toHaveURL(HISTORICAL_REVISION_PATH)
    await expectPageReady(expect, page)
    await page.goBack()
    await expect(page).toHaveURL(`${DOCUMENT_PATH}/revisions`)
    await expectPageReady(expect, page)
    await expect.poll(() => tableRowKeys(page)).not.toHaveLength(0)
  })

  await test.step('Direct entry and F5 keep the historical Privacy text', async () => {
    await gotoPage(expect, page, `${HISTORICAL_REVISION_PATH}/text`, PAGE_READY)
    await expectHistoricalPrivacyText(page)
    await page.reload()
    await expectHistoricalPrivacyText(page)
  })

  await test.step('A cold entry and F5 keep the privacy deviation scoped', async () => {
    await gotoPage(expect, page, `${DOCUMENT_PATH}/deviations`, PAGE_READY)
    await expect.poll(() => tableRowKeys(page)).toEqual(['standard.deletion'])
    await page.reload()
    await expectPageReady(expect, page)
    await expect.poll(() => tableRowKeys(page)).toEqual(['standard.deletion'])
  })
})

import { expect, test, type Page } from '@playwright/test'

import {
  createTestAdmin,
  expectPageReady,
  gotoPage,
  PAGE_READY,
  shownByTestId,
  tableRowKeys,
  typeInto,
} from '../../../../../framework/frontend/e2e/index.js'

/*
 * Legal Documents on Tasks: the React tab mounted by this demo reaches the
 * server's real window — through the Overview tab, its search and sort, the
 * Open link and back, and on a cold entry and a reload of its own address.
 * Document tabs keep their scope through revision Open/Back and cold entry.
 * Frames and live updates are proven by the Chat scenario on the same server
 * path; revision dates are Tasks' own and not asserted here.
 */

test.use({ colorScheme: 'light', timezoneId: 'Atlantic/Canary' })

const DOCUMENTS_PATH = '/hilos/legal/documents'
const DOCUMENT_PATH = '/hilos/legal/document/privacy'
const OPENING_ROWS = ['privacy', 'terms']

/** The Documents rows the server window put into the DOM. */
async function expectDocumentRows(page: Page, keys: string[]) {
  await expect.poll(() => tableRowKeys(page)).toEqual(keys)
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

  await test.step('Open follows the selected revision and Back restores Revisions rows', async () => {
    const open = shownByTestId(page, 'legal-revision-open').first()
    await expect(open).toHaveAttribute('data-revision', /.+/)
    const revisionId = await open.getAttribute('data-revision')
    const path = `${DOCUMENT_PATH}/revision/${revisionId}`
    await expect(open).toHaveAttribute('href', path)
    await open.click()
    await expect(page).toHaveURL(path)
    await expectPageReady(expect, page)
    await page.goBack()
    await expect(page).toHaveURL(`${DOCUMENT_PATH}/revisions`)
    await expectPageReady(expect, page)
    await expect.poll(() => tableRowKeys(page)).not.toHaveLength(0)
  })

  await test.step('A cold entry and F5 keep the privacy deviation scoped', async () => {
    await gotoPage(expect, page, `${DOCUMENT_PATH}/deviations`, PAGE_READY)
    await expect.poll(() => tableRowKeys(page)).toEqual(['standard.deletion'])
    await page.reload()
    await expectPageReady(expect, page)
    await expect.poll(() => tableRowKeys(page)).toEqual(['standard.deletion'])
  })
})

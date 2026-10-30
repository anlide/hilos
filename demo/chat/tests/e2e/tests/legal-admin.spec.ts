import { expect, test, type Page } from '@playwright/test'

import {
  createTestAdmin,
  createTestUser,
  deliveryObject as object,
  expectDocumentUnchanged,
  expectPageReady,
  gotoPage,
  markDocument,
  PAGE_READY,
  shownByTestId,
  tableRowKeys,
  typeInto,
  watchDeliveryFrames,
  type DeliveryFrameLog,
} from '../../../../../framework/frontend/e2e/index.js'
import type { HilosLegalOverview } from '../../../../../framework/frontend/core/src/admin/legal/hilosLegalOverview.js'
import { holdLegalRevision } from '../../../../../framework/frontend/scripts/legal.mjs'

// spec-owner: demo — Chat's 2026-09-17 holdings precede its 2026-10-01 demo reset terms and 2026-10-05 analytics privacy revisions.

test.use({ colorScheme: 'light' })

const OVERVIEW = 'hilos_legal'
const DOCUMENTS = 'hilos_legal_documents'
const DOCUMENTS_TABLE = 'hilosLegalDocuments'
const DOCUMENTS_PATH = '/hilos/legal/documents'
const DOCUMENT_KEYS = ['terms', 'privacy'] as const
const OLD_REVISION = '2026-09-17'

/** Retain the first reply even when it lacks the window the page needs. */
function legalReplies(log: DeliveryFrameLog, since: number, pageKey: string) {
  const request = log.sent.slice(since).find(({ wire }) => {
    const value = object(wire)
    return value.type === 'page_subscribe' && value.page === pageKey
  })
  if (!request) return []
  return log.packets
    .filter(({ socket, at }) => socket === request.socket && at >= request.at)
    .flatMap(({ frames }) => frames)
    .filter(({ wire }) => {
      const value = object(wire)
      return (
        value.type === 'page_response' && object(value.data).page === pageKey
      )
    })
    .map((frame) => ({
      frame,
      payload: object(object(object(frame.wire).data).payload),
    }))
}

async function firstLegalReply(
  log: DeliveryFrameLog,
  since: number,
  pageKey: string,
) {
  await expect
    .poll(() => legalReplies(log, since, pageKey).length)
    .toBeGreaterThan(0)
  const reply = legalReplies(log, since, pageKey)[0]!
  expect(reply.frame.failure).toBeUndefined()
  expect(object(reply.payload.data).legalCatalogRefusal).toBeNull()
  expect(
    object(
      log.sent.slice(since).find(({ wire }) => {
        const value = object(wire)
        return value.type === 'page_subscribe' && value.page === pageKey
      })!.wire,
    ).params,
  ).toEqual({})
  return reply.payload
}

/** Initial carriers arrive before mounting; no action fetches missing data. */
function expectOnlyOpeningRequest(
  log: DeliveryFrameLog,
  since: number,
  pageKey: string,
) {
  const requests = log.sent
    .slice(since)
    .map(({ wire }) => object(wire))
    .filter((wire) => wire.page === pageKey && wire.type !== 'page_unsubscribe')
  // Rendered-column reports describe the mounted rows; they do not fetch a window.
  expect(
    requests
      .filter(({ type }) => type !== 'table_rendered')
      .map(({ type }) => type),
  ).toEqual(['page_subscribe'])
  if (pageKey === OVERVIEW)
    expect(
      requests.filter(({ tableKey }) => tableKey === DOCUMENTS_TABLE),
    ).toEqual([])
}

function documentRows(window: unknown) {
  const rows = object(window).rows
  expect(Array.isArray(rows)).toBe(true)
  return (rows as unknown[]).map((row) =>
    object(object(object(row).slots).document),
  )
}

async function expectDocumentsOpening(
  page: Page,
  log: DeliveryFrameLog,
  since: number,
) {
  const payload = await firstLegalReply(log, since, DOCUMENTS)
  const windows = object(payload.windows)
  expect(Object.keys(windows).sort()).toEqual([
    DOCUMENTS_TABLE,
    'hilosLegalSettings',
  ])
  expect(object(windows[DOCUMENTS_TABLE]).sort).toEqual([
    { field: 'publishedOn', direction: 'desc' },
  ])
  const rows = documentRows(windows[DOCUMENTS_TABLE])
  expect(rows.map(({ rowKey }) => rowKey)).toEqual(['privacy', 'terms'])
  expect(rows).toEqual([
    expect.objectContaining({
      rowKey: 'privacy',
      documentLabel: 'Privacy policy',
      publishedOn: '2026-10-05',
    }),
    expect.objectContaining({
      rowKey: 'terms',
      documentLabel: 'Terms of use',
      publishedOn: '2026-10-01',
    }),
  ])
  await expect.poll(() => tableRowKeys(page)).toEqual(['privacy', 'terms'])
  expectOnlyOpeningRequest(log, since, DOCUMENTS)
  return rows
}

/** Match the standard table reply to this connection's latest viewport request. */
async function documentsWindow(log: DeliveryFrameLog, since: number) {
  const answer = () => {
    const request = log.sent
      .slice(since)
      .filter(({ wire }) => {
        const value = object(wire)
        return (
          value.type === 'table_viewport' && value.tableKey === DOCUMENTS_TABLE
        )
      })
      .at(-1)
    if (!request) return undefined
    const wire = object(request.wire)
    const frame = log.packets
      .filter(({ socket, at }) => socket === request.socket && at >= request.at)
      .flatMap(({ frames }) => frames)
      .find(({ wire: candidate }) => {
        const value = object(candidate)
        const data = object(value.data)
        return (
          value.type === 'table_window' &&
          data.tableKey === DOCUMENTS_TABLE &&
          data.requestId === wire.requestId
        )
      })
    return frame
      ? { frame, request: wire, window: object(object(frame.wire).data) }
      : undefined
  }
  await expect.poll(answer).toBeDefined()
  const result = answer()!
  expect(result.frame.failure).toBeUndefined()
  return result
}

test('Legal opens complete replies and navigates through searched and sorted Documents', async ({
  page,
}) => {
  const log = watchDeliveryFrames(page)
  try {
    await createTestAdmin(expect, page)
    await test.step('Overview receives its summary and carriers in the first answer', async () => {
      const since = log.sent.length
      await gotoPage(expect, page, '/hilos/legal', PAGE_READY)
      const payload = await firstLegalReply(log, since, OVERVIEW)
      expect(Object.keys(object(payload.windows)).sort()).toEqual([
        'hilosLegalChecks',
        'hilosLegalSettings',
      ])
      expect(object(payload.data).legalOverview).toMatchObject({
        documentCount: 2,
        documents: [
          {
            document: 'terms',
            documentLabel: 'Terms of use',
            window: expect.any(Number),
            lapsed: expect.any(Number),
          },
          {
            document: 'privacy',
            documentLabel: 'Privacy policy',
            window: expect.any(Number),
            lapsed: expect.any(Number),
          },
        ],
      })
      await expect(page.getByTestId('legal-document-count')).toHaveText('2')
      expectOnlyOpeningRequest(log, since, OVERVIEW)
    })

    await test.step('The real Documents tab opens its own server window', async () => {
      const since = log.sent.length
      const tab = page.getByTestId(`hilos-page-tab-${DOCUMENTS}`)
      await expect(tab).toHaveAttribute('href', DOCUMENTS_PATH)
      await tab.click()
      await expect(page).toHaveURL(DOCUMENTS_PATH)
      await expectPageReady(expect, page)
      await expectDocumentsOpening(page, log, since)
    })

    await test.step('Search matches the displayed label and key without case sensitivity', async () => {
      for (const [search, key] of [
        ['tErMs Of UsE', 'terms'],
        ['pRiVaCy', 'privacy'],
      ]) {
        const since = log.sent.length
        await typeInto(page.getByTestId('hilos-table-search'), search!)
        const reply = await documentsWindow(log, since)
        expect(object(reply.request.filter).search).toBe(search)
        expect(documentRows(reply.window).map(({ rowKey }) => rowKey)).toEqual([
          key,
        ])
        await expect.poll(() => tableRowKeys(page)).toEqual([key])
      }
      const since = log.sent.length
      await page.getByTestId('hilos-table-search-clear').click()
      await documentsWindow(log, since)
      await expect.poll(() => tableRowKeys(page)).toEqual(['privacy', 'terms'])
    })

    await test.step('Standard controls change date and numeric orders on the server', async () => {
      let since = log.sent.length
      await page.getByTestId('hilos-table-sort-publishedOn').click()
      let reply = await documentsWindow(log, since)
      expect(reply.request.sort).toEqual([
        { field: 'publishedOn', direction: 'asc' },
      ])
      expect(documentRows(reply.window).map(({ rowKey }) => rowKey)).toEqual([
        'terms',
        'privacy',
      ])
      await expect.poll(() => tableRowKeys(page)).toEqual(['terms', 'privacy'])
      for (const direction of ['asc', 'desc']) {
        since = log.sent.length
        await page.getByTestId('hilos-table-sort-covered').click()
        reply = await documentsWindow(log, since)
        expect(reply.request.sort).toEqual([{ field: 'covered', direction }])
        const rows = documentRows(reply.window)
        for (const row of rows) expect(row.covered).toEqual(expect.any(Number))
        const expected = [...rows].sort((a, b) => {
          const value = Number(a.covered) - Number(b.covered)
          return (
            (value || String(a.rowKey).localeCompare(String(b.rowKey))) *
            (direction === 'asc' ? 1 : -1)
          )
        })
        expect(rows).toEqual(expected)
        await expect
          .poll(() => tableRowKeys(page))
          .toEqual(expected.map(({ rowKey }) => rowKey))
      }
    })

    await test.step('Open follows explicit document and revision addresses, with Back to Documents', async () => {
      const open = shownByTestId(page, 'legal-document-open').and(
        page.locator('[data-document="terms"]'),
      )
      await expect(open).toHaveAttribute('href', '/hilos/legal/document/terms')
      await open.click()
      await expect(page).toHaveURL('/hilos/legal/document/terms')
      await expectPageReady(expect, page)
      await expect(page.getByTestId('legal-set')).toBeVisible()
      const revision = shownByTestId(page, 'legal-revision-open').and(
        page.locator('[data-revision="2026-10-01"]'),
      )
      const revisionPath = '/hilos/legal/document/terms/revision/2026-10-01'
      await expect(revision).toHaveAttribute('href', revisionPath)
      await revision.click()
      await expect(page).toHaveURL(revisionPath)
      await expectPageReady(expect, page)
      await expect(page.getByTestId('legal-revision-text')).toContainText(
        'This is a demo: its data may be wiped at any time.',
      )
      await page.goBack()
      await expect(page).toHaveURL('/hilos/legal/document/terms')
      await expectPageReady(expect, page)
      const since = log.sent.length
      await page.goBack()
      await expect(page).toHaveURL(DOCUMENTS_PATH)
      await expectPageReady(expect, page)
      await expectDocumentsOpening(page, log, since)
    })

    await test.step('Direct entry and F5 resolve Documents as a static route', async () => {
      let since = log.sent.length
      await gotoPage(expect, page, DOCUMENTS_PATH, PAGE_READY)
      await expectDocumentsOpening(page, log, since)
      since = log.sent.length
      await page.reload()
      await expectPageReady(expect, page)
      await expectDocumentsOpening(page, log, since)
    })

    await test.step('Direct document and revision entry keeps its data after F5', async () => {
      await gotoPage(expect, page, '/hilos/legal/document/terms', PAGE_READY)
      await expect(page.getByTestId('legal-set')).toBeVisible()
      await page.reload()
      await expectPageReady(expect, page)
      await expect(page.getByTestId('legal-set')).toBeVisible()
      await gotoPage(
        expect,
        page,
        '/hilos/legal/document/terms/revision/2026-10-01',
        PAGE_READY,
      )
      await expect(page.getByTestId('legal-revision-text')).toContainText(
        'This is a demo: its data may be wiped at any time.',
      )
      await page.reload()
      await expectPageReady(expect, page)
      await expect(page.getByTestId('legal-revision-text')).toContainText(
        'This is a demo: its data may be wiped at any time.',
      )
    })
  } finally {
    log.stop()
  }
})

test('Overview ingests independent live legal holdings while another connection filters Documents', async ({
  page,
  browser,
  baseURL,
}) => {
  test.slow() // Three independent sessions, four live mutations and both Users links.
  const subjectContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
    colorScheme: 'light',
  })
  const documentsContext = await browser.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
    colorScheme: 'light',
  })
  const subject = await subjectContext.newPage()
  const documents = await documentsContext.newPage()
  const log = watchDeliveryFrames(page)
  const documentsLog = watchDeliveryFrames(documents)
  const current = new Map<string, string>()
  const errors: unknown[] = []
  let userId: number | undefined
  try {
    userId = (await createTestUser(expect, subject)).userId
    await createTestAdmin(expect, page)
    await createTestAdmin(expect, documents)
    const documentsSince = documentsLog.sent.length
    await gotoPage(expect, documents, DOCUMENTS_PATH, PAGE_READY)
    const rows = await expectDocumentsOpening(
      documents,
      documentsLog,
      documentsSince,
    )
    for (const key of DOCUMENT_KEYS) {
      const revisionId = object(
        rows.find(({ rowKey }) => rowKey === key)!.revision,
      ).revisionId
      expect(revisionId).toEqual(expect.any(String))
      expect(revisionId).not.toBe(OLD_REVISION)
      current.set(key, revisionId as string)
    }

    let since = documentsLog.sent.length
    await typeInto(documents.getByTestId('hilos-table-search'), 'tErMs Of UsE')
    await documentsWindow(documentsLog, since)
    since = documentsLog.sent.length
    await documents.getByTestId('hilos-table-sort-covered').click()
    const filtered = await documentsWindow(documentsLog, since)
    expect(filtered.request).toMatchObject({
      filter: { search: 'tErMs Of UsE' },
      sort: [{ field: 'covered', direction: 'asc' }],
    })
    await expect.poll(() => tableRowKeys(documents)).toEqual(['terms'])

    const overviewSince = log.sent.length
    await gotoPage(expect, page, '/hilos/legal', PAGE_READY)
    const initial = await firstLegalReply(log, overviewSince, OVERVIEW)
    const baseline = object(initial.data).legalOverview as HilosLegalOverview
    expect(baseline.documents.map(({ document }) => document)).toEqual(
      DOCUMENT_KEYS,
    )
    expect(Object.keys(object(initial.windows)).sort()).toEqual([
      'hilosLegalChecks',
      'hilosLegalSettings',
    ])
    expectOnlyOpeningRequest(log, overviewSince, OVERVIEW)
    await markDocument(page)
    const connections = log.connections.length

    const expectCounts = async (terms: number, privacy: number) => {
      const expected = baseline.documents.map((row) => ({
        ...row,
        lapsed: row.lapsed + (row.document === 'terms' ? terms : privacy),
      }))
      await expect
        .poll(
          () =>
            object(
              legalReplies(log, overviewSince, OVERVIEW).at(-1)?.payload.data,
            ).legalOverview,
        )
        .toEqual({ ...baseline, documents: expected })
      for (const row of expected) {
        for (const field of ['window', 'lapsed'] as const) {
          await expect(
            page
              .getByTestId(`legal-overview-${field}`)
              .locator(`[data-document="${row.document}"]`)
              .getByTestId(`legal-count-${field}`),
          ).toHaveText(String(row[field]))
        }
      }
      await expectDocumentUnchanged(expect, page)
      expect(log.connections).toHaveLength(connections)
      expectOnlyOpeningRequest(log, overviewSince, OVERVIEW)
      await expect(documents.getByTestId('hilos-table-search')).toHaveValue(
        'tErMs Of UsE',
      )
      await expect.poll(() => tableRowKeys(documents)).toEqual(['terms'])
      await expect(
        documents.getByRole('columnheader').filter({
          has: documents.getByTestId('hilos-table-sort-covered'),
        }),
      ).toHaveAttribute('aria-sort', 'ascending')
    }

    await test.step('Old terms change only the terms tally over the existing socket', async () => {
      expect(
        await holdLegalRevision(userId!, 'terms', OLD_REVISION),
      ).toMatchObject({ standing: 'lapsed', frozen: true })
      await expectCounts(1, 0)
    })
    await test.step('Old privacy has its own tally despite the other filtered window', async () => {
      expect(
        await holdLegalRevision(userId!, 'privacy', OLD_REVISION),
      ).toMatchObject({ standing: 'lapsed', frozen: true })
      await expectCounts(1, 1)
    })
    await test.step('Each lapsed link opens Users for that document', async () => {
      for (const key of DOCUMENT_KEYS) {
        const link = page
          .getByTestId('legal-overview-lapsed')
          .locator(`[data-document="${key}"]`)
          .getByTestId('legal-count-lapsed-link')
        await expect(link).toHaveAttribute('href', `/hilos/users/${key}`)
        const opened = page.context().waitForEvent('page')
        await link.click({ modifiers: ['ControlOrMeta'] })
        const users = await opened
        try {
          await expect(users).toHaveURL(`/hilos/users/${key}`)
          await expectPageReady(expect, users)
        } finally {
          await users.close()
        }
      }
    })
    await test.step('Restoring each original current revision returns only its tally', async () => {
      expect(
        await holdLegalRevision(userId!, 'terms', current.get('terms')!),
      ).toMatchObject({ standing: 'covered' })
      await expectCounts(0, 1)
      expect(
        await holdLegalRevision(userId!, 'privacy', current.get('privacy')!),
      ).toMatchObject({ standing: 'covered', frozen: false })
      await expectCounts(0, 0)
    })
  } catch (error) {
    errors.push(error)
  } finally {
    // Attempt both restores even if a mutation timed out or one restore fails.
    // The account helpers keep test accounts; only old holdings must not survive.
    if (userId !== undefined) {
      for (const [document, revisionId] of current) {
        try {
          expect(
            await holdLegalRevision(userId, document, revisionId),
          ).toMatchObject({ standing: 'covered' })
        } catch (error) {
          errors.push(error)
        }
      }
    }
    log.stop()
    documentsLog.stop()
    const closed = await Promise.allSettled([
      subjectContext.close(),
      documentsContext.close(),
    ])
    for (const result of closed)
      if (result.status === 'rejected') errors.push(result.reason)
  }
  if (errors.length === 1) throw errors[0]
  if (errors.length > 1)
    throw new AggregateError(
      errors,
      'Legal scenario or holding cleanup failed',
      { cause: errors[0] },
    )
})

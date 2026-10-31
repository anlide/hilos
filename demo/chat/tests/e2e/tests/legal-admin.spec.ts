import { expect, test, type Page } from '@playwright/test'

import {
  armSocketDrop,
  createTestAdmin,
  createTestUser,
  deliveryObject as object,
  dropSocket,
  expectDocumentUnchanged,
  expectPageReady,
  gotoPage,
  markDocument,
  navigateByHistory,
  PAGE_READY,
  shownByTestId,
  tableRowKeys,
  typeInto,
  watchDeliveryFrames,
  watchTableSearch,
  type DeliveryFrameLog,
} from '../../../../../framework/frontend/e2e/index.js'
import type { HilosLegalOverview } from '../../../../../framework/frontend/core/src/admin/legal/hilosLegalOverview.js'
import { holdLegalRevision } from '../../../../../framework/frontend/scripts/legal.mjs'
import {
  clearTableRefusal,
  refuseTableWindow,
} from '../../../../../framework/frontend/scripts/tableRefusal.mjs'

// spec-owner: demo — Chat's 2026-09-17 holdings precede its 2026-10-01 demo reset terms and 2026-10-05 analytics privacy revisions.

test.use({ colorScheme: 'light' })

const OVERVIEW = 'hilos_legal'
const DOCUMENTS = 'hilos_legal_documents'
const DOCUMENTS_TABLE = 'hilosLegalDocuments'
const DOCUMENTS_PATH = '/hilos/legal/documents'
const DOCUMENT_KEYS = ['terms', 'privacy'] as const
const OLD_REVISION = '2026-09-17'
const DOCUMENT = 'hilos_legal_document'
const DOCUMENT_PATH = '/hilos/legal/document/terms'
const DOCUMENT_PAGES = [
  [DOCUMENT, '', 'hilosLegalSettings', 'setting'],
  [`${DOCUMENT}_set`, '/set', 'hilosLegalDocumentSet', 'clause'],
  [
    `${DOCUMENT}_deviations`,
    '/deviations',
    'hilosLegalDocumentDeviations',
    'deviation',
  ],
  [`${DOCUMENT}_revisions`, '/revisions', 'hilosLegalRevisions', 'revision'],
] as const

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
  params: Record<string, string> = {},
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
  ).toEqual(params)
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

/** Match the Legal reply to this connection's requested table and search. */
async function documentsWindow(
  log: DeliveryFrameLog,
  since: number,
  tableKey = DOCUMENTS_TABLE,
  search?: string,
) {
  const answer = () => {
    const request = log.sent
      .slice(since)
      .filter(({ wire }) => {
        const value = object(wire)
        return (
          value.type === 'table_viewport' &&
          value.tableKey === tableKey &&
          (search === undefined || object(value.filter).search === search)
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
          data.tableKey === tableKey &&
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

/** Chat's declared terms and their adopted tables must arrive together. */
async function expectDocumentOpening(
  page: Page,
  log: DeliveryFrameLog,
  since: number,
  pageKey: string,
) {
  const binding = DOCUMENT_PAGES.find(([key]) => key === pageKey)!
  const payload = await firstLegalReply(log, since, pageKey, {
    documentKey: 'terms',
  })
  const data = object(payload.data)
  expect(data).toMatchObject({
    pageHeadOwner: { page: DOCUMENT },
    pageTabs: DOCUMENT_PAGES.map(([page]) => expect.objectContaining({ page })),
    legalDocument: {
      document: 'terms',
      declared: true,
      revision: {
        revisionId: '2026-10-01',
        publishedOn: '2026-10-01',
        effectiveOn: '2026-10-01',
      },
      set: { version: 1, publishedOn: '2026-09-17' },
      standardSetCatalogClass: 'Hilos\\Legal\\StandardSetCatalog',
      projectCatalogClass: 'Demo\\Chat\\Legal\\LegalCatalog',
    },
  })
  for (const field of ['covered', 'window', 'lapsed']) {
    const value = object(data.legalDocument)[field]
    expect(Number.isInteger(value)).toBe(true)
    expect(value).toBeGreaterThanOrEqual(0)
  }
  expect(Object.keys(object(payload.windows))).toEqual([binding[2]])
  expect(object(payload.refusedWindows)).toEqual({})
  const window = object(object(payload.windows)[binding[2]])
  await expect(page.getByTestId('legal-document-key')).toHaveText('terms')
  await expect(page.getByTestId('legal-document-revision')).toContainText(
    '2026-10-01',
  )
  if (pageKey === DOCUMENT) {
    await expect(page.getByTestId('legal-document-sources')).toContainText(
      'Demo\\Chat\\Legal\\LegalCatalog',
    )
    for (const field of ['covered', 'window', 'lapsed'])
      await expect(page.getByTestId(`legal-count-${field}`)).toHaveText(
        String(object(data.legalDocument)[field]),
      )
  } else {
    expect(window).toMatchObject({ rowsBefore: 0, totalExact: true })
    await expect(page.getByTestId('hilos-table-search')).toHaveValue('')
    const rows = window.rows as unknown[]
    expect(Array.isArray(rows)).toBe(true)
    expect(window.totalCount).toBe(rows.length)
    await expect(page.getByTestId('hilos-table-count')).toHaveText(
      `1 – ${rows.length} of ${rows.length}`,
    )
    const values = rows.map((row) =>
      object(object(object(row).slots)[binding[3]]),
    )
    if (pageKey === `${DOCUMENT}_set`) {
      expect(window.sort).toEqual([{ field: 'position', direction: 'asc' }])
      expect(values.map(({ rowKey }) => rowKey)).toEqual([
        'standard.file_access',
        'standard.retention',
        'standard.moderation',
        'standard.availability',
        'standard.account_rules',
        'standard.ownership',
      ])
      expect(values).toContainEqual(
        expect.objectContaining({
          rowKey: 'standard.retention',
          statement: 'Content is kept for 12 months.',
        }),
      )
      await expect(page.getByTestId('legal-set-metadata')).toContainText(
        'Set 1 from 2026-09-17',
      )
      await expect(page.getByRole('table')).toContainText(
        'Content is kept for 12 months.',
      )
    } else if (pageKey === `${DOCUMENT}_deviations`) {
      expect(window.sort).toEqual([{ field: 'position', direction: 'asc' }])
      expect(values.map(({ rowKey }) => rowKey)).toEqual([
        'standard.file_access',
        'standard.retention',
        'standard.moderation',
        'standard.availability',
      ])
      expect(values).toContainEqual(
        expect.objectContaining({
          rowKey: 'standard.availability',
          statement: 'This is a demo: its data may be wiped at any time',
          text: expect.stringContaining('it is reset without notice'),
        }),
      )
      await expect(page.getByRole('table')).toContainText(
        'it is reset without notice',
      )
    } else {
      expect(window.sort).toEqual([{ field: 'publishedOn', direction: 'desc' }])
      expect(values.map(({ rowKey }) => rowKey)).toEqual([
        '2026-10-01',
        '2026-09-27',
        OLD_REVISION,
      ])
      await expect(page.getByRole('table')).toContainText('editorial')
      await expect(page.getByRole('table')).toContainText(OLD_REVISION)
      await expect(
        page.getByRole('columnheader').filter({
          has: page.getByTestId('hilos-table-sort-publishedOn'),
        }),
      ).toHaveAttribute('aria-sort', 'descending')
    }
    await expect
      .poll(() => tableRowKeys(page))
      .toEqual(values.map(({ rowKey }) => rowKey))
  }
  expectOnlyOpeningRequest(log, since, pageKey)
  return object(data.legalDocument)
}

test('Legal opens complete replies and navigates through searched and sorted Documents', async ({
  page,
}) => {
  const log = watchDeliveryFrames(page)
  const browserLog = await watchTableSearch(page, {
    page: `${DOCUMENT}_set`,
    tableKey: 'hilosLegalDocumentSet',
  })
  try {
    await armSocketDrop(page)
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

    await test.step('Document links open their complete scoped pages and server searches', async () => {
      let since = log.sent.length
      const open = shownByTestId(page, 'legal-document-open').and(
        page.locator('[data-document="terms"]'),
      )
      await expect(open).toHaveAttribute('href', DOCUMENT_PATH)
      await open.click()
      await expect(page).toHaveURL(DOCUMENT_PATH)
      await expectPageReady(expect, page)
      await expectDocumentOpening(page, log, since, DOCUMENT)
      for (const [key, suffix, tableKey, slot] of DOCUMENT_PAGES.slice(1)) {
        since = log.sent.length
        const link = page.getByTestId(`hilos-page-tab-${key}`)
        await expect(link).toHaveAttribute('href', `${DOCUMENT_PATH}${suffix}`)
        await link.click()
        await expect(page).toHaveURL(`${DOCUMENT_PATH}${suffix}`)
        await expectPageReady(expect, page)
        await expectDocumentOpening(page, log, since, key)
        if (slot === 'revision') continue
        const search =
          slot === 'clause' ? 'Content is kept' : 'reset without notice'
        since = log.sent.length
        await typeInto(page.getByTestId('hilos-table-search'), search)
        const reply = await documentsWindow(log, since, tableKey, search)
        expect(reply.request.filter).toEqual({ document: 'terms', search })
        const rows = reply.window.rows as unknown[]
        expect(rows).toHaveLength(1)
        const row = object(object(object(rows[0]).slots)[slot])
        expect(row).toMatchObject(
          slot === 'clause'
            ? {
                rowKey: 'standard.retention',
                statement: 'Content is kept for 12 months.',
              }
            : {
                rowKey: 'standard.availability',
                direction: 'stricter',
                text: expect.stringContaining('it is reset without notice'),
              },
        )
        await expect.poll(() => tableRowKeys(page)).toEqual([row.rowKey])
        await expect(page.getByRole('table')).toContainText(
          slot === 'clause'
            ? String(row.statement)
            : 'it is reset without notice',
        )
      }
    })

    await test.step('Sorted history opens the exact saved revision and returns through history', async () => {
      let since = log.sent.length
      await page.getByTestId('hilos-table-sort-publishedOn').click()
      const sorted = await documentsWindow(log, since, 'hilosLegalRevisions')
      expect(sorted.request.sort).toEqual([
        { field: 'publishedOn', direction: 'asc' },
      ])
      expect(sorted.request.filter).toEqual({ document: 'terms' })
      await expect
        .poll(() => tableRowKeys(page))
        .toEqual([OLD_REVISION, '2026-09-27', '2026-10-01'])
      const revision = shownByTestId(page, 'legal-revision-open').and(
        page.locator('[data-revision="2026-09-27"]'),
      )
      const path = `${DOCUMENT_PATH}/revision/2026-09-27`
      await expect(revision).toHaveAttribute('href', path)
      since = log.sent.length
      await revision.click()
      await expect(page).toHaveURL(path)
      await expectPageReady(expect, page)
      const exact = await firstLegalReply(log, since, 'hilos_legal_revision', {
        documentKey: 'terms',
        revisionId: '2026-09-27',
      })
      expect(object(exact.data).legalRevision).toMatchObject({
        document: 'terms',
        revisionId: '2026-09-27',
        predecessorId: OLD_REVISION,
        current: false,
      })
      expect(Object.keys(object(exact.windows))).toEqual([
        'hilosLegalRevisions',
      ])
      const exactWindow = object(object(exact.windows).hilosLegalRevisions)
      expect(
        (exactWindow.rows as unknown[]).map((row) => object(row).rowKey),
      ).toEqual(['2026-09-27'])
      expect(exactWindow.totalCount).toBe(1)
      const text = page.getByTestId('legal-revision-text')
      await expect(text).toContainText('Messages are kept indefinitely.')
      await expect(text).toContainText(
        'they remain until you delete them or your account is deleted.',
      )
      await expect(text).not.toContainText('it is reset without notice')
      await expect(page.getByTestId('hilos-admin-page')).toContainText(
        'Published 2026-09-27 · Effective 2026-09-27',
      )
      await expect(
        page.getByTestId('hilos-admin-page').getByRole('button'),
      ).toHaveCount(0)
      expectOnlyOpeningRequest(log, since, 'hilos_legal_revision')
      since = log.sent.length
      await page.goBack()
      await expect(page).toHaveURL(`${DOCUMENT_PATH}/revisions`)
      await expectPageReady(expect, page)
      await expectDocumentOpening(page, log, since, `${DOCUMENT}_revisions`)
      const historyRequest = log.sent.slice(since).find(({ wire }) => {
        const value = object(wire)
        return (
          value.type === 'page_subscribe' &&
          value.page === `${DOCUMENT}_revisions`
        )
      })!
      expect(
        object(object(historyRequest.wire).tableWindows).hilosLegalRevisions,
      ).toMatchObject({
        filter: { document: 'terms', revision: '2026-09-27' },
      })
      await page.goForward()
      await expect(page).toHaveURL(path)
      await expectPageReady(expect, page)
      await expect(text).toContainText(
        'they remain until you delete them or your account is deleted.',
      )
      await page.goBack()
      await expectPageReady(expect, page)
      for (const [key, suffix] of [...DOCUMENT_PAGES].reverse().slice(1)) {
        since = log.sent.length
        await page.goBack()
        await expect(page).toHaveURL(`${DOCUMENT_PATH}${suffix}`)
        await expectPageReady(expect, page)
        await expectDocumentOpening(page, log, since, key)
      }
      for (const [key, suffix] of DOCUMENT_PAGES.slice(1)) {
        since = log.sent.length
        await page.goForward()
        await expect(page).toHaveURL(`${DOCUMENT_PATH}${suffix}`)
        await expectPageReady(expect, page)
        await expectDocumentOpening(page, log, since, key)
      }
      for (const [key, suffix] of [...DOCUMENT_PAGES].reverse().slice(1)) {
        since = log.sent.length
        await page.goBack()
        await expect(page).toHaveURL(`${DOCUMENT_PATH}${suffix}`)
        await expectPageReady(expect, page)
        await expectDocumentOpening(page, log, since, key)
      }
      since = log.sent.length
      await page.goBack()
      await expect(page).toHaveURL(DOCUMENTS_PATH)
      await expectPageReady(expect, page)
      await expectDocumentsOpening(page, log, since)
    })

    await test.step('The active Standard set link replaces an empty searched window with its opening set', async () => {
      await shownByTestId(page, 'legal-document-open')
        .and(page.locator('[data-document="terms"]'))
        .click()
      await expectPageReady(expect, page)
      let since = log.sent.length
      await page.getByTestId(`hilos-page-tab-${DOCUMENT}_set`).click()
      await expect(page).toHaveURL(`${DOCUMENT_PATH}/set`)
      await expectPageReady(expect, page)
      await expectDocumentOpening(page, log, since, `${DOCUMENT}_set`)

      since = log.sent.length
      await page.getByTestId('hilos-table-sort-clauseKey').click()
      const sorted = await documentsWindow(log, since, 'hilosLegalDocumentSet')
      expect(sorted.request.sort).toEqual([
        { field: 'clauseKey', direction: 'asc' },
      ])
      await expect
        .poll(() => tableRowKeys(page))
        .toEqual([
          'standard.account_rules',
          'standard.availability',
          'standard.file_access',
          'standard.moderation',
          'standard.ownership',
          'standard.retention',
        ])
      since = log.sent.length
      const search = 'no matching legal clause'
      await typeInto(page.getByTestId('hilos-table-search'), search)
      const empty = await documentsWindow(
        log,
        since,
        'hilosLegalDocumentSet',
        search,
      )
      expect(empty.request.filter).toEqual({ document: 'terms', search })
      expect(empty.window).toMatchObject({ rows: [], totalCount: 0 })
      await expect.poll(() => tableRowKeys(page)).toEqual([])
      await expect(page.getByTestId('hilos-table-search')).toHaveValue(search)
      await expect(shownByTestId(page, 'hilos-table-no-matches')).toBeVisible()
      const active = page.getByTestId(`hilos-page-tab-${DOCUMENT}_set`)
      await expect(active).toHaveAttribute('aria-current', 'page')
      await markDocument(page)
      const connections = log.connections.length
      since = log.sent.length
      await active.click()
      await expect(page).toHaveURL(`${DOCUMENT_PATH}/set`)
      await expectPageReady(expect, page)
      await expectDocumentOpening(page, log, since, `${DOCUMENT}_set`)
      await expect(page.getByTestId('hilos-table-count')).toHaveText(
        '1 – 6 of 6',
      )
      await expect(page.getByTestId('hilos-table-no-matches')).toHaveCount(0)
      await expectDocumentUnchanged(expect, page)
      expect(log.connections).toHaveLength(connections)
    })

    await test.step('Cold refused document tables recover their complete server opening windows without F5', async () => {
      for (const [pageKey, suffix, tableKey, slot] of DOCUMENT_PAGES.slice(1)) {
        const errors: unknown[] = []
        try {
          await refuseTableWindow(tableKey)
          let since = log.sent.length
          await gotoPage(expect, page, `${DOCUMENT_PATH}${suffix}`, PAGE_READY)
          const expectRefused = async (start: number) => {
            const payload = await firstLegalReply(log, start, pageKey, {
              documentKey: 'terms',
            })
            expect(object(payload.windows)).not.toHaveProperty(tableKey)
            expect(object(payload.refusedWindows)).toEqual({
              [tableKey]: { errorCode: 'internal_error' },
            })
            const subscribe = log.sent.slice(start).find(({ wire }) => {
              const value = object(wire)
              return value.type === 'page_subscribe' && value.page === pageKey
            })!
            // No successful window exists yet: reporting limit=1/sort=null
            // here would silently replace the server's declared opening preset.
            expect(
              object(object(subscribe.wire).tableWindows),
            ).not.toHaveProperty(tableKey)
            await expect(
              shownByTestId(page, 'hilos-table-unavailable'),
            ).toBeVisible()
            await expect(
              shownByTestId(page, 'hilos-table-unavailable-title'),
            ).toHaveText('List unavailable')
            await expect.poll(() => tableRowKeys(page)).toEqual([])
            await expect(
              page.getByTestId('hilos-table-no-matches'),
            ).toHaveCount(0)
            await expect(page.getByTestId('hilos-table-empty')).toHaveCount(0)
            await expect(page.getByTestId('page-error')).toHaveCount(0)
            await expect(page.getByTestId('legal-document-key')).toHaveText(
              'terms',
            )
            const search = page.getByTestId('hilos-table-search')
            await expect(search).toBeVisible()
            await expect(search).toBeDisabled()
            await expect(search).toHaveValue('')
            const sorts = page.locator('[data-id^="hilos-table-sort-"]:visible')
            expect(await sorts.count()).toBeGreaterThan(0)
            for (const sort of await sorts.all())
              await expect(sort).toBeDisabled()
            // Exercise native disabled input without bypassing its policy.
            await search.pressSequentially('retention', { delay: 10 })
            await expect(search).toHaveValue('')
            const state = await browserLog.read()
            expect(state.application).toHaveLength(1)
            expect(state.application[0]).toMatchObject({
              search: '',
              domSearch: '',
              filter: { document: 'terms' },
              rows: [],
              domRows: [],
              descriptor: null,
            })
            expect(
              log.sent
                .slice(start)
                .filter(({ wire }) =>
                  [
                    'action',
                    'table_viewport',
                    'table_facets',
                    'table_rendered',
                    'page_update_subscription',
                  ].includes(String(object(wire).type)),
                ),
            ).toEqual([])
            expectOnlyOpeningRequest(log, start, pageKey)
          }
          await expectRefused(since)
          await markDocument(page)
          since = log.sent.length
          await page.getByTestId(`hilos-page-tab-${pageKey}`).click()
          await expectPageReady(expect, page)
          await expectRefused(since)

          const reconnect = async () => {
            const connections = log.connections.length
            since = log.sent.length
            await dropSocket(page)
            await expect
              .poll(() => log.connections.length)
              .toBeGreaterThan(connections)
            await expect(page.getByTestId('conn-state')).toHaveText('connected')
            await expectPageReady(expect, page)
          }
          // A refused reconnect still knows no window. After lifting the lever,
          // recover through the active link on that same socket and document.
          await reconnect()
          await expectRefused(since)
          const before = await browserLog.read()
          const connections = log.connections.length
          const socket = log.connections.at(-1)!.socket
          await clearTableRefusal()
          since = log.sent.length
          await page.getByTestId(`hilos-page-tab-${pageKey}`).click()
          await expect(page).toHaveURL(`${DOCUMENT_PATH}${suffix}`)
          await expectPageReady(expect, page)
          await expectDocumentOpening(page, log, since, pageKey)
          const payload = await firstLegalReply(log, since, pageKey, {
            documentKey: 'terms',
          })
          expect(object(object(payload.windows)[tableKey])).toMatchObject({
            limit: 25,
            rowsBefore: 0,
          })
          await expect(page.getByTestId('hilos-table-unavailable')).toHaveCount(
            0,
          )
          const resumed = await browserLog.read()
          expect(resumed.socket).toBe(before.socket)
          expect(resumed.documentStarted).toBe(before.documentStarted)
          expect(resumed.application).toHaveLength(1)
          expect(resumed.application[0]!.controller).not.toBe(
            before.application[0]!.controller,
          )
          expect(object(resumed.application[0]!.descriptor)).toMatchObject({
            filter: { document: 'terms' },
            limit: 25,
          })
          expect(log.connections).toHaveLength(connections)
          expect(log.connections.at(-1)!.closed).toBeUndefined()
          expect(
            log.sent.slice(since).every((request) => request.socket === socket),
          ).toBe(true)
          expect(legalReplies(log, since, pageKey)).toHaveLength(1)
          await expectDocumentUnchanged(expect, page)

          const search = page.getByTestId('hilos-table-search')
          const sortField = slot === 'revision' ? 'publishedOn' : 'clauseKey'
          const sort = page.getByTestId(`hilos-table-sort-${sortField}`)
          await expect(search).toBeEnabled()
          await expect(sort).toBeEnabled()
          since = log.sent.length
          await sort.click()
          const sorted = await documentsWindow(log, since, tableKey)
          expect(sorted.request).toMatchObject({
            filter: { document: 'terms' },
            sort: [{ field: sortField, direction: 'asc' }],
            limit: 25,
          })
          const sortedKeys =
            slot === 'clause'
              ? [
                  'standard.account_rules',
                  'standard.availability',
                  'standard.file_access',
                  'standard.moderation',
                  'standard.ownership',
                  'standard.retention',
                ]
              : slot === 'deviation'
                ? [
                    'standard.availability',
                    'standard.file_access',
                    'standard.moderation',
                    'standard.retention',
                  ]
                : [OLD_REVISION, '2026-09-27', '2026-10-01']
          expect(
            (sorted.window.rows as unknown[]).map((row) => object(row).rowKey),
          ).toEqual(sortedKeys)
          await expect.poll(() => tableRowKeys(page)).toEqual(sortedKeys)

          const term =
            slot === 'revision'
              ? OLD_REVISION
              : slot === 'clause'
                ? 'Content is kept'
                : 'reset without notice'
          const key =
            slot === 'revision'
              ? OLD_REVISION
              : slot === 'clause'
                ? 'standard.retention'
                : 'standard.availability'
          // A later refusal has a known window and must leave explicit retry usable.
          await refuseTableWindow(tableKey)
          since = log.sent.length
          await typeInto(search, term)
          const refusedRequests = () =>
            log.sent.slice(since).filter(({ wire }) => {
              const value = object(wire)
              const filter = object(value.filter)
              return (
                value.type === 'table_viewport' &&
                value.tableKey === tableKey &&
                filter.document === 'terms' &&
                filter.search === term
              )
            })
          await expect.poll(() => refusedRequests().length).toBeGreaterThan(0)
          const refusedRequest = refusedRequests().at(-1)!
          const refusedWire = object(refusedRequest.wire)
          expect(refusedWire.tableKey).toBe(tableKey)
          expect(refusedWire.filter).toEqual({
            document: 'terms',
            search: term,
          })
          expect(refusedWire.limit).toBe(25)
          expect(refusedWire.requestId).toEqual(expect.any(String))
          expect(refusedWire.requestId).not.toBe('')
          await expect
            .poll(() =>
              log.packets
                .filter(
                  ({ socket, at }) =>
                    socket === refusedRequest.socket && at >= refusedRequest.at,
                )
                .flatMap(({ frames }) => frames)
                .some(({ wire }) => {
                  const value = object(wire)
                  return (
                    value.type === 'table_window_refused' &&
                    object(value.data).requestId === refusedWire.requestId
                  )
                }),
            )
            .toBe(true)
          await expect(
            shownByTestId(page, 'hilos-table-unavailable'),
          ).toBeVisible()
          await expect(search).toBeEnabled()
          await expect(search).toHaveValue(term)
          await clearTableRefusal()
          since = log.sent.length
          await typeInto(search, term)
          const searched = await documentsWindow(log, since, tableKey, term)
          expect(searched.request.filter).toEqual({
            document: 'terms',
            search: term,
          })
          expect(searched.window).toMatchObject({
            totalCount: 1,
            totalExact: true,
          })
          expect(
            (searched.window.rows as unknown[]).map(
              (row) => object(row).rowKey,
            ),
          ).toEqual([key])
          await expect.poll(() => tableRowKeys(page)).toEqual([key])
          await expect(page.getByTestId('hilos-table-count')).toHaveText(
            '1 – 1 of 1',
          )
          await expect(page.getByRole('table')).toContainText(term)
        } catch (error) {
          errors.push(error)
        } finally {
          try {
            await clearTableRefusal()
          } catch (error) {
            errors.push(error)
          }
        }
        if (errors.length === 1) throw errors[0]
        if (errors.length > 1)
          throw new AggregateError(
            errors,
            'Legal refusal scenario or cleanup failed',
            { cause: errors[0] },
          )
      }
    })

    await test.step('Changing document with the same mounted table opens the destination set', async () => {
      await page.getByTestId(`hilos-page-tab-${DOCUMENT}_set`).click()
      await expectPageReady(expect, page)
      const since = log.sent.length
      const connections = log.connections.length
      await markDocument(page)
      await navigateByHistory(
        expect,
        page,
        '/hilos/legal/document/privacy/set',
        PAGE_READY,
      )
      const payload = await firstLegalReply(log, since, `${DOCUMENT}_set`, {
        documentKey: 'privacy',
      })
      const request = log.sent.slice(since).find(({ wire }) => {
        const value = object(wire)
        return (
          value.type === 'page_subscribe' && value.page === `${DOCUMENT}_set`
        )
      })!
      expect(
        object(object(request.wire).tableWindows).hilosLegalDocumentSet,
      ).toMatchObject({ filter: { document: 'terms' } })
      expect(object(payload.data).legalDocument).toMatchObject({
        document: 'privacy',
        declared: true,
        revision: { revisionId: '2026-10-05' },
        set: { version: 1, publishedOn: '2026-09-17' },
      })
      expect(Object.keys(object(payload.windows))).toEqual([
        'hilosLegalDocumentSet',
      ])
      expect(object(payload.refusedWindows)).toEqual({})
      const window = object(object(payload.windows).hilosLegalDocumentSet)
      expect(window).toMatchObject({
        rowsBefore: 0,
        totalCount: 7,
        totalExact: true,
        sort: [{ field: 'position', direction: 'asc' }],
      })
      const keys = [
        'standard.no_sale',
        'standard.deletion',
        'standard.export',
        'standard.passwords',
        'standard.access_log',
        'standard.session_data',
        'standard.breach_notice',
      ]
      expect(
        (window.rows as unknown[]).map((row) => object(row).rowKey),
      ).toEqual(keys)
      await expect(page.getByTestId('legal-document-key')).toHaveText('privacy')
      await expect(page.getByTestId('legal-document-revision')).toContainText(
        '2026-10-05',
      )
      await expect(page.getByTestId('hilos-table-search')).toHaveValue('')
      await expect(page.getByTestId('hilos-table-count')).toHaveText(
        '1 – 7 of 7',
      )
      await expect.poll(() => tableRowKeys(page)).toEqual(keys)
      await expect(page.getByRole('table')).toContainText(
        'Your data is not sold.',
      )
      // The departing controller can react to route params before subscribing.
      // The new mount consumes this subscription's answer without fetching again.
      expectOnlyOpeningRequest(
        log,
        log.sent.indexOf(request),
        `${DOCUMENT}_set`,
      )
      await expectDocumentUnchanged(expect, page)
      expect(log.connections).toHaveLength(connections)
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

    await test.step('Cold entry and F5 preserve each document page and current exact text', async () => {
      for (const [key, suffix] of DOCUMENT_PAGES) {
        let since = log.sent.length
        await gotoPage(expect, page, `${DOCUMENT_PATH}${suffix}`, PAGE_READY)
        await expectDocumentOpening(page, log, since, key)
        since = log.sent.length
        await page.reload()
        await expectPageReady(expect, page)
        await expectDocumentOpening(page, log, since, key)
      }
      await gotoPage(
        expect,
        page,
        `${DOCUMENT_PATH}/revision/2026-10-01`,
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
      await expect(
        page.getByTestId('hilos-admin-page').getByRole('button'),
      ).toHaveCount(0)
    })
  } finally {
    await test.info().attach('legal-navigation-deliveries', {
      body: JSON.stringify({ sent: log.sent, packets: log.packets }),
      contentType: 'application/json',
    })
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
  const document = await page.context().newPage()
  const log = watchDeliveryFrames(page)
  const documentsLog = watchDeliveryFrames(documents)
  const documentLog = watchDeliveryFrames(document)
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
    const documentSince = documentLog.sent.length
    await gotoPage(expect, document, DOCUMENT_PATH, PAGE_READY)
    const documentBaseline = await expectDocumentOpening(
      document,
      documentLog,
      documentSince,
      DOCUMENT,
    )
    expect(documentBaseline.window).toBe(0)
    expect(documentBaseline.lapsed).toBe(0)
    expect(documentBaseline.covered).toBe(
      rows.find(({ rowKey }) => rowKey === 'terms')!.covered,
    )
    await markDocument(document)
    const documentConnections = documentLog.connections.length

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
      const documentCounts = {
        covered: Number(documentBaseline.covered) - terms,
        window: 0,
        lapsed: terms,
      }
      await expect
        .poll(
          () =>
            object(
              legalReplies(documentLog, documentSince, DOCUMENT).at(-1)?.payload
                .data,
            ).legalDocument,
        )
        .toEqual({ ...documentBaseline, ...documentCounts })
      for (const [field, value] of Object.entries(documentCounts))
        await expect(document.getByTestId(`legal-count-${field}`)).toHaveText(
          String(value),
        )
      const lapsedLink = document.getByTestId('legal-count-lapsed-link')
      if (terms > 0)
        await expect(lapsedLink).toHaveAttribute('href', '/hilos/users/terms')
      else await expect(lapsedLink).toHaveCount(0)
      await expectDocumentUnchanged(expect, document)
      expect(documentLog.connections).toHaveLength(documentConnections)
      expectOnlyOpeningRequest(documentLog, documentSince, DOCUMENT)
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
    await test.info().attach('legal-document-live-deliveries', {
      body: JSON.stringify({
        sent: documentLog.sent,
        packets: documentLog.packets,
      }),
      contentType: 'application/json',
    })
    log.stop()
    documentsLog.stop()
    documentLog.stop()
    const closed = await Promise.allSettled([
      subjectContext.close(),
      documentsContext.close(),
      document.close(),
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

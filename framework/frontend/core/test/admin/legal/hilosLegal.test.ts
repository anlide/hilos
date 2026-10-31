import { describe, expect, it } from 'vitest'
import {
  createHilosLegalAcceptancesTable,
  createHilosLegalDocumentsTable,
  createHilosLegalDocument,
  createHilosLegalDocumentSetTable,
  createHilosLegalDocumentDeviationsTable,
  createHilosLegalRevisionsTable,
  createHilosLegalRevisionAcceptanceSummary,
  hilosLegalDocumentLabel,
  createHilosLegalSettingsActions,
  describeHilosLegalCheck,
  legalAcceptanceFiltersSchema,
  legalAdminDocumentSchema,
  legalAdminRevisionSchema,
  LEGAL_ACCEPTANCES_SIGNAL,
  HilosLegalTableKey,
  resolveHilosLegalAcceptanceRow,
  resolveHilosLegalDocumentSetRow,
  resolveHilosLegalDocumentDeviationRow,
  resolveHilosLegalDocumentRow,
  resolveHilosLegalRevisionRow,
  resolveHilosLegalSettingRow,
  hilosLegalLapsedLabel,
  type HilosLegalAcceptanceFilters,
  type HilosLegalAdminDocument,
} from '../../../src/admin/legal/hilosLegal.js'
import { type HilosLegalContext } from '../../../src/legal/legalAgreements.js'
import {
  HilosConnection,
  type TableViewportDescriptor,
  type WebSocketLike,
} from '../../../src/connection/HilosConnection.js'
import { formatMoment } from '../../../src/format/moment.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { createAppPageRouter } from '../../../src/routing/appPageRouter.js'
import { createHilosRouter } from '../../../src/routing/HilosRouter.js'
import { HIDDEN_VALUE, type Hideable } from '../../../src/state/hiddenValue.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { createSignal, type ReadonlySignal } from '../../../src/state/signal.js'
import { bindTimeZoneState } from '../../../src/timeZone/timeZoneState.js'
import { type TableWindowSink } from '../../../src/table/TableViewportController.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'
import {
  bindPageScope,
  PAGE_SIGNAL_SCHEMAS,
} from '../../../src/subscription/bindPageScope.js'

const revision = {
  revisionId: 'current',
  publishedOn: '2026-09-27',
  effectiveOn: '2026-10-01',
  significance: 'substantial',
  setVersion: 1,
  deviationCount: 2,
} as const
const documentData: HilosLegalAdminDocument = {
  document: 'terms',
  declared: true,
  revision,
  covered: 7,
  window: 2,
  lapsed: 1,
  standardSetCatalogClass: 'Hilos\\Legal\\StandardSetCatalog',
  projectCatalogClass: 'Project\\Legal\\LegalCatalog',
  set: {
    version: 1,
    publishedOn: '2026-01-01',
    significance: 'editorial',
    clauses: [{ clauseKey: 'retention', statement: 'Limited retention' }],
  },
  newerSet: null,
  deviations: [],
}
const vocabulary: HilosLegalAcceptanceFilters = {
  documents: [
    {
      document: 'terms',
      declared: true,
      revisions: [
        { revisionId: 'current', declared: true },
        { revisionId: 'off-window', declared: false },
      ],
    },
    {
      document: 'privacy',
      declared: true,
      revisions: [{ revisionId: 'privacy-old', declared: true }],
    },
  ],
}

/** A real scope manager and a connection whose listeners replay the early page frame. */
function harness(early?: HilosLegalAcceptanceFilters) {
  const listeners = new Map<string, Set<(frame: never) => void>>()
  const sent: Array<{
    page: string
    table: string
    descriptor: TableViewportDescriptor
  }> = []
  const actions: Array<{ name: string; payload: unknown }> = []
  const facets: unknown[] = []
  const windows = new Map<string, TableWindowSink>()
  const rendered: Array<{
    page: string
    table: string
    keys: readonly string[]
  }> = []
  let latestPage: unknown
  let latest = early
  const connection = {
    projectTimeZone: 'UTC',
    on(event: string, listener: (frame: never) => void): () => void {
      const group = listeners.get(event) ?? new Set()
      listeners.set(event, group)
      group.add(listener)
      if (event === 'projectSignal' && latest !== undefined)
        listener({ type: LEGAL_ACCEPTANCES_SIGNAL, data: latest } as never)
      if (event === 'projectSignal' && latestPage !== undefined)
        listener(latestPage as never)
      return () => {
        group.delete(listener)
      }
    },
    choose(timeZone: string) {
      for (const listener of listeners.get('projectSignal') ?? []) {
        listener({
          type: 'handshake_response',
          data: { entities: { currentUser: { id: 1, timeZone } } },
        } as never)
      }
    },
    registerTableWindow(key: string, sink: TableWindowSink) {
      windows.set(key, sink)
    },
    unregisterTableWindow(key: string) {
      windows.delete(key)
    },
    sendTableViewport(
      page: string,
      table: string,
      descriptor: TableViewportDescriptor,
    ) {
      sent.push({ page, table, descriptor })
    },
    sendTableRendered(page: string, table: string, keys: readonly string[]) {
      rendered.push({ page, table, keys })
    },
    sendTableRowFocus() {},
    sendTableFacets(_page: string, _table: string, values: unknown) {
      facets.push(values)
    },
  }
  const context = {
    connection,
    scopes: new ScopeManager(),
    actions: {
      dispatch(name: string, payload: unknown) {
        actions.push({ name, payload })
        return { done: Promise.resolve({}) }
      },
    },
  } as unknown as HilosLegalContext
  return {
    context,
    sent,
    actions,
    facets,
    windows,
    rendered,
    choose(timeZone: string) {
      connection.choose(timeZone)
    },
    emit(data: HilosLegalAcceptanceFilters) {
      latest = data
      for (const listener of listeners.get('projectSignal') ?? [])
        listener({ type: LEGAL_ACCEPTANCES_SIGNAL, data } as never)
    },
    project(frame: unknown) {
      latestPage = frame
      for (const listener of listeners.get('projectSignal') ?? [])
        listener(frame as never)
    },
    listenerCount: () =>
      [...listeners.values()].reduce((count, group) => count + group.size, 0),
  }
}

/** A subscription answer containing the route-scoped first table window. */
function documentWindow(
  page: string,
  table: string,
  rows: TableRow[],
  field = 'position',
  direction: 'asc' | 'desc' = 'asc',
) {
  return {
    type: 'page_response',
    data: {
      page,
      payload: {
        data: { legalDocument: documentData, legalCatalogRefusal: null },
        windows: {
          [table]: {
            rows,
            totalCount: rows.length,
            totalExact: true,
            firstAnchor: null,
            lastAnchor: null,
            limit: 25,
            sort: [{ field, direction }],
          },
        },
      },
    },
  }
}

/** Only the socket is scripted; subscriptions and table bindings use the real connection. */
function connectedHarness() {
  const h = harness()
  const listeners = new Map<
    string,
    Array<(event: { data?: unknown }) => void>
  >()
  const frames: Array<Record<string, unknown>> = []
  const socket: WebSocketLike = {
    send(data) {
      frames.push(JSON.parse(String(data)) as Record<string, unknown>)
    },
    close() {},
    addEventListener(type, listener) {
      listeners.set(type, [...(listeners.get(type) ?? []), listener])
    },
  }
  const emit = (type: string, event: { data?: unknown } = {}) => {
    for (const listener of listeners.get(type) ?? []) listener(event)
  }
  const connection = new HilosConnection({
    url: 'ws://test/ws',
    webSocketFactory: () => socket,
    projectSchemas: PAGE_SIGNAL_SCHEMAS,
  })
  const context = { ...h.context, connection }
  const pages = bindPageScope(connection, context.scopes)
  const routes = createAppPageRouter({})
  let path = '/hilos/legal'
  const navigator = createHilosRouter(routes, pages, {
    pathname: () => path,
    pushState: (value) => {
      path = value
    },
    replaceState: (value) => {
      path = value
    },
    onPopState: () => () => {},
  })
  connection.connect()
  emit('open')
  pages.releaseOnSession()
  return {
    ...h,
    connection,
    context,
    pages,
    frames,
    emit,
    navigate(page: string, params: Record<string, string>) {
      const href = routes.path(page, params)
      if (href === undefined) throw new Error('Test route is missing')
      navigator.navigate(href)
    },
    project(frame: unknown) {
      emit('message', { data: JSON.stringify(frame) })
    },
    dispose() {
      pages.unsubscribe()
      connection.close()
    },
  }
}

describe('document page data and catalog rows', () => {
  it('roundtrips the common shape with server counts and catalog class names', () => {
    expect(legalAdminDocumentSchema.parse(documentData)).toEqual(documentData)
    const empty = {
      ...documentData,
      covered: 0,
      window: 0,
      lapsed: 0,
      projectCatalogClass: null,
    }
    expect(legalAdminDocumentSchema.parse(empty)).toEqual(empty)
  })

  it.each(['covered', 'window', 'lapsed'])(
    'requires a nonnegative integer %s, including confirmed zero',
    (field) => {
      for (const value of [undefined, null, -1, 1.5, '0'])
        expect(
          legalAdminDocumentSchema.safeParse({
            ...documentData,
            [field]: value,
          }).success,
        ).toBe(false)
    },
  )

  it('requires both catalog source fields while allowing a null project source', () => {
    for (const field of ['standardSetCatalogClass', 'projectCatalogClass']) {
      expect(
        legalAdminDocumentSchema.safeParse({
          ...documentData,
          [field]: undefined,
        }).success,
      ).toBe(false)
      expect(
        legalAdminDocumentSchema.safeParse({ ...documentData, [field]: 2 })
          .success,
      ).toBe(false)
    }
    expect(
      legalAdminDocumentSchema.safeParse({
        ...documentData,
        standardSetCatalogClass: null,
      }).success,
    ).toBe(false)
  })

  it.each([
    HilosPages.LEGAL_DOCUMENT,
    HilosPages.LEGAL_DOCUMENT_SET,
    HilosPages.LEGAL_DOCUMENT_DEVIATIONS,
    HilosPages.LEGAL_DOCUMENT_REVISIONS,
  ])('clears malformed and stale common document data on %s', (pageKey) => {
    const h = harness()
    const details = createHilosLegalDocument(h.context)
    expect(details.get()).toBeUndefined()
    const page = h.context.scopes.openPage(pageKey)
    page.data.set('legalDocument', documentData)
    expect(details.get()).toEqual(documentData)

    page.data.set('legalDocument', { ...documentData, covered: null })
    expect(details.get()).toBeNull()
    page.data.set('legalDocument', documentData)
    page.data.set('legalDocument', null)
    page.data.set(
      'legalCatalogRefusal',
      'The document catalog could not be loaded.',
    )
    expect(details.get()).toBeNull()
    expect(h.context.scopes.pageDataSignal('legalCatalogRefusal').get()).toBe(
      'The document catalog could not be loaded.',
    )

    page.data.set('legalCatalogRefusal', null)
    page.data.set('legalDocument', {
      ...documentData,
      covered: 0,
      window: 0,
      lapsed: 0,
    })
    expect(details.get()).toMatchObject({ covered: 0, window: 0, lapsed: 0 })
    const next = h.context.scopes.openPage(pageKey)
    expect(details.get()).toBeUndefined()
    next.data.set('legalDocument', {
      ...documentData,
      document: 'retired /?#%',
      declared: false,
      revision: null,
      set: null,
    })
    expect(details.get()).toMatchObject({
      document: 'retired /?#%',
      revision: null,
    })
    page.data.set('legalDocument', documentData)
    expect(details.get()?.document).toBe('retired /?#%')
    h.context.scopes.dropPage()
    expect(details.get()).toBeUndefined()
    expect(h.sent).toEqual([])
    expect(h.actions).toEqual([])
  })

  it('reads adopted clauses and both deviation directions without changing their text or position', () => {
    const clause = {
      rowKey: 'retention',
      clauseKey: 'retention',
      statement: 'Limited retention',
      position: 4,
    }
    expect(
      resolveHilosLegalDocumentSetRow({
        rowKey: clause.rowKey,
        slots: { clause },
      }),
    ).toEqual(clause)
    for (const direction of ['stricter', 'looser'] as const) {
      const deviation = {
        ...clause,
        standardStatement: 'Retain only as needed',
        text: 'Project retention\nwith a reason.',
        direction,
      }
      expect(
        resolveHilosLegalDocumentDeviationRow({
          rowKey: deviation.rowKey,
          slots: { deviation },
        }),
      ).toEqual(deviation)
    }
    expect(
      resolveHilosLegalDocumentDeviationRow({ rowKey: 'bad', slots: {} })
        .direction,
    ).toBeNull()
  })

  it('keeps calendar dates literal and derives significance and set only from nullable revision metadata', () => {
    const h = harness()
    const release = bindTimeZoneState(h.context.connection)
    try {
      for (const zone of ['Pacific/Honolulu', 'Pacific/Kiritimati']) {
        h.choose(zone)
        const row = resolveHilosLegalRevisionRow({
          rowKey: 'current',
          slots: {
            revision: {
              declared: true,
              revision,
              publishedOn: '2026-09-27',
              effectiveOn: '2026-10-01',
              significance: 'editorial',
              setVersion: 99,
              heldCount: 3,
              acceptedCount: 8,
            },
          },
        })
        expect(row).toMatchObject({
          publishedOn: '2026-09-27',
          effectiveOn: '2026-10-01',
          significance: 'substantial',
          setVersion: 1,
          heldCount: 3,
          acceptedCount: 8,
        })
        expect(
          resolveHilosLegalRevisionRow({
            rowKey: 'gone',
            slots: {
              revision: {
                declared: false,
                revision: null,
                publishedOn: null,
                effectiveOn: null,
                significance: 'editorial',
                setVersion: 99,
              },
            },
          }),
        ).toMatchObject({
          publishedOn: null,
          effectiveOn: null,
          significance: null,
          setVersion: null,
        })
      }
    } finally {
      release()
    }
  })
})

describe('document tab table lifecycle', () => {
  const cases = [
    {
      page: HilosPages.LEGAL_DOCUMENT_SET,
      table: 'hilosLegalDocumentSet',
      create: createHilosLegalDocumentSetTable,
      row: {
        rowKey: 'retention',
        slots: {
          clause: {
            clauseKey: 'retention',
            statement: 'Limited retention',
            position: 3,
          },
        },
      },
      field: 'position',
      direction: 'asc',
      sortable: ['clauseKey', 'statement'],
    },
    {
      page: HilosPages.LEGAL_DOCUMENT_DEVIATIONS,
      table: 'hilosLegalDocumentDeviations',
      create: createHilosLegalDocumentDeviationsTable,
      row: {
        rowKey: 'retention',
        slots: {
          deviation: {
            clauseKey: 'retention',
            statement: 'Limited retention',
            standardStatement: 'Only as needed',
            text: 'For 30 days',
            direction: 'stricter',
            position: 3,
          },
        },
      },
      field: 'position',
      direction: 'asc',
      sortable: ['direction', 'clauseKey'],
    },
    {
      page: HilosPages.LEGAL_DOCUMENT_REVISIONS,
      table: 'hilosLegalRevisions',
      create: (context: HilosLegalContext, document: ReadonlySignal<string>) =>
        createHilosLegalRevisionsTable(
          context,
          document,
          HilosPages.LEGAL_DOCUMENT_REVISIONS,
        ),
      row: {
        rowKey: 'current',
        slots: {
          revision: {
            declared: true,
            revision,
            publishedOn: '2026-09-27',
            effectiveOn: '2026-10-01',
            heldCount: 3,
            acceptedCount: 8,
            current: true,
            origin: 'current',
          },
        },
      },
      field: 'publishedOn',
      direction: 'desc',
      sortable: ['publishedOn', 'effectiveOn', 'heldCount'],
    },
  ] as const

  it.each(cases)(
    'consumes the early first window of $table with no viewport or action',
    (test) => {
      const h = harness()
      h.context.scopes.openPage(test.page)
      const first = documentWindow(
        test.page,
        test.table,
        [test.row],
        test.field,
        test.direction,
      )
      h.project(first)
      const table = test.create(h.context, createSignal('terms'))
      expect(table.controller.descriptor()).toBeNull()
      table.start()

      expect(table.controller.rows.get()[0]?.row?.rowKey).toBe(test.row.rowKey)
      expect(table.controller.order.get()).toEqual([
        { field: test.field, direction: test.direction },
      ])
      expect(table.controller.descriptor()).toMatchObject({
        filter: { document: 'terms' },
        limit: 25,
        sort: [{ field: test.field, direction: test.direction }],
      })
      expect(h.windows.get(test.table)).toBe(table.controller)
      expect(h.sent).toEqual([])
      expect(h.actions).toEqual([])
      expect(h.rendered).toHaveLength(1)

      table.start()
      expect(h.sent).toEqual([])
      expect(h.windows.size).toBe(1)
      table.dispose()
      expect(h.windows.size).toBe(0)
      expect(h.listenerCount()).toBe(0)
    },
  )

  it.each(cases)(
    'offers only declared orders and sends search and sort for $table to the server',
    (test) => {
      const h = harness()
      const document = createSignal('terms /?#%')
      h.context.scopes.openPage(test.page)
      const table = test.create(h.context, document)
      table.start()
      expect(table.controller.frame.body.get()).toBe('loading')
      expect(h.sent).toEqual([])
      h.project(
        documentWindow(
          test.page,
          test.table,
          [test.row],
          test.field,
          test.direction,
        ),
      )

      expect(table.controller.frame.declaration.search).toBeDefined()
      expect(
        table.controller.frame.columns
          .get()
          .filter(({ sortable }) => sortable)
          .map(({ key }) => key),
      ).toEqual(test.sortable)
      table.controller.setSearch('retention')
      expect(h.sent).toHaveLength(1)
      expect(h.sent[0]).toMatchObject({
        page: test.page,
        table: test.table,
        descriptor: {
          filter: { document: 'terms /?#%', search: 'retention' },
          sort: [{ field: test.field, direction: test.direction }],
        },
      })
      // The old window is kept until the server answers; the browser never searches it.
      expect(table.controller.rows.get()[0]?.row?.rowKey).toBe(test.row.rowKey)
      table.controller.ingestWindow([test.row], 1, true, null, null, 25)
      for (const field of test.sortable) {
        table.controller.setSort(field)
        expect(h.sent.at(-1)).toMatchObject({
          page: test.page,
          table: test.table,
          descriptor: {
            filter: { document: 'terms /?#%', search: 'retention' },
            sort: [{ field, direction: 'asc' }],
          },
        })
      }
      table.controller.resetOrder()
      expect(h.sent.at(-1)?.descriptor.sort).toEqual([
        { field: test.field, direction: test.direction },
      ])
      table.controller.resetFilters()
      expect(h.sent.at(-1)?.descriptor.filter).toEqual({
        document: 'terms /?#%',
      })
      document.set('retired')
      expect(h.sent.at(-1)?.descriptor.filter).toEqual({ document: 'retired' })

      h.sent.length = 0
      h.project(
        documentWindow(
          test.page,
          test.table,
          [test.row],
          test.field,
          test.direction,
        ),
      )
      expect(h.windows.get(test.table)?.descriptor()).toMatchObject({
        filter: { document: 'retired' },
        sort: [{ field: test.field, direction: test.direction }],
      })
      expect(h.sent).toEqual([])
      table.dispose()
      document.set('privacy')
      expect(h.sent).toEqual([])
      expect(h.listenerCount()).toBe(0)
    },
  )

  it.each(cases)(
    'sends real search/sort frames and preserves the held $table on reconnect',
    (test) => {
      const h = connectedHarness()
      const { connection, context, pages, frames, emit } = h
      const table = test.create(context, createSignal('terms /?#%'))
      try {
        pages.subscribe(test.page, { documentKey: 'terms /?#%' })
        const details = createHilosLegalDocument(context)
        expect(details.get()).toBeUndefined()
        emit('message', {
          data: JSON.stringify(
            documentWindow(
              test.page,
              test.table,
              [test.row],
              test.field,
              test.direction,
            ),
          ),
        })
        table.start()
        expect(details.get()).toEqual(documentData)
        expect(table.controller.rows.get()[0]?.row?.rowKey).toBe(
          test.row.rowKey,
        )
        expect(frames.map(({ type }) => type)).toEqual([
          'page_subscribe',
          'table_rendered',
        ])

        table.controller.setSearch('retention')
        expect(frames.at(-1)).toMatchObject({
          type: 'table_viewport',
          page: test.page,
          tableKey: test.table,
          filter: { document: 'terms /?#%', search: 'retention' },
          sort: [{ field: test.field, direction: test.direction }],
          limit: 25,
        })
        const field = test.sortable[0]
        table.controller.setSort(field)
        expect(frames.at(-1)).toMatchObject({
          type: 'table_viewport',
          page: test.page,
          tableKey: test.table,
          filter: { document: 'terms /?#%', search: 'retention' },
          sort: [{ field, direction: 'asc' }],
        })
        expect(details.get()?.covered).toBe(7)

        connection.close()
        connection.connect()
        emit('open')
        pages.releaseOnSession()
        expect(frames.at(-1)).toMatchObject({
          type: 'page_subscribe',
          page: test.page,
          params: { documentKey: 'terms /?#%' },
          tableWindows: {
            [test.table]: {
              filter: { document: 'terms /?#%', search: 'retention' },
              sort: [{ field, direction: 'asc' }],
              limit: 25,
            },
          },
        })
        const before = frames.length
        emit('message', {
          data: JSON.stringify(
            documentWindow(test.page, test.table, [test.row], field, 'asc'),
          ),
        })
        expect(table.controller.rows.get()[0]?.row?.rowKey).toBe(
          test.row.rowKey,
        )
        expect(frames).toHaveLength(before)
        expect(h.actions).toEqual([])
      } finally {
        table.dispose()
        pages.unsubscribe()
        connection.close()
      }
    },
  )

  it.each(cases)(
    'opens a new $table controller with a matching window after an active link navigation',
    (test) => {
      const h = connectedHarness()
      const opening = documentWindow(
        test.page,
        test.table,
        [test.row],
        test.field,
        test.direction,
      )
      h.navigate(test.page, { documentKey: 'terms' })
      h.project(opening)
      const departing = test.create(h.context, createSignal('terms'))
      departing.start()
      try {
        departing.controller.setSearch('no matching clause or revision')
        h.project({
          type: 'table_window',
          data: {
            ...opening.data.payload.windows[test.table],
            page: test.page,
            tableKey: test.table,
            rows: [],
            totalCount: 0,
            rowsBefore: 0,
            requestId: h.frames.at(-1)?.requestId,
          },
        })
        expect(departing.controller.frame.body.get()).toBe('empty_filtered')
        departing.controller.setSort(test.sortable[0])
        expect(departing.controller.descriptor()?.filter).toEqual({
          document: 'terms',
          search: 'no matching clause or revision',
        })

        // Vue unmounts after subscribe has already asked the departing controller for a report.
        h.navigate(test.page, { documentKey: 'terms' })
        expect(h.frames.at(-1)).toEqual({
          type: 'page_subscribe',
          page: test.page,
          params: { documentKey: 'terms' },
          tableWindows: {
            [test.table]: {
              filter: { document: 'terms' },
              sort: [{ field: test.field, direction: test.direction }],
              limit: 25,
              anchor: null,
              anchorDirection: 'after',
              pageIndex: null,
              rendered: departing.controller.descriptor()?.rendered,
            },
          },
        })
        departing.dispose()
        h.project(opening)
        const mounted = test.create(h.context, createSignal('terms'))
        const before = h.frames.length
        mounted.start()
        try {
          expect(mounted.controller.search.get()).toBe('')
          expect(mounted.controller.frame.body.get()).toBe('rows')
          expect(mounted.controller.frame.footer.get()).toMatchObject({
            totalCount: 1,
            totalExact: true,
            page: 0,
          })
          expect(mounted.controller.rows.get()[0]?.row?.rowKey).toBe(
            test.row.rowKey,
          )
          expect(mounted.controller.descriptor()).toMatchObject({
            filter: { document: 'terms' },
            sort: [{ field: test.field, direction: test.direction }],
            anchor: null,
            anchorDirection: 'after',
            pageIndex: null,
          })
          expect(h.frames.slice(before).map(({ type }) => type)).toEqual([
            'table_rendered',
          ])
          h.pages.subscribe(test.page, { documentKey: 'terms' })
          expect(h.frames.at(-1)?.tableWindows).toEqual({
            [test.table]: mounted.controller.descriptor(),
          })
        } finally {
          mounted.dispose()
        }
      } finally {
        departing.dispose()
        h.dispose()
      }
    },
  )

  it.each(cases)(
    'resets the reported $table paging address for a new mount and keeps it on reconnect',
    (test) => {
      const h = connectedHarness()
      const opening = documentWindow(
        test.page,
        test.table,
        [test.row],
        test.field,
        test.direction,
      )
      opening.data.payload.windows[test.table].totalCount = 101
      h.navigate(test.page, { documentKey: 'terms' })
      h.project(opening)
      const departing = test.create(h.context, createSignal('terms'))
      departing.start()
      try {
        departing.controller.setPage(2)
        h.project({
          type: 'table_window',
          data: {
            ...opening.data.payload.windows[test.table],
            page: test.page,
            tableKey: test.table,
            rowsBefore: 50,
            requestId: h.frames.at(-1)?.requestId,
          },
        })
        expect(departing.controller.descriptor()?.pageIndex).toBe(2)
        expect(departing.controller.frame.footer.get()).toMatchObject({
          totalCount: 101,
          page: 2,
          firstRow: 51,
        })
        h.connection.close()
        h.connection.connect()
        h.emit('open')
        h.pages.releaseOnSession()
        expect(h.frames.at(-1)?.tableWindows).toEqual({
          [test.table]: departing.controller.descriptor(),
        })
        h.navigate(test.page, { documentKey: 'terms' })
        const reports = h.frames.at(-1)?.tableWindows as Record<
          string,
          TableViewportDescriptor
        >
        expect(reports[test.table]?.pageIndex).toBeNull()
        expect(reports[test.table]?.anchor).toBeNull()
        departing.dispose()
        h.project(opening)
        const mounted = test.create(h.context, createSignal('terms'))
        const before = h.frames.length
        mounted.start()
        try {
          expect(mounted.controller.descriptor()).toEqual(reports[test.table])
          expect(mounted.controller.frame.footer.get()).toMatchObject({
            totalCount: 101,
            page: 0,
            firstRow: 1,
          })
          expect(h.frames.slice(before).map(({ type }) => type)).toEqual([
            'table_rendered',
          ])
        } finally {
          mounted.dispose()
        }
      } finally {
        departing.dispose()
        h.dispose()
      }
    },
  )

  it.each(cases)(
    'mounts $table in another document scope from that route response',
    (test) => {
      const h = connectedHarness()
      h.navigate(test.page, { documentKey: 'terms' })
      h.project(
        documentWindow(
          test.page,
          test.table,
          [test.row],
          test.field,
          test.direction,
        ),
      )
      const departing = test.create(h.context, createSignal('terms'))
      departing.start()
      try {
        departing.controller.setSearch('no matching legal row')
        h.navigate(test.page, { documentKey: 'privacy' })
        const reports = h.frames.at(-1)?.tableWindows as Record<
          string,
          TableViewportDescriptor
        >
        // The server rejects this report's document scope (LegalAdminPagesTest).
        expect(reports[test.table]?.filter).toEqual({ document: 'terms' })
        departing.dispose()
        const privacyRow = { ...test.row, rowKey: 'privacy-row' }
        const privacyWindow = documentWindow(
          test.page,
          test.table,
          [privacyRow],
          test.field,
          test.direction,
        )
        privacyWindow.data.payload.data.legalDocument = {
          ...documentData,
          document: 'privacy',
        }
        h.project(privacyWindow)
        const mounted = test.create(h.context, createSignal('privacy'))
        const before = h.frames.length
        mounted.start()
        try {
          expect(mounted.controller.search.get()).toBe('')
          expect(
            mounted.controller.rows.get().map(({ row }) => row?.rowKey),
          ).toEqual(['privacy-row'])
          expect(mounted.controller.descriptor()?.filter).toEqual({
            document: 'privacy',
          })
          expect(h.frames.slice(before).map(({ type }) => type)).toEqual([
            'table_rendered',
          ])
        } finally {
          mounted.dispose()
        }
      } finally {
        departing.dispose()
        h.dispose()
      }
    },
  )

  it('mounts a revision list after exact detail without keeping the exact filter', () => {
    const h = connectedHarness()
    const test = cases[2]
    h.navigate(HilosPages.LEGAL_REVISION, {
      documentKey: 'terms',
      revisionId: 'current',
    })
    h.project(
      documentWindow(
        HilosPages.LEGAL_REVISION,
        test.table,
        [test.row],
        test.field,
        test.direction,
      ),
    )
    const departing = createHilosLegalRevisionsTable(
      h.context,
      createSignal('terms'),
      HilosPages.LEGAL_REVISION,
      createSignal('current'),
    )
    departing.start()
    try {
      departing.controller.setSearch('current')
      departing.controller.setSort('heldCount')
      h.connection.close()
      h.connection.connect()
      h.emit('open')
      h.pages.releaseOnSession()
      expect(h.frames.at(-1)?.tableWindows).toEqual({
        [test.table]: departing.controller.descriptor(),
      })
      expect(departing.controller.descriptor()?.filter).toEqual({
        document: 'terms',
        revision: 'current',
        search: 'current',
      })
      h.navigate(test.page, { documentKey: 'terms' })
      const reports = h.frames.at(-1)?.tableWindows as Record<
        string,
        TableViewportDescriptor
      >
      expect(reports[test.table]?.filter).toEqual({
        document: 'terms',
        revision: 'current',
      })
      departing.dispose()
      h.project(
        documentWindow(
          test.page,
          test.table,
          [test.row, { ...test.row, rowKey: 'earlier' }],
          test.field,
          test.direction,
        ),
      )
      const mounted = test.create(h.context, createSignal('terms'))
      const before = h.frames.length
      mounted.start()
      try {
        expect(
          mounted.controller.rows.get().map(({ row }) => row?.rowKey),
        ).toEqual(['current', 'earlier'])
        expect(mounted.controller.descriptor()?.filter).toEqual({
          document: 'terms',
        })
        expect(h.frames.slice(before).map(({ type }) => type)).toEqual([
          'table_rendered',
        ])
      } finally {
        mounted.dispose()
      }
    } finally {
      departing.dispose()
      h.dispose()
    }
  })

  it.each(cases)(
    'distinguishes an empty window from a refused $table and recovers on the next answer',
    (test) => {
      const h = harness()
      h.context.scopes.openPage(test.page)
      const table = test.create(h.context, createSignal('retired'))
      table.start()
      h.project({
        type: 'page_response',
        data: {
          page: test.page,
          payload: {
            refusedWindows: { [test.table]: { errorCode: 'internal_error' } },
          },
        },
      })
      expect(table.controller.frame.body.get()).toBe('unavailable')
      h.project(
        documentWindow(test.page, test.table, [], test.field, test.direction),
      )
      expect(table.controller.frame.body.get()).toBe('empty')
      expect(table.controller.frame.refusal.get()).toBeNull()
      expect(h.sent).toEqual([])
      table.dispose()
    },
  )

  it.each(cases)(
    'keeps the first refused $table unknown across active navigation and reconnect until recovery',
    (test) => {
      const h = connectedHarness()
      const refusal = {
        type: 'page_response',
        data: {
          page: test.page,
          payload: {
            data: { legalDocument: documentData, legalCatalogRefusal: null },
            refusedWindows: { [test.table]: { errorCode: 'internal_error' } },
          },
        },
      }
      h.navigate(test.page, { documentKey: 'terms' })
      h.project(refusal)
      const departing = test.create(h.context, createSignal('terms'))
      departing.start()
      let mounted: ReturnType<typeof test.create> | undefined
      try {
        expect(departing.controller.frame.body.get()).toBe('unavailable')
        expect(departing.controller.loaded.get()).toBe(true)
        expect(departing.controller.descriptor()).toBeNull()
        expect(departing.controller.descriptor(true)).toBeNull()
        expect(h.connection.tableWindowDescriptors()).toEqual({})
        expect(h.connection.tableWindowDescriptors(true)).toEqual({})

        departing.controller.setSearch('no matching clause')
        departing.controller.setFilter('document', 'privacy')
        departing.controller.setSort('publishedOn')
        expect(departing.controller.filter.get()).toEqual({ document: 'terms' })
        expect(departing.controller.search.get()).toBe('')
        expect(departing.controller.descriptor()).toBeNull()
        expect(
          h.frames.filter(({ type }) => type === 'table_viewport'),
        ).toEqual([])

        // Subscribe reads the old controller before Vue unmounts it. With no known
        // window, only the route can be reported; the server keeps its held/default window.
        h.navigate(test.page, { documentKey: 'terms' })
        expect(h.frames.at(-1)).toEqual({
          type: 'page_subscribe',
          page: test.page,
          params: { documentKey: 'terms' },
        })
        departing.dispose()
        h.project(refusal)
        mounted = test.create(h.context, createSignal('terms'))
        mounted.start()
        expect(mounted.controller.frame.body.get()).toBe('unavailable')
        h.connection.close()
        h.connection.connect()
        h.emit('open')
        h.pages.releaseOnSession()
        expect(h.frames.at(-1)).toEqual({
          type: 'page_subscribe',
          page: test.page,
          params: { documentKey: 'terms' },
        })
        h.project(refusal)
        expect(mounted.controller.descriptor()).toBeNull()
        expect(mounted.controller.descriptor(true)).toBeNull()

        const rows = [
          test.row,
          { ...test.row, rowKey: 'next' },
          { ...test.row, rowKey: 'last' },
        ]
        const before = h.frames.length
        h.project(
          documentWindow(
            test.page,
            test.table,
            rows,
            test.field,
            test.direction,
          ),
        )
        expect(mounted.controller.frame.body.get()).toBe('rows')
        expect(mounted.controller.frame.refusal.get()).toBeNull()
        expect(
          mounted.controller.rows.get().map((row) => row.row?.rowKey),
        ).toEqual([test.row.rowKey, 'next', 'last'])
        expect(mounted.controller.order.get()).toEqual([
          { field: test.field, direction: test.direction },
        ])
        expect(mounted.controller.descriptor()).toMatchObject({
          filter: { document: 'terms' },
          sort: [{ field: test.field, direction: test.direction }],
          limit: 25,
        })
        expect(mounted.controller.frame.footer.get()).toMatchObject({
          totalCount: 3,
          firstRow: 1,
          lastRow: 3,
        })
        expect(h.frames.slice(before).map(({ type }) => type)).toEqual([
          'table_rendered',
        ])
        expect(h.frames.some(({ type }) => type === 'table_viewport')).toBe(
          false,
        )
        expect(h.actions).toEqual([])
        h.pages.subscribe(test.page, { documentKey: 'terms' })
        expect(h.frames.at(-1)?.tableWindows).toEqual({
          [test.table]: mounted.controller.descriptor(),
        })
      } finally {
        mounted?.dispose()
        departing.dispose()
        h.dispose()
      }
    },
  )

  it('declares the dedicated revision metadata and lets Apply settle a removed historical row', () => {
    const h = harness()
    h.context.scopes.openPage(HilosPages.LEGAL_DOCUMENT_REVISIONS)
    const table = createHilosLegalRevisionsTable(
      h.context,
      createSignal('terms'),
      HilosPages.LEGAL_DOCUMENT_REVISIONS,
    )
    table.start()
    h.project(
      documentWindow(
        HilosPages.LEGAL_DOCUMENT_REVISIONS,
        'hilosLegalRevisions',
        [
          {
            rowKey: 'gone',
            slots: {
              revision: {
                declared: false,
                revision: null,
                publishedOn: null,
                effectiveOn: null,
                heldCount: 3,
                acceptedCount: 3,
              },
            },
          },
        ],
        'publishedOn',
        'desc',
      ),
    )
    expect(
      table.controller.frame.columns
        .get()
        .map(({ key, label }) => ({ key, label })),
    ).toEqual([
      { key: 'publishedOn', label: 'Revision' },
      { key: 'significance', label: 'Significance' },
      { key: 'effectiveOn', label: 'Effective' },
      { key: 'setVersion', label: 'Set' },
      { key: 'heldCount', label: 'Held by' },
      { key: 'actions', label: '' },
    ])
    expect(h.rendered[0]?.keys).toEqual([
      'publishedOn',
      'rowKey',
      'current',
      'declared',
      'significance',
      'revision',
      'effectiveOn',
      'setVersion',
      'heldCount',
    ])
    table.controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'gone',
      reason: 'deleted',
    })
    expect(table.controller.pendingCount.get()).toBe(1)
    expect(table.controller.rows.get()).toHaveLength(1)
    table.controller.apply()
    expect(table.controller.rows.get()[0]?.placeholder).toBe(true)
    expect(table.controller.rows.get()[0]?.row).toBeNull()
    expect(table.controller.pendingCount.get()).toBe(0)
    table.dispose()
  })

  it('uses the route-scoped first window for an exact historical revision', () => {
    const h = harness()
    h.context.scopes.openPage(HilosPages.LEGAL_REVISION)
    const table = createHilosLegalRevisionsTable(
      h.context,
      createSignal('retired'),
      HilosPages.LEGAL_REVISION,
      createSignal('gone'),
    )
    table.start()
    h.project(
      documentWindow(
        HilosPages.LEGAL_REVISION,
        'hilosLegalRevisions',
        [
          {
            rowKey: 'gone',
            slots: { revision: { declared: false, acceptedCount: 12 } },
          },
        ],
        'publishedOn',
        'desc',
      ),
    )
    expect(table.controller.rows.get()[0]?.row?.acceptedCount).toBe(12)
    expect(table.controller.descriptor()?.filter).toEqual({
      document: 'retired',
      revision: 'gone',
    })
    expect(h.sent).toEqual([])
    table.dispose()
  })
})

describe('legal administration rows and sections', () => {
  it.each(['constructor', '__proto__', 'toString', '123', 'retired'])(
    'keeps the literal historical document key %s',
    (key) => {
      expect(hilosLegalDocumentLabel(key)).toBe(key)
    },
  )

  it('retains server counts and revision metadata', () => {
    expect(
      resolveHilosLegalDocumentRow({
        rowKey: 'terms',
        slots: {
          document: {
            documentLabel: 'Terms of use',
            publishedOn: '2026-09-27',
            declared: true,
            revision,
            covered: 4,
            window: 2,
            lapsed: 1,
          },
        },
      }),
    ).toEqual({
      rowKey: 'terms',
      documentLabel: 'Terms of use',
      publishedOn: '2026-09-27',
      declared: true,
      revision,
      covered: 4,
      window: 2,
      lapsed: 1,
    })
    expect(
      resolveHilosLegalDocumentRow({
        rowKey: 'retired',
        slots: {
          document: {
            documentLabel: 'retired',
            publishedOn: null,
            declared: false,
            revision: null,
            covered: 0,
            window: 0,
            lapsed: 0,
          },
        },
      }),
    ).toEqual({
      rowKey: 'retired',
      documentLabel: 'retired',
      publishedOn: null,
      declared: false,
      revision: null,
      covered: 0,
      window: 0,
      lapsed: 0,
    })
    expect(
      resolveHilosLegalRevisionRow({
        rowKey: 'gone',
        slots: {
          revision: {
            declared: false,
            revision: null,
            current: false,
            origin: null,
            heldCount: 9,
            acceptedCount: 12,
          },
        },
      }),
    ).toEqual({
      rowKey: 'gone',
      declared: false,
      revision: null,
      publishedOn: null,
      effectiveOn: null,
      significance: null,
      setVersion: null,
      current: false,
      origin: null,
      heldCount: 9,
      acceptedCount: 12,
    })
    expect(
      resolveHilosLegalSettingRow({
        rowKey: 'legal.consent_form',
        slots: { setting: { value: 'line', defaultValue: 'checkbox' } },
      }),
    ).toEqual({
      rowKey: 'legal.consent_form',
      value: 'line',
      defaultValue: 'checkbox',
    })
  })

  it('reads the personal fields sent hidden as the one hidden value (HIL-1260)', () => {
    const acceptance = resolveHilosLegalAcceptanceRow({
      rowKey: '43',
      slots: {
        acceptance: {
          userId: 9,
          name: { _hidden: true },
          email: { _hidden: true },
          document: 'terms',
          revisionId: 'old',
          declared: true,
          acceptedAt: 1790510400000,
        },
      },
    })
    expect(acceptance.name).toBe(HIDDEN_VALUE)
    expect(acceptance.email).toBe(HIDDEN_VALUE)
    expect(acceptance.acceptedAt).toBe(
      formatMoment(1790510400000, { kind: 'dateTime' }),
    )

    const setting = resolveHilosLegalSettingRow({
      rowKey: 'legal.refusal_after_deadline',
      slots: {
        setting: { value: { _hidden: true }, defaultValue: { _hidden: true } },
      },
    })
    expect(setting.value).toBe(HIDDEN_VALUE)
    expect(setting.defaultValue).toBe(HIDDEN_VALUE)
  })

  it.each([
    ['remind', 'Past deadline'],
    ['hidden', 'Past deadline'],
    ['freeze', 'Frozen'],
    ['unread', 'Frozen'],
  ] as const)(
    'labels the lapsed count under a %s refusal setting "%s" (HIL-1260)',
    (refusal, label) => {
      const value =
        refusal === 'hidden'
          ? HIDDEN_VALUE
          : refusal === 'unread'
            ? undefined
            : refusal
      expect(hilosLegalLapsedLabel(value)).toBe(label)
    },
  )

  it.each([true, false, null])(
    'preserves declared=%s for acceptance records',
    (declared) => {
      const row = resolveHilosLegalAcceptanceRow({
        rowKey: '42',
        slots: {
          acceptance: {
            userId: 9,
            name: 'Reader',
            email: null,
            document: 'terms',
            revisionId: 'old',
            declared,
            acceptedAt: 1790510400000,
          },
        },
      })
      expect(row).toEqual({
        rowKey: 42,
        userId: 9,
        name: 'Reader',
        email: null,
        document: 'terms',
        revisionId: 'old',
        declared,
        acceptedAt: formatMoment(1790510400000, { kind: 'dateTime' })!,
      })
    },
  )

  it.each([
    ['null', null],
    ['undefined', undefined],
    ['old SQL string', '2026-09-27 12:00:00'],
    ['numeric string', '1790510400000'],
    ['NaN', Number.NaN],
    ['Infinity', Number.POSITIVE_INFINITY],
    ['out of date range', 1e16],
  ])(
    'formats invalid or non-numeric acceptedAt (%s) as em-dash',
    (_, acceptedAt) => {
      const row = resolveHilosLegalAcceptanceRow({
        rowKey: '42',
        slots: {
          acceptance: {
            userId: 9,
            name: 'Reader',
            email: null,
            document: 'terms',
            revisionId: 'old',
            declared: true,
            acceptedAt,
          },
        },
      })
      expect(row.acceptedAt).toBe('—')
    },
  )

  it('formats numeric 0 as the valid epoch moment rather than fallback', () => {
    const row = resolveHilosLegalAcceptanceRow({
      rowKey: '42',
      slots: {
        acceptance: {
          userId: 9,
          name: 'Reader',
          email: null,
          document: 'terms',
          revisionId: 'old',
          declared: true,
          acceptedAt: 0,
        },
      },
    })
    expect(row.acceptedAt).toBe(formatMoment(0, { kind: 'dateTime' }))
    expect(row.acceptedAt).not.toBe('—')
  })

  it('formats acceptedAt in UTC, Europe/Kyiv, and Asia/Tokyo with calendar day transition and DST', () => {
    const h = harness()
    const release = bindTimeZoneState(h.context.connection)
    try {
      const lateMoment = 1790548200000 // 2026-09-27 22:30:00 UTC
      const tableRow = {
        rowKey: '1',
        slots: {
          acceptance: {
            userId: 1,
            name: 'Reader',
            email: null,
            document: 'terms',
            revisionId: 'current',
            declared: true,
            acceptedAt: lateMoment,
          },
        },
      }

      h.choose('UTC')
      const utcRow = resolveHilosLegalAcceptanceRow(tableRow)
      expect(utcRow.acceptedAt).toBe(
        formatMoment(lateMoment, { kind: 'dateTime' }),
      )
      expect(utcRow.acceptedAt).toContain('Sep 27, 2026')

      h.choose('Europe/Kyiv')
      const kyivRow = resolveHilosLegalAcceptanceRow(tableRow)
      expect(kyivRow.acceptedAt).toBe(
        formatMoment(lateMoment, { kind: 'dateTime' }),
      )
      expect(kyivRow.acceptedAt).toContain('Sep 28, 2026')

      h.choose('Asia/Tokyo')
      const tokyoRow = resolveHilosLegalAcceptanceRow(tableRow)
      expect(tokyoRow.acceptedAt).toBe(
        formatMoment(lateMoment, { kind: 'dateTime' }),
      )
      expect(tokyoRow.acceptedAt).toContain('Sep 28, 2026')

      // DST transition in Europe/Kyiv (2026-10-25)
      // 2026-10-25 00:30:00 UTC (EEST, UTC+3 -> 03:30)
      const summerMoment = 1792888200000
      h.choose('Europe/Kyiv')
      const summerRow = resolveHilosLegalAcceptanceRow({
        rowKey: '2',
        slots: {
          acceptance: {
            ...tableRow.slots.acceptance,
            acceptedAt: summerMoment,
          },
        },
      })
      expect(summerRow.acceptedAt).toContain('3:30:00')

      // 2026-10-25 02:30:00 UTC (EET, UTC+2 -> 04:30)
      const winterMoment = 1792895400000
      const winterRow = resolveHilosLegalAcceptanceRow({
        rowKey: '3',
        slots: {
          acceptance: {
            ...tableRow.slots.acceptance,
            acceptedAt: winterMoment,
          },
        },
      })
      expect(winterRow.acceptedAt).toContain('4:30:00')
    } finally {
      release()
    }
  })

  it('accepts historical page data with no invented text', () => {
    expect(
      legalAdminRevisionSchema.safeParse({
        document: 'terms',
        revisionId: 'gone',
        declared: false,
        revision: null,
        current: null,
        predecessorId: null,
        clauses: null,
        changes: null,
      }).success,
    ).toBe(true)
    expect(
      legalAdminDocumentSchema.safeParse({
        document: 'retired',
        declared: false,
        covered: 0,
        window: 0,
        lapsed: 0,
        standardSetCatalogClass: 'Hilos\\Legal\\StandardSetCatalog',
        projectCatalogClass: null,
        revision: null,
        set: null,
        newerSet: null,
        deviations: [],
      }).success,
    ).toBe(true)
    expect(
      legalAcceptanceFiltersSchema.safeParse({
        documents: [
          {
            document: 'retired',
            declared: null,
            revisions: [{ revisionId: 'gone', declared: null }],
          },
        ],
      }).success,
    ).toBe(true)
    expect(
      legalAcceptanceFiltersSchema.safeParse({
        documents: [{ document: 'terms' }],
      }).success,
    ).toBe(false)
  })

  it('describes each backend check without recomputing its verdict', () => {
    expect(
      describeHilosLegalCheck({
        rowKey: 'undeclared_revision',
        ok: false,
        items: [{ document: 'terms', revisionId: 'gone', people: 3 }],
      }).lines[0],
    ).toContain('3 people')
    expect(
      describeHilosLegalCheck({
        rowKey: 'zero_window',
        ok: false,
        items: [{ document: 'terms', revisionId: 'new' }],
      }).lines[0],
    ).toContain('publication')
    expect(
      describeHilosLegalCheck({
        rowKey: 'newer_standard_set',
        ok: false,
        items: [
          {
            document: 'terms',
            setVersion: 2,
            documentSetVersion: 1,
            significance: 'substantial',
          },
        ],
      }).lines[0],
    ).toContain('set 2 (substantial)')
    expect(
      describeHilosLegalCheck({
        rowKey: 'deviations',
        ok: false,
        items: [{ document: 'privacy', deviations: 0 }],
      }).lines[0],
    ).toContain('no differences')
  })
})

describe('legal table controllers', () => {
  it('keeps the documents table on the legacy root page by default', () => {
    const h = harness()
    const table = createHilosLegalDocumentsTable(h.context)
    table.start()
    table.controller.refresh()

    expect(h.sent.at(-1)).toMatchObject({
      page: HilosPages.LEGAL,
      table: HilosLegalTableKey.documents,
    })

    expect(table.controller.frame.columns.get().map(({ key }) => key)).toEqual([
      'rowKey',
      'revision',
      'covered',
      'window',
      'lapsed',
      'actions',
    ])
    table.dispose()
  })

  it('declares the Documents search, sortable scalar keys, rendered reads and live policy header', () => {
    const h = harness()
    const policy = createSignal<Hideable<string> | undefined>('freeze')
    const table = createHilosLegalDocumentsTable(
      h.context,
      HilosPages.LEGAL_DOCUMENTS,
      policy,
    )
    const frame = table.controller.frame
    expect(frame.searchPlaceholder.get()).toBe('Document or key')
    expect(
      frame.columns
        .get()
        .map(({ key, sortable, reads }) => ({ key, sortable, reads })),
    ).toEqual([
      { key: 'documentLabel', sortable: true, reads: ['rowKey', 'declared'] },
      { key: 'publishedOn', sortable: true, reads: ['revision', 'declared'] },
      { key: 'covered', sortable: true, reads: undefined },
      { key: 'window', sortable: true, reads: undefined },
      { key: 'lapsed', sortable: true, reads: ['rowKey'] },
      { key: 'actions', sortable: undefined, reads: ['rowKey'] },
    ])
    for (const [value, label] of [
      ['freeze', 'Frozen'],
      ['remind', 'Past deadline'],
      [HIDDEN_VALUE, 'Past deadline'],
    ] as const) {
      policy.set(value)
      expect(
        frame.columns.get().find(({ key }) => key === 'lapsed')?.label,
      ).toBe(label)
    }
    table.dispose()
  })

  it('takes the opening window and sort from the page response without asking for a viewport', () => {
    const h = harness()
    h.context.scopes.openPage(HilosPages.LEGAL_DOCUMENTS)
    const table = createHilosLegalDocumentsTable(
      h.context,
      HilosPages.LEGAL_DOCUMENTS,
    )
    table.start()
    expect(h.sent).toEqual([])
    expect(table.controller.order.get()).toBeUndefined()
    h.project({
      type: 'page_response',
      data: {
        page: 'hilos_legal_documents',
        payload: {
          windows: {
            hilosLegalDocuments: {
              rows: [
                {
                  rowKey: 'terms',
                  slots: {
                    document: {
                      documentLabel: 'Server name',
                      publishedOn: '2026-09-27',
                      declared: true,
                      revision,
                      covered: 4,
                      window: 2,
                      lapsed: 1,
                    },
                  },
                },
              ],
              totalCount: 1,
              totalExact: true,
              firstAnchor: null,
              lastAnchor: null,
              limit: 25,
              sort: [{ field: 'publishedOn', direction: 'desc' }],
            },
          },
        },
      },
    })
    expect(table.controller.rows.get()[0]?.row?.documentLabel).toBe(
      'Server name',
    )
    expect(table.controller.order.get()).toEqual([
      { field: 'publishedOn', direction: 'desc' },
    ])
    expect(h.sent).toEqual([])
    table.controller.setSort('covered')
    expect(h.sent.at(-1)).toMatchObject({
      page: 'hilos_legal_documents',
      table: 'hilosLegalDocuments',
      descriptor: { sort: [{ field: 'covered', direction: 'asc' }] },
    })
    table.dispose()
    expect(h.listenerCount()).toBe(0)
  })

  it('shows zero after the last historical acceptance disappears and distinguishes a refused read', () => {
    const h = harness()
    const revisionId = createSignal('gone')
    const table = createHilosLegalRevisionsTable(
      h.context,
      createSignal('terms'),
      'hilos_legal_revision',
      revisionId,
    )
    const summary = createHilosLegalRevisionAcceptanceSummary(
      table.controller,
      revisionId,
    )
    expect(summary.get()).toBe('Loading acceptance count…')
    table.controller.ingestWindow(
      [{ rowKey: 'gone', slots: { revision: { acceptedCount: 1 } } }],
      1,
      true,
      null,
      null,
      25,
    )
    expect(summary.get()).toBe('1 acceptances')
    table.controller.ingestWindow([], 0, true, null, null, 25)
    expect(summary.get()).toBe('0 acceptances')
    table.controller.ingestRefusal('internal_error')
    expect(summary.get()).toBe('Acceptance count unavailable.')
  })

  it('reads the complete early vocabulary and atomically clears a revision on document changes', () => {
    const h = harness(vocabulary)
    const table = createHilosLegalAcceptancesTable(h.context)
    table.start()
    const filters = table.controller.frame.filters
      .get()
      .map(({ filter }) => filter)
    const documents = filters[0]
    const revisions = filters[1]
    expect(documents?.kind).toBe('select')
    expect(revisions?.kind).toBe('select')
    if (documents?.kind !== 'select' || revisions?.kind !== 'select')
      throw new Error('Expected selects')
    expect(documents.options().map((option) => option.value)).toEqual([
      'terms',
      'privacy',
    ])
    expect(revisions.options()).toEqual([])
    table.controller.ingestWindow([], 0, true, null, null, 25)
    table.controller.setFilter('document', 'terms')
    expect(revisions.options()).toEqual([
      { value: 'current', label: 'current' },
      { value: 'off-window', label: 'off-window (not in code)' },
    ])
    table.controller.setFilter('revision', 'off-window')
    h.sent.length = 0
    table.controller.setFilter('document', 'privacy')
    expect(table.controller.filter.get()).toEqual({ document: 'privacy' })
    expect(h.sent).toHaveLength(1)
    expect(h.sent[0]?.descriptor.filter).toEqual({ document: 'privacy' })
    expect(revisions.options()).toEqual([
      { value: 'privacy-old', label: 'privacy-old' },
    ])
    h.emit({
      documents: [
        {
          document: 'privacy',
          declared: null,
          revisions: [{ revisionId: 'new-on-record', declared: null }],
        },
      ],
    })
    expect(revisions.options()).toEqual([
      { value: 'new-on-record', label: 'new-on-record' },
    ])
    expect(h.facets.at(-1)).toEqual({
      document: ['privacy'],
      revision: ['new-on-record'],
    })
    table.dispose()
    expect(h.listenerCount()).toBe(0)
    h.emit(vocabulary)
    expect(revisions.options()).toEqual([
      { value: 'new-on-record', label: 'new-on-record' },
    ])
  })

  it('keeps the revision table narrowed when the route document changes', () => {
    const h = harness()
    h.context.scopes.openPage(HilosPages.LEGAL_DOCUMENT)
    const document = createSignal('terms')
    const table = createHilosLegalRevisionsTable(h.context, document)
    h.project(
      documentWindow(
        HilosPages.LEGAL_DOCUMENT,
        HilosLegalTableKey.revisions,
        [],
        'publishedOn',
        'desc',
      ),
    )
    table.start()
    expect(table.controller.filter.get()).toEqual({ document: 'terms' })
    document.set('privacy')
    expect(table.controller.filter.get()).toEqual({ document: 'privacy' })
    expect(h.sent.at(-1)?.descriptor).toMatchObject({
      filter: { document: 'privacy' },
      limit: 25,
      sort: [{ field: 'publishedOn', direction: 'desc' }],
    })
    table.dispose()
    document.set('terms')
    expect(table.controller.filter.get()).toEqual({ document: 'privacy' })
  })

  it('requests the exact revision on its detail page, even beyond the history window', () => {
    const h = harness()
    h.context.scopes.openPage(HilosPages.LEGAL_REVISION)
    const document = createSignal('terms')
    const revision = createSignal('old')
    const table = createHilosLegalRevisionsTable(
      h.context,
      document,
      'hilos_legal_revision',
      revision,
    )
    h.project(
      documentWindow(
        HilosPages.LEGAL_REVISION,
        HilosLegalTableKey.revisions,
        [],
        'publishedOn',
        'desc',
      ),
    )
    table.start()
    expect(table.controller.filter.get()).toEqual({
      document: 'terms',
      revision: 'old',
    })
    revision.set('gone')
    expect(table.controller.filter.get()).toEqual({
      document: 'terms',
      revision: 'gone',
    })
    expect(h.sent.at(-1)?.descriptor).toMatchObject({
      filter: { document: 'terms', revision: 'gone' },
      limit: 25,
      sort: [{ field: 'publishedOn', direction: 'desc' }],
    })
    table.dispose()
  })

  it('sends only the named legal setting and selected value', () => {
    const h = harness()
    createHilosLegalSettingsActions(h.context).sendSettingSet(
      'legal.consent_form',
      'line',
    )
    expect(h.actions).toEqual([
      {
        name: 'legal_setting_set',
        payload: { key: 'legal.consent_form', value: 'line' },
      },
    ])
  })

  it('updates display labels in controller.rows and focusedRow on timezone change without sendViewport', () => {
    const h = harness(vocabulary)
    const release = bindTimeZoneState(h.context.connection)
    try {
      h.choose('UTC')
      const table = createHilosLegalAcceptancesTable(h.context)
      table.start()
      const rawMoment = 1790548200000 // 2026-09-27 22:30:00 UTC
      table.controller.ingestWindow(
        [
          {
            rowKey: '100',
            slots: {
              acceptance: {
                userId: 42,
                name: 'Focused acceptor',
                email: 'acceptor@example.com',
                document: 'terms',
                revisionId: 'current',
                declared: true,
                acceptedAt: rawMoment,
              },
            },
          },
        ],
        1,
        true,
        null,
        null,
        25,
      )
      table.controller.focusRow('100')

      expect(table.controller.rows.get()[0]?.row?.acceptedAt).toContain(
        'Sep 27, 2026',
      )
      expect(table.controller.focusedRow.get()?.acceptedAt).toContain(
        'Sep 27, 2026',
      )

      h.sent.length = 0

      h.choose('Europe/Kyiv')

      expect(h.sent).toHaveLength(0)
      expect(table.controller.rows.get()[0]?.row?.acceptedAt).toContain(
        'Sep 28, 2026',
      )
      expect(table.controller.focusedRow.get()?.acceptedAt).toContain(
        'Sep 28, 2026',
      )

      table.dispose()
    } finally {
      release()
    }
  })
})

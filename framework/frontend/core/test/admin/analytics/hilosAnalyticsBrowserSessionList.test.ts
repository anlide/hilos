import { afterEach, describe, expect, it, vi } from 'vitest'
import { ZodError } from 'zod'
import {
  ANALYTICS_BROWSER_SESSION_LIST_TABLE,
  ANALYTICS_BROWSER_SESSION_LIST_SLOT,
  ANALYTICS_BROWSER_SESSIONS_PERIOD_ROUTE_PARAM,
  ANALYTICS_BROWSER_SESSION_LIST_ROW_KEY_FIELD,
  ANALYTICS_BROWSER_SESSION_LIST_LAST_SIGNED_IN_USER_ID_FIELD,
  ANALYTICS_BROWSER_SESSION_LIST_LAST_SIGNED_IN_USER_NAME_FIELD,
  ANALYTICS_BROWSER_SESSION_LIST_BROWSER_FIELD,
  ANALYTICS_BROWSER_SESSION_LIST_FIRST_SEEN_TS_FIELD,
  ANALYTICS_BROWSER_SESSION_LIST_LAST_SEEN_TS_FIELD,
  ANALYTICS_BROWSER_SESSION_LIST_ACTION_COUNT_FIELD,
  createHilosAnalyticsBrowserSessionListTable,
  analyticsBrowserSessionsPath,
  readAnalyticsBrowserSessionsAddress,
  formatAnalyticsBrowserSessionListPerson,
  formatAnalyticsBrowserSessionListTime,
  resolveHilosAnalyticsBrowserSessionListRow,
  type HilosAnalyticsBrowserSessionListTable,
} from '../../../src/admin/analytics/index.js'
import { type HilosAnalyticsContext } from '../../../src/admin/analytics/hilosAnalytics.js'
import {
  type HilosConnection,
  type TableViewportDescriptor,
  type TableWindowDescriptorSource,
} from '../../../src/connection/HilosConnection.js'
import { formatSnapshotMoment } from '../../../src/format/moment.js'
import { parseSignalValue } from '../../../src/protocol/parseSignal.js'
import {
  pageResponseSchema,
  type PageResponseWire,
  type TableWindowSectionWire,
} from '../../../src/protocol/scopePayload.js'
import { createAppPageRouter } from '../../../src/routing/appPageRouter.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { createSignal } from '../../../src/state/signal.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'
import { hilosTimeZone } from '../../../src/timeZone/timeZoneState.js'

const CONTEXT = {
  timeZone: 'Europe/Warsaw',
  weekStartTs: Date.parse('2026-10-11T22:00:00Z'),
  dayStartTs: Date.parse('2026-10-13T22:00:00Z'),
  hourStartTs: Date.parse('2026-10-14T20:00:00Z'),
}
const SESSION = {
  lastSignedInUserId: 12,
  lastSignedInUserName: 'Maria Petrova',
  browser: 'Firefox · Windows',
  firstSeenTs: Date.parse('2026-10-14T20:40:51Z'),
  actionCount: 7,
  lastSeenTs: Date.parse('2026-10-14T20:59:00Z'),
}
const TABLES: HilosAnalyticsBrowserSessionListTable[] = []

afterEach(() => {
  for (const table of TABLES.splice(0)) table.dispose()
  vi.restoreAllMocks()
  vi.useRealTimers()
})

function rawRow(slot: unknown = SESSION, rowKey = '9'): TableRow {
  return { rowKey, slots: { browserSession: slot } }
}

function windowSection(
  changes: Partial<TableWindowSectionWire> = {},
): TableWindowSectionWire {
  return {
    rows: [],
    totalCount: 0,
    totalExact: true,
    limit: 25,
    firstAnchor: null,
    lastAnchor: null,
    rowsBefore: 0,
    sort: [{ field: 'lastSeenTs', direction: 'desc' }],
    ...changes,
  }
}

const router = createAppPageRouter({})
const ROOT = '/hilos/analytics/browser-sessions'

function stubTable(tail = '', replay = false) {
  const page = HilosPages.ANALYTICS_BROWSER_SESSIONS
  const currentRoute = createSignal(router.match(`${ROOT}${tail}`))
  const replaced: string[] = []
  const address = {
    currentRoute,
    replacePath(path: string) {
      replaced.push(path)
      currentRoute.set(router.match(path))
    },
  }
  const sent: Array<{
    page: string
    tableKey: string
    descriptor: TableViewportDescriptor
  }> = []
  const rendered: Array<readonly string[]> = []
  const facets: Array<{
    page: string
    tableKey: string
    values: Readonly<Record<string, readonly unknown[]>>
  }> = []
  const listeners = new Map<string, Set<(signal: never) => void>>()
  const registered = new Map<string, TableWindowDescriptorSource>()
  const scopes = new ScopeManager()
  scopes.openPage(page)
  const context: HilosAnalyticsContext = {
    connection: {
      on(event: string, listener: (signal: never) => void) {
        const set = listeners.get(event) ?? new Set()
        listeners.set(event, set)
        set.add(listener)
        if (replay && event === 'projectSignal') {
          listener({
            kind: 'project',
            type: 'page_response',
            data: {
              page,
              payload: {
                windows: { hilosAnalyticsBrowserSessionList: windowSection() },
              },
            },
          } as never)
        }
        return () => set.delete(listener)
      },
      registerTableWindow(key: string, source: TableWindowDescriptorSource) {
        registered.set(key, source)
      },
      unregisterTableWindow(key: string, source: TableWindowDescriptorSource) {
        if (registered.get(key) === source) registered.delete(key)
      },
      sendTableViewport(
        target: string,
        tableKey: string,
        descriptor: TableViewportDescriptor,
      ) {
        sent.push({ page: target, tableKey, descriptor })
        return true
      },
      sendTableRendered(
        _target: string,
        _tableKey: string,
        fields: readonly string[],
      ) {
        rendered.push(fields)
        return true
      },
      sendTableFacets(
        target: string,
        tableKey: string,
        values: Readonly<Record<string, readonly unknown[]>>,
      ) {
        facets.push({ page: target, tableKey, values })
        return true
      },
    } as unknown as HilosConnection,
    scopes,
  }
  const table = createHilosAnalyticsBrowserSessionListTable(context, address, {
    periodContext: CONTEXT,
  })
  TABLES.push(table)

  function emit(
    event:
      | 'projectSignal'
      | 'tableWindow'
      | 'tableFacetCounts'
      | 'tableWindowRefused',
    envelope: { type: string; data: unknown },
  ): void {
    const parsed = parseSignalValue(envelope, {
      page_response: pageResponseSchema,
    })
    if (!parsed.ok) throw new Error(JSON.stringify(parsed.failure))
    expect(parsed.signal.kind).toBe(
      event === 'projectSignal' ? 'project' : event,
    )
    for (const listener of listeners.get(event) ?? [])
      listener(parsed.signal as never)
  }

  return {
    table,
    context,
    currentRoute,
    replaced,
    sent,
    rendered,
    facets,
    registered,
    listeners,
    emit,
    coldWindow(
      window = windowSection(),
      target: string = page,
      tableKey = 'hilosAnalyticsBrowserSessionList',
    ) {
      const data: PageResponseWire = {
        page: target,
        payload: { windows: { [tableKey]: window } },
      }
      emit('projectSignal', { type: 'page_response', data })
    },
    answer(
      window = windowSection(),
      requestId = sent.at(-1)?.descriptor.requestId,
    ) {
      emit('tableWindow', {
        type: 'table_window',
        data: {
          page,
          tableKey: 'hilosAnalyticsBrowserSessionList',
          ...window,
          requestId,
        },
      })
    },
  }
}

describe('Analytics browser-session list row boundary', () => {
  it('exports its concrete table, slot, route and row keys', () => {
    expect([
      ANALYTICS_BROWSER_SESSION_LIST_TABLE,
      ANALYTICS_BROWSER_SESSION_LIST_SLOT,
      ANALYTICS_BROWSER_SESSIONS_PERIOD_ROUTE_PARAM,
      ANALYTICS_BROWSER_SESSION_LIST_ROW_KEY_FIELD,
      ANALYTICS_BROWSER_SESSION_LIST_LAST_SIGNED_IN_USER_ID_FIELD,
      ANALYTICS_BROWSER_SESSION_LIST_LAST_SIGNED_IN_USER_NAME_FIELD,
      ANALYTICS_BROWSER_SESSION_LIST_BROWSER_FIELD,
      ANALYTICS_BROWSER_SESSION_LIST_FIRST_SEEN_TS_FIELD,
      ANALYTICS_BROWSER_SESSION_LIST_LAST_SEEN_TS_FIELD,
      ANALYTICS_BROWSER_SESSION_LIST_ACTION_COUNT_FIELD,
    ]).toEqual([
      'hilosAnalyticsBrowserSessionList',
      'browserSession',
      'period',
      'rowKey',
      'lastSignedInUserId',
      'lastSignedInUserName',
      'browser',
      'firstSeenTs',
      'lastSeenTs',
      'actionCount',
    ])
  })

  it('reads only fragment identity, preserves raw names, nulls and zero counts', () => {
    expect(
      resolveHilosAnalyticsBrowserSessionListRow(
        rawRow({
          ...SESSION,
          id: 999,
          rowKey: 888,
          lastSignedInUserName: '  Maria  ',
        }),
      ),
    ).toEqual({ rowKey: 9, ...SESSION, lastSignedInUserName: '  Maria  ' })
    expect(
      resolveHilosAnalyticsBrowserSessionListRow(
        rawRow({
          ...SESSION,
          lastSignedInUserId: null,
          lastSignedInUserName: null,
          browser: null,
          firstSeenTs: 0,
          lastSeenTs: 0,
          actionCount: 0,
        }),
      ),
    ).toEqual({
      rowKey: 9,
      lastSignedInUserId: null,
      lastSignedInUserName: null,
      browser: null,
      firstSeenTs: 0,
      lastSeenTs: 0,
      actionCount: 0,
    })
    expect(
      resolveHilosAnalyticsBrowserSessionListRow(
        rawRow(SESSION, String(Number.MAX_SAFE_INTEGER)),
      ).rowKey,
    ).toBe(Number.MAX_SAFE_INTEGER)
  })

  it.each([
    '',
    '0',
    '-1',
    '1.5',
    '1e2',
    ' 9',
    '9 ',
    '9\n',
    '9\r',
    '+9',
    '09',
    'NaN',
    'Infinity',
    '9007199254740992',
  ])('rejects a malformed fragment identity %s', (key) => {
    expect(() =>
      resolveHilosAnalyticsBrowserSessionListRow(rawRow(SESSION, key)),
    ).toThrow(ZodError)
  })

  it.each(Object.keys(SESSION))(
    'requires %s, accepts Hidden and rejects malformed hidden values',
    (field) => {
      const hidden = resolveHilosAnalyticsBrowserSessionListRow(
        rawRow({
          ...SESSION,
          [field]: { _hidden: true },
        }),
      )
      expect(hidden).toHaveProperty(field, HIDDEN_VALUE)
      const missing: Record<string, unknown> = { ...SESSION }
      delete missing[field]
      expect(() =>
        resolveHilosAnalyticsBrowserSessionListRow(rawRow(missing)),
      ).toThrow(ZodError)
      for (const value of [
        undefined,
        [],
        {},
        { _hidden: false },
        { _hidden: 'yes' },
        { _hidden: true, extra: 1 },
      ]) {
        expect(() =>
          resolveHilosAnalyticsBrowserSessionListRow(
            rawRow({
              ...SESSION,
              [field]: value,
            }),
          ),
        ).toThrow(ZodError)
      }
    },
  )

  it.each(['lastSignedInUserId', 'firstSeenTs', 'lastSeenTs', 'actionCount'])(
    'requires safe nonnegative integers for %s and a positive account id',
    (field) => {
      for (const value of [
        '7',
        -1,
        0.5,
        NaN,
        Infinity,
        Number.MAX_SAFE_INTEGER + 1,
      ]) {
        expect(() =>
          resolveHilosAnalyticsBrowserSessionListRow(
            rawRow({
              ...SESSION,
              [field]: value,
            }),
          ),
        ).toThrow(ZodError)
      }
      expect(
        resolveHilosAnalyticsBrowserSessionListRow(
          rawRow({
            ...SESSION,
            [field]: Number.MAX_SAFE_INTEGER,
          }),
        ),
      ).toHaveProperty(field, Number.MAX_SAFE_INTEGER)
      expect(() =>
        resolveHilosAnalyticsBrowserSessionListRow(
          rawRow({
            ...SESSION,
            [field]: field === 'lastSignedInUserId' ? 0 : null,
          }),
        ),
      ).toThrow(ZodError)
    },
  )

  it.each(
    [null, undefined, [], 'wrong', {}, { _hidden: true }].map((slot) => ({
      slot,
    })),
  )('rejects a malformed entire slot $slot', ({ slot }) => {
    expect(() =>
      resolveHilosAnalyticsBrowserSessionListRow({
        rowKey: '9',
        slots: { browserSession: slot },
      }),
    ).toThrow(ZodError)
  })

  it('rejects nonstrings, and never reads an unrelated slot', () => {
    for (const field of ['lastSignedInUserName', 'browser']) {
      expect(() =>
        resolveHilosAnalyticsBrowserSessionListRow(
          rawRow({ ...SESSION, [field]: 7 }),
        ),
      ).toThrow(ZodError)
    }
    expect(() =>
      resolveHilosAnalyticsBrowserSessionListRow({
        rowKey: '9',
        slots: { other: SESSION },
      }),
    ).toThrow(ZodError)
  })

  it('distinguishes signed-in, guest and deleted users without interpreting the raw name', () => {
    const row = resolveHilosAnalyticsBrowserSessionListRow(rawRow())
    expect(formatAnalyticsBrowserSessionListPerson(row)).toBe(
      'Maria Petrova (#12)',
    )
    expect(
      formatAnalyticsBrowserSessionListPerson({
        ...row,
        lastSignedInUserName: null,
      }),
    ).toBe('Deleted user #12')
    expect(
      formatAnalyticsBrowserSessionListPerson({
        ...row,
        lastSignedInUserId: null,
        lastSignedInUserName: null,
      }),
    ).toBe('Not signed in')
    expect(
      formatAnalyticsBrowserSessionListPerson({
        ...row,
        lastSignedInUserName: 'Deleted user #12',
      }),
    ).toBe('Deleted user #12 (#12)')
    for (const fields of [
      { lastSignedInUserId: HIDDEN_VALUE },
      { lastSignedInUserName: HIDDEN_VALUE },
      { lastSignedInUserId: HIDDEN_VALUE, lastSignedInUserName: HIDDEN_VALUE },
    ]) {
      expect(
        formatAnalyticsBrowserSessionListPerson({ ...row, ...fields }),
      ).toBe(HIDDEN_VALUE)
    }
    const hidden = resolveHilosAnalyticsBrowserSessionListRow(
      rawRow(
        Object.fromEntries(
          Object.keys(SESSION).map((key) => [key, { _hidden: true }]),
        ),
      ),
    )
    expect(hidden).toEqual({
      rowKey: 9,
      ...Object.fromEntries(
        Object.keys(SESSION).map((key) => [key, HIDDEN_VALUE]),
      ),
    })
  })

  it('formats seconds in the captured zone without reading a changed live zone', () => {
    const zone = vi
      .spyOn(hilosTimeZone, 'get')
      .mockReturnValue('Pacific/Honolulu')
    expect(
      formatAnalyticsBrowserSessionListTime(SESSION.lastSeenTs, CONTEXT),
    ).toBe(
      formatSnapshotMoment(SESSION.lastSeenTs, 'Europe/Warsaw', {
        kind: 'dateTime',
      }),
    )
    expect(formatAnalyticsBrowserSessionListTime(NaN, CONTEXT)).toBe('—')
    expect(zone).not.toHaveBeenCalled()
  })
})

describe('Analytics browser-session list period address', () => {
  it.each(['any-time', 'this-hour', 'today', 'this-week'] as const)(
    'round trips %s through the real list route',
    (period) => {
      const path = analyticsBrowserSessionsPath(period)
      expect(path).toBe(`${ROOT}/${period}`)
      expect(
        readAnalyticsBrowserSessionsAddress(router.match(path).params),
      ).toBe(period)
    },
  )

  it.each<Record<string, string>>([{}, { period: 'unknown' }, { period: '' }])(
    'defaults $period to Today',
    (params) => {
      expect(readAnalyticsBrowserSessionsAddress(params)).toBe('today')
    },
  )
})

describe('Analytics browser-session list controller', () => {
  it('declares the six accessible columns and the shared Period filter with no local UI controls', () => {
    const { table, sent, facets } = stubTable()
    expect(table.controller.frame.columns.get()).toEqual([
      { key: 'rowKey', label: 'Session', card: 'title' },
      {
        key: 'lastSignedInUserId',
        label: 'Signed in',
        reads: ['lastSignedInUserName'],
      },
      { key: 'browser', label: 'Browser' },
      { key: 'firstSeenTs', label: 'First seen' },
      { key: 'lastSeenTs', label: 'Last seen', sortable: true },
      { key: 'actionCount', label: 'Actions' },
    ])
    expect(table.controller.frame.filters.get()).toMatchObject([
      {
        filter: {
          kind: 'select',
          key: 'period',
          label: 'Period',
          anyLabel: 'Any time',
        },
        value: 'today',
      },
    ])
    expect(table.controller.frame.declaration).toMatchObject({
      empty: { title: 'No browser sessions in the selected period' },
    })
    expect(table.controller.frame.declaration.search).toBeUndefined()
    expect(table.controller.frame.declaration.mainAction).toBeUndefined()
    expect(table.controller.frame.declaration.bulkActions).toBeUndefined()
    expect(table.controller.selection.enabled).toBe(false)
    expect(table.controller.descriptor()).toBeNull()
    expect(sent).toEqual([])
    expect(facets).toEqual([])
  })

  it.each(['', '/unknown', '/this-hour', '/today', '/this-week', '/any-time'])(
    'sets the address preset %s before the first outgoing viewport',
    (tail) => {
      const fixture = stubTable(tail)
      fixture.table.start()
      fixture.table.start()
      expect(fixture.sent).toEqual([])
      fixture.coldWindow(windowSection(), 'other_page')
      fixture.coldWindow(
        windowSection(),
        HilosPages.ANALYTICS_BROWSER_SESSIONS,
        'other_table',
      )
      expect(fixture.sent).toEqual([])
      fixture.coldWindow()
      const choice = readAnalyticsBrowserSessionsAddress(
        router.match(`${ROOT}${tail}`).params,
      )
      const expected =
        choice === 'any-time' ? CONTEXT : { ...CONTEXT, period: choice }
      expect(fixture.sent).toHaveLength(1)
      expect(fixture.sent[0]).toMatchObject({
        page: 'hilos_analytics_browser_sessions',
        tableKey: 'hilosAnalyticsBrowserSessionList',
        descriptor: {
          filter: expected,
          limit: 25,
          sort: [{ field: 'lastSeenTs', direction: 'desc' }],
          rendered: [
            'rowKey',
            'lastSignedInUserId',
            'lastSignedInUserName',
            'browser',
            'firstSeenTs',
            'lastSeenTs',
            'actionCount',
          ],
        },
      })
      fixture.answer()
      expect(fixture.sent).toHaveLength(1)
      expect(fixture.replaced).toEqual([])
      expect(fixture.table.periodContext).toEqual(CONTEXT)
    },
  )

  it('can send its preset during a synchronous buffered page replay', () => {
    const fixture = stubTable('/this-week', true)
    fixture.table.start()
    expect(fixture.sent).toHaveLength(1)
    expect(fixture.sent[0]?.descriptor.filter).toEqual({
      ...CONTEXT,
      period: 'this-week',
    })
    expect(fixture.registered.get('hilosAnalyticsBrowserSessionList')).toBe(
      fixture.table.controller,
    )
  })

  it('syncs UI choice, reset, same-page navigation and Back/Forward without loops or extra asks', () => {
    const fixture = stubTable('/this-week')
    const { table, currentRoute, sent, replaced } = fixture
    table.start()
    fixture.coldWindow()
    fixture.answer()
    table.controller.setFilter('period', 'this-hour')
    expect(replaced).toEqual([`${ROOT}/this-hour`])
    expect(currentRoute.get().params).toEqual({ period: 'this-hour' })
    expect(sent).toHaveLength(2)
    table.controller.resetFilters()
    expect(replaced).toEqual([`${ROOT}/this-hour`, `${ROOT}/any-time`])
    expect(sent).toHaveLength(3)
    expect(sent.at(-1)?.descriptor.filter).toEqual(CONTEXT)
    expect(table.controller.frame.activeFilterCount.get()).toBe(0)
    expect(table.controller.frame.filters.get()[0]?.value).toBeUndefined()
    for (const [tail, period] of [
      ['/today', 'today'],
      ['/this-week', 'this-week'],
      ['/today', 'today'],
      ['/any-time', undefined],
      ['/unknown', 'today'],
    ] as const) {
      const before = sent.length
      currentRoute.set(router.match(`${ROOT}${tail}`))
      expect(sent).toHaveLength(before + 1)
      expect(table.controller.filter.get()).toEqual(
        period === undefined ? CONTEXT : { ...CONTEXT, period },
      )
    }
    const before = sent.length
    currentRoute.set(router.match(ROOT))
    currentRoute.set(router.match(`${ROOT}/today`))
    expect(sent).toHaveLength(before)
    expect(replaced).toHaveLength(2)
    expect(table.periodContext).toEqual(CONTEXT)
  })

  it('uses the actual route when it changes between construction and start', () => {
    const fixture = stubTable('/today')
    fixture.currentRoute.set(router.match(`${ROOT}/this-hour`))
    fixture.table.start()
    fixture.coldWindow()
    expect(fixture.sent).toHaveLength(1)
    expect(fixture.sent[0]?.descriptor.filter).toEqual({
      ...CONTEXT,
      period: 'this-hour',
    })
    expect(fixture.replaced).toEqual([])
  })

  it('ignores another page while its scope is leaving', () => {
    const fixture = stubTable()
    fixture.table.start()
    fixture.coldWindow()
    fixture.currentRoute.set(router.match('/hilos/analytics/people'))
    expect(fixture.sent).toHaveLength(1)
    fixture.table.controller.setFilter('period', 'this-week')
    expect(fixture.replaced).toEqual([])
  })

  it('keeps captured context through address/filter/sort changes after the live zone changes', () => {
    const fixture = stubTable()
    fixture.table.start()
    fixture.coldWindow()
    fixture.answer()
    vi.spyOn(hilosTimeZone, 'get').mockReturnValue('Pacific/Honolulu')
    fixture.currentRoute.set(router.match(`${ROOT}/this-week`))
    fixture.table.controller.setSort('lastSeenTs')
    expect(fixture.sent.at(-1)?.descriptor).toMatchObject({
      filter: { ...CONTEXT, period: 'this-week' },
      sort: [{ field: 'lastSeenTs', direction: 'asc' }],
    })
    expect(fixture.replaced).toEqual([])
    expect(fixture.table.periodContext).toEqual(CONTEXT)
  })

  it('keeps refusals and empty results in the standard table frame', () => {
    const fixture = stubTable()
    const { table, emit, sent } = fixture
    table.start()
    fixture.coldWindow()
    emit('tableWindowRefused', {
      type: 'table_window_refused',
      data: {
        page: HilosPages.ANALYTICS_BROWSER_SESSIONS,
        tableKey: 'hilosAnalyticsBrowserSessionList',
        errorCode: 'unavailable',
        requestId: sent.at(-1)?.descriptor.requestId,
      },
    })
    expect(table.controller.frame.body.get()).toBe('unavailable')
    table.controller.setFilter('period', 'this-hour')
    fixture.answer()
    expect(table.controller.frame.body.get()).toBe('empty_filtered')
    table.controller.resetFilters()
    fixture.answer()
    expect(table.controller.frame.body.get()).toBe('empty')
    expect(fixture.replaced).toEqual([`${ROOT}/this-hour`, `${ROOT}/any-time`])
  })

  it('handles hidden rows, viewer sorting, capped counts, facets and resubscription through the standard frame', () => {
    const fixture = stubTable('/any-time')
    const { table, emit, facets } = fixture
    table.start()
    fixture.coldWindow(windowSection({ sort: [] }))
    expect(fixture.sent[0]?.descriptor.sort).toBeNull()
    fixture.answer(
      windowSection({
        rows: [
          {
            rowKey: 9,
            slots: {
              browserSession: Object.fromEntries(
                Object.keys(SESSION).map((key) => [key, { _hidden: true }]),
              ),
            },
          },
        ],
        totalCount: 500,
        totalExact: false,
        firstAnchor: { rowKey: 9 },
        lastAnchor: { rowKey: 9 },
      }),
    )
    expect(table.controller.rows.get()[0]).toMatchObject({
      rowKey: '9',
      row: {
        rowKey: 9,
        lastSignedInUserId: HIDDEN_VALUE,
        lastSeenTs: HIDDEN_VALUE,
      },
    })
    expect(table.controller.frame.footer.get()).toMatchObject({
      totalCount: 500,
      totalExact: false,
      pageCount: null,
    })
    expect(facets).toEqual([
      {
        page: 'hilos_analytics_browser_sessions',
        tableKey: 'hilosAnalyticsBrowserSessionList',
        values: { period: ['this-hour', 'today', 'this-week'] },
      },
    ])
    const counts = {
      period: {
        any: { count: 500, exact: false },
        options: {
          today: { count: 7, exact: true },
          'this-hour': { count: 0, exact: true },
          'this-week': { count: 38, exact: true },
        },
      },
    }
    emit('tableFacetCounts', {
      type: 'table_facet_counts',
      data: {
        page: 'other_page',
        tableKey: 'hilosAnalyticsBrowserSessionList',
        facets: counts,
      },
    })
    expect(table.controller.frame.filters.get()[0]?.facets).toBeNull()
    emit('tableFacetCounts', {
      type: 'table_facet_counts',
      data: {
        page: HilosPages.ANALYTICS_BROWSER_SESSIONS,
        tableKey: 'hilosAnalyticsBrowserSessionList',
        facets: counts,
      },
    })
    expect(table.controller.frame.filters.get()[0]?.facets).toEqual({
      any: counts.period.any,
      options: new Map(Object.entries(counts.period.options)),
    })
    expect(table.controller.descriptor()).toMatchObject({
      filter: CONTEXT,
      sort: null,
      facets: { period: ['this-hour', 'today', 'this-week'] },
    })
    fixture.coldWindow()
    expect(facets).toHaveLength(2)
    expect(fixture.sent).toHaveLength(1)
    table.controller.setFilter('period', 'today')
    fixture.answer(windowSection({ totalCount: 500, totalExact: true }))
    expect(table.controller.frame.footer.get()).toMatchObject({
      totalCount: 500,
      totalExact: true,
      pageCount: 20,
    })
  })

  it.each(['dispose', 'same-page scope', 'different-page scope'])(
    'releases bindings, watchers, rows and timers on %s and cannot restart',
    (mode) => {
      vi.useFakeTimers()
      const fixture = stubTable()
      const {
        table,
        context,
        currentRoute,
        registered,
        listeners,
        sent,
        replaced,
      } = fixture
      table.start()
      fixture.coldWindow()
      fixture.answer(
        windowSection({
          rows: [{ rowKey: 9, slots: { browserSession: SESSION } }],
          totalCount: 1,
        }),
      )
      expect(table.controller.rows.get()).toHaveLength(1)
      table.controller.setFilter('period', 'this-week')
      expect(vi.getTimerCount()).toBeGreaterThan(0)
      if (mode === 'dispose') table.dispose()
      else
        context.scopes.openPage(
          mode === 'same-page scope'
            ? HilosPages.ANALYTICS_BROWSER_SESSIONS
            : HilosPages.ANALYTICS_PEOPLE,
        )
      table.dispose()
      table.start()
      expect(registered.size).toBe(0)
      expect([...listeners.values()].every((set) => set.size === 0)).toBe(true)
      expect(table.controller.rows.get()).toEqual([])
      expect(vi.getTimerCount()).toBe(0)
      const sentBefore = sent.length
      const replacedBefore = replaced.length
      currentRoute.set(router.match(`${ROOT}/this-hour`))
      expect(table.controller.filter.get()['period']).toBe('this-week')
      fixture.coldWindow()
      table.controller.setFilter('period', 'today')
      expect(sent).toHaveLength(sentBefore)
      expect(replaced).toHaveLength(replacedBefore)
    },
  )

  it('can dispose before start without any subscriptions or timer leak', () => {
    vi.useFakeTimers()
    const fixture = stubTable()
    fixture.table.dispose()
    fixture.table.start()
    expect(fixture.registered.size).toBe(0)
    expect(fixture.listeners.size).toBe(0)
    expect(vi.getTimerCount()).toBe(0)
    expect(fixture.sent).toEqual([])
  })
})

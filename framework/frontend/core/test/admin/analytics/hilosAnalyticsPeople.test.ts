import { afterEach, describe, expect, it, vi } from 'vitest'
import { ZodError } from 'zod'
import {
  ANALYTICS_PEOPLE_TABLE,
  ANALYTICS_PEOPLE_SLOT,
  ANALYTICS_PERSON_ROW_KEY_FIELD,
  ANALYTICS_PERSON_NAME_FIELD,
  ANALYTICS_PERSON_SESSION_COUNT_FIELD,
  ANALYTICS_PERSON_LAST_SEEN_TS_FIELD,
  createHilosAnalyticsPeopleTable,
  formatAnalyticsPeopleTime,
  formatAnalyticsPersonName,
  resolveHilosAnalyticsPersonRow,
  type AnalyticsPeriod,
  type HilosAnalyticsPeopleOptions,
  type HilosAnalyticsPeopleTable,
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
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { subscribeSignal } from '../../../src/state/signal.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'
import { hilosTimeZone } from '../../../src/timeZone/timeZoneState.js'

const CONTEXT = {
  timeZone: 'Europe/Warsaw',
  weekStartTs: Date.parse('2026-10-11T22:00:00Z'),
  dayStartTs: Date.parse('2026-10-13T22:00:00Z'),
  hourStartTs: Date.parse('2026-10-14T20:00:00Z'),
}
const PERSON = {
  name: 'Maria Petrova',
  sessionCount: 3,
  lastSeenTs: Date.parse('2026-10-14T20:59:00Z'),
}
const TABLES: HilosAnalyticsPeopleTable[] = []

afterEach(() => {
  for (const table of TABLES.splice(0)) table.dispose()
  vi.restoreAllMocks()
  vi.useRealTimers()
})

function rawRow(slot: unknown = PERSON, rowKey = '9'): TableRow {
  return { rowKey, slots: { person: slot } }
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

function stubTable(options: Partial<HilosAnalyticsPeopleOptions> = {}) {
  const page = options.page ?? HilosPages.ANALYTICS_PEOPLE
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
  const table = createHilosAnalyticsPeopleTable(context, {
    periodContext: CONTEXT,
    ...options,
    page,
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
    sent,
    rendered,
    facets,
    registered,
    listeners,
    emit,
    coldWindow(
      window = windowSection(),
      target = page,
      tableKey = 'hilosAnalyticsPeople',
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
        data: { page, tableKey: 'hilosAnalyticsPeople', ...window, requestId },
      })
    },
  }
}

describe('Analytics People row boundary', () => {
  it('exports Analytics-owned field and slot keys', () => {
    expect([
      ANALYTICS_PEOPLE_TABLE,
      ANALYTICS_PEOPLE_SLOT,
      ANALYTICS_PERSON_ROW_KEY_FIELD,
      ANALYTICS_PERSON_NAME_FIELD,
      ANALYTICS_PERSON_SESSION_COUNT_FIELD,
      ANALYTICS_PERSON_LAST_SEEN_TS_FIELD,
    ]).toEqual([
      'hilosAnalyticsPeople',
      'person',
      'rowKey',
      'name',
      'sessionCount',
      'lastSeenTs',
    ])
  })

  it('reads scalar fields and fragment identity, ignoring ids inside the slot', () => {
    expect(
      resolveHilosAnalyticsPersonRow(
        rawRow({ ...PERSON, rowKey: 999, userId: 888 }),
      ),
    ).toEqual({ rowKey: 9, ...PERSON })
    expect(
      resolveHilosAnalyticsPersonRow(
        rawRow(PERSON, String(Number.MAX_SAFE_INTEGER)),
      ).rowKey,
    ).toBe(Number.MAX_SAFE_INTEGER)
    expect(
      resolveHilosAnalyticsPersonRow(
        rawRow({ name: null, sessionCount: 0, lastSeenTs: 0 }),
      ),
    ).toEqual({ rowKey: 9, name: null, sessionCount: 0, lastSeenTs: 0 })
  })

  it('preserves null and the canonical Hidden values without inventing labels', () => {
    const hidden = resolveHilosAnalyticsPersonRow(
      rawRow({
        name: { _hidden: true },
        sessionCount: { _hidden: true },
        lastSeenTs: { _hidden: true },
      }),
    )
    expect(hidden.name).toBe(HIDDEN_VALUE)
    expect(hidden.sessionCount).toBe(HIDDEN_VALUE)
    expect(hidden.lastSeenTs).toBe(HIDDEN_VALUE)
    expect(hidden.rowKey).toBe(9)
    expect(formatAnalyticsPersonName(hidden)).toBe('#9')
    expect(formatAnalyticsPersonName({ rowKey: 9, ...PERSON })).toBe(
      'Maria Petrova (#9)',
    )
    expect(
      formatAnalyticsPersonName({ rowKey: 9, ...PERSON, name: null }),
    ).toBe('Deleted user #9')
    expect(
      resolveHilosAnalyticsPersonRow(
        rawRow({ ...PERSON, name: { _hidden: true } }),
      ),
    ).toEqual({ rowKey: 9, ...PERSON, name: HIDDEN_VALUE })
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
    '999999999999999999999999999999',
  ])('rejects a noncanonical or unsafe fragment id: %s', (rowKey) => {
    expect(() =>
      resolveHilosAnalyticsPersonRow(rawRow(PERSON, rowKey)),
    ).toThrow(ZodError)
  })

  it.each(
    [null, undefined, [], 'wrong', {}, { _hidden: true }].map((slot) => ({
      slot,
    })),
  )('rejects an absent, hidden or malformed person slot: $slot', ({ slot }) => {
    expect(() =>
      resolveHilosAnalyticsPersonRow({
        rowKey: '9',
        slots: { person: slot },
      }),
    ).toThrow(ZodError)
  })

  it.each(['name', 'sessionCount', 'lastSeenTs'])(
    'requires %s and rejects malformed hidden marks',
    (field) => {
      const slot: Record<string, unknown> = { ...PERSON }
      delete slot[field]
      expect(() => resolveHilosAnalyticsPersonRow(rawRow(slot))).toThrow(
        ZodError,
      )
      for (const value of [
        undefined,
        [],
        {},
        { _hidden: false },
        { _hidden: 'yes' },
        { _hidden: true, extra: 1 },
      ]) {
        expect(() =>
          resolveHilosAnalyticsPersonRow(rawRow({ ...PERSON, [field]: value })),
        ).toThrow(ZodError)
      }
    },
  )

  it.each(['sessionCount', 'lastSeenTs'])(
    'requires a nonnegative safe scalar integer in %s',
    (field) => {
      for (const value of [
        null,
        '7',
        -1,
        0.5,
        NaN,
        Infinity,
        Number.MAX_SAFE_INTEGER + 1,
      ]) {
        expect(() =>
          resolveHilosAnalyticsPersonRow(rawRow({ ...PERSON, [field]: value })),
        ).toThrow(ZodError)
      }
      expect(
        resolveHilosAnalyticsPersonRow(
          rawRow({ ...PERSON, [field]: Number.MAX_SAFE_INTEGER }),
        ),
      ).toHaveProperty(field, Number.MAX_SAFE_INTEGER)
    },
  )

  it('rejects an unrelated slot and a numeric name', () => {
    expect(() =>
      resolveHilosAnalyticsPersonRow({ rowKey: '9', slots: { other: PERSON } }),
    ).toThrow(ZodError)
    expect(() =>
      resolveHilosAnalyticsPersonRow(rawRow({ ...PERSON, name: 7 })),
    ).toThrow(ZodError)
  })

  it('formats a moment in the captured zone even after the live zone changes', () => {
    const zone = vi.spyOn(hilosTimeZone, 'get').mockReturnValue('UTC')
    const expected = formatSnapshotMoment(PERSON.lastSeenTs, CONTEXT.timeZone, {
      kind: 'dateTime',
    })
    expect(expected).not.toBeNull()
    expect(formatAnalyticsPeopleTime(PERSON.lastSeenTs, CONTEXT)).toBe(expected)
    zone.mockReturnValue('Pacific/Honolulu')
    expect(formatAnalyticsPeopleTime(PERSON.lastSeenTs, CONTEXT)).toBe(expected)
    expect(zone).not.toHaveBeenCalled()
    expect(formatAnalyticsPeopleTime(NaN, CONTEXT)).toBe('—')
  })
})

describe('Analytics People table lifecycle', () => {
  it('declares one visible filter, a Person card and its unfiltered empty state', () => {
    const { table, sent, facets } = stubTable()
    const frame = table.controller.frame
    expect(frame.columns.get()).toEqual([
      { key: 'rowKey', label: 'Person', card: 'title', reads: ['name'] },
      { key: 'sessionCount', label: 'Sessions' },
      { key: 'lastSeenTs', label: 'Last seen', sortable: true },
    ])
    expect(frame.filters.get()).toHaveLength(1)
    expect(frame.filters.get()[0]).toMatchObject({
      filter: {
        kind: 'select',
        key: 'period',
        label: 'Period',
        anyLabel: 'Any time',
      },
      value: 'today',
      active: true,
      facets: null,
    })
    expect(frame.activeFilterCount.get()).toBe(1)
    expect(frame.declaration.filteredEmpty).toBeUndefined()
    expect(frame.declaration.search).toBeUndefined()
    expect(frame.declaration.mainAction).toBeUndefined()
    expect(frame.declaration.bulkActions).toBeUndefined()
    expect(table.controller.selection.enabled).toBe(false)
    expect(table.periodContext).toEqual(CONTEXT)
    expect(table.controller.filter.get()).toEqual({
      ...CONTEXT,
      period: 'today',
    })
    expect(table.controller.descriptor()).toBeNull()
    expect(sent).toEqual([])
    expect(facets).toEqual([])
  })

  it('sends exactly one first viewport, already Today, after binding and the cold window', () => {
    vi.useFakeTimers()
    const fixture = stubTable()
    const { table, sent, facets, rendered, registered } = fixture
    expect(vi.getTimerCount()).toBe(0)
    table.start()
    table.start()
    expect(sent).toEqual([])
    expect(registered.get('hilosAnalyticsPeople')).toBe(table.controller)
    fixture.coldWindow(windowSection(), 'other_page')
    fixture.coldWindow(
      windowSection(),
      HilosPages.ANALYTICS_PEOPLE,
      'other_table',
    )
    expect(sent).toEqual([])
    fixture.coldWindow()
    expect(sent).toHaveLength(1)
    expect(sent[0]).toMatchObject({
      page: 'hilos_analytics_people',
      tableKey: 'hilosAnalyticsPeople',
      descriptor: {
        filter: { ...CONTEXT, period: 'today' },
        sort: [{ field: 'lastSeenTs', direction: 'desc' }],
        limit: 25,
        rendered: ['rowKey', 'name', 'sessionCount', 'lastSeenTs'],
      },
    })
    expect(table.controller.frame.body.get()).toBe('loading')
    expect(facets).toEqual([])
    expect(rendered).toEqual([])
    fixture.answer()
    expect(table.controller.frame.body.get()).toBe('empty_filtered')
    expect(facets).toEqual([
      {
        page: 'hilos_analytics_people',
        tableKey: 'hilosAnalyticsPeople',
        values: { period: ['this-hour', 'today', 'this-week'] },
      },
    ])
    expect(sent).toHaveLength(1)
    expect(vi.getTimerCount()).toBe(0)
  })

  it.each<AnalyticsPeriod>(['any-time', 'this-hour', 'today', 'this-week'])(
    'opens with %s and resets to Any time while retaining context',
    (initialPeriod) => {
      const fixture = stubTable({ page: 'custom_people', initialPeriod })
      const { table, sent } = fixture
      table.start()
      fixture.coldWindow()
      expect(sent).toHaveLength(1)
      const expected =
        initialPeriod === 'any-time'
          ? CONTEXT
          : { ...CONTEXT, period: initialPeriod }
      expect(sent[0]?.descriptor.filter).toEqual(expected)
      fixture.answer()
      expect(table.controller.frame.activeFilterCount.get()).toBe(
        initialPeriod === 'any-time' ? 0 : 1,
      )
      table.controller.resetFilters()
      expect(table.controller.filter.get()).toEqual(CONTEXT)
      expect(table.controller.frame.filters.get()[0]?.value).toBeUndefined()
      expect(table.controller.frame.activeFilterCount.get()).toBe(0)
      expect(sent.at(-1)?.descriptor.filter).toEqual(CONTEXT)
      expect(sent.at(-1)?.descriptor.filter).not.toHaveProperty('period')
      expect(sent.every((entry) => entry.page === 'custom_people')).toBe(true)
      fixture.answer()
      expect(table.controller.frame.body.get()).toBe('empty')
    },
  )

  it('reads the sole selected value from controller.filter through changes, reset and sort', () => {
    const fixture = stubTable()
    const { table, sent } = fixture
    const seen: unknown[] = []
    const off = subscribeSignal(table.controller.filter, (filter) => {
      seen.push(filter['period'])
    })
    table.start()
    fixture.coldWindow()
    fixture.answer()
    table.controller.setFilter('period', 'this-week')
    expect(table.controller.frame.filters.get()[0]?.value).toBe('this-week')
    table.controller.setSort('lastSeenTs')
    expect(sent.at(-1)?.descriptor).toMatchObject({
      filter: { ...CONTEXT, period: 'this-week' },
      sort: [{ field: 'lastSeenTs', direction: 'asc' }],
    })
    table.controller.setFilter('period', undefined)
    expect(sent.at(-1)?.descriptor.filter).toEqual(CONTEXT)
    expect(table.controller.frame.filters.get()[0]?.value).toBeUndefined()
    expect(table.controller.frame.activeFilterCount.get()).toBe(0)
    table.controller.setFilter('period', 'this-hour')
    table.controller.resetFilters()
    expect(seen).toEqual(['this-week', undefined, 'this-hour', undefined])
    expect(table.controller.frame.filters.get()[0]?.active).toBe(false)
    expect(table.controller.filter.get()).toEqual(CONTEXT)
    expect(table.periodContext).toEqual(CONTEXT)
    off()
  })

  it('ingests numeric fragments, viewer sort, facets and exact versus capped counts', () => {
    const fixture = stubTable()
    const { table, emit } = fixture
    table.start()
    fixture.coldWindow(windowSection({ sort: [] }))
    expect(fixture.sent[0]?.descriptor.sort).toBeNull()
    fixture.answer(
      windowSection({
        rows: [
          {
            rowKey: 9,
            slots: {
              person: {
                name: { _hidden: true },
                sessionCount: { _hidden: true },
                lastSeenTs: { _hidden: true },
              },
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
        name: HIDDEN_VALUE,
        sessionCount: HIDDEN_VALUE,
        lastSeenTs: HIDDEN_VALUE,
      },
    })
    expect(table.controller.frame.body.get()).toBe('rows')
    expect(table.controller.frame.footer.get()).toMatchObject({
      totalCount: 500,
      totalExact: false,
      pageCount: null,
    })
    const counts = {
      period: {
        any: { count: 500, exact: false },
        options: {
          'this-hour': { count: 0, exact: true },
          today: { count: 500, exact: true },
          'this-week': { count: 500, exact: false },
        },
      },
    }
    const expectedFacets = {
      any: counts.period.any,
      options: new Map(Object.entries(counts.period.options)),
    }
    emit('tableFacetCounts', {
      type: 'table_facet_counts',
      data: {
        page: 'other_page',
        tableKey: 'hilosAnalyticsPeople',
        facets: counts,
      },
    })
    expect(table.controller.frame.filters.get()[0]?.facets).toBeNull()
    emit('tableFacetCounts', {
      type: 'table_facet_counts',
      data: {
        page: HilosPages.ANALYTICS_PEOPLE,
        tableKey: 'other_table',
        facets: counts,
      },
    })
    expect(table.controller.frame.filters.get()[0]?.facets).toBeNull()
    emit('tableFacetCounts', {
      type: 'table_facet_counts',
      data: {
        page: HilosPages.ANALYTICS_PEOPLE,
        tableKey: 'hilosAnalyticsPeople',
        facets: counts,
      },
    })
    expect(table.controller.frame.filters.get()[0]?.facets).toEqual(
      expectedFacets,
    )
    expect(table.controller.descriptor()).toMatchObject({
      filter: { ...CONTEXT, period: 'today' },
      sort: null,
      facets: { period: ['this-hour', 'today', 'this-week'] },
    })
    table.controller.setFilter('period', 'this-week')
    expect(table.controller.frame.filters.get()[0]?.facets).toEqual(
      expectedFacets,
    )
    fixture.answer(
      windowSection({
        rows: [{ rowKey: 9, slots: { person: PERSON } }],
        totalCount: 500,
        totalExact: true,
      }),
    )
    expect(table.controller.frame.footer.get()).toMatchObject({
      totalCount: 500,
      totalExact: true,
      pageCount: 20,
    })
  })

  it('redeclares facets on resubscription without sending another viewport', () => {
    const fixture = stubTable()
    fixture.table.start()
    fixture.coldWindow()
    fixture.answer()
    expect(fixture.facets).toHaveLength(1)
    fixture.coldWindow()
    expect(fixture.facets).toHaveLength(2)
    expect(fixture.sent).toHaveLength(1)
  })

  it('unregisters, releases rows and cancels timers on dispose, and cannot restart', () => {
    vi.useFakeTimers()
    const fixture = stubTable()
    const { table, registered, listeners, sent } = fixture
    table.start()
    fixture.coldWindow()
    fixture.answer(
      windowSection({
        rows: [{ rowKey: 9, slots: { person: PERSON } }],
        totalCount: 1,
      }),
    )
    expect(table.controller.rows.get()).toHaveLength(1)
    table.controller.setFilter('period', 'this-week')
    expect(vi.getTimerCount()).toBeGreaterThan(0)
    table.dispose()
    table.dispose()
    table.start()
    expect(registered.size).toBe(0)
    expect([...listeners.values()].every((set) => set.size === 0)).toBe(true)
    expect(table.controller.rows.get()).toEqual([])
    expect(vi.getTimerCount()).toBe(0)
    const sentBefore = sent.length
    fixture.answer()
    fixture.coldWindow()
    table.controller.setFilter('period', 'today')
    expect(sent).toHaveLength(sentBefore)
  })

  it('owns one scope opening and cleans up when even the same page opens afresh', () => {
    const fixture = stubTable()
    fixture.table.start()
    fixture.coldWindow()
    fixture.answer(
      windowSection({
        rows: [{ rowKey: 9, slots: { person: PERSON } }],
        totalCount: 1,
      }),
    )
    fixture.context.scopes.openPage(HilosPages.ANALYTICS_PEOPLE)
    expect(fixture.registered.size).toBe(0)
    expect([...fixture.listeners.values()].every((set) => set.size === 0)).toBe(
      true,
    )
    expect(fixture.table.controller.rows.get()).toEqual([])
    fixture.coldWindow()
    expect(fixture.sent).toHaveLength(1)
  })

  it('can be disposed before start without a binding or initialization timer leak', () => {
    vi.useFakeTimers()
    const fixture = stubTable()
    fixture.table.dispose()
    fixture.table.start()
    expect(fixture.registered.size).toBe(0)
    expect(fixture.listeners.size).toBe(0)
    expect(fixture.sent).toEqual([])
    expect(vi.getTimerCount()).toBe(0)
  })
})

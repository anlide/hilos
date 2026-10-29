import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  createHilosStatusHistoryState,
  createHilosStatusHistoryTable,
  hilosStatusHistoryDuration,
  resolveHilosStatusHistoryRow,
  statusHistoryDataSchema,
  statusHistoryRowSchema,
  type HilosStatusHistoryRow,
} from '../../../src/admin/status/hilosStatusHistory.js'
import { ActionLifecycle } from '../../../src/connection/actionLifecycle.js'
import { HilosConnection } from '../../../src/connection/HilosConnection.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { normalizeTableRow } from '../../../src/state/normalizer.js'
import { subscribeSignal } from '../../../src/state/signal.js'
import {
  bindPageScope,
  PAGE_SIGNAL_SCHEMAS,
} from '../../../src/subscription/bindPageScope.js'
import { bindTimeZoneState } from '../../../src/timeZone/timeZoneState.js'
import { Socket } from '../../status/fixtures.js'
import { harness } from './fixtures.js'

const page = HilosPages.STATUS_HISTORY
const tableKey = 'hilosStatusHistory'
const sort = [{ field: 'startedAt', direction: 'desc' as const }]
const incident: HilosStatusHistoryRow = {
  id: 'incident:7',
  kind: 'incident',
  sourceId: 7,
  startedAt: 1_800_000_000_000,
  endedAt: 1_800_000_061_000,
  completionObservedAt: null,
  publishedAt: 1_800_000_010_000,
  outcome: 'resolved',
  title: 'Saved service name',
  componentKeys: ['removed'],
  severity: 'major_outage',
  announcementKnown: null,
  workPublicId: null,
}
const maintenance: HilosStatusHistoryRow = {
  id: 'maintenance:7',
  kind: 'maintenance',
  sourceId: 7,
  startedAt: 1_800_000_000_000,
  endedAt: null,
  completionObservedAt: 1_800_000_100_000,
  publishedAt: null,
  outcome: 'maintenance_completed',
  title: 'Saved upgrade',
  componentKeys: ['removed'],
  severity: null,
  announcementKnown: false,
  workPublicId: null,
}
const catalog = {
  components: [
    { componentKey: 'current', name: 'Current component' },
    { componentKey: 'removed', name: 'removed' },
  ],
}
const row = (value: HilosStatusHistoryRow = incident) => ({
  rowKey: value.id,
  slots: { history: value },
})
const window = (rows = [row(maintenance), row()], filter = {}) => ({
  rows,
  totalCount: rows.length,
  totalExact: true,
  firstAnchor: null,
  lastAnchor: null,
  limit: 25,
  sort,
  filter,
})
const cleanups: (() => void)[] = []
afterEach(() => {
  for (const cleanup of cleanups.splice(0).reverse()) cleanup()
  vi.restoreAllMocks()
})

function setup() {
  const h = harness(page)
  cleanups.push(h.releaseAccess)
  h.context.connection.forgetPageFrames = vi.fn()
  const pages = bindPageScope(h.context.connection, h.scopes)
  pages.subscribe(page)
  const releaseZone = bindTimeZoneState(h.context.connection)
  cleanups.push(releaseZone)
  const state = createHilosStatusHistoryState(h.context)
  const table = createHilosStatusHistoryTable(h.context)
  cleanups.push(() => {
    table.dispose()
    state.dispose()
  })
  state.start()
  table.start()
  const answer = (payload: Record<string, unknown>) =>
    h.emit('projectSignal', {
      type: 'page_response',
      data: { page, payload },
    })
  return {
    ...h,
    state,
    table,
    pages,
    window: (value = window(), data: unknown = catalog) =>
      answer({
        data: { statusHistory: data },
        windows: { [tableKey]: value },
      }),
    data: (value: unknown) => answer({ data: { statusHistory: value } }),
    delta: (data: Record<string, unknown>) =>
      h.emit('tableViewportDelta', {
        data: { page, tableKey, ...data },
      }),
  }
}

describe('Status History exact readers', () => {
  it('accepts only the completed public source shapes and explicit nullable fields', () => {
    expect(statusHistoryRowSchema.parse(incident)).toEqual(incident)
    expect(statusHistoryRowSchema.parse(maintenance)).toEqual(maintenance)
    for (const change of [
      { id: 'maintenance:7' },
      { sourceId: 8 },
      { sourceId: '7' },
      { endedAt: null },
      { completionObservedAt: 100 },
      { severity: null },
      { outcome: 'active' },
      { title: null },
      { actorId: 1 },
      { startedAt: '2030-01-01' },
    ])
      expect(
        statusHistoryRowSchema.safeParse({ ...incident, ...change }).success,
      ).toBe(false)
    for (const key of Object.keys(incident)) {
      const missing: Record<string, unknown> = { ...incident }
      delete missing[key]
      expect(statusHistoryRowSchema.safeParse(missing).success).toBe(false)
    }
    for (const change of [
      { completionObservedAt: null },
      { publishedAt: 10 },
      { announcementKnown: null },
      { severity: 'major_outage' },
    ])
      expect(
        statusHistoryRowSchema.safeParse({ ...maintenance, ...change }).success,
      ).toBe(false)
    expect(statusHistoryDataSchema.parse(null)).toBeNull()
    expect(statusHistoryDataSchema.parse(catalog)).toEqual(catalog)
    for (const value of [
      undefined,
      {},
      { ...catalog, actor: 1 },
      { components: [{ componentKey: 'x' }] },
    ])
      expect(statusHistoryDataSchema.safeParse(value).success).toBe(false)
  })

  it('checks all namespaced identities across normalized row, reference and fields', () => {
    const h = setup()
    const scope = h.scopes.page()!
    for (const value of [incident, maintenance]) {
      const normalized = normalizeTableRow(scope, row(value))
      expect(resolveHilosStatusHistoryRow(normalized, h.scopes)).toEqual(value)
      for (const malformed of [
        { ...normalized, rowKey: 'incident:9' },
        {
          ...normalized,
          slots: { history: { type: 'history', id: 'incident:9' } },
        },
        { ...normalized, slots: { history: { type: 'work', id: value.id } } },
        { ...normalized, slots: {} },
      ])
        expect(() =>
          resolveHilosStatusHistoryRow(malformed, h.scopes),
        ).toThrow()
    }
    scope.entities.upsert(
      { type: 'history', id: incident.id },
      { ...incident, sourceId: 9 },
    )
    expect(() =>
      resolveHilosStatusHistoryRow(
        {
          rowKey: incident.id,
          slots: {
            history: { type: 'history', id: incident.id },
          },
        },
        h.scopes,
      ),
    ).toThrow()
  })

  it('measures whole seconds from the exact end and never from the observation', () => {
    expect(hilosStatusHistoryDuration(incident)).toBe('1 min 1 s')
    expect(
      hilosStatusHistoryDuration({
        ...incident,
        endedAt: incident.startedAt + 3_661_999,
      }),
    ).toBe('1 h 1 min 1 s')
    expect(
      hilosStatusHistoryDuration({ ...incident, endedAt: incident.startedAt }),
    ).toBe('0 s')
    expect(hilosStatusHistoryDuration(maintenance)).toBe('Unknown')
    expect(
      hilosStatusHistoryDuration({
        ...maintenance,
        endedAt: maintenance.startedAt + 1000,
      }),
    ).toBe('1 s')
  })
})

describe('Status History standard viewport', () => {
  it('takes the first answer without extra asks, keeping page data, focus and pending independent', () => {
    const h = setup()
    h.state.start()
    h.table.start()
    expect(h.viewports).toEqual([])
    expect(h.sent).toEqual([])
    expect(h.registered.size).toBe(1)
    h.window()
    expect(h.table.controller.rows.get().map((entry) => entry.row)).toEqual([
      maintenance,
      incident,
    ])
    expect(h.table.controller.order.get()).toEqual(sort)
    h.table.controller.focusRow(incident.id)
    h.delta({
      kind: 'row_moved',
      rowKey: incident.id,
      row: row({ ...incident, title: 'Live saved name' }),
      position: 0,
    })
    h.emit('tableViewportAnnounce', {
      data: {
        page,
        tableKey,
        rowKey: 'incident:9',
        placement: 'above',
        totalCount: 3,
        totalExact: true,
      },
    })
    const descriptor = h.table.controller.descriptor()
    const pending = h.table.controller.pendingCount.get()
    const requests = [...h.viewports]
    h.data({ components: [{ componentKey: 'another', name: 'New facet' }] })
    expect(h.state.data.get()?.components[0]?.componentKey).toBe('another')
    expect(h.table.controller.descriptor()).toEqual({
      ...descriptor,
      facets: { ...descriptor!.facets, componentKey: ['another'] },
    })
    expect(h.table.controller.pendingCount.get()).toBe(pending)
    expect(h.table.controller.rows.get().map((entry) => entry.rowKey)).toEqual([
      maintenance.id,
      incident.id,
    ])
    expect(h.table.controller.focusedRow.get()?.title).toBe('Live saved name')
    expect(h.viewports).toEqual(requests)
    expect(h.table.controller.announced.get().above).toBe(1)
    h.table.controller.apply()
    expect(h.table.controller.rows.get()[0]?.rowKey).toBe(incident.id)
  })

  it('uses the standard refusal, freeze, staleness, focus and rendered transports', () => {
    const h = setup()
    h.window()
    expect(h.rendered).toContainEqual([
      page,
      tableKey,
      expect.arrayContaining(['startedAt', 'endedAt', 'title', 'outcome']),
    ])
    h.table.controller.focusRow(incident.id)
    expect(h.focuses.at(-1)).toEqual([page, tableKey, incident.id])
    h.delta({
      kind: 'row_stale',
      rowKey: incident.id,
      staleSources: ['history'],
    })
    expect(
      h.table.controller.rows
        .get()
        .find((entry) => entry.rowKey === incident.id)?.staleSources,
    ).toEqual(['history'])
    h.emit('tableViewportFrozen', { data: { page, tableKey, since: 123 } })
    expect(h.table.controller.frozenSince.get()).toBe(123)
    expect(h.table.controller.rows.get()).toHaveLength(2)
    h.emit('tableWindowRefused', {
      data: { page, tableKey, errorCode: 'source_unavailable' },
    })
    expect(h.table.controller.rows.get()).toEqual([])
    h.window(window([]), null)
    expect(h.state.data.get()).toBeNull()
    h.window(window([]), { components: [] })
    expect(h.state.data.get()).toEqual({ components: [] })
    expect(h.table.controller.rows.get()).toEqual([])
    expect(h.table.controller.loaded.get()).toBe(true)
  })

  it('offers only Start orders, saved-key component/outcome facets and a reader-zone calendar period', () => {
    const h = setup()
    h.window()
    const controller = h.table.controller
    expect(
      controller.frame.columns
        .get()
        .map(({ key, sortable }) => [key, sortable ?? false]),
    ).toEqual([
      ['startedAt', true],
      ['endedAt', false],
      ['title', false],
      ['outcome', false],
    ])
    expect(controller.frame.declaration.mainAction).toBeUndefined()
    expect(controller.frame.declaration.bulkActions).toBeUndefined()
    expect(controller.frame.searchPlaceholder.get()).toBe(
      'Search by component or maintenance title…',
    )
    const componentFilter = controller.frame.filters.get()[0]!.filter
    expect(
      componentFilter.kind === 'select' && componentFilter.options(),
    ).toEqual([
      { value: 'current', label: 'Current component' },
      { value: 'removed', label: 'removed' },
    ])
    controller.setSearch('saved')
    const beforePeriod = h.viewports.length
    controller.setFilters({
      componentKey: 'removed',
      outcome: 'maintenance_completed',
      from: '2031-03-09',
      to: '2031-03-09',
    })
    expect(h.viewports).toHaveLength(beforePeriod + 1)
    expect(controller.descriptor()?.filter).toMatchObject({
      from: '2031-03-09',
      to: '2031-03-09',
      timeZone: 'UTC',
    })
    h.emit('projectSignal', {
      type: 'handshake_response',
      data: {
        entities: { currentUser: { id: 1, timeZone: 'America/New_York' } },
      },
    })
    expect(h.viewports.at(-1)).toEqual([
      page,
      tableKey,
      expect.objectContaining({
        filter: {
          search: 'saved',
          componentKey: 'removed',
          outcome: 'maintenance_completed',
          from: '2031-03-09',
          to: '2031-03-09',
          timeZone: 'America/New_York',
        },
      }),
    ])
    controller.setSort('startedAt')
    expect(h.viewports.at(-1)).toEqual([
      page,
      tableKey,
      expect.objectContaining({
        sort: [{ field: 'startedAt', direction: 'asc' }],
      }),
    ])
    controller.setSort('startedAt')
    expect(h.viewports.at(-1)).toEqual([
      page,
      tableKey,
      expect.objectContaining({ sort }),
    ])
    expect(controller.rows.get().map((entry) => entry.rowKey)).toEqual([
      maintenance.id,
      incident.id,
    ])
    controller.resetFilters()
    expect(controller.filter.get()).toEqual({})
    expect(h.facets).toContainEqual([
      page,
      tableKey,
      expect.objectContaining({ componentKey: ['current', 'removed'] }),
    ])
  })

  it('clears missing/malformed page data and disposes rows and focus before a scope replacement', () => {
    const h = setup()
    h.window()
    for (const value of [undefined, { components: 'broken' }, null]) {
      h.table.controller.focusRow(incident.id)
      h.data(value)
      expect(h.state.data.get()).toBeNull()
      expect(h.table.controller.focusedRow.get()).toBeUndefined()
    }
    h.data({ components: [] })
    expect(h.state.data.get()).toEqual({ components: [] })
    h.table.controller.focusRow(incident.id)
    const offRows = subscribeSignal(h.table.controller.rows, vi.fn())
    cleanups.push(offRows)
    h.scopes.openPage(page)
    expect(h.state.data.get()).toBeNull()
    expect(h.table.controller.rows.get()).toEqual([])
    expect(h.table.controller.focusedRow.get()).toBeUndefined()
    expect(h.registered.size).toBe(0)
    h.table.start()
    h.state.start()
    h.window()
    expect(h.table.controller.rows.get()).toEqual([])
  })

  it('replays a real page-first answer at mount and releases every listener on leaving', () => {
    const socket = new Socket()
    const connection = new HilosConnection({
      url: 'ws://test/ws',
      webSocketFactory: () => socket,
      projectSchemas: PAGE_SIGNAL_SCHEMAS,
    })
    const scopes = new ScopeManager()
    const context = {
      connection,
      scopes,
      actions: new ActionLifecycle(connection),
    }
    const pages = bindPageScope(connection, scopes)
    connection.connect()
    socket.emit('open')
    pages.releaseOnSession()
    pages.subscribe(page)
    socket.push('page_response', {
      page,
      payload: {
        data: { statusHistory: catalog },
        windows: { [tableKey]: window() },
      },
    })
    const state = createHilosStatusHistoryState(context)
    const table = createHilosStatusHistoryTable(context)
    const offRows = subscribeSignal(table.controller.rows, vi.fn())
    cleanups.push(() => {
      offRows()
      table.dispose()
      state.dispose()
      connection.close()
    })
    table.start()
    state.start()
    expect(table.controller.rows.get().map((entry) => entry.row)).toEqual([
      maintenance,
      incident,
    ])
    expect(state.data.get()).toEqual(catalog)
    expect(connection.tableWindowDescriptors()).toHaveProperty(tableKey)
    expect(
      socket.send.mock.calls
        .map(([raw]) => JSON.parse(raw))
        .filter((frame) => ['action', 'table_viewport'].includes(frame.type)),
    ).toEqual([])
    pages.subscribe(HilosPages.STATUS)
    expect(table.controller.rows.get()).toEqual([])
    expect(state.data.get()).toBeNull()
    expect(connection.tableWindowDescriptors()).toEqual({})
  })
})

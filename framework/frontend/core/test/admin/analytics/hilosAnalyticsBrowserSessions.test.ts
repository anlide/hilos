import { describe, expect, it } from 'vitest'

import {
  createHilosAnalyticsBrowserSessionsTable,
  resolveHilosAnalyticsBrowserSessionRow,
} from '../../../src/admin/analytics/hilosAnalyticsBrowserSessions.js'
import { type HilosAnalyticsContext } from '../../../src/admin/analytics/hilosAnalytics.js'
import {
  type HilosConnection,
  type TableViewportDescriptor,
  type TableWindowDescriptorSource,
} from '../../../src/connection/HilosConnection.js'
import { type ProjectSignal } from '../../../src/protocol/parseSignal.js'
import { type PageResponseWire } from '../../../src/protocol/scopePayload.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'

function rawRow(slot: unknown): TableRow {
  return { rowKey: '9', slots: { browserSession: slot } }
}

function stubTable(page = 'project_analytics', id = 57) {
  const sent: Array<{
    page: string
    tableKey: string
    descriptor: TableViewportDescriptor
  }> = []
  const rendered: Array<{
    page: string
    tableKey: string
    fields: readonly string[]
  }> = []
  const listeners = new Map<string, Set<(signal: ProjectSignal) => void>>()
  const registered = new Map<string, TableWindowDescriptorSource>()
  const scopes = new ScopeManager()
  scopes.openPage(page)
  const context: HilosAnalyticsContext = {
    connection: {
      on(event: string, listener: (signal: ProjectSignal) => void) {
        const set = listeners.get(event) ?? new Set()
        listeners.set(event, set)
        set.add(listener)
        return () => set.delete(listener)
      },
      registerTableWindow(key: string, source: TableWindowDescriptorSource) {
        registered.set(key, source)
      },
      unregisterTableWindow(key: string) {
        registered.delete(key)
      },
      sendTableViewport(
        target: string,
        tableKey: string,
        descriptor: TableViewportDescriptor,
      ): boolean {
        sent.push({ page: target, tableKey, descriptor })
        return true
      },
      sendTableRendered(
        target: string,
        tableKey: string,
        fields: readonly string[],
      ): boolean {
        rendered.push({ page: target, tableKey, fields })
        return true
      },
    } as unknown as HilosConnection,
    scopes,
  }
  return {
    table: createHilosAnalyticsBrowserSessionsTable(context, {
      page,
      userId: id,
    }),
    sent,
    rendered,
    registered,
    listeners,
    emitPageResponse(data: PageResponseWire) {
      for (const listener of listeners.get('projectSignal') ?? []) {
        listener({ type: 'page_response', data } as unknown as ProjectSignal)
      }
    },
  }
}

describe('hilosAnalyticsBrowserSessions row', () => {
  it('reads the inline slot and takes identity from the fragment', () => {
    expect(
      resolveHilosAnalyticsBrowserSessionRow(
        rawRow({
          rowKey: 99,
          firstSeenTs: 1791540000000,
          lastSeenTs: 1791540672123,
          browser: 'Chrome 141 · Linux',
          actionCount: 7,
        }),
      ),
    ).toEqual({
      rowKey: 9,
      firstSeenTs: 1791540000000,
      lastSeenTs: 1791540672123,
      browser: 'Chrome 141 · Linux',
      actionCount: 7,
    })
  })

  it('keeps a nullable label absent', () => {
    const row = resolveHilosAnalyticsBrowserSessionRow(
      rawRow({
        firstSeenTs: 1791540000000,
        lastSeenTs: 1791540672123,
        actionCount: 7,
        browser: null,
      }),
    )
    expect(row.browser).toBeNull()
  })

  it('folds the viewer hidden marks into safe defaults', () => {
    expect(
      resolveHilosAnalyticsBrowserSessionRow(
        rawRow({
          firstSeenTs: { _hidden: true },
          lastSeenTs: { _hidden: true },
          browser: { _hidden: true },
          actionCount: { _hidden: true },
        }),
      ),
    ).toEqual({
      rowKey: 9,
      firstSeenTs: 0,
      lastSeenTs: 0,
      browser: null,
      actionCount: 0,
    })
  })

  it.each([
    null,
    [],
    'wrong',
    { firstSeenTs: '12', lastSeenTs: [], browser: 7, actionCount: '7' },
  ])('tolerates an absent or malformed slot: %j', (slot) => {
    expect(resolveHilosAnalyticsBrowserSessionRow(rawRow(slot))).toEqual({
      rowKey: 9,
      firstSeenTs: 0,
      lastSeenTs: 0,
      browser: null,
      actionCount: 0,
    })
  })

  it('ignores unrelated slots', () => {
    expect(
      resolveHilosAnalyticsBrowserSessionRow({
        rowKey: '9',
        slots: { other: { lastSeenTs: 12 } },
      }),
    ).toEqual({
      rowKey: 9,
      firstSeenTs: 0,
      lastSeenTs: 0,
      browser: null,
      actionCount: 0,
    })
  })
})

describe('hilosAnalyticsBrowserSessions viewport', () => {
  it('declares its columns, card title and empty state without bar controls', () => {
    const { table } = stubTable()
    const frame = table.controller.frame
    expect(frame.declaration?.title).toBeUndefined()
    expect(frame.declaration?.search).toBeUndefined()
    expect(frame.declaration?.filters).toBeUndefined()
    expect(frame.declaration?.mainAction).toBeUndefined()
    expect(frame.declaration?.bulkActions).toBeUndefined()
    expect(frame.declaration?.empty?.title).toBe(
      'No browser sessions where this person signed in last',
    )
    const columns = frame.columns.get()
    expect(columns.map(({ key }) => key)).toEqual([
      'rowKey',
      'firstSeenTs',
      'lastSeenTs',
      'browser',
      'actionCount',
    ])
    expect(columns.map(({ label }) => label)).toEqual([
      'Session',
      'First seen',
      'Last seen',
      'Browser',
      'Actions',
    ])
    expect(columns[0]?.card).toBe('title')
    expect(
      columns.filter(({ sortable }) => sortable).map(({ key }) => key),
    ).toEqual(['lastSeenTs'])

    expect(table.controller.descriptor()).toBeNull()
    table.dispose()
  })

  it('asks for the preset after the cold window and keeps it across sort and reset', () => {
    const { table, sent, rendered } = stubTable('custom_analytics_page', 83)
    table.controller.ingestSubscriptionWindow(
      [],
      0,
      true,
      null,
      null,
      25,
      [{ field: 'lastSeenTs', direction: 'desc' }],
      [],
    )
    expect(sent).toHaveLength(1)
    expect(sent[0]).toMatchObject({
      page: 'custom_analytics_page',
      tableKey: 'hilosAnalyticsBrowserSessions',
      descriptor: {
        filter: { user: 83 },
        sort: [{ field: 'lastSeenTs', direction: 'desc' }],
        limit: 25,
        rendered: [
          'rowKey',
          'firstSeenTs',
          'lastSeenTs',
          'browser',
          'actionCount',
        ],
      },
    })
    expect(rendered).toEqual([])
    expect(table.controller.frame.body.get()).toBe('loading')
    table.controller.ingestWindow([], 0, true, null, null, 25, 0)
    expect(table.controller.frame.body.get()).toBe('empty')
    table.controller.setSort('lastSeenTs')
    expect(sent.at(-1)?.descriptor.sort).toEqual([
      { field: 'lastSeenTs', direction: 'asc' },
    ])
    expect(sent.at(-1)?.descriptor.filter).toEqual({ user: 83 })
    table.controller.setFilter('extra', 'value')
    table.controller.resetFilters()
    expect(sent.at(-1)?.descriptor.filter).toEqual({ user: 83 })
    table.dispose()
  })

  it('passes an invalid preset to the backend without client validation', () => {
    const { table, sent, rendered } = stubTable('custom_analytics_page', 0)
    table.controller.ingestSubscriptionWindow(
      [],
      0,
      true,
      null,
      null,
      25,
      undefined,
      [],
    )
    expect(sent[0]?.descriptor.filter).toEqual({ user: 0 })
    expect(rendered).toEqual([])
    table.dispose()
  })

  it('binds only the supplied page and unregisters on dispose', () => {
    const { table, sent, registered, listeners, emitPageResponse } =
      stubTable('custom_bound_page')
    table.start()
    expect(registered.get('hilosAnalyticsBrowserSessions')).toBe(
      table.controller,
    )
    const payload = {
      windows: {
        hilosAnalyticsBrowserSessions: {
          rows: [],
          limit: 25,
          totalCount: 0,
          totalExact: true,
          firstAnchor: null,
          lastAnchor: null,
          rowsBefore: 0,
          sort: [{ field: 'lastSeenTs', direction: 'desc' as const }],
        },
      },
    }
    emitPageResponse({ page: 'other_page', payload })
    expect(sent).toEqual([])
    emitPageResponse({ page: 'custom_bound_page', payload })
    expect(sent).toHaveLength(1)
    expect(sent[0]?.page).toBe('custom_bound_page')
    table.dispose()
    expect(registered.size).toBe(0)
    expect([...listeners.values()].every((set) => set.size === 0)).toBe(true)
    emitPageResponse({ page: 'custom_bound_page', payload })
    expect(sent).toHaveLength(1)
    table.dispose()
  })
})

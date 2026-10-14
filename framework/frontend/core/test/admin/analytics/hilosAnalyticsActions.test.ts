import { describe, expect, it } from 'vitest'

import {
  createHilosAnalyticsActionsTable,
  resolveHilosAnalyticsActionRow,
} from '../../../src/admin/analytics/hilosAnalyticsActions.js'
import { type HilosAnalyticsContext } from '../../../src/admin/analytics/hilosAnalytics.js'
import {
  type HilosConnection,
  type TableViewportDescriptor,
  type TableWindowDescriptorSource,
} from '../../../src/connection/HilosConnection.js'
import { type ProjectSignal } from '../../../src/protocol/parseSignal.js'
import { type PageResponseWire } from '../../../src/protocol/scopePayload.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'

function rawRow(slot: unknown): TableRow {
  return { rowKey: '9', slots: { action: slot } }
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
    table: createHilosAnalyticsActionsTable(context, {
      page,
      browserSessionId: id,
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

describe('hilosAnalyticsActions row', () => {
  it('reads the inline slot and takes identity from the fragment', () => {
    expect(
      resolveHilosAnalyticsActionRow(
        rawRow({
          rowKey: 99,
          createdTs: 1791540672123,
          pageName: 'profile',
          actionName: 'rename',
        }),
      ),
    ).toEqual({
      rowKey: 9,
      createdTs: 1791540672123,
      pageName: 'profile',
      actionName: 'rename',
    })
  })

  it('keeps a nullable label absent', () => {
    const row = resolveHilosAnalyticsActionRow(
      rawRow({
        createdTs: 1791540672123,
        actionName: 'rename',
        pageName: null,
      }),
    )
    expect(row.pageName).toBeNull()
  })

  it('preserves the viewer hidden marks as the shared hidden value', () => {
    const row = resolveHilosAnalyticsActionRow(
      rawRow({
        createdTs: { _hidden: true },
        pageName: { _hidden: true },
        actionName: { _hidden: true },
      }),
    )
    expect(row.rowKey).toBe(9)
    expect(row.createdTs).toBe(HIDDEN_VALUE)
    expect(row.pageName).toBe(HIDDEN_VALUE)
    expect(row.actionName).toBe(HIDDEN_VALUE)
  })

  it('defaults malformed fields without discarding valid or hidden siblings', () => {
    const row = resolveHilosAnalyticsActionRow(
      rawRow({
        createdTs: 'bad',
        pageName: { _hidden: true },
        actionName: 'rename',
      }),
    )
    expect(row.createdTs).toBe(0)
    expect(row.pageName).toBe(HIDDEN_VALUE)
    expect(row.actionName).toBe('rename')
  })

  it.each([
    null,
    [],
    'wrong',
    { createdTs: '12', pageName: 7, actionName: [] },
  ])('tolerates an absent or malformed slot: %j', (slot) => {
    expect(resolveHilosAnalyticsActionRow(rawRow(slot))).toEqual({
      rowKey: 9,
      createdTs: 0,
      pageName: null,
      actionName: '',
    })
  })

  it('ignores unrelated slots', () => {
    expect(
      resolveHilosAnalyticsActionRow({
        rowKey: '9',
        slots: { other: { createdTs: 12 } },
      }),
    ).toEqual({ rowKey: 9, createdTs: 0, pageName: null, actionName: '' })
  })
})

describe('hilosAnalyticsActions viewport', () => {
  it('declares its columns, card title and empty state without bar controls', () => {
    const { table } = stubTable()
    const frame = table.controller.frame
    expect(frame.declaration?.title).toBeUndefined()
    expect(frame.declaration?.search).toBeUndefined()
    expect(frame.declaration?.filters).toBeUndefined()
    expect(frame.declaration?.mainAction).toBeUndefined()
    expect(frame.declaration?.bulkActions).toBeUndefined()
    expect(frame.declaration?.empty?.title).toBe(
      'No actions recorded in this browser session yet',
    )
    const columns = frame.columns.get()
    expect(columns.map(({ key }) => key)).toEqual([
      'createdTs',
      'pageName',
      'actionName',
    ])
    expect(columns.map(({ label }) => label)).toEqual([
      'When',
      'Page',
      'Action',
    ])
    expect(columns[0]?.card).toBe('title')
    expect(
      columns.filter(({ sortable }) => sortable).map(({ key }) => key),
    ).toEqual(['createdTs'])
    expect(columns[0]?.cellClass).toBe('text-nowrap')
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
      [{ field: 'createdTs', direction: 'asc' }],
      [],
    )
    expect(sent).toHaveLength(1)
    expect(sent[0]).toMatchObject({
      page: 'custom_analytics_page',
      tableKey: 'hilosAnalyticsActions',
      descriptor: {
        filter: { browserSession: 83 },
        sort: [{ field: 'createdTs', direction: 'asc' }],
        limit: 25,
        rendered: ['createdTs', 'pageName', 'actionName'],
      },
    })
    expect(rendered).toEqual([])
    expect(table.controller.frame.body.get()).toBe('loading')
    table.controller.ingestWindow([], 0, true, null, null, 25, 0)
    expect(table.controller.frame.body.get()).toBe('empty')
    table.controller.setSort('createdTs')
    expect(sent.at(-1)?.descriptor.sort).toEqual([
      { field: 'createdTs', direction: 'desc' },
    ])
    expect(sent.at(-1)?.descriptor.filter).toEqual({ browserSession: 83 })
    table.controller.setFilter('extra', 'value')
    table.controller.resetFilters()
    expect(sent.at(-1)?.descriptor.filter).toEqual({ browserSession: 83 })
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
    expect(sent[0]?.descriptor.filter).toEqual({ browserSession: 0 })
    expect(rendered).toEqual([])
    table.dispose()
  })

  it('binds only the supplied page and unregisters on dispose', () => {
    const { table, sent, registered, listeners, emitPageResponse } =
      stubTable('custom_bound_page')
    table.start()
    expect(registered.get('hilosAnalyticsActions')).toBe(table.controller)
    const payload = {
      windows: {
        hilosAnalyticsActions: {
          rows: [],
          limit: 25,
          totalCount: 0,
          totalExact: true,
          firstAnchor: null,
          lastAnchor: null,
          rowsBefore: 0,
          sort: [{ field: 'createdTs', direction: 'asc' as const }],
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

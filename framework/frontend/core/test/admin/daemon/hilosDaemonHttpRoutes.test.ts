import { describe, expect, it } from 'vitest'

import {
  createHilosDaemonHttpRoutesTable,
  formatDaemonHttpCount,
  formatDaemonHttpRoute,
  formatDaemonHttpSlowest,
  resolveHilosDaemonHttpRouteRow,
  type HilosDaemonHttpRouteRow,
  type HilosDaemonHttpRoutesContext,
} from '../../../src/admin/daemon/hilosDaemonHttpRoutes.js'
import {
  type HilosConnection,
  type TableViewportDescriptor,
} from '../../../src/connection/HilosConnection.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { type ScopeManager } from '../../../src/state/ScopeManager.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'

function row(
  overrides: Partial<HilosDaemonHttpRouteRow> = {},
): HilosDaemonHttpRouteRow {
  return {
    rowKey: 'GET/%2Fstatus',
    method: 'GET',
    path: '/status',
    agentType: null,
    requests: 1_240,
    serverErrors: 0,
    slowestMs: 12,
    ...overrides,
  }
}

function rawRow(rowKey: string, slot: Record<string, unknown>): TableRow {
  return { rowKey, slots: { route: slot } }
}

function stubTable(nodeId = 'n1'): {
  table: ReturnType<typeof createHilosDaemonHttpRoutesTable>
  sent: Array<{
    page: string
    tableKey: string
    descriptor: TableViewportDescriptor
  }>
} {
  const sent: Array<{
    page: string
    tableKey: string
    descriptor: TableViewportDescriptor
  }> = []
  const context: HilosDaemonHttpRoutesContext = {
    connection: {
      registerTableWindow(): void {},
      unregisterTableWindow(): void {},
      tableWindowDescriptors: () => ({}),
      sendTableViewport(
        page: string,
        tableKey: string,
        descriptor: TableViewportDescriptor,
      ): boolean {
        sent.push({ page, tableKey, descriptor })
        return true
      },
      sendTableRendered(): boolean {
        return true
      },
    } as unknown as HilosConnection,
    scopes: {} as ScopeManager,
  }

  return { table: createHilosDaemonHttpRoutesTable(context, nodeId), sent }
}

describe('daemon HTTP route row', () => {
  it('reads the nullable unrouted tally and identity from the fragment', () => {
    expect(
      resolveHilosDaemonHttpRouteRow(
        rawRow('unrouted', {
          rowKey: 'wrong-slot-key',
          method: null,
          path: null,
          agentType: null,
          requests: 0,
          serverErrors: 0,
          slowestMs: null,
        }),
      ),
    ).toEqual(
      row({
        rowKey: 'unrouted',
        method: null,
        path: null,
        requests: 0,
        slowestMs: null,
      }),
    )

    expect(
      resolveHilosDaemonHttpRouteRow(
        rawRow('POST/%2Fhook', {
          method: 'POST',
          path: '/hook',
          agentType: 'webhook',
          requests: 37,
          serverErrors: 2,
          slowestMs: 1_240,
        }),
      ),
    ).toEqual(
      row({
        rowKey: 'POST/%2Fhook',
        method: 'POST',
        path: '/hook',
        agentType: 'webhook',
        requests: 37,
        serverErrors: 2,
        slowestMs: 1_240,
      }),
    )
  })
})

describe('daemon HTTP routes viewport', () => {
  it('requests the route node after the cold page window and no order', () => {
    const { table, sent } = stubTable('standalone')
    expect(table.controller.descriptor()).toBeNull()

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

    expect(sent.at(-1)?.page).toBe(HilosPages.DAEMON_HTTP_SERVER)
    expect(sent.at(-1)?.tableKey).toBe('hilosDaemonHttpRoutes')
    expect(sent.at(-1)?.descriptor.filter).toEqual({ node: 'standalone' })
    expect(sent.at(-1)?.descriptor.sort).toBeNull()
  })

  it('declares four unsorted columns and no table header or empty state', () => {
    const frame = stubTable().table.controller.frame
    expect(frame.declaration?.title).toBeUndefined()
    expect(frame.declaration?.search).toBeUndefined()
    expect(frame.declaration?.filters).toBeUndefined()
    expect(frame.declaration?.empty).toBeUndefined()
    expect(frame.declaration?.mainAction).toBeUndefined()
    expect(frame.declaration?.bulkActions).toBeUndefined()

    const columns = frame.columns.get()
    expect(columns.map((column) => column.key)).toEqual([
      'path',
      'requests',
      'serverErrors',
      'slowestMs',
    ])
    expect(columns.map((column) => column.label)).toEqual([
      'Route',
      'Requests',
      'Errors',
      'Slowest',
    ])
    expect(columns[0]?.card).toBe('title')
    expect(columns[0]?.reads).toEqual(['method', 'agentType'])
    for (const column of columns.slice(1, 3)) {
      expect(column.headerClass).toBe('text-center')
      expect(column.cellClass).toBe('text-center')
    }
    expect(columns.every((column) => column.sortable !== true)).toBe(true)
  })
})

describe('daemon HTTP route cell text', () => {
  it('shows the path for GET, the method for other routes, and the unrouted label', () => {
    expect(formatDaemonHttpRoute(row())).toBe('/status')
    expect(formatDaemonHttpRoute(row({ method: 'POST', path: '/hook' }))).toBe(
      'POST /hook',
    )
    expect(formatDaemonHttpRoute(row({ method: null, path: null }))).toBe(
      'No route',
    )
  })

  it('formats counts and the longest duration in the browser locale', () => {
    expect(formatDaemonHttpCount(1_240)).toBe((1_240).toLocaleString())
    expect(formatDaemonHttpSlowest(row({ slowestMs: 1_240 }))).toBe(
      `${(1_240).toLocaleString()} ms`,
    )
    expect(formatDaemonHttpSlowest(row({ slowestMs: null }))).toBe('—')
  })
})

import { describe, expect, it } from 'vitest'

import {
  createHilosDaemonCronTable,
  formatDaemonCronExecutor,
  formatDaemonCronLastRun,
  formatDaemonCronMoment,
  formatDaemonCronNextRun,
  resolveHilosDaemonCronRow,
  DAEMON_CRON_IDLE_NOT_LEADER,
  type HilosDaemonCronContext,
  type HilosDaemonCronRow,
} from '../../../src/admin/daemon/hilosDaemonCron.js'
import {
  type HilosConnection,
  type TableViewportDescriptor,
} from '../../../src/connection/HilosConnection.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { type ScopeManager } from '../../../src/state/ScopeManager.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'

function row(overrides: Partial<HilosDaemonCronRow> = {}): HilosDaemonCronRow {
  return {
    rowKey: '/cleanup',
    agentId: null,
    name: 'cleanup',
    expression: '0 3 * * *',
    lastRunAt: null,
    nextRunAt: null,
    idleReason: null,
    ...overrides,
  }
}

function rawRow(rowKey: string, slot: Record<string, unknown>): TableRow {
  return { rowKey, slots: { rule: slot } }
}

function stubTable(nodeId = 'n1'): {
  table: ReturnType<typeof createHilosDaemonCronTable>
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
  const context: HilosDaemonCronContext = {
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

  return { table: createHilosDaemonCronTable(context, nodeId), sent }
}

describe('daemon cron row', () => {
  it('reads both owner kinds, nullable moments, and identity from the fragment', () => {
    expect(
      resolveHilosDaemonCronRow(
        rawRow('/cleanup', {
          agentId: null,
          name: 'cleanup',
          expression: '0 3 * * *',
          lastRunAt: null,
          nextRunAt: 1_800_000_000,
          idleReason: null,
          rowKey: 'wrong-slot-key',
        }),
      ),
    ).toEqual(row({ nextRunAt: 1_800_000_000 }))

    expect(
      resolveHilosDaemonCronRow(
        rawRow('log/rotate', {
          agentId: 'log',
          name: 'rotate',
          expression: '0 * * * *',
          lastRunAt: 1_800_000_000,
          nextRunAt: null,
          idleReason: null,
        }),
      ),
    ).toEqual(
      row({
        rowKey: 'log/rotate',
        agentId: 'log',
        name: 'rotate',
        expression: '0 * * * *',
        lastRunAt: 1_800_000_000,
      }),
    )
  })
})

describe('daemon cron viewport', () => {
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

    expect(sent.at(-1)?.page).toBe(HilosPages.DAEMON_CRON)
    expect(sent.at(-1)?.tableKey).toBe('hilosDaemonCron')
    expect(sent.at(-1)?.descriptor.filter).toEqual({ node: 'standalone' })
    expect(sent.at(-1)?.descriptor.sort).toBeNull()
  })

  it('declares the five unsorted columns and each moment dependency', () => {
    const frame = stubTable().table.controller.frame
    expect(frame.declaration?.title).toBeUndefined()
    expect(frame.declaration?.search).toBeUndefined()
    expect(frame.declaration?.filters).toBeUndefined()
    expect(frame.declaration?.empty).toBeUndefined()
    expect(frame.declaration?.mainAction).toBeUndefined()
    expect(frame.declaration?.bulkActions).toBeUndefined()

    const columns = frame.columns.get()
    expect(columns.map((column) => column.key)).toEqual([
      'name',
      'agentId',
      'expression',
      'lastRunAt',
      'nextRunAt',
    ])
    expect(columns.map((column) => column.label)).toEqual([
      'Rule',
      'Run by',
      'Expression',
      'Last run',
      'Next run',
    ])
    expect(columns[0]?.card).toBe('title')
    expect(columns[1]?.card).toBe('badge')
    expect(columns[3]?.reads).toEqual(['idleReason'])
    expect(columns[4]?.reads).toEqual(['idleReason'])
    expect(columns.every((column) => column.sortable !== true)).toBe(true)
  })
})

describe('daemon cron cell text', () => {
  const now = new Date(2026, 9, 8, 12, 0).getTime()

  it('names daemon and agent executors by installation mode', () => {
    expect(formatDaemonCronExecutor(row(), true)).toBe('Daemon (leader)')
    expect(formatDaemonCronExecutor(row(), false)).toBe('Daemon')
    expect(formatDaemonCronExecutor(row({ agentId: 'log:1' }), true)).toBe(
      'log:1',
    )
  })

  it('keeps a follower mark on daemon rows and nullable runs distinct', () => {
    const idle = row({ idleReason: DAEMON_CRON_IDLE_NOT_LEADER })
    expect(formatDaemonCronLastRun(idle, now)).toBe('Not on this node')
    expect(formatDaemonCronNextRun(idle, now)).toBe('—')
    expect(formatDaemonCronLastRun(row(), now)).toBe('Never')
    expect(formatDaemonCronNextRun(row(), now)).toBe('Never')
    const agent = row({ agentId: 'log', nextRunAt: now / 1000 })
    expect(formatDaemonCronNextRun(agent, now)).toContain('Today, ')
  })

  it('uses browser-local calendar days around today', () => {
    const today = new Date(2026, 9, 8, 3, 15)
    const tomorrow = new Date(2026, 9, 9, 3, 15)
    const yesterday = new Date(2026, 9, 7, 3, 15)
    const later = new Date(2026, 9, 12, 3, 15)
    const localTime = today.toLocaleTimeString(undefined, {
      hour: '2-digit',
      minute: '2-digit',
    })

    expect(formatDaemonCronMoment(today.getTime() / 1000, now)).toBe(
      `Today, ${localTime}`,
    )
    expect(formatDaemonCronMoment(tomorrow.getTime() / 1000, now)).toBe(
      `Tomorrow, ${localTime}`,
    )
    expect(formatDaemonCronMoment(yesterday.getTime() / 1000, now)).toBe(
      `Yesterday, ${localTime}`,
    )
    expect(formatDaemonCronMoment(later.getTime() / 1000, now)).toBe(
      later.toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
      }),
    )
  })
})

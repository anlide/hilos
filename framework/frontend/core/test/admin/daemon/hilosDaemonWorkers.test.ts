import { describe, expect, it } from 'vitest'

import {
  createHilosDaemonWorkersTable,
  daemonWorkersEmptyState,
  daemonWorkerLogPath,
  formatDaemonWorkerAgentCount,
  formatDaemonWorkerKind,
  formatDaemonWorkerMemory,
  formatDaemonWorkerName,
  formatDaemonWorkerPid,
  readDaemonWorkersProcessesReported,
  resolveHilosDaemonWorkerRow,
  type HilosDaemonWorkerRow,
  type HilosDaemonWorkersContext,
} from '../../../src/admin/daemon/hilosDaemonWorkers.js'
import {
  type HilosConnection,
  type TableViewportDescriptor,
} from '../../../src/connection/HilosConnection.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'

function row(
  overrides: Partial<HilosDaemonWorkerRow> = {},
): HilosDaemonWorkerRow {
  return {
    rowKey: 'regular:1',
    index: 1,
    kind: 'regular',
    pid: null,
    memoryBytes: null,
    agentCount: 0,
    agentIds: [],
    logStream: 'worker-regular-1.log',
    ...overrides,
  }
}

function rawRow(rowKey: string, slot: Record<string, unknown>): TableRow {
  return { rowKey, slots: { worker: slot } }
}

function stubTable(nodeId = 'n1'): {
  table: ReturnType<typeof createHilosDaemonWorkersTable>
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
  const context: HilosDaemonWorkersContext = {
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

  return { table: createHilosDaemonWorkersTable(context, nodeId), sent }
}

describe('daemon workers row', () => {
  it('reads nullable measurements and identity from the fragment', () => {
    expect(
      resolveHilosDaemonWorkerRow(
        rawRow('regular:1', {
          rowKey: 'wrong-slot-key',
          index: 1,
          kind: 'regular',
          pid: null,
          memoryBytes: null,
          agentCount: 2,
          agentIds: ['agent:a', 7, 'agent:b'],
          logStream: 'worker-regular-1.log',
        }),
      ),
    ).toEqual(row({ agentCount: 2, agentIds: ['agent:a', 'agent:b'] }))
    expect(
      resolveHilosDaemonWorkerRow(
        rawRow('monopolistic:3', {
          index: 3,
          kind: 'monopolistic',
          pid: 4321,
          memoryBytes: 4096,
          agentCount: 0,
          agentIds: null,
          logStream: 'worker-monopolistic-3.log',
        }),
      ),
    ).toEqual(
      row({
        rowKey: 'monopolistic:3',
        index: 3,
        kind: 'monopolistic',
        pid: 4321,
        memoryBytes: 4096,
        logStream: 'worker-monopolistic-3.log',
      }),
    )
  })
})

describe('daemon workers empty state', () => {
  it('reads whether the node has reported its process roster', () => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.DAEMON_WORKERS)
    const reported = readDaemonWorkersProcessesReported(scopes)

    expect(reported.get()).toBe(false)
    page.data.set('processesReported', true)
    expect(reported.get()).toBe(true)
    page.data.set('processesReported', false)
    expect(reported.get()).toBe(false)
  })

  it('distinguishes a completed empty roster, silence, and waiting', () => {
    const silent = { clustered: true, state: 'silent', silentSince: 42 }
    const standby = { clustered: true, state: 'standby', silentSince: null }

    expect(daemonWorkersEmptyState(null, true)).toBe('none')
    expect(daemonWorkersEmptyState(silent, true)).toBe('none')
    expect(daemonWorkersEmptyState(silent, false)).toBe('silent')
    expect(daemonWorkersEmptyState(null, false)).toBe('waiting')
    expect(daemonWorkersEmptyState(standby, false)).toBe('waiting')
  })
})

describe('daemon workers viewport', () => {
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

    expect(sent.at(-1)?.page).toBe(HilosPages.DAEMON_WORKERS)
    expect(sent.at(-1)?.tableKey).toBe('hilosDaemonWorkers')
    expect(sent.at(-1)?.descriptor.filter).toEqual({ node: 'standalone' })
    expect(sent.at(-1)?.descriptor.sort).toBeNull()
  })

  it('declares six unsorted row columns and an agent detail panel', () => {
    const frame = stubTable().table.controller.frame
    expect(frame.declaration?.title).toBeUndefined()
    expect(frame.declaration?.search).toBeUndefined()
    expect(frame.declaration?.filters).toBeUndefined()
    expect(frame.declaration?.empty).toBeUndefined()
    expect(frame.declaration?.mainAction).toBeUndefined()
    expect(frame.declaration?.bulkActions).toBeUndefined()

    const columns = frame.columns.get()
    expect(columns.map((column) => column.key)).toEqual([
      'index',
      'kind',
      'pid',
      'agentCount',
      'memoryBytes',
      'actions',
      'agentIds',
    ])
    expect(columns.map((column) => column.label)).toEqual([
      'Worker',
      'Kind',
      'PID',
      'Agents',
      'Memory',
      '',
      'Agent instances',
    ])
    expect(columns[0]?.card).toBe('title')
    expect(columns[0]?.reads).toEqual(['kind'])
    expect(columns[1]?.card).toBe('badge')
    expect(columns[3]?.headerClass).toBe('text-center')
    expect(columns[5]?.reads).toEqual(['logStream'])
    expect(columns[6]?.detail).toBe(true)
    expect(columns.every((column) => column.sortable !== true)).toBe(true)
  })
})

describe('daemon workers cell text and log address', () => {
  it('names both worker kinds by their actual index', () => {
    expect(formatDaemonWorkerName(1, 'regular')).toBe('w1')
    expect(formatDaemonWorkerName(3, 'monopolistic')).toBe('m3')
    expect(formatDaemonWorkerName(4, 'future')).toBe('future:4')
    expect(formatDaemonWorkerKind(row())).toBe('Ordinary')
    expect(formatDaemonWorkerKind(row({ kind: 'monopolistic' }))).toBe(
      'Monopolistic',
    )
    expect(formatDaemonWorkerKind(row({ kind: 'future' }))).toBe('future')
  })

  it('keeps unknown measurements distinct from zero and formats counts', () => {
    expect(formatDaemonWorkerPid(row())).toBe('—')
    expect(formatDaemonWorkerPid(row({ pid: 4321 }))).toBe('4321')
    expect(formatDaemonWorkerMemory(row())).toBe('—')
    expect(formatDaemonWorkerMemory(row({ memoryBytes: 0 }))).toBe('0 B')
    expect(formatDaemonWorkerMemory(row({ memoryBytes: 2048 }))).toBe('2.0 KB')
    expect(formatDaemonWorkerAgentCount(row())).toBe('·')
    expect(formatDaemonWorkerAgentCount(row({ agentCount: 2 }))).toBe('2')
  })

  it('opens the live worker file on the route node', () => {
    expect(daemonWorkerLogPath('n1', row())).toBe(
      '/hilos/logs/view/n1/live/worker-regular-1.log',
    )
    expect(daemonWorkerLogPath('standalone', row())).toBe(
      '/hilos/logs/view/standalone/live/worker-regular-1.log',
    )
  })
})

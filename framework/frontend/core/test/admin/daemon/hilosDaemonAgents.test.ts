import { describe, expect, it } from 'vitest'

import {
  createHilosDaemonAgentsTable,
  daemonAgentLogPath,
  formatDaemonAgentKind,
  formatDaemonAgentOwnership,
  formatDaemonAgentWorker,
  resolveHilosDaemonAgentRow,
  type HilosDaemonAgentRow,
  type HilosDaemonAgentsContext,
} from '../../../src/admin/daemon/hilosDaemonAgents.js'
import {
  type HilosConnection,
  type TableViewportDescriptor,
} from '../../../src/connection/HilosConnection.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { type ScopeManager } from '../../../src/state/ScopeManager.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'

function row(
  overrides: Partial<HilosDaemonAgentRow> = {},
): HilosDaemonAgentRow {
  return {
    rowKey: 'bot:1',
    agentId: 'bot:1',
    workerIndex: 1,
    workerKind: 'regular',
    placement: 'node',
    idle: false,
    ownsRt: [],
    ownsDb: [],
    logStream: 'agent-bot_1.log',
    ...overrides,
  }
}

function rawRow(rowKey: string, slot: Record<string, unknown>): TableRow {
  return { rowKey, slots: { agent: slot } }
}

function stubTable(nodeId = 'n1'): {
  table: ReturnType<typeof createHilosDaemonAgentsTable>
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
  const context: HilosDaemonAgentsContext = {
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

  return { table: createHilosDaemonAgentsTable(context, nodeId), sent }
}

describe('daemon agent row', () => {
  it('reads the inline ownership lists and fragment identity', () => {
    expect(
      resolveHilosDaemonAgentRow(
        rawRow('bot:1', {
          rowKey: 'wrong-slot-key',
          agentId: 'bot:1',
          workerIndex: 3,
          workerKind: 'monopolistic',
          placement: 'policy',
          idle: true,
          ownsRt: [{ collection: 'rooms', width: 'rows' }],
          ownsDb: [{ collection: 'messages', width: 'set' }],
          logStream: 'agent-bot_1.log',
        }),
      ),
    ).toEqual(
      row({
        workerIndex: 3,
        workerKind: 'monopolistic',
        placement: 'policy',
        idle: true,
        ownsRt: [{ collection: 'rooms', width: 'rows' }],
        ownsDb: [{ collection: 'messages', width: 'set' }],
      }),
    )

    expect(
      resolveHilosDaemonAgentRow(
        rawRow('bot:2', {
          agentId: 'bot:2',
          workerIndex: 1,
          workerKind: 'regular',
          placement: 'node',
          idle: false,
          ownsRt: 'not-a-list',
          ownsDb: [{ collection: 12, width: 'whole' }],
          logStream: 'agent-bot_2.log',
        }),
      ),
    ).toEqual(
      row({
        rowKey: 'bot:2',
        agentId: 'bot:2',
        ownsRt: [],
        ownsDb: [],
        logStream: 'agent-bot_2.log',
      }),
    )
  })
})

describe('daemon agents viewport', () => {
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

    expect(sent.at(-1)?.page).toBe(HilosPages.DAEMON_AGENTS)
    expect(sent.at(-1)?.tableKey).toBe('hilosDaemonAgents')
    expect(sent.at(-1)?.descriptor.filter).toEqual({ node: 'standalone' })
    expect(sent.at(-1)?.descriptor.sort).toBeNull()
  })

  it('declares search and six unsorted row columns', () => {
    const frame = stubTable().table.controller.frame
    expect(frame.declaration?.title).toBeUndefined()
    expect(frame.declaration?.search?.placeholder).toBe('Search by agent id…')
    expect(frame.declaration?.filters).toBeUndefined()
    expect(frame.declaration?.empty).toBeUndefined()
    expect(frame.declaration?.mainAction).toBeUndefined()
    expect(frame.declaration?.bulkActions).toBeUndefined()

    const columns = frame.columns.get()
    expect(columns.map((column) => column.key)).toEqual([
      'agentId',
      'workerIndex',
      'placement',
      'ownsRt',
      'ownsDb',
      'actions',
    ])
    expect(columns.map((column) => column.label)).toEqual([
      'Agent',
      'Worker',
      'Kind',
      'Owns in RT',
      'Owns in DB',
      '',
    ])
    expect(columns.map((column) => column.sortable)).toEqual([
      undefined,
      undefined,
      undefined,
      undefined,
      undefined,
      undefined,
    ])
    expect(columns[0]?.card).toBe('title')
    expect(columns[1]?.reads).toEqual(['workerKind'])
    expect(columns[2]?.card).toBe('badge')
    expect(columns[2]?.reads).toEqual(['idle'])
    expect(columns[5]?.reads).toEqual(['agentId', 'logStream'])
  })
})

describe('daemon agent labels and log link', () => {
  it('uses the shared w/m worker naming rule', () => {
    expect(formatDaemonAgentWorker(row())).toBe('w1')
    expect(
      formatDaemonAgentWorker(
        row({ workerIndex: 3, workerKind: 'monopolistic' }),
      ),
    ).toBe('m3')
  })

  it('labels all placements, lazy agents and both standalone states', () => {
    expect(formatDaemonAgentKind(row(), true)).toBe('Node replica')
    expect(formatDaemonAgentKind(row({ placement: 'leader' }), true)).toBe(
      'Leader-hosted',
    )
    expect(
      formatDaemonAgentKind(row({ placement: 'policy', idle: true }), true),
    ).toBe('Policy-placed · lazy')
    expect(formatDaemonAgentKind(row({ placement: 'future' }), true)).toBe(
      'future',
    )
    expect(formatDaemonAgentKind(row(), false)).toBe('Stays up')
    expect(formatDaemonAgentKind(row({ idle: true }), false)).toBe('Lazy')
  })

  it('labels whole, row and set claims, including unknown widths', () => {
    expect(formatDaemonAgentOwnership([])).toBe('—')
    expect(
      formatDaemonAgentOwnership([
        { collection: 'whole', width: 'whole' },
        { collection: 'rows', width: 'rows' },
        { collection: 'set', width: 'set' },
        { collection: 'future', width: 'future' },
      ]),
    ).toBe('whole, rows · rows, set · set, future · future')
  })

  it('links the exact live stream on both cluster and standalone nodes', () => {
    expect(daemonAgentLogPath('n1', row())).toBe(
      '/hilos/logs/view/n1/live/agent-bot_1.log',
    )
    expect(daemonAgentLogPath('standalone', row())).toBe(
      '/hilos/logs/view/standalone/live/agent-bot_1.log',
    )
  })
})

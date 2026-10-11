import { describe, expect, it } from 'vitest'

import {
  createHilosDaemonPlacementTable,
  daemonPlacementCellIds,
  daemonPlacementNodeColumnKey,
  formatDaemonPlacementCell,
  formatDaemonPlacementKind,
  formatDaemonPlacementNodeLabel,
  formatDaemonPlacementState,
  resolveHilosDaemonPlacementRow,
  type HilosDaemonPlacementContext,
  type HilosDaemonPlacementNode,
  type HilosDaemonPlacementRow,
} from '../../../src/admin/daemon/hilosDaemonPlacement.js'
import {
  type HilosConnection,
  type TableViewportDescriptor,
} from '../../../src/connection/HilosConnection.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { type ScopeManager } from '../../../src/state/ScopeManager.js'
import { createSignal } from '../../../src/state/signal.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'

function row(
  overrides: Partial<HilosDaemonPlacementRow> = {},
): HilosDaemonPlacementRow {
  return {
    rowKey: 'chat_room',
    agentType: 'chat_room',
    placement: 'policy',
    idle: false,
    nodes: [],
    unplacedAgentIds: [],
    ...overrides,
  }
}

function rawRow(rowKey: string, slot: Record<string, unknown>): TableRow {
  return { rowKey, slots: { type: slot } }
}

function stubTable(nodes: readonly HilosDaemonPlacementNode[]) {
  const sent: Array<{
    page: string
    tableKey: string
    descriptor: TableViewportDescriptor
  }> = []
  const sentRendered: Array<{
    page: string
    tableKey: string
    rendered: readonly string[]
  }> = []
  const nodeSignal = createSignal(nodes)
  const context: HilosDaemonPlacementContext = {
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
      sendTableRendered(
        page: string,
        tableKey: string,
        rendered: readonly string[],
      ): boolean {
        sentRendered.push({ page, tableKey, rendered })
        return true
      },
    } as unknown as HilosConnection,
    scopes: {} as ScopeManager,
  }

  return {
    table: createHilosDaemonPlacementTable(context, nodeSignal),
    nodeSignal,
    sent,
    sentRendered,
  }
}

describe('daemon placement row', () => {
  it('reads null and populated node rosters, unplaced ids, and fragment identity', () => {
    expect(
      resolveHilosDaemonPlacementRow(
        rawRow('chat_room', {
          rowKey: 'wrong-slot-key',
          agentType: 'chat_room',
          placement: 'policy',
          idle: true,
          nodes: [
            { nodeId: 'node-1', agentIds: null },
            { nodeId: 'node-2', agentIds: ['chat_room:2', 'chat_room:10'] },
          ],
          unplacedAgentIds: ['chat_room:3', 7],
        }),
      ),
    ).toEqual(
      row({
        idle: true,
        nodes: [
          { nodeId: 'node-1', agentIds: null },
          { nodeId: 'node-2', agentIds: ['chat_room:2', 'chat_room:10'] },
        ],
        unplacedAgentIds: ['chat_room:3'],
      }),
    )
  })

  it('rejects the entire malformed node list', () => {
    expect(
      resolveHilosDaemonPlacementRow(
        rawRow('chat_room', {
          agentType: 'chat_room',
          placement: 'node',
          idle: false,
          nodes: [
            { nodeId: 'node-1', agentIds: [] },
            { nodeId: 'node-2', agentIds: [7] },
          ],
          unplacedAgentIds: null,
        }),
      ),
    ).toEqual(row({ placement: 'node' }))
  })
})

describe('daemon placement viewport', () => {
  it('declares the three page nodes in order, with a leader star', () => {
    const { table } = stubTable([
      { nodeId: 'node-1', leader: false },
      { nodeId: 'node-2', leader: true },
      { nodeId: 'node-10', leader: false },
    ])
    const frame = table.controller.frame
    expect(frame.declaration?.title).toBeUndefined()
    expect(frame.declaration?.search?.placeholder).toBe('Search by agent id…')
    expect(frame.declaration?.filters).toBeUndefined()
    expect(frame.declaration?.empty).toBeUndefined()
    expect(frame.declaration?.mainAction).toBeUndefined()
    expect(frame.declaration?.bulkActions).toBeUndefined()

    const columns = frame.columns.get()
    expect(columns.map((column) => column.key)).toEqual([
      'agentType',
      'placement',
      'node:node-1',
      'node:node-2',
      'node:node-10',
      'unplacedAgentIds',
    ])
    expect(columns.map((column) => column.label)).toEqual([
      'Agent type',
      'Kind',
      'node-1',
      'node-2 ★',
      'node-10',
      'State',
    ])
    expect(columns[0]?.card).toBe('title')
    expect(columns[1]?.reads).toEqual(['idle'])
    expect(columns[2]?.reads).toEqual(['nodes'])
    expect(columns[2]?.headerClass).toBe('text-center')
    expect(columns[2]?.cellClass).toBe('text-center')
    expect(columns[5]?.card).toBe('badge')
    expect(columns[5]?.headerClass).toBe('text-end')
    expect(columns[5]?.cellClass).toBe('text-end')
    expect(columns.every((column) => column.sortable !== true)).toBe(true)
  })

  it('keeps a standalone node id and rebuilds columns after the signal changes', () => {
    const { table, nodeSignal } = stubTable([
      { nodeId: 'standalone', leader: false },
    ])
    expect(
      table.controller.frame.columns.get().map((column) => column.key),
    ).toEqual(['agentType', 'placement', 'node:standalone', 'unplacedAgentIds'])
    expect(table.controller.frame.columns.get()[2]?.label).toBe('standalone')

    nodeSignal.set([
      { nodeId: 'node-1', leader: true },
      { nodeId: 'node-2', leader: false },
    ])
    expect(
      table.controller.frame.columns.get().map((column) => column.key),
    ).toEqual([
      'agentType',
      'placement',
      'node:node-1',
      'node:node-2',
      'unplacedAgentIds',
    ])
    expect(table.controller.frame.columns.get()[2]?.label).toBe('node-1 ★')
  })

  it('declares rendered fields and sends a descriptor without sort or domain filter', () => {
    const { table, sent, sentRendered } = stubTable([
      { nodeId: 'node-1', leader: false },
    ])
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
    expect(sentRendered).toHaveLength(1)
    expect(sentRendered[0]?.page).toBe(HilosPages.DAEMON)
    expect(sentRendered[0]?.tableKey).toBe('hilosDaemonPlacement')
    expect(sentRendered[0]?.rendered).toEqual([
      'agentType',
      'placement',
      'idle',
      'node:node-1',
      'nodes',
      'unplacedAgentIds',
    ])

    table.controller.refresh()
    expect(sent.at(-1)?.page).toBe(HilosPages.DAEMON)
    expect(sent.at(-1)?.tableKey).toBe('hilosDaemonPlacement')
    expect(sent.at(-1)?.descriptor.filter).toEqual({})
    expect(sent.at(-1)?.descriptor.sort).toBeNull()
    expect(sent.at(-1)?.descriptor.rendered).toEqual(sentRendered[0]?.rendered)
  })
})

describe('daemon placement labels', () => {
  it('formats the column key, leader label, and known, empty or unknown cells', () => {
    const placement = row({
      nodes: [
        { nodeId: 'node-1', agentIds: null },
        { nodeId: 'node-2', agentIds: [] },
        { nodeId: 'node-3', agentIds: ['chat_room:2', 'chat_room:10'] },
      ],
    })
    expect(daemonPlacementNodeColumnKey('node-3')).toBe('node:node-3')
    expect(
      formatDaemonPlacementNodeLabel({ nodeId: 'node-3', leader: true }),
    ).toBe('node-3 ★')
    expect(daemonPlacementCellIds(placement, 'node-3')).toEqual([
      'chat_room:2',
      'chat_room:10',
    ])
    expect(formatDaemonPlacementCell(placement, 'node-1')).toBe('?')
    expect(formatDaemonPlacementCell(placement, 'node-2')).toBe('·')
    expect(formatDaemonPlacementCell(placement, 'node-3')).toBe('2')
    expect(daemonPlacementCellIds(placement, 'new-node')).toBeNull()
    expect(formatDaemonPlacementCell(placement, 'new-node')).toBe('?')
  })

  it('shares cluster and standalone kind labels and the unplaced verdict', () => {
    expect(formatDaemonPlacementKind(row({ placement: 'node' }), true)).toBe(
      'Node replica',
    )
    expect(formatDaemonPlacementKind(row({ placement: 'leader' }), true)).toBe(
      'Leader-hosted',
    )
    expect(
      formatDaemonPlacementKind(row({ placement: 'policy', idle: true }), true),
    ).toBe('Policy-placed · lazy')
    expect(formatDaemonPlacementKind(row(), false)).toBe('Stays up')
    expect(formatDaemonPlacementKind(row({ idle: true }), false)).toBe('Lazy')
    expect(formatDaemonPlacementState(row())).toBe('')
    expect(
      formatDaemonPlacementState(row({ unplacedAgentIds: ['chat_room:111'] })),
    ).toBe('Unplaced')
  })
})

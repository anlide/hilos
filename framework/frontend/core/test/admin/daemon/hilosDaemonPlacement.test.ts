import { describe, expect, it, vi } from 'vitest'

import {
  createHilosDaemonPlacementTable,
  createHilosDaemonPlacementView,
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
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { createSignal } from '../../../src/state/signal.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'
import {
  type HilosDaemonOverviewData,
  type HilosDaemonOverviewNode,
} from '../../../src/admin/daemon/hilosDaemonOverview.js'

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
  const unbind = vi.fn()
  const on = vi.fn(() => unbind)
  const unregister = vi.fn()
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.DAEMON)
  const context: HilosDaemonPlacementContext = {
    connection: {
      on,
      registerTableWindow: vi.fn(),
      unregisterTableWindow: unregister,
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
    scopes,
  }

  return {
    table: createHilosDaemonPlacementTable(context, nodeSignal),
    nodeSignal,
    sent,
    sentRendered,
    context,
    on,
    unbind,
    unregister,
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
      'nodes',
    ])
    expect(columns.map((column) => column.label)).toEqual([
      'Agent type',
      'Kind',
      'node-1',
      'node-2 ★',
      'node-10',
      'State',
      'Instances',
    ])
    expect(columns[0]?.card).toBe('title')
    expect(columns[1]?.reads).toEqual(['idle'])
    expect(columns[2]?.reads).toEqual(['nodes'])
    expect(columns[2]?.headerClass).toBe('text-center')
    expect(columns[2]?.cellClass).toBe('text-center')
    expect(columns[5]?.card).toBe('badge')
    expect(columns[5]?.headerClass).toBe('text-end')
    expect(columns[5]?.cellClass).toBe('text-end')
    expect(columns[6]?.detail).toBe(true)
    expect(columns[6]?.detailWide).toBe(true)
    expect(columns.every((column) => column.sortable !== true)).toBe(true)
  })

  it('keeps a standalone node id and rebuilds columns after the signal changes', () => {
    const { table, nodeSignal } = stubTable([
      { nodeId: 'standalone', leader: false },
    ])
    expect(
      table.controller.frame.columns.get().map((column) => column.key),
    ).toEqual([
      'agentType',
      'placement',
      'node:standalone',
      'unplacedAgentIds',
      'nodes',
    ])
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
      'nodes',
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

function viewPicture(
  overrides: Partial<HilosDaemonOverviewNode> = {},
): HilosDaemonOverviewData {
  return {
    clustered: true,
    nodes: [
      {
        nodeId: 'n1',
        online: true,
        role: 'master',
        state: 'leader',
        silentSince: null,
        workers: [],
        workerRestarts24h: 0,
        uptimeMinutes: 0,
        measurements: null,
        standing: null,
        environment: null,
        ...overrides,
      },
    ],
    consensus: null,
    placement: { instances: 2, types: 1, clusterTypes: 1, unplacedTypes: [] },
    environmentComparison: {
      answeringNodes: 1,
      divergedKeys: null,
      sourcesDifferKeys: null,
    },
  }
}

function stubView() {
  const h = stubTable([])
  const overview = createSignal<HilosDaemonOverviewData | null>(viewPicture())
  const view = createHilosDaemonPlacementView(h.context, overview)
  const window = (
    ids: readonly string[] | null = ['chat_room:1', 'chat_room:2'],
    requestId?: string,
  ) => {
    view.controller.ingestWindow(
      [
        rawRow('chat_room', {
          agentType: 'chat_room',
          placement: 'policy',
          idle: false,
          nodes: [{ nodeId: 'n1', agentIds: ids }],
          unplacedAgentIds: [],
        }),
      ],
      1,
      true,
      null,
      null,
      25,
      null,
      requestId,
    )
  }
  view.start()
  window()
  return { ...h, overview, view, window }
}

describe('daemon placement view', () => {
  it('derives dynamic columns and leader marks from the complete picture', () => {
    const h = stubView()
    expect(h.view.nodes.get()).toEqual([{ nodeId: 'n1', leader: true }])
    expect(h.view.controller.frame.columns.get()[2]?.label).toBe('n1 ★')
    h.overview.set({ ...viewPicture(), clustered: false })
    expect(h.view.nodes.get()).toEqual([{ nodeId: 'n1', leader: false }])
    h.overview.set(viewPicture({ nodeId: 'n2', state: 'standby' }))
    expect(h.view.controller.frame.columns.get()[2]?.key).toBe('node:n2')
    h.view.dispose()
  })

  it('toggles the same cell through the row expansion and follows current window ids', () => {
    const h = stubView()
    expect(h.view.controller.rows.get()[0]?.expandable).toBe(false)
    h.view.controller.expandRow('chat_room', true)
    expect(h.view.controller.rows.get()[0]?.expanded).toBe(false)
    h.view.openCell('chat_room', 'n1')
    expect(h.view.selectedCell.get()).toEqual({
      rowKey: 'chat_room',
      agentType: 'chat_room',
      nodeId: 'n1',
      agentIds: ['chat_room:1', 'chat_room:2'],
    })
    expect(h.view.controller.rows.get()[0]?.expanded).toBe(true)
    h.view.openCell('chat_room', 'n1')
    expect(h.view.selectedCell.get()).toBeNull()
    expect(h.view.controller.rows.get()[0]?.expanded).toBe(false)
    expect(h.view.controller.rows.get()[0]?.expandable).toBe(false)
    h.view.openCell('chat_room', 'n1')
    h.window(['chat_room:99'])
    expect(h.view.selectedCell.get()?.agentIds).toEqual(['chat_room:99'])
    expect(h.sent).toEqual([])
    h.view.closeCell()
    expect(h.view.selectedCell.get()).toBeNull()
    expect(h.view.controller.rows.get()[0]?.expanded).toBe(false)
    h.view.dispose()
  })

  it('shares chevron collapse and reopening with the cell button without reopening on live updates', () => {
    const h = stubView()
    h.view.openCell('chat_room', 'n1')
    h.view.controller.expandRow('chat_room', false)
    expect(h.view.selectedCell.get()).toBeNull()
    expect(h.view.controller.rows.get()[0]?.expandable).toBe(true)
    h.window(['chat_room:99'])
    expect(h.view.selectedCell.get()).toBeNull()
    h.view.controller.expandRow('chat_room', true)
    expect(h.view.selectedCell.get()?.agentIds).toEqual(['chat_room:99'])
    h.view.controller.expandRow('chat_room', false)
    h.view.openCell('chat_room', 'n1')
    expect(h.view.selectedCell.get()?.agentIds).toEqual(['chat_room:99'])
    h.view.closeCell()
    h.view.controller.expandRow('chat_room', true)
    expect(h.view.selectedCell.get()).toBeNull()
    h.view.dispose()
  })

  it('replaces the one chosen address when another cell opens', () => {
    const h = stubView()
    h.overview.set({
      ...viewPicture(),
      nodes: [...viewPicture().nodes, ...viewPicture({ nodeId: 'n2' }).nodes],
    })
    h.view.controller.ingestWindow(
      [
        rawRow('chat_room', {
          agentType: 'chat_room',
          nodes: [
            { nodeId: 'n1', agentIds: ['chat_room:1'] },
            { nodeId: 'n2', agentIds: ['chat_room:2'] },
          ],
        }),
      ],
      1,
      true,
      null,
      null,
      25,
    )
    h.view.openCell('chat_room', 'n1')
    h.view.openCell('chat_room', 'n2')
    expect(h.view.selectedCell.get()).toEqual({
      rowKey: 'chat_room',
      agentType: 'chat_room',
      nodeId: 'n2',
      agentIds: ['chat_room:2'],
    })
    expect(h.sent).toEqual([])
    h.view.dispose()
  })

  it('moves the only expansion between types and refuses chevrons on unselected rows', () => {
    const h = stubView()
    h.view.controller.ingestAppend(
      rawRow('hilos_daemon_node', {
        agentType: 'hilos_daemon_node',
        nodes: [{ nodeId: 'n1', agentIds: ['hilos_daemon_node'] }],
      }),
      2,
      true,
      null,
      null,
    )
    h.view.openCell('chat_room', 'n1')
    h.view.controller.expandRow('hilos_daemon_node', true)
    expect(
      h.view.controller.rows
        .get()
        .filter((row) => row.expanded)
        .map((row) => row.rowKey),
    ).toEqual(['chat_room'])
    h.view.openCell('hilos_daemon_node', 'n1')
    expect(
      h.view.controller.rows
        .get()
        .filter((row) => row.expanded)
        .map((row) => row.rowKey),
    ).toEqual(['hilos_daemon_node'])
    expect(h.view.selectedCell.get()?.agentIds).toEqual(['hilos_daemon_node'])
    h.view.openCell('chat_room', 'n1')
    expect(
      h.view.controller.rows
        .get()
        .filter((row) => row.expanded)
        .map((row) => row.rowKey),
    ).toEqual(['chat_room'])
    h.view.dispose()
  })

  it.each([[], null])(
    'forgets a selected cell whose new roster is %j',
    (ids) => {
      const h = stubView()
      h.view.openCell('chat_room', 'n1')
      h.window(ids)
      expect(h.view.selectedCell.get()).toBeNull()
      expect(h.view.controller.rows.get()[0]?.expanded).toBe(false)
      expect(h.view.controller.rows.get()[0]?.expandable).toBe(false)
      h.view.openCell('chat_room', 'n1')
      h.window()
      expect(h.view.selectedCell.get()).toBeNull()
      h.view.dispose()
    },
  )

  it('forgets a missing row or node cell and will not revive it later', () => {
    const h = stubView()
    h.view.openCell('chat_room', 'n1')
    h.view.controller.ingestWindow([], 0, true, null, null, 25)
    h.window()
    expect(h.view.selectedCell.get()).toBeNull()
    h.view.openCell('chat_room', 'n1')
    h.view.controller.ingestWindow(
      [
        rawRow('chat_room', {
          agentType: 'chat_room',
          nodes: [],
        }),
      ],
      1,
      true,
      null,
      null,
      25,
    )
    h.window()
    expect(h.view.selectedCell.get()).toBeNull()
    h.view.dispose()
  })

  it('forgets an applied removal and does not reopen a row that returns', () => {
    const h = stubView()
    h.view.openCell('chat_room', 'n1')
    h.view.controller.ingestDelta({
      kind: 'row_removed',
      rowKey: 'chat_room',
      reason: 'deleted',
    })
    h.view.controller.apply()
    expect(h.view.selectedCell.get()).toBeNull()
    expect(h.view.controller.rows.get().some((row) => row.expanded)).toBe(false)
    h.window()
    expect(h.view.selectedCell.get()).toBeNull()
    h.view.dispose()
  })

  it.each(['removed', 'silent', 'unreported', 'unknown'] as const)(
    'forgets selection when the node is %s and cannot reopen old ids',
    (state) => {
      const h = stubView()
      h.view.openCell('chat_room', 'n1')
      h.overview.set(
        state === 'unknown'
          ? null
          : state === 'removed'
            ? { ...viewPicture(), nodes: [] }
            : viewPicture(
                state === 'silent'
                  ? { online: false, state: 'silent' }
                  : { workers: null },
              ),
      )
      expect(h.view.selectedCell.get()).toBeNull()
      h.view.openCell('chat_room', 'n1')
      expect(h.view.selectedCell.get()).toBeNull()
      h.overview.set(viewPicture())
      expect(h.view.selectedCell.get()).toBeNull()
      h.view.dispose()
    },
  )

  it('closes on search and keeps the arriving window collapsed until a cell opens', () => {
    vi.useFakeTimers()
    const h = stubView()
    try {
      h.view.openCell('chat_room', 'n1')
      h.view.controller.setSearch('99')
      vi.advanceTimersByTime(1000)
      expect(h.view.controller.frame.body.get()).toBe('loading')
      expect(h.view.selectedCell.get()).toBeNull()
      h.view.openCell('chat_room', 'n1')
      h.window(['chat_room:99'], h.sent.at(-1)?.descriptor.requestId)
      expect(h.view.selectedCell.get()).toBeNull()
      expect(h.view.controller.rows.get()[0]?.expanded).toBe(false)
      h.view.openCell('chat_room', 'n1')
      expect(h.view.selectedCell.get()?.agentIds).toEqual(['chat_room:99'])
      expect(h.view.controller.rows.get()[0]?.expanded).toBe(true)
      h.view.controller.setSearch('absent')
      vi.advanceTimersByTime(1000)
      h.view.controller.ingestWindow(
        [],
        0,
        true,
        null,
        null,
        25,
        null,
        h.sent.at(-1)?.descriptor.requestId,
      )
      h.window()
      expect(h.view.selectedCell.get()).toBeNull()
    } finally {
      h.view.dispose()
      vi.useRealTimers()
    }
  })

  it('closes through a fast search and keeps a chevron-collapsed cell closed', () => {
    const h = stubView()
    h.view.openCell('chat_room', 'n1')
    h.view.controller.setSearch('99')
    h.window(['chat_room:99'])
    expect(h.view.selectedCell.get()).toBeNull()
    expect(h.view.controller.rows.get()[0]?.expanded).toBe(false)
    h.view.openCell('chat_room', 'n1')
    expect(h.view.selectedCell.get()?.agentIds).toEqual(['chat_room:99'])
    h.view.controller.expandRow('chat_room', false)
    h.view.controller.setSearch('room')
    h.window(['chat_room:100'])
    expect(h.view.selectedCell.get()).toBeNull()
    h.view.controller.expandRow('chat_room', true)
    expect(h.view.selectedCell.get()?.agentIds).toEqual(['chat_room:100'])
    h.view.dispose()
  })

  it.each(['freeze', 'refusal'] as const)(
    'forgets selection on %s',
    (state) => {
      const h = stubView()
      h.view.openCell('chat_room', 'n1')
      if (state === 'freeze') h.view.controller.ingestFrozen(123)
      else h.view.controller.ingestRefusal('unavailable')
      expect(h.view.selectedCell.get()).toBeNull()
      expect(h.view.controller.rows.get().some((row) => row.expanded)).toBe(
        false,
      )
      h.view.openCell('chat_room', 'n1')
      h.window()
      expect(h.view.selectedCell.get()).toBeNull()
      h.view.dispose()
    },
  )

  it('binds once and disposes every table and selection subscription', () => {
    const h = stubView()
    const bindings = h.on.mock.calls.length
    expect(bindings).toBeGreaterThan(0)
    h.view.start()
    expect(h.on).toHaveBeenCalledTimes(bindings)
    h.view.openCell('chat_room', 'n1')
    h.view.dispose()
    expect(h.view.selectedCell.get()).toBeNull()
    expect(h.view.controller.rows.get()[0]?.expanded).toBe(false)
    expect(h.unbind).toHaveBeenCalledTimes(bindings)
    expect(h.unregister).toHaveBeenCalledWith(
      'hilosDaemonPlacement',
      h.view.controller,
    )
    // Disposal leaves no window watcher that could restore an expansion on search.
    h.view.openCell('chat_room', 'n1')
    h.view.controller.setSearch('99')
    h.window(['chat_room:99'])
    expect(h.view.selectedCell.get()).toBeNull()
    h.view.closeCell()
  })
})

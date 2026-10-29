import { describe, expect, it, vi } from 'vitest'

import {
  createHilosLogNodesTable,
  resolveHilosLogNodeRow,
  type HilosLogNodeRow,
} from '../../../src/admin/logs/index.js'
import { Emitter } from '../../../src/connection/events.js'
import {
  type HilosConnection,
  type HilosConnectionEventMap,
  type TableWindowDescriptorSource,
} from '../../../src/connection/HilosConnection.js'
import { type PageResponseWire } from '../../../src/protocol/scopePayload.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'
import { hilosTableRowMessageCells } from '../../../src/table/tableRowMessage.js'

function node(overrides: Partial<HilosLogNodeRow> = {}): HilosLogNodeRow {
  return {
    nodeId: 'node-1',
    available: true,
    lastRotationAt: 1800000000000,
    liveBytes: 1024,
    archiveBytes: 4096,
    growthBytesPerDay: 512,
    batchesDueForTakeout: 0,
    ...overrides,
  }
}

function nodeRow(overrides: Partial<HilosLogNodeRow> = {}): TableRow {
  const fields = node(overrides)

  return { rowKey: fields.nodeId, slots: { node: { ...fields } } }
}

function windowData(rows: TableRow[], totalCount = rows.length) {
  return {
    rows: rows.map((row) => ({ rowKey: row.rowKey, slots: row.slots })),
    totalCount,
    totalExact: true,
    sort: [{ field: 'nodeId', direction: 'asc' as const }],
    limit: 25,
    rowsBefore: 0,
    firstAnchor: rows.length ? { nodeId: rows[0].rowKey } : null,
    lastAnchor: rows.length ? { nodeId: rows.at(-1)?.rowKey } : null,
  }
}

/** The real binder and scope manager, with only the connection transport stubbed. */
function setup() {
  const emitter = new Emitter<HilosConnectionEventMap>()
  const registered = new Map<string, TableWindowDescriptorSource>()
  const sendTableViewport = vi.fn(() => true)
  const sendTableRendered = vi.fn(() => true)
  const sendTableFacets = vi.fn(() => true)
  let listenerCount = 0
  const connection = {
    on<K extends keyof HilosConnectionEventMap>(
      event: K,
      listener: (payload: HilosConnectionEventMap[K]) => void,
    ): () => void {
      const off = emitter.on(event, listener)
      listenerCount += 1
      return () => {
        listenerCount -= 1
        off()
      }
    },
    registerTableWindow(tableKey: string, source: TableWindowDescriptorSource) {
      registered.set(tableKey, source)
    },
    unregisterTableWindow(
      tableKey: string,
      source: TableWindowDescriptorSource,
    ) {
      if (registered.get(tableKey) === source) {
        registered.delete(tableKey)
      }
    },
    sendTableViewport,
    sendTableRendered,
    sendTableFacets,
  } as unknown as HilosConnection
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.LOGS)
  const table = createHilosLogNodesTable({ connection, scopes })

  return {
    table,
    scopes,
    emitter,
    registered,
    sendTableViewport,
    sendTableRendered,
    sendTableFacets,
    listenerCount: () => listenerCount,
    pageWindow(rows: TableRow[], totalCount = rows.length) {
      const data: PageResponseWire = {
        page: HilosPages.LOGS,
        payload: { windows: { hilosLogNodes: windowData(rows, totalCount) } },
      }
      emitter.emit('projectSignal', {
        kind: 'project',
        type: 'page_response',
        data,
        envelope: { type: 'page_response', data },
      })
    },
    updateWindow(rows: TableRow[]) {
      const data = {
        page: HilosPages.LOGS,
        tableKey: 'hilosLogNodes',
        ...windowData(rows),
      }
      emitter.emit('tableWindow', {
        kind: 'tableWindow',
        data,
        envelope: { type: 'table_window', data },
      })
    },
  }
}

describe('resolveHilosLogNodeRow', () => {
  it('reads all seven fields from the inline node slot without an entity id', () => {
    const row = nodeRow({ nodeId: 'node-2' })

    expect(row.slots.node).not.toHaveProperty('id')
    expect(resolveHilosLogNodeRow(row)).toEqual({
      nodeId: 'node-2',
      available: true,
      lastRotationAt: 1800000000000,
      liveBytes: 1024,
      archiveBytes: 4096,
      growthBytesPerDay: 512,
      batchesDueForTakeout: 0,
    })
  })

  it('preserves unknown measurements and measured zeros as different answers', () => {
    expect(
      resolveHilosLogNodeRow(
        nodeRow({
          lastRotationAt: null,
          liveBytes: 0,
          archiveBytes: 0,
          growthBytesPerDay: null,
        }),
      ),
    ).toEqual(
      node({
        lastRotationAt: null,
        liveBytes: 0,
        archiveBytes: 0,
        growthBytesPerDay: null,
      }),
    )
  })

  it.each([undefined, null, [], 'node-1'])(
    'leaves a missing or malformed slot unknown: %j',
    (slot) => {
      expect(
        resolveHilosLogNodeRow({ rowKey: 'node-1', slots: { node: slot } }),
      ).toEqual({
        nodeId: '',
        available: false,
        lastRotationAt: null,
        liveBytes: null,
        archiveBytes: null,
        growthBytesPerDay: null,
        batchesDueForTakeout: null,
      })
    },
  )
})

describe('createHilosLogNodesTable', () => {
  it('declares the shared frame, six columns and cards without extra controls', () => {
    const { table } = setup()
    const frame = table.controller.frame

    expect(frame.declaration?.title).toBe('By node')
    expect(frame.declaration?.subtitle).toBe(
      'A journal is written on the node the event happened on and stays there. The tiles above add every node together; here you can see whose journal is growing faster.',
    )
    expect(frame.declaration?.search).toBeUndefined()
    expect(frame.declaration?.filters).toBeUndefined()
    expect(frame.declaration?.mainAction).toBeUndefined()
    expect(frame.declaration?.bulkActions).toBeUndefined()
    expect(frame.filters.get()).toEqual([])
    const columns = frame.columns.get()
    expect(columns.map((column) => column.key)).toEqual([
      'nodeId',
      'lastRotationAt',
      'liveBytes',
      'archiveBytes',
      'growthBytesPerDay',
      'batchesDueForTakeout',
    ])
    expect(columns.map((column) => column.label)).toEqual([
      'Node',
      'Last rotation',
      'Live',
      'Archive',
      'Per day',
      'To take out',
    ])
    expect(columns.map((column) => column.card)).toEqual([
      'title',
      'field',
      'field',
      'field',
      'field',
      'field',
    ])
    expect(columns[1].reads).toEqual(['available'])
    expect(columns.every((column) => !column.sortable)).toBe(true)
    for (const column of columns.slice(2)) {
      expect(column.headerClass).toBe('text-end')
      expect(column.cellClass).toBe('text-end')
    }
  })

  it('takes the first window from the page scope and declares available without another fetch', () => {
    const test = setup()
    const controller = test.table.controller
    expect(controller.descriptor()).toBeNull()
    test.table.start()
    expect(test.registered.get('hilosLogNodes')).toBe(controller)
    expect(test.sendTableViewport).not.toHaveBeenCalled()
    expect(test.sendTableRendered).not.toHaveBeenCalled()

    test.pageWindow([nodeRow(), nodeRow({ nodeId: 'node-2' })])

    expect(controller.rows.get().map((view) => view.row)).toEqual([
      node(),
      node({ nodeId: 'node-2' }),
    ])
    expect(controller.descriptor()).toMatchObject({
      sort: [{ field: 'nodeId', direction: 'asc' }],
      limit: 25,
    })
    expect(test.sendTableRendered).toHaveBeenCalledExactlyOnceWith(
      HilosPages.LOGS,
      'hilosLogNodes',
      [
        'nodeId',
        'lastRotationAt',
        'available',
        'liveBytes',
        'archiveBytes',
        'growthBytesPerDay',
        'batchesDueForTakeout',
      ],
    )
    expect(test.sendTableViewport).not.toHaveBeenCalled()
    expect(test.sendTableFacets).not.toHaveBeenCalled()
    expect(controller.frame.footer.get()).toEqual({
      firstRow: 1,
      lastRow: 2,
      totalCount: 2,
      totalExact: true,
      page: 0,
      pageCount: 1,
      hasPreviousPage: false,
      hasNextPage: false,
      paginated: false,
    })
    test.table.dispose()
  })

  it('updates measurements and the merged row message when availability alone changes', () => {
    const test = setup()
    test.table.start()
    const unreadable = {
      available: false,
      lastRotationAt: null,
      liveBytes: null,
      archiveBytes: null,
      growthBytesPerDay: null,
      batchesDueForTakeout: null,
    }
    test.pageWindow([nodeRow(unreadable)])
    const view = () => test.table.controller.rows.get()[0]
    expect(view().row).toEqual(node(unreadable))
    expect(view().rowMessage).toEqual({
      fromColumn: 'lastRotationAt',
      text: 'No data — this node could not read its log store',
    })
    expect(
      hilosTableRowMessageCells(
        test.table.controller.frame.columns.get(),
        view().rowMessage,
      ),
    ).toEqual([
      { key: 'nodeId', span: 1, text: null },
      {
        key: 'lastRotationAt',
        span: 5,
        text: 'No data — this node could not read its log store',
      },
    ])

    test.updateWindow([nodeRow({ ...unreadable, available: true })])
    expect(view().rowMessage).toBeNull()
    test.updateWindow([
      nodeRow({ liveBytes: 2048, lastRotationAt: 1800000001000 }),
    ])
    expect(view().row).toEqual(
      node({ liveBytes: 2048, lastRotationAt: 1800000001000 }),
    )
    expect(test.sendTableViewport).not.toHaveBeenCalled()
    test.table.dispose()
  })

  it('sends later viewport changes to the overview table address', () => {
    const test = setup()
    test.table.start()
    test.pageWindow([nodeRow()], 30)
    test.table.controller.nextPage()
    expect(test.sendTableViewport).toHaveBeenCalledWith(
      HilosPages.LOGS,
      'hilosLogNodes',
      expect.objectContaining({
        anchor: { nodeId: 'node-1' },
        anchorDirection: 'after',
      }),
    )
    test.table.dispose()
  })

  it('drops frames for another page or table, and frames after navigation or disposal', () => {
    const test = setup()
    test.table.start()
    const data = {
      page: HilosPages.LOGS,
      tableKey: 'otherTable',
      ...windowData([nodeRow()]),
    }
    test.emitter.emit('tableWindow', {
      kind: 'tableWindow',
      data,
      envelope: { type: 'table_window', data },
    })
    test.emitter.emit('tableWindow', {
      kind: 'tableWindow',
      data: {
        ...data,
        page: HilosPages.LOGS_WORKERS,
        tableKey: 'hilosLogNodes',
      },
      envelope: { type: 'table_window', data },
    })
    expect(test.table.controller.rows.get()).toEqual([])
    test.scopes.openPage(HilosPages.LOGS_WORKERS)
    test.pageWindow([nodeRow()])
    test.updateWindow([nodeRow()])
    expect(test.table.controller.rows.get()).toEqual([])
    test.scopes.openPage(HilosPages.LOGS)
    test.table.dispose()
    expect(test.listenerCount()).toBe(0)
    expect(test.registered.size).toBe(0)
    test.pageWindow([nodeRow()])
    test.updateWindow([nodeRow()])
    expect(test.table.controller.rows.get()).toEqual([])
    test.table.dispose()
    expect(test.listenerCount()).toBe(0)
  })
})

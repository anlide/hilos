import { readFileSync } from 'node:fs'
import { describe, expect, it, vi } from 'vitest'
import {
  createHilosMaintenanceNodesTable,
  resolveHilosMaintenanceNodeRow,
  hilosMaintenanceNodePresentation,
  hilosMaintenanceNodeRowMessage,
  HILOS_MAINTENANCE_NODES_TABLE,
  HILOS_MAINTENANCE_NODES_SLOT,
  HILOS_MAINTENANCE_NODES_COPY,
  HILOS_MAINTENANCE_NODES_COLUMNS,
  MAINTENANCE_NODE_ID_FIELD,
  MAINTENANCE_NODE_PHASE_FIELD,
  MAINTENANCE_NODE_QUIESCED_FIELD,
  MAINTENANCE_NODE_IS_LEADER_FIELD,
  MAINTENANCE_NODE_IS_INITIATOR_FIELD,
  MAINTENANCE_NODE_REPORT_STATE_FIELD,
  type HilosMaintenanceNodeRow,
} from '../../../src/admin/maintenance/index.js'
import { Emitter } from '../../../src/connection/events.js'
import {
  type HilosConnection,
  type HilosConnectionEventMap,
  type TableWindowDescriptorSource,
} from '../../../src/connection/HilosConnection.js'
import { type PageResponseWire } from '../../../src/protocol/scopePayload.js'
import {
  HilosPages,
  type HilosPageKey,
} from '../../../src/routing/hilosPages.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'
import { hilosTableRowMessageCells } from '../../../src/table/tableRowMessage.js'

function node(
  overrides: Partial<HilosMaintenanceNodeRow> = {},
): HilosMaintenanceNodeRow {
  return {
    nodeId: 'node-1',
    phase: 'inactive',
    quiesced: null,
    isLeader: false,
    isInitiator: false,
    reportState: 'current',
    ...overrides,
  }
}

function nodeRow(overrides: Partial<HilosMaintenanceNodeRow> = {}): TableRow {
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

/** Only transport is stubbed; rows go through the real binder, stores and controller. */
function setup(page: HilosPageKey = HilosPages.MAINTENANCE_NODES) {
  const emitter = new Emitter<HilosConnectionEventMap>()
  const registered = new Map<string, TableWindowDescriptorSource>()
  const sendTableViewport = vi.fn(() => true)
  const sendTableRendered = vi.fn(() => true)
  const sendTableFacets = vi.fn(() => true)
  const sendAction = vi.fn(() => true)
  let listeners = 0
  const connection = {
    on<K extends keyof HilosConnectionEventMap>(
      event: K,
      listener: (payload: HilosConnectionEventMap[K]) => void,
    ): () => void {
      const off = emitter.on(event, listener)
      listeners += 1
      return () => {
        listeners -= 1
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
      if (registered.get(tableKey) === source) registered.delete(tableKey)
    },
    sendTableViewport,
    sendTableRendered,
    sendTableFacets,
    sendAction,
  } as unknown as HilosConnection
  const scopes = new ScopeManager()
  scopes.openPage(page)
  const table = createHilosMaintenanceNodesTable({ connection, scopes }, page)

  return {
    table,
    scopes,
    emitter,
    registered,
    sendTableViewport,
    sendTableRendered,
    sendTableFacets,
    sendAction,
    listenerCount: () => listeners,
    pageWindow(rows: TableRow[], totalCount = rows.length) {
      const data: PageResponseWire = {
        page,
        payload: {
          windows: { hilosMaintenanceNodes: windowData(rows, totalCount) },
        },
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
        page,
        tableKey: 'hilosMaintenanceNodes',
        ...windowData(rows),
      }
      emitter.emit('tableWindow', {
        kind: 'tableWindow',
        data,
        envelope: { type: 'table_window', data },
      })
    },
    updateRow(overrides: Partial<HilosMaintenanceNodeRow>) {
      const row = nodeRow(overrides)
      const data = {
        page,
        tableKey: 'hilosMaintenanceNodes',
        kind: 'row_updated',
        rowKey: row.rowKey,
        row: { rowKey: row.rowKey, slots: row.slots },
      }
      emitter.emit('tableViewportDelta', {
        kind: 'tableViewportDelta',
        data,
        envelope: { type: 'table_viewport_delta', data },
      })
    },
  }
}

describe('maintenance Nodes row contract', () => {
  it('keeps exactly the six backend fields in the node slot with nodeId as row key', () => {
    const fields = [
      'nodeId',
      'phase',
      'quiesced',
      'isLeader',
      'isInitiator',
      'reportState',
    ]
    const php = readFileSync(
      new URL(
        '../../../../../backend/Tables/ProtectedMode/HilosMaintenanceNodesTableRow.php',
        import.meta.url,
      ),
      'utf8',
    )
    expect(
      [...php.matchAll(/public const string \w+ = '([^']+)';/g)].map(
        (match) => match[1],
      ),
    ).toEqual(fields)
    expect([
      MAINTENANCE_NODE_ID_FIELD,
      MAINTENANCE_NODE_PHASE_FIELD,
      MAINTENANCE_NODE_QUIESCED_FIELD,
      MAINTENANCE_NODE_IS_LEADER_FIELD,
      MAINTENANCE_NODE_IS_INITIATOR_FIELD,
      MAINTENANCE_NODE_REPORT_STATE_FIELD,
    ]).toEqual(fields)
    expect(HILOS_MAINTENANCE_NODES_TABLE).toBe('hilosMaintenanceNodes')
    expect(HILOS_MAINTENANCE_NODES_SLOT).toBe('node')
    const raw = nodeRow({
      phase: 'verifying',
      quiesced: true,
      isLeader: true,
      isInitiator: true,
    })
    expect(raw.rowKey).toBe('node-1')
    expect(Object.keys(raw.slots.node as object)).toEqual(fields)
    expect(resolveHilosMaintenanceNodeRow(raw)).toEqual({
      nodeId: 'node-1',
      phase: 'verifying',
      quiesced: true,
      isLeader: true,
      isInitiator: true,
      reportState: 'current',
    })
    expect(Object.keys(resolveHilosMaintenanceNodeRow(raw))).toEqual(fields)
  })

  it.each([null, false, true])(
    'preserves quiesced %s and null phase',
    (quiesced) => {
      expect(
        resolveHilosMaintenanceNodeRow(nodeRow({ phase: null, quiesced })),
      ).toEqual(node({ phase: null, quiesced }))
    },
  )

  it.each([
    undefined,
    null,
    [],
    'node-1',
    { phase: 'unknown', quiesced: 'false' },
  ])('keeps malformed or absent reports unknown: %j', (slot) => {
    expect(
      resolveHilosMaintenanceNodeRow({
        rowKey: 'node-2',
        slots: { node: slot },
      }),
    ).toEqual({
      nodeId: 'node-2',
      phase: null,
      quiesced: null,
      isLeader: false,
      isInitiator: false,
      reportState: 'missing',
    })
  })
})

describe('maintenance Nodes presentation', () => {
  it.each([
    [true, 'Yes'],
    [false, 'No'],
    [null, '—'],
  ] as const)('gives quiesced %s a visible word %s', (quiesced, text) => {
    expect(
      hilosMaintenanceNodePresentation(node({ quiesced })).quiesced.text,
    ).toBe(text)
  })

  it.each([
    'inactive',
    'activating',
    'active',
    'verifying',
    'deactivating',
  ] as const)('presents the reported %s phase as a badge', (phase) => {
    const row = resolveHilosMaintenanceNodeRow(nodeRow({ phase }))
    expect(hilosMaintenanceNodePresentation(row).phase).toEqual({
      text: phase,
      badge: true,
    })
  })

  it('leaves an unknown phase unbadged and both unknown values as dashes', () => {
    const view = hilosMaintenanceNodePresentation(node({ phase: null }))
    expect(view.phase).toEqual({ text: '—', badge: false })
    expect(view.quiesced).toEqual({ text: '—', tone: null })
  })

  it.each([
    [false, false, '—'],
    [true, false, 'Leader'],
    [false, true, 'Initiator here'],
    [true, true, 'Leader · Initiator here'],
  ] as const)(
    'composes leader=%s and initiator=%s into %s',
    (isLeader, isInitiator, role) => {
      expect(
        hilosMaintenanceNodePresentation(node({ isLeader, isInitiator })).role,
      ).toBe(role)
    },
  )

  it.each([
    ['current', null],
    ['missing', 'No maintenance report received on the current connection.'],
    ['silent', 'Node disconnected — no current maintenance report.'],
  ] as const)(
    'explains %s through the standard row message',
    (reportState, text) => {
      expect(hilosMaintenanceNodeRowMessage(node({ reportState }))).toEqual(
        text === null ? null : { fromColumn: 'isLeader', text },
      )
    },
  )

  it.each(['missing', 'silent'] as const)(
    'preserves independent acknowledgement, phase and role facts for %s reports',
    (reportState) => {
      for (const [quiesced, isLeader, isInitiator, acknowledgement, role] of [
        [false, false, true, 'No', 'Initiator here'],
        [false, true, false, 'No', 'Leader'],
        [true, true, true, 'Yes', 'Leader · Initiator here'],
        [null, false, false, '—', null],
      ] as const) {
        const row = node({
          phase: null,
          quiesced,
          isLeader,
          isInitiator,
          reportState,
        })
        const text = role
          ? `${role} · ${HILOS_MAINTENANCE_NODES_COPY[reportState]}`
          : HILOS_MAINTENANCE_NODES_COPY[reportState]
        const message = hilosMaintenanceNodeRowMessage(row)
        expect(message).toEqual({ fromColumn: 'isLeader', text })
        expect(
          hilosTableRowMessageCells(HILOS_MAINTENANCE_NODES_COLUMNS, message),
        ).toEqual([
          { key: 'nodeId', span: 1, text: null },
          { key: 'quiesced', span: 1, text: null },
          { key: 'phase', span: 1, text: null },
          { key: 'isLeader', span: 1, text },
        ])
        expect(hilosMaintenanceNodePresentation(row).quiesced.text).toBe(
          acknowledgement,
        )
        expect(hilosMaintenanceNodePresentation(row).phase.text).toBe('—')
        expect(hilosMaintenanceNodePresentation(row).role).toBe(role ?? '—')
      }
    },
  )
})

describe('createHilosMaintenanceNodesTable', () => {
  it('declares four columns with only Node sortable and no actions, search or filters', () => {
    const { table } = setup()
    const frame = table.controller.frame
    expect(frame.declaration?.title).toBe(HILOS_MAINTENANCE_NODES_COPY.title)
    expect(frame.declaration?.empty).toEqual({
      title: HILOS_MAINTENANCE_NODES_COPY.empty,
    })
    expect(frame.declaration?.search).toBeUndefined()
    expect(frame.declaration?.filters).toBeUndefined()
    expect(frame.declaration?.mainAction).toBeUndefined()
    expect(frame.declaration?.bulkActions).toBeUndefined()
    expect(frame.filters.get()).toEqual([])
    const columns = frame.columns.get()
    expect(columns).toEqual(HILOS_MAINTENANCE_NODES_COLUMNS)
    expect(columns.map((column) => column.key)).toEqual([
      'nodeId',
      'quiesced',
      'phase',
      'isLeader',
    ])
    expect(columns.map((column) => column.label)).toEqual([
      'Node',
      'Quiesced',
      'Phase on node',
      'Role',
    ])
    expect(columns.map((column) => column.sortable === true)).toEqual([
      true,
      false,
      false,
      false,
    ])
    expect(columns.map((column) => column.card)).toEqual([
      'title',
      'field',
      'badge',
      'field',
    ])
    expect(columns[1].reads).toBeUndefined()
    expect(columns[3].reads).toEqual(['isInitiator', 'reportState'])
  })

  it('takes nodeId ASC and the 25-row first window from page_response without a mount fetch', () => {
    const test = setup()
    const controller = test.table.controller
    expect(controller.descriptor()).toBeNull()
    test.table.start()
    expect(test.registered.get('hilosMaintenanceNodes')).toBe(controller)
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
      HilosPages.MAINTENANCE_NODES,
      'hilosMaintenanceNodes',
      ['nodeId', 'quiesced', 'phase', 'isLeader', 'isInitiator', 'reportState'],
    )
    expect(test.sendTableViewport).not.toHaveBeenCalled()
    expect(test.sendTableFacets).not.toHaveBeenCalled()
    expect(test.sendAction).not.toHaveBeenCalled()
    expect(controller.frame.footer.get()).toMatchObject({
      firstRow: 1,
      lastRow: 2,
      totalCount: 2,
      totalExact: true,
      page: 0,
      pageCount: 1,
    })
    test.table.dispose()
  })

  it('updates reported values, roles and missing/silent messages through standard live rows and windows', () => {
    const test = setup()
    test.table.start()
    test.pageWindow([
      nodeRow({ phase: 'verifying', quiesced: true, isLeader: true }),
    ])
    const view = () => test.table.controller.rows.get()[0]
    expect(view().rowMessage).toBeNull()
    test.updateRow({ phase: 'activating', quiesced: false, isInitiator: true })
    expect(view().row).toEqual(
      node({ phase: 'activating', quiesced: false, isInitiator: true }),
    )
    expect(hilosMaintenanceNodePresentation(view().row!).role).toBe(
      'Initiator here',
    )
    const originalRowKey = view().rowKey
    for (const [reportState, quiesced, isLeader, acknowledgement, role] of [
      ['missing', false, false, 'No', 'Initiator here'],
      ['silent', true, true, 'Yes', 'Leader · Initiator here'],
    ] as const) {
      test.updateRow({
        phase: null,
        quiesced,
        isLeader,
        isInitiator: true,
        reportState,
      })
      const text = `${role} · ${HILOS_MAINTENANCE_NODES_COPY[reportState]}`
      expect(view().rowKey).toBe(originalRowKey)
      expect(view().row?.phase).toBeNull()
      expect(view().row?.quiesced).toBe(quiesced)
      expect(hilosMaintenanceNodePresentation(view().row!).quiesced.text).toBe(
        acknowledgement,
      )
      expect(hilosMaintenanceNodePresentation(view().row!).phase.text).toBe('—')
      expect(view().rowMessage).toEqual({
        fromColumn: 'isLeader',
        text,
      })
      expect(
        hilosTableRowMessageCells(
          test.table.controller.frame.columns.get(),
          view().rowMessage,
        ),
      ).toEqual([
        { key: 'nodeId', span: 1, text: null },
        { key: 'quiesced', span: 1, text: null },
        { key: 'phase', span: 1, text: null },
        { key: 'isLeader', span: 1, text },
      ])
    }
    test.updateWindow([
      nodeRow({
        phase: 'active',
        quiesced: true,
        isLeader: true,
        isInitiator: true,
      }),
    ])
    expect(view().rowKey).toBe(originalRowKey)
    expect(view().rowMessage).toBeNull()
    expect(view().row).toEqual(
      node({
        phase: 'active',
        quiesced: true,
        isLeader: true,
        isInitiator: true,
      }),
    )
    expect(hilosMaintenanceNodePresentation(view().row!).role).toBe(
      'Leader · Initiator here',
    )
    expect(test.sendTableViewport).not.toHaveBeenCalled()
    test.table.dispose()
  })

  it('sends an ordinary viewport only when the reader asks for the next window', () => {
    const test = setup()
    test.table.start()
    test.pageWindow([nodeRow()], 30)
    test.table.controller.nextPage()
    expect(test.sendTableViewport).toHaveBeenCalledExactlyOnceWith(
      HilosPages.MAINTENANCE_NODES,
      'hilosMaintenanceNodes',
      expect.objectContaining({
        anchor: { nodeId: 'node-1' },
        anchorDirection: 'after',
        limit: 25,
        sort: [{ field: 'nodeId', direction: 'asc' }],
      }),
    )
    test.table.dispose()
  })

  it('binds a custom page and removes listeners and held-window registration on disposal', () => {
    const test = setup(HilosPages.MAINTENANCE)
    test.table.start()
    expect(test.listenerCount()).toBeGreaterThan(0)
    test.pageWindow([nodeRow()])
    expect(test.table.controller.rows.get()[0].row).toEqual(node())
    expect(test.sendTableRendered).toHaveBeenCalledWith(
      HilosPages.MAINTENANCE,
      'hilosMaintenanceNodes',
      expect.any(Array),
    )
    test.table.dispose()
    expect(test.listenerCount()).toBe(0)
    expect(test.registered.size).toBe(0)
    test.updateRow({ phase: 'active' })
    test.updateWindow([nodeRow({ phase: 'active' })])
    test.pageWindow([nodeRow({ phase: 'active' })])
    expect(test.table.controller.rows.get()[0].row?.phase).toBe('inactive')
    test.table.dispose()
    expect(test.listenerCount()).toBe(0)
  })
})

import { describe, expect, it } from 'vitest'

import {
  createHilosLogWorkersTable,
  formatLogWorkerState,
  formatLogWorkerType,
  formatLogWorkerWeight,
  hasLogWorkerNodes,
  logWorkerViewerPath,
  logWorkersEmptyState,
  logWorkersSearchPlaceholder,
  resolveHilosLogWorkerRow,
  HILOS_LOG_WORKER_TYPE_MONOPOLISTIC,
  HILOS_LOG_WORKER_TYPE_REGULAR,
  WORKERS_HEADER_SIGNAL,
  WORKER_FILTER_NODE,
  WORKER_FILTER_TYPE,
  WORKER_NAME_FIELD,
  WORKER_NODE_FIELD,
  WORKER_LIVE_FIELD,
  WORKER_BATCH_COUNT_FIELD,
  WORKER_BYTES_FIELD,
  LOGS_WORKERS_SIGNAL_SCHEMAS,
  type HilosLogWorkerRow,
  type HilosLogWorkersContext,
  type HilosLogWorkersHeader,
} from '../../../src/admin/logs/hilosLogWorkers.js'
import {
  type HilosConnection,
  type TableViewportDescriptor,
} from '../../../src/connection/HilosConnection.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { createSignal, type ReadonlySignal } from '../../../src/state/signal.js'
import { type ScopeManager } from '../../../src/state/ScopeManager.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'
import { HILOS_TABLE_ACTIONS_KEY } from '../../../src/table/hilosTableColumn.js'

function row(overrides: Partial<HilosLogWorkerRow> = {}): HilosLogWorkerRow {
  return {
    rowKey: 'node-1:worker-0.log',
    key: 'worker-0.log',
    node: 'node-1',
    type: HILOS_LOG_WORKER_TYPE_REGULAR,
    live: true,
    batchCount: 12,
    lastBatchAt: 1800000000,
    bytes: 1024,
    ...overrides,
  }
}

function header(
  overrides: Partial<HilosLogWorkersHeader> = {},
): HilosLogWorkersHeader {
  return {
    available: true,
    nodes: [],
    ...overrides,
  }
}

function workerTableRow(
  rowKey: string,
  slot: Record<string, unknown> | undefined,
): TableRow {
  return { rowKey, slots: slot === undefined ? {} : { stream: slot } }
}

/** A table bound to a connection that records the descriptors instead of sending them. */
function tableOnAStubConnection(
  headerSignal: ReadonlySignal<HilosLogWorkersHeader | null> = createSignal(
    header(),
  ),
  initialFilter?: Record<string, unknown>,
): {
  table: ReturnType<typeof createHilosLogWorkersTable>
  sent: Array<{
    page: string
    tableKey: string
    descriptor: TableViewportDescriptor
  }>
  sentRendered: Array<{
    page: string
    tableKey: string
    rendered: readonly string[]
  }>
  sentFacets: Array<{
    page: string
    tableKey: string
    facets: Readonly<Record<string, readonly unknown[]>>
  }>
} {
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
  const sentFacets: Array<{
    page: string
    tableKey: string
    facets: Readonly<Record<string, readonly unknown[]>>
  }> = []
  const context: HilosLogWorkersContext = {
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
      sendTableFacets(
        page: string,
        tableKey: string,
        facets: Readonly<Record<string, readonly unknown[]>>,
      ): boolean {
        sentFacets.push({ page, tableKey, facets })

        return true
      },
    } as unknown as HilosConnection,
    scopes: {} as unknown as ScopeManager,
  }

  return {
    table: createHilosLogWorkersTable(context, headerSignal, initialFilter),
    sent,
    sentRendered,
    sentFacets,
  }
}

describe('resolveHilosLogWorkerRow', () => {
  it('reads the stream slot into the view-model', () => {
    const resolved = resolveHilosLogWorkerRow(
      workerTableRow('node-2:worker-monopolistic-truth.log', {
        key: 'worker-monopolistic-truth.log',
        node: 'node-2',
        type: HILOS_LOG_WORKER_TYPE_MONOPOLISTIC,
        live: true,
        batchCount: 3,
        lastBatchAt: 1799999000,
        bytes: 4096,
      }),
    )

    expect(resolved).toEqual({
      rowKey: 'node-2:worker-monopolistic-truth.log',
      key: 'worker-monopolistic-truth.log',
      node: 'node-2',
      type: HILOS_LOG_WORKER_TYPE_MONOPOLISTIC,
      live: true,
      batchCount: 3,
      lastBatchAt: 1799999000,
      bytes: 4096,
    })
  })

  it('keeps the effective standalone node id', () => {
    const resolved = resolveHilosLogWorkerRow(
      workerTableRow('standalone:worker-0.log', {
        node: 'standalone',
        key: 'worker-0.log',
        bytes: 10,
      }),
    )

    expect(resolved.node).toBe('standalone')
  })

  it('takes the identity from the row key, never from inside the slot', () => {
    const resolved = resolveHilosLogWorkerRow(
      workerTableRow('node-1:worker-0.log', undefined),
    )

    expect(resolved.rowKey).toBe('node-1:worker-0.log')
    expect(resolved.key).toBe('')
  })
})

describe('the worker table descriptor', () => {
  it('asks for nothing on its own — its first window arrives with the page', () => {
    const { table, sent } = tableOnAStubConnection()

    // What the screen opens on — the heaviest stream first, twenty-five of them — is declared
    // by HilosLogWorkersTable on the backend since HIL-642, and travels on the window itself.
    // This side declares neither, so it has nothing to ask for until the reader asks.
    expect(sent).toEqual([])
    expect(table.controller.descriptor()).toBeNull()
  })

  it('sends the type filter to the server rather than narrowing the window here', () => {
    const { table, sent } = tableOnAStubConnection()

    table.controller.setFilter(
      WORKER_FILTER_TYPE,
      HILOS_LOG_WORKER_TYPE_MONOPOLISTIC,
    )

    expect(sent.at(-1)?.descriptor.filter).toEqual({
      [WORKER_FILTER_TYPE]: HILOS_LOG_WORKER_TYPE_MONOPOLISTIC,
    })
  })

  it('sends a chosen ordering to the server too', () => {
    const { table, sent } = tableOnAStubConnection()

    table.controller.setSort('key')

    expect(sent.at(-1)?.descriptor.sort).toEqual([
      { field: 'key', direction: 'asc' },
    ])
  })
})

describe('the worker table frame', () => {
  it('declares its title, copy, empty state policy, and single-node columns', () => {
    const { table } = tableOnAStubConnection(
      createSignal(header({ nodes: [] })),
    )
    const frame = table.controller.frame

    expect(frame.declaration?.title).toBe('Worker streams')
    expect(frame.declaration?.filteredEmpty).toBe('page')
    expect(frame.declaration?.empty).toBeUndefined()
    expect(frame.searchPlaceholder.get()).toBe('Search by key…')

    const columns = frame.columns.get()
    expect(columns.map((c) => c.key)).toEqual([
      WORKER_NAME_FIELD,
      'type',
      WORKER_LIVE_FIELD,
      WORKER_BATCH_COUNT_FIELD,
      WORKER_BYTES_FIELD,
      HILOS_TABLE_ACTIONS_KEY,
    ])
    expect(columns[0]?.card).toBe('title')
    expect(columns[1]?.card).toBe('badge')

    const filters = frame.filters.get()
    expect(filters).toHaveLength(1)
    expect(filters[0]?.filter).toEqual({
      key: WORKER_FILTER_TYPE,
      kind: 'toggle',
      label: 'Monopolistic only',
      on: HILOS_LOG_WORKER_TYPE_MONOPOLISTIC,
    })
  })

  it('manages the type toggle filter', () => {
    const { table, sent } = tableOnAStubConnection()

    table.controller.setFilter(
      WORKER_FILTER_TYPE,
      HILOS_LOG_WORKER_TYPE_MONOPOLISTIC,
    )
    expect(sent.at(-1)?.descriptor.filter).toEqual({
      [WORKER_FILTER_TYPE]: HILOS_LOG_WORKER_TYPE_MONOPOLISTIC,
    })

    table.controller.setFilter(WORKER_FILTER_TYPE, '')
    expect(sent.at(-1)?.descriptor.filter).toEqual({})
  })

  it('adapts columns, filters, and search placeholder to a late cluster header', () => {
    const headerSignal = createSignal<HilosLogWorkersHeader | null>(null)
    const { table } = tableOnAStubConnection(headerSignal)

    expect(table.controller.frame.searchPlaceholder.get()).toBe(
      'Search by key…',
    )
    expect(
      table.controller.frame.columns.get().map((c) => c.key),
    ).not.toContain(WORKER_NODE_FIELD)
    expect(table.controller.frame.filters.get()).toHaveLength(1)

    headerSignal.set(header({ nodes: ['node-1', 'node-2'] }))

    expect(table.controller.frame.searchPlaceholder.get()).toBe(
      'Search by key or node…',
    )
    const colKeys = table.controller.frame.columns.get().map((c) => c.key)
    expect(colKeys).toEqual([
      WORKER_NAME_FIELD,
      WORKER_NODE_FIELD,
      'type',
      WORKER_LIVE_FIELD,
      WORKER_BATCH_COUNT_FIELD,
      WORKER_BYTES_FIELD,
      HILOS_TABLE_ACTIONS_KEY,
    ])

    const filters = table.controller.frame.filters.get()
    expect(filters).toHaveLength(2)
    const nodeFilter = filters[0]?.filter
    expect(nodeFilter?.kind).toBe('select')
    if (nodeFilter?.kind === 'select') {
      expect(nodeFilter.key).toBe(WORKER_FILTER_NODE)
      expect(nodeFilter.options()).toEqual([
        { value: 'node-1', label: 'node-1' },
        { value: 'node-2', label: 'node-2' },
      ])
    }
  })

  it('derives card layout and rendered keys including action reads', () => {
    const headerSignal = createSignal(header({ nodes: ['node-1'] }))
    const { table } = tableOnAStubConnection(headerSignal)

    const card = table.controller.frame.card.get()
    expect(card?.title?.key).toBe(WORKER_NAME_FIELD)
    expect(card?.badge?.key).toBe('type')
    expect(card?.fields.map((f) => f.key)).toEqual([
      WORKER_NODE_FIELD,
      WORKER_LIVE_FIELD,
      WORKER_BATCH_COUNT_FIELD,
      WORKER_BYTES_FIELD,
    ])
    expect(card?.actions?.key).toBe(HILOS_TABLE_ACTIONS_KEY)

    const actionCol = table.controller.frame.columns
      .get()
      .find((c) => c.key === HILOS_TABLE_ACTIONS_KEY)
    expect(actionCol?.reads).toEqual([
      WORKER_NAME_FIELD,
      WORKER_NODE_FIELD,
      WORKER_LIVE_FIELD,
      'lastBatchAt',
    ])

    // Node is rendered even if single-node because actions.reads requires node
    const singleNode = tableOnAStubConnection(
      createSignal(header({ nodes: [] })),
    )
    singleNode.table.controller.ingestSubscriptionWindow(
      [],
      0,
      true,
      null,
      null,
      25,
      undefined,
      [],
    )
    const singleNodeRendered = singleNode.sentRendered[0]?.rendered ?? []
    expect(singleNodeRendered).toContain(WORKER_NODE_FIELD)
    expect(singleNodeRendered).toContain('lastBatchAt')
  })

  it('declares rendered keys and facet options once the first window lands', () => {
    const headerSignal = createSignal(header({ nodes: ['node-1', 'node-2'] }))
    const { table, sentRendered, sentFacets } =
      tableOnAStubConnection(headerSignal)

    expect(sentRendered).toEqual([])
    expect(sentFacets).toEqual([])

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
    expect(sentRendered[0]?.page).toBe(HilosPages.LOGS_WORKERS)
    expect(sentRendered[0]?.tableKey).toBe('hilosLogWorkers')
    expect(sentRendered[0]?.rendered).toEqual(
      table.controller.descriptor()?.rendered,
    )

    expect(sentFacets).toHaveLength(1)
    expect(sentFacets[0]?.page).toBe(HilosPages.LOGS_WORKERS)
    expect(sentFacets[0]?.tableKey).toBe('hilosLogWorkers')
    expect(sentFacets[0]?.facets).toEqual({
      [WORKER_FILTER_NODE]: ['node-1', 'node-2'],
    })
  })
})

describe('the header schema', () => {
  it('is registered under the page signal the backend sends it as', () => {
    expect(Object.keys(LOGS_WORKERS_SIGNAL_SCHEMAS)).toEqual([
      WORKERS_HEADER_SIGNAL,
    ])
  })

  it('accepts the third availability state, which is a null and not a false', () => {
    const parsed = LOGS_WORKERS_SIGNAL_SCHEMAS[WORKERS_HEADER_SIGNAL].safeParse(
      {
        ...header(),
        available: null,
      },
    )

    expect(parsed.success).toBe(true)
    expect(parsed.success && parsed.data.available).toBeNull()
  })

  it('refuses a payload whose node list is not a list of names', () => {
    const parsed = LOGS_WORKERS_SIGNAL_SCHEMAS[WORKERS_HEADER_SIGNAL].safeParse(
      {
        ...header(),
        nodes: 'node-1',
      },
    )

    expect(parsed.success).toBe(false)
  })

  it('refuses a payload with no availability at all, which is not the same as an unknown one', () => {
    const withoutAvailable: Record<string, unknown> = { ...header() }
    delete withoutAvailable.available
    const parsed =
      LOGS_WORKERS_SIGNAL_SCHEMAS[WORKERS_HEADER_SIGNAL].safeParse(
        withoutAvailable,
      )

    expect(parsed.success).toBe(false)
  })
})

describe('hasLogWorkerNodes', () => {
  it('is false for a single-node installation, which names no node at all', () => {
    expect(hasLogWorkerNodes(header({ nodes: [] }))).toBe(false)
  })

  it('is false before the header arrives, so no column flashes on entry', () => {
    expect(hasLogWorkerNodes(null)).toBe(false)
  })

  it('is true once the picture names nodes', () => {
    expect(hasLogWorkerNodes(header({ nodes: ['node-1', 'node-2'] }))).toBe(
      true,
    )
  })
})

describe('logWorkersSearchPlaceholder', () => {
  it('offers only the key in a single-node installation, which has no node to match', () => {
    expect(logWorkersSearchPlaceholder(header({ nodes: [] }))).toBe(
      'Search by key…',
    )
  })

  it('offers only the key before the header arrives, so no offer is withdrawn', () => {
    expect(logWorkersSearchPlaceholder(null)).toBe('Search by key…')
  })

  it('offers the node once the picture names nodes', () => {
    expect(logWorkersSearchPlaceholder(header({ nodes: ['node-1'] }))).toBe(
      'Search by key or node…',
    )
  })
})

describe('logWorkersEmptyState', () => {
  it('waits rather than reporting a fault before any picture arrives', () => {
    expect(logWorkersEmptyState(null, 0, false)).toBe('unknown')
    expect(logWorkersEmptyState(header({ available: null }), 0, false)).toBe(
      'unknown',
    )
  })

  it('reports the fault when the picture arrived and nothing could be read', () => {
    expect(logWorkersEmptyState(header({ available: false }), 0, false)).toBe(
      'unreadable',
    )
  })

  it('tells an installation with no logs from a filter that matched nothing', () => {
    expect(logWorkersEmptyState(header(), 0, false)).toBe('never')
    expect(logWorkersEmptyState(header(), 0, true)).toBe('nomatch')
  })

  it('says nothing at all when there are rows', () => {
    expect(logWorkersEmptyState(header(), 3, true)).toBe('rows')
  })

  it('lets an unreadable picture outrank an empty window, which it explains', () => {
    expect(logWorkersEmptyState(header({ available: false }), 0, true)).toBe(
      'unreadable',
    )
  })
})

describe('logWorkerViewerPath', () => {
  it('opens a stream that is still written on its live file', () => {
    expect(logWorkerViewerPath(row())).toBe(
      '/hilos/logs/view/node-1/live/worker-0.log',
    )
  })

  it('opens a stream that is only in the archive on its newest batch', () => {
    expect(
      logWorkerViewerPath(row({ live: false, lastBatchAt: 1799999000 })),
    ).toBe('/hilos/logs/view/node-1/1799999000/worker-0.log')
  })

  it('names the standalone node in the viewer address', () => {
    expect(logWorkerViewerPath(row({ node: 'standalone' }))).toBe(
      '/hilos/logs/view/standalone/live/worker-0.log',
    )
  })

  it('has no address for a stream that is neither live nor archived', () => {
    expect(logWorkerViewerPath(row({ live: false, lastBatchAt: null }))).toBe(
      '',
    )
  })
})

describe('formatLogWorkerWeight', () => {
  it('reports a zero-byte stream as a measurement and not as missing data', () => {
    expect(formatLogWorkerWeight(row({ bytes: 0 }))).toBe('0 B')
  })

  it('climbs to the largest unit that leaves a readable number', () => {
    expect(formatLogWorkerWeight(row({ bytes: 1024 }))).toBe('1.0 KB')
    expect(formatLogWorkerWeight(row({ bytes: 1536 * 1024 * 1024 }))).toBe(
      '1.5 GB',
    )
  })
})

describe('formatLogWorkerType and formatLogWorkerState', () => {
  it('labels the two kinds this screen was opened to tell apart', () => {
    expect(formatLogWorkerType(row())).toBe('Ordinary')
    expect(
      formatLogWorkerType(row({ type: HILOS_LOG_WORKER_TYPE_MONOPOLISTIC })),
    ).toBe('Monopolistic')
  })

  it('prints a kind it does not know rather than folding it into one it does', () => {
    expect(formatLogWorkerType(row({ type: 'sidecar' }))).toBe('sidecar')
  })

  it('tells a stream still being written from one left in the archive', () => {
    expect(formatLogWorkerState(row())).toBe('Writing')
    expect(formatLogWorkerState(row({ live: false }))).toBe('Archive only')
  })
})

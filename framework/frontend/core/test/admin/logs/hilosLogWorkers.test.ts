import { describe, expect, it } from 'vitest'

import {
  createHilosLogWorkersTable,
  formatLogWorkerState,
  formatLogWorkerType,
  formatLogWorkerWeight,
  hasLogWorkerNodes,
  logWorkerViewerPath,
  logWorkersEmptyState,
  resolveHilosLogWorkerRow,
  HILOS_LOG_WORKER_TYPE_MONOPOLISTIC,
  HILOS_LOG_WORKER_TYPE_REGULAR,
  WORKERS_HEADER_SIGNAL,
  WORKER_BYTES_FIELD,
  WORKER_FILTER_TYPE,
  LOGS_WORKERS_SIGNAL_SCHEMAS,
  type HilosLogWorkerRow,
  type HilosLogWorkersContext,
  type HilosLogWorkersHeader,
} from '../../../src/admin/logs/hilosLogWorkers.js'
import {
  type HilosConnection,
  type TableViewportDescriptor,
} from '../../../src/connection/HilosConnection.js'
import { type ScopeManager } from '../../../src/state/ScopeManager.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'

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
function tableOnAStubConnection(): {
  table: ReturnType<typeof createHilosLogWorkersTable>
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
  const context: HilosLogWorkersContext = {
    connection: {
      sendTableViewport(
        page: string,
        tableKey: string,
        descriptor: TableViewportDescriptor,
      ): boolean {
        sent.push({ page, tableKey, descriptor })

        return true
      },
    } as unknown as HilosConnection,
    scopes: {} as unknown as ScopeManager,
  }

  return { table: createHilosLogWorkersTable(context), sent }
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

  it('keeps a nameless node null, because that is the single-node installation', () => {
    const resolved = resolveHilosLogWorkerRow(
      workerTableRow('-:worker-0.log', { key: 'worker-0.log', bytes: 10 }),
    )

    expect(resolved.node).toBeNull()
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
  it('opens on the heaviest stream, which is the question the screen is opened with', () => {
    const { table, sent } = tableOnAStubConnection()

    table.controller.start()

    expect(sent).toHaveLength(1)
    expect(sent[0].tableKey).toBe('hilosLogWorkers')
    expect(sent[0].descriptor.sort).toEqual({
      field: WORKER_BYTES_FIELD,
      direction: 'desc',
    })
    expect(sent[0].descriptor.limit).toBe(25)
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

    expect(sent.at(-1)?.descriptor.sort).toEqual({
      field: 'key',
      direction: 'asc',
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

  it('names the single-node installation by the dash segment the viewer reads', () => {
    expect(logWorkerViewerPath(row({ node: null }))).toBe(
      '/hilos/logs/view/-/live/worker-0.log',
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

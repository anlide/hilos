import { describe, expect, it } from 'vitest'

import {
  formatLogKeyClass,
  formatLogKeyGrowth,
  formatLogKeyState,
  formatLogKeyWeight,
  hasLogKeyNodes,
  logKeyViewerPath,
  logKeysEmptyState,
  resolveHilosLogKeyRow,
  HILOS_LOG_CLASS_AGENT,
  HILOS_LOG_CLASS_WORKER,
  KEYS_HEADER_SIGNAL,
  LOGS_KEYS_SIGNAL_SCHEMAS,
  type HilosLogKeyRow,
  type HilosLogKeysHeader,
} from '../../../src/admin/logs/hilosLogKeys.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'

function row(overrides: Partial<HilosLogKeyRow> = {}): HilosLogKeyRow {
  return {
    rowKey: 'node-1:worker-0.log',
    key: 'worker-0.log',
    node: 'node-1',
    class: HILOS_LOG_CLASS_WORKER,
    live: true,
    batchCount: 12,
    lastBatchAt: 1800000000,
    bytes: 1024,
    growthPerDay: 512,
    ...overrides,
  }
}

function header(
  overrides: Partial<HilosLogKeysHeader> = {},
): HilosLogKeysHeader {
  return {
    available: true,
    nodes: [],
    ...overrides,
  }
}

function keyTableRow(
  rowKey: string,
  slot: Record<string, unknown> | undefined,
): TableRow {
  return { rowKey, slots: slot === undefined ? {} : { stream: slot } }
}

describe('resolveHilosLogKeyRow', () => {
  it('reads the stream slot into the view-model', () => {
    const resolved = resolveHilosLogKeyRow(
      keyTableRow('node-2:agent-hilos_logs.log', {
        key: 'agent-hilos_logs.log',
        node: 'node-2',
        class: HILOS_LOG_CLASS_AGENT,
        live: true,
        batchCount: 3,
        lastBatchAt: 1799999000,
        bytes: 4096,
        growthPerDay: 128,
      }),
    )

    expect(resolved).toEqual({
      rowKey: 'node-2:agent-hilos_logs.log',
      key: 'agent-hilos_logs.log',
      node: 'node-2',
      class: HILOS_LOG_CLASS_AGENT,
      live: true,
      batchCount: 3,
      lastBatchAt: 1799999000,
      bytes: 4096,
      growthPerDay: 128,
    })
  })

  it('keeps a nameless node null, because that is the single-node installation', () => {
    const resolved = resolveHilosLogKeyRow(
      keyTableRow('-:worker-0.log', { key: 'worker-0.log', bytes: 10 }),
    )

    expect(resolved.node).toBeNull()
  })

  it('keeps an unmeasured growth null rather than reading it as a standstill', () => {
    const resolved = resolveHilosLogKeyRow(
      keyTableRow('-:worker-0.log', {
        key: 'worker-0.log',
        bytes: 10,
        growthPerDay: null,
      }),
    )

    expect(resolved.growthPerDay).toBeNull()
  })

  it('takes the identity from the row key, never from inside the slot', () => {
    const resolved = resolveHilosLogKeyRow(
      keyTableRow('node-1:worker-0.log', undefined),
    )

    expect(resolved.rowKey).toBe('node-1:worker-0.log')
    expect(resolved.key).toBe('')
  })
})

describe('the header schema', () => {
  it('is registered under the page signal the backend sends it as', () => {
    expect(Object.keys(LOGS_KEYS_SIGNAL_SCHEMAS)).toEqual([KEYS_HEADER_SIGNAL])
  })

  it('accepts the third availability state, which is a null and not a false', () => {
    const parsed = LOGS_KEYS_SIGNAL_SCHEMAS[KEYS_HEADER_SIGNAL].safeParse({
      ...header(),
      available: null,
    })

    expect(parsed.success).toBe(true)
    expect(parsed.success && parsed.data.available).toBeNull()
  })

  it('refuses a payload whose node list is not a list of names', () => {
    const parsed = LOGS_KEYS_SIGNAL_SCHEMAS[KEYS_HEADER_SIGNAL].safeParse({
      ...header(),
      nodes: 'node-1',
    })

    expect(parsed.success).toBe(false)
  })

  it('refuses a payload with no availability at all, which is not the same as an unknown one', () => {
    const withoutAvailable: Record<string, unknown> = { ...header() }
    delete withoutAvailable.available
    const parsed =
      LOGS_KEYS_SIGNAL_SCHEMAS[KEYS_HEADER_SIGNAL].safeParse(withoutAvailable)

    expect(parsed.success).toBe(false)
  })
})

describe('hasLogKeyNodes', () => {
  it('is false for a single-node installation, which names no node at all', () => {
    expect(hasLogKeyNodes(header({ nodes: [] }))).toBe(false)
  })

  it('is false before the header arrives, so no column flashes on entry', () => {
    expect(hasLogKeyNodes(null)).toBe(false)
  })

  it('is true once the picture names nodes', () => {
    expect(hasLogKeyNodes(header({ nodes: ['node-1', 'node-2'] }))).toBe(true)
  })
})

describe('logKeysEmptyState', () => {
  it('waits rather than reporting a fault before any picture arrives', () => {
    expect(logKeysEmptyState(null, 0, false)).toBe('unknown')
    expect(logKeysEmptyState(header({ available: null }), 0, false)).toBe(
      'unknown',
    )
  })

  it('reports the fault when the picture arrived and nothing could be read', () => {
    expect(logKeysEmptyState(header({ available: false }), 0, false)).toBe(
      'unreadable',
    )
  })

  it('tells an installation with no logs from a filter that matched nothing', () => {
    expect(logKeysEmptyState(header(), 0, false)).toBe('never')
    expect(logKeysEmptyState(header(), 0, true)).toBe('nomatch')
  })

  it('says nothing at all when there are rows', () => {
    expect(logKeysEmptyState(header(), 3, true)).toBe('rows')
  })

  it('lets an unreadable picture outrank an empty window, which it explains', () => {
    expect(logKeysEmptyState(header({ available: false }), 0, true)).toBe(
      'unreadable',
    )
  })
})

describe('logKeyViewerPath', () => {
  it('opens a stream that is still written on its live file', () => {
    expect(logKeyViewerPath(row())).toBe(
      '/hilos/logs/view/node-1/live/worker-0.log',
    )
  })

  it('opens a stream that is only in the archive on its newest batch', () => {
    expect(
      logKeyViewerPath(row({ live: false, lastBatchAt: 1799999000 })),
    ).toBe('/hilos/logs/view/node-1/1799999000/worker-0.log')
  })

  it('names the single-node installation by the dash segment the viewer reads', () => {
    expect(logKeyViewerPath(row({ node: null }))).toBe(
      '/hilos/logs/view/-/live/worker-0.log',
    )
  })

  it('has no address for a stream that is neither live nor archived', () => {
    expect(logKeyViewerPath(row({ live: false, lastBatchAt: null }))).toBe('')
  })
})

describe('formatLogKeyWeight', () => {
  it('reports a zero-byte stream as a measurement and not as missing data', () => {
    expect(formatLogKeyWeight(row({ bytes: 0 }))).toBe('0 B')
  })

  it('climbs to the largest unit that leaves a readable number', () => {
    expect(formatLogKeyWeight(row({ bytes: 1024 }))).toBe('1.0 KB')
    expect(formatLogKeyWeight(row({ bytes: 1536 * 1024 * 1024 }))).toBe(
      '1.5 GB',
    )
  })
})

describe('formatLogKeyGrowth', () => {
  it('draws an unmeasured day as a dash, never as a zero', () => {
    expect(formatLogKeyGrowth(row({ growthPerDay: null }))).toBe('—')
  })

  it('reports a measured standstill as the zero it is', () => {
    expect(formatLogKeyGrowth(row({ growthPerDay: 0 }))).toBe('0 B')
  })

  it('climbs to the largest unit that leaves a readable number', () => {
    expect(formatLogKeyGrowth(row({ growthPerDay: 2 * 1024 * 1024 }))).toBe(
      '2.0 MB',
    )
  })
})

describe('formatLogKeyClass and formatLogKeyState', () => {
  it('labels the two classes this screen draws', () => {
    expect(formatLogKeyClass(row())).toBe('Worker')
    expect(formatLogKeyClass(row({ class: HILOS_LOG_CLASS_AGENT }))).toBe(
      'Agent',
    )
  })

  it('prints a class it does not know rather than folding it into one it does', () => {
    expect(formatLogKeyClass(row({ class: 'daemon' }))).toBe('daemon')
  })

  it('tells a stream still being written from one left in the archive', () => {
    expect(formatLogKeyState(row())).toBe('Writing')
    expect(formatLogKeyState(row({ live: false }))).toBe('Archive only')
  })
})

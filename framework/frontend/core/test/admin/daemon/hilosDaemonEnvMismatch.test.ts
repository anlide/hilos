import { describe, expect, it } from 'vitest'

import {
  createHilosDaemonEnvMismatchTables,
  DAEMON_ENV_MISMATCH_DATA,
  daemonEnvMismatchCellOf,
  daemonEnvMismatchCellView,
  daemonEnvMismatchDigestLength,
  daemonEnvMismatchEmptyState,
  daemonEnvMismatchFromExample,
  daemonEnvMismatchNodeColumnKey,
  daemonEnvMismatchNodes,
  daemonEnvMismatchStandsOut,
  daemonEnvMismatchSummarySchema,
  daemonEnvMismatchTypesDiffer,
  formatDaemonEnvMismatchNodeLabel,
  readHilosDaemonEnvMismatchSummary,
  resolveHilosDaemonEnvMismatchRow,
  type HilosDaemonEnvMismatchCell,
  type HilosDaemonEnvMismatchContext,
  type HilosDaemonEnvMismatchNode,
  type HilosDaemonEnvMismatchRow,
  type HilosDaemonEnvMismatchSummary,
} from '../../../src/admin/daemon/hilosDaemonEnvMismatch.js'
import {
  type HilosConnection,
  type TableViewportDescriptor,
} from '../../../src/connection/HilosConnection.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { createSignal } from '../../../src/state/signal.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'

function cell(
  nodeId: string,
  overrides: Partial<HilosDaemonEnvMismatchCell> = {},
): HilosDaemonEnvMismatchCell {
  return {
    nodeId,
    state: 'known',
    type: 'string',
    source: 'env',
    digest: 'a3f1000000000000',
    ...overrides,
  }
}

function row(
  overrides: Partial<HilosDaemonEnvMismatchRow> = {},
): HilosDaemonEnvMismatchRow {
  return {
    rowKey: 'APP_ENV',
    key: 'APP_ENV',
    sensitive: false,
    value: { kind: 'text', text: 'production' },
    cells: [cell('n1'), cell('n2', { digest: 'b4e2000000000000' })],
    ...overrides,
  }
}

function rawRow(slot: Record<string, unknown>): TableRow {
  return { rowKey: 'APP_ENV', slots: { key: slot } }
}

function stubTables(nodes: readonly HilosDaemonEnvMismatchNode[]) {
  const sent: Array<{
    page: string
    tableKey: string
    descriptor: TableViewportDescriptor
  }> = []
  const rendered: Array<{
    page: string
    tableKey: string
    fields: readonly string[]
  }> = []
  const nodeSignal = createSignal(nodes)
  const context: HilosDaemonEnvMismatchContext = {
    connection: {
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
        fields: readonly string[],
      ): boolean {
        rendered.push({ page, tableKey, fields })
        return true
      },
    } as unknown as HilosConnection,
    scopes: {} as ScopeManager,
  }

  return {
    tables: createHilosDaemonEnvMismatchTables(context, nodeSignal),
    nodeSignal,
    sent,
    rendered,
  }
}

describe('daemon environment mismatch row', () => {
  it('reads the fragment identity, three value forms, and three cell states', () => {
    const cells = [
      cell('n1'),
      cell('n2', {
        state: 'unknown',
        type: null,
        source: null,
        digest: null,
      }),
      cell('n3', {
        state: 'undeclared',
        type: null,
        source: null,
        digest: null,
      }),
    ]
    const base = {
      rowKey: 'wrong-slot-key',
      key: 'APP_ENV',
      sensitive: true,
      cells,
    }
    expect(
      resolveHilosDaemonEnvMismatchRow(rawRow({ ...base, value: null })),
    ).toEqual(row({ sensitive: true, value: null, cells }))
    expect(
      resolveHilosDaemonEnvMismatchRow(
        rawRow({ ...base, value: { kind: 'text', text: 'production' } }),
      ).value,
    ).toEqual({ kind: 'text', text: 'production' })
    expect(
      resolveHilosDaemonEnvMismatchRow(
        rawRow({ ...base, value: { kind: 'none' } }),
      ).value,
    ).toEqual({ kind: 'none' })
    expect(
      resolveHilosDaemonEnvMismatchRow(
        rawRow({ ...base, value: { _hidden: true } }),
      ).value,
    ).toBe(HIDDEN_VALUE)
  })

  it('degrades malformed values and cell lists without inventing known cells', () => {
    const badCells = [{ ...cell('n1'), state: 'unrecognized' }]
    expect(
      resolveHilosDaemonEnvMismatchRow(
        rawRow({
          key: 'APP_ENV',
          value: { kind: 'text', text: 7 },
          cells: badCells,
        }),
      ),
    ).toEqual(row({ value: null, cells: [] }))
    expect(
      resolveHilosDaemonEnvMismatchRow(
        rawRow({ key: 'APP_ENV', value: { kind: 'none' }, cells: 'bad' }),
      ).cells,
    ).toEqual([])
    expect(
      resolveHilosDaemonEnvMismatchRow(
        rawRow({
          key: 'APP_ENV',
          value: null,
          cells: [{ ...cell('n1'), source: 'future-source' }],
        }),
      ).cells[0]?.source,
    ).toBe('missing')
  })
})

describe('daemon environment mismatch viewports', () => {
  it('names both tables and sends unfiltered, unsorted descriptors', () => {
    const { tables, sent, rendered } = stubTables([
      { nodeId: 'n1', serving: true },
    ])
    for (const [controller, tableKey, title] of [
      [tables.diverged, 'hilosDaemonEnvDiverged', 'Keys the nodes disagree on'],
      [
        tables.sourcesDiffer,
        'hilosDaemonEnvSourcesDiffer',
        'Values match, sources differ',
      ],
    ] as const) {
      expect(controller.frame.declaration?.title).toBe(title)
      expect(controller.frame.declaration?.search).toBeUndefined()
      expect(controller.frame.declaration?.filters).toBeUndefined()
      expect(controller.frame.declaration?.empty).toBeUndefined()
      expect(controller.frame.declaration?.mainAction).toBeUndefined()
      expect(controller.frame.declaration?.bulkActions).toBeUndefined()
      controller.ingestSubscriptionWindow(
        [],
        0,
        true,
        null,
        null,
        25,
        undefined,
        [],
      )
      controller.refresh()
      expect(sent.at(-1)).toMatchObject({
        page: HilosPages.DAEMON_ENV_MISMATCH,
        tableKey,
        descriptor: { filter: {}, sort: null },
      })
      expect(rendered.at(-1)).toEqual({
        page: HilosPages.DAEMON_ENV_MISMATCH,
        tableKey,
        fields: ['key', 'sensitive', 'node:n1', 'cells', 'value'],
      })
    }
  })

  it('rebuilds columns in page order when the node signal changes', () => {
    const { tables, nodeSignal } = stubTables([
      { nodeId: 'n1', serving: false },
      { nodeId: 'n2', serving: true },
      { nodeId: 'n10', serving: false },
    ])
    for (const controller of [tables.diverged, tables.sourcesDiffer]) {
      const columns = controller.frame.columns.get()
      expect(columns.map((column) => column.key)).toEqual([
        'key',
        'node:n1',
        'node:n2',
        'node:n10',
      ])
      expect(columns.map((column) => column.label)).toEqual([
        'Key',
        'n1',
        'n2 · this node',
        'n10',
      ])
      expect(columns[0]).toMatchObject({ card: 'title', reads: ['sensitive'] })
      expect(columns[1]?.reads).toEqual(['cells', 'value'])
      expect(columns.every((column) => column.sortable !== true)).toBe(true)
    }
    nodeSignal.set([{ nodeId: 'n3', serving: true }])
    for (const controller of [tables.diverged, tables.sourcesDiffer]) {
      expect(
        controller.frame.columns.get().map((column) => column.key),
      ).toEqual(['key', 'node:n3'])
      expect(controller.frame.columns.get()[1]?.label).toBe('n3 · this node')
    }
    expect(daemonEnvMismatchNodeColumnKey('n3')).toBe('node:n3')
    expect(
      formatDaemonEnvMismatchNodeLabel({ nodeId: 'n3', serving: false }),
    ).toBe('n3')
  })
})

describe('daemon environment mismatch cells', () => {
  it('distinguishes silence, undeclared keys, missing values, and absent nodes', () => {
    const comparison = row({
      cells: [
        cell('silent', {
          state: 'unknown',
          type: null,
          source: null,
          digest: null,
        }),
        cell('absent', {
          state: 'undeclared',
          type: null,
          source: null,
          digest: null,
        }),
        cell('missing', { source: 'missing', digest: null }),
      ],
    })
    expect(daemonEnvMismatchCellOf(comparison, 'outside')).toBeNull()
    expect(
      daemonEnvMismatchCellView(comparison, 'outside', 'missing', false),
    ).toEqual({
      kind: 'unknown',
      text: '?',
    })
    expect(
      daemonEnvMismatchCellView(comparison, 'silent', 'missing', false),
    ).toEqual({
      kind: 'unknown',
      text: '?',
    })
    expect(
      daemonEnvMismatchCellView(comparison, 'absent', 'missing', false),
    ).toEqual({
      kind: 'undeclared',
      text: 'Not declared',
    })
    expect(
      daemonEnvMismatchCellView(comparison, 'missing', 'missing', false),
    ).toEqual({
      kind: 'none',
      text: '—',
      source: 'missing',
    })
  })

  it('shows a local value only on its node, or on every known cell when values match', () => {
    const comparison = row()
    expect(daemonEnvMismatchCellView(comparison, 'n1', 'n1', false)).toEqual({
      kind: 'value',
      view: { kind: 'text', text: 'production', numeric: false },
      source: 'env',
    })
    expect(daemonEnvMismatchCellView(comparison, 'n2', 'n1', false)).toEqual({
      kind: 'digest',
      text: '#b4e2',
      source: 'env',
    })
    expect(daemonEnvMismatchCellView(comparison, 'n2', 'n1', true)).toEqual({
      kind: 'value',
      view: { kind: 'text', text: 'production', numeric: false },
      source: 'env',
    })
    expect(
      daemonEnvMismatchCellView(row({ value: HIDDEN_VALUE }), 'n1', 'n1', true),
    ).toEqual({
      kind: 'value',
      view: { kind: 'hidden' },
      source: 'env',
    })
    expect(
      daemonEnvMismatchCellView(
        row({ sensitive: true, value: null }),
        'n1',
        'n1',
        true,
      ),
    ).toEqual({
      kind: 'digest',
      text: '#a3f1',
      source: 'env',
    })
  })

  it('extends digest prefixes only for different colliding labels, at most to 16', () => {
    expect(daemonEnvMismatchDigestLength(row())).toBe(4)
    const shared = row({
      cells: [
        cell('n1', { digest: 'abcd100000000000' }),
        cell('n2', { digest: 'abcd200000000000' }),
        cell('n3', { digest: 'abcd100000000000' }),
      ],
    })
    expect(daemonEnvMismatchDigestLength(shared)).toBe(5)
    expect(daemonEnvMismatchCellView(shared, 'n2', null, false)).toMatchObject({
      text: '#abcd2',
    })
    expect(
      daemonEnvMismatchDigestLength(
        row({
          cells: [
            cell('n1', { digest: 'abcdefghijklmnop1' }),
            cell('n2', { digest: 'abcdefghijklmnop2' }),
          ],
        }),
      ),
    ).toBe(16)
  })

  it('adds the type to labels when known node types differ', () => {
    const comparison = row({
      value: null,
      cells: [
        cell('n1'),
        cell('n2', { type: 'integer', digest: 'b4e2000000000000' }),
      ],
    })
    expect(daemonEnvMismatchTypesDiffer(comparison)).toBe(true)
    expect(daemonEnvMismatchCellView(comparison, 'n2', 'n1', false)).toEqual({
      kind: 'digest',
      text: '#b4e2 · integer',
      source: 'env',
    })
    expect(daemonEnvMismatchTypesDiffer(row())).toBe(false)
  })

  it('marks unique type-and-digest pairs, undeclared keys, and example sources', () => {
    const comparison = row({
      cells: [
        cell('n1'),
        cell('n2'),
        cell('n3', { digest: 'b4e2000000000000' }),
        cell('n4', {
          state: 'undeclared',
          type: null,
          source: null,
          digest: null,
        }),
        cell('n5', {
          source: 'example',
          digest: 'a3f1000000000000',
          type: 'integer',
        }),
      ],
    })
    expect(daemonEnvMismatchStandsOut(comparison, 'n1')).toBe(false)
    expect(daemonEnvMismatchStandsOut(comparison, 'n2')).toBe(false)
    expect(daemonEnvMismatchStandsOut(comparison, 'n3')).toBe(true)
    expect(daemonEnvMismatchStandsOut(comparison, 'n4')).toBe(true)
    expect(daemonEnvMismatchStandsOut(comparison, 'n5')).toBe(true)
    expect(daemonEnvMismatchStandsOut(comparison, 'outside')).toBe(false)
    expect(daemonEnvMismatchFromExample(comparison, 'n5')).toBe(true)
    expect(daemonEnvMismatchFromExample(comparison, 'n1')).toBe(false)
    expect(daemonEnvMismatchFromExample(comparison, 'n4')).toBe(false)
  })
})

describe('daemon environment mismatch summary and helpers', () => {
  it('validates the complete summary, standalone null comparison, and degrades garbage to null', () => {
    const fullSummary: HilosDaemonEnvMismatchSummary = {
      clustered: true,
      servingNodeId: 'n1',
      comparison: {
        nodes: ['n1', 'n2'],
        answering: ['n1', 'n2'],
        diverged: 1,
        sourcesDiffer: 2,
        perNode: ['CLUSTER_NODE_ID'],
      },
    }
    expect(daemonEnvMismatchSummarySchema.parse(fullSummary)).toEqual(
      fullSummary,
    )

    const standaloneSummary: HilosDaemonEnvMismatchSummary = {
      clustered: false,
      servingNodeId: 'standalone',
      comparison: null,
    }
    expect(daemonEnvMismatchSummarySchema.parse(standaloneSummary)).toEqual(
      standaloneSummary,
    )

    expect(
      daemonEnvMismatchSummarySchema.safeParse({ invalid: 'object' }).success,
    ).toBe(false)

    const scopes = new ScopeManager()
    const scope = scopes.openPage(HilosPages.DAEMON_ENV_MISMATCH)
    const signal = readHilosDaemonEnvMismatchSummary(scopes)
    expect(signal.get()).toBeNull()

    scope.data.set(DAEMON_ENV_MISMATCH_DATA, fullSummary)
    expect(signal.get()).toEqual(fullSummary)

    scope.data.set(DAEMON_ENV_MISMATCH_DATA, 'garbage-string')
    expect(signal.get()).toBeNull()
  })

  it('builds node columns marking only the serving node, returning empty on null', () => {
    const summary: HilosDaemonEnvMismatchSummary = {
      clustered: true,
      servingNodeId: 'n2',
      comparison: {
        nodes: ['n1', 'n2', 'n3'],
        answering: ['n1', 'n2'],
        diverged: 0,
        sourcesDiffer: 0,
        perNode: [],
      },
    }
    expect(daemonEnvMismatchNodes(summary)).toEqual([
      { nodeId: 'n1', serving: false },
      { nodeId: 'n2', serving: true },
      { nodeId: 'n3', serving: false },
    ])
    expect(daemonEnvMismatchNodes(null)).toEqual([])
    expect(
      daemonEnvMismatchNodes({
        clustered: false,
        servingNodeId: 'standalone',
        comparison: null,
      }),
    ).toEqual([])
  })

  it('derives empty state between alone and agree', () => {
    expect(
      daemonEnvMismatchEmptyState({
        nodes: ['n1', 'n2'],
        answering: ['n1'],
        diverged: 0,
        sourcesDiffer: 0,
        perNode: [],
      }),
    ).toBe('alone')
    expect(
      daemonEnvMismatchEmptyState({
        nodes: ['n1', 'n2'],
        answering: ['n1', 'n2'],
        diverged: 0,
        sourcesDiffer: 0,
        perNode: [],
      }),
    ).toBe('agree')
  })
})

import { describe, expect, it } from 'vitest'

import {
  formatRetentionRule,
  formatRotationFileCounts,
  formatRotationRule,
  formatRotationState,
  formatRotationWeight,
  hasRotationNodes,
  resolveHilosLogRotationRow,
  rotationsEmptyState,
  HILOS_ROTATION_STATE_DUE,
  HILOS_ROTATION_STATE_TAKEN,
  LOGS_SIGNAL_SCHEMAS,
  ROTATIONS_HEADER_SIGNAL,
  type HilosLogRotationRow,
  type HilosLogRotationsHeader,
} from '../../../src/admin/logs/hilosLogRotations.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'

function row(
  overrides: Partial<HilosLogRotationRow> = {},
): HilosLogRotationRow {
  return {
    rowKey: 'node-1:1800000000',
    batchAt: 1800000000,
    node: 'node-1',
    path: 'archive/2027-01-15-08-00-00/',
    agentFileCount: 12,
    workerFileCount: 8,
    workerMonopolisticFileCount: 2,
    bytes: 1024,
    retentionState: 'kept',
    ...overrides,
  }
}

function header(
  overrides: Partial<HilosLogRotationsHeader> = {},
): HilosLogRotationsHeader {
  return {
    available: true,
    nodes: [],
    rotationCron: null,
    rotationMaxAgeSeconds: 0,
    rotationMaxLiveSizeBytes: 0,
    retentionKeepBatches: 0,
    retentionMaxAgeSeconds: 0,
    ...overrides,
  }
}

function rotationTableRow(
  rowKey: string,
  slot: Record<string, unknown> | undefined,
): TableRow {
  return { rowKey, slots: slot === undefined ? {} : { batch: slot } }
}

describe('resolveHilosLogRotationRow', () => {
  it('reads the batch slot into the view-model', () => {
    const resolved = resolveHilosLogRotationRow(
      rotationTableRow('node-2:1800000000', {
        batchAt: 1800000000,
        node: 'node-2',
        path: 'archive/2027-01-15-08-00-00/',
        agentFileCount: 11,
        workerFileCount: 8,
        workerMonopolisticFileCount: 2,
        bytes: 4096,
        retentionState: HILOS_ROTATION_STATE_DUE,
      }),
    )

    expect(resolved).toEqual({
      rowKey: 'node-2:1800000000',
      batchAt: 1800000000,
      node: 'node-2',
      path: 'archive/2027-01-15-08-00-00/',
      agentFileCount: 11,
      workerFileCount: 8,
      workerMonopolisticFileCount: 2,
      bytes: 4096,
      retentionState: HILOS_ROTATION_STATE_DUE,
    })
  })

  it('keeps a nameless node null, because that is the single-node installation', () => {
    const resolved = resolveHilosLogRotationRow(
      rotationTableRow('-:1800000000', { batchAt: 1800000000, bytes: 10 }),
    )

    expect(resolved.node).toBeNull()
  })

  it('takes the identity from the row key, never from inside the slot', () => {
    const resolved = resolveHilosLogRotationRow(
      rotationTableRow('node-1:1800000000', undefined),
    )

    expect(resolved.rowKey).toBe('node-1:1800000000')
    expect(resolved.batchAt).toBe(0)
  })
})

describe('the header schema', () => {
  it('is registered under the page signal the backend sends it as', () => {
    expect(Object.keys(LOGS_SIGNAL_SCHEMAS)).toEqual([ROTATIONS_HEADER_SIGNAL])
  })

  it('accepts the third availability state, which is a null and not a false', () => {
    const parsed = LOGS_SIGNAL_SCHEMAS[ROTATIONS_HEADER_SIGNAL].safeParse({
      ...header(),
      available: null,
    })

    expect(parsed.success).toBe(true)
    expect(parsed.success && parsed.data.available).toBeNull()
  })

  it('refuses a payload whose node list is not a list of names', () => {
    const parsed = LOGS_SIGNAL_SCHEMAS[ROTATIONS_HEADER_SIGNAL].safeParse({
      ...header(),
      nodes: 'node-1',
    })

    expect(parsed.success).toBe(false)
  })

  it('refuses a payload missing a rule the screen has to print', () => {
    const withoutKeepBatches: Record<string, unknown> = { ...header() }
    delete withoutKeepBatches.retentionKeepBatches
    const parsed =
      LOGS_SIGNAL_SCHEMAS[ROTATIONS_HEADER_SIGNAL].safeParse(withoutKeepBatches)

    expect(parsed.success).toBe(false)
  })
})

describe('hasRotationNodes', () => {
  it('is false for a single-node installation, which names no node at all', () => {
    expect(hasRotationNodes(header({ nodes: [] }))).toBe(false)
  })

  it('is false before the header arrives, so no column flashes on entry', () => {
    expect(hasRotationNodes(null)).toBe(false)
  })

  it('is true once the picture names nodes', () => {
    expect(hasRotationNodes(header({ nodes: ['node-1', 'node-2'] }))).toBe(true)
  })
})

describe('rotationsEmptyState', () => {
  it('waits rather than reporting a fault before any picture arrives', () => {
    expect(rotationsEmptyState(null, 0, false)).toBe('unknown')
    expect(rotationsEmptyState(header({ available: null }), 0, false)).toBe(
      'unknown',
    )
  })

  it('reports the fault when the picture arrived and nothing could be read', () => {
    expect(rotationsEmptyState(header({ available: false }), 0, false)).toBe(
      'unreadable',
    )
  })

  it('tells an installation that never rotated from a filter that matched nothing', () => {
    expect(rotationsEmptyState(header(), 0, false)).toBe('never')
    expect(rotationsEmptyState(header(), 0, true)).toBe('nomatch')
  })

  it('says nothing at all when there are rows', () => {
    expect(rotationsEmptyState(header(), 3, true)).toBe('rows')
  })

  it('lets an unreadable picture outrank an empty window, which it explains', () => {
    expect(rotationsEmptyState(header({ available: false }), 0, true)).toBe(
      'unreadable',
    )
  })
})

describe('formatRotationWeight', () => {
  it('reports a zero-byte batch as a measurement and not as missing data', () => {
    expect(formatRotationWeight(row({ bytes: 0 }))).toBe('0 B')
  })

  it('climbs to the largest unit that leaves a readable number', () => {
    expect(formatRotationWeight(row({ bytes: 1024 }))).toBe('1.0 KB')
    expect(formatRotationWeight(row({ bytes: 1536 * 1024 * 1024 }))).toBe(
      '1.5 GB',
    )
  })
})

describe('formatRotationFileCounts', () => {
  it('shows the three classes an operator acts on, daemon streams apart', () => {
    expect(formatRotationFileCounts(row())).toBe('12 / 8 / 2')
  })
})

describe('formatRotationState', () => {
  it('labels the verdicts this build knows', () => {
    expect(formatRotationState(row())).toBe('Kept')
    expect(
      formatRotationState(row({ retentionState: HILOS_ROTATION_STATE_DUE })),
    ).toBe('Awaiting carry-off')
    expect(
      formatRotationState(row({ retentionState: HILOS_ROTATION_STATE_TAKEN })),
    ).toBe('Taken')
  })

  it('prints a verdict it does not know rather than calling it protected', () => {
    expect(formatRotationState(row({ retentionState: 'evicted' }))).toBe(
      'evicted',
    )
  })
})

describe('formatRotationRule', () => {
  it('lists every axis that is on, and joins them as alternatives', () => {
    expect(
      formatRotationRule(
        header({
          rotationCron: '0 4 * * *',
          rotationMaxAgeSeconds: 3600,
          rotationMaxLiveSizeBytes: 512 * 1024 * 1024,
        }),
      ),
    ).toBe(
      'Rotates on the schedule 0 4 * * *, or 1 h after the last rotation, or when the live logs reach 512.0 MB',
    )
  })

  it('leaves a disabled axis out instead of printing it as a zero', () => {
    expect(formatRotationRule(header({ rotationMaxLiveSizeBytes: 1024 }))).toBe(
      'Rotates when the live logs reach 1.0 KB',
    )
  })

  it('says outright that nothing but a restart rotates, rather than printing a blank', () => {
    expect(formatRotationRule(header())).toBe(
      'Rotates only when the node restarts',
    )
  })
})

describe('formatRetentionRule', () => {
  it('joins the two criteria with "and", because both hold at once', () => {
    expect(
      formatRetentionRule(
        header({ retentionKeepBatches: 7, retentionMaxAgeSeconds: 2592000 }),
      ),
    ).toBe(
      'Recommends carrying off a batch outside the newest 7 and older than 30 d',
    )
  })

  it('says outright that nothing is ever recommended when both criteria are off', () => {
    expect(formatRetentionRule(header())).toBe(
      'Nothing is ever recommended for carrying off',
    )
  })
})

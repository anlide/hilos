import { describe, expect, it } from 'vitest'

import {
  formatLogsOverviewBytes,
  formatLogsOverviewCount,
  formatLogsOverviewGrowth,
  formatLogsOverviewRotationAt,
  hasLogsOverviewNodes,
  logsOverviewBatchesNote,
  logsOverviewGrowthNote,
  logsOverviewNodesDue,
  logsOverviewState,
  logsOverviewTakeoutHeadline,
  LOGS_OVERVIEW_SIGNAL_SCHEMAS,
  OVERVIEW_SIGNAL,
  type HilosLogsOverview,
  type HilosLogsOverviewNode,
} from '../../../src/admin/logs/hilosLogsOverview.js'

function node(
  overrides: Partial<HilosLogsOverviewNode> = {},
): HilosLogsOverviewNode {
  return {
    nodeId: 'node-1',
    available: true,
    lastRotationAt: '2026-09-02T03:00:00+00:00',
    liveBytes: 1024,
    archiveBytes: 4096,
    growthBytesPerDay: 512,
    batchesDueForTakeout: 0,
    ...overrides,
  }
}

function overview(
  overrides: Partial<HilosLogsOverview> = {},
): HilosLogsOverview {
  return {
    available: true,
    totalRotationsAllTime: 384,
    lastRotationAt: '2026-09-02T03:00:00+00:00',
    logKeysPerAgent: 14,
    totalWeightAgentKeysBytes: 940,
    logKeysPerWorker: 9,
    totalWeightWorkerKeysBytes: 3200,
    growthBytesPerDay: 620,
    keysWithoutGrowthWindow: 0,
    batchesDueForTakeout: 0,
    nodes: [],
    ...overrides,
  }
}

describe('LOGS_OVERVIEW_SIGNAL_SCHEMAS', () => {
  it('is a set of its own, so no neighbouring screen has to land first', () => {
    expect(Object.keys(LOGS_OVERVIEW_SIGNAL_SCHEMAS)).toEqual([OVERVIEW_SIGNAL])
  })

  it('accepts the third availability state, which is a null and not a false', () => {
    const parsed = LOGS_OVERVIEW_SIGNAL_SCHEMAS[OVERVIEW_SIGNAL].safeParse({
      ...overview(),
      available: null,
    })

    expect(parsed.success).toBe(true)
    expect(parsed.success && parsed.data.available).toBeNull()
  })

  it('accepts a node row whose every figure is null, which is a node nobody could read', () => {
    const parsed = LOGS_OVERVIEW_SIGNAL_SCHEMAS[OVERVIEW_SIGNAL].safeParse(
      overview({
        nodes: [
          node({
            nodeId: 'node-2',
            available: false,
            lastRotationAt: null,
            liveBytes: null,
            archiveBytes: null,
            growthBytesPerDay: null,
            batchesDueForTakeout: null,
          }),
        ],
      }),
    )

    expect(parsed.success).toBe(true)
  })

  it('tolerates a field it has never heard of, so a newer backend still reaches the screen', () => {
    const parsed = LOGS_OVERVIEW_SIGNAL_SCHEMAS[OVERVIEW_SIGNAL].safeParse({
      ...overview(),
      somethingLaterLeavesAdded: 7,
    })

    expect(parsed.success).toBe(true)
  })

  it('refuses a payload whose node list is not a list', () => {
    const parsed = LOGS_OVERVIEW_SIGNAL_SCHEMAS[OVERVIEW_SIGNAL].safeParse({
      ...overview(),
      nodes: 'node-1',
    })

    expect(parsed.success).toBe(false)
  })

  it('refuses a node row with no name, because a nameless node does not travel here', () => {
    const nameless: Record<string, unknown> = { ...node() }
    delete nameless.nodeId
    const parsed = LOGS_OVERVIEW_SIGNAL_SCHEMAS[OVERVIEW_SIGNAL].safeParse(
      overview({ nodes: [nameless as HilosLogsOverviewNode] }),
    )

    expect(parsed.success).toBe(false)
  })

  it('refuses a payload with no availability at all, which is not the same as an unknown one', () => {
    const withoutAvailable: Record<string, unknown> = { ...overview() }
    delete withoutAvailable.available
    const parsed =
      LOGS_OVERVIEW_SIGNAL_SCHEMAS[OVERVIEW_SIGNAL].safeParse(withoutAvailable)

    expect(parsed.success).toBe(false)
  })
})

describe('logsOverviewState', () => {
  it('waits rather than reporting a fault before any picture arrives', () => {
    expect(logsOverviewState(null)).toBe('unknown')
    expect(logsOverviewState(overview({ available: null }))).toBe('unknown')
  })

  it('reports the fault when the picture arrived and nothing could be read', () => {
    expect(logsOverviewState(overview({ available: false }))).toBe('unreadable')
  })

  it('has figures once at least one node answered for itself', () => {
    expect(logsOverviewState(overview())).toBe('figures')
  })
})

describe('hasLogsOverviewNodes', () => {
  it('is false for a single-node installation, which names no node at all', () => {
    expect(hasLogsOverviewNodes(overview({ nodes: [] }))).toBe(false)
  })

  it('is false before the frame arrives, so no table flashes on entry', () => {
    expect(hasLogsOverviewNodes(null)).toBe(false)
  })

  it('is true once the picture names nodes', () => {
    expect(hasLogsOverviewNodes(overview({ nodes: [node()] }))).toBe(true)
  })
})

describe('logsOverviewNodesDue', () => {
  it('names only the nodes actually holding something past its retention', () => {
    const screen = overview({
      nodes: [
        node({ nodeId: 'node-1', batchesDueForTakeout: 0 }),
        node({ nodeId: 'node-2', batchesDueForTakeout: 2 }),
        node({ nodeId: 'node-3', batchesDueForTakeout: 1 }),
      ],
    })

    expect(logsOverviewNodesDue(screen)).toEqual(['node-2', 'node-3'])
  })

  it('names no node whose verdict is not known, which is a node nobody could read', () => {
    const screen = overview({
      nodes: [node({ nodeId: 'node-2', batchesDueForTakeout: null })],
    })

    expect(logsOverviewNodesDue(screen)).toEqual([])
  })

  it('names nobody before the frame arrives', () => {
    expect(logsOverviewNodesDue(null)).toEqual([])
  })
})

describe('formatLogsOverviewBytes', () => {
  it('prints a dash for what nobody measured', () => {
    expect(formatLogsOverviewBytes(null)).toBe('—')
  })

  it('prints a measured nothing as a zero, which is not the same answer', () => {
    expect(formatLogsOverviewBytes(0)).toBe('0 B')
  })

  it('climbs to the largest unit that leaves a readable number', () => {
    expect(formatLogsOverviewBytes(1536)).toBe('1.5 KB')
  })
})

describe('formatLogsOverviewCount', () => {
  it('keeps the same split between an unknown and a counted nothing', () => {
    expect(formatLogsOverviewCount(null)).toBe('—')
    expect(formatLogsOverviewCount(0)).toBe('0')
  })
})

describe('formatLogsOverviewRotationAt', () => {
  it('prints a dash when nothing has ever rotated', () => {
    expect(formatLogsOverviewRotationAt(null)).toBe('—')
  })

  it('prints a dash rather than the words an unreadable instant would produce', () => {
    expect(formatLogsOverviewRotationAt('the day before yesterday')).toBe('—')
  })

  it('prints a real instant in the reader own locale', () => {
    expect(formatLogsOverviewRotationAt('2026-09-02T03:00:00+00:00')).not.toBe(
      '—',
    )
  })
})

describe('formatLogsOverviewGrowth', () => {
  it('says so in words while the first day is still being measured', () => {
    expect(
      formatLogsOverviewGrowth(overview({ growthBytesPerDay: null })),
    ).toBe('Still measuring')
  })

  it('shows the figure once there is one', () => {
    expect(
      formatLogsOverviewGrowth(overview({ growthBytesPerDay: 2048 })),
    ).toBe('2.0 KB')
  })

  it('shows nothing at all in either empty state, where even the words would be a claim', () => {
    expect(formatLogsOverviewGrowth(null)).toBe('—')
    expect(formatLogsOverviewGrowth(overview({ available: false }))).toBe('—')
  })
})

describe('logsOverviewGrowthNote', () => {
  it('appears exactly when some stream has not been watched for a day yet', () => {
    expect(
      logsOverviewGrowthNote(overview({ keysWithoutGrowthWindow: 3 })),
    ).toBe('No full day of data yet for 3 streams')
  })

  it('says one stream in the singular', () => {
    expect(
      logsOverviewGrowthNote(overview({ keysWithoutGrowthWindow: 1 })),
    ).toBe('No full day of data yet for 1 stream')
  })

  it('stays away when every stream has a full day behind it', () => {
    expect(
      logsOverviewGrowthNote(overview({ keysWithoutGrowthWindow: 0 })),
    ).toBeNull()
  })

  it('stays away beside no figure at all, where the tile already says it', () => {
    expect(
      logsOverviewGrowthNote(
        overview({ growthBytesPerDay: null, keysWithoutGrowthWindow: 4 }),
      ),
    ).toBeNull()
  })
})

describe('logsOverviewBatchesNote', () => {
  it('agrees with the one a fresh installation is at', () => {
    expect(
      logsOverviewBatchesNote(overview({ totalRotationsAllTime: 1 })),
    ).toBe('1 batch so far')
  })

  it('agrees with every other count', () => {
    expect(
      logsOverviewBatchesNote(overview({ totalRotationsAllTime: 0 })),
    ).toBe('0 batches so far')
    expect(
      logsOverviewBatchesNote(overview({ totalRotationsAllTime: 384 })),
    ).toBe('384 batches so far')
  })

  it('keeps the plural for a count nobody knows, which is not a one', () => {
    expect(logsOverviewBatchesNote(null)).toBe('— batches so far')
  })
})

describe('logsOverviewTakeoutHeadline', () => {
  it('agrees with the single batch, which is how the first one always arrives', () => {
    expect(logsOverviewTakeoutHeadline(1)).toBe(
      '1 batch is waiting to be taken out',
    )
  })

  it('agrees with the many', () => {
    expect(logsOverviewTakeoutHeadline(3)).toBe(
      '3 batches are waiting to be taken out',
    )
  })
})

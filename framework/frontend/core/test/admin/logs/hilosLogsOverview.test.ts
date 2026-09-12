import { describe, expect, it } from 'vitest'

import {
  formatLogsOverviewBytes,
  formatLogsOverviewCount,
  formatLogsOverviewErrorAt,
  formatLogsOverviewGrowth,
  formatLogsOverviewRotationAt,
  hasLogsOverviewNodes,
  hasLogsOverviewRecentErrors,
  logsOverviewBatchesNote,
  logsOverviewErrorOrigin,
  logsOverviewErrorPath,
  logsOverviewForecastNote,
  logsOverviewGrowthNote,
  logsOverviewNodesDue,
  logsOverviewRecentErrors,
  logsOverviewRecentErrorsBadge,
  logsOverviewState,
  logsOverviewTakeoutHeadline,
  LOGS_OVERVIEW_SIGNAL_SCHEMAS,
  OVERVIEW_SIGNAL,
  type HilosLogsOverview,
  type HilosLogsOverviewError,
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
    filesystemFreeBytes: null,
    filesystemTotalBytes: null,
    freeSpaceThresholdPercent: null,
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
    recentErrors: [],
    recentErrorsCapped: false,
    filesystemFreeBytes: null,
    filesystemTotalBytes: null,
    freeSpaceThresholdPercent: null,
    ...overrides,
  }
}

function failure(
  overrides: Partial<HilosLogsOverviewError> = {},
): HilosLogsOverviewError {
  return {
    nodeId: '',
    stream: 'worker-monopolistic-5.error.log',
    at: '2026-09-06T10:00:02.125+00:00',
    message: 'login action failed',
    traceFrames: 3,
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

  it('accepts the panel of failures, the null frame count included', () => {
    const parsed = LOGS_OVERVIEW_SIGNAL_SCHEMAS[OVERVIEW_SIGNAL].safeParse(
      overview({
        recentErrors: [failure(), failure({ traceFrames: null })],
        recentErrorsCapped: true,
      }),
    )

    expect(parsed.success).toBe(true)
    expect(parsed.success && parsed.data.recentErrors[1].traceFrames).toBeNull()
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

describe('logsOverviewRecentErrors', () => {
  it('has nothing to draw before a picture arrives, which is not the same as no failures', () => {
    expect(logsOverviewRecentErrors(null)).toEqual([])
    expect(hasLogsOverviewRecentErrors(null)).toBe(false)
  })

  it('draws the list the page sent, in the order it sent it', () => {
    const newest = failure({ message: 'the newest one' })
    const older = failure({ message: 'the older one' })

    expect(
      logsOverviewRecentErrors(overview({ recentErrors: [newest, older] })),
    ).toEqual([newest, older])
    expect(
      hasLogsOverviewRecentErrors(overview({ recentErrors: [newest, older] })),
    ).toBe(true)
  })
})

describe('logsOverviewRecentErrorsBadge', () => {
  it('names the length exactly while the list was not cut', () => {
    expect(
      logsOverviewRecentErrorsBadge(
        overview({ recentErrors: [failure(), failure(), failure()] }),
      ),
    ).toBe('3')
  })

  it('says there were at least that many once the page cut the list', () => {
    expect(
      logsOverviewRecentErrorsBadge(
        overview({ recentErrors: [failure()], recentErrorsCapped: true }),
      ),
    ).toBe('1+')
  })
})

describe('formatLogsOverviewErrorAt', () => {
  it('prints the time of day and leaves the date out, since every row shares it', () => {
    const printed = formatLogsOverviewErrorAt('2026-09-06T10:00:02.125+00:00')

    expect(printed).toBe(
      new Date('2026-09-06T10:00:02.125+00:00').toLocaleTimeString(),
    )
    expect(printed).not.toContain('2026')
  })

  it('answers a dash to an instant it cannot read, rather than the words Invalid Date', () => {
    expect(formatLogsOverviewErrorAt('whenever')).toBe('—')
  })
})

describe('logsOverviewErrorOrigin', () => {
  it('names the node only where an installation has node names', () => {
    const clustered = overview({ nodes: [node({ nodeId: 'node-2' })] })

    expect(
      logsOverviewErrorOrigin(
        clustered,
        failure({ nodeId: 'node-2', stream: 'worker-0.error.log' }),
      ),
    ).toBe('node-2 · worker-0.error.log')
  })

  it('names the stream alone on one machine, where there is no node to speak of', () => {
    expect(
      logsOverviewErrorOrigin(
        overview(),
        failure({ stream: 'worker-0.error.log' }),
      ),
    ).toBe('worker-0.error.log')
  })
})

describe('logsOverviewErrorPath', () => {
  it('leads to the viewer on the live file the line is in', () => {
    expect(
      logsOverviewErrorPath(
        failure({ nodeId: 'node-2', stream: 'worker-0.error.log' }),
      ),
    ).toBe('/hilos/logs/view/node-2/live/worker-0.error.log')
  })

  it('reads the empty node id as the machine the reader is on', () => {
    expect(logsOverviewErrorPath(failure({ stream: 'daemon-error.log' }))).toBe(
      '/hilos/logs/view/-/live/daemon-error.log',
    )
  })
})

describe('logsOverviewForecastNote', () => {
  it('turns the rate and the room into days on a single-node installation', () => {
    expect(
      logsOverviewForecastNote(
        overview({
          growthBytesPerDay: 100,
          filesystemFreeBytes: 5400,
          filesystemTotalBytes: 10000,
          freeSpaceThresholdPercent: 20,
        }),
      ),
    ).toBe('At this rate the 20% threshold is 34 days away')
  })

  it('names the node in a cluster', () => {
    expect(
      logsOverviewForecastNote(
        overview({
          nodes: [
            node({
              nodeId: 'node-2',
              growthBytesPerDay: 100,
              filesystemFreeBytes: 3200,
              filesystemTotalBytes: 10000,
              freeSpaceThresholdPercent: 20,
            }),
          ],
        }),
      ),
    ).toBe('At this rate the 20% threshold is 12 days away on node-2')
  })

  // Days are rounded down, so the count reaches zero before the room does. A zero said
  // as a figure would promise a day that the rounding has already spent.
  it('says less than a day rather than nought days', () => {
    expect(
      logsOverviewForecastNote(
        overview({
          nodes: [
            node({
              nodeId: 'node-2',
              growthBytesPerDay: 100,
              filesystemFreeBytes: 2050,
              filesystemTotalBytes: 10000,
              freeSpaceThresholdPercent: 20,
            }),
          ],
        }),
      ),
    ).toBe('At this rate the 20% threshold is less than a day away on node-2')
  })

  // A threshold of nought is the installation that keeps no reserve, which is a
  // second family of wording rather than an axis switched off.
  it('counts the days to a full disk when no reserve is kept', () => {
    expect(
      logsOverviewForecastNote(
        overview({
          growthBytesPerDay: 100,
          filesystemFreeBytes: 3400,
          filesystemTotalBytes: 10000,
          freeSpaceThresholdPercent: 0,
        }),
      ),
    ).toBe('At this rate the disk is full in 34 days')
  })

  it('names the node in the zero-threshold family too', () => {
    expect(
      logsOverviewForecastNote(
        overview({
          nodes: [
            node({
              nodeId: 'node-2',
              growthBytesPerDay: 100,
              filesystemFreeBytes: 3400,
              filesystemTotalBytes: 10000,
              freeSpaceThresholdPercent: 0,
            }),
          ],
        }),
      ),
    ).toBe('At this rate the disk is full in 34 days on node-2')
  })

  it('says the threshold is already behind us', () => {
    expect(
      logsOverviewForecastNote(
        overview({
          nodes: [
            node({
              nodeId: 'node-2',
              growthBytesPerDay: 100,
              filesystemFreeBytes: 1000,
              filesystemTotalBytes: 10000,
              freeSpaceThresholdPercent: 20,
            }),
          ],
        }),
      ),
    ).toBe('Free space is already below the 20% threshold on node-2')
  })

  it('says the disk is full when no reserve is kept and none is left', () => {
    expect(
      logsOverviewForecastNote(
        overview({
          nodes: [
            node({
              nodeId: 'node-2',
              growthBytesPerDay: 100,
              filesystemFreeBytes: 0,
              filesystemTotalBytes: 10000,
              freeSpaceThresholdPercent: 0,
            }),
          ],
        }),
      ),
    ).toBe('The log disk is full on node-2')
  })

  // The line is about the machine in trouble, not about the fleet: a cluster answers
  // with the node that runs out first, and a node past its threshold outranks any
  // number of days.
  it('speaks for the node with the least time left', () => {
    expect(
      logsOverviewForecastNote(
        overview({
          nodes: [
            node({
              nodeId: 'node-1',
              growthBytesPerDay: 100,
              filesystemFreeBytes: 5400,
              filesystemTotalBytes: 10000,
              freeSpaceThresholdPercent: 20,
            }),
            node({
              nodeId: 'node-3',
              growthBytesPerDay: 100,
              filesystemFreeBytes: 3200,
              filesystemTotalBytes: 10000,
              freeSpaceThresholdPercent: 20,
            }),
          ],
        }),
      ),
    ).toBe('At this rate the 20% threshold is 12 days away on node-3')
  })

  it('lets a node already past its threshold speak before one with days', () => {
    expect(
      logsOverviewForecastNote(
        overview({
          nodes: [
            node({
              nodeId: 'node-1',
              growthBytesPerDay: 100,
              filesystemFreeBytes: 5400,
              filesystemTotalBytes: 10000,
              freeSpaceThresholdPercent: 20,
            }),
            node({
              nodeId: 'node-3',
              growthBytesPerDay: 100,
              filesystemFreeBytes: 900,
              filesystemTotalBytes: 10000,
              freeSpaceThresholdPercent: 20,
            }),
          ],
        }),
      ),
    ).toBe('Free space is already below the 20% threshold on node-3')
  })

  it('leaves an unreadable node out of the choice', () => {
    expect(
      logsOverviewForecastNote(
        overview({
          nodes: [
            node({
              nodeId: 'node-1',
              growthBytesPerDay: 100,
              filesystemFreeBytes: 5400,
              filesystemTotalBytes: 10000,
              freeSpaceThresholdPercent: 20,
            }),
            node({
              nodeId: 'node-3',
              available: false,
              growthBytesPerDay: 100,
              filesystemFreeBytes: 900,
              filesystemTotalBytes: 10000,
              freeSpaceThresholdPercent: 20,
            }),
          ],
        }),
      ),
    ).toBe('At this rate the 20% threshold is 34 days away on node-1')
  })

  // Three of the four silences, and each is the honest answer: the tile already says
  // it is still measuring, there is nothing to divide by, and a guess about a
  // filesystem that did not answer costs more than a blank.
  it('says nothing while the rate is still being measured', () => {
    expect(
      logsOverviewForecastNote(
        overview({
          growthBytesPerDay: null,
          filesystemFreeBytes: 5400,
          filesystemTotalBytes: 10000,
          freeSpaceThresholdPercent: 20,
        }),
      ),
    ).toBeNull()
  })

  it('says nothing when nothing is being written', () => {
    expect(
      logsOverviewForecastNote(
        overview({
          growthBytesPerDay: 0,
          filesystemFreeBytes: 5400,
          filesystemTotalBytes: 10000,
          freeSpaceThresholdPercent: 20,
        }),
      ),
    ).toBeNull()
  })

  it('says nothing when the room is not known', () => {
    expect(
      logsOverviewForecastNote(overview({ growthBytesPerDay: 100 })),
    ).toBeNull()
  })

  it('says nothing before the first frame and while the picture is unreadable', () => {
    expect(logsOverviewForecastNote(null)).toBeNull()
    expect(
      logsOverviewForecastNote(
        overview({
          available: false,
          growthBytesPerDay: 100,
          filesystemFreeBytes: 5400,
          filesystemTotalBytes: 10000,
          freeSpaceThresholdPercent: 20,
        }),
      ),
    ).toBeNull()
  })

  // It stands beside the qualifying note rather than instead of it: the rate is there
  // to divide by, and how complete it is has been said by the line above.
  it('is shown even while some streams have no full day of data', () => {
    expect(
      logsOverviewForecastNote(
        overview({
          growthBytesPerDay: 100,
          keysWithoutGrowthWindow: 3,
          filesystemFreeBytes: 5400,
          filesystemTotalBytes: 10000,
          freeSpaceThresholdPercent: 20,
        }),
      ),
    ).toBe('At this rate the 20% threshold is 34 days away')
  })
})

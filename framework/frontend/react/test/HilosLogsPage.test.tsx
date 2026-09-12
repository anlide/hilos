import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, render } from '@testing-library/react'
import { HilosPages, OVERVIEW_SIGNAL, createSignal } from '@hilos/core'
import type {
  HilosConnection,
  HilosLogsOverview,
  HilosLogsOverviewError,
  HilosLogsOverviewNode,
  HilosPageIdentity,
  HilosRouter,
  PageRouteMatch,
} from '@hilos/core'

import { HilosLogsPage } from '../src/admin/logs/HilosLogsPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

/** One node's row as the backend puts it on the wire. */
function node(
  overrides: Partial<HilosLogsOverviewNode> = {},
): HilosLogsOverviewNode {
  return {
    nodeId: 'node-1',
    available: true,
    lastRotationAt: '2026-09-02T03:00:00+00:00',
    liveBytes: 210 * 1024 * 1024,
    archiveBytes: 1024 * 1024 * 1024,
    growthBytesPerDay: 190 * 1024 * 1024,
    batchesDueForTakeout: 0,
    filesystemFreeBytes: null,
    filesystemTotalBytes: null,
    freeSpaceThresholdPercent: null,
    ...overrides,
  }
}

/** The overview as the page answers a subscription with it. */
function overview(
  overrides: Partial<HilosLogsOverview> = {},
): HilosLogsOverview {
  return {
    available: true,
    totalRotationsAllTime: 384,
    lastRotationAt: '2026-09-02T03:00:00+00:00',
    logKeysPerAgent: 14,
    totalWeightAgentKeysBytes: 940 * 1024 * 1024,
    logKeysPerWorker: 9,
    totalWeightWorkerKeysBytes: 3 * 1024 * 1024 * 1024,
    growthBytesPerDay: 620 * 1024 * 1024,
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

/** One failure of the recent-errors panel. */
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

/**
 * The identity the section root answers with: the chain above it and the cards
 * to its five child screens. The overview draws its own figures on top of them,
 * so both have to be there at once.
 */
const SECTION_IDENTITY: HilosPageIdentity = {
  label: 'Logs',
  lead: 'What the journals weigh and where they are rotated.',
  breadcrumb: [{ page: HilosPages.LOGS, label: 'Logs' }],
  children: [
    {
      page: HilosPages.LOGS_KEYS,
      label: 'By key',
      lead: 'Every log key an agent writes.',
      icon: null,
    },
    {
      page: HilosPages.LOGS_ROTATIONS,
      label: 'Rotations',
      lead: 'The history of rotation batches.',
      icon: null,
    },
  ],
}

function router(identity: HilosPageIdentity | undefined): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.LOGS,
      params: {},
      admin: true,
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(identity),
    dashboardSections: createSignal(undefined),
    resolvePath: (page) => `/hilos/${page}`,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

/**
 * A connection stub handing back the one frame this screen lives on. There is no
 * second one and no window: the tiles, the takeout verdict and the per-node rows
 * all ride the page's own signal.
 */
function makeConnection(): {
  connection: HilosConnection
  push: (frame: HilosLogsOverview) => void
} {
  const listeners: ((signal: { type: string; data: unknown }) => void)[] = []
  const connection = {
    on(event: string, listener: (signal: never) => void): () => void {
      if (event === 'projectSignal') {
        listeners.push(
          listener as unknown as (signal: {
            type: string
            data: unknown
          }) => void,
        )
      }

      return () => {}
    },
  } as unknown as HilosConnection

  return {
    connection,
    push(frame: HilosLogsOverview): void {
      act(() => {
        for (const listener of listeners) {
          listener({ type: OVERVIEW_SIGNAL, data: frame })
        }
      })
    },
  }
}

function mountPage(
  connection: HilosConnection,
  identity: HilosPageIdentity | undefined = undefined,
): HTMLElement {
  return render(
    <HilosRouterContext.Provider value={router(identity)}>
      <HilosLogsPage context={{ connection }} />
    </HilosRouterContext.Provider>,
  ).container
}

function byId(container: HTMLElement, id: string): HTMLElement | null {
  return container.querySelector(`[data-id="${id}"]`)
}

function textOf(container: HTMLElement, id: string): string {
  return byId(container, id)?.textContent ?? ''
}

describe('HilosLogsPage', () => {
  afterEach(cleanup)

  it('keeps the cards to its child screens and puts its own figures under them', () => {
    // The section root is the one place both are needed: without the cards the
    // five child screens are reachable by typed address only, and without the
    // figures the overview is not an overview. The rest of this file mounts the
    // page with no identity, where the cards are absent from sound and broken
    // code alike — which is how the defect survived seventy-five scenarios.
    const { connection } = makeConnection()
    const container = mountPage(connection, SECTION_IDENTITY)

    expect(byId(container, 'hilos-admin-children')).not.toBeNull()
    expect(
      byId(container, `hilos-admin-child-${HilosPages.LOGS_KEYS}`),
    ).not.toBeNull()
    expect(byId(container, 'hilos-logs-tiles')).not.toBeNull()
  })

  it('keeps the tiles empty rather than zero before any picture arrives', () => {
    const { connection } = makeConnection()
    const container = mountPage(connection)

    expect(byId(container, 'hilos-logs-empty-unknown')).not.toBeNull()
    expect(textOf(container, 'hilos-logs-tile-rotation')).toBe('—')
    expect(textOf(container, 'hilos-logs-tile-growth')).toBe('—')
    expect(textOf(container, 'hilos-logs-tile-agents')).toContain('—')
  })

  it('keeps the tiles empty in the fault state too, where a zero would be a claim', () => {
    const { connection, push } = makeConnection()
    const container = mountPage(connection)

    push(
      overview({
        available: false,
        totalRotationsAllTime: null,
        lastRotationAt: null,
        logKeysPerAgent: null,
        totalWeightAgentKeysBytes: null,
        logKeysPerWorker: null,
        totalWeightWorkerKeysBytes: null,
        growthBytesPerDay: null,
        keysWithoutGrowthWindow: null,
        batchesDueForTakeout: null,
      }),
    )

    expect(byId(container, 'hilos-logs-empty-unreadable')).not.toBeNull()
    expect(textOf(container, 'hilos-logs-tile-rotation')).toBe('—')
    expect(textOf(container, 'hilos-logs-tile-workers')).toContain('—')
  })

  it('drops the per-node table entirely in a single-node installation', () => {
    const { connection, push } = makeConnection()
    const container = mountPage(connection)

    push(overview({ nodes: [] }))

    expect(container.querySelector('table')).toBeNull()
    expect(byId(container, 'hilos-logs-empty-unknown')).toBeNull()
  })

  it('draws a row per named node once the picture has them', () => {
    const { connection, push } = makeConnection()
    const container = mountPage(connection)

    push(
      overview({
        nodes: [node({ nodeId: 'node-1' }), node({ nodeId: 'node-2' })],
      }),
    )

    expect(byId(container, 'hilos-logs-node-node-1')).not.toBeNull()
    expect(byId(container, 'hilos-logs-node-node-2')).not.toBeNull()
  })

  it('says so once for a node that could not read its own store', () => {
    const { connection, push } = makeConnection()
    const container = mountPage(connection)

    push(
      overview({
        nodes: [
          node({ nodeId: 'node-1' }),
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

    expect(byId(container, 'hilos-logs-node-nodata-node-2')).not.toBeNull()
    expect(byId(container, 'hilos-logs-node-nodata-node-1')).toBeNull()
  })

  it('draws no panel of failures in either empty state, where good news would be made up', () => {
    const { connection, push } = makeConnection()
    const container = mountPage(connection)

    expect(byId(container, 'hilos-logs-recent-errors-empty')).toBeNull()

    push(overview({ available: false }))

    expect(byId(container, 'hilos-logs-recent-errors-empty')).toBeNull()
    expect(byId(container, 'hilos-logs-recent-errors')).toBeNull()
  })

  it('says the hour was quiet in words, rather than showing an empty list', () => {
    const { connection, push } = makeConnection()
    const container = mountPage(connection)

    push(overview({ recentErrors: [] }))

    expect(textOf(container, 'hilos-logs-recent-errors-empty')).toContain(
      'No errors in the last hour',
    )
    expect(byId(container, 'hilos-logs-recent-errors')).toBeNull()
  })

  it('leads a row into the viewer on the file that line is in', () => {
    const { connection, push } = makeConnection()
    const container = mountPage(connection)

    push(
      overview({ recentErrors: [failure({ stream: 'worker-0.error.log' })] }),
    )

    const row = byId(container, 'hilos-logs-recent-error')
    expect(row?.getAttribute('href')).toBe(
      '/hilos/logs/view/-/live/worker-0.error.log',
    )
    expect(row?.textContent).toContain('login action failed')
  })

  it('badges only the failure that has a stack behind it', () => {
    const { connection, push } = makeConnection()
    const container = mountPage(connection)

    push(
      overview({
        recentErrors: [
          failure({ traceFrames: 3 }),
          failure({ traceFrames: null }),
        ],
      }),
    )

    const badges = container.querySelectorAll(
      '[data-id="hilos-logs-recent-error-trace"]',
    )
    expect(badges).toHaveLength(1)
    expect(badges[0].textContent).toContain('3')
    expect(textOf(container, 'hilos-logs-recent-errors-count')).toBe('2')
  })

  it('leaves the takeout banner out entirely when nothing is waiting', () => {
    const { connection, push } = makeConnection()
    const container = mountPage(connection)

    push(overview({ batchesDueForTakeout: 0 }))

    expect(byId(container, 'hilos-logs-takeout')).toBeNull()
  })

  it('says the singular where the count is one, in the banner and in the tile alike', () => {
    const { connection, push } = makeConnection()
    const container = mountPage(connection)

    push(overview({ totalRotationsAllTime: 1, batchesDueForTakeout: 1 }))

    expect(textOf(container, 'hilos-logs-takeout')).toContain(
      '1 batch is waiting',
    )
    expect(textOf(container, 'hilos-logs-tiles')).toContain('1 batch so far')
  })

  it('names the nodes the waiting batches are lying on', () => {
    const { connection, push } = makeConnection()
    const container = mountPage(connection)

    push(
      overview({
        batchesDueForTakeout: 3,
        nodes: [
          node({ nodeId: 'node-1', batchesDueForTakeout: 0 }),
          node({ nodeId: 'node-2', batchesDueForTakeout: 2 }),
          node({ nodeId: 'node-3', batchesDueForTakeout: 1 }),
        ],
      }),
    )

    const banner = byId(container, 'hilos-logs-takeout')
    expect(banner).not.toBeNull()
    expect(banner?.textContent).toContain('node-2, node-3')
    expect(banner?.textContent).not.toContain('node-1')
  })

  it('takes both ways off the screen to the rotation history', () => {
    const { connection, push } = makeConnection()
    const container = mountPage(connection)

    push(
      overview({
        batchesDueForTakeout: 2,
        nodes: [node({ nodeId: 'node-2', batchesDueForTakeout: 2 })],
      }),
    )

    expect(
      byId(container, 'hilos-logs-takeout-open')?.getAttribute('href'),
    ).toBe('/hilos/logs/rotations')
    expect(
      byId(container, 'hilos-logs-node-due-node-2')?.getAttribute('href'),
    ).toBe('/hilos/logs/rotations')
  })

  it('shows the growth in its three positions and never as a zero', () => {
    const { connection, push } = makeConnection()
    const container = mountPage(connection)

    push(overview({ growthBytesPerDay: 2048, keysWithoutGrowthWindow: 0 }))
    expect(textOf(container, 'hilos-logs-tile-growth')).toBe('2.0 KB')
    expect(byId(container, 'hilos-logs-growth-note')).toBeNull()

    push(overview({ growthBytesPerDay: 2048, keysWithoutGrowthWindow: 3 }))
    expect(textOf(container, 'hilos-logs-tile-growth')).toBe('2.0 KB')
    expect(textOf(container, 'hilos-logs-growth-note')).toContain('3 streams')

    push(overview({ growthBytesPerDay: null, keysWithoutGrowthWindow: 5 }))
    expect(textOf(container, 'hilos-logs-tile-growth')).toBe('Still measuring')
    expect(byId(container, 'hilos-logs-growth-note')).toBeNull()
  })

  // The forecast stands under the caveat and appears only where the room is known:
  // it is what the growth figure MEANS, and the tile changes neither color nor
  // shape for it.
  it('draws the forecast under the growth note, and only with room to forecast', () => {
    const { connection, push } = makeConnection()
    const container = mountPage(connection)

    push(overview({ growthBytesPerDay: 100, keysWithoutGrowthWindow: 3 }))
    expect(byId(container, 'hilos-logs-growth-forecast')).toBeNull()

    push(
      overview({
        growthBytesPerDay: 100,
        keysWithoutGrowthWindow: 3,
        filesystemFreeBytes: 5400,
        filesystemTotalBytes: 10000,
        freeSpaceThresholdPercent: 20,
      }),
    )
    expect(textOf(container, 'hilos-logs-growth-forecast')).toBe(
      'At this rate the 20% threshold is 34 days away',
    )
    expect(textOf(container, 'hilos-logs-growth-note')).toContain('3 streams')
  })
})

import { afterEach, describe, expect, it } from 'vitest'
import {
  act,
  cleanup,
  fireEvent,
  render,
  screen,
  within,
} from '@testing-library/react'
import {
  formatLogsOverviewRecentAt,
  formatLogsOverviewRotationAt,
} from '@hilos/core/admin/logs'
import {
  HIDDEN_VALUE,
  HilosPages,
  OVERVIEW_SIGNAL,
  SIGNAL_TYPE_PAGE_RESPONSE,
  ScopeManager,
  createSignal,
} from '@hilos/core'
import {
  formatMoment,
  hilosBrowserTimeZone,
  hilosTimeZone,
} from '@hilos/core/base'
import type { HilosLogNodeRow } from '@hilos/core/admin/logs'
import type {
  HilosConnection,
  HilosLogsOverview,
  HilosLogsOverviewRecentEntry,
  HilosLogsOverviewNode,
  HilosRouter,
  PageRouteMatch,
} from '@hilos/core'

import { HilosLogsPage } from '../src/admin/logs/HilosLogsPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'
import { readerTimeZone } from './readerTimeZone.js'

/** One node's row as the backend puts it on the wire. */
function node(
  overrides: Partial<HilosLogsOverviewNode> = {},
): HilosLogsOverviewNode {
  return {
    nodeId: 'node-1',
    available: true,
    lastRotationAt: '2026-09-02T03:00:00.000Z',
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

/** One row as the page-scoped node table receives it. */
function tableNode(overrides: Partial<HilosLogNodeRow> = {}): HilosLogNodeRow {
  return {
    nodeId: 'node-1',
    available: true,
    lastRotationAt: Date.parse('2026-09-02T03:00:00.000Z'),
    liveBytes: 210 * 1024 * 1024,
    archiveBytes: 1024 * 1024 * 1024,
    growthBytesPerDay: 190 * 1024 * 1024,
    batchesDueForTakeout: 0,
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
    lastRotationAt: '2026-09-02T03:00:00.000Z',
    logKeysPerDaemon: 4,
    totalWeightDaemonKeysBytes: 220 * 1024 * 1024,
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
    recentWarnings: [],
    recentWarningsCapped: false,
    filesystemFreeBytes: null,
    filesystemTotalBytes: null,
    freeSpaceThresholdPercent: null,
    ...overrides,
  }
}

/** One entry of the recent-failures panel. */
function failure(
  overrides: Partial<HilosLogsOverviewRecentEntry> = {},
): HilosLogsOverviewRecentEntry {
  return {
    nodeId: 'standalone',
    stream: 'worker-monopolistic-5.error.log',
    at: '2026-09-06T10:00:02.125Z',
    message: 'login action failed',
    traceFrames: 3,
    ...overrides,
  }
}

function router(): HilosRouter {
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
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: (page) => `/hilos/${page}`,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

/** The overview signal and the first and later windows of the node table. */
function makeConnection(): {
  connection: HilosConnection
  push: (frame: HilosLogsOverview) => void
  pushPageWindow: (rows: HilosLogNodeRow[], totalCount?: number) => void
  pushWindow: (rows: HilosLogNodeRow[], totalCount?: number) => void
  listenerRegistrations: () => number
  pushedFrames: () => number
  pushedWindows: () => number
  viewportRequests: () => number
} {
  const projectListeners: ((signal: {
    type: string
    data: unknown
  }) => void)[] = []
  const windowListeners: ((signal: { data: unknown }) => void)[] = []
  let listenerRegistrations = 0
  let pushedFrames = 0
  let pushedWindows = 0
  let viewportRequestCount = 0

  const windowData = (rows: HilosLogNodeRow[], totalCount = rows.length) => ({
    rows: rows.map((row) => ({
      rowKey: row.nodeId,
      slots: { node: { ...row } },
    })),
    totalCount,
    totalExact: true,
    sort: [{ field: 'nodeId', direction: 'asc' as const }],
    limit: 25,
    rowsBefore: 0,
    firstAnchor: rows.length ? { nodeId: rows[0].nodeId } : null,
    lastAnchor: rows.length ? { nodeId: rows.at(-1)?.nodeId } : null,
  })

  const connection = {
    on(event: string, listener: (signal: never) => void): () => void {
      if (event === 'projectSignal') {
        listenerRegistrations += 1
        projectListeners.push(
          listener as unknown as (signal: {
            type: string
            data: unknown
          }) => void,
        )
        return () => {
          listenerRegistrations -= 1
          projectListeners.splice(
            projectListeners.indexOf(
              listener as unknown as (signal: {
                type: string
                data: unknown
              }) => void,
            ),
            1,
          )
        }
      }

      if (event === 'tableWindow') {
        const callback = listener as unknown as (signal: {
          data: unknown
        }) => void
        windowListeners.push(callback)
        return () => {
          windowListeners.splice(windowListeners.indexOf(callback), 1)
        }
      }

      return () => {}
    },
    registerTableWindow: () => {},
    unregisterTableWindow: () => {},
    sendTableViewport: () => {
      viewportRequestCount += 1
      return true
    },
    sendTableRendered: () => true,
    sendTableFacets: () => true,
    tableWindowDescriptors: () => ({}),
  } as unknown as HilosConnection

  return {
    connection,
    listenerRegistrations: () => listenerRegistrations,
    pushedFrames: () => pushedFrames,
    pushedWindows: () => pushedWindows,
    viewportRequests: () => viewportRequestCount,
    push(frame: HilosLogsOverview): void {
      pushedFrames += 1
      act(() => {
        for (const listener of projectListeners) {
          listener({ type: OVERVIEW_SIGNAL, data: frame })
        }
      })
    },
    pushPageWindow(rows: HilosLogNodeRow[], totalCount = rows.length): void {
      pushedWindows += 1
      const data = {
        page: HilosPages.LOGS,
        payload: { windows: { hilosLogNodes: windowData(rows, totalCount) } },
      }
      act(() => {
        for (const listener of projectListeners) {
          listener({ type: SIGNAL_TYPE_PAGE_RESPONSE, data })
        }
      })
    },
    pushWindow(rows: HilosLogNodeRow[], totalCount = rows.length): void {
      pushedWindows += 1
      const data = {
        page: HilosPages.LOGS,
        tableKey: 'hilosLogNodes',
        ...windowData(rows, totalCount),
      }
      act(() => {
        for (const listener of windowListeners) {
          listener({ data })
        }
      })
    },
  }
}

function mountPage(connection: HilosConnection): HTMLElement {
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.LOGS)

  return render(
    <HilosRouterContext.Provider value={router()}>
      <HilosLogsPage context={{ connection, scopes }} />
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

  it('reformats overview and node moments live without another frame or window', () => {
    const reader = readerTimeZone()
    const browserZone = hilosBrowserTimeZone.get() ?? 'UTC'
    const initialZone =
      browserZone === 'Asia/Tokyo' ? 'Pacific/Honolulu' : 'Asia/Tokyo'
    const nextZone =
      browserZone === 'America/New_York' ? 'Europe/Warsaw' : 'America/New_York'
    act(() => reader.choose(initialZone))

    const moment = '2026-10-24T03:30:00.000Z'
    const {
      connection,
      push,
      pushPageWindow,
      listenerRegistrations,
      pushedFrames,
      pushedWindows,
      viewportRequests,
    } = makeConnection()
    const container = mountPage(connection)

    try {
      push(
        overview({
          lastRotationAt: moment,
          nodes: [
            node({ nodeId: 'node-1', lastRotationAt: moment }),
            node({ nodeId: 'node-2', lastRotationAt: 'not-a-moment' }),
          ],
          recentErrors: [failure()],
          recentWarnings: [
            failure({ at: moment, message: 'Slow query' }),
            failure({ at: 'not-a-moment', message: 'Unparseable time' }),
          ],
        }),
      )
      pushPageWindow(
        [
          tableNode({ nodeId: 'node-1', lastRotationAt: Date.parse(moment) }),
          tableNode({ nodeId: 'node-2', lastRotationAt: null }),
        ],
        2,
      )
      fireEvent.click(byId(container, 'hilos-logs-recent-tab-warnings')!)

      const rotation = textOf(container, 'hilos-logs-tile-rotation')
      const nodeRow = screen.getByRole('row', { name: /node-1/ })
      const nodeRotation = byId(container, 'hilos-logs-node-rotation-node-1')
      const unknownRotation = byId(container, 'hilos-logs-node-rotation-node-2')
      const recentRows = container.querySelectorAll(
        '[data-id="hilos-logs-recent-row"]',
      )
      const recentRow = recentRows[0] as HTMLElement
      const invalidRecentRow = recentRows[1] as HTMLElement
      const warningTab = byId(container, 'hilos-logs-recent-tab-warnings')
      const daemonTile = textOf(container, 'hilos-logs-tile-daemon')
      const rotationBefore = formatLogsOverviewRotationAt(moment)
      const nodeRotationBefore =
        formatMoment(Date.parse(moment), { kind: 'dateTime' }) ?? '—'
      const recentBefore = formatLogsOverviewRecentAt(moment)
      const registrationsBeforeZoneChange = listenerRegistrations()
      const framesBeforeZoneChange = pushedFrames()
      const windowsBeforeZoneChange = pushedWindows()
      const viewportRequestsBeforeZoneChange = viewportRequests()

      expect(rotation).toBe(rotationBefore)
      expect(nodeRow).toBeTruthy()
      expect(nodeRotation?.textContent).toBe(nodeRotationBefore)
      expect(unknownRotation?.textContent).toBe('—')
      expect(recentRow.children[1]?.textContent).toBe(recentBefore)
      expect(invalidRecentRow.children[1]?.textContent).toBe('—')
      expect(warningTab?.getAttribute('aria-selected')).toBe('true')

      act(() => reader.choose(nextZone))

      expect(hilosTimeZone.get()).toBe(nextZone)
      expect(nextZone).not.toBe(browserZone)
      expect(pushedFrames()).toBe(framesBeforeZoneChange)
      expect(pushedWindows()).toBe(windowsBeforeZoneChange)
      expect(viewportRequests()).toBe(viewportRequestsBeforeZoneChange)
      expect(listenerRegistrations()).toBe(registrationsBeforeZoneChange)
      expect(screen.getByRole('row', { name: /node-1/ })).toBeTruthy()
      expect(container.querySelector('[data-id="hilos-logs-recent-row"]')).toBe(
        recentRow,
      )
      expect(textOf(container, 'hilos-logs-tile-rotation')).toBe(
        formatLogsOverviewRotationAt(moment),
      )
      expect(textOf(container, 'hilos-logs-tile-rotation')).not.toBe(
        rotationBefore,
      )
      const nodeRotationAfter =
        formatMoment(Date.parse(moment), { kind: 'dateTime' }) ?? '—'
      expect(nodeRotation?.textContent).toBe(nodeRotationAfter)
      expect(nodeRotation?.textContent).not.toBe(nodeRotationBefore)
      expect(recentRow.children[1]?.textContent).toBe(
        formatLogsOverviewRecentAt(moment),
      )
      expect(recentRow.children[1]?.textContent).not.toBe(recentBefore)
      expect(unknownRotation?.textContent).toBe('—')
      expect(invalidRecentRow.children[1]?.textContent).toBe('—')
      expect(byId(container, 'hilos-logs-recent-tab-warnings')).toBe(warningTab)
      expect(warningTab?.getAttribute('aria-selected')).toBe('true')
      expect(textOf(container, 'hilos-logs-tile-daemon')).toBe(daemonTile)
      expect(daemonTile).toContain('4')
      expect(daemonTile).toContain('220.0 MB')
    } finally {
      cleanup()
      reader.release()
    }
  })

  it('draws a hidden recent message and forecast as separate marks', () => {
    const { connection, push } = makeConnection()
    const container = mountPage(connection)
    push(
      overview({
        filesystemFreeBytes: 1_000_000,
        filesystemTotalBytes: 2_000_000,
        freeSpaceThresholdPercent: HIDDEN_VALUE,
        recentErrors: [failure({ message: HIDDEN_VALUE })],
      }),
    )

    expect(
      byId(container, 'hilos-logs-growth-forecast')?.querySelector(
        '[data-id="hilos-hidden"]',
      ),
    ).not.toBeNull()
    expect(
      byId(container, 'hilos-logs-recent-row')?.querySelector(
        '[data-id="hilos-hidden"]',
      ),
    ).not.toBeNull()
    expect(textOf(container, 'hilos-logs-growth-forecast')).not.toContain('14%')
  })

  it('keeps the tiles empty rather than zero before any picture arrives', () => {
    const { connection } = makeConnection()
    const container = mountPage(connection)

    expect(byId(container, 'hilos-logs-empty-unknown')).not.toBeNull()
    expect(textOf(container, 'hilos-logs-tile-rotation')).toBe('—')
    expect(textOf(container, 'hilos-logs-tile-growth')).toBe('—')
    expect(textOf(container, 'hilos-logs-tile-agents')).toContain('—')
    expect(textOf(container, 'hilos-logs-tile-daemon')).toContain('—')
  })

  it('draws the daemon streams as a class of their own, count and weight together', () => {
    const { connection, push } = makeConnection()
    const container = mountPage(connection)

    push(overview())

    expect(textOf(container, 'hilos-logs-tile-daemon')).toContain('4')
    expect(textOf(container, 'hilos-logs-tile-daemon')).toContain('220.0 MB')
  })

  it('keeps the tiles empty in the fault state too, where a zero would be a claim', () => {
    const { connection, push } = makeConnection()
    const container = mountPage(connection)

    push(
      overview({
        available: false,
        totalRotationsAllTime: null,
        lastRotationAt: null,
        logKeysPerDaemon: null,
        totalWeightDaemonKeysBytes: null,
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
    expect(textOf(container, 'hilos-logs-tile-daemon')).toContain('—')
  })

  it('keeps node rows out of a standalone overview', () => {
    const { connection, push, pushPageWindow } = makeConnection()
    const container = mountPage(connection)

    push(overview({ nodes: [] }))
    // Even a stale or malformed row window cannot turn a standalone overview into
    // a by-node page.
    pushPageWindow([tableNode({ nodeId: 'standalone' })])

    expect(screen.queryByRole('row', { name: /standalone/ })).toBeNull()
    expect(byId(container, 'hilos-logs-empty-unknown')).toBeNull()
  })

  it('reads rows from the table window while the overview carries the full node set', () => {
    const { connection, push, pushPageWindow, pushWindow, viewportRequests } =
      makeConnection()
    mountPage(connection)

    push(
      overview({
        nodes: [node({ nodeId: 'node-1' }), node({ nodeId: 'node-2' })],
      }),
    )
    pushPageWindow([tableNode({ nodeId: 'node-2' })], 2)

    expect(screen.getByRole('row', { name: /node-2/ })).toBeTruthy()
    expect(screen.queryByRole('row', { name: /node-1/ })).toBeNull()
    expect(viewportRequests()).toBe(0)

    pushWindow([tableNode({ nodeId: 'node-1' })], 2)

    expect(screen.getByRole('row', { name: /node-1/ })).toBeTruthy()
    expect(screen.queryByRole('row', { name: /node-2/ })).toBeNull()
  })

  it('says so once for a node that could not read its own store', () => {
    const { connection, push, pushPageWindow } = makeConnection()
    mountPage(connection)

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
    pushPageWindow([
      tableNode({
        nodeId: 'node-2',
        available: false,
        lastRotationAt: null,
        liveBytes: null,
        archiveBytes: null,
        growthBytesPerDay: null,
        batchesDueForTakeout: null,
      }),
    ])

    const message = 'No data — this node could not read its log store'
    const unavailableRow = screen.getByRole('row', {
      name: /node-2.*No data/,
    })
    expect(unavailableRow).toBeTruthy()
    expect(within(unavailableRow).getByText(message)).toBeTruthy()
  })

  it('keeps unknown node figures distinct from measured zero', () => {
    const { connection, push, pushPageWindow } = makeConnection()
    const container = mountPage(connection)

    push(
      overview({
        nodes: [node({ nodeId: 'node-1' }), node({ nodeId: 'node-2' })],
      }),
    )
    pushPageWindow([
      tableNode({
        nodeId: 'node-1',
        lastRotationAt: null,
        liveBytes: null,
        archiveBytes: null,
        growthBytesPerDay: null,
        batchesDueForTakeout: null,
      }),
      tableNode({
        nodeId: 'node-2',
        lastRotationAt: null,
        liveBytes: 0,
        archiveBytes: null,
        growthBytesPerDay: null,
        batchesDueForTakeout: null,
      }),
    ])

    expect(textOf(container, 'hilos-logs-node-live-node-1')).toBe('—')
    expect(textOf(container, 'hilos-logs-node-live-node-2')).toBe('0 B')
  })

  it('draws no panel of failures in either empty state, where good news would be made up', () => {
    const { connection, push } = makeConnection()
    const container = mountPage(connection)

    expect(byId(container, 'hilos-logs-recent-empty')).toBeNull()

    push(overview({ available: false }))

    expect(byId(container, 'hilos-logs-recent-empty')).toBeNull()
    expect(byId(container, 'hilos-logs-recent')).toBeNull()
  })

  it('says the hour was quiet in words, rather than showing an empty list', () => {
    const { connection, push } = makeConnection()
    const container = mountPage(connection)

    push(overview({ recentErrors: [] }))

    expect(textOf(container, 'hilos-logs-recent-empty')).toContain(
      'No errors in the last hour',
    )
    expect(byId(container, 'hilos-logs-recent-row')).toBeNull()
  })

  it('leads a row into the viewer on the line itself', () => {
    const { connection, push } = makeConnection()
    const container = mountPage(connection)

    push(
      overview({ recentErrors: [failure({ stream: 'worker-0.error.log' })] }),
    )

    const row = byId(container, 'hilos-logs-recent-row')
    expect(row?.getAttribute('href')).toBe(
      `/hilos/logs/view/standalone/live/worker-0.error.log/${Date.parse('2026-09-06T10:00:02.125Z')}`,
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
      '[data-id="hilos-logs-recent-row-trace"]',
    )
    expect(badges).toHaveLength(1)
    expect(badges[0].textContent).toContain('3')
    expect(textOf(container, 'hilos-logs-recent-count-errors')).toBe('2')
  })

  it('draws both tabs, each counting its own list by its own flag', () => {
    const { connection, push } = makeConnection()
    const container = mountPage(connection)

    push(
      overview({
        recentErrors: [failure()],
        recentWarnings: [failure(), failure()],
        recentWarningsCapped: true,
      }),
    )

    expect(textOf(container, 'hilos-logs-recent-count-errors')).toBe('1')
    expect(textOf(container, 'hilos-logs-recent-count-warnings')).toBe('2+')
    expect(
      byId(container, 'hilos-logs-recent-tab-errors')?.getAttribute(
        'aria-selected',
      ),
    ).toBe('true')
  })

  it('shows the warnings on a click of their tab, from the frame it already holds', () => {
    // The connection stub can send nothing: a tab that asked the server would throw.
    const { connection, push } = makeConnection()
    const container = mountPage(connection)
    push(
      overview({
        recentErrors: [failure({ message: 'login action failed' })],
        recentWarnings: [failure({ message: 'slow query', traceFrames: null })],
      }),
    )

    act(() => {
      byId(container, 'hilos-logs-recent-tab-warnings')?.click()
    })

    const rows = container.querySelectorAll('[data-id="hilos-logs-recent-row"]')
    expect(rows).toHaveLength(1)
    expect(rows[0].textContent).toContain('slow query')
    expect(rows[0].textContent).toContain('WARNING')
  })

  it('gives each tab its own good news', () => {
    const { connection, push } = makeConnection()
    const container = mountPage(connection)
    push(overview({ recentErrors: [failure()] }))

    act(() => {
      byId(container, 'hilos-logs-recent-tab-warnings')?.click()
    })

    expect(textOf(container, 'hilos-logs-recent-empty')).toContain(
      'No warnings in the last hour',
    )
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

  it('takes both ways off the screen to the rotations awaiting carry-off', () => {
    const { connection, push, pushPageWindow } = makeConnection()
    mountPage(connection)

    push(
      overview({
        batchesDueForTakeout: 2,
        nodes: [node({ nodeId: 'node-2', batchesDueForTakeout: 2 })],
      }),
    )
    pushPageWindow([tableNode({ nodeId: 'node-2', batchesDueForTakeout: 2 })])

    expect(
      screen.getByRole('link', { name: 'Show them' }).getAttribute('href'),
    ).toBe('/hilos/logs/rotations/due')
    expect(
      within(screen.getByRole('row', { name: /node-2/ }))
        .getByRole('link', { name: '2' })
        .getAttribute('href'),
    ).toBe('/hilos/logs/rotations/due')
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

  it('names a passed threshold and marks its warning icon as decorative', () => {
    const { connection, push } = makeConnection()
    const container = mountPage(connection)

    push(
      overview({
        nodes: [
          node({ nodeId: 'node-1' }),
          node({
            nodeId: 'node-2',
            filesystemFreeBytes: 1400,
            filesystemTotalBytes: 10000,
            freeSpaceThresholdPercent: 20,
            growthBytesPerDay: null,
          }),
        ],
      }),
    )

    const forecast = byId(container, 'hilos-logs-growth-forecast')
    expect(forecast?.textContent).toBe(
      'Free space is already below the 20% threshold on node-2 — 14% free',
    )
    expect(forecast?.querySelector('i')?.getAttribute('aria-hidden')).toBe(
      'true',
    )
  })

  it('names a full disk at zero threshold, with a node only in a cluster', () => {
    const { connection, push } = makeConnection()
    const container = mountPage(connection)

    push(
      overview({
        nodes: [
          node({
            nodeId: 'node-2',
            filesystemFreeBytes: 0,
            filesystemTotalBytes: 10000,
            freeSpaceThresholdPercent: 0,
            growthBytesPerDay: null,
          }),
        ],
      }),
    )
    expect(textOf(container, 'hilos-logs-growth-forecast')).toBe(
      'The log disk is full on node-2 — 0% free',
    )

    push(
      overview({
        growthBytesPerDay: null,
        filesystemFreeBytes: 0,
        filesystemTotalBytes: 10000,
        freeSpaceThresholdPercent: 0,
      }),
    )
    expect(textOf(container, 'hilos-logs-growth-forecast')).toBe(
      'The log disk is full — 0% free',
    )
  })

  it('keeps hidden, future and unknown thresholds in their neutral states', () => {
    const { connection, push } = makeConnection()
    const container = mountPage(connection)
    const disk = {
      filesystemFreeBytes: 1400,
      filesystemTotalBytes: 10000,
      freeSpaceThresholdPercent: 20,
    }

    push(overview({ ...disk, freeSpaceThresholdPercent: HIDDEN_VALUE }))
    expect(textOf(container, 'hilos-logs-growth-forecast')).toBe('Hidden')
    expect(textOf(container, 'hilos-logs-growth-forecast')).not.toContain('14%')

    push(
      overview({
        ...disk,
        filesystemFreeBytes: 5400,
        growthBytesPerDay: 100,
      }),
    )
    expect(textOf(container, 'hilos-logs-growth-forecast')).toBe(
      'At this rate the 20% threshold is 34 days away',
    )
    expect(
      byId(container, 'hilos-logs-growth-forecast')?.querySelector('i'),
    ).toBeNull()

    push(overview())
    expect(byId(container, 'hilos-logs-growth-forecast')).toBeNull()
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

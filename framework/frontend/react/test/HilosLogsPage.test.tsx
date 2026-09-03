import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, render } from '@testing-library/react'
import { HilosPages, OVERVIEW_SIGNAL, createSignal } from '@hilos/core'
import type {
  HilosConnection,
  HilosLogsOverview,
  HilosLogsOverviewNode,
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
    resolvePath: () => undefined,
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

function mountPage(connection: HilosConnection): HTMLElement {
  return render(
    <HilosRouterContext.Provider value={router()}>
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
})

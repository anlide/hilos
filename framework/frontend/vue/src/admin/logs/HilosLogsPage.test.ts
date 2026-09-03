import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { describe, expect, it } from 'vitest'
import { HilosPages, createSignal, OVERVIEW_SIGNAL } from '@hilos/core'
import type {
  HilosConnection,
  HilosLogsOverview,
  HilosLogsOverviewNode,
  HilosRouter,
  PageRouteMatch,
} from '@hilos/core'

import HilosLogsPage from './HilosLogsPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

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
      for (const listener of listeners) {
        listener({ type: OVERVIEW_SIGNAL, data: frame })
      }
    },
  }
}

function mountPage(connection: HilosConnection) {
  return mount(HilosLogsPage, {
    props: { context: { connection } },
    global: { provide: { [hilosRouterKey as symbol]: router() } },
  })
}

describe('HilosLogsPage', () => {
  it('keeps the tiles empty rather than zero before any picture arrives', () => {
    const { connection } = makeConnection()
    const wrapper = mountPage(connection)

    expect(wrapper.find('[data-id="hilos-logs-empty-unknown"]').exists()).toBe(
      true,
    )
    expect(wrapper.find('[data-id="hilos-logs-tile-rotation"]').text()).toBe(
      '—',
    )
    expect(wrapper.find('[data-id="hilos-logs-tile-growth"]').text()).toBe('—')
    expect(wrapper.find('[data-id="hilos-logs-tile-agents"]').text()).toContain(
      '—',
    )
  })

  it('keeps the tiles empty in the fault state too, where a zero would be a claim', async () => {
    const { connection, push } = makeConnection()
    const wrapper = mountPage(connection)

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
    await nextTick()

    expect(
      wrapper.find('[data-id="hilos-logs-empty-unreadable"]').exists(),
    ).toBe(true)
    expect(wrapper.find('[data-id="hilos-logs-tile-rotation"]').text()).toBe(
      '—',
    )
    expect(
      wrapper.find('[data-id="hilos-logs-tile-workers"]').text(),
    ).toContain('—')
  })

  it('drops the per-node table entirely in a single-node installation', async () => {
    const { connection, push } = makeConnection()
    const wrapper = mountPage(connection)

    push(overview({ nodes: [] }))
    await nextTick()

    expect(wrapper.find('table').exists()).toBe(false)
    expect(wrapper.find('[data-id="hilos-logs-empty-unknown"]').exists()).toBe(
      false,
    )
  })

  it('draws a row per named node once the picture has them', async () => {
    const { connection, push } = makeConnection()
    const wrapper = mountPage(connection)

    push(
      overview({
        nodes: [node({ nodeId: 'node-1' }), node({ nodeId: 'node-2' })],
      }),
    )
    await nextTick()

    expect(wrapper.find('[data-id="hilos-logs-node-node-1"]').exists()).toBe(
      true,
    )
    expect(wrapper.find('[data-id="hilos-logs-node-node-2"]').exists()).toBe(
      true,
    )
  })

  it('says so once for a node that could not read its own store', async () => {
    const { connection, push } = makeConnection()
    const wrapper = mountPage(connection)

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
    await nextTick()

    expect(
      wrapper.find('[data-id="hilos-logs-node-nodata-node-2"]').exists(),
    ).toBe(true)
    expect(
      wrapper.find('[data-id="hilos-logs-node-nodata-node-1"]').exists(),
    ).toBe(false)
  })

  it('leaves the takeout banner out entirely when nothing is waiting', async () => {
    const { connection, push } = makeConnection()
    const wrapper = mountPage(connection)

    push(overview({ batchesDueForTakeout: 0 }))
    await nextTick()

    expect(wrapper.find('[data-id="hilos-logs-takeout"]').exists()).toBe(false)
  })

  it('says the singular where the count is one, in the banner and in the tile alike', async () => {
    const { connection, push } = makeConnection()
    const wrapper = mountPage(connection)

    push(overview({ totalRotationsAllTime: 1, batchesDueForTakeout: 1 }))
    await nextTick()

    expect(wrapper.find('[data-id="hilos-logs-takeout"]').text()).toContain(
      '1 batch is waiting',
    )
    expect(wrapper.find('[data-id="hilos-logs-tiles"]').text()).toContain(
      '1 batch so far',
    )
  })

  it('names the nodes the waiting batches are lying on', async () => {
    const { connection, push } = makeConnection()
    const wrapper = mountPage(connection)

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
    await nextTick()

    const banner = wrapper.find('[data-id="hilos-logs-takeout"]')
    expect(banner.exists()).toBe(true)
    expect(banner.text()).toContain('node-2, node-3')
    expect(banner.text()).not.toContain('node-1')
  })

  it('takes both ways off the screen to the rotation history', async () => {
    const { connection, push } = makeConnection()
    const wrapper = mountPage(connection)

    push(
      overview({
        batchesDueForTakeout: 2,
        nodes: [node({ nodeId: 'node-2', batchesDueForTakeout: 2 })],
      }),
    )
    await nextTick()

    expect(
      wrapper.find('[data-id="hilos-logs-takeout-open"]').attributes('href'),
    ).toBe('/hilos/logs/rotations')
    expect(
      wrapper.find('[data-id="hilos-logs-node-due-node-2"]').attributes('href'),
    ).toBe('/hilos/logs/rotations')
  })

  it('shows the growth in its three positions and never as a zero', async () => {
    const { connection, push } = makeConnection()
    const wrapper = mountPage(connection)

    push(overview({ growthBytesPerDay: 2048, keysWithoutGrowthWindow: 0 }))
    await nextTick()
    expect(wrapper.find('[data-id="hilos-logs-tile-growth"]').text()).toBe(
      '2.0 KB',
    )
    expect(wrapper.find('[data-id="hilos-logs-growth-note"]').exists()).toBe(
      false,
    )

    push(overview({ growthBytesPerDay: 2048, keysWithoutGrowthWindow: 3 }))
    await nextTick()
    expect(wrapper.find('[data-id="hilos-logs-tile-growth"]').text()).toBe(
      '2.0 KB',
    )
    expect(wrapper.find('[data-id="hilos-logs-growth-note"]').text()).toContain(
      '3 streams',
    )

    push(overview({ growthBytesPerDay: null, keysWithoutGrowthWindow: 5 }))
    await nextTick()
    expect(wrapper.find('[data-id="hilos-logs-tile-growth"]').text()).toBe(
      'Still measuring',
    )
    expect(wrapper.find('[data-id="hilos-logs-growth-note"]').exists()).toBe(
      false,
    )
  })
})

import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { describe, expect, it } from 'vitest'
import {
  HIDDEN_VALUE,
  HilosPages,
  bindTimeZoneState,
  createSignal,
  hilosTimeZone,
  OVERVIEW_SIGNAL,
} from '@hilos/core/base'
import type {
  HilosConnection,
  HilosLogsOverview,
  HilosLogsOverviewRecentEntry,
  HilosLogsOverviewNode,
  HilosRouter,
  PageRouteMatch,
} from '@hilos/core/base'

import HilosLogsPage from './HilosLogsPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

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

const DST_MOMENT = Date.parse('2026-03-29T01:30:00.123Z')
const DST_MOMENT_ISO = '2026-03-29T01:30:00.123Z'

function rotationLabel(timeZone: string): string {
  return new Intl.DateTimeFormat(undefined, {
    year: 'numeric',
    month: 'numeric',
    day: 'numeric',
    hour: 'numeric',
    minute: 'numeric',
    second: 'numeric',
    timeZone,
  }).format(new Date(DST_MOMENT))
}

function recentLabel(timeZone: string): string {
  return new Intl.DateTimeFormat(undefined, {
    hour: 'numeric',
    minute: 'numeric',
    second: 'numeric',
    timeZone,
  }).format(new Date(DST_MOMENT))
}

function bindReaderZone(): {
  setZone: (timeZone: string) => void
  restore: () => void
} {
  type ZoneSignal = { type: string; data: unknown }
  const previousZone = hilosTimeZone.get()
  const listeners = new Set<(signal: ZoneSignal) => void>()
  const connection = {
    projectTimeZone: 'UTC',
    on(event: string, listener: (signal: never) => void): () => void {
      if (event !== 'projectSignal') return () => {}
      const receive = listener as unknown as (signal: ZoneSignal) => void
      listeners.add(receive)
      return () => listeners.delete(receive)
    },
  } as unknown as HilosConnection
  const release = bindTimeZoneState(connection)
  const setZone = (timeZone: string): void => {
    for (const listener of listeners) {
      listener({
        type: 'handshake_response',
        data: { entities: { currentUser: { id: 1, timeZone } } },
      })
    }
  }
  setZone('UTC')

  return {
    setZone,
    restore(): void {
      setZone(previousZone)
      release()
    },
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
    onLeave: () => () => {},
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
  it('reformats the overview moments on a reader-zone change without another frame', async () => {
    const zone = bindReaderZone()
    const { connection, push } = makeConnection()
    const wrapper = mountPage(connection)

    try {
      push(
        overview({
          lastRotationAt: DST_MOMENT_ISO,
          recentErrors: [
            failure({ at: DST_MOMENT_ISO, message: HIDDEN_VALUE }),
          ],
        }),
      )
      await nextTick()

      const rotation = wrapper.get('[data-id="hilos-logs-tile-rotation"]')
      const recent = wrapper.get('[data-id="hilos-logs-recent-row"]')
      const rotationElement = rotation.element
      const recentElement = recent.element
      expect(rotation.text()).toBe(rotationLabel('UTC'))
      expect(recent.text()).toContain(recentLabel('UTC'))
      expect(recent.find('[data-id="hilos-hidden"]').exists()).toBe(true)

      zone.setZone('America/Los_Angeles')
      await nextTick()

      expect(wrapper.get('[data-id="hilos-logs-tile-rotation"]').element).toBe(
        rotationElement,
      )
      expect(wrapper.get('[data-id="hilos-logs-recent-row"]').element).toBe(
        recentElement,
      )
      expect(rotation.text()).toBe(rotationLabel('America/Los_Angeles'))
      expect(recent.text()).toContain(recentLabel('America/Los_Angeles'))
      expect(recent.find('[data-id="hilos-hidden"]').exists()).toBe(true)
    } finally {
      wrapper.unmount()
      zone.restore()
    }
  })

  it('draws a hidden recent message and forecast as separate marks', async () => {
    const { connection, push } = makeConnection()
    const wrapper = mountPage(connection)
    push(
      overview({
        filesystemFreeBytes: 1_000_000,
        filesystemTotalBytes: 2_000_000,
        freeSpaceThresholdPercent: HIDDEN_VALUE,
        recentErrors: [failure({ message: HIDDEN_VALUE })],
      }),
    )
    await nextTick()

    expect(
      wrapper
        .find('[data-id="hilos-logs-growth-forecast"] [data-id="hilos-hidden"]')
        .exists(),
    ).toBe(true)
    expect(
      wrapper
        .find('[data-id="hilos-logs-recent-row"] [data-id="hilos-hidden"]')
        .exists(),
    ).toBe(true)
  })

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
    expect(wrapper.find('[data-id="hilos-logs-tile-daemon"]').text()).toContain(
      '—',
    )
  })

  it('draws the daemon streams as a class of their own, count and weight together', async () => {
    const { connection, push } = makeConnection()
    const wrapper = mountPage(connection)

    push(overview())
    await nextTick()

    const tile = wrapper.find('[data-id="hilos-logs-tile-daemon"]').text()
    expect(tile).toContain('4')
    expect(tile).toContain('220.0 MB')
  })

  it('keeps the tiles empty in the fault state too, where a zero would be a claim', async () => {
    const { connection, push } = makeConnection()
    const wrapper = mountPage(connection)

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
    expect(wrapper.find('[data-id="hilos-logs-tile-daemon"]').text()).toContain(
      '—',
    )
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

  it('draws no panel of failures in either empty state, where good news would be made up', async () => {
    const { connection, push } = makeConnection()
    const wrapper = mountPage(connection)

    expect(wrapper.find('[data-id="hilos-logs-recent-empty"]').exists()).toBe(
      false,
    )

    push(overview({ available: false }))
    await nextTick()

    expect(wrapper.find('[data-id="hilos-logs-recent-empty"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="hilos-logs-recent"]').exists()).toBe(false)
  })

  it('says the hour was quiet in words, rather than showing an empty list', async () => {
    const { connection, push } = makeConnection()
    const wrapper = mountPage(connection)

    push(overview({ recentErrors: [] }))
    await nextTick()

    expect(
      wrapper.find('[data-id="hilos-logs-recent-empty"]').text(),
    ).toContain('No errors in the last hour')
    expect(wrapper.find('[data-id="hilos-logs-recent-row"]').exists()).toBe(
      false,
    )
  })

  it('leads a row into the viewer on the line itself', async () => {
    const { connection, push } = makeConnection()
    const wrapper = mountPage(connection)

    push(
      overview({
        recentErrors: [failure({ stream: 'worker-0.error.log' })],
      }),
    )
    await nextTick()

    const row = wrapper.find('[data-id="hilos-logs-recent-row"]')
    expect(row.exists()).toBe(true)
    expect(row.attributes('href')).toBe(
      `/hilos/logs/view/standalone/live/worker-0.error.log/${Date.parse('2026-09-06T10:00:02.125Z')}`,
    )
    expect(row.text()).toContain('login action failed')
  })

  it('badges only the failure that has a stack behind it', async () => {
    const { connection, push } = makeConnection()
    const wrapper = mountPage(connection)

    push(
      overview({
        recentErrors: [
          failure({ traceFrames: 3 }),
          failure({ traceFrames: null }),
        ],
      }),
    )
    await nextTick()

    const badges = wrapper.findAll('[data-id="hilos-logs-recent-row-trace"]')
    expect(badges).toHaveLength(1)
    expect(badges[0].text()).toContain('3')
    expect(
      wrapper.find('[data-id="hilos-logs-recent-count-errors"]').text(),
    ).toBe('2')
  })

  it('draws both tabs, each counting its own list by its own flag', async () => {
    const { connection, push } = makeConnection()
    const wrapper = mountPage(connection)

    push(
      overview({
        recentErrors: [failure()],
        recentWarnings: [failure(), failure()],
        recentWarningsCapped: true,
      }),
    )
    await nextTick()

    expect(
      wrapper.find('[data-id="hilos-logs-recent-count-errors"]').text(),
    ).toBe('1')
    expect(
      wrapper.find('[data-id="hilos-logs-recent-count-warnings"]').text(),
    ).toBe('2+')
    expect(
      wrapper
        .find('[data-id="hilos-logs-recent-tab-errors"]')
        .attributes('aria-selected'),
    ).toBe('true')
  })

  it('shows the warnings on a click of their tab, from the frame it already holds', async () => {
    // The connection stub can send nothing: a tab that asked the server would throw.
    const { connection, push } = makeConnection()
    const wrapper = mountPage(connection)
    push(
      overview({
        recentErrors: [failure({ message: 'login action failed' })],
        recentWarnings: [failure({ message: 'slow query', traceFrames: null })],
      }),
    )
    await nextTick()

    await wrapper
      .find('[data-id="hilos-logs-recent-tab-warnings"]')
      .trigger('click')

    const rows = wrapper.findAll('[data-id="hilos-logs-recent-row"]')
    expect(rows).toHaveLength(1)
    expect(rows[0].text()).toContain('slow query')
    expect(rows[0].text()).toContain('WARNING')
  })

  it('gives each tab its own good news', async () => {
    const { connection, push } = makeConnection()
    const wrapper = mountPage(connection)
    push(overview({ recentErrors: [failure()] }))
    await nextTick()

    await wrapper
      .find('[data-id="hilos-logs-recent-tab-warnings"]')
      .trigger('click')

    expect(
      wrapper.find('[data-id="hilos-logs-recent-empty"]').text(),
    ).toContain('No warnings in the last hour')
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

  it('takes both ways off the screen to the rotations awaiting carry-off', async () => {
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
    ).toBe('/hilos/logs/rotations/due')
    expect(
      wrapper.find('[data-id="hilos-logs-node-due-node-2"]').attributes('href'),
    ).toBe('/hilos/logs/rotations/due')
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

  // The forecast stands under the caveat and appears only where the room is known:
  // it is what the growth figure MEANS, and the tile changes neither color nor
  // shape for it.
  it('draws the forecast under the growth note, and only with room to forecast', async () => {
    const { connection, push } = makeConnection()
    const wrapper = mountPage(connection)

    push(overview({ growthBytesPerDay: 100, keysWithoutGrowthWindow: 3 }))
    await nextTick()
    expect(
      wrapper.find('[data-id="hilos-logs-growth-forecast"]').exists(),
    ).toBe(false)

    push(
      overview({
        growthBytesPerDay: 100,
        keysWithoutGrowthWindow: 3,
        filesystemFreeBytes: 5400,
        filesystemTotalBytes: 10000,
        freeSpaceThresholdPercent: 20,
      }),
    )
    await nextTick()
    expect(wrapper.find('[data-id="hilos-logs-growth-forecast"]').text()).toBe(
      'At this rate the 20% threshold is 34 days away',
    )
    expect(wrapper.find('[data-id="hilos-logs-growth-note"]').text()).toContain(
      '3 streams',
    )
  })
})

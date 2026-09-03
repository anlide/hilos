import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
import {
  HilosPages,
  ScopeManager,
  createSignal,
  ROTATIONS_HEADER_SIGNAL,
} from '@hilos/core'
import type {
  ActionHandle,
  ActionLifecycle,
  ActionResult,
  HilosConnection,
  HilosLogRotationsHeader,
  HilosRouter,
  PageRouteMatch,
} from '@hilos/core'

import HilosLogsRotationsPage from './HilosLogsRotationsPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

/** A header as the page answers a subscription with it. */
function header(
  overrides: Partial<HilosLogRotationsHeader> = {},
): HilosLogRotationsHeader {
  return {
    available: true,
    nodes: [],
    rotationCron: '0 4 * * *',
    rotationMaxAgeSeconds: 0,
    rotationMaxLiveSizeBytes: 0,
    retentionKeepBatches: 7,
    retentionMaxAgeSeconds: 2592000,
    ...overrides,
  }
}

function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.LOGS_ROTATIONS,
      params: {},
      admin: true,
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
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
 * A connection stub that hands back the two frames this screen lives on: the
 * page's own header, and the window of the rotations table. The window matters
 * even when it is empty — until one arrives the table is loading, and the four
 * empty states are exactly what it shows once it is not.
 */
function makeConnection(): {
  connection: HilosConnection
  pushHeader: (frame: HilosLogRotationsHeader) => void
  pushEmptyWindow: () => void
  pushWindow: (rows: Record<string, unknown>[]) => void
} {
  const projectListeners: ((signal: {
    type: string
    data: unknown
  }) => void)[] = []
  const windowListeners: ((signal: { data: unknown }) => void)[] = []
  const connection = {
    on(event: string, listener: (signal: never) => void): () => void {
      if (event === 'projectSignal') {
        projectListeners.push(
          listener as unknown as (signal: {
            type: string
            data: unknown
          }) => void,
        )
      }
      if (event === 'tableWindow') {
        windowListeners.push(
          listener as unknown as (signal: { data: unknown }) => void,
        )
      }

      return () => {}
    },
    sendTableViewport(): void {},
  } as unknown as HilosConnection

  const pushWindow = (rows: Record<string, unknown>[]): void => {
    for (const listener of windowListeners) {
      listener({
        data: {
          page: HilosPages.LOGS_ROTATIONS,
          tableKey: 'hilosLogRotations',
          rows: rows.map((slot) => ({
            rowKey: String(slot.rowKey),
            slots: { batch: slot },
          })),
          totalCount: rows.length,
        },
      })
    }
  }

  return {
    connection,
    pushHeader(frame: HilosLogRotationsHeader): void {
      for (const listener of projectListeners) {
        listener({ type: ROTATIONS_HEADER_SIGNAL, data: frame })
      }
    },
    pushEmptyWindow: () => pushWindow([]),
    pushWindow,
  }
}

/** One dispatched action, held open so the test times the answer the backend gives. */
interface Dispatched {
  action: string
  payload: Record<string, unknown>
  settle: (result: ActionResult) => void
}

/**
 * An action lifecycle that records what was dispatched and hands the answer back to
 * the test: the takeout dialog closes on the server's word, so a fake that settled
 * by itself would hide exactly the step under test.
 */
function makeActions(): {
  actions: ActionLifecycle
  dispatched: Dispatched[]
} {
  const dispatched: Dispatched[] = []
  const actions = {
    dispatch(action: string, payload: Record<string, unknown>): ActionHandle {
      let settle: (result: ActionResult) => void = () => {}
      const done = new Promise<ActionResult>((resolve) => {
        settle = resolve
      })
      dispatched.push({ action, payload, settle })

      return {
        requestId: String(dispatched.length),
        loading: createSignal(false),
        done,
      }
    },
  } as unknown as ActionLifecycle

  return { actions, dispatched }
}

/** One batch as the backend puts it on the wire. */
function batch(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    rowKey: 'node-1:1800000000',
    batchAt: 1800000000,
    node: null,
    path: 'archive/2027-01-15-08-00-00/',
    absolutePath: '/var/log/hilos/archive/2027-01-15-08-00-00/',
    agentFileCount: 12,
    workerFileCount: 8,
    workerMonopolisticFileCount: 2,
    bytes: 1536 * 1024 * 1024,
    retentionState: 'kept',
    pruneNotBefore: null,
    ...overrides,
  }
}

/** A real page scope, because a window of rows is normalized into one. */
function makeScopes(): ScopeManager {
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.LOGS_ROTATIONS)

  return scopes
}

// The takeout dialog is teleported to the document body, so a wrapper left mounted
// would leave its modal there for the next case to find.
const mounted: ReturnType<typeof mount>[] = []

afterEach(() => {
  for (const wrapper of mounted.splice(0)) {
    wrapper.unmount()
  }
})

function mountPage(
  connection: HilosConnection,
  actions: ActionLifecycle = makeActions().actions,
) {
  const wrapper = mount(HilosLogsRotationsPage, {
    props: { context: { connection, scopes: makeScopes(), actions } },
    global: { provide: { [hilosRouterKey as symbol]: router() } },
  })
  mounted.push(wrapper)

  return wrapper
}

/** Wait out the microtasks a settled action resolves through. */
async function settled(): Promise<void> {
  await nextTick()
  await nextTick()
  await nextTick()
}

describe('HilosLogsRotationsPage', () => {
  it('waits rather than reporting a fault before any picture arrives', async () => {
    const { connection, pushEmptyWindow } = makeConnection()
    const wrapper = mountPage(connection)

    pushEmptyWindow()
    await nextTick()

    expect(
      wrapper.find('[data-id="hilos-rotation-empty-unknown"]').exists(),
    ).toBe(true)
  })

  it('reports the fault once the picture says no node could be read', async () => {
    const { connection, pushHeader, pushEmptyWindow } = makeConnection()
    const wrapper = mountPage(connection)

    pushHeader(header({ available: false }))
    pushEmptyWindow()
    await nextTick()

    expect(
      wrapper.find('[data-id="hilos-rotation-empty-unreadable"]').exists(),
    ).toBe(true)
  })

  it('says nothing has rotated yet when the archive is simply empty', async () => {
    const { connection, pushHeader, pushEmptyWindow } = makeConnection()
    const wrapper = mountPage(connection)

    pushHeader(header())
    pushEmptyWindow()
    await nextTick()

    expect(
      wrapper.find('[data-id="hilos-rotation-empty-never"]').exists(),
    ).toBe(true)
  })

  it('tells a search that matched nothing from an archive that is empty', async () => {
    const { connection, pushHeader, pushEmptyWindow } = makeConnection()
    const wrapper = mountPage(connection)
    pushHeader(header())
    pushEmptyWindow()

    await wrapper.find('[data-id="hilos-table-search"]').setValue('2019-01-01')

    expect(
      wrapper.find('[data-id="hilos-rotation-empty-nomatch"]').exists(),
    ).toBe(true)
    expect(
      wrapper.find('[data-id="hilos-rotation-clear-filters"]').exists(),
    ).toBe(true)
  })

  it('shows no node column and no node filter where nodes have no names', async () => {
    const { connection, pushHeader } = makeConnection()
    const wrapper = mountPage(connection)

    pushHeader(header({ nodes: [] }))
    await nextTick()

    expect(wrapper.find('[data-id="hilos-rotation-node"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="hilos-table-sort-node"]').exists()).toBe(
      false,
    )
  })

  it('offers the node column and the node filter once the picture names nodes', async () => {
    const { connection, pushHeader } = makeConnection()
    const wrapper = mountPage(connection)

    pushHeader(header({ nodes: ['node-1', 'node-2'] }))
    await nextTick()

    const select = wrapper.find('[data-id="hilos-rotation-node"]')
    expect(select.exists()).toBe(true)
    expect(select.text()).toContain('node-2')
    expect(wrapper.find('[data-id="hilos-table-sort-node"]').exists()).toBe(
      true,
    )
  })

  it('prints the rules in force rather than a preset name', async () => {
    const { connection, pushHeader } = makeConnection()
    const wrapper = mountPage(connection)

    pushHeader(header())
    await nextTick()

    const rule = wrapper.find('[data-id="hilos-rotation-rule"]')
    expect(rule.text()).toBe('Rotates on the schedule 0 4 * * *')
    expect(wrapper.text()).toContain(
      'Recommends carrying off a batch outside the newest 7 and older than 30 d',
    )
  })

  /**
   * Below `lg` the node and weight columns are hidden and their values move into a
   * sub-line under the batch name. The single-node installation is the case that
   * broke: only the weight is hidden there, and a sub-line that appeared for
   * clusters alone left a narrow screen with no weight anywhere.
   */
  it('carries the hidden weight into the sub-line even with no node names', async () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const wrapper = mountPage(connection)

    pushHeader(header({ nodes: [] }))
    pushWindow([batch()])
    await nextTick()

    const subLine = wrapper.find('[data-id^="hilos-table-row-"] .d-lg-none')
    expect(subLine.exists()).toBe(true)
    expect(subLine.text()).toBe('1.5 GB')
  })

  it('carries the node into that sub-line as well where nodes have names', async () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const wrapper = mountPage(connection)

    pushHeader(header({ nodes: ['node-1'] }))
    pushWindow([batch({ node: 'node-1' })])
    await nextTick()

    expect(
      wrapper.find('[data-id^="hilos-table-row-"] .d-lg-none').text(),
    ).toBe('node-1 · 1.5 GB')
  })

  it('offers the takeout only on a batch the rule recommends carrying off', async () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const wrapper = mountPage(connection)

    pushHeader(header())
    pushWindow([
      batch({ rowKey: 'a:1', batchAt: 1, retentionState: 'kept' }),
      batch({ rowKey: 'a:2', batchAt: 2, retentionState: 'due' }),
      batch({ rowKey: 'a:3', batchAt: 3, retentionState: 'taken' }),
    ])
    await nextTick()

    expect(wrapper.findAll('[data-id="hilos-rotation-takeout"]')).toHaveLength(
      1,
    )
  })

  it('says where the batch lies and how to copy it off, node first in a cluster', async () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const wrapper = mountPage(connection)

    pushHeader(header({ nodes: ['node-1'] }))
    pushWindow([batch({ node: 'node-1', retentionState: 'due' })])
    await nextTick()
    await wrapper.find('[data-id="hilos-rotation-takeout"]').trigger('click')

    expect(
      document.querySelector('[data-id="hilos-rotation-takeout-path"]')
        ?.textContent,
    ).toBe('node-1:/var/log/hilos/archive/2027-01-15-08-00-00/')
    expect(
      document.querySelector('[data-id="hilos-rotation-takeout-command"]')
        ?.textContent,
    ).toBe(
      'rsync -a node-1:/var/log/hilos/archive/2027-01-15-08-00-00/ ./cold-logs/node-1/2027-01-15-08-00-00/',
    )
  })

  /**
   * A node that reported no log root has no address to give, and this screen must
   * not fill the gap with its own: the page worker knows where ITS logs live, and
   * that directory is on another machine.
   */
  it('offers no address at all when the holding node reported no log root', async () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const wrapper = mountPage(connection)

    pushHeader(header())
    pushWindow([batch({ absolutePath: null, retentionState: 'due' })])
    await nextTick()
    await wrapper.find('[data-id="hilos-rotation-takeout"]').trigger('click')

    expect(
      document.querySelector('[data-id="hilos-rotation-takeout-path"]'),
    ).toBeNull()
    expect(document.body.textContent).toContain(
      'did not report where its logs live',
    )
  })

  it("names the batch by node and stamp, and closes only on the server's word", async () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const { actions, dispatched } = makeActions()
    const wrapper = mountPage(connection, actions)

    pushHeader(header({ nodes: ['node-1'] }))
    pushWindow([batch({ node: 'node-1', retentionState: 'due' })])
    await nextTick()
    await wrapper.find('[data-id="hilos-rotation-takeout"]').trigger('click')
    const confirm = document.querySelector(
      '[data-id="hilos-rotation-takeout-confirm"]',
    ) as HTMLElement
    confirm.click()
    await settled()

    expect(dispatched).toMatchObject([
      {
        action: 'logs_takeout_confirm',
        payload: { nodeId: 'node-1', batchTimestamp: 1800000000 },
      },
    ])
    expect(document.body.textContent).toContain('Where it lies')

    dispatched[0]?.settle({ action: 'logs_takeout_confirm' } as ActionResult)
    await settled()

    expect(document.body.textContent).not.toContain('Where it lies')
  })

  it('offers the withdrawal only on a batch somebody said was carried off', async () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const wrapper = mountPage(connection)

    pushHeader(header())
    pushWindow([
      batch({ rowKey: 'a:1', batchAt: 1, retentionState: 'kept' }),
      batch({ rowKey: 'a:2', batchAt: 2, retentionState: 'due' }),
      batch({ rowKey: 'a:3', batchAt: 3, retentionState: 'taken' }),
    ])
    await nextTick()

    expect(wrapper.findAll('[data-id="hilos-rotation-undo"]')).toHaveLength(1)
  })

  /**
   * The deadline is the node's own promise, and the screen has to say it out loud:
   * somebody reading this modal is deciding whether they still have time.
   */
  it('names the instant the batch stops being safe from the cleaner', async () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const wrapper = mountPage(connection)

    pushHeader(header())
    pushWindow([batch({ retentionState: 'taken', pruneNotBefore: 1800086400 })])
    await nextTick()
    await wrapper.find('[data-id="hilos-rotation-undo"]').trigger('click')

    expect(
      document.querySelector('[data-id="hilos-rotation-undo-deadline"]')
        ?.textContent,
    ).toContain(
      `The cleaner may delete this batch after ${new Date(1800086400 * 1000).toLocaleString()}.`,
    )
  })

  /**
   * A node whose window is zero told the pruner not to wait, so there is no instant
   * to name — and saying nothing would read as "we do not know" rather than "now".
   */
  it('says the cleaner may come at its next pass when the node will not wait', async () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const wrapper = mountPage(connection)

    pushHeader(header())
    pushWindow([batch({ retentionState: 'taken', pruneNotBefore: null })])
    await nextTick()
    await wrapper.find('[data-id="hilos-rotation-undo"]').trigger('click')

    expect(
      document.querySelector('[data-id="hilos-rotation-undo-deadline"]')
        ?.textContent,
    ).toContain('as soon as it next runs')
  })

  it("withdraws under its own action name, and closes only on the server's word", async () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const { actions, dispatched } = makeActions()
    const wrapper = mountPage(connection, actions)

    pushHeader(header({ nodes: ['node-1'] }))
    pushWindow([batch({ node: 'node-1', retentionState: 'taken' })])
    await nextTick()
    await wrapper.find('[data-id="hilos-rotation-undo"]').trigger('click')
    const confirm = document.querySelector(
      '[data-id="hilos-rotation-undo-confirm"]',
    ) as HTMLElement
    confirm.click()
    await settled()

    expect(dispatched).toMatchObject([
      {
        action: 'logs_takeout_undo',
        payload: { nodeId: 'node-1', batchTimestamp: 1800000000 },
      },
    ])
    expect(document.body.textContent).toContain(
      'Has the batch not been carried off?',
    )

    dispatched[0]?.settle({ action: 'logs_takeout_undo' } as ActionResult)
    await settled()

    expect(document.body.textContent).not.toContain(
      'Has the batch not been carried off?',
    )
  })

  it('opens the legend modal, which is where the three numbers are explained', async () => {
    const { connection } = makeConnection()
    const wrapper = mountPage(connection)

    expect(document.body.textContent).not.toContain('What is in a batch')

    await wrapper.find('[data-id="hilos-rotation-legend"]').trigger('click')

    expect(document.body.textContent).toContain('What is in a batch')
  })
})

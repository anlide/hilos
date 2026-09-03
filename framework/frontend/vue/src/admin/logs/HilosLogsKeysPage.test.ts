import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { describe, expect, it } from 'vitest'
import {
  HilosPages,
  ScopeManager,
  createSignal,
  KEYS_HEADER_SIGNAL,
} from '@hilos/core'
import type {
  HilosConnection,
  HilosLogKeysHeader,
  HilosRouter,
  PageRouteMatch,
} from '@hilos/core'

import HilosLogsKeysPage from './HilosLogsKeysPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

/** A header as the page answers a subscription with it. */
function header(
  overrides: Partial<HilosLogKeysHeader> = {},
): HilosLogKeysHeader {
  return {
    available: true,
    nodes: [],
    ...overrides,
  }
}

function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.LOGS_KEYS,
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
 * A connection stub that hands back the two frames this screen lives on: the page's
 * own header, and the window of the stream table. The window matters even when it is
 * empty — until one arrives the table is loading, and the four empty states are
 * exactly what it shows once it is not.
 */
function makeConnection(): {
  connection: HilosConnection
  pushHeader: (frame: HilosLogKeysHeader) => void
  pushEmptyWindow: () => void
  pushWindow: (rows: Record<string, unknown>[]) => void
  filters: Record<string, unknown>[]
} {
  const projectListeners: ((signal: {
    type: string
    data: unknown
  }) => void)[] = []
  const windowListeners: ((signal: { data: unknown }) => void)[] = []
  const filters: Record<string, unknown>[] = []
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
    sendTableViewport(
      page: string,
      tableKey: string,
      descriptor: { filter?: Record<string, unknown> },
    ): void {
      filters.push(descriptor.filter ?? {})
    },
  } as unknown as HilosConnection

  const pushWindow = (rows: Record<string, unknown>[]): void => {
    for (const listener of windowListeners) {
      listener({
        data: {
          page: HilosPages.LOGS_KEYS,
          tableKey: 'hilosLogKeys',
          rows: rows.map((slot) => ({
            rowKey: String(slot.rowKey),
            slots: { stream: slot },
          })),
          totalCount: rows.length,
        },
      })
    }
  }

  return {
    connection,
    pushHeader(frame: HilosLogKeysHeader): void {
      for (const listener of projectListeners) {
        listener({ type: KEYS_HEADER_SIGNAL, data: frame })
      }
    },
    pushEmptyWindow: () => pushWindow([]),
    pushWindow,
    filters,
  }
}

/** One stream as the backend puts it on the wire. */
function stream(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    rowKey: '-:worker-0.log',
    key: 'worker-0.log',
    node: null,
    class: 'worker',
    live: true,
    batchCount: 12,
    lastBatchAt: 1800000000,
    bytes: 1536 * 1024 * 1024,
    growthPerDay: 2 * 1024 * 1024,
    growthSort: 2 * 1024 * 1024,
    ...overrides,
  }
}

/** A real page scope, because a window of rows is normalized into one. */
function makeScopes(): ScopeManager {
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.LOGS_KEYS)

  return scopes
}

function mountPage(connection: HilosConnection) {
  return mount(HilosLogsKeysPage, {
    props: { context: { connection, scopes: makeScopes() } },
    global: { provide: { [hilosRouterKey as symbol]: router() } },
  })
}

describe('HilosLogsKeysPage', () => {
  it('waits rather than reporting a fault before any picture arrives', async () => {
    const { connection, pushEmptyWindow } = makeConnection()
    const wrapper = mountPage(connection)

    pushEmptyWindow()
    await nextTick()

    expect(
      wrapper.find('[data-id="hilos-log-key-empty-unknown"]').exists(),
    ).toBe(true)
  })

  it('reports the fault once the picture says no node could be read', async () => {
    const { connection, pushHeader, pushEmptyWindow } = makeConnection()
    const wrapper = mountPage(connection)

    pushHeader(header({ available: false }))
    pushEmptyWindow()
    await nextTick()

    expect(
      wrapper.find('[data-id="hilos-log-key-empty-unreadable"]').exists(),
    ).toBe(true)
  })

  it('says nothing has been logged yet when the store is simply empty', async () => {
    const { connection, pushHeader, pushEmptyWindow } = makeConnection()
    const wrapper = mountPage(connection)

    pushHeader(header())
    pushEmptyWindow()
    await nextTick()

    expect(wrapper.find('[data-id="hilos-log-key-empty-never"]').exists()).toBe(
      true,
    )
  })

  it('tells a search that matched nothing from a store that is empty', async () => {
    const { connection, pushHeader, pushEmptyWindow } = makeConnection()
    const wrapper = mountPage(connection)
    pushHeader(header())
    pushEmptyWindow()

    await wrapper.find('[data-id="hilos-table-search"]').setValue('nothing')

    expect(
      wrapper.find('[data-id="hilos-log-key-empty-nomatch"]').exists(),
    ).toBe(true)
    expect(
      wrapper.find('[data-id="hilos-log-key-clear-filters"]').exists(),
    ).toBe(true)
  })

  it('shows no node column and no node filter where nodes have no names', async () => {
    const { connection, pushHeader } = makeConnection()
    const wrapper = mountPage(connection)

    pushHeader(header({ nodes: [] }))
    await nextTick()

    expect(wrapper.find('[data-id="hilos-log-key-node"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="hilos-table-sort-node"]').exists()).toBe(
      false,
    )
  })

  it('offers the node column and the node filter once the picture names nodes', async () => {
    const { connection, pushHeader } = makeConnection()
    const wrapper = mountPage(connection)

    pushHeader(header({ nodes: ['node-1', 'node-2'] }))
    await nextTick()

    const select = wrapper.find('[data-id="hilos-log-key-node"]')
    expect(select.exists()).toBe(true)
    expect(select.text()).toContain('node-2')
    expect(wrapper.find('[data-id="hilos-table-sort-node"]').exists()).toBe(
      true,
    )
  })

  it('sends the class switch to the server as a filter, never narrowing locally', async () => {
    const { connection, filters } = makeConnection()
    const wrapper = mountPage(connection)

    await wrapper
      .find('[data-id="hilos-log-key-class-worker"]')
      .trigger('click')

    expect(filters.at(-1)).toEqual({ class: 'worker' })
  })

  /**
   * Below `lg` the node, weight and growth columns are hidden and their values move
   * into a sub-line under the key. The single-node installation is the case worth
   * holding: only two of the three are hidden there, and a sub-line drawn for
   * clusters alone would leave a narrow screen with no figures at all.
   */
  it('carries the hidden weight and growth into the sub-line with no node names', async () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const wrapper = mountPage(connection)

    pushHeader(header({ nodes: [] }))
    pushWindow([stream()])
    await nextTick()

    const subLine = wrapper.find('[data-id^="hilos-table-row-"] .d-lg-none')
    expect(subLine.exists()).toBe(true)
    expect(subLine.text()).toBe('1.5 GB · 2.0 MB')
  })

  it('carries the node into that sub-line as well where nodes have names', async () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const wrapper = mountPage(connection)

    pushHeader(header({ nodes: ['node-1'] }))
    pushWindow([stream({ rowKey: 'node-1:worker-0.log', node: 'node-1' })])
    await nextTick()

    expect(
      wrapper.find('[data-id^="hilos-table-row-"] .d-lg-none').text(),
    ).toBe('node-1 · 1.5 GB · 2.0 MB')
  })

  it('sends a live stream to the live file and an archived one to its last batch', async () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const wrapper = mountPage(connection)

    pushHeader(header({ nodes: ['node-1'] }))
    pushWindow([
      stream({ rowKey: 'node-1:worker-0.log', node: 'node-1' }),
      stream({
        rowKey: 'node-1:worker-1.log',
        key: 'worker-1.log',
        node: 'node-1',
        live: false,
        lastBatchAt: 1799999000,
      }),
    ])
    await nextTick()

    expect(
      wrapper
        .find('[data-id="hilos-log-key-open-node-1:worker-0.log"]')
        .attributes('href'),
    ).toBe('/hilos/logs/view/node-1/live/worker-0.log')
    expect(
      wrapper
        .find('[data-id="hilos-log-key-open-node-1:worker-1.log"]')
        .attributes('href'),
    ).toBe('/hilos/logs/view/node-1/1799999000/worker-1.log')
  })

  it('draws an unmeasured day as a dash rather than as a standstill', async () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const wrapper = mountPage(connection)

    pushHeader(header())
    pushWindow([stream({ growthPerDay: null, growthSort: -1 })])
    await nextTick()

    expect(
      wrapper.find('[data-id^="hilos-table-row-"] .d-lg-none').text(),
    ).toBe('1.5 GB · —')
  })

  /**
   * The footnote says the split is shown by the workers page, and it is a way there
   * rather than a mention of one: HIL-385 left the phrase as plain text only because
   * that page was still a stub.
   */
  it('takes the reader to the workers page from the phrase that names it', () => {
    const { connection } = makeConnection()
    const wrapper = mountPage(connection)

    expect(
      wrapper.find('[data-id="hilos-log-key-workers-link"]').attributes('href'),
    ).toBe('/hilos/logs/workers')
  })
})

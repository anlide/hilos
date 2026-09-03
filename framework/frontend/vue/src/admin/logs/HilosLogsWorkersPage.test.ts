import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { describe, expect, it } from 'vitest'
import {
  HilosPages,
  ScopeManager,
  createSignal,
  WORKERS_HEADER_SIGNAL,
} from '@hilos/core'
import type {
  HilosConnection,
  HilosLogWorkersHeader,
  HilosRouter,
  PageRouteMatch,
} from '@hilos/core'

import HilosLogsWorkersPage from './HilosLogsWorkersPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

/** A header as the page answers a subscription with it. */
function header(
  overrides: Partial<HilosLogWorkersHeader> = {},
): HilosLogWorkersHeader {
  return {
    available: true,
    nodes: [],
    ...overrides,
  }
}

function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.LOGS_WORKERS,
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
  pushHeader: (frame: HilosLogWorkersHeader) => void
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
          page: HilosPages.LOGS_WORKERS,
          tableKey: 'hilosLogWorkers',
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
    pushHeader(frame: HilosLogWorkersHeader): void {
      for (const listener of projectListeners) {
        listener({ type: WORKERS_HEADER_SIGNAL, data: frame })
      }
    },
    pushEmptyWindow: () => pushWindow([]),
    pushWindow,
    filters,
  }
}

/** One worker stream as the backend puts it on the wire. */
function stream(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    rowKey: '-:worker-0.log',
    key: 'worker-0.log',
    node: null,
    type: 'regular',
    live: true,
    batchCount: 12,
    lastBatchAt: 1800000000,
    bytes: 1536 * 1024 * 1024,
    ...overrides,
  }
}

/** A real page scope, because a window of rows is normalized into one. */
function makeScopes(): ScopeManager {
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.LOGS_WORKERS)

  return scopes
}

function mountPage(connection: HilosConnection) {
  return mount(HilosLogsWorkersPage, {
    props: { context: { connection, scopes: makeScopes() } },
    global: { provide: { [hilosRouterKey as symbol]: router() } },
  })
}

describe('HilosLogsWorkersPage', () => {
  it('waits rather than reporting a fault before any picture arrives', async () => {
    const { connection, pushEmptyWindow } = makeConnection()
    const wrapper = mountPage(connection)

    pushEmptyWindow()
    await nextTick()

    expect(
      wrapper.find('[data-id="hilos-log-worker-empty-unknown"]').exists(),
    ).toBe(true)
  })

  it('reports the fault once the picture says no node could be read', async () => {
    const { connection, pushHeader, pushEmptyWindow } = makeConnection()
    const wrapper = mountPage(connection)

    pushHeader(header({ available: false }))
    pushEmptyWindow()
    await nextTick()

    expect(
      wrapper.find('[data-id="hilos-log-worker-empty-unreadable"]').exists(),
    ).toBe(true)
  })

  it('says nothing has been logged yet when the store is simply empty', async () => {
    const { connection, pushHeader, pushEmptyWindow } = makeConnection()
    const wrapper = mountPage(connection)

    pushHeader(header())
    pushEmptyWindow()
    await nextTick()

    expect(
      wrapper.find('[data-id="hilos-log-worker-empty-never"]').exists(),
    ).toBe(true)
  })

  it('tells a search that matched nothing from a store that is empty', async () => {
    const { connection, pushHeader, pushEmptyWindow } = makeConnection()
    const wrapper = mountPage(connection)
    pushHeader(header())
    pushEmptyWindow()

    await wrapper.find('[data-id="hilos-table-search"]').setValue('nothing')

    expect(
      wrapper.find('[data-id="hilos-log-worker-empty-nomatch"]').exists(),
    ).toBe(true)
    expect(
      wrapper.find('[data-id="hilos-log-worker-clear-filters"]').exists(),
    ).toBe(true)
  })

  it('shows no node column and no node filter where nodes have no names', async () => {
    const { connection, pushHeader } = makeConnection()
    const wrapper = mountPage(connection)

    pushHeader(header({ nodes: [] }))
    await nextTick()

    expect(wrapper.find('[data-id="hilos-log-worker-node"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="hilos-table-sort-node"]').exists()).toBe(
      false,
    )
  })

  it('offers the node column and the node filter once the picture names nodes', async () => {
    const { connection, pushHeader } = makeConnection()
    const wrapper = mountPage(connection)

    pushHeader(header({ nodes: ['node-1', 'node-2'] }))
    await nextTick()

    const select = wrapper.find('[data-id="hilos-log-worker-node"]')
    expect(select.exists()).toBe(true)
    expect(select.text()).toContain('node-2')
    expect(wrapper.find('[data-id="hilos-table-sort-node"]').exists()).toBe(
      true,
    )
  })

  it('sends the type switch to the server as a filter, never narrowing locally', async () => {
    const { connection, filters } = makeConnection()
    const wrapper = mountPage(connection)

    await wrapper
      .find('[data-id="hilos-log-worker-type-monopolistic"]')
      .trigger('click')

    expect(filters.at(-1)).toEqual({ type: 'monopolistic' })
  })

  it('offers two type buttons and no third, because the panel asks two questions', () => {
    const { connection } = makeConnection()
    const wrapper = mountPage(connection)

    expect(wrapper.findAll('[data-id^="hilos-log-worker-type-"]')).toHaveLength(
      2,
    )
    expect(
      wrapper.find('[data-id="hilos-log-worker-type-regular"]').exists(),
    ).toBe(false)
  })

  it('tells the monopolistic worker from an ordinary one by its badge', async () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const wrapper = mountPage(connection)

    pushHeader(header())
    pushWindow([
      stream(),
      stream({
        rowKey: '-:worker-monopolistic-truth.log',
        key: 'worker-monopolistic-truth.log',
        type: 'monopolistic',
      }),
    ])
    await nextTick()

    const badges = wrapper.findAll('[data-id^="hilos-table-row-"] .badge')
    expect(badges[0].text()).toBe('Ordinary')
    expect(badges[0].classes()).toContain('text-bg-light')
    const monopolistic = badges.find((badge) => badge.text() === 'Monopolistic')
    expect(monopolistic?.classes()).toContain('text-bg-info-subtle')
  })

  /**
   * Below `lg` the node and weight columns are hidden and their values move into a
   * sub-line under the key. The single-node installation is the case worth holding:
   * only one of the two is hidden there, and a sub-line drawn for clusters alone
   * would leave a narrow screen with no figures at all.
   */
  it('carries the hidden weight into the sub-line with no node names', async () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const wrapper = mountPage(connection)

    pushHeader(header({ nodes: [] }))
    pushWindow([stream()])
    await nextTick()

    const subLine = wrapper.find('[data-id^="hilos-table-row-"] .d-lg-none')
    expect(subLine.exists()).toBe(true)
    expect(subLine.text()).toBe('1.5 GB')
  })

  it('carries the node into that sub-line as well where nodes have names', async () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const wrapper = mountPage(connection)

    pushHeader(header({ nodes: ['node-1'] }))
    pushWindow([stream({ rowKey: 'node-1:worker-0.log', node: 'node-1' })])
    await nextTick()

    expect(
      wrapper.find('[data-id^="hilos-table-row-"] .d-lg-none').text(),
    ).toBe('node-1 · 1.5 GB')
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
        .find('[data-id="hilos-log-worker-open-node-1:worker-0.log"]')
        .attributes('href'),
    ).toBe('/hilos/logs/view/node-1/live/worker-0.log')
    expect(
      wrapper
        .find('[data-id="hilos-log-worker-open-node-1:worker-1.log"]')
        .attributes('href'),
    ).toBe('/hilos/logs/view/node-1/1799999000/worker-1.log')
  })

  /**
   * The footnote is the one place the screen explains itself, and the explanation is
   * not the same one in the two installations: in a cluster the node column is part
   * of the answer, and in a single-node installation there is no node to point at.
   */
  it('explains itself by the cluster wording only where nodes have names', async () => {
    const { connection, pushHeader } = makeConnection()
    const wrapper = mountPage(connection)

    pushHeader(header({ nodes: [] }))
    await nextTick()
    expect(wrapper.find('.alert').text()).toContain('two hands')

    pushHeader(header({ nodes: ['node-1'] }))
    await nextTick()
    expect(wrapper.find('.alert').text()).toContain('different machines')
  })
})

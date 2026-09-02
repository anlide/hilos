import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { describe, expect, it } from 'vitest'
import {
  HilosPages,
  ScopeManager,
  createSignal,
  ROTATIONS_HEADER_SIGNAL,
} from '@hilos/core'
import type {
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

/** One batch as the backend puts it on the wire. */
function batch(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    rowKey: 'node-1:1800000000',
    batchAt: 1800000000,
    node: null,
    path: 'archive/2027-01-15-08-00-00/',
    agentFileCount: 12,
    workerFileCount: 8,
    workerMonopolisticFileCount: 2,
    bytes: 1536 * 1024 * 1024,
    retentionState: 'kept',
    ...overrides,
  }
}

/** A real page scope, because a window of rows is normalized into one. */
function makeScopes(): ScopeManager {
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.LOGS_ROTATIONS)

  return scopes
}

function mountPage(connection: HilosConnection) {
  return mount(HilosLogsRotationsPage, {
    props: { context: { connection, scopes: makeScopes() } },
    global: { provide: { [hilosRouterKey as symbol]: router() } },
  })
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

  it('opens the legend modal, which is where the three numbers are explained', async () => {
    const { connection } = makeConnection()
    const wrapper = mountPage(connection)

    expect(document.body.textContent).not.toContain('What is in a batch')

    await wrapper.find('[data-id="hilos-rotation-legend"]').trigger('click')

    expect(document.body.textContent).toContain('What is in a batch')
  })
})

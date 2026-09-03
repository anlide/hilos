import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import {
  HilosPages,
  KEYS_HEADER_SIGNAL,
  ScopeManager,
  createSignal,
} from '@hilos/core'
import type {
  HilosConnection,
  HilosLogKeysHeader,
  HilosRouter,
  PageRouteMatch,
} from '@hilos/core'

import { HilosLogsKeysPage } from '../src/admin/logs/HilosLogsKeysPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

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
    act(() => {
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
    })
  }

  return {
    connection,
    pushHeader(frame: HilosLogKeysHeader): void {
      act(() => {
        for (const listener of projectListeners) {
          listener({ type: KEYS_HEADER_SIGNAL, data: frame })
        }
      })
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

function mountPage(connection: HilosConnection): HTMLElement {
  return render(
    <HilosRouterContext.Provider value={router()}>
      <HilosLogsKeysPage context={{ connection, scopes: makeScopes() }} />
    </HilosRouterContext.Provider>,
  ).container
}

function byId(container: HTMLElement, id: string): HTMLElement | null {
  return container.querySelector(`[data-id="${id}"]`)
}

function subLine(container: HTMLElement): HTMLElement | null {
  return container.querySelector('[data-id^="hilos-table-row-"] .d-lg-none')
}

describe('HilosLogsKeysPage', () => {
  afterEach(cleanup)

  it('waits rather than reporting a fault before any picture arrives', () => {
    const { connection, pushEmptyWindow } = makeConnection()
    const container = mountPage(connection)

    pushEmptyWindow()

    expect(byId(container, 'hilos-log-key-empty-unknown')).not.toBeNull()
  })

  it('reports the fault once the picture says no node could be read', () => {
    const { connection, pushHeader, pushEmptyWindow } = makeConnection()
    const container = mountPage(connection)

    pushHeader(header({ available: false }))
    pushEmptyWindow()

    expect(byId(container, 'hilos-log-key-empty-unreadable')).not.toBeNull()
  })

  it('says nothing has been logged yet when the store is simply empty', () => {
    const { connection, pushHeader, pushEmptyWindow } = makeConnection()
    const container = mountPage(connection)

    pushHeader(header())
    pushEmptyWindow()

    expect(byId(container, 'hilos-log-key-empty-never')).not.toBeNull()
  })

  it('tells a search that matched nothing from a store that is empty', () => {
    const { connection, pushHeader, pushEmptyWindow } = makeConnection()
    const container = mountPage(connection)
    pushHeader(header())
    pushEmptyWindow()

    const search = byId(container, 'hilos-table-search')
    fireEvent.change(search as HTMLInputElement, {
      target: { value: 'nothing' },
    })

    expect(byId(container, 'hilos-log-key-empty-nomatch')).not.toBeNull()
    expect(byId(container, 'hilos-log-key-clear-filters')).not.toBeNull()
  })

  it('shows no node column and no node filter where nodes have no names', () => {
    const { connection, pushHeader } = makeConnection()
    const container = mountPage(connection)

    pushHeader(header({ nodes: [] }))

    expect(byId(container, 'hilos-log-key-node')).toBeNull()
    expect(byId(container, 'hilos-table-sort-node')).toBeNull()
  })

  it('offers the node column and the node filter once the picture names nodes', () => {
    const { connection, pushHeader } = makeConnection()
    const container = mountPage(connection)

    pushHeader(header({ nodes: ['node-1', 'node-2'] }))

    const select = byId(container, 'hilos-log-key-node')
    expect(select).not.toBeNull()
    expect(select?.textContent).toContain('node-2')
    expect(byId(container, 'hilos-table-sort-node')).not.toBeNull()
  })

  it('sends the class switch to the server as a filter, never narrowing locally', () => {
    const { connection, filters } = makeConnection()
    const container = mountPage(connection)

    fireEvent.click(
      byId(container, 'hilos-log-key-class-worker') as HTMLElement,
    )

    expect(filters.at(-1)).toEqual({ class: 'worker' })
  })

  /**
   * Below `lg` the node, weight and growth columns are hidden and their values move
   * into a sub-line under the key. The single-node installation is the case worth
   * holding: only two of the three are hidden there, and a sub-line drawn for
   * clusters alone would leave a narrow screen with no figures at all.
   */
  it('carries the hidden weight and growth into the sub-line with no node names', () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const container = mountPage(connection)

    pushHeader(header({ nodes: [] }))
    pushWindow([stream()])

    expect(subLine(container)).not.toBeNull()
    expect(subLine(container)?.textContent).toBe('1.5 GB · 2.0 MB')
  })

  it('carries the node into that sub-line as well where nodes have names', () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const container = mountPage(connection)

    pushHeader(header({ nodes: ['node-1'] }))
    pushWindow([stream({ rowKey: 'node-1:worker-0.log', node: 'node-1' })])

    expect(subLine(container)?.textContent).toBe('node-1 · 1.5 GB · 2.0 MB')
  })

  it('sends a live stream to the live file and an archived one to its last batch', () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const container = mountPage(connection)

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

    expect(
      byId(container, 'hilos-log-key-open-node-1:worker-0.log')?.getAttribute(
        'href',
      ),
    ).toBe('/hilos/logs/view/node-1/live/worker-0.log')
    expect(
      byId(container, 'hilos-log-key-open-node-1:worker-1.log')?.getAttribute(
        'href',
      ),
    ).toBe('/hilos/logs/view/node-1/1799999000/worker-1.log')
  })

  it('draws an unmeasured day as a dash rather than as a standstill', () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const container = mountPage(connection)

    pushHeader(header())
    pushWindow([stream({ growthPerDay: null, growthSort: -1 })])

    expect(subLine(container)?.textContent).toBe('1.5 GB · —')
  })

  /**
   * The footnote says the split is shown by the workers page, and it is a way there
   * rather than a mention of one: HIL-385 left the phrase as plain text only because
   * that page was still a stub.
   */
  it('takes the reader to the workers page from the phrase that names it', () => {
    const { connection } = makeConnection()
    const container = mountPage(connection)

    expect(
      byId(container, 'hilos-log-key-workers-link')?.getAttribute('href'),
    ).toBe('/hilos/logs/workers')
  })
})

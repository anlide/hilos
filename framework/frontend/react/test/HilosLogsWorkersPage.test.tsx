import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import {
  HilosPages,
  ScopeManager,
  WORKERS_HEADER_SIGNAL,
  createSignal,
} from '@hilos/core'
import type {
  HilosConnection,
  HilosLogWorkersHeader,
  HilosRouter,
  PageRouteMatch,
} from '@hilos/core'

import { HilosLogsWorkersPage } from '../src/admin/logs/HilosLogsWorkersPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

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
    act(() => {
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
    })
  }

  return {
    connection,
    pushHeader(frame: HilosLogWorkersHeader): void {
      act(() => {
        for (const listener of projectListeners) {
          listener({ type: WORKERS_HEADER_SIGNAL, data: frame })
        }
      })
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

function mountPage(connection: HilosConnection): HTMLElement {
  return render(
    <HilosRouterContext.Provider value={router()}>
      <HilosLogsWorkersPage context={{ connection, scopes: makeScopes() }} />
    </HilosRouterContext.Provider>,
  ).container
}

function byId(container: HTMLElement, id: string): HTMLElement | null {
  return container.querySelector(`[data-id="${id}"]`)
}

function subLine(container: HTMLElement): HTMLElement | null {
  return container.querySelector('[data-id^="hilos-table-row-"] .d-lg-none')
}

describe('HilosLogsWorkersPage', () => {
  afterEach(cleanup)

  it('waits rather than reporting a fault before any picture arrives', () => {
    const { connection, pushEmptyWindow } = makeConnection()
    const container = mountPage(connection)

    pushEmptyWindow()

    expect(byId(container, 'hilos-log-worker-empty-unknown')).not.toBeNull()
  })

  it('reports the fault once the picture says no node could be read', () => {
    const { connection, pushHeader, pushEmptyWindow } = makeConnection()
    const container = mountPage(connection)

    pushHeader(header({ available: false }))
    pushEmptyWindow()

    expect(byId(container, 'hilos-log-worker-empty-unreadable')).not.toBeNull()
  })

  it('says nothing has been logged yet when the store is simply empty', () => {
    const { connection, pushHeader, pushEmptyWindow } = makeConnection()
    const container = mountPage(connection)

    pushHeader(header())
    pushEmptyWindow()

    expect(byId(container, 'hilos-log-worker-empty-never')).not.toBeNull()
  })

  it('tells a search that matched nothing from a store that is empty', () => {
    const { connection, pushHeader, pushEmptyWindow } = makeConnection()
    const container = mountPage(connection)
    pushHeader(header())
    pushEmptyWindow()

    fireEvent.change(
      byId(container, 'hilos-table-search') as HTMLInputElement,
      {
        target: { value: 'nothing' },
      },
    )

    expect(byId(container, 'hilos-log-worker-empty-nomatch')).not.toBeNull()
    expect(byId(container, 'hilos-log-worker-clear-filters')).not.toBeNull()
  })

  it('shows no node column and no node filter where nodes have no names', () => {
    const { connection, pushHeader } = makeConnection()
    const container = mountPage(connection)

    pushHeader(header({ nodes: [] }))

    expect(byId(container, 'hilos-log-worker-node')).toBeNull()
    expect(byId(container, 'hilos-table-sort-node')).toBeNull()
  })

  it('offers the node column and the node filter once the picture names nodes', () => {
    const { connection, pushHeader } = makeConnection()
    const container = mountPage(connection)

    pushHeader(header({ nodes: ['node-1', 'node-2'] }))

    const select = byId(container, 'hilos-log-worker-node')
    expect(select).not.toBeNull()
    expect(select?.textContent).toContain('node-2')
    expect(byId(container, 'hilos-table-sort-node')).not.toBeNull()
  })

  it('sends the type switch to the server as a filter, never narrowing locally', () => {
    const { connection, filters } = makeConnection()
    const container = mountPage(connection)

    fireEvent.click(
      byId(container, 'hilos-log-worker-type-monopolistic') as HTMLElement,
    )

    expect(filters.at(-1)).toEqual({ type: 'monopolistic' })
  })

  it('offers two type buttons and no third, because the panel asks two questions', () => {
    const { connection } = makeConnection()
    const container = mountPage(connection)

    expect(
      container.querySelectorAll('[data-id^="hilos-log-worker-type-"]'),
    ).toHaveLength(2)
    expect(byId(container, 'hilos-log-worker-type-regular')).toBeNull()
  })

  it('tells the monopolistic worker from an ordinary one by its badge', () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const container = mountPage(connection)

    pushHeader(header())
    pushWindow([
      stream(),
      stream({
        rowKey: '-:worker-monopolistic-truth.log',
        key: 'worker-monopolistic-truth.log',
        type: 'monopolistic',
      }),
    ])

    const badges = Array.from(
      container.querySelectorAll('[data-id^="hilos-table-row-"] .badge'),
    )
    expect(badges[0].textContent).toBe('Ordinary')
    expect(badges[0].classList).toContain('text-bg-light')
    const monopolistic = badges.find(
      (badge) => badge.textContent === 'Monopolistic',
    )
    expect(monopolistic?.classList).toContain('text-bg-info-subtle')
  })

  /**
   * Below `lg` the node and weight columns are hidden and their values move into a
   * sub-line under the key. The single-node installation is the case worth holding:
   * only one of the two is hidden there, and a sub-line drawn for clusters alone
   * would leave a narrow screen with no figures at all.
   */
  it('carries the hidden weight into the sub-line with no node names', () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const container = mountPage(connection)

    pushHeader(header({ nodes: [] }))
    pushWindow([stream()])

    expect(subLine(container)).not.toBeNull()
    expect(subLine(container)?.textContent).toBe('1.5 GB')
  })

  it('carries the node into that sub-line as well where nodes have names', () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const container = mountPage(connection)

    pushHeader(header({ nodes: ['node-1'] }))
    pushWindow([stream({ rowKey: 'node-1:worker-0.log', node: 'node-1' })])

    expect(subLine(container)?.textContent).toBe('node-1 · 1.5 GB')
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
      byId(
        container,
        'hilos-log-worker-open-node-1:worker-0.log',
      )?.getAttribute('href'),
    ).toBe('/hilos/logs/view/node-1/live/worker-0.log')
    expect(
      byId(
        container,
        'hilos-log-worker-open-node-1:worker-1.log',
      )?.getAttribute('href'),
    ).toBe('/hilos/logs/view/node-1/1799999000/worker-1.log')
  })

  /**
   * The footnote is the one place the screen explains itself, and the explanation is
   * not the same one in the two installations: in a cluster the node column is part
   * of the answer, and in a single-node installation there is no node to point at.
   */
  it('explains itself by the cluster wording only where nodes have names', () => {
    const { connection, pushHeader } = makeConnection()
    const container = mountPage(connection)

    pushHeader(header({ nodes: [] }))
    expect(container.querySelector('.alert')?.textContent).toContain(
      'two hands',
    )

    pushHeader(header({ nodes: ['node-1'] }))
    expect(container.querySelector('.alert')?.textContent).toContain(
      'different machines',
    )
  })
})

import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import {
  HilosPages,
  ROTATIONS_HEADER_SIGNAL,
  ScopeManager,
  createSignal,
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

import { HilosLogsRotationsPage } from '../src/admin/logs/HilosLogsRotationsPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

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
    act(() => {
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
    })
  }

  return {
    connection,
    pushHeader(frame: HilosLogRotationsHeader): void {
      act(() => {
        for (const listener of projectListeners) {
          listener({ type: ROTATIONS_HEADER_SIGNAL, data: frame })
        }
      })
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
    ...overrides,
  }
}

/** A real page scope, because a window of rows is normalized into one. */
function makeScopes(): ScopeManager {
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.LOGS_ROTATIONS)

  return scopes
}

function mountPage(
  connection: HilosConnection,
  actions: ActionLifecycle = makeActions().actions,
): HTMLElement {
  return render(
    <HilosRouterContext.Provider value={router()}>
      <HilosLogsRotationsPage
        context={{ connection, scopes: makeScopes(), actions }}
      />
    </HilosRouterContext.Provider>,
  ).container
}

function byId(container: HTMLElement, id: string): HTMLElement | null {
  return container.querySelector(`[data-id="${id}"]`)
}

function subLine(container: HTMLElement): HTMLElement | null {
  return container.querySelector('[data-id^="hilos-table-row-"] .d-lg-none')
}

/** Wait out the microtasks a settled action resolves through. */
async function settled(): Promise<void> {
  await act(async () => {
    await Promise.resolve()
  })
}

describe('HilosLogsRotationsPage', () => {
  // The takeout dialog is portalled to the document body, so a page left mounted
  // would leave its modal there for the next case to find.
  afterEach(cleanup)

  it('waits rather than reporting a fault before any picture arrives', () => {
    const { connection, pushEmptyWindow } = makeConnection()
    const container = mountPage(connection)

    pushEmptyWindow()

    expect(byId(container, 'hilos-rotation-empty-unknown')).not.toBeNull()
  })

  it('reports the fault once the picture says no node could be read', () => {
    const { connection, pushHeader, pushEmptyWindow } = makeConnection()
    const container = mountPage(connection)

    pushHeader(header({ available: false }))
    pushEmptyWindow()

    expect(byId(container, 'hilos-rotation-empty-unreadable')).not.toBeNull()
  })

  it('says nothing has rotated yet when the archive is simply empty', () => {
    const { connection, pushHeader, pushEmptyWindow } = makeConnection()
    const container = mountPage(connection)

    pushHeader(header())
    pushEmptyWindow()

    expect(byId(container, 'hilos-rotation-empty-never')).not.toBeNull()
  })

  it('tells a search that matched nothing from an archive that is empty', () => {
    const { connection, pushHeader, pushEmptyWindow } = makeConnection()
    const container = mountPage(connection)
    pushHeader(header())
    pushEmptyWindow()

    fireEvent.change(
      byId(container, 'hilos-table-search') as HTMLInputElement,
      {
        target: { value: '2019-01-01' },
      },
    )

    expect(byId(container, 'hilos-rotation-empty-nomatch')).not.toBeNull()
    expect(byId(container, 'hilos-rotation-clear-filters')).not.toBeNull()
  })

  it('shows no node column and no node filter where nodes have no names', () => {
    const { connection, pushHeader } = makeConnection()
    const container = mountPage(connection)

    pushHeader(header({ nodes: [] }))

    expect(byId(container, 'hilos-rotation-node')).toBeNull()
    expect(byId(container, 'hilos-table-sort-node')).toBeNull()
  })

  it('offers the node column and the node filter once the picture names nodes', () => {
    const { connection, pushHeader } = makeConnection()
    const container = mountPage(connection)

    pushHeader(header({ nodes: ['node-1', 'node-2'] }))

    const select = byId(container, 'hilos-rotation-node')
    expect(select).not.toBeNull()
    expect(select?.textContent).toContain('node-2')
    expect(byId(container, 'hilos-table-sort-node')).not.toBeNull()
  })

  it('prints the rules in force rather than a preset name', () => {
    const { connection, pushHeader } = makeConnection()
    const container = mountPage(connection)

    pushHeader(header())

    expect(byId(container, 'hilos-rotation-rule')?.textContent).toBe(
      'Rotates on the schedule 0 4 * * *',
    )
    expect(container.textContent).toContain(
      'Recommends carrying off a batch outside the newest 7 and older than 30 d',
    )
  })

  /**
   * Below `lg` the node and weight columns are hidden and their values move into a
   * sub-line under the batch name. The single-node installation is the case that
   * broke: only the weight is hidden there, and a sub-line that appeared for
   * clusters alone left a narrow screen with no weight anywhere.
   */
  it('carries the hidden weight into the sub-line even with no node names', () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const container = mountPage(connection)

    pushHeader(header({ nodes: [] }))
    pushWindow([batch()])

    expect(subLine(container)).not.toBeNull()
    expect(subLine(container)?.textContent).toBe('1.5 GB')
  })

  it('carries the node into that sub-line as well where nodes have names', () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const container = mountPage(connection)

    pushHeader(header({ nodes: ['node-1'] }))
    pushWindow([batch({ node: 'node-1' })])

    expect(subLine(container)?.textContent).toBe('node-1 · 1.5 GB')
  })

  it('offers the takeout only on a batch the rule recommends carrying off', () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const container = mountPage(connection)

    pushHeader(header())
    pushWindow([
      batch({ rowKey: 'a:1', batchAt: 1, retentionState: 'kept' }),
      batch({ rowKey: 'a:2', batchAt: 2, retentionState: 'due' }),
      batch({ rowKey: 'a:3', batchAt: 3, retentionState: 'taken' }),
    ])

    expect(
      container.querySelectorAll('[data-id="hilos-rotation-takeout"]'),
    ).toHaveLength(1)
  })

  it('says where the batch lies and how to copy it off, node first in a cluster', () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const container = mountPage(connection)

    pushHeader(header({ nodes: ['node-1'] }))
    pushWindow([batch({ node: 'node-1', retentionState: 'due' })])
    fireEvent.click(byId(container, 'hilos-rotation-takeout') as HTMLElement)

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
  it('offers no address at all when the holding node reported no log root', () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const container = mountPage(connection)

    pushHeader(header())
    pushWindow([batch({ absolutePath: null, retentionState: 'due' })])
    fireEvent.click(byId(container, 'hilos-rotation-takeout') as HTMLElement)

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
    const container = mountPage(connection, actions)

    pushHeader(header({ nodes: ['node-1'] }))
    pushWindow([batch({ node: 'node-1', retentionState: 'due' })])
    fireEvent.click(byId(container, 'hilos-rotation-takeout') as HTMLElement)
    fireEvent.click(
      document.querySelector(
        '[data-id="hilos-rotation-takeout-confirm"]',
      ) as HTMLElement,
    )
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

  it('opens the legend modal, which is where the three numbers are explained', () => {
    const { connection } = makeConnection()
    const container = mountPage(connection)

    expect(document.body.textContent).not.toContain('What is in a batch')

    fireEvent.click(byId(container, 'hilos-rotation-legend') as HTMLElement)

    expect(document.body.textContent).toContain('What is in a batch')
  })
})

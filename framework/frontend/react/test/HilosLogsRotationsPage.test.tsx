import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import {
  HIDDEN_VALUE,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosPages,
  ROTATIONS_HEADER_SIGNAL,
  SIGNAL_TYPE_PAGE_RESPONSE,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
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
  ProjectSignal,
  TableViewportDescriptor,
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

/**
 * A navigator entered at `params`, recording the addresses it is rewritten to.
 *
 * @param params The route params the screen was entered with.
 * @param rewrites Collects every address passed to `replacePath`.
 */
function router(
  params: Record<string, string> = {},
  rewrites: string[] = [],
): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.LOGS_ROTATIONS,
      params,
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
    replacePath: (pathname: string) => {
      rewrites.push(pathname)
    },
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
  sent: TableViewportDescriptor[]
  focus: string[]
  pushHeader: (frame: HilosLogRotationsHeader) => void
  pushEmptyWindow: () => void
  pushWindow: (rows: Record<string, unknown>[]) => void
  pushPageWindow: (rows: Record<string, unknown>[]) => void
  pushFocusedChange: (row?: Record<string, unknown>) => void
} {
  const projectListeners: ((signal: {
    type: string
    data: unknown
  }) => void)[] = []
  const windowListeners: ((signal: { data: unknown }) => void)[] = []
  const deltaListeners: ((signal: { data: unknown }) => void)[] = []
  const sent: TableViewportDescriptor[] = []
  const focus: string[] = []
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
      if (event === 'tableViewportDelta') {
        deltaListeners.push(
          listener as unknown as (signal: { data: unknown }) => void,
        )
      }

      return () => {}
    },
    registerTableWindow(): void {},
    unregisterTableWindow(): void {},
    tableWindowDescriptors: () => ({}),
    sendTableViewport(
      _page: string,
      _tableKey: string,
      descriptor: TableViewportDescriptor,
    ): void {
      sent.push(descriptor)
    },
    sendTableRowFocus(
      _page: string,
      _tableKey: string,
      rowKey: string,
    ): boolean {
      focus.push(rowKey)

      return true
    },
    sendTableRendered(): boolean {
      return true
    },
    sendTableFacets(): boolean {
      return true
    },
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
            limit: 25,
          },
        })
      }
    })
  }

  return {
    connection,
    sent,
    focus,
    pushFocusedChange(row?: Record<string, unknown>): void {
      act(() => {
        for (const listener of deltaListeners) {
          listener({
            data: {
              page: HilosPages.LOGS_ROTATIONS,
              tableKey: 'hilosLogRotations',
              kind: 'row_removed',
              rowKey: 'node-1:1800000000',
              reason: row === undefined ? 'deleted' : 'left_set',
              ...(row === undefined
                ? {}
                : {
                    row: { rowKey: String(row.rowKey), slots: { batch: row } },
                  }),
            },
          })
        }
      })
    },
    pushHeader(frame: HilosLogRotationsHeader): void {
      act(() => {
        for (const listener of projectListeners) {
          listener({ type: ROTATIONS_HEADER_SIGNAL, data: frame })
        }
      })
    },
    pushEmptyWindow: () => pushWindow([]),
    pushWindow,
    // The window the page's own answer carries — every batch, whatever the address
    // named, because the page declares the table and not the route's filter.
    pushPageWindow(rows: Record<string, unknown>[]): void {
      act(() => {
        for (const listener of projectListeners) {
          listener({
            type: SIGNAL_TYPE_PAGE_RESPONSE,
            data: {
              page: HilosPages.LOGS_ROTATIONS,
              payload: {
                windows: {
                  hilosLogRotations: {
                    rows: rows.map((slot) => ({
                      rowKey: String(slot.rowKey),
                      slots: { batch: slot },
                    })),
                    sort: [{ field: 'batchAt', direction: 'desc' }],
                    limit: 25,
                    totalCount: rows.length,
                    totalExact: true,
                    firstAnchor: null,
                    lastAnchor: null,
                  },
                },
              },
            },
          })
        }
      })
    },
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
    daemonFileCount: 3,
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

function mountPage(
  connection: HilosConnection,
  actions: ActionLifecycle = makeActions().actions,
  navigator: HilosRouter = router(),
  scopes: ScopeManager = makeScopes(),
): HTMLElement {
  return render(
    <HilosRouterContext.Provider value={navigator}>
      <HilosLogsRotationsPage context={{ connection, scopes, actions }} />
    </HilosRouterContext.Provider>,
  ).container
}

/**
 * Bind the session scope and the admin access the way bootHilos does, over a
 * page scope the connection's handshake feeds.
 */
function bindViewerSession(scopes: ScopeManager): {
  handshake: (
    user: { id: number; admin: boolean } | null,
    viewMode: boolean,
  ) => void
} {
  const listeners: ((signal: ProjectSignal) => void)[] = []
  const handshakeConnection = {
    on(event: string, listener: (payload: never) => void): () => void {
      if (event === 'projectSignal') {
        listeners.push(listener as (signal: ProjectSignal) => void)
      }

      return () => {}
    },
  } as unknown as HilosConnection
  bindSessionScope(handshakeConnection, scopes)
  bindAdminAccess(scopes)

  return {
    handshake(user, viewMode): void {
      const signal = {
        kind: 'project',
        type: 'handshake_response',
        data: {
          entities: {
            currentUser: user === null ? null : { ...user, name: 'Olena' },
          },
          data: { adminViewMode: viewMode },
        },
        envelope: {},
      } as unknown as ProjectSignal
      for (const listener of listeners) {
        listener(signal)
      }
    },
  }
}

function byId(container: HTMLElement, id: string): HTMLElement | null {
  return container.querySelector(`[data-id="${id}"]`)
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

  it('draws each half of a partly hidden rule as its own mark', () => {
    const { connection, pushHeader } = makeConnection()
    const container = mountPage(connection)
    pushHeader(
      header({
        rotationCron: HIDDEN_VALUE,
        retentionKeepBatches: HIDDEN_VALUE,
      }),
    )

    expect(container.querySelectorAll('[data-id="hilos-hidden"]')).toHaveLength(
      2,
    )
    expect(byId(container, 'hilos-rotation-rule')?.textContent).not.toContain(
      'Rotates only when the node restarts',
    )
  })

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

    // This frame opts into the page's own no-match wording and reset control.
    expect(byId(container, 'hilos-rotation-empty-nomatch')).not.toBeNull()
    expect(byId(container, 'hilos-rotation-clear-filters')).not.toBeNull()
    expect(byId(container, 'hilos-rotation-empty-never')).toBeNull()
  })

  it('shows no node column and no node filter where nodes have no names', () => {
    const { connection, pushHeader } = makeConnection()
    const container = mountPage(connection)

    pushHeader(header({ nodes: [] }))

    expect(byId(container, 'hilos-table-title')?.textContent).toBe(
      'Rotation batches',
    )
    expect(
      (byId(container, 'hilos-table-search') as HTMLInputElement).placeholder,
    ).toBe('Search by batch date…')
    expect(byId(container, 'hilos-table-filter-node')).toBeNull()
    expect(byId(container, 'hilos-table-filter-state')).not.toBeNull()
    expect(byId(container, 'hilos-table-sort-node')).toBeNull()
  })

  it('offers the node column and the node filter once the picture names nodes', () => {
    const { connection, pushHeader } = makeConnection()
    const container = mountPage(connection)

    pushHeader(header({ nodes: ['node-1', 'node-2'] }))

    const select = byId(container, 'hilos-table-filter-node')
    expect(select).not.toBeNull()
    expect(select?.textContent).toContain('node-2')
    expect(
      (byId(container, 'hilos-table-search') as HTMLInputElement).placeholder,
    ).toBe('Search by batch date or node…')
    expect(byId(container, 'hilos-table-sort-node')).not.toBeNull()
  })

  it('prints the rules in force rather than a preset name', () => {
    const { connection, pushHeader } = makeConnection()
    const container = mountPage(connection)

    pushHeader(header())

    expect(byId(container, 'hilos-rotation-rule')?.textContent).toBe(
      'Rotates at 04:00',
    )
    expect(container.textContent).toContain(
      'Recommends carrying off a batch outside the newest 7 and older than 30 days',
    )
  })

  it("points the Log settings button at the section's own settings screen", () => {
    const { connection } = makeConnection()
    const container = mountPage(connection)

    expect(
      byId(container, 'hilos-rotation-settings')?.getAttribute('href'),
    ).toBe('/hilos/logs/settings')
  })

  /**
   * The narrow card is the same row, so the weight is a field of it even when the
   * installation names no node and the wide row has no node column.
   */
  it('shows the weight in the wide row and in the card, with no node column', () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const container = mountPage(connection)

    pushHeader(header({ nodes: [] }))
    pushWindow([batch()])

    const row = container.querySelector('[data-id^="hilos-table-row-"]')
    const card = container.querySelector('[data-id^="hilos-table-card-"]')
    expect(row?.textContent).toContain('1.5 GB')
    expect(card?.textContent).toContain('1.5 GB')
    expect(row?.querySelector('.d-lg-none')).toBeNull()
    expect(byId(container, 'hilos-table-count')?.textContent).toContain('of 1')
  })

  /**
   * The Files cell names every class of stream in the batch, the daemon's own
   * first, so the counts add up to what the weight beside them is taken over.
   */
  it('shows the four file counts in the Files cell, daemon first', () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const container = mountPage(connection)

    pushHeader(header())
    pushWindow([batch()])

    expect(
      container.querySelector('[data-id^="hilos-table-row-"]')?.textContent,
    ).toContain('3 / 12 / 8 / 2')
    expect(
      container.querySelector('[data-id^="hilos-table-card-"]')?.textContent,
    ).toContain('3 / 12 / 8 / 2')
  })

  it('shows the node in the wide row and in the card where nodes have names', () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const container = mountPage(connection)

    pushHeader(header({ nodes: ['node-1'] }))
    pushWindow([batch({ node: 'node-1' })])

    expect(
      container.querySelector('[data-id^="hilos-table-row-"]')?.textContent,
    ).toContain('node-1')
    expect(
      container.querySelector('[data-id^="hilos-table-card-"]')?.textContent,
    ).toContain('node-1')
    expect(
      container.querySelector('[data-id^="hilos-table-card-"]')?.textContent,
    ).toContain('1.5 GB')
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
      container.querySelectorAll(
        '[data-id^="hilos-table-row-"] [data-id="hilos-rotation-takeout"]',
      ),
    ).toHaveLength(1)
    expect(
      container.querySelectorAll(
        '[data-id^="hilos-table-card-"] [data-id="hilos-rotation-takeout"]',
      ),
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

    pushHeader(header({ nodes: [] }))
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

  /**
   * The confirmation is not the end of the batch, and the modal that asks for it
   * has to say so: a promise that nothing will be touched would read as though the
   * click could not be taken back, which is the opposite of what the node does.
   */
  it('says the confirmation can still be taken back, while the batch is there', () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const container = mountPage(connection)

    pushHeader(header())
    pushWindow([batch({ retentionState: 'due' })])
    fireEvent.click(byId(container, 'hilos-rotation-takeout') as HTMLElement)

    expect(document.body.textContent).toContain('but not straight away')
    expect(document.body.textContent).not.toContain(
      'until then it will not be touched',
    )
  })

  /**
   * A batch on its way to the archive is a fourth state, and it is the state in
   * which neither action applies: it is not being recommended for carrying off, and
   * nobody has said it was carried off. The badge has to say where it is instead.
   */
  it('shows a batch still being carried as such, and offers it neither action', () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const container = mountPage(connection)

    pushHeader(header())
    pushWindow([
      batch({ rowKey: 'a:1', batchAt: 1, retentionState: 'carrying' }),
    ])

    expect(container.textContent).toContain('Moving to the archive')
    expect(container.querySelector('.badge')?.className).toContain(
      'text-bg-info',
    )
    expect(
      container.querySelectorAll('[data-id="hilos-rotation-takeout"]'),
    ).toHaveLength(0)
    expect(
      container.querySelectorAll('[data-id="hilos-rotation-undo"]'),
    ).toHaveLength(0)
  })

  it('offers the withdrawal only on a batch somebody said was carried off', () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const container = mountPage(connection)

    pushHeader(header())
    pushWindow([
      batch({ rowKey: 'a:1', batchAt: 1, retentionState: 'kept' }),
      batch({ rowKey: 'a:2', batchAt: 2, retentionState: 'due' }),
      batch({ rowKey: 'a:3', batchAt: 3, retentionState: 'taken' }),
    ])

    expect(
      container.querySelectorAll(
        '[data-id^="hilos-table-row-"] [data-id="hilos-rotation-undo"]',
      ),
    ).toHaveLength(1)
    expect(
      container.querySelectorAll(
        '[data-id^="hilos-table-card-"] [data-id="hilos-rotation-undo"]',
      ),
    ).toHaveLength(1)
  })

  /**
   * The deadline is the node's own promise, and the screen has to say it out loud:
   * somebody reading this modal is deciding whether they still have time.
   */
  it('names the instant the batch stops being safe from the cleaner', () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const container = mountPage(connection)

    pushHeader(header())
    pushWindow([batch({ retentionState: 'taken', pruneNotBefore: 1800086400 })])
    fireEvent.click(byId(container, 'hilos-rotation-undo') as HTMLElement)

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
  it('says the cleaner may come at its next pass when the node will not wait', () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const container = mountPage(connection)

    pushHeader(header())
    pushWindow([batch({ retentionState: 'taken', pruneNotBefore: null })])
    fireEvent.click(byId(container, 'hilos-rotation-undo') as HTMLElement)

    expect(
      document.querySelector('[data-id="hilos-rotation-undo-deadline"]')
        ?.textContent,
    ).toContain('as soon as it next runs')
  })

  it("withdraws under its own action name, and closes only on the server's word", async () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const { actions, dispatched } = makeActions()
    const container = mountPage(connection, actions)

    pushHeader(header({ nodes: ['node-1'] }))
    pushWindow([batch({ node: 'node-1', retentionState: 'taken' })])
    fireEvent.click(byId(container, 'hilos-rotation-undo') as HTMLElement)
    fireEvent.click(
      document.querySelector(
        '[data-id="hilos-rotation-undo-confirm"]',
      ) as HTMLElement,
    )
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

  it('opens the legend modal, which is where the four numbers are explained', () => {
    const { connection } = makeConnection()
    const container = mountPage(connection)

    expect(document.body.textContent).not.toContain('What is in a batch')

    fireEvent.click(byId(container, 'hilos-rotation-legend') as HTMLElement)

    expect(document.body.textContent).toContain('What is in a batch')

    // The legend edits nothing, so its one way out through the footer is a
    // single Close button - not the OK that used to do nothing.
    const close = document.querySelector(
      '[data-id="hilos-rotation-legend-close"]',
    )
    expect(close).not.toBeNull()

    fireEvent.click(close as Element)

    expect(document.body.textContent).not.toContain('What is in a batch')
  })

  it('opens on Awaiting carry-off when entered by the awaiting address', () => {
    const { connection, sent, pushPageWindow } = makeConnection()
    const container = mountPage(
      connection,
      makeActions().actions,
      router({ state: 'due' }),
    )

    pushPageWindow([batch()])

    // The page's unfiltered window is not drawn; the table asks for the awaiting one.
    expect(container.textContent).not.toContain('archive/2027-01-15-08-00-00/')
    expect(
      (byId(container, 'hilos-table-filter-state') as HTMLInputElement).checked,
    ).toBe(true)
    expect(sent[0]?.filter).toEqual({ state: 'due' })
  })

  it('rewrites the address in place when the switch moves to All', () => {
    const { connection } = makeConnection()
    const rewrites: string[] = []
    const container = mountPage(
      connection,
      makeActions().actions,
      router({ state: 'due' }, rewrites),
    )

    act(() => {
      fireEvent.click(byId(container, 'hilos-table-filter-state') as Element)
    })

    expect(rewrites).toEqual(['/hilos/logs/rotations'])
    expect(
      (byId(container, 'hilos-table-filter-state') as HTMLInputElement).checked,
    ).toBe(false)
  })
})

describe('HilosLogsRotationsPage in the admin view mode', () => {
  afterEach(cleanup)

  it('a viewer opens the takeout dialog and has nothing to confirm it with', async () => {
    const scopes = makeScopes()
    bindViewerSession(scopes).handshake(null, true)
    const { connection, pushHeader, pushWindow } = makeConnection()
    const { actions, dispatched } = makeActions()
    const container = mountPage(connection, actions, router(), scopes)

    pushHeader(header({ nodes: ['node-1'] }))
    pushWindow([batch({ node: 'node-1', retentionState: 'due' })])

    const takeout = byId(
      container,
      'hilos-rotation-takeout',
    ) as HTMLButtonElement
    expect(takeout.disabled).toBe(false)
    fireEvent.click(takeout)

    const confirm = document.querySelector<HTMLButtonElement>(
      '[data-id="hilos-rotation-takeout-confirm"]',
    )
    expect(confirm).not.toBeNull()
    expect(confirm?.disabled).toBe(true)
    expect(confirm?.getAttribute('aria-describedby')).toBe(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    fireEvent.click(confirm as HTMLElement)
    await settled()
    expect(dispatched).toHaveLength(0)

    const close = document.querySelector<HTMLButtonElement>(
      '[data-id="hilos-rotation-takeout-close"]',
    )
    expect(close).not.toBeNull()
    expect(close?.disabled).toBe(false)
    fireEvent.click(close as HTMLElement)

    expect(
      document.querySelector('[data-id="hilos-rotation-takeout-confirm"]'),
    ).toBeNull()
  })

  it('a viewer opens the withdrawal dialog and has nothing to withdraw with', async () => {
    const scopes = makeScopes()
    bindViewerSession(scopes).handshake(null, true)
    const { connection, pushHeader, pushWindow } = makeConnection()
    const { actions, dispatched } = makeActions()
    const container = mountPage(connection, actions, router(), scopes)

    pushHeader(header({ nodes: ['node-1'] }))
    pushWindow([batch({ node: 'node-1', retentionState: 'taken' })])

    const undo = byId(container, 'hilos-rotation-undo') as HTMLButtonElement
    expect(undo.disabled).toBe(false)
    fireEvent.click(undo)

    const confirm = document.querySelector<HTMLButtonElement>(
      '[data-id="hilos-rotation-undo-confirm"]',
    )
    expect(confirm).not.toBeNull()
    expect(confirm?.disabled).toBe(true)
    expect(confirm?.getAttribute('aria-describedby')).toBe(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )

    fireEvent.click(confirm as HTMLElement)
    await settled()
    expect(dispatched).toHaveLength(0)

    const cancel = document.querySelector<HTMLButtonElement>(
      '[data-id="hilos-rotation-undo-cancel"]',
    )
    expect(cancel).not.toBeNull()
    expect(cancel?.disabled).toBe(false)
    fireEvent.click(cancel as HTMLElement)

    expect(
      document.querySelector('[data-id="hilos-rotation-undo-confirm"]'),
    ).toBeNull()
  })

  it('a viewer filters the batches and reads the legend as an admin does', () => {
    const scopes = makeScopes()
    bindViewerSession(scopes).handshake(null, true)
    const { connection } = makeConnection()
    const container = mountPage(
      connection,
      makeActions().actions,
      router(),
      scopes,
    )

    const dueSwitch = byId(
      container,
      'hilos-table-filter-state',
    ) as HTMLInputElement
    expect(dueSwitch.disabled).toBe(false)
    fireEvent.click(dueSwitch)
    expect(dueSwitch.checked).toBe(true)

    const legend = byId(container, 'hilos-rotation-legend') as HTMLButtonElement
    expect(legend.disabled).toBe(false)
    fireEvent.click(legend)
    expect(document.body.textContent).toContain('What is in a batch')

    const close = document.querySelector<HTMLButtonElement>(
      '[data-id="hilos-rotation-legend-close"]',
    )
    expect(close).not.toBeNull()
    expect(close?.disabled).toBe(false)
    fireEvent.click(close as HTMLElement)
    expect(document.body.textContent).not.toContain('What is in a batch')
  })

  it('an admin on a node in the mode confirms a takeout as today', async () => {
    const scopes = makeScopes()
    bindViewerSession(scopes).handshake({ id: 1, admin: true }, true)
    const { connection, pushHeader, pushWindow } = makeConnection()
    const { actions, dispatched } = makeActions()
    const container = mountPage(connection, actions, router(), scopes)

    pushHeader(header({ nodes: ['node-1'] }))
    pushWindow([batch({ node: 'node-1', retentionState: 'due' })])

    fireEvent.click(byId(container, 'hilos-rotation-takeout') as HTMLElement)
    const confirm = document.querySelector<HTMLButtonElement>(
      '[data-id="hilos-rotation-takeout-confirm"]',
    )
    expect(confirm).not.toBeNull()
    expect(confirm?.disabled).toBe(false)
    expect(confirm?.getAttribute('aria-describedby')).toBeNull()

    fireEvent.click(confirm as HTMLElement)
    await settled()

    expect(dispatched).toMatchObject([
      {
        action: 'logs_takeout_confirm',
        payload: { nodeId: 'node-1', batchTimestamp: 1800000000 },
      },
    ])
  })
})

describe('HilosLogsRotationsPage dialogs following a batch', () => {
  afterEach(cleanup)

  it('takes the batch into focus, warns when protected, and releases it on close', () => {
    const { connection, focus, pushWindow, pushFocusedChange } =
      makeConnection()
    const container = mountPage(connection)
    pushWindow([batch({ retentionState: 'due' })])

    fireEvent.click(byId(container, 'hilos-rotation-takeout') as Element)
    expect(focus).toEqual(['node-1:1800000000'])
    pushFocusedChange(batch({ retentionState: 'kept' }))
    expect(
      document.querySelector('[data-id="hilos-rotation-takeout-notice"]')
        ?.textContent,
    ).toContain('This batch is protected again')
    expect(
      (
        document.querySelector(
          '[data-id="hilos-rotation-takeout-confirm"]',
        ) as HTMLButtonElement
      ).disabled,
    ).toBe(true)

    fireEvent.click(
      document.querySelector('[data-id="modal-close"]') as Element,
    )
    expect(focus.at(-1)).toBe('')
  })

  it.each([
    [
      'taken',
      batch({ retentionState: 'taken' }),
      'Already recorded as carried off elsewhere.',
    ],
    ['gone', undefined, 'This batch is no longer on the node.'],
  ])('warns when the takeout batch is %s', (_name, live, message) => {
    const { connection, pushWindow, pushFocusedChange } = makeConnection()
    const container = mountPage(connection)
    pushWindow([batch({ retentionState: 'due' })])
    fireEvent.click(byId(container, 'hilos-rotation-takeout') as Element)

    pushFocusedChange(live)
    expect(
      document.querySelector('[data-id="hilos-rotation-takeout-notice"]')
        ?.textContent,
    ).toContain(message)
    expect(
      (
        document.querySelector(
          '[data-id="hilos-rotation-takeout-confirm"]',
        ) as HTMLButtonElement
      ).disabled,
    ).toBe(true)
  })

  it('hides its own change while confirmation is in flight', () => {
    const { connection, pushWindow, pushFocusedChange } = makeConnection()
    const { actions } = makeActions()
    const container = mountPage(connection, actions)
    pushWindow([batch({ retentionState: 'due' })])
    fireEvent.click(byId(container, 'hilos-rotation-takeout') as Element)
    fireEvent.click(
      document.querySelector(
        '[data-id="hilos-rotation-takeout-confirm"]',
      ) as Element,
    )

    pushFocusedChange(batch({ retentionState: 'taken' }))
    expect(
      document.querySelector('[data-id="hilos-rotation-takeout-notice"]'),
    ).toBeNull()
  })

  it.each([
    [
      'withdrawn',
      batch({ retentionState: 'due' }),
      'Already withdrawn elsewhere.',
    ],
    ['gone', undefined, 'This batch is no longer on the node.'],
  ])('warns when the acknowledged batch is %s', (_name, live, message) => {
    const { connection, focus, pushWindow, pushFocusedChange } =
      makeConnection()
    const container = mountPage(connection)
    pushWindow([batch({ retentionState: 'taken' })])
    fireEvent.click(byId(container, 'hilos-rotation-undo') as Element)
    expect(focus).toEqual(['node-1:1800000000'])

    pushFocusedChange(live)
    expect(
      document.querySelector('[data-id="hilos-rotation-undo-notice"]')
        ?.textContent,
    ).toContain(message)
    expect(
      (
        document.querySelector(
          '[data-id="hilos-rotation-undo-confirm"]',
        ) as HTMLButtonElement
      ).disabled,
    ).toBe(true)
    fireEvent.click(
      document.querySelector('[data-id="modal-close"]') as Element,
    )
    expect(focus.at(-1)).toBe('')
  })
})

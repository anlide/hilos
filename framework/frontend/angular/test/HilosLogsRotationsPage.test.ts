// The Angular peer of vue/src/admin/logs/HilosLogsRotationsPage.test.ts and
// react/test/HilosLogsRotationsPage.test.tsx: the two commands of the rotation
// history screen — carrying a batch off, and taking that word back (HIL-759) —
// plus the fourth batch state the badge has to name (HIL-870).
//
// Only the cases about the withdrawal, about the takeout modal's promise and about
// the Files cell are here, by the same names the other two shells give them. The
// empty states, the filters and the sub-line are the core headless's
// discrimination and are proved once, in the peers; a third copy of them would
// test @hilos/core through three view layers rather than test this view.
//
// The world below — the connection, the action lifecycle and the row on the wire —
// is the React peer's, moved over verbatim: it is written against @hilos/core and
// knows nothing about a view framework. What is Angular's own is the mount
// (TestBed) and the fact that a frame is read by running change detection rather
// than by awaiting a tick.
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  createSignal,
  HilosPages,
  ROTATIONS_HEADER_SIGNAL,
  ScopeManager,
  type ActionHandle,
  type ActionLifecycle,
  type ActionResult,
  type HilosConnection,
  type HilosLogRotationsHeader,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core'
import { describe, expect, it } from 'vitest'

import { HilosLogsRotationsPage } from '../src/admin/logs/HilosLogsRotationsPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

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
  } as unknown as HilosRouter
}

/**
 * A connection stub that hands back the two frames this screen lives on: the
 * page's own header, and the window of the rotations table. The window matters
 * even when it is empty — until one arrives the table is loading.
 *
 * @returns The connection plus the two pushes a case drives it with.
 */
function makeConnection(): {
  connection: HilosConnection
  pushHeader: (frame: HilosLogRotationsHeader) => void
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
    registerTableWindow(): void {},
    unregisterTableWindow(): void {},
    tableWindowDescriptors: () => ({}),
    sendTableViewport(): void {},
  } as unknown as HilosConnection

  return {
    connection,
    pushHeader(frame: HilosLogRotationsHeader): void {
      for (const listener of projectListeners) {
        listener({ type: ROTATIONS_HEADER_SIGNAL, data: frame })
      }
    },
    pushWindow(rows: Record<string, unknown>[]): void {
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
 * the test: the withdrawal dialog closes on the server's word, so a fake that
 * settled by itself would hide exactly the step under test.
 *
 * @returns The lifecycle to mount with, and the log the assertions read.
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

/**
 * Mount the screen with the router an app provides it with. `context` is a
 * required input, so it holds a value before the first change detection reads it.
 *
 * @param connection The connection the two frames arrive over.
 * @param actions The action lifecycle a dispatch travels through.
 * @returns The mounted fixture.
 */
function mountPage(
  connection: HilosConnection,
  actions: ActionLifecycle = makeActions().actions,
): ComponentFixture<HilosLogsRotationsPage> {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router() }],
  })

  const fixture = TestBed.createComponent(HilosLogsRotationsPage)
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.LOGS_ROTATIONS)
  fixture.componentRef.setInput('context', { connection, scopes, actions })
  fixture.detectChanges()

  return fixture
}

/**
 * Find a node of the mounted screen by its stable test id. The Angular modal
 * renders in place rather than through a portal, so one root covers the screen
 * and its dialogs alike.
 *
 * @param fixture The mounted screen to look inside.
 * @param id The `data-id` the screen renders on the node.
 * @returns The node, or null when this state does not render it.
 */
function byId(
  fixture: ComponentFixture<HilosLogsRotationsPage>,
  id: string,
): HTMLElement | null {
  return (fixture.nativeElement as HTMLElement).querySelector(
    `[data-id="${id}"]`,
  )
}

/**
 * Every node of the mounted screen carrying one test id.
 *
 * @param fixture The mounted screen to look inside.
 * @param id The `data-id` the screen renders on the nodes.
 * @returns The nodes, in document order.
 */
function allById(
  fixture: ComponentFixture<HilosLogsRotationsPage>,
  id: string,
): NodeListOf<HTMLElement> {
  return (fixture.nativeElement as HTMLElement).querySelectorAll(
    `[data-id="${id}"]`,
  )
}

/**
 * Click a node the screen is expected to be offering, and render what follows.
 *
 * @param fixture The mounted screen to click inside.
 * @param id The `data-id` the screen renders on the node.
 * @throws Error When this state does not render the node at all.
 */
function clickById(
  fixture: ComponentFixture<HilosLogsRotationsPage>,
  id: string,
): void {
  const node = byId(fixture, id)
  if (node === null) {
    throw new Error(`the screen is not offering a node with data-id="${id}"`)
  }
  node.click()
  fixture.detectChanges()
}

/**
 * Let the microtasks a settled action resolves through run out, then render.
 *
 * @param fixture The mounted screen to flush the render of.
 */
async function settled(
  fixture: ComponentFixture<HilosLogsRotationsPage>,
): Promise<void> {
  for (let tick = 0; tick < 10; tick += 1) {
    await Promise.resolve()
  }
  fixture.detectChanges()
}

/**
 * The text of the whole mounted screen, dialogs included.
 *
 * @param fixture The mounted screen to read.
 * @returns Everything the screen is currently saying.
 */
function screenText(fixture: ComponentFixture<HilosLogsRotationsPage>): string {
  return (fixture.nativeElement as HTMLElement).textContent ?? ''
}

describe('HilosLogsRotationsPage', () => {
  it("points the Log settings button at the section's own settings screen", () => {
    const { connection } = makeConnection()
    const fixture = mountPage(connection)

    expect(byId(fixture, 'hilos-rotation-settings')?.getAttribute('href')).toBe(
      '/hilos/logs/settings',
    )
  })

  /**
   * The confirmation is not the end of the batch, and the modal that asks for it
   * has to say so: a promise that nothing will be touched would read as though the
   * click could not be taken back, which is the opposite of what the node does.
   */
  it('says the confirmation can still be taken back, while the batch is there', () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const fixture = mountPage(connection)

    pushHeader(header())
    pushWindow([batch({ retentionState: 'due' })])
    fixture.detectChanges()
    clickById(fixture, 'hilos-rotation-takeout')

    expect(screenText(fixture)).toContain('but not straight away')
    expect(screenText(fixture)).not.toContain(
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
    const fixture = mountPage(connection)

    pushHeader(header())
    pushWindow([
      batch({ rowKey: 'a:1', batchAt: 1, retentionState: 'carrying' }),
    ])
    fixture.detectChanges()

    expect(screenText(fixture)).toContain('Moving to the archive')
    expect(
      (fixture.nativeElement as HTMLElement).querySelector('.badge')?.className,
    ).toContain('text-bg-info')
    expect(allById(fixture, 'hilos-rotation-takeout')).toHaveLength(0)
    expect(allById(fixture, 'hilos-rotation-undo')).toHaveLength(0)
  })

  /**
   * The Files cell names every class of stream in the batch, the daemon's own
   * first, so the counts add up to what the weight beside them is taken over.
   */
  it('shows the four file counts in the Files cell, daemon first', () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const fixture = mountPage(connection)

    pushHeader(header())
    pushWindow([batch()])
    fixture.detectChanges()

    expect(
      (fixture.nativeElement as HTMLElement)
        .querySelector('[data-id^="hilos-table-row-"] td.small')
        ?.textContent?.trim(),
    ).toBe('3 / 12 / 8 / 2')
  })

  it('offers the withdrawal only on a batch somebody said was carried off', () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const fixture = mountPage(connection)

    pushHeader(header())
    pushWindow([
      batch({ rowKey: 'a:1', batchAt: 1, retentionState: 'kept' }),
      batch({ rowKey: 'a:2', batchAt: 2, retentionState: 'due' }),
      batch({ rowKey: 'a:3', batchAt: 3, retentionState: 'taken' }),
    ])
    fixture.detectChanges()

    expect(allById(fixture, 'hilos-rotation-undo')).toHaveLength(1)
  })

  /**
   * The deadline is the node's own promise, and the screen has to say it out loud:
   * somebody reading this modal is deciding whether they still have time.
   */
  it('names the instant the batch stops being safe from the cleaner', () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const fixture = mountPage(connection)

    pushHeader(header())
    pushWindow([batch({ retentionState: 'taken', pruneNotBefore: 1800086400 })])
    fixture.detectChanges()
    clickById(fixture, 'hilos-rotation-undo')

    expect(
      byId(fixture, 'hilos-rotation-undo-deadline')?.textContent,
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
    const fixture = mountPage(connection)

    pushHeader(header())
    pushWindow([batch({ retentionState: 'taken', pruneNotBefore: null })])
    fixture.detectChanges()
    clickById(fixture, 'hilos-rotation-undo')

    expect(
      byId(fixture, 'hilos-rotation-undo-deadline')?.textContent,
    ).toContain('as soon as it next runs')
  })

  it("withdraws under its own action name, and closes only on the server's word", async () => {
    const { connection, pushHeader, pushWindow } = makeConnection()
    const { actions, dispatched } = makeActions()
    const fixture = mountPage(connection, actions)

    pushHeader(header({ nodes: ['node-1'] }))
    pushWindow([batch({ node: 'node-1', retentionState: 'taken' })])
    fixture.detectChanges()
    clickById(fixture, 'hilos-rotation-undo')
    clickById(fixture, 'hilos-rotation-undo-confirm')
    await settled(fixture)

    expect(dispatched).toMatchObject([
      {
        action: 'logs_takeout_undo',
        payload: { nodeId: 'node-1', batchTimestamp: 1800000000 },
      },
    ])
    expect(screenText(fixture)).toContain('Has the batch not been carried off?')

    dispatched[0]?.settle({ action: 'logs_takeout_undo' } as ActionResult)
    await settled(fixture)

    expect(screenText(fixture)).not.toContain(
      'Has the batch not been carried off?',
    )
  })
})

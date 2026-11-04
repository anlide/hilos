// The Angular overview renders independent summary and node-window pushes, with
// reader-zone dates, takeout links, hidden measurements and threshold wording.
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  createSignal,
  HIDDEN_VALUE,
  HilosPages,
  OVERVIEW_SIGNAL,
  ScopeManager,
  type HilosConnection,
  type HilosLogsOverview,
  type HilosLogsOverviewNode,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core/base'
import type { HilosLogNodeRow } from '@hilos/core/admin/logs'
import { describe, expect, it, vi } from 'vitest'

import { HilosLogsPage } from '../src/admin/logs/HilosLogsPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'
import { bindTestTimeZone } from './timeZoneTestHarness.js'

/** One node's row as the overview carries it. */
function node(
  overrides: Partial<HilosLogsOverviewNode> = {},
): HilosLogsOverviewNode {
  return {
    nodeId: 'node-1',
    nodeName: null,
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
  } as unknown as HilosRouter
}

const ROTATION_MOMENT = Date.parse('2026-03-29T01:30:00.123Z')

/** A table row, independent of the full overview's node list. */
function tableNode(overrides: Partial<HilosLogNodeRow> = {}): HilosLogNodeRow {
  return {
    nodeId: 'node-1',
    nodeName: null,
    available: true,
    lastRotationAt: ROTATION_MOMENT,
    liveBytes: 1024,
    archiveBytes: 4096,
    growthBytesPerDay: 512,
    batchesDueForTakeout: 0,
    ...overrides,
  }
}

/** The overview and table push paths, normalized into real page-scoped stores. */
function makeConnection() {
  type Frame = { type: string; data: unknown }
  const listeners = new Map<string, Set<(signal: Frame) => void>>()
  const sendTableViewport = vi.fn()
  const registerTableWindow = vi.fn()
  const unregisterTableWindow = vi.fn()
  const connection = {
    on(event: string, listener: (signal: never) => void): () => void {
      const receive = listener as unknown as (signal: Frame) => void
      const set = listeners.get(event) ?? new Set()
      set.add(receive)
      listeners.set(event, set)
      return () => set.delete(receive)
    },
    sendTableViewport,
    registerTableWindow,
    unregisterTableWindow,
    sendTableRendered: vi.fn(),
    sendTableFacets: vi.fn(),
    tableWindowDescriptors: () => ({}),
  } as unknown as HilosConnection
  const emit = (event: string, type: string, data: unknown): void => {
    for (const listener of listeners.get(event) ?? []) {
      listener({ type, data })
    }
  }
  const window = (rows: HilosLogNodeRow[], totalCount = rows.length) => ({
    filter: {},
    rows: rows.map((slot) => ({
      rowKey: slot.nodeId,
      slots: { node: { ...slot } },
    })),
    totalCount,
    totalExact: true,
    sort: [{ field: 'nodeId', direction: 'asc' }],
    limit: 25,
    rowsBefore: 0,
    firstAnchor: rows.length ? { nodeId: rows[0].nodeId } : null,
    lastAnchor: rows.length ? { nodeId: rows.at(-1)!.nodeId } : null,
  })

  return {
    connection,
    sendTableViewport,
    registerTableWindow,
    unregisterTableWindow,
    listenerCount: () =>
      [...listeners.values()].reduce((count, set) => count + set.size, 0),
    push(frame: HilosLogsOverview): void {
      emit('projectSignal', OVERVIEW_SIGNAL, frame)
    },
    pushPageWindow(rows: HilosLogNodeRow[], totalCount = rows.length): void {
      emit('projectSignal', 'page_response', {
        page: HilosPages.LOGS,
        payload: { windows: { hilosLogNodes: window(rows, totalCount) } },
      })
    },
    pushWindow(rows: HilosLogNodeRow[]): void {
      emit('tableWindow', 'table_window', {
        page: HilosPages.LOGS,
        tableKey: 'hilosLogNodes',
        ...window(rows),
      })
    },
  }
}

/**
 * Mount the screen with the router an app provides it with. `context` is a
 * required input, so it holds a value before the first change detection reads it.
 *
 * @param connection The connection the frame arrives over.
 * @returns The mounted fixture.
 */
function mountPage(
  connection: HilosConnection,
): ComponentFixture<HilosLogsPage> {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router() }],
  })

  const fixture = TestBed.createComponent(HilosLogsPage)
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.LOGS)
  fixture.componentRef.setInput('context', { connection, scopes })
  fixture.detectChanges()

  return fixture
}

/**
 * The text of one node of the mounted screen, found by its stable test id.
 *
 * @param fixture The mounted screen to look inside.
 * @param id The `data-id` the screen renders on the node.
 * @returns The node's text, or an empty string when this state does not render it.
 */
function textOf(fixture: ComponentFixture<HilosLogsPage>, id: string): string {
  return (
    (fixture.nativeElement as HTMLElement).querySelector(`[data-id="${id}"]`)
      ?.textContent ?? ''
  )
}

describe('HilosLogsPage', () => {
  it('names nodes from the table window with separate ids and a null fallback', () => {
    const { connection, push, pushPageWindow, sendTableViewport } =
      makeConnection()
    const fixture = mountPage(connection)
    const root = fixture.nativeElement as HTMLElement
    const name = '<b>Узел & β</b>'
    push(overview({ nodes: [node({ nodeName: 'Summary only' })] }))
    pushPageWindow([
      tableNode({ nodeName: name }),
      tableNode({ nodeId: 'node-2', nodeName: null }),
    ])
    fixture.detectChanges()

    for (const projection of ['table', '[data-id="hilos-table-cards"]']) {
      const named = root.querySelector(
        `${projection} [data-id="hilos-logs-node-node-1"]`,
      )!
      expect(named.textContent).toContain(name)
      expect(
        [...named.children].map((part) => part.textContent?.trim()),
      ).toEqual([name, 'node-1'])
      expect(named.querySelector('b')).toBeNull()
      expect(
        root
          .querySelector(`${projection} [data-id="hilos-logs-node-node-2"]`)
          ?.textContent?.trim(),
      ).toBe('node-2')
    }
    expect(root.textContent).not.toContain('Summary only')
    expect(sendTableViewport).not.toHaveBeenCalled()
  })

  it('uses the core node labels in recent origins and the takeout banner', () => {
    const { connection, push, sendTableViewport } = makeConnection()
    const fixture = mountPage(connection)
    push(
      overview({
        batchesDueForTakeout: 2,
        nodes: [
          node({ nodeName: 'Север & <edge>', batchesDueForTakeout: 1 }),
          node({ nodeId: 'node-2', batchesDueForTakeout: 1 }),
        ],
        recentErrors: [
          {
            nodeId: 'node-1',
            nodeName: 'Север & <edge>',
            stream: 'worker-0.log',
            at: '2026-09-02T03:00:00.000Z',
            message: 'Failure',
            traceFrames: 0,
          },
        ],
      }),
    )
    fixture.detectChanges()

    expect(textOf(fixture, 'hilos-logs-takeout')).toContain(
      'Север & <edge> (node-1)',
    )
    expect(textOf(fixture, 'hilos-logs-takeout')).toContain('node-2')
    expect(textOf(fixture, 'hilos-logs-recent-row')).toContain(
      'Север & <edge> (node-1) · worker-0.log',
    )
    const root = fixture.nativeElement as HTMLElement
    expect(root.querySelector('edge')).toBeNull()
    expect(
      root
        .querySelector('[data-id="hilos-logs-recent-row"]')
        ?.getAttribute('href'),
    ).toBe('/hilos/logs/view/node-1/live/worker-0.log/1788318000000')
    expect(sendTableViewport).not.toHaveBeenCalled()
  })

  it('draws a hidden recent message and forecast as separate marks', () => {
    const { connection, push } = makeConnection()
    const fixture = mountPage(connection)
    push(
      overview({
        filesystemFreeBytes: 1_000_000,
        filesystemTotalBytes: 2_000_000,
        freeSpaceThresholdPercent: HIDDEN_VALUE,
        recentErrors: [
          {
            nodeId: '',
            nodeName: null,
            stream: 'worker-0.log',
            at: '2026-09-02T03:00:00.000Z',
            message: HIDDEN_VALUE,
            traceFrames: 2,
          },
        ],
      }),
    )
    fixture.detectChanges()

    const root = fixture.nativeElement as HTMLElement
    expect(
      root.querySelector(
        '[data-id="hilos-logs-growth-forecast"] [data-id="hilos-hidden"]',
      ),
    ).not.toBeNull()
    expect(
      root.querySelector(
        '[data-id="hilos-logs-recent-row"] [data-id="hilos-hidden"]',
      ),
    ).not.toBeNull()
  })

  it('draws the daemon streams as a class of their own, count and weight together', () => {
    const { connection, push } = makeConnection()
    const fixture = mountPage(connection)

    push(overview())
    fixture.detectChanges()

    expect(textOf(fixture, 'hilos-logs-tile-daemon')).toContain('4')
    expect(textOf(fixture, 'hilos-logs-tile-daemon')).toContain('220.0 MB')
  })

  it('keeps the daemon tile empty rather than zero while the figures are not known', () => {
    const { connection, push } = makeConnection()
    const fixture = mountPage(connection)

    push(overview({ logKeysPerDaemon: null, totalWeightDaemonKeysBytes: null }))
    fixture.detectChanges()

    expect(textOf(fixture, 'hilos-logs-tile-daemon')).toContain('—')
    expect(textOf(fixture, 'hilos-logs-tile-daemon')).not.toContain('0')
  })

  it('takes both ways off the screen to the rotations awaiting carry-off', () => {
    const { connection, push, pushPageWindow } = makeConnection()
    const fixture = mountPage(connection)

    push(
      overview({
        batchesDueForTakeout: 2,
        nodes: [node({ nodeId: 'full-node', batchesDueForTakeout: 2 })],
      }),
    )
    pushPageWindow([
      tableNode({ nodeId: 'window-node', batchesDueForTakeout: 3 }),
      tableNode({ nodeId: 'zero-node', batchesDueForTakeout: 0 }),
      tableNode({ nodeId: 'unknown-node', batchesDueForTakeout: null }),
    ])
    fixture.detectChanges()

    const root = fixture.nativeElement as HTMLElement
    expect(
      root
        .querySelector('[data-id="hilos-logs-takeout-open"]')
        ?.getAttribute('href'),
    ).toBe('/hilos/logs/rotations/due')
    expect(
      root
        .querySelector('table [data-id="hilos-logs-node-due-window-node"]')
        ?.getAttribute('href'),
    ).toBe('/hilos/logs/rotations/due')
    expect(textOf(fixture, 'hilos-logs-node-due-window-node').trim()).toBe('3')
    expect(textOf(fixture, 'hilos-logs-takeout')).toContain('full-node')
    expect(
      root.querySelector('table [data-id="hilos-logs-node-due-zero-node"]'),
    ).toBeNull()
    expect(
      root.querySelector('table [data-id="hilos-logs-node-due-unknown-node"]'),
    ).toBeNull()
  })

  it('renders only the page window and replaces it from later table windows', () => {
    const { connection, push, pushPageWindow, pushWindow, sendTableViewport } =
      makeConnection()
    const fixture = mountPage(connection)
    const root = fixture.nativeElement as HTMLElement

    push(overview({ nodes: [node(), node({ nodeId: 'node-2' })] }))
    fixture.detectChanges()
    expect(
      root.querySelector('table [data-id="hilos-logs-node-node-1"]'),
    ).toBeNull()
    expect(
      root.querySelector('table [data-id="hilos-logs-node-node-2"]'),
    ).toBeNull()

    pushPageWindow([tableNode({ nodeId: 'window-node', liveBytes: 2048 })], 2)
    fixture.detectChanges()
    const table = root.querySelector('table')!
    expect(
      table
        .querySelector('[data-id="hilos-logs-node-window-node"]')
        ?.textContent?.trim(),
    ).toBe('window-node')
    expect(textOf(fixture, 'hilos-logs-node-live-window-node').trim()).toBe(
      '2.0 KB',
    )
    expect(table.querySelector('[data-id="hilos-logs-node-node-1"]')).toBeNull()
    expect(table.querySelector('[data-id="hilos-logs-node-node-2"]')).toBeNull()
    const titleId = table.getAttribute('aria-labelledby')!
    expect(root.querySelector(`[id="${titleId}"]`)?.textContent?.trim()).toBe(
      'By node',
    )
    expect(sendTableViewport).not.toHaveBeenCalled()

    pushWindow([tableNode({ nodeId: 'updated-node' })])
    fixture.detectChanges()
    expect(
      table.querySelector('[data-id="hilos-logs-node-window-node"]'),
    ).toBeNull()
    expect(
      table
        .querySelector('[data-id="hilos-logs-node-updated-node"]')
        ?.textContent?.trim(),
    ).toBe('updated-node')
  })

  it('explains an unreadable store once through the table row message', () => {
    const { connection, push, pushPageWindow } = makeConnection()
    const fixture = mountPage(connection)
    push(overview({ nodes: [node()] }))
    pushPageWindow([
      tableNode(),
      tableNode({
        nodeId: 'unreadable-node',
        available: false,
        lastRotationAt: null,
        liveBytes: null,
        archiveBytes: null,
        growthBytesPerDay: null,
        batchesDueForTakeout: null,
      }),
    ])
    fixture.detectChanges()

    const root = fixture.nativeElement as HTMLElement
    const row = root.querySelector(
      'table [data-id="hilos-table-row-unreadable-node"]',
    )!
    expect(row.textContent).toContain('unreadable-node')
    expect(
      row
        .querySelector('[data-id="hilos-table-row-message"]')
        ?.textContent?.trim(),
    ).toBe('No data — this node could not read its log store')
    expect(row.textContent?.match(/No data/g)).toHaveLength(1)
    expect(
      row.querySelector('[data-id="hilos-logs-node-rotation-unreadable-node"]'),
    ).toBeNull()
    expect(
      row.querySelector('[data-id="hilos-logs-node-due-unreadable-node"]'),
    ).toBeNull()
    expect(
      root.querySelector(
        'table [data-id="hilos-table-row-node-1"] [data-id="hilos-table-row-message"]',
      ),
    ).toBeNull()
  })

  it('keeps unknown measurements distinct from measured zeros', () => {
    const { connection, push, pushPageWindow } = makeConnection()
    const fixture = mountPage(connection)
    push(overview({ nodes: [node()] }))
    pushPageWindow([
      tableNode({
        nodeId: 'unknown-node',
        lastRotationAt: null,
        liveBytes: null,
        archiveBytes: null,
        growthBytesPerDay: null,
        batchesDueForTakeout: null,
      }),
      tableNode({
        nodeId: 'zero-node',
        lastRotationAt: null,
        liveBytes: 0,
        archiveBytes: 0,
        growthBytesPerDay: 0,
      }),
    ])
    fixture.detectChanges()

    const root = fixture.nativeElement as HTMLElement
    const values = (nodeId: string) =>
      Array.from(
        root.querySelectorAll(`table [data-id="hilos-table-row-${nodeId}"] td`),
      )
        .slice(0, 6)
        .map((cell) => cell.textContent?.trim())
    expect(values('unknown-node')).toEqual([
      'unknown-node',
      '—',
      '—',
      '—',
      '—',
      '—',
    ])
    expect(values('zero-node')).toEqual([
      'zero-node',
      '—',
      '0 B',
      '0 B',
      '0 B',
      '—',
    ])
    expect(
      root.querySelector('table [data-id="hilos-table-row-message"]'),
    ).toBeNull()
  })

  it('reformats an epoch moment on a zone change without requesting another window', () => {
    const zone = bindTestTimeZone('UTC')
    const {
      connection,
      push,
      pushPageWindow,
      sendTableViewport,
      registerTableWindow,
    } = makeConnection()
    const fixture = mountPage(connection)
    try {
      push(overview({ nodes: [node()] }))
      pushPageWindow([tableNode()])
      fixture.detectChanges()
      const root = fixture.nativeElement as HTMLElement
      const label = (timeZone: string) =>
        new Intl.DateTimeFormat(undefined, {
          dateStyle: 'medium',
          timeStyle: 'medium',
          timeZone,
        }).format(new Date(ROTATION_MOMENT))
      expect(textOf(fixture, 'hilos-logs-node-rotation-node-1').trim()).toBe(
        label('UTC'),
      )
      const cell = root.querySelector(
        'table [data-id="hilos-logs-node-rotation-node-1"]',
      )

      zone.receive('America/Los_Angeles')
      fixture.detectChanges()
      expect(textOf(fixture, 'hilos-logs-node-rotation-node-1').trim()).toBe(
        label('America/Los_Angeles'),
      )
      expect(
        root.querySelector('table [data-id="hilos-logs-node-rotation-node-1"]'),
      ).toBe(cell)
      expect(sendTableViewport).not.toHaveBeenCalled()
      expect(registerTableWindow).toHaveBeenCalledTimes(1)
    } finally {
      fixture.destroy()
      zone.release()
      vi.restoreAllMocks()
    }
  })

  it('names reached and full-disk thresholds and hides unknown forecasts', () => {
    const { connection, push, pushPageWindow } = makeConnection()
    const fixture = mountPage(connection)
    const root = fixture.nativeElement as HTMLElement
    push(
      overview({
        nodes: [
          node({
            nodeId: 'full-node',
            filesystemFreeBytes: 20,
            filesystemTotalBytes: 100,
            freeSpaceThresholdPercent: 20,
            growthBytesPerDay: null,
          }),
        ],
      }),
    )
    pushPageWindow([tableNode({ nodeId: 'window-node' })])
    fixture.detectChanges()
    expect(textOf(fixture, 'hilos-logs-growth-forecast').trim()).toBe(
      'The 20% threshold has been reached on full-node — 20% free',
    )
    const icon = root.querySelector<HTMLElement>(
      '[data-id="hilos-logs-growth-forecast"] > i',
    )!
    expect(icon.getAttribute('aria-hidden')).toBe('true')
    expect(icon.tabIndex).toBe(-1)

    push(
      overview({
        nodes: [
          node({
            nodeId: 'full-node',
            filesystemFreeBytes: 14,
            filesystemTotalBytes: 100,
            freeSpaceThresholdPercent: 20,
            growthBytesPerDay: 0,
          }),
        ],
      }),
    )
    fixture.detectChanges()
    expect(textOf(fixture, 'hilos-logs-growth-forecast').trim()).toBe(
      'Free space is already below the 20% threshold on full-node — 14% free',
    )

    push(
      overview({
        filesystemFreeBytes: 0,
        filesystemTotalBytes: 100,
        freeSpaceThresholdPercent: 0,
        growthBytesPerDay: null,
      }),
    )
    fixture.detectChanges()
    expect(textOf(fixture, 'hilos-logs-growth-forecast').trim()).toBe(
      'The log disk is full — 0% free',
    )

    push(
      overview({
        filesystemFreeBytes: 80,
        filesystemTotalBytes: 100,
        freeSpaceThresholdPercent: 20,
        growthBytesPerDay: 10,
      }),
    )
    fixture.detectChanges()
    expect(textOf(fixture, 'hilos-logs-growth-forecast').trim()).toBe(
      'At this rate the 20% threshold is 6 days away',
    )
    expect(
      root.querySelector('[data-id="hilos-logs-growth-forecast"] > i'),
    ).toBeNull()

    push(overview({ freeSpaceThresholdPercent: HIDDEN_VALUE }))
    fixture.detectChanges()
    expect(
      root.querySelector(
        '[data-id="hilos-logs-growth-forecast"] [data-id="hilos-hidden"]',
      ),
    ).not.toBeNull()
    expect(
      root.querySelector('[data-id="hilos-logs-growth-forecast"] > i'),
    ).toBeNull()

    push(overview())
    fixture.detectChanges()
    expect(
      root.querySelector('[data-id="hilos-logs-growth-forecast"]'),
    ).toBeNull()
  })

  it('shows no node rows for a standalone overview even if a window arrives', () => {
    const { connection, push, pushPageWindow } = makeConnection()
    const fixture = mountPage(connection)
    push(overview({ nodes: [] }))
    pushPageWindow([tableNode({ nodeId: 'window-node' })])
    fixture.detectChanges()

    expect(
      (fixture.nativeElement as HTMLElement).querySelector('table'),
    ).toBeNull()
    expect(textOf(fixture, 'hilos-logs-node-window-node')).toBe('')
  })

  it('releases both bindings on a context swap and on destroy', () => {
    const first = makeConnection()
    const fixture = mountPage(first.connection)
    expect(first.registerTableWindow).toHaveBeenCalledTimes(1)
    expect(first.listenerCount()).toBeGreaterThan(0)

    const second = makeConnection()
    const scopes = new ScopeManager()
    scopes.openPage(HilosPages.LOGS)
    fixture.componentRef.setInput('context', {
      connection: second.connection,
      scopes,
    })
    fixture.detectChanges()
    expect(first.listenerCount()).toBe(0)
    expect(first.unregisterTableWindow).toHaveBeenCalledWith(
      ...first.registerTableWindow.mock.calls[0],
    )
    expect(second.registerTableWindow).toHaveBeenCalledTimes(1)

    first.push(overview({ logKeysPerDaemon: 99 }))
    second.push(overview({ nodes: [node()], logKeysPerDaemon: 7 }))
    second.pushPageWindow([tableNode({ nodeId: 'second-node' })])
    fixture.detectChanges()
    expect(textOf(fixture, 'hilos-logs-tile-daemon')).toContain('7')
    expect(textOf(fixture, 'hilos-logs-tile-daemon')).not.toContain('99')
    expect(textOf(fixture, 'hilos-logs-node-second-node').trim()).toBe(
      'second-node',
    )

    fixture.destroy()
    expect(second.listenerCount()).toBe(0)
    expect(second.unregisterTableWindow).toHaveBeenCalledWith(
      ...second.registerTableWindow.mock.calls[0],
    )
  })
})

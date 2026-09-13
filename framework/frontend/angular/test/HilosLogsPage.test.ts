// The Angular peer of vue/src/admin/logs/HilosLogsPage.test.ts and
// react/test/HilosLogsPage.test.tsx, for the one piece of this screen's markup that
// is new in all three at once: the row of stream classes and its daemon tile
// (HIL-936).
//
// Only the tile and the order of the row are here. The empty states, the takeout
// banner and the per-node table are the core headless's discrimination and are
// proved once, in the peers; a third copy of them would test @hilos/core through
// three view layers rather than test this view.
//
// What is Angular's own is the mount (TestBed) and the fact that a frame is read by
// running change detection rather than by awaiting a tick.
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  createSignal,
  HilosPages,
  OVERVIEW_SIGNAL,
  type HilosConnection,
  type HilosLogsOverview,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core'
import { describe, expect, it } from 'vitest'

import { HilosLogsPage } from '../src/admin/logs/HilosLogsPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

/** The overview as the page answers a subscription with it. */
function overview(
  overrides: Partial<HilosLogsOverview> = {},
): HilosLogsOverview {
  return {
    available: true,
    totalRotationsAllTime: 384,
    lastRotationAt: '2026-09-02T03:00:00+00:00',
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
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  } as unknown as HilosRouter
}

/**
 * A connection stub handing back the one frame this screen lives on.
 *
 * @returns The connection plus the push a case drives it with.
 */
function makeConnection(): {
  connection: HilosConnection
  push: (frame: HilosLogsOverview) => void
} {
  const listeners: ((signal: { type: string; data: unknown }) => void)[] = []
  const connection = {
    on(event: string, listener: (signal: never) => void): () => void {
      if (event === 'projectSignal') {
        listeners.push(
          listener as unknown as (signal: {
            type: string
            data: unknown
          }) => void,
        )
      }

      return () => {}
    },
  } as unknown as HilosConnection

  return {
    connection,
    push(frame: HilosLogsOverview): void {
      for (const listener of listeners) {
        listener({ type: OVERVIEW_SIGNAL, data: frame })
      }
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
  fixture.componentRef.setInput('context', { connection })
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

  /**
   * The row is read as a set, in the order the filters of the streams screen keep:
   * a tile out of its place would read as a different class under the same figure.
   */
  it('lays the stream classes out as daemon, agents, workers', () => {
    const { connection } = makeConnection()
    const fixture = mountPage(connection)

    const row = (fixture.nativeElement as HTMLElement).querySelector(
      '[data-id="hilos-logs-class-tiles"]',
    )
    const tiles = Array.from(
      row?.querySelectorAll('[data-id^="hilos-logs-tile-"]') ?? [],
    ).map((tile) => tile.getAttribute('data-id'))

    expect(tiles).toEqual([
      'hilos-logs-tile-daemon',
      'hilos-logs-tile-agents',
      'hilos-logs-tile-workers',
    ])
  })
})

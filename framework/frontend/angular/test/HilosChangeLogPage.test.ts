// The Angular peer of vue/src/admin/changeLog/HilosChangeLogPage.test.ts and
// react/test/HilosChangeLogPage.test.tsx, under the same case names and over the
// same fixtures: the tiles and the feed filter, the two empty feeds, the feed
// cells and the viewer's hidden names, and the receipt modal driven through the
// real tracked lifecycle — opening, paging, Retry, and the three closes of the
// one shared read. The StrictMode case of React has no Angular counterpart: no
// effect runs twice over the same reader here.
import { Component, signal } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { By } from '@angular/platform-browser'
import {
  ActionLifecycle,
  CHANGE_LOG_OVERVIEW_SECTION,
  HilosPages,
  ScopeManager,
  createSignal,
  type ActionErrorSignal,
  type ActionLifecycleSource,
  type ActionSuccessSignal,
  type ConnectionState,
  type HilosChangeLogOverview,
  type HilosChangeLogReceiptContext,
  type HilosPageIdentity,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosChangeLogPage } from '../src/admin/changeLog/HilosChangeLogPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'
import { bindTestTimeZone } from './timeZoneTestHarness.js'

const TABLE = 'hilosChangeLogFeed'
const OVERVIEW: HilosChangeLogOverview = {
  journalEntries: 12,
  journalBytes: 2048,
  oldestAt: '2026-09-01T10:00:00.000Z',
  journaledTables: ['bot', 'hilos_user'],
  liveTables: 32,
}

function element(id: string, root: ParentNode = document): HTMLElement {
  const found = root.querySelector(`[data-id="${id}"]`)
  expect(found).not.toBeNull()
  return found as HTMLElement
}

/**
 * What a node says, with the layout whitespace of the template folded to the one
 * space a browser draws.
 *
 * @param node The node to read.
 */
function said(node: Element): string {
  return (node.textContent ?? '').replace(/\s+/g, ' ')
}

/**
 * The page's parent, which drops it the way the page outlet does: the page is
 * destroyed and its DOM goes with it, which a destroyed root fixture keeps.
 */
@Component({
  selector: 'test-change-log-host',
  imports: [HilosChangeLogPage],
  template: `
    @if (shown()) {
      <hilos-change-log-page [context]="context" />
    }
  `,
})
class PageHost {
  readonly shown = signal(true)
  context!: HilosChangeLogReceiptContext
}

// The host of the current case; one page is mounted per case.
let fixture: ComponentFixture<PageHost>
const releases: (() => void)[] = []
afterEach(() => {
  for (const release of releases.splice(0)) release()
  vi.restoreAllMocks()
})

/** Let a settled action and the page's own re-render reach the document. */
async function settle(): Promise<void> {
  // The tracked lifecycle settles through promises outside Angular's pending
  // tasks: let them run before waiting on the render they schedule.
  await new Promise((resolve) => setTimeout(resolve))
  await fixture.whenStable()
  fixture.detectChanges()
}

function router(tablesBuilt = false) {
  const identity = createSignal<HilosPageIdentity | undefined>(undefined)
  const leaves = new Set<() => void>()
  const value: HilosRouter = {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.CHANGE_LOG,
      params: {},
      admin: true,
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: identity,
    dashboardSections: createSignal(undefined),
    resolvePath: (page) =>
      tablesBuilt && page === HilosPages.CHANGE_LOG_TABLES
        ? '/hilos/change-log/tables'
        : undefined,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: (listener) => {
      leaves.add(listener)
      return () => leaves.delete(listener)
    },
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
  return {
    value,
    identity,
    leaves,
    leave: () => leaves.forEach((listener) => listener()),
  }
}

interface Events {
  actionSuccess: ActionSuccessSignal
  actionError: ActionErrorSignal
  state: ConnectionState
}

/** Exercise the real tracked lifecycle, including its parser, correlation and failures. */
class Source implements ActionLifecycleSource {
  connected = true
  readonly sent: { action: string; data: unknown; requestId?: string }[] = []
  private readonly listeners: {
    [E in keyof Events]: ((payload: Events[E]) => void)[]
  } = { actionSuccess: [], actionError: [], state: [] }

  sendAction(action: string, data: unknown, requestId?: string): boolean {
    this.sent.push({ action, data, requestId })
    return this.connected
  }

  on<E extends keyof Events>(
    event: E,
    listener: (payload: Events[E]) => void,
  ): () => void {
    this.listeners[event].push(listener)
    return () => {
      const index = this.listeners[event].indexOf(listener)
      if (index !== -1) this.listeners[event].splice(index, 1)
    }
  }

  success(index: number, reply?: unknown): void {
    const request = this.sent[index]!
    for (const listener of this.listeners.actionSuccess) {
      listener({
        kind: 'actionSuccess',
        action: request.action,
        requestId: request.requestId,
        reply,
        message: undefined,
        envelope: { type: 'action_success', data: {} },
      })
    }
  }

  fail(index: number, reason = 'Could not read the journal'): void {
    const request = this.sent[index]!
    for (const listener of this.listeners.actionError) {
      listener({
        kind: 'actionError',
        action: request.action,
        requestId: request.requestId,
        reason,
        errorCode: undefined,
        errorType: undefined,
        errorDetail: undefined,
        envelope: { type: 'action_error', data: {} },
      })
    }
  }

  state(state: ConnectionState): void {
    this.connected = state === 'connected'
    for (const listener of this.listeners.state) listener(state)
  }
}

type WindowListener = (signal: { data: Record<string, unknown> }) => void

function harness(
  overview: HilosChangeLogOverview = OVERVIEW,
  rows: Array<{ rowKey: string; slots: Record<string, unknown> }> = [],
) {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(HilosPages.CHANGE_LOG)
  scope.data.set(CHANGE_LOG_OVERVIEW_SECTION, overview)
  const listeners = new Set<WindowListener>()
  const registered = new Set<string>()
  const connection = {
    registerTableWindow: vi.fn((key: string): void => {
      registered.add(key)
    }),
    unregisterTableWindow: vi.fn((key: string): void => {
      registered.delete(key)
    }),
    tableWindowDescriptors: () => ({}),
    sendTableViewport: vi.fn(() => true),
    sendTableRendered(): boolean {
      return true
    },
    on(event: string, listener: WindowListener): () => void {
      if (event === 'tableWindow') {
        listeners.add(listener)
        return () => listeners.delete(listener)
      }
      return () => {}
    },
  }
  const source = new Source()
  const actions = new ActionLifecycle(source)
  const context: HilosChangeLogReceiptContext = {
    connection:
      connection as unknown as HilosChangeLogReceiptContext['connection'],
    scopes,
    actions,
  }
  function pushWindow(nextRows = rows) {
    const data = {
      page: HilosPages.CHANGE_LOG,
      tableKey: TABLE,
      rows: nextRows,
      totalCount: nextRows.length,
      totalExact: true,
      firstAnchor: null,
      lastAnchor: null,
      offset: 0,
      limit: 25,
    }
    for (const listener of listeners) listener({ data })
  }
  return {
    context,
    scope,
    connection,
    pushWindow,
    source,
    listeners,
    registered,
  }
}

/**
 * Mount the page inside a navigator and answer its feed window.
 *
 * @param h The harness whose context the page is mounted with.
 * @param tablesBuilt Whether the Tracked tables page has an address.
 * @param r The navigator the page reads.
 */
async function mountPage(
  h: ReturnType<typeof harness>,
  tablesBuilt = false,
  r = router(tablesBuilt),
): Promise<void> {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: r.value }],
  })
  fixture = TestBed.createComponent(PageHost)
  fixture.componentInstance.context = h.context
  fixture.detectChanges()
  h.pushWindow()
  await settle()
}

/** Drop the page from its parent and let the removal render. */
async function unmount(): Promise<void> {
  fixture.componentInstance.shown.set(false)
  await settle()
}

describe('HilosChangeLogPage', () => {
  it('draws the page snapshot and offers its table names in the feed filter', async () => {
    const h = harness()
    await mountPage(h)
    expect(
      document
        .querySelector('[data-id="hilos-change-log-tile-journal"]')
        ?.textContent?.trim(),
    ).toBe('12')
    expect(
      document
        .querySelector('[data-id="hilos-change-log-tile-tracked"]')
        ?.textContent?.trim(),
    ).toBe('2 of 32')
    expect(document.body.textContent).toContain('since September 2026')

    expect(
      document.querySelector('[data-id="hilos-change-log-feed-empty"]'),
    ).toBeNull()
    expect(h.connection.registerTableWindow).not.toHaveBeenCalled()
  })
  it('reformats the journal note from the live reader zone with an empty feed', async () => {
    const timeZone = bindTestTimeZone('UTC')
    releases.push(timeZone.release)
    timeZone.receive('UTC')
    const overview = { ...OVERVIEW, oldestAt: '2026-01-31T22:30:00Z' }
    const h = harness(overview)
    const r = router()
    const pushWindow = vi.spyOn(h, 'pushWindow')
    await mountPage(h, false, r)

    const page = fixture.debugElement.query(By.directive(HilosChangeLogPage))
      .componentInstance as HilosChangeLogPage
    const tile = element('hilos-change-log-tile-journal')
    const note = tile.nextElementSibling!
    const section = h.context.scopes.pageDataSignal(CHANGE_LOG_OVERVIEW_SECTION)
    const snapshot = section.get()
    const identity = r.identity.get()
    const viewportRequests = h.connection.sendTableViewport.mock.calls.length
    expect(said(note).trim()).toBe('2.0 KB · since January 2026')

    for (const [zone, expected] of [
      ['Europe/Kyiv', '2.0 KB · since February 2026'],
      [null, '2.0 KB · since January 2026'],
    ] as const) {
      timeZone.receive(zone)
      await fixture.whenStable()
      // Check the parent only: an unmarked OnPush page must stay skipped.
      fixture.detectChanges()
      expect(said(note).trim()).toBe(expected)
      expect(element('hilos-change-log-tile-journal')).toBe(tile)
      expect(tile.nextElementSibling).toBe(note)
      expect(
        fixture.debugElement.query(By.directive(HilosChangeLogPage))
          .componentInstance,
      ).toBe(page)
      expect(page.context()).toBe(h.context)
      expect(section.get()).toBe(snapshot)
      expect(r.identity.get()).toBe(identity)
      expect(document.querySelector('[data-id="modal"]')).toBeNull()
      expect(h.source.sent).toEqual([])
      expect(pushWindow).toHaveBeenCalledTimes(1)
      expect(h.connection.registerTableWindow).not.toHaveBeenCalled()
      expect(h.connection.unregisterTableWindow).not.toHaveBeenCalled()
      expect(h.connection.sendTableViewport).toHaveBeenCalledTimes(
        viewportRequests,
      )
    }

    await unmount()
    expect(h.registered.size).toBe(0)
    expect(h.listeners.size).toBe(0)
    expect(r.leaves.size).toBe(0)
    expect(timeZone.listenerCount()).toBe(2)
    timeZone.release()
    expect(timeZone.listenerCount()).toBe(0)
  })

  it('offers the table names of a later page answer in the feed filter', async () => {
    const h = harness()
    await mountPage(h)
    h.scope.data.set(CHANGE_LOG_OVERVIEW_SECTION, {
      ...OVERVIEW,
      journaledTables: ['bot', 'hilos_user', 'poll'],
      liveTables: 33,
    })
    await settle()
    expect(
      document
        .querySelector('[data-id="hilos-change-log-tile-tracked"]')
        ?.textContent?.trim(),
    ).toBe('3 of 33')
  })
})

describe('overview isolation', () => {
  it('keeps schema figures without a reader or feed', async () => {
    await mountPage(harness())
    expect(
      document.querySelector('[data-id="hilos-change-log-tile-tracked"]')
        ?.textContent,
    ).toContain('2 of 32')
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
  })
})

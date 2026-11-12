import { StrictMode } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, render } from '@testing-library/react'
import {
  ActionLifecycle,
  HilosPages,
  ScopeManager,
  createSignal,
} from '@hilos/core/base'
import { CHANGE_LOG_OVERVIEW_SECTION } from '@hilos/core/admin/change-log'
import type {
  ActionErrorSignal,
  ActionLifecycleSource,
  ActionSuccessSignal,
  ConnectionState,
  HilosPageIdentity,
  HilosRouter,
  PageRouteMatch,
} from '@hilos/core/base'
import type {
  HilosChangeLogOverview,
  HilosChangeLogReceiptContext,
} from '@hilos/core/admin/change-log'

import { HilosChangeLogPage } from '../src/admin/changeLog/HilosChangeLogPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

const TABLE = 'hilosChangeLogFeed'
const OVERVIEW: HilosChangeLogOverview = {
  journalEntries: 12,
  journalBytes: 2048,
  oldestAt: '2026-09-01T10:00:00.000Z',
  journaledTables: ['bot', 'hilos_user'],
  liveTables: 32,
}

const SECTION_IDENTITY: HilosPageIdentity = {
  label: 'Overview',
  lead: 'Who changed what in the tracked tables, and through which action.',
  breadcrumb: [
    { page: HilosPages.DASHBOARD, label: 'Hilos' },
    { page: HilosPages.CHANGE_LOG, label: 'Change Log' },
  ],
  children: [],
  pageHeadOwner: {
    page: HilosPages.CHANGE_LOG,
    label: 'Change Log',
    lead: 'Who changed what in the tracked tables, and through which action.',
    icon: 'bi-clock-history',
  },
  pageTabs: [
    { page: HilosPages.CHANGE_LOG, label: 'Overview' },
    { page: HilosPages.CHANGE_LOG_FEED, label: 'Feed' },
    { page: HilosPages.CHANGE_LOG_TABLES, label: 'Tracked tables' },
    { page: HilosPages.CHANGE_LOG_NOISY, label: 'Noisy columns' },
  ],
}

function router(feedBuilt = true, tablesBuilt = true) {
  const identity = createSignal<HilosPageIdentity | undefined>(SECTION_IDENTITY)
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
    resolvePath: (page) => {
      if (page === HilosPages.DASHBOARD) return '/hilos'
      if (page === HilosPages.CHANGE_LOG) return '/hilos/change-log'
      if (page === HilosPages.CHANGE_LOG_FEED) {
        return feedBuilt ? '/hilos/change-log/feed' : undefined
      }
      if (page === HilosPages.CHANGE_LOG_TABLES) {
        return tablesBuilt ? '/hilos/change-log/tables' : undefined
      }
      if (page === HilosPages.CHANGE_LOG_NOISY) {
        return '/hilos/change-log/noisy'
      }
      return undefined
    },
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
  const connection = {
    registerTableWindow: vi.fn(),
    unregisterTableWindow: vi.fn(),
    tableWindowDescriptors: () => ({}),
    sendTableViewport(): boolean {
      return true
    },
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
  return { context, scope, pushWindow, source, connection }
}

/**
 * Mount the page inside a navigator and answer its feed window.
 *
 * @param h The harness whose context the page is mounted with.
 * @param tablesBuilt Whether the Tracked tables page has an address.
 * @param r The navigator the page reads.
 * @param strict Whether the tree is rooted in StrictMode, as the React demos
 *   mount theirs.
 */
function mountPage(
  h: ReturnType<typeof harness>,
  r = router(),
  strict = false,
) {
  const tree = (
    <HilosRouterContext.Provider value={r.value}>
      <HilosChangeLogPage context={h.context} />
    </HilosRouterContext.Provider>
  )
  const result = render(strict ? <StrictMode>{tree}</StrictMode> : tree)
  act(() => h.pushWindow())
  return result
}

describe('HilosChangeLogPage', () => {
  // The receipt modal is portalled to the document body, so a page left mounted
  // would leave it there for the next case to find.
  afterEach(() => {
    cleanup()
    vi.restoreAllMocks()
  })

  it('draws the page snapshot and offers its table names in the feed filter', () => {
    const h = harness()
    const { container } = mountPage(h)
    const headings = container.querySelectorAll('h1')
    expect(headings).toHaveLength(1)
    expect(headings[0]?.textContent).toBe('Change Log')
    const head = container.querySelector('[data-id="hilos-page-head"]')
    expect(head?.textContent).toContain(
      'Who changed what in the tracked tables, and through which action.',
    )
    expect(
      head?.querySelector('i')?.parentElement?.getAttribute('aria-hidden'),
    ).toBe('true')
    const breadcrumb = container.querySelector('[data-id="hilos-breadcrumb"]')
    expect(
      Array.from(breadcrumb?.querySelectorAll('a') ?? [], (link) =>
        link.textContent?.trim(),
      ),
    ).toEqual(['Hilos'])
    expect(breadcrumb?.querySelector('a')?.getAttribute('href')).toBe('/hilos')
    const currentCrumb = breadcrumb?.querySelector('[aria-current="page"]')
    expect(
      Array.from(currentCrumb?.childNodes ?? [])
        .filter((node) => node.nodeType === Node.TEXT_NODE)
        .map((node) => node.textContent?.trim())
        .join(''),
    ).toBe('Change Log')
    const tabs = container.querySelector('[data-id="hilos-page-tabs"]')
    expect(tabs?.getAttribute('aria-label')).toBe('Change Log pages')
    expect(tabs?.querySelectorAll('[aria-current="page"]')).toHaveLength(1)
    expect(
      tabs?.querySelector(
        `[data-id="hilos-page-tab-${HilosPages.CHANGE_LOG}"]`,
      ),
    ).not.toBeNull()
    expect(
      Array.from(tabs?.querySelectorAll('a') ?? [], (link) =>
        link.textContent?.trim(),
      ),
    ).toEqual(['Overview', 'Feed', 'Tracked tables', 'Noisy columns'])
    expect(
      Array.from(tabs?.querySelectorAll('a') ?? [], (link) =>
        link.getAttribute('href'),
      ),
    ).toEqual([
      '/hilos/change-log',
      '/hilos/change-log/feed',
      '/hilos/change-log/tables',
      '/hilos/change-log/noisy',
    ])
    const feedLink = container.querySelector(
      '[data-id="hilos-change-log-link-feed"]',
    )
    const tablesLink = container.querySelector(
      '[data-id="hilos-change-log-link-tables"]',
    )
    expect(feedLink?.tagName).toBe('A')
    expect(feedLink?.getAttribute('href')).toBe('/hilos/change-log/feed')
    expect(tablesLink?.tagName).toBe('A')
    expect(tablesLink?.getAttribute('href')).toBe('/hilos/change-log/tables')
    expect(
      Array.from(
        container.querySelectorAll('[data-id^="hilos-change-log-link-"] i'),
        (icon) => icon.getAttribute('aria-hidden'),
      ),
    ).toEqual(['true', 'true'])
    expect(
      container
        .querySelector('[data-id="hilos-change-log-tile-journal"]')
        ?.textContent?.trim(),
    ).toBe('12')
    expect(
      container
        .querySelector('[data-id="hilos-change-log-tile-tracked"]')
        ?.textContent?.trim(),
    ).toBe('2 of 32')
    expect(container.textContent).toContain('since September 2026')

    expect(
      container.querySelector('[data-id="hilos-change-log-feed-empty"]'),
    ).toBeNull()
    expect(h.connection.registerTableWindow).not.toHaveBeenCalled()
  })

  it('leaves a tile without a link when its page has no address', () => {
    const { container } = mountPage(harness(), router(false, false))

    expect(
      container.querySelector('[data-id="hilos-change-log-link-feed"]'),
    ).toBeNull()
    expect(
      container.querySelector('[data-id="hilos-change-log-link-tables"]'),
    ).toBeNull()
    expect(
      container
        .querySelector('[data-id="hilos-change-log-tile-journal"]')
        ?.closest('a'),
    ).toBeNull()
    expect(
      container
        .querySelector('[data-id="hilos-change-log-tile-tracked"]')
        ?.closest('a'),
    ).toBeNull()
    expect(
      container.querySelector(
        `[data-id="hilos-page-tab-${HilosPages.CHANGE_LOG_FEED}"]`,
      ),
    ).toBeNull()
  })
})

describe('overview isolation', () => {
  it('keeps schema figures without a reader or feed', () => {
    const { container } = mountPage(harness())
    expect(
      container.querySelector('[data-id="hilos-change-log-tile-tracked"]')
        ?.textContent,
    ).toContain('2 of 32')
    expect(container.querySelector('[data-id="modal"]')).toBeNull()
  })
})

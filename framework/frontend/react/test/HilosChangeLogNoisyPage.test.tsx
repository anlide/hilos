import { afterEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, render } from '@testing-library/react'
import {
  CHANGE_LOG_NOISY_COLUMNS_EMPTY,
  CHANGE_LOG_NOISY_COLUMNS_SECTION,
  HilosPages,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
  hilosAdminAccess,
} from '@hilos/core'
import type {
  HilosChangeLogContext,
  HilosPageIdentity,
  HilosRouter,
  PageRouteMatch,
} from '@hilos/core'

import { HilosChangeLogNoisyPage } from '../src/admin/changeLog/HilosChangeLogNoisyPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

const TABLE = 'hilosChangeLogNoisyColumns'
const SECTION_IDENTITY: HilosPageIdentity = {
  label: 'Noisy columns',
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
const NOISY = [
  {
    table: 'project_record',
    column: 'heartbeat',
    reason: 'Updated automatically <em>without a decision</em>',
  },
  {
    table: 'project_record',
    column: 'seen_at',
    reason: 'Moves with every read',
  },
]
const ROWS = NOISY.map((column) => ({
  rowKey: `${column.table}.${column.column}`,
  slots: {
    noisyColumn: {
      ...column,
      columnKey: `${column.table}.${column.column}`,
    },
  },
}))

type Listener = (signal: {
  type?: string
  data: Record<string, unknown>
}) => void

function harness(legacySnapshot: unknown = NOISY) {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(HilosPages.CHANGE_LOG_NOISY)
  scope.data.set(CHANGE_LOG_NOISY_COLUMNS_SECTION, legacySnapshot)
  const listeners = new Map<string, Set<Listener>>()
  const connection = {
    registerTableWindow: vi.fn(),
    unregisterTableWindow: vi.fn(),
    tableWindowDescriptors: () => ({}),
    sendTableViewport: vi.fn(() => true),
    sendTableRendered: vi.fn(() => true),
    on(event: string, listener: Listener): () => void {
      const set = listeners.get(event) ?? new Set<Listener>()
      listeners.set(event, set)
      set.add(listener)
      return () => set.delete(listener)
    },
  }
  const context: HilosChangeLogContext = {
    connection: connection as unknown as HilosChangeLogContext['connection'],
    scopes,
  }

  function pushWindow(rows = ROWS) {
    for (const listener of listeners.get('projectSignal') ?? []) {
      listener({
        type: 'page_response',
        data: {
          page: HilosPages.CHANGE_LOG_NOISY,
          payload: {
            windows: {
              [TABLE]: {
                filter: {},
                rows,
                totalCount: rows.length,
                totalExact: true,
                firstAnchor: null,
                lastAnchor: null,
                offset: 0,
                limit: 25,
                sort: [{ field: 'column', direction: 'asc' }],
              },
            },
          },
        },
      })
    }
  }

  return { context, scope, connection, listeners, pushWindow }
}

function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.CHANGE_LOG_NOISY,
      params: {},
      admin: true,
    }),
    currentPath: createSignal('/hilos/change-log/noisy'),
    currentTitle: createSignal('Noisy columns'),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal<HilosPageIdentity | undefined>(SECTION_IDENTITY),
    dashboardSections: createSignal(undefined),
    resolvePath: (page) => {
      if (page === HilosPages.DASHBOARD) return '/hilos'
      if (page === HilosPages.CHANGE_LOG) return '/hilos/change-log'
      if (page === HilosPages.CHANGE_LOG_FEED) {
        return '/hilos/change-log/feed'
      }
      if (page === HilosPages.CHANGE_LOG_TABLES) {
        return '/hilos/change-log/tables'
      }
      if (page === HilosPages.CHANGE_LOG_NOISY) {
        return '/hilos/change-log/noisy'
      }
      return undefined
    },
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

const releases: (() => void)[] = []

function mountPage(h: ReturnType<typeof harness>) {
  const r = router()
  const result = render(
    <HilosRouterContext.Provider value={r}>
      <HilosChangeLogNoisyPage context={h.context} />
    </HilosRouterContext.Provider>,
  )
  act(() => h.pushWindow())
  return { container: result.container, unmount: result.unmount }
}

function get(root: ParentNode, selector: string): HTMLElement {
  const found = root.querySelector(selector)
  expect(found).not.toBeNull()
  return found as HTMLElement
}

describe('HilosChangeLogNoisyPage', () => {
  afterEach(() => {
    cleanup()
    for (const release of releases.splice(0)) release()
  })

  it.each(['full', 'view'] as const)(
    'draws the server table and shared section head under %s access',
    (access) => {
      const h = harness([
        { table: 'wrong', column: 'snapshot', reason: 'Unused legacy data' },
      ])
      bindSessionScope(h.context.connection, h.context.scopes)
      releases.push(bindAdminAccess(h.context.scopes))
      for (const listener of h.listeners.get('projectSignal') ?? []) {
        listener({
          type: 'handshake_response',
          data: {
            entities: {
              currentUser: { id: 9, name: 'Ada', admin: access === 'full' },
            },
            data: { adminViewMode: true },
          },
        })
      }
      expect(hilosAdminAccess.get()).toBe(access)

      const listenersBeforePage = [...h.listeners.values()].reduce(
        (total, set) => total + set.size,
        0,
      )
      const { container, unmount } = mountPage(h)
      const headings = container.querySelectorAll('h1')
      expect(headings).toHaveLength(1)
      expect(headings[0]?.textContent).toBe('Change Log')
      const tabs = get(container, '[data-id="hilos-page-tabs"]')
      expect(tabs.getAttribute('aria-label')).toBe('Change Log pages')
      expect(tabs.querySelectorAll('[aria-current="page"]')).toHaveLength(1)
      expect(
        tabs
          .querySelector(
            `[data-id="hilos-page-tab-${HilosPages.CHANGE_LOG_NOISY}"]`,
          )
          ?.getAttribute('aria-current'),
      ).toBe('page')
      expect(
        container.querySelector('[data-id="hilos-admin-children"]'),
      ).toBeNull()

      const table = get(container, 'table')
      expect(table.getAttribute('aria-labelledby')).toBe(
        headings[0]?.getAttribute('id'),
      )
      const headers = Array.from(table.querySelectorAll('th'))
      const columnHeader = headers.find(
        (header) => header.textContent?.trim() === 'Column',
      )
      const reasonHeader = headers.find(
        (header) => header.textContent?.trim() === 'Why noisy',
      )
      expect(columnHeader?.getAttribute('scope')).toBe('col')
      expect(reasonHeader?.getAttribute('scope')).toBe('col')
      const row = get(
        container,
        'table [data-id="hilos-table-row-project_record.heartbeat"]',
      )
      expect(row.textContent).toContain('project_record.heartbeat')
      expect(row.textContent).toContain(NOISY[0]!.reason)
      expect(row.querySelector('em')).toBeNull()
      expect(row.textContent).not.toContain('Unused legacy data')
      expect(
        container.querySelector('[data-id="hilos-table-title"]'),
      ).toBeNull()
      expect(h.connection.sendTableViewport).not.toHaveBeenCalled()
      expect(h.connection.sendTableRendered).toHaveBeenCalled()
      expect(h.connection.registerTableWindow).toHaveBeenCalledWith(
        TABLE,
        expect.anything(),
      )
      act(() => h.pushWindow([]))
      expect(
        container.querySelector('[data-id="hilos-table-empty-title"]')
          ?.textContent,
      ).toBe(CHANGE_LOG_NOISY_COLUMNS_EMPTY)

      unmount()
      expect(h.connection.unregisterTableWindow).toHaveBeenCalledWith(
        TABLE,
        h.connection.registerTableWindow.mock.calls[0]?.[1],
      )
      expect(
        [...h.listeners.values()].reduce((total, set) => total + set.size, 0),
      ).toBe(listenersBeforePage)
    },
  )
})

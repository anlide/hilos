// The Angular peer of vue/src/admin/changeLog/HilosChangeLogNoisyPage.test.ts
// and react/test/HilosChangeLogNoisyPage.test.tsx, under the same case names and
// over the same fixtures: the schema metadata under both accesses, Open only for a
// journaled table whose detail page has an address, the noisy-column snapshot and
// its empty and damaged states, and the release of the window and every listener.
import { Component, signal } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  CHANGE_LOG_NOISY_COLUMNS_EMPTY,
  CHANGE_LOG_NOISY_COLUMNS_SECTION,
  type HilosChangeLogContext,
} from '@hilos/core/admin/change-log'
import {
  HilosPages,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
  hilosAdminAccess,
  type HilosPageIdentity,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core/base'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosChangeLogNoisyPage } from '../src/admin/changeLog/HilosChangeLogNoisyPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

const TABLE = 'hilosChangeLogNoisyColumns'
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
const TABS = [
  { page: HilosPages.CHANGE_LOG, label: 'Overview', path: '/hilos/change-log' },
  {
    page: HilosPages.CHANGE_LOG_FEED,
    label: 'Feed',
    path: '/hilos/change-log/feed',
  },
  {
    page: HilosPages.CHANGE_LOG_TABLES,
    label: 'Tracked tables',
    path: '/hilos/change-log/tables',
  },
  {
    page: HilosPages.CHANGE_LOG_NOISY,
    label: 'Noisy columns',
    path: '/hilos/change-log/noisy',
  },
]
const OWNER = {
  page: HilosPages.CHANGE_LOG,
  label: 'Change Log',
  lead: 'Who changed what in the tracked tables, and through which action.',
  icon: 'bi-clock-history',
}

type Listener = (signal: {
  type?: string
  data: Record<string, unknown>
}) => void

function harness(noisy: unknown = NOISY) {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(HilosPages.CHANGE_LOG_NOISY)
  scope.data.set(CHANGE_LOG_NOISY_COLUMNS_SECTION, noisy)
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
      return () => {
        set.delete(listener)
      }
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
                sort: [{ field: 'name', direction: 'asc' }],
              },
            },
          },
        },
      })
    }
  }
  function pushRefusal(): void {
    for (const listener of listeners.get('tableWindowRefused') ?? []) {
      listener({
        data: {
          page: HilosPages.CHANGE_LOG_NOISY,
          tableKey: TABLE,
          errorCode: 'unavailable',
        },
      })
    }
  }
  return { context, scope, connection, listeners, pushWindow, pushRefusal }
}

function router(detailBuilt: boolean): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.CHANGE_LOG_NOISY,
      params: {},
      admin: true,
    }),
    currentPath: createSignal('/hilos/change-log/tables'),
    currentTitle: createSignal('Noisy columns'),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal<HilosPageIdentity | undefined>({
      label: 'Noisy columns',
      lead: 'Database tables, their journal label and column rules.',
      breadcrumb: [
        { page: HilosPages.DASHBOARD, label: 'Hilos' },
        { page: HilosPages.CHANGE_LOG, label: 'Change Log' },
      ],
      children: [],
      pageHeadOwner: OWNER,
      pageTabs: TABS.map(({ page, label }) => ({ page, label })),
    }),
    dashboardSections: createSignal(undefined),
    resolvePath: vi.fn((page, params) =>
      detailBuilt && page === HilosPages.CHANGE_LOG_TABLE
        ? `/hilos/change-log/table/${params.tableId}`
        : page === HilosPages.DASHBOARD
          ? '/hilos'
          : TABS.find((tab) => tab.page === page)?.path,
    ),
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: vi.fn(),
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

/**
 * The page's parent, which drops it the way the page outlet does: the page is
 * destroyed and its DOM goes with it, which a destroyed root fixture keeps.
 */
@Component({
  selector: 'test-change-log-tables-host',
  imports: [HilosChangeLogNoisyPage],
  template: `
    @if (shown()) {
      <hilos-change-log-noisy-page [context]="context" />
    }
  `,
})
class PageHost {
  readonly shown = signal(true)
  context!: HilosChangeLogContext
}

// The host of the current case; one page is mounted per case.
let fixture: ComponentFixture<PageHost>
const releases: (() => void)[] = []
afterEach(() => {
  for (const release of releases.splice(0)) release()
})

/** Let the page's re-render reach the document. */
async function settle(): Promise<void> {
  await fixture.whenStable()
  fixture.detectChanges()
}

function get(selector: string, root: ParentNode = document): HTMLElement {
  const found = root.querySelector(selector)
  expect(found).not.toBeNull()
  return found as HTMLElement
}

function text(node: Element): string {
  return (node.textContent ?? '').replace(/\s+/g, ' ').trim()
}

/**
 * Mount the page inside a navigator and answer its tables window.
 *
 * @param h The harness whose context the page is mounted with.
 * @param detailBuilt Whether the Tracked table page has an address.
 */
async function mountPage(h = harness(), detailBuilt = false) {
  const r = router(detailBuilt)
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: r }],
  })
  fixture = TestBed.createComponent(PageHost)
  fixture.componentInstance.context = h.context
  fixture.detectChanges()
  h.pushWindow()
  await settle()
  return { r }
}

describe('HilosChangeLogNoisyPage', () => {
  it.each(['full', 'view'] as const)(
    'draws the server window under %s access and ignores the legacy snapshot',
    async (access) => {
      const h = harness([
        { table: 'wrong', column: 'snapshot', reason: 'Unused' },
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
      await mountPage(h)
      expect(document.querySelectorAll('h1')).toHaveLength(1)
      expect(text(get('h1'))).toBe('Change Log')
      const table = get('table')
      expect(table.getAttribute('aria-labelledby')).toBe(
        get('h1').getAttribute('id'),
      )
      const headers = Array.from(table.querySelectorAll('th'), (th) => text(th))
      expect(headers).toContain('Column')
      expect(headers).toContain('Why noisy')
      expect(table.querySelectorAll('tbody tr')).toHaveLength(2)
      expect(get('tbody', table).textContent).toContain(
        'project_record.heartbeat',
      )
      expect(get('tbody', table).textContent).toContain(NOISY[0]!.reason)
      expect(table.querySelector('em')).toBeNull()
      expect(document.body.textContent).not.toContain('wrong.snapshot')
      expect(
        document.querySelector('[data-id="hilos-change-log-noisy-columns"]'),
      ).toBeNull()
      expect(h.connection.sendTableViewport).not.toHaveBeenCalled()
    },
  )

  it('draws the table empty and unavailable states, then releases its window', async () => {
    const h = harness([])
    await mountPage(h)
    h.pushWindow([])
    await settle()
    expect(document.body.textContent).toContain(CHANGE_LOG_NOISY_COLUMNS_EMPTY)
    expect(h.connection.registerTableWindow).toHaveBeenCalledWith(
      TABLE,
      expect.anything(),
    )

    h.pushRefusal()
    await settle()
    expect(text(get('[data-id="hilos-table-unavailable"]'))).toContain(
      'List unavailable',
    )
    expect(document.body.textContent).not.toContain(
      CHANGE_LOG_NOISY_COLUMNS_EMPTY,
    )

    h.pushWindow(ROWS)
    await settle()
    expect(
      document.querySelector('[data-id="hilos-table-unavailable"]'),
    ).toBeNull()
    expect(get('tbody').textContent).toContain('project_record.heartbeat')

    fixture.componentInstance.shown.set(false)
    await settle()
    expect(h.connection.unregisterTableWindow).toHaveBeenCalledWith(
      TABLE,
      h.connection.registerTableWindow.mock.calls[0]?.[1],
    )
    expect(
      [...h.listeners.values()].every((listeners) => listeners.size === 0),
    ).toBe(true)
  })
})

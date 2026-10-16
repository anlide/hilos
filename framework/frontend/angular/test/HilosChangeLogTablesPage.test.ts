// The Angular peer of vue/src/admin/changeLog/HilosChangeLogTablesPage.test.ts
// and react/test/HilosChangeLogTablesPage.test.tsx, under the same case names and
// over the same fixtures: the schema metadata under both accesses, Open only for a
// journaled table whose detail page has an address, the noisy-column snapshot and
// its empty and damaged states, and the release of the window and every listener.
import { Component, signal } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  CHANGE_LOG_COLUMN_MODES,
  CHANGE_LOG_NOISY_COLUMNS_EMPTY,
  CHANGE_LOG_NOISY_COLUMNS_SECTION,
  CHANGE_LOG_TABLES_UNTRACKED_NOTE,
  HilosPages,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
  hilosAdminAccess,
  type HilosChangeLogContext,
  type HilosPageIdentity,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosChangeLogTablesPage } from '../src/admin/changeLog/HilosChangeLogTablesPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

const TABLE = 'hilosChangeLogTables'
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
const ROWS = [
  {
    rowKey: 'project_record',
    slots: {
      table: {
        name: 'project_record',
        owner: 'project',
        journaled: true,
        columns: {
          value: 4,
          personal: 2,
          binary: 3,
          secret: 1,
          noise: 2,
          key: 77,
        },
        changesLast24h: 0,
        lastChangeAt: null,
      },
    },
  },
  {
    rowKey: 'hilos_session',
    slots: {
      table: {
        name: 'hilos_session',
        owner: 'framework',
        journaled: false,
        columns: null,
        changesLast24h: null,
        lastChangeAt: null,
      },
    },
  },
]

type Listener = (signal: {
  type?: string
  data: Record<string, unknown>
}) => void

function harness(noisy: unknown = NOISY) {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(HilosPages.CHANGE_LOG_TABLES)
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
          page: HilosPages.CHANGE_LOG_TABLES,
          payload: {
            windows: {
              [TABLE]: {
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
  return { context, scope, connection, listeners, pushWindow }
}

function router(detailBuilt: boolean): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.CHANGE_LOG_TABLES,
      params: {},
      admin: true,
    }),
    currentPath: createSignal('/hilos/change-log/tables'),
    currentTitle: createSignal('Tracked tables'),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal<HilosPageIdentity | undefined>({
      label: 'Tracked tables',
      lead: 'Database tables, their journal label and column rules.',
      breadcrumb: [],
      children: [],
    }),
    dashboardSections: createSignal(undefined),
    resolvePath: vi.fn((page, params) =>
      detailBuilt && page === HilosPages.CHANGE_LOG_TABLE
        ? `/hilos/change-log/tables/${params.tableId}`
        : undefined,
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
  imports: [HilosChangeLogTablesPage],
  template: `
    @if (shown()) {
      <hilos-change-log-tables-page [context]="context" />
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

describe('HilosChangeLogTablesPage', () => {
  it.each(['full', 'view'] as const)(
    'draws the same schema metadata under %s access',
    async (access) => {
      const h = harness()
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
      expect(text(get('h1'))).toBe('Tracked tables')
      const tracked = get('table [data-id="hilos-table-row-project_record"]')
      expect(text(get('code', tracked))).toBe('project_record')
      expect(tracked.textContent).toContain('Project')
      expect(tracked.textContent).toContain(
        'with value 4 · as fact 5 · noisy 2 · secret 1',
      )
      expect(tracked.textContent).not.toContain('77')
      const trackedCells = tracked.querySelectorAll('td')
      expect(text(trackedCells[3]!)).toBe('0')
      expect(text(trackedCells[4]!)).toBe('—')
      const untracked = get('table [data-id="hilos-table-row-hilos_session"]')
      expect(untracked.textContent).toContain(CHANGE_LOG_TABLES_UNTRACKED_NOTE)
      expect(untracked.textContent).toContain('Framework')
      expect(
        get('code', untracked).classList.contains('text-body-secondary'),
      ).toBe(true)
      expect(
        Array.from(untracked.querySelectorAll('td'))
          .slice(2, 5)
          .map((cell) => text(cell)),
      ).toEqual(['—', '—', '—'])
      expect(
        get('[data-id="hilos-table-card-hilos_session"]').textContent,
      ).toContain(CHANGE_LOG_TABLES_UNTRACKED_NOTE)
      const noise = get('[data-id="hilos-change-log-noisy-columns"]')
      expect(get('table', noise).getAttribute('aria-labelledby')).toBe(
        get('h2', noise).getAttribute('id'),
      )
      expect(
        Array.from(noise.querySelectorAll('th'), (th) =>
          th.getAttribute('scope'),
        ),
      ).toEqual(['col', 'col'])
      expect(noise.querySelectorAll('tbody tr')).toHaveLength(2)
      expect(get('tbody', noise).textContent).toContain(
        'project_record.heartbeat',
      )
      expect(get('tbody', noise).textContent).toContain(NOISY[0]!.reason)
      expect(get('.d-md-none', noise).textContent).toContain(NOISY[0]!.reason)
      expect(noise.querySelector('em')).toBeNull()
      const modes = get('[data-id="hilos-change-log-column-modes"]')
      expect(text(get('h2', modes))).toBe('How a column enters the journal')
      for (const mode of CHANGE_LOG_COLUMN_MODES) {
        expect(modes.textContent).toContain(mode.label)
        expect(modes.textContent).toContain(mode.description)
      }
      expect(
        Array.from(modes.querySelectorAll('i')).every(
          (icon) => icon.getAttribute('aria-hidden') === 'true',
        ),
      ).toBe(true)
      expect(h.connection.sendTableViewport).not.toHaveBeenCalled()
      expect(h.connection.sendTableRendered).toHaveBeenCalled()
      h.pushWindow([])
      await settle()
      expect(noise.querySelectorAll('tbody tr')).toHaveLength(2)
    },
  )

  it.each([false, true])(
    'offers Open only for a tracked table when detail is built: %s',
    async (built) => {
      const { r } = await mountPage(harness(), built)
      const links = document.querySelectorAll<HTMLElement>(
        '[data-id="hilos-change-log-open-table-project_record"]',
      )
      expect(links).toHaveLength(built ? 2 : 0)
      expect(
        document.querySelector(
          '[data-id="hilos-change-log-open-table-hilos_session"]',
        ),
      ).toBeNull()
      if (built) {
        expect(text(links[0]!)).toBe('Open')
        expect(links[0]!.getAttribute('aria-label')).toBe('Open project_record')
        expect(links[0]!.getAttribute('href')).toBe(
          '/hilos/change-log/tables/project_record',
        )
        expect(r.resolvePath).toHaveBeenCalledWith(
          HilosPages.CHANGE_LOG_TABLE,
          { tableId: 'project_record' },
        )
        links[0]!.click()
        expect(r.navigate).toHaveBeenCalledWith(
          '/hilos/change-log/tables/project_record',
        )
      }
    },
  )

  it('uses the core empty copy only for a valid empty snapshot', async () => {
    const h = harness([])
    await mountPage(h)
    const noise = get('[data-id="hilos-change-log-noisy-columns"]')
    expect(noise.textContent).toContain(CHANGE_LOG_NOISY_COLUMNS_EMPTY)
    expect(noise.querySelector('table')).toBeNull()
    for (const invalid of [null, [{ table: 'broken', column: 'heartbeat' }]]) {
      h.scope.data.set(CHANGE_LOG_NOISY_COLUMNS_SECTION, invalid)
      await settle()
      expect(noise.textContent).not.toContain(CHANGE_LOG_NOISY_COLUMNS_EMPTY)
      expect(noise.querySelector('table')).toBeNull()
    }
  })

  it('unregisters the table and releases every connection binding on unmount', async () => {
    const h = harness()
    await mountPage(h)
    expect(h.connection.registerTableWindow).toHaveBeenCalledWith(
      TABLE,
      expect.anything(),
    )
    expect([...h.listeners.values()].some((set) => set.size > 0)).toBe(true)
    fixture.componentInstance.shown.set(false)
    await settle()
    expect(h.connection.unregisterTableWindow).toHaveBeenCalledWith(TABLE)
    expect([...h.listeners.values()].every((set) => set.size === 0)).toBe(true)
    expect(
      document.querySelector('[data-id="hilos-change-log-noisy-columns"]'),
    ).toBeNull()
  })
})

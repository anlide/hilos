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

import { HilosChangeLogNoisyPage } from '../src/admin/changeLog/HilosChangeLogNoisyPage.js'
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
      expect(text(get('h1'))).toBe('Noisy columns')
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
})

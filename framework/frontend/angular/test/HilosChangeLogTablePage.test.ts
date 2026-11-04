// Overview tiles, live time formatting and binding cleanup through the entity shell.
import { Component, signal } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { By } from '@angular/platform-browser'
import {
  ActionLifecycle,
  CHANGE_LOG_TABLE_COPY,
  CHANGE_LOG_TABLE_SECTION,
  HilosPages,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
  pageIdentity,
  hilosAdminAccess,
  type HilosChangeLogReceiptContext,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosChangeLogTablePage } from '../src/admin/changeLog/HilosChangeLogTablePage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'
import { bindTestTimeZone } from './timeZoneTestHarness.js'

const HISTORY = 'hilosChangeLogHistory'
const BOT = {
  name: 'bot',
  owner: 'project',
  journaled: true,
  changesLast24h: 3,
  lastChangeAt: null,
  columns: [
    {
      name: 'id',
      sqlType: 'int',
      mode: 'record_key',
      storage: null,
      noiseReason: null,
    },
    {
      name: 'name',
      sqlType: 'varchar',
      mode: 'value',
      storage: 'inline',
      noiseReason: null,
    },
    {
      name: 'description',
      sqlType: 'text',
      mode: 'value',
      storage: 'long',
      noiseReason: null,
    },
    {
      name: 'email',
      sqlType: 'varchar',
      mode: 'personal',
      storage: 'fact',
      noiseReason: null,
    },
    {
      name: 'seen_at',
      sqlType: 'datetime',
      mode: 'noise',
      storage: 'ignored',
      noiseReason: 'Moves with every read',
    },
  ],
  triggers: [
    {
      name: 'hilos_cl_bot_after_insert',
      event: 'INSERT',
      table: 'bot',
      validFromMigration: 71,
    },
    {
      name: 'hilos_cl_bot_after_update',
      event: 'UPDATE',
      table: 'bot',
      validFromMigration: 71,
    },
    {
      name: 'hilos_cl_bot_after_delete',
      event: 'DELETE',
      table: 'bot',
      validFromMigration: 71,
    },
  ],
}
const SESSION = {
  name: 'hilos_session',
  owner: 'framework',
  journaled: false,
  changesLast24h: null,
  lastChangeAt: null,
  columns: [
    {
      name: 'id',
      sqlType: 'int',
      mode: null,
      storage: null,
      noiseReason: null,
    },
  ],
  triggers: [],
}

type Listener = (signal: {
  type?: string
  data: Record<string, unknown>
}) => void

function harness(section: unknown = BOT) {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(HilosPages.CHANGE_LOG_TABLE)
  scope.data.set(CHANGE_LOG_TABLE_SECTION, section)
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
  const source = {
    connected: true,
    sent: [] as { action: string; data: unknown; requestId?: string }[],
    sendAction(action: string, data: unknown, requestId?: string): boolean {
      this.sent.push({ action, data, requestId })
      return true
    },
    on: vi.fn(() => () => {}),
  }
  const context: HilosChangeLogReceiptContext = {
    connection:
      connection as unknown as HilosChangeLogReceiptContext['connection'],
    scopes,
    actions: new ActionLifecycle(source),
  }
  function emit(event: string, signal: Parameters<Listener>[0]) {
    for (const listener of listeners.get(event) ?? []) listener(signal)
  }
  function pushWindow(rows: unknown[] = []) {
    emit('tableWindow', {
      data: {
        page: HilosPages.CHANGE_LOG_TABLE_HISTORY,
        tableKey: HISTORY,
        rows,
        totalCount: rows.length,
        totalExact: true,
        firstAnchor: null,
        lastAnchor: null,
        offset: 0,
        limit: 25,
      },
    })
  }
  function pushPageWindow() {
    emit('projectSignal', {
      type: 'page_response',
      data: {
        page: HilosPages.CHANGE_LOG_TABLE_HISTORY,
        payload: {
          windows: {
            [HISTORY]: {
              filter: {},
              rows: [],
              totalCount: 0,
              totalExact: true,
              firstAnchor: null,
              lastAnchor: null,
              offset: 0,
              limit: 25,
              sort: [{ field: 'createdAt', direction: 'desc' }],
            },
          },
        },
      },
    })
  }
  return {
    context,
    scope,
    connection,
    listeners,
    source,
    emit,
    pushWindow,
    pushPageWindow,
  }
}

/** Address the page as the application's route map does. */
function tablePath(params: Record<string, string>): string {
  const record = params.record
  return `/hilos/change-log/tables/${encodeURIComponent(params.tableId ?? '')}/history${
    record === undefined ? '' : `/${encodeURIComponent(record)}`
  }`
}

function router(params: Record<string, string>, built = true) {
  const leaves = new Set<() => void>()
  const currentRoute = createSignal<PageRouteMatch>({
    page: HilosPages.CHANGE_LOG_TABLE,
    params,
    admin: true,
  })
  const value: HilosRouter = {
    currentRoute,
    currentPath: createSignal(
      `/hilos/change-log/tables/${encodeURIComponent(params.tableId ?? '')}`,
    ),
    currentTitle: createSignal('Tracked table'),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: vi.fn((page: string, to: Record<string, string> = {}) => {
      if (!built) return undefined
      if (page === HilosPages.CHANGE_LOG_TABLE_HISTORY) return tablePath(to)
      const root = `/hilos/change-log/tables/${encodeURIComponent(to.tableId ?? '')}`
      if (page === HilosPages.CHANGE_LOG_TABLE) return root
      if (page === HilosPages.CHANGE_LOG_TABLE_COLUMNS) return `${root}/columns`
      if (page === HilosPages.CHANGE_LOG_TABLE_TRIGGERS)
        return `${root}/triggers`
      return undefined
    }),
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: (listener) => {
      leaves.add(listener)
      return () => leaves.delete(listener)
    },
    navigate: vi.fn(),
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
  return {
    value,
    route: currentRoute,
    leaves,
  }
}

/**
 * The page's parent, which drops it the way the page outlet does: the page is
 * destroyed and its DOM goes with it, which a destroyed root fixture keeps.
 */
@Component({
  selector: 'test-change-log-table-host',
  imports: [HilosChangeLogTablePage],
  template: `
    @if (shown()) {
      <hilos-change-log-table-page [context]="context" />
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

/** Mount Overview with its current catalog answer and available tabs. */
async function mountOverview(
  h = harness(),
  r = router({ tableId: 'bot' }),
  push = false,
) {
  const section = h.context.scopes
    .pageDataSignal(CHANGE_LOG_TABLE_SECTION)
    .get()
  const scope = h.context.scopes.openPage(HilosPages.CHANGE_LOG_TABLE)
  scope.data.set(CHANGE_LOG_TABLE_SECTION, section)
  scope.data.set('pageLabel', 'Tracked table')
  scope.data.set('pageHeadOwner', {
    page: HilosPages.CHANGE_LOG_TABLE,
    label: 'Tracked table',
    lead: '',
    icon: 'bi-table',
  })
  scope.data.set('pageTabs', [
    { page: HilosPages.CHANGE_LOG_TABLE, label: 'Overview' },
    ...((section as typeof BOT)?.journaled === false
      ? []
      : [{ page: HilosPages.CHANGE_LOG_TABLE_COLUMNS, label: 'Columns' }]),
    { page: HilosPages.CHANGE_LOG_TABLE_TRIGGERS, label: 'Triggers' },
    ...((section as typeof BOT)?.journaled === false
      ? []
      : [{ page: HilosPages.CHANGE_LOG_TABLE_HISTORY, label: 'History' }]),
  ])
  r.route.set({
    ...r.value.currentRoute.get(),
    page: HilosPages.CHANGE_LOG_TABLE,
  })
  const navigator = { ...r.value, pageIdentity: pageIdentity(h.context.scopes) }
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: navigator }],
  })
  fixture = TestBed.createComponent(PageHost)
  fixture.componentInstance.context = h.context
  fixture.detectChanges()
  if (push) h.pushWindow()
  await settle()
  return { r }
}

/** Drop the page from its parent and let the removal render. */
async function unmount(): Promise<void> {
  fixture.componentInstance.shown.set(false)
  await settle()
}

function element(id: string, root: ParentNode = document): HTMLElement {
  const found = root.querySelector(`[data-id="${id}"]`)
  expect(found).not.toBeNull()
  return found as HTMLElement
}

function get(selector: string, root: ParentNode = document): HTMLElement {
  const found = root.querySelector(selector)
  expect(found).not.toBeNull()
  return found as HTMLElement
}

function text(node: Element): string {
  return (node.textContent ?? '').replace(/\s+/g, ' ').trim()
}

describe('HilosChangeLogTablePage', () => {
  it('links the Overview aggregates through the current available tabs', async () => {
    const h = harness()
    const { r } = await mountOverview(h)
    expect(document.querySelectorAll('h1')).toHaveLength(1)
    expect(text(get('h1'))).toBe('bot')
    expect(text(element('hilos-change-log-table-head-badges'))).toBe(
      'Project Journaled',
    )
    expect(text(element('hilos-change-log-table-head-description'))).toBe(
      'Journaled since migration 071',
    )
    expect(text(element('hilos-change-log-table-link-history'))).toBe(
      'Changes in 24 hours 3 Last change: —',
    )
    expect(text(element('hilos-change-log-table-link-columns'))).toBe(
      'Columns 5 with value 2 · as fact 1 · noisy 1',
    )
    expect(text(element('hilos-change-log-table-link-triggers'))).toBe(
      'Triggers 3 Service 3',
    )
    for (const [part, suffix] of [
      ['history', '/history'],
      ['columns', '/columns'],
      ['triggers', '/triggers'],
    ]) {
      const link = element(`hilos-change-log-table-link-${part}`)
      const path = `/hilos/change-log/tables/bot${suffix}`
      expect(link.tagName).toBe('A')
      expect(link.getAttribute('href')).toBe(path)
      expect(get('i', link).getAttribute('aria-hidden')).toBe('true')
      link.click()
      expect(r.value.navigate).toHaveBeenLastCalledWith(path)
    }
    expect(
      document.querySelector('[data-id="hilos-change-log-table-header"]'),
    ).toBeNull()
  })

  it('omits tiles for tabs missing from the answer or unavailable in the project', async () => {
    const h = harness()
    await mountOverview(h)
    h.context.scopes.page()!.data.set('pageTabs', [
      { page: HilosPages.CHANGE_LOG_TABLE, label: 'Overview' },
      { page: HilosPages.CHANGE_LOG_TABLE_TRIGGERS, label: 'Triggers' },
    ])
    await settle()
    expect(
      document.querySelector('[data-id="hilos-change-log-table-link-history"]'),
    ).toBeNull()
    expect(
      document.querySelector('[data-id="hilos-change-log-table-link-columns"]'),
    ).toBeNull()
    expect(
      element('hilos-change-log-table-link-triggers').getAttribute('href'),
    ).toBe('/hilos/change-log/tables/bot/triggers')
    await unmount()
    TestBed.resetTestingModule()
    await mountOverview(harness(), router({ tableId: 'bot' }, false))
    expect(
      document.querySelectorAll('[data-id^="hilos-change-log-table-link-"]'),
    ).toHaveLength(0)
    expect(document.querySelector('a[href=""]')).toBeNull()
  })

  it('reformats the last change from the live reader zone on Overview without a history reader', async () => {
    const timeZone = bindTestTimeZone('UTC')
    releases.push(timeZone.release)
    timeZone.receive('UTC')
    const lastChangeAt = '2025-12-31T23:30:05Z'
    const h = harness({ ...BOT, lastChangeAt })
    const pushWindow = vi.spyOn(h, 'pushWindow')
    const pushPageWindow = vi.spyOn(h, 'pushPageWindow')
    const { r } = await mountOverview(
      h,
      router({ tableId: 'bot', record: '57' }),
      false,
    )
    h.pushPageWindow()
    await settle()

    const page = fixture.debugElement.query(
      By.directive(HilosChangeLogTablePage),
    ).componentInstance as HilosChangeLogTablePage
    const tile = element('hilos-change-log-table-link-history')
    const section = h.context.scopes.pageDataSignal(CHANGE_LOG_TABLE_SECTION)
    const snapshot = section.get()
    const identitySource = pageIdentity(h.context.scopes)
    const identity = identitySource.get()
    const viewportRequests = h.connection.sendTableViewport.mock.calls.length
    const expectedActivity = (zone: string): string =>
      `Changes in 24 hours 3 Last change: ${new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'medium',
        timeZone: zone,
      }).format(new Date(lastChangeAt))}`
    const utc = expectedActivity('UTC')
    const kyiv = expectedActivity('Europe/Kyiv')
    expect(utc).not.toBe(kyiv)
    expect(text(tile)).toBe(utc)

    for (const [zone, expected] of [
      ['Europe/Kyiv', kyiv],
      [null, utc],
    ] as const) {
      timeZone.receive(zone)
      await fixture.whenStable()
      // Check the parent only: an unmarked OnPush page must stay skipped.
      fixture.detectChanges()
      expect(text(tile)).toBe(expected)
      expect(element('hilos-change-log-table-link-history')).toBe(tile)
      expect(
        fixture.debugElement.query(By.directive(HilosChangeLogTablePage))
          .componentInstance,
      ).toBe(page)
      expect(page.context()).toBe(h.context)
      expect(section.get()).toBe(snapshot)
      expect(identitySource.get()).toBe(identity)
      expect(document.querySelector('[data-id="modal"]')).toBeNull()
      expect(h.source.sent).toEqual([])
      expect(pushWindow).not.toHaveBeenCalled()
      expect(pushPageWindow).toHaveBeenCalledTimes(1)
      expect(h.connection.registerTableWindow).not.toHaveBeenCalled()
      expect(h.connection.unregisterTableWindow).not.toHaveBeenCalled()
      expect(h.connection.sendTableViewport).toHaveBeenCalledTimes(
        viewportRequests,
      )
    }

    await unmount()
    expect(h.connection.unregisterTableWindow).not.toHaveBeenCalled()
    expect([...h.listeners.values()].every((set) => set.size === 0)).toBe(true)
    expect(r.leaves.size).toBe(0)
    expect(timeZone.listenerCount()).toBe(2)
    timeZone.release()
    expect(timeZone.listenerCount()).toBe(0)
  })

  it('shows an unjournaled table with its explanation and only the Triggers tile', async () => {
    const h = harness(SESSION)
    await mountOverview(h, router({ tableId: 'hilos_session' }))
    expect(text(get('h1'))).toBe('hilos_session')
    expect(text(element('hilos-change-log-table-head-badges'))).toBe(
      'Framework',
    )
    expect(text(element('hilos-change-log-table-head-description'))).toBe(
      CHANGE_LOG_TABLE_COPY.untracked,
    )
    expect(document.body.textContent).toContain(CHANGE_LOG_TABLE_COPY.untracked)
    expect(
      document.querySelector('[data-id="hilos-change-log-table-link-history"]'),
    ).toBeNull()
    expect(
      document.querySelector('[data-id="hilos-change-log-table-link-columns"]'),
    ).toBeNull()
    expect(text(element('hilos-change-log-table-link-triggers'))).toBe(
      'Triggers 0 Service 0',
    )
    expect(
      element('hilos-change-log-table-link-triggers').getAttribute('href'),
    ).toBe('/hilos/change-log/tables/hilos_session/triggers')
    expect(
      Array.from(
        document.querySelectorAll('nav[aria-label="Table pages"] a'),
        text,
      ),
    ).toEqual(['Overview', 'Triggers'])
    expect(h.connection.registerTableWindow).not.toHaveBeenCalled()
  })

  it('creates no list windows, reading bindings or actions, including on a damaged answer', async () => {
    const h = harness()
    await mountOverview(h)
    h.pushPageWindow()
    await settle()
    for (const id of [
      'hilos-change-log-table-columns',
      'hilos-change-log-table-triggers',
      'hilos-viewport-table',
      'modal',
    ])
      expect(document.querySelector(`[data-id="${id}"]`)).toBeNull()
    expect([...h.listeners.values()].every((set) => set.size === 0)).toBe(true)
    expect(h.source.sent).toEqual([])
    expect(h.connection.registerTableWindow).not.toHaveBeenCalled()
    expect(h.connection.sendTableViewport).not.toHaveBeenCalled()
    h.context.scopes
      .page()!
      .data.set(CHANGE_LOG_TABLE_SECTION, { ...BOT, owner: 'nobody' })
    await settle()
    expect(
      document.querySelector('[data-id="hilos-change-log-table-head-badges"]'),
    ).toBeNull()
    expect(
      document.querySelectorAll('[data-id^="hilos-change-log-table-link-"]'),
    ).toHaveLength(0)
    await unmount()
    expect(h.connection.unregisterTableWindow).not.toHaveBeenCalled()
    expect([...h.listeners.values()].every((set) => set.size === 0)).toBe(true)
  })

  it.each(['full', 'view'] as const)(
    'keeps %s metadata visible on Overview without history bindings',
    async (access) => {
      const h = harness()
      bindSessionScope(h.context.connection, h.context.scopes)
      releases.push(bindAdminAccess(h.context.scopes))
      h.emit('projectSignal', {
        type: 'handshake_response',
        data: {
          entities: {
            currentUser: { id: 9, name: 'Ada', admin: access === 'full' },
          },
          data: { adminViewMode: true },
        },
      })
      expect(hilosAdminAccess.get()).toBe(access)
      await mountOverview(h, router({ tableId: 'bot' }), false)
      expect(element('hilos-page-head-title').textContent).toContain('bot')
      expect(
        document.querySelector('[data-id="hilos-viewport-table"]'),
      ).toBeNull()
      expect(document.querySelector('[data-id="modal"]')).toBeNull()
      expect(h.connection.registerTableWindow).not.toHaveBeenCalled()
      expect(h.source.sent).toEqual([])
    },
  )
})
